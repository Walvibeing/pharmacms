<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    json_response(['success' => false, 'message' => 'Not authenticated.'], 401);
}

$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$isSuperAdmin = is_super_admin();

$action = $_GET['action'] ?? '';

// ---- GET: Fetch full media details ----
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(['success' => false, 'message' => 'Missing media ID.']);

    if ($isSuperAdmin) {
        $media = fetch_one(
            "SELECT m.*, u.name as uploader_name, c.name as company_name, l.name as location_name
             FROM media m
             LEFT JOIN users u ON m.uploaded_by = u.id
             LEFT JOIN companies c ON m.company_id = c.id
             LEFT JOIN locations l ON m.location_id = l.id
             WHERE m.id = ? AND m.is_active = 1",
            [$id]
        );
    } else {
        $media = fetch_one(
            "SELECT m.*, u.name as uploader_name, c.name as company_name, l.name as location_name
             FROM media m
             LEFT JOIN users u ON m.uploaded_by = u.id
             LEFT JOIN companies c ON m.company_id = c.id
             LEFT JOIN locations l ON m.location_id = l.id
             WHERE m.id = ? AND m.company_id = ? AND m.is_active = 1",
            [$id, $companyId]
        );
    }

    if (!$media) json_response(['success' => false, 'message' => 'Media not found.']);

    // Get location assignments from junction table
    $mediaLocations = fetch_all(
        "SELECT location_id FROM media_locations WHERE media_id = ?",
        [$id]
    );
    $media['location_ids'] = array_column($mediaLocations, 'location_id');

    // Get playlists that use this media
    $playlists = fetch_all(
        "SELECT p.id, p.name
         FROM playlist_items pi
         INNER JOIN playlists p ON pi.playlist_id = p.id
         WHERE pi.media_id = ? AND p.is_active = 1
         ORDER BY p.name",
        [$id]
    );

    // Build URLs
    $media['media_src'] = media_url($media['filename']);
    $media['thumb_src'] = thumb_url($media['thumbnail']);
    $media['file_size_formatted'] = format_bytes($media['file_size']);
    $media['created_at_formatted'] = date('M j, Y g:ia', strtotime($media['created_at']));
    $media['created_at_ago'] = time_ago($media['created_at']);
    $media['duration_formatted'] = format_duration($media['duration']);

    json_response([
        'success' => true,
        'media' => $media,
        'playlists' => $playlists
    ]);
}

// ---- UPDATE: Update media name, tags, duration ----
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(['success' => false, 'message' => 'Missing media ID.']);

    if ($isSuperAdmin) {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND is_active = 1", [$id]);
    } else {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
    }
    if (!$media) json_response(['success' => false, 'message' => 'Media not found.']);

    $name = trim($_POST['name'] ?? $media['name']);
    $tags = trim($_POST['tags'] ?? '');
    $duration = (int)($_POST['duration'] ?? $media['duration']);
    if ($duration < 1) $duration = 10;

    update('media', [
        'name' => $name,
        'tags' => $tags,
        'duration' => $duration
    ], 'id = ?', [$id]);

    // Update location assignments
    if (isset($_POST['location_ids'])) {
        delete_row('media_locations', 'media_id = ?', [$id]);
        $newLocationIds = $_POST['location_ids'] ?? [];
        if (is_array($newLocationIds)) {
            foreach ($newLocationIds as $lid) {
                $lid = (int)$lid;
                if ($lid > 0) {
                    insert('media_locations', ['media_id' => $id, 'location_id' => $lid]);
                }
            }
        }
        // Update legacy location_id column
        $firstLoc = !empty($newLocationIds) ? (int)$newLocationIds[0] : null;
        update('media', ['location_id' => $firstLoc], 'id = ?', [$id]);
    } elseif (isset($_POST['all_locations']) && $_POST['all_locations'] === '1') {
        delete_row('media_locations', 'media_id = ?', [$id]);
        update('media', ['location_id' => null], 'id = ?', [$id]);
    }

    log_activity('media_updated', "Updated media: {$name}");

    json_response(['success' => true, 'message' => 'Media updated successfully.']);
}

// ---- DELETE: Soft-delete media ----
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(['success' => false, 'message' => 'Missing media ID.']);

    if ($isSuperAdmin) {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND is_active = 1", [$id]);
    } else {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
    }
    if (!$media) json_response(['success' => false, 'message' => 'Media not found.']);

    // Check if used in playlists
    $inUse = fetch_one("SELECT COUNT(*) as c FROM playlist_items WHERE media_id = ?", [$id]);
    if ($inUse['c'] > 0) {
        json_response([
            'success' => false,
            'message' => 'Cannot delete — this media is used in ' . $inUse['c'] . ' playlist(s). Remove it from playlists first.'
        ]);
    }

    // Soft delete
    update('media', ['is_active' => 0], 'id = ?', [$id]);

    log_activity('media_deleted', "Deleted media: {$media['name']}");

    json_response(['success' => true, 'message' => 'Media deleted successfully.']);
}

// ---- LIST: Return all media as lightweight JSON for grid refresh ----
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $filterLocation = $_GET['location_id'] ?? '';
    $filterType = $_GET['type'] ?? '';
    $search = trim($_GET['q'] ?? '');

    if ($isSuperAdmin) {
        $where = ["m.is_active = 1"];
        $params = [];
    } else {
        $where = ["m.company_id = ?", "m.is_active = 1"];
        $params = [$companyId];
    }

    if ($filterLocation !== '') {
        $where[] = "m.location_id = ?";
        $params[] = (int)$filterLocation;
    }
    if ($filterType === 'image' || $filterType === 'video') {
        $where[] = "m.file_type = ?";
        $params[] = $filterType;
    }
    if ($search !== '') {
        $where[] = "(m.name LIKE ? OR m.tags LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $whereClause = implode(' AND ', $where);
    // Note: m.width and m.height require migration: ALTER TABLE media ADD COLUMN width INT DEFAULT 0, ADD COLUMN height INT DEFAULT 0;
    // Once migrated, uncomment the width/height columns in the SELECT below.
    $rows = fetch_all(
        "SELECT m.id, m.name, m.filename, m.thumbnail, m.file_type, m.file_size, m.duration, m.tags,
            (SELECT COUNT(*) FROM playlist_items pi WHERE pi.media_id = m.id) as playlist_count,
            c.name as company_name
         FROM media m
         LEFT JOIN users u ON m.uploaded_by = u.id
         LEFT JOIN companies c ON m.company_id = c.id
         WHERE {$whereClause}
         ORDER BY m.created_at DESC",
        $params
    );

    // Build lightweight media array and compute stats
    $mediaList = [];
    $totalSize = 0;
    $imageCount = 0;
    $videoCount = 0;

    foreach ($rows as $row) {
        $item = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'filename' => $row['filename'],
            'thumbnail' => $row['thumbnail'],
            'file_type' => $row['file_type'],
            'file_size' => (int)$row['file_size'],
            'file_size_formatted' => format_bytes($row['file_size']),
            'duration' => (int)$row['duration'],
            'width' => (int)($row['width'] ?? 0),
            'height' => (int)($row['height'] ?? 0),
            'tags' => $row['tags'] ?? '',
            'playlist_count' => (int)$row['playlist_count'],
            'media_url' => media_url($row['filename']),
            'thumb_url' => thumb_url($row['thumbnail'])
        ];

        if ($isSuperAdmin) {
            $item['company_name'] = $row['company_name'] ?? '';
        }

        $mediaList[] = $item;
        $totalSize += (int)$row['file_size'];
        if ($row['file_type'] === 'image') $imageCount++;
        if ($row['file_type'] === 'video') $videoCount++;
    }

    json_response([
        'success' => true,
        'media' => $mediaList,
        'is_super_admin' => $isSuperAdmin,
        'stats' => [
            'total' => count($mediaList),
            'images' => $imageCount,
            'videos' => $videoCount,
            'total_size' => format_bytes($totalSize)
        ]
    ]);
}

// ---- GET: Fetch screens for push-to-screen feature ----
if ($action === 'get_screens' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($isSuperAdmin) {
        $screens = fetch_all(
            "SELECT s.id, s.name, s.current_mode, l.name as location_name,
                COALESCE(sa.assignment_type, '') as assignment_type,
                COALESCE(sa.media_ids, '') as media_ids,
                sa.media_id
             FROM screens s
             JOIN locations l ON s.location_id = l.id
             LEFT JOIN screen_assignments sa ON s.id = sa.screen_id
             WHERE s.status = 'active'
             ORDER BY l.name, s.name"
        );
    } else {
        $screens = fetch_all(
            "SELECT s.id, s.name, s.current_mode, l.name as location_name,
                COALESCE(sa.assignment_type, '') as assignment_type,
                COALESCE(sa.media_ids, '') as media_ids,
                sa.media_id
             FROM screens s
             JOIN locations l ON s.location_id = l.id
             LEFT JOIN screen_assignments sa ON s.id = sa.screen_id
             WHERE s.company_id = ? AND s.status = 'active'
             ORDER BY l.name, s.name",
            [$companyId]
        );
    }

    json_response(['success' => true, 'screens' => $screens]);
}

// ---- POST: Push media to screen(s) ----
if ($action === 'push_to_screen' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $mediaId = (int)($_POST['media_id'] ?? 0);
    if (!$mediaId) json_response(['success' => false, 'message' => 'Missing media ID.']);

    $screenIds = $_POST['screen_ids'] ?? [];
    if (!is_array($screenIds) || empty($screenIds)) {
        json_response(['success' => false, 'message' => 'No screens selected.']);
    }

    // Verify media exists and user has access
    if ($isSuperAdmin) {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND is_active = 1", [$mediaId]);
    } else {
        $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ? AND is_active = 1", [$mediaId, $companyId]);
    }
    if (!$media) json_response(['success' => false, 'message' => 'Media not found.']);

    $updated = 0;
    foreach ($screenIds as $sid) {
        $sid = (int)$sid;
        if ($isSuperAdmin) {
            $screen = fetch_one("SELECT id FROM screens WHERE id = ? AND status = 'active'", [$sid]);
        } else {
            $screen = fetch_one("SELECT id FROM screens WHERE id = ? AND company_id = ? AND status = 'active'", [$sid, $companyId]);
        }
        if (!$screen) continue;

        $existing = fetch_one("SELECT * FROM screen_assignments WHERE screen_id = ?", [$sid]);

        if ($existing && $existing['assignment_type'] === 'single') {
            $currentIds = !empty($existing['media_ids']) ? explode(',', $existing['media_ids']) : [];
            if (empty($currentIds) && $existing['media_id']) $currentIds = [(string)$existing['media_id']];
            if (!in_array((string)$mediaId, $currentIds)) {
                $currentIds[] = (string)$mediaId;
            }
            $newIdsStr = implode(',', $currentIds);
            update('screen_assignments', [
                'media_ids' => $newIdsStr,
                'media_id' => (int)$currentIds[0]
            ], 'screen_id = ?', [$sid]);
        } else {
            delete_row('screen_assignments', 'screen_id = ?', [$sid]);
            insert('screen_assignments', [
                'screen_id' => $sid,
                'assignment_type' => 'single',
                'media_id' => $mediaId,
                'media_ids' => (string)$mediaId
            ]);
            update('screens', ['current_mode' => 'single'], 'id = ?', [$sid]);
        }
        $updated++;
    }

    log_activity('media_pushed_to_screen', "Pushed media '{$media['name']}' to {$updated} screen(s)");
    json_response(['success' => true, 'message' => "Pushed to {$updated} screen(s) successfully."]);
}

// ---- BULK DELETE: Soft-delete multiple media ----
if ($action === 'bulk_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        json_response(['success' => false, 'message' => 'No media selected.']);
    }

    $deleted = 0;
    $skipped = 0;
    $total = count($ids);

    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        if (!$id) continue;

        // Verify ownership
        if ($isSuperAdmin) {
            $media = fetch_one("SELECT * FROM media WHERE id = ? AND is_active = 1", [$id]);
        } else {
            $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
        }
        if (!$media) continue;

        // Check if used in playlists
        $inUse = fetch_one("SELECT COUNT(*) as c FROM playlist_items WHERE media_id = ?", [$id]);
        if ($inUse['c'] > 0) {
            $skipped++;
            continue;
        }

        // Soft delete
        update('media', ['is_active' => 0], 'id = ?', [$id]);
        log_activity('media_deleted', "Bulk deleted media: {$media['name']}");
        $deleted++;
    }

    $message = "Deleted {$deleted} of {$total} items.";
    if ($skipped > 0) {
        $message .= " {$skipped} skipped (in use).";
    }

    json_response(['success' => true, 'message' => $message, 'deleted' => $deleted, 'skipped' => $skipped]);
}

// ---- BULK TAG: Update tags on multiple media ----
if ($action === 'bulk_tag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
    }

    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        json_response(['success' => false, 'message' => 'No media selected.']);
    }

    $tags = trim($_POST['tags'] ?? '');
    $mode = $_POST['mode'] ?? 'append';
    if ($mode !== 'append' && $mode !== 'replace') {
        $mode = 'append';
    }

    $updated = 0;

    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        if (!$id) continue;

        // Verify ownership
        if ($isSuperAdmin) {
            $media = fetch_one("SELECT id, tags FROM media WHERE id = ? AND is_active = 1", [$id]);
        } else {
            $media = fetch_one("SELECT id, tags FROM media WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
        }
        if (!$media) continue;

        if ($mode === 'replace') {
            $newTags = $tags;
        } else {
            // Append: merge existing tags with new tags, avoiding duplicates
            $existingTags = array_filter(array_map('trim', explode(',', $media['tags'] ?? '')));
            $newTagsList = array_filter(array_map('trim', explode(',', $tags)));
            $existingLower = array_map('strtolower', $existingTags);
            foreach ($newTagsList as $nt) {
                if (!in_array(strtolower($nt), $existingLower)) {
                    $existingTags[] = $nt;
                }
            }
            $newTags = implode(', ', $existingTags);
        }

        update('media', ['tags' => $newTags], 'id = ?', [$id]);
        $updated++;
    }

    log_activity('media_bulk_tagged', "Bulk tagged {$updated} media items ({$mode})");

    json_response(['success' => true, 'message' => "Updated tags on {$updated} item(s).", 'updated' => $updated]);
}

json_response(['success' => false, 'message' => 'Invalid action.'], 400);
