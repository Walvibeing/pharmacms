<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;

// ---- Create Broadcast ----
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid token.'], 403);
    }

    // Check no active broadcast
    if ($isSuperAdmin) {
        $existing = fetch_one("SELECT id FROM emergency_broadcasts WHERE is_active = 1");
    } else {
        $existing = fetch_one("SELECT id FROM emergency_broadcasts WHERE company_id = ? AND is_active = 1", [$companyId]);
    }
    if ($existing) {
        json_response(['success' => false, 'message' => 'An emergency broadcast is already active. End it first.']);
    }

    $title = trim($_POST['title'] ?? '');
    $mediaId = (int)($_POST['media_id'] ?? 0);
    $target = $_POST['target'] ?? 'all_locations';

    // Validate
    $errors = [];
    if (empty($title)) $errors[] = 'Title is required.';
    if (!$mediaId) $errors[] = 'Please select a media item.';
    if (!in_array($target, ['all_locations', 'specific_locations', 'specific_screens'])) {
        $errors[] = 'Invalid target type.';
    }

    if (!empty($errors)) {
        json_response(['success' => false, 'message' => implode(' ', $errors)]);
    }

    // Validate media belongs to user's company
    if (!$isSuperAdmin) {
        $mediaCheck = fetch_one("SELECT company_id FROM media WHERE id = ?", [$mediaId]);
        if (!$mediaCheck || $mediaCheck['company_id'] != $companyId) {
            json_response(['success' => false, 'message' => 'Invalid media selection.'], 400);
        }
    }

    // Validate locations belong to user's company
    if (!$isSuperAdmin && !empty($_POST['location_ids'])) {
        foreach ($_POST['location_ids'] as $locId) {
            $locCheck = fetch_one("SELECT company_id FROM locations WHERE id = ?", [(int)$locId]);
            if (!$locCheck || $locCheck['company_id'] != $companyId) {
                json_response(['success' => false, 'message' => 'Invalid location selection.'], 400);
            }
        }
    }

    // Validate screens belong to user's company
    if (!$isSuperAdmin && !empty($_POST['screen_ids'])) {
        foreach ($_POST['screen_ids'] as $scrId) {
            $scrCheck = fetch_one("SELECT company_id FROM screens WHERE id = ?", [(int)$scrId]);
            if (!$scrCheck || $scrCheck['company_id'] != $companyId) {
                json_response(['success' => false, 'message' => 'Invalid screen selection.'], 400);
            }
        }
    }

    // For super_admin, derive company_id from selected media
    $insertCompanyId = $companyId;
    if ($isSuperAdmin && $mediaId) {
        $mediaRecord = fetch_one("SELECT company_id FROM media WHERE id = ?", [$mediaId]);
        if ($mediaRecord) $insertCompanyId = $mediaRecord['company_id'];
    }

    $broadcastId = insert('emergency_broadcasts', [
        'company_id' => $insertCompanyId,
        'created_by' => $_SESSION['user_id'],
        'title' => $title,
        'media_id' => $mediaId,
        'target' => $target,
        'is_active' => 1,
        'started_at' => date('Y-m-d H:i:s')
    ]);

    // Insert targets
    if ($target === 'specific_locations' && !empty($_POST['location_ids'])) {
        foreach ($_POST['location_ids'] as $locId) {
            insert('emergency_targets', [
                'broadcast_id' => $broadcastId,
                'location_id' => (int)$locId
            ]);
        }
    } elseif ($target === 'specific_screens' && !empty($_POST['screen_ids'])) {
        foreach ($_POST['screen_ids'] as $scrId) {
            insert('emergency_targets', [
                'broadcast_id' => $broadcastId,
                'screen_id' => (int)$scrId
            ]);
        }
    }

    // Update affected screens to emergency mode
    if ($target === 'all_locations') {
        query("UPDATE screens SET current_mode = 'emergency' WHERE company_id = ?", [$insertCompanyId]);
    } elseif ($target === 'specific_locations' && !empty($_POST['location_ids'])) {
        $locIds = array_map('intval', $_POST['location_ids']);
        $ph = implode(',', array_fill(0, count($locIds), '?'));
        query("UPDATE screens SET current_mode = 'emergency' WHERE location_id IN ({$ph})", $locIds);
    } elseif ($target === 'specific_screens' && !empty($_POST['screen_ids'])) {
        $scrIds = array_map('intval', $_POST['screen_ids']);
        $ph = implode(',', array_fill(0, count($scrIds), '?'));
        query("UPDATE screens SET current_mode = 'emergency' WHERE id IN ({$ph})", $scrIds);
    }

    log_activity('emergency_activated', "Activated emergency broadcast: {$title}");
    json_response(['success' => true, 'message' => 'Emergency broadcast activated!']);
}

// ---- Deactivate Broadcast ----
if ($action === 'deactivate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        json_response(['success' => false, 'message' => 'Invalid token.'], 403);
    }

    $id = (int)($_POST['id'] ?? 0);

    if ($isSuperAdmin) {
        $broadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE id = ? AND is_active = 1", [$id]);
    } else {
        $broadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
    }

    if (!$broadcast) {
        json_response(['success' => false, 'message' => 'Broadcast not found or already ended.']);
    }

    $bcCompanyId = $broadcast['company_id'];

    // End the broadcast
    update('emergency_broadcasts', [
        'is_active' => 0,
        'ended_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$id]);

    // Restore screens to their previous mode based on assignments
    $affectedScreens = [];
    if ($broadcast['target'] === 'all_locations') {
        $affectedScreens = fetch_all("SELECT id FROM screens WHERE company_id = ?", [$bcCompanyId]);
    } else {
        $targets = fetch_all("SELECT * FROM emergency_targets WHERE broadcast_id = ?", [$id]);
        foreach ($targets as $t) {
            if ($t['screen_id']) {
                $affectedScreens[] = ['id' => $t['screen_id']];
            }
            if ($t['location_id']) {
                $locScreens = fetch_all("SELECT id FROM screens WHERE location_id = ?", [$t['location_id']]);
                $affectedScreens = array_merge($affectedScreens, $locScreens);
            }
        }
    }

    foreach ($affectedScreens as $scr) {
        $sa = fetch_one("SELECT assignment_type FROM screen_assignments WHERE screen_id = ?", [$scr['id']]);
        $mode = $sa ? $sa['assignment_type'] : 'playlist';
        update('screens', ['current_mode' => $mode], 'id = ?', [$scr['id']]);
    }

    log_activity('emergency_ended', "Ended emergency broadcast: {$broadcast['title']}");
    json_response(['success' => true, 'message' => 'Emergency broadcast ended. Screens returning to normal content.']);
}

// Unknown action
json_response(['success' => false, 'message' => 'Invalid action.'], 400);
