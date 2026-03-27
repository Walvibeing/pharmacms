<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
// Restrict CORS to same origin (remove for same-origin deployments)
$allowedOrigin = defined('SCREEN_PLAYER_ORIGIN') ? SCREEN_PLAYER_ORIGIN : '';
if ($allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
// If no origin configured, don't send CORS header (same-origin only)

$key = $_GET['key'] ?? '';
if (empty($key)) {
    json_response(['error' => 'Missing screen key.'], 400);
}

$screen = fetch_one("SELECT s.*, l.company_id FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.screen_key = ?", [$key]);
if (!$screen) {
    json_response(['error' => 'Screen not found.'], 404);
}

$companyId = $screen['company_id'];
$screenId = $screen['id'];
$locationId = $screen['location_id'];

// 1. Check for active emergency broadcast
$emergency = null;

// Check all_locations broadcast
$broadcast = fetch_one(
    "SELECT eb.*, m.filename, m.file_type, m.mime_type
     FROM emergency_broadcasts eb
     JOIN media m ON eb.media_id = m.id
     WHERE eb.company_id = ? AND eb.is_active = 1 AND eb.target = 'all_locations'
     ORDER BY eb.id DESC LIMIT 1",
    [$companyId]
);

if (!$broadcast) {
    // Check specific_locations targeting this screen's location
    $broadcast = fetch_one(
        "SELECT eb.*, m.filename, m.file_type, m.mime_type
         FROM emergency_broadcasts eb
         JOIN media m ON eb.media_id = m.id
         JOIN emergency_targets et ON eb.id = et.broadcast_id
         WHERE eb.company_id = ? AND eb.is_active = 1
           AND (et.location_id = ? OR et.screen_id = ?)
         ORDER BY eb.id DESC LIMIT 1",
        [$companyId, $locationId, $screenId]
    );
}

if ($broadcast) {
    json_response([
        'mode' => 'emergency',
        'emergency' => [
            'title' => $broadcast['title'],
            'media_url' => media_url($broadcast['filename']),
            'type' => $broadcast['file_type']
        ],
        'items' => []
    ]);
}

// 2. Check for active schedule
$now = date('Y-m-d H:i:s');
$dayName = date('D'); // Mon, Tue, etc.

$schedule = fetch_one(
    "SELECT sc.*, p.id as pl_id, m.id as media_single_id, m.filename as media_filename, m.file_type as media_file_type, m.duration as media_duration
     FROM schedules sc
     LEFT JOIN playlists p ON sc.playlist_id = p.id
     LEFT JOIN media m ON sc.media_id = m.id
     WHERE sc.screen_id = ? AND sc.is_active = 1
       AND (
           (sc.repeat_type = 'none' AND sc.start_datetime <= ? AND sc.end_datetime >= ?)
           OR (sc.repeat_type = 'daily' AND TIME(sc.start_datetime) <= TIME(?) AND TIME(sc.end_datetime) >= TIME(?))
           OR (sc.repeat_type = 'weekly' AND TIME(sc.start_datetime) <= TIME(?) AND TIME(sc.end_datetime) >= TIME(?) AND sc.repeat_days LIKE ?)
       )
     ORDER BY sc.id DESC LIMIT 1",
    [$screenId, $now, $now, $now, $now, $now, $now, "%{$dayName}%"]
);

if ($schedule) {
    if ($schedule['pl_id']) {
        // Playlist from schedule
        $items = fetch_all(
            "SELECT m.filename, m.file_type, pi.duration
             FROM playlist_items pi
             JOIN media m ON pi.media_id = m.id
             WHERE pi.playlist_id = ?
             ORDER BY pi.sort_order",
            [$schedule['pl_id']]
        );
        json_response([
            'mode' => 'playlist',
            'items' => array_map(fn($item) => [
                'url' => media_url($item['filename']),
                'type' => $item['file_type'],
                'duration' => (int)$item['duration']
            ], $items)
        ]);
    } elseif ($schedule['media_single_id']) {
        json_response([
            'mode' => 'single',
            'items' => [[
                'url' => media_url($schedule['media_filename']),
                'type' => $schedule['media_file_type'],
                'duration' => (int)$schedule['media_duration']
            ]]
        ]);
    }
}

// 3. Fall back to default assignment
$assignment = fetch_one("SELECT * FROM screen_assignments WHERE screen_id = ?", [$screenId]);

if (!$assignment) {
    json_response(['mode' => 'blank', 'items' => []]);
}

if ($assignment['assignment_type'] === 'playlist' && $assignment['playlist_id']) {
    $items = fetch_all(
        "SELECT m.filename, m.file_type, pi.duration
         FROM playlist_items pi
         JOIN media m ON pi.media_id = m.id
         WHERE pi.playlist_id = ?
         ORDER BY pi.sort_order",
        [$assignment['playlist_id']]
    );

    if (empty($items)) {
        json_response(['mode' => 'blank', 'items' => []]);
    }

    json_response([
        'mode' => 'playlist',
        'items' => array_map(fn($item) => [
            'url' => media_url($item['filename']),
            'type' => $item['file_type'],
            'duration' => (int)$item['duration']
        ], $items)
    ]);
}

if ($assignment['assignment_type'] === 'single') {
    // Multi-select media: check media_ids first, fall back to media_id
    $mediaIds = [];
    if (!empty($assignment['media_ids'])) {
        $mediaIds = array_map('intval', explode(',', $assignment['media_ids']));
        $mediaIds = array_filter($mediaIds, function($v) { return $v > 0; });
    }
    if (empty($mediaIds) && $assignment['media_id']) {
        $mediaIds = [(int)$assignment['media_id']];
    }

    if (!empty($mediaIds)) {
        $placeholders = implode(',', array_fill(0, count($mediaIds), '?'));
        $mediaItems = fetch_all(
            "SELECT id, filename, file_type, duration FROM media WHERE id IN ({$placeholders}) AND is_active = 1",
            $mediaIds
        );

        // Preserve the order from media_ids
        $ordered = [];
        $byId = [];
        foreach ($mediaItems as $mi) { $byId[$mi['id']] = $mi; }
        foreach ($mediaIds as $mid) {
            if (isset($byId[$mid])) $ordered[] = $byId[$mid];
        }

        if (count($ordered) === 1) {
            json_response([
                'mode' => 'single',
                'items' => [[
                    'url' => media_url($ordered[0]['filename']),
                    'type' => $ordered[0]['file_type'],
                    'duration' => (int)$ordered[0]['duration']
                ]]
            ]);
        } elseif (count($ordered) > 1) {
            json_response([
                'mode' => 'playlist',
                'items' => array_map(function($item) {
                    return [
                        'url' => media_url($item['filename']),
                        'type' => $item['file_type'],
                        'duration' => $item['file_type'] === 'video' ? (int)$item['duration'] : 30
                    ];
                }, $ordered)
            ]);
        }
    }
}

json_response(['mode' => 'blank', 'items' => []]);
