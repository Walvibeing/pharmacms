<?php
/**
 * Locations AJAX API
 * Handles: get location data, update details
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$userId = $_SESSION['user_id'];
$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;

// GET location details (for view panel)
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if (!can_access_location($userId, $id)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    if ($isSuperAdmin) {
        $location = fetch_one(
            "SELECT l.*, c.name as company_name
             FROM locations l
             JOIN companies c ON l.company_id = c.id
             WHERE l.id = ?",
            [$id]
        );
    } else {
        $location = fetch_one("SELECT l.* FROM locations l WHERE l.id = ?", [$id]);
    }

    if (!$location) {
        echo json_encode(['success' => false, 'message' => 'Location not found.']);
        exit;
    }

    // Get screens at this location
    $screens = fetch_all(
        "SELECT s.*,
            COALESCE(
                (SELECT p.name FROM screen_assignments sa JOIN playlists p ON sa.playlist_id = p.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'playlist'),
                (SELECT m.name FROM screen_assignments sa JOIN media m ON sa.media_id = m.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'single'),
                'None'
            ) as content_name
         FROM screens s WHERE s.location_id = ? ORDER BY s.name",
        [$id]
    );

    // Add status info to each screen
    foreach ($screens as &$scr) {
        $scr['status_class'] = screen_status_class($scr['last_ping']);
        $scr['status_label'] = screen_status_label($scr['last_ping']);
        $scr['mode_badge'] = mode_badge($scr['current_mode']);
    }
    unset($scr);

    // Get assigned managers
    $assignedManagers = fetch_all(
        "SELECT u.id, u.name, u.email FROM users u
         INNER JOIN location_users lu ON u.id = lu.user_id
         WHERE lu.location_id = ? ORDER BY u.name",
        [$id]
    );

    // Get all available managers for this company (for edit form)
    $allManagers = fetch_all(
        "SELECT id, name, email FROM users WHERE company_id = ? AND role = 'location_manager' AND is_active = 1 ORDER BY name",
        [$location['company_id']]
    );

    $assignedIds = array_column($assignedManagers, 'id');

    // Get media thumbnails
    $media = fetch_all(
        "SELECT m.id, m.name, m.filename, m.file_type, m.thumbnail
         FROM media m
         WHERE m.company_id = ? AND (m.location_id = ? OR m.location_id IS NULL) AND m.is_active = 1
         ORDER BY m.created_at DESC LIMIT 12",
        [$location['company_id'], $id]
    );

    echo json_encode([
        'success' => true,
        'location' => $location,
        'screens' => $screens,
        'assigned_managers' => $assignedManagers,
        'assigned_ids' => $assignedIds,
        'all_managers' => $allManagers,
        'media' => $media
    ]);
    exit;
}

// POST update location
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    if (!can_access_location($userId, $id)) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    // Only super_admin and company_admin can edit
    if (!$isSuperAdmin && $_SESSION['role'] !== 'company_admin') {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to edit locations.']);
        exit;
    }

    $location = fetch_one("SELECT * FROM locations WHERE id = ?", [$id]);
    if (!$location) {
        echo json_encode(['success' => false, 'message' => 'Location not found.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $postcode = trim($_POST['postcode'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $isActive = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    $selectedManagers = $_POST['managers'] ?? [];

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Location name is required.']);
        exit;
    }

    update('locations', [
        'name' => $name,
        'address' => $address,
        'city' => $city,
        'postcode' => $postcode,
        'contact_name' => $contactName,
        'contact_email' => $contactEmail,
        'contact_phone' => $contactPhone,
        'is_active' => $isActive
    ], 'id = ?', [$id]);

    // Update manager assignments
    delete_row('location_users', 'location_id = ?', [$id]);
    foreach ($selectedManagers as $managerId) {
        $managerId = (int)$managerId;
        $valid = fetch_one("SELECT id FROM users WHERE id = ? AND company_id = ? AND role = 'location_manager'", [$managerId, $location['company_id']]);
        if ($valid) {
            insert('location_users', ['location_id' => $id, 'user_id' => $managerId]);
        }
    }

    log_activity('location_updated', "Updated location: {$name}");
    echo json_encode(['success' => true, 'message' => 'Location updated.']);
    exit;
}

// GET location list (lightweight, for table refresh)
if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($isSuperAdmin) {
        $locations = fetch_all(
            "SELECT l.id, l.name, l.city, l.postcode, l.is_active, c.name as company_name,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
                (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
             FROM locations l
             JOIN companies c ON l.company_id = c.id
             ORDER BY c.name, l.name"
        );
    } elseif ($_SESSION['role'] === 'company_admin') {
        $locations = fetch_all(
            "SELECT l.id, l.name, l.city, l.postcode, l.is_active,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
                (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
             FROM locations l WHERE l.company_id = ? ORDER BY l.name",
            [$companyId]
        );
    } else {
        $locations = fetch_all(
            "SELECT l.id, l.name, l.city, l.postcode, l.is_active,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
                (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
                (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
             FROM locations l
             INNER JOIN location_users lu ON l.id = lu.location_id
             WHERE lu.user_id = ? ORDER BY l.name",
            [$userId]
        );
    }

    $totalScreens = 0;
    $totalOnline = 0;
    $totalInactive = 0;
    foreach ($locations as $loc) {
        $totalScreens += (int)$loc['screen_count'];
        $totalOnline += (int)$loc['online_count'];
        if (!$loc['is_active']) $totalInactive++;
    }

    echo json_encode([
        'success' => true,
        'locations' => $locations,
        'is_super_admin' => $isSuperAdmin,
        'can_edit' => ($isSuperAdmin || $_SESSION['role'] === 'company_admin'),
        'stats' => [
            'total' => count($locations),
            'screens' => $totalScreens,
            'online' => $totalOnline,
            'inactive' => $totalInactive
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
