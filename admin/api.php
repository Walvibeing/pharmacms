<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();
require_role(['super_admin']);
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'create_company') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'Invalid method']); exit;
    }

    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $companyName = trim($_POST['company_name'] ?? '');
    $companyEmail = trim($_POST['company_email'] ?? '');
    $companyPhone = trim($_POST['company_phone'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['pending', 'active']) ? $_POST['status'] : 'active';

    if (empty($companyName)) {
        echo json_encode(['error' => 'Company name is required.']); exit;
    }
    if (empty($companyEmail) || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Valid company email is required.']); exit;
    }
    if (empty($adminName)) {
        echo json_encode(['error' => 'Admin name is required.']); exit;
    }
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['error' => 'Valid admin email is required.']); exit;
    }

    $slug = generate_slug($companyName);
    $existing = fetch_one("SELECT id FROM companies WHERE slug = ? OR email = ?", [$slug, $companyEmail]);
    if ($existing) {
        echo json_encode(['error' => 'A company with this name or email already exists.']); exit;
    }

    $existingUser = fetch_one("SELECT id FROM users WHERE email = ?", [$adminEmail]);
    if ($existingUser) {
        echo json_encode(['error' => 'A user with this admin email already exists.']); exit;
    }

    $companyId = insert('companies', [
        'name' => $companyName,
        'slug' => $slug,
        'email' => $companyEmail,
        'phone' => $companyPhone,
        'address' => $companyAddress,
        'status' => $status,
        'approved_at' => $status === 'active' ? date('Y-m-d H:i:s') : null
    ]);

    insert('users', [
        'company_id' => $companyId,
        'email' => $adminEmail,
        'name' => $adminName,
        'role' => 'company_admin',
        'is_active' => $status === 'active' ? 1 : 0
    ]);

    log_activity('company_created', "Created company: {$companyName}");

    echo json_encode([
        'success' => true,
        'message' => "Company '{$companyName}' created successfully.",
        'company_id' => $companyId,
        'company_name' => $companyName
    ]);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
