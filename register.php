<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/helpers.php';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim($_POST['company_name'] ?? '');
    $companyEmail = trim($_POST['company_email'] ?? '');
    $companyPhone = trim($_POST['company_phone'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $userName = trim($_POST['user_name'] ?? '');
    $userEmail = trim($_POST['user_email'] ?? '');

    if (empty($companyName)) $errors[] = 'Company name is required.';
    if (empty($companyEmail) || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid company email is required.';
    if (empty($userName)) $errors[] = 'Your name is required.';
    if (empty($userEmail) || !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid personal email is required.';

    // Check duplicates
    if (!$errors) {
        $slug = generate_slug($companyName);
        $existing = fetch_one("SELECT id FROM companies WHERE slug = ? OR email = ?", [$slug, $companyEmail]);
        if ($existing) $errors[] = 'A company with this name or email already exists.';

        $existingUser = fetch_one("SELECT id FROM users WHERE email = ?", [$userEmail]);
        if ($existingUser) $errors[] = 'An account with this email already exists.';
    }

    if (empty($errors)) {
        $companyId = insert('companies', [
            'name' => $companyName,
            'slug' => $slug,
            'email' => $companyEmail,
            'phone' => $companyPhone,
            'address' => $companyAddress,
            'status' => 'pending'
        ]);

        insert('users', [
            'company_id' => $companyId,
            'email' => $userEmail,
            'name' => $userName,
            'role' => 'company_admin',
            'is_active' => 0
        ]);

        // Send confirmation email to registrant
        require_once __DIR__ . '/auth.php';
        send_email($userEmail, APP_NAME . ' — Registration Received',
            "<div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
                <h2 style='color:#1f1f2e'>" . APP_NAME . "</h2>
                <p>Hi {$userName},</p>
                <p>Thanks for registering <strong>{$companyName}</strong>. Your account is pending approval.</p>
                <p>We'll review your application and notify you by email once approved. This usually takes 1 business day.</p>
                <p style='color:#888;font-size:13px'>— The " . APP_NAME . " Team</p>
            </div>"
        );

        // Notify super admins
        $admins = fetch_all("SELECT email FROM users WHERE role = 'super_admin' AND is_active = 1");
        foreach ($admins as $admin) {
            send_email($admin['email'], APP_NAME . ' — New Company Registration',
                "<div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
                    <h2 style='color:#1f1f2e'>New Registration</h2>
                    <p>A new company has registered:</p>
                    <p><strong>{$companyName}</strong><br>{$companyEmail}</p>
                    <p>Contact: {$userName} ({$userEmail})</p>
                    <p><a href='" . BASE_URL . "admin/companies.php' style='background:#4f46e5;color:#fff;padding:10px 20px;border-radius:6px;display:inline-block;text-decoration:none'>Review in Admin Panel</a></p>
                </div>"
            );
        }

        header('Location: ' . BASE_URL . 'register_success.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-card wide">
        <div class="auth-logo">
            <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" width="24" height="24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
            <span><?= APP_NAME ?></span>
        </div>
        <p class="auth-tagline">Register your pharmacy network</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $err): ?>
                    <div><?= sanitize($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="grid-2">
                <div>
                    <h3 class="mb-2" style="font-size:1rem">Company Details</h3>
                    <div class="form-group">
                        <label for="company_name">Company Name *</label>
                        <input type="text" id="company_name" name="company_name" class="form-control" autocomplete="organization" value="<?= sanitize($_POST['company_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="company_email">Company Email *</label>
                        <input type="email" id="company_email" name="company_email" class="form-control" autocomplete="email" value="<?= sanitize($_POST['company_email'] ?? '') ?>" required>
                        <span class="form-hint">This will be used for billing and account communications.</span>
                    </div>
                    <div class="form-group">
                        <label for="company_phone">Phone</label>
                        <input type="text" id="company_phone" name="company_phone" class="form-control" autocomplete="tel" value="<?= sanitize($_POST['company_phone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="company_address">Address</label>
                        <textarea id="company_address" name="company_address" class="form-control" autocomplete="street-address" rows="3"><?= sanitize($_POST['company_address'] ?? '') ?></textarea>
                    </div>
                </div>
                <div>
                    <h3 class="mb-2" style="font-size:1rem">Your Details</h3>
                    <div class="form-group">
                        <label for="user_name">Your Name *</label>
                        <input type="text" id="user_name" name="user_name" class="form-control" autocomplete="name" value="<?= sanitize($_POST['user_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="user_email">Your Email *</label>
                        <input type="email" id="user_email" name="user_email" class="form-control" autocomplete="email" value="<?= sanitize($_POST['user_email'] ?? '') ?>" required>
                        <span class="form-hint">You'll use this email to log in.</span>
                    </div>
                </div>
            </div>
            <div class="mt-2">
                <button type="submit" class="btn btn-primary btn-lg" style="width:100%">Submit Registration</button>
            </div>
        </form>

        <p class="text-center mt-2 text-sm text-muted">
            Already registered? <a href="<?= BASE_URL ?>index.php">Login here</a>
        </p>
    </div>
</div>
</body>
</html>
