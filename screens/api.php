<?php
/**
 * Screens AJAX API
 * Handles: get screen data, update screen, assign content
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];

// GET screen details
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!can_access_screen($userId, $id)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $screen = fetch_one(
        "SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.id = ?",
        [$id]
    );
    if (!$screen) {
        echo json_encode(['success' => false, 'message' => 'Screen not found.']);
        exit;
    }

    // Get current assignment
    $assignment = fetch_one("SELECT * FROM screen_assignments WHERE screen_id = ?", [$id]);

    // Get playlists for this company (with item counts + total duration)
    $playlists = fetch_all(
        "SELECT p.id, p.name,
            (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
            (SELECT COALESCE(SUM(m.duration), 0) FROM playlist_items pi JOIN media m ON pi.media_id = m.id WHERE pi.playlist_id = p.id) as total_duration
         FROM playlists p WHERE p.company_id = ? AND p.is_active = 1 ORDER BY p.name",
        [$screen['company_id']]
    );

    // Get media for this company (with thumbnails, duration, file size)
    $mediaItems = fetch_all(
        "SELECT id, name, file_type, filename, thumbnail, duration, file_size
         FROM media WHERE company_id = ? AND is_active = 1 ORDER BY name",
        [$screen['company_id']]
    );

    // Get schedules for this screen
    $schedules = fetch_all(
        "SELECT sc.*, COALESCE(p.name, m.name) as content_name
         FROM schedules sc
         LEFT JOIN playlists p ON sc.playlist_id = p.id
         LEFT JOIN media m ON sc.media_id = m.id
         WHERE sc.screen_id = ? AND sc.is_active = 1
         ORDER BY sc.start_datetime",
        [$id]
    );

    echo json_encode([
        'success' => true,
        'screen' => $screen,
        'assignment' => $assignment,
        'playlists' => $playlists,
        'media' => $mediaItems,
        'schedules' => $schedules
    ]);
    exit;
}

// POST update screen details
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!can_access_screen($userId, $id)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $locationId = (int)($_POST['location_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $orientation = in_array($_POST['orientation'] ?? '', ['landscape', 'portrait']) ? $_POST['orientation'] : 'landscape';
    $resolution = trim($_POST['resolution'] ?? '1920x1080');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Screen name is required.']);
        exit;
    }

    update('screens', [
        'name' => $name,
        'location_id' => $locationId,
        'description' => $description,
        'orientation' => $orientation,
        'resolution' => $resolution,
        'status' => $status
    ], 'id = ?', [$id]);

    log_activity('screen_updated', "Updated screen: {$name}");
    echo json_encode(['success' => true, 'message' => 'Screen updated.']);
    exit;
}

// POST assign content
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['screen_id'] ?? 0);
    if (!can_access_screen($userId, $id)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $mode = $_POST['assign_mode'] ?? '';

    // Validate assigned content belongs to screen's company
    $isSuperAdmin = is_super_admin();
    if (!$isSuperAdmin) {
        $screen = fetch_one("SELECT company_id FROM screens WHERE id = ?", [$id]);
        if ($screen) {
            if (!empty($_POST['playlist_id'])) {
                $plCheck = fetch_one("SELECT company_id FROM playlists WHERE id = ?", [(int)$_POST['playlist_id']]);
                if (!$plCheck || $plCheck['company_id'] != $screen['company_id']) {
                    json_response(['success' => false, 'message' => 'Invalid playlist selection.'], 400);
                }
            }
            if (!empty($_POST['media_id'])) {
                $mCheck = fetch_one("SELECT company_id FROM media WHERE id = ?", [(int)$_POST['media_id']]);
                if (!$mCheck || $mCheck['company_id'] != $screen['company_id']) {
                    json_response(['success' => false, 'message' => 'Invalid media selection.'], 400);
                }
            }
            if (!empty($_POST['media_ids']) && is_array($_POST['media_ids'])) {
                foreach ($_POST['media_ids'] as $mid) {
                    $mCheck = fetch_one("SELECT company_id FROM media WHERE id = ?", [(int)$mid]);
                    if (!$mCheck || $mCheck['company_id'] != $screen['company_id']) {
                        json_response(['success' => false, 'message' => 'Invalid media selection.'], 400);
                    }
                }
            }
        }
    }

    // Delete existing assignment
    delete_row('screen_assignments', 'screen_id = ?', [$id]);

    $screen = fetch_one("SELECT name FROM screens WHERE id = ?", [$id]);

    if ($mode === 'playlist' && !empty($_POST['playlist_id'])) {
        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'playlist',
            'playlist_id' => (int)$_POST['playlist_id']
        ]);
        update('screens', ['current_mode' => 'playlist'], 'id = ?', [$id]);
        log_activity('screen_assigned', "Assigned playlist to screen: {$screen['name']}");
    } elseif ($mode === 'single' && (!empty($_POST['media_ids']) || !empty($_POST['media_id']))) {
        // Handle multi-select media
        $mediaIds = [];
        if (!empty($_POST['media_ids']) && is_array($_POST['media_ids'])) {
            $mediaIds = array_map('intval', $_POST['media_ids']);
        } elseif (!empty($_POST['media_id'])) {
            $mediaIds = [(int)$_POST['media_id']];
        }
        $mediaIds = array_filter($mediaIds, function($v) { return $v > 0; });
        $mediaIdsStr = implode(',', $mediaIds);
        $firstMediaId = !empty($mediaIds) ? $mediaIds[0] : null;

        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'single',
            'media_id' => $firstMediaId,
            'media_ids' => $mediaIdsStr
        ]);
        update('screens', ['current_mode' => 'single'], 'id = ?', [$id]);
        $countLabel = count($mediaIds) === 1 ? '1 media item' : count($mediaIds) . ' media items';
        log_activity('screen_assigned', "Assigned {$countLabel} to screen: {$screen['name']}");
    } elseif ($mode === 'scheduled') {
        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'scheduled'
        ]);
        update('screens', ['current_mode' => 'scheduled'], 'id = ?', [$id]);
    }

    echo json_encode(['success' => true, 'message' => 'Content assignment updated.']);
    exit;
}

// POST create screen
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $locationId = (int)($_POST['location_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $orientation = in_array($_POST['orientation'] ?? '', ['landscape', 'portrait']) ? $_POST['orientation'] : 'landscape';
    $resolution = trim($_POST['resolution'] ?? '1920x1080');

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Screen name is required.']);
        exit;
    }

    if (!$locationId || !can_access_location($userId, $locationId)) {
        echo json_encode(['success' => false, 'message' => 'Invalid location.']);
        exit;
    }

    $location = fetch_one("SELECT company_id FROM locations WHERE id = ?", [$locationId]);
    if (!$location) {
        echo json_encode(['success' => false, 'message' => 'Location not found.']);
        exit;
    }

    $screenKey = generate_screen_key();

    $screenId = insert('screens', [
        'location_id' => $locationId,
        'company_id' => $location['company_id'],
        'name' => $name,
        'description' => $description,
        'screen_key' => $screenKey,
        'orientation' => $orientation,
        'resolution' => $resolution,
        'status' => 'active',
        'current_mode' => 'playlist'
    ]);

    log_activity('screen_created', "Created screen: {$name}");
    echo json_encode([
        'success' => true,
        'message' => 'Screen created.',
        'screen_id' => $screenId,
        'screen_key' => $screenKey
    ]);
    exit;
}

// POST add schedule
if ($action === 'add_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $screenId = (int)($_POST['screen_id'] ?? 0);
    if (!can_access_screen($userId, $screenId)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $screen = fetch_one("SELECT * FROM screens WHERE id = ?", [$screenId]);
    if (!$screen) {
        echo json_encode(['success' => false, 'message' => 'Screen not found.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $contentType = $_POST['content_type'] ?? 'playlist';
    $playlistId = !empty($_POST['playlist_id']) ? (int)$_POST['playlist_id'] : null;
    $mediaId = !empty($_POST['media_id']) ? (int)$_POST['media_id'] : null;
    $repeatDays = !empty($_POST['repeat_days']) ? $_POST['repeat_days'] : null;

    // New flow: days + time + optional date range
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $effectiveFrom = $_POST['effective_from'] ?? '';
    $effectiveUntil = $_POST['effective_until'] ?? '';

    // Legacy support: also accept start_datetime / end_datetime directly
    $startDt = $_POST['start_datetime'] ?? '';
    $endDt = $_POST['end_datetime'] ?? '';

    if (!empty($startTime) && !empty($endTime)) {
        // New format: construct datetimes from date + time
        $fromDate = !empty($effectiveFrom) ? $effectiveFrom : date('Y-m-d');
        $untilDate = !empty($effectiveUntil) ? $effectiveUntil : '2099-12-31';
        $startDt = $fromDate . ' ' . $startTime . ':00';
        $endDt = $untilDate . ' ' . $endTime . ':00';
        $repeatType = 'weekly';
    } else {
        $repeatType = in_array($_POST['repeat_type'] ?? '', ['none', 'daily', 'weekly']) ? $_POST['repeat_type'] : 'none';
    }

    if (empty($name) || empty($startDt) || empty($endDt)) {
        echo json_encode(['success' => false, 'message' => 'Name and time window are required.']);
        exit;
    }

    insert('schedules', [
        'screen_id' => $screenId,
        'company_id' => $screen['company_id'],
        'name' => $name,
        'playlist_id' => $contentType === 'playlist' ? $playlistId : null,
        'media_id' => $contentType === 'media' ? $mediaId : null,
        'start_datetime' => $startDt,
        'end_datetime' => $endDt,
        'repeat_type' => $repeatType,
        'repeat_days' => $repeatDays,
        'is_active' => 1
    ]);

    log_activity('schedule_created', "Added schedule '{$name}' to screen: {$screen['name']}");
    echo json_encode(['success' => true, 'message' => 'Schedule added.']);
    exit;
}

// POST delete schedule
if ($action === 'delete_schedule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $schedId = (int)($_POST['id'] ?? 0);
    $schedule = fetch_one(
        "SELECT sc.*, s.name as screen_name FROM schedules sc JOIN screens s ON sc.screen_id = s.id WHERE sc.id = ?",
        [$schedId]
    );

    if (!$schedule || !can_access_screen($userId, $schedule['screen_id'])) {
        echo json_encode(['success' => false, 'message' => 'Schedule not found.']);
        exit;
    }

    update('schedules', ['is_active' => 0], 'id = ?', [$schedId]);
    log_activity('schedule_deleted', "Deleted schedule from screen: {$schedule['screen_name']}");
    echo json_encode(['success' => true, 'message' => 'Schedule deleted.']);
    exit;
}

// POST bulk assign content to multiple screens
if ($action === 'bulk_assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $screenIds = $_POST['screen_ids'] ?? [];
    if (!is_array($screenIds) || empty($screenIds)) {
        echo json_encode(['success' => false, 'message' => 'No screens selected.']);
        exit;
    }

    $mode = $_POST['assign_mode'] ?? '';
    $updated = 0;

    foreach ($screenIds as $sid) {
        $sid = (int)$sid;
        if (!can_access_screen($userId, $sid)) continue;

        delete_row('screen_assignments', 'screen_id = ?', [$sid]);

        if ($mode === 'playlist' && !empty($_POST['playlist_id'])) {
            insert('screen_assignments', [
                'screen_id' => $sid,
                'assignment_type' => 'playlist',
                'playlist_id' => (int)$_POST['playlist_id']
            ]);
            update('screens', ['current_mode' => 'playlist'], 'id = ?', [$sid]);
        } elseif ($mode === 'single' && (!empty($_POST['media_ids']) || !empty($_POST['media_id']))) {
            $mediaIds = [];
            if (!empty($_POST['media_ids']) && is_array($_POST['media_ids'])) {
                $mediaIds = array_map('intval', $_POST['media_ids']);
            } elseif (!empty($_POST['media_id'])) {
                $mediaIds = [(int)$_POST['media_id']];
            }
            $mediaIds = array_filter($mediaIds, function($v) { return $v > 0; });
            insert('screen_assignments', [
                'screen_id' => $sid,
                'assignment_type' => 'single',
                'media_id' => !empty($mediaIds) ? $mediaIds[0] : null,
                'media_ids' => implode(',', $mediaIds)
            ]);
            update('screens', ['current_mode' => 'single'], 'id = ?', [$sid]);
        }
        $updated++;
    }

    log_activity('bulk_screen_assigned', "Bulk assigned content to {$updated} screen(s)");
    echo json_encode(['success' => true, 'message' => "Content updated on {$updated} screen(s)."]);
    exit;
}

// POST pair device to screen
if ($action === 'pair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $screenId = (int)($_POST['screen_id'] ?? 0);
    $code = trim($_POST['code'] ?? '');

    if (!can_access_screen($userId, $screenId)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Pairing code is required.']);
        exit;
    }

    $screen = fetch_one("SELECT id, screen_key, name FROM screens WHERE id = ?", [$screenId]);
    if (!$screen) {
        echo json_encode(['success' => false, 'message' => 'Screen not found.']);
        exit;
    }

    // Look up the pairing code (must be unused and not expired)
    $pairCode = fetch_one(
        "SELECT * FROM screen_pair_codes WHERE code = ? AND is_used = 0 AND expires_at > NOW()",
        [$code]
    );

    if (!$pairCode) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired pairing code.']);
        exit;
    }

    // Verify that the code belongs to this screen's key
    if ($pairCode['screen_key'] !== $screen['screen_key']) {
        echo json_encode(['success' => false, 'message' => 'This code does not match the selected screen. Make sure the display is showing the player URL for this screen.']);
        exit;
    }

    // Mark code as used
    query("UPDATE screen_pair_codes SET is_used = 1 WHERE id = ?", [$pairCode['id']]);

    // Generate a device UUID
    $deviceId = sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );

    $deviceInfo = $pairCode['device_info'] ?: '{}';

    query(
        "UPDATE screens SET device_id = ?, device_info = ? WHERE id = ?",
        [$deviceId, $deviceInfo, $screenId]
    );

    log_activity('device_paired', "Paired device to screen: {$screen['name']}");
    echo json_encode(['success' => true, 'message' => 'Device paired successfully.', 'device_id' => $deviceId]);
    exit;
}

// POST unpair device from screen
if ($action === 'unpair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $screenId = (int)($_POST['screen_id'] ?? 0);

    if (!can_access_screen($userId, $screenId)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    $screen = fetch_one("SELECT id, name FROM screens WHERE id = ?", [$screenId]);
    if (!$screen) {
        echo json_encode(['success' => false, 'message' => 'Screen not found.']);
        exit;
    }

    query("UPDATE screens SET device_id = NULL, device_info = NULL WHERE id = ?", [$screenId]);

    log_activity('device_unpaired', "Unpaired device from screen: {$screen['name']}");
    echo json_encode(['success' => true, 'message' => 'Device unpaired successfully.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
