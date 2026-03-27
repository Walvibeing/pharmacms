<?php
/**
 * Users AJAX API
 * Handles: get user data, update user, invite user
 */
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin']);

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$sessionUserId = $_SESSION['user_id'];
$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;

// GET user details (for edit panel)
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);

    if ($isSuperAdmin) {
        $user = fetch_one("SELECT id, name, email, role, is_active, company_id FROM users WHERE id = ?", [$id]);
    } else {
        $user = fetch_one("SELECT id, name, email, role, is_active, company_id FROM users WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found or access denied.']);
        exit;
    }

    $userCompanyId = $user['company_id'];

    // Get assigned location IDs
    $assignedLocations = fetch_all(
        "SELECT l.id, l.name FROM location_users lu JOIN locations l ON lu.location_id = l.id WHERE lu.user_id = ? ORDER BY l.name",
        [$id]
    );
    $user['assigned_location_ids'] = array_column($assignedLocations, 'id');

    // Get available locations for assignment
    $availableLocations = fetch_all(
        "SELECT id, name FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name",
        [$userCompanyId]
    );
    $user['available_locations'] = $availableLocations;

    echo json_encode(['success' => true, 'user' => $user]);
    exit;
}

// POST update user
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);

    // Verify access
    if ($isSuperAdmin) {
        $user = fetch_one("SELECT * FROM users WHERE id = ?", [$id]);
    } else {
        $user = fetch_one("SELECT * FROM users WHERE id = ? AND company_id = ?", [$id, $companyId]);
    }

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found or access denied.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $userRole = in_array($_POST['role'] ?? '', ['company_admin', 'location_manager']) ? $_POST['role'] : $user['role'];
    $isActive = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    $locationIds = $_POST['locations'] ?? [];

    if (empty($name)) {
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit;
    }

    // Don't let admin deactivate themselves
    if ($id == $sessionUserId && !$isActive) {
        echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account.']);
        exit;
    }

    // Update user
    update('users', [
        'name' => $name,
        'role' => $userRole,
        'is_active' => $isActive
    ], 'id = ?', [$id]);

    // Update location assignments
    delete_row('location_users', 'user_id = ?', [$id]);
    if ($userRole === 'location_manager') {
        foreach ($locationIds as $locId) {
            $locId = (int)$locId;
            $valid = fetch_one("SELECT id FROM locations WHERE id = ? AND company_id = ?", [$locId, $user['company_id']]);
            if ($valid) {
                insert('location_users', ['location_id' => $locId, 'user_id' => $id]);
            }
        }
    }

    log_activity('user_updated', "Updated user: {$name}");
    echo json_encode(['success' => true, 'message' => 'User updated successfully.']);
    exit;
}

// POST invite user
if ($action === 'invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $userRole = in_array($_POST['role'] ?? '', ['company_admin', 'location_manager']) ? $_POST['role'] : 'location_manager';
    $locationIds = $_POST['locations'] ?? [];

    $targetCompanyId = $companyId;
    if ($isSuperAdmin) {
        $targetCompanyId = (int)($_POST['company_id'] ?? 0);
        if (!$targetCompanyId) {
            echo json_encode(['success' => false, 'message' => 'Please select a company.']);
            exit;
        }
    }

    if (empty($name) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Name and email are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
        exit;
    }

    // Check if email already exists
    $existing = fetch_one("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        echo json_encode(['success' => false, 'message' => 'A user with this email already exists.']);
        exit;
    }

    // Create user
    $newUserId = insert('users', [
        'company_id' => $targetCompanyId,
        'email' => $email,
        'name' => $name,
        'role' => $userRole,
        'is_active' => 1
    ]);

    // Assign locations if location_manager
    if ($userRole === 'location_manager') {
        foreach ($locationIds as $locId) {
            $locId = (int)$locId;
            $valid = fetch_one("SELECT id FROM locations WHERE id = ? AND company_id = ?", [$locId, $targetCompanyId]);
            if ($valid) {
                insert('location_users', ['location_id' => $locId, 'user_id' => $newUserId]);
            }
        }
    }

    // Send welcome email
    $companyName = fetch_one("SELECT name FROM companies WHERE id = ?", [$targetCompanyId])['name'] ?? '';
    send_email($email, APP_NAME . ' — Welcome!',
        "<div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
            <h2 style='color:#1f1f2e'>" . APP_NAME . "</h2>
            <p>Hi {$name},</p>
            <p>You've been added to <strong>{$companyName}</strong> on " . APP_NAME . ".</p>
            <p>Log in using your email address:</p>
            <p><a href='" . BASE_URL . "' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;display:inline-block;text-decoration:none'>Log in to " . APP_NAME . "</a></p>
        </div>"
    );

    log_activity('user_invited', "Invited user: {$name} ({$email})");
    echo json_encode(['success' => true, 'message' => 'User invited successfully. A welcome email has been sent.', 'user_id' => $newUserId, 'user_name' => $name]);
    exit;
}

// GET locations for a company (for super admin dynamic loading)
if ($action === 'get_locations' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$isSuperAdmin) {
        echo json_encode([]);
        exit;
    }
    $cid = (int)($_GET['company_id'] ?? 0);
    if (!$cid) {
        echo json_encode([]);
        exit;
    }
    $locations = fetch_all("SELECT id, name FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name", [$cid]);
    echo json_encode($locations);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
