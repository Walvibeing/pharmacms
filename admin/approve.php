<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'admin/companies.php');
if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'admin/companies.php'); }

$companyId = (int)($_POST['company_id'] ?? 0);
$action = $_POST['action'] ?? '';

$company = fetch_one("SELECT * FROM companies WHERE id = ?", [$companyId]);
if (!$company) { flash('error', 'Company not found.'); redirect(BASE_URL . 'admin/companies.php'); }

if ($action === 'approve') {
    update('companies', [
        'status' => 'active',
        'approved_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$companyId]);

    // Activate the company admin user
    query("UPDATE users SET is_active = 1 WHERE company_id = ? AND role = 'company_admin'", [$companyId]);

    // Send approval email
    $admin = fetch_one("SELECT * FROM users WHERE company_id = ? AND role = 'company_admin' LIMIT 1", [$companyId]);
    if ($admin) {
        send_email($admin['email'], APP_NAME . ' — Account Approved!',
            "<div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
                <h2 style='color:#1f1f2e'>" . APP_NAME . "</h2>
                <p>Hi {$admin['name']},</p>
                <p>Great news! Your company <strong>{$company['name']}</strong> has been approved.</p>
                <p>You can now log in and start setting up your locations and screens.</p>
                <p><a href='" . BASE_URL . "' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;display:inline-block;text-decoration:none'>Log in now</a></p>
            </div>"
        );
    }

    log_activity('company_approved', "Approved company: {$company['name']}");
    flash('success', "Company '{$company['name']}' has been approved.");

} elseif ($action === 'suspend') {
    update('companies', ['status' => 'suspended'], 'id = ?', [$companyId]);
    log_activity('company_suspended', "Suspended company: {$company['name']}");
    flash('success', "Company '{$company['name']}' has been suspended.");
}

header('Location: ' . BASE_URL . 'admin/companies.php');
exit;
