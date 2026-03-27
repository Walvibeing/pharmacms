<?php
$pageTitle = 'Add Company';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'Invalid security token.'; }

    $companyName = trim($_POST['company_name'] ?? '');
    $companyEmail = trim($_POST['company_email'] ?? '');
    $companyPhone = trim($_POST['company_phone'] ?? '');
    $companyAddress = trim($_POST['company_address'] ?? '');
    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['pending', 'active']) ? $_POST['status'] : 'active';

    if (empty($companyName)) $errors[] = 'Company name is required.';
    if (empty($companyEmail) || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid company email is required.';
    if (empty($adminName)) $errors[] = 'Admin name is required.';
    if (empty($adminEmail) || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid admin email is required.';

    if (!$errors) {
        $slug = generate_slug($companyName);
        $existing = fetch_one("SELECT id FROM companies WHERE slug = ? OR email = ?", [$slug, $companyEmail]);
        if ($existing) $errors[] = 'A company with this name or email already exists.';

        $existingUser = fetch_one("SELECT id FROM users WHERE email = ?", [$adminEmail]);
        if ($existingUser) $errors[] = 'A user with this admin email already exists.';
    }

    if (empty($errors)) {
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
        flash('success', "Company '{$companyName}' created successfully.");
        redirect(BASE_URL . 'admin/company_view.php?id=' . $companyId);
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb">
    <a href="<?= BASE_URL ?>admin/companies.php">Companies</a> <span>/</span> Add Company
</div>

<div class="page-header">
    <h1>Add Company</h1>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $e) echo sanitize($e) . '<br>'; ?></div>
<?php endif; ?>

<div class="card" style="max-width:720px">
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>

            <h3 style="font-size:1rem;margin-bottom:1rem">Company Details</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="company_name">Company Name *</label>
                    <input type="text" id="company_name" name="company_name" class="form-control" value="<?= sanitize($_POST['company_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="company_email">Company Email *</label>
                    <input type="email" id="company_email" name="company_email" class="form-control" value="<?= sanitize($_POST['company_email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="company_phone">Phone</label>
                    <input type="text" id="company_phone" name="company_phone" class="form-control" value="<?= sanitize($_POST['company_phone'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="active">Active (ready to use)</option>
                        <option value="pending">Pending (needs approval)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="company_address">Address</label>
                <textarea id="company_address" name="company_address" class="form-control" rows="2"><?= sanitize($_POST['company_address'] ?? '') ?></textarea>
            </div>

            <h3 style="font-size:1rem;margin-bottom:1rem;margin-top:1.5rem">Company Admin User</h3>
            <p class="text-sm text-muted mb-2">This person will be the company's primary administrator and can log in immediately.</p>
            <div class="form-row">
                <div class="form-group">
                    <label for="admin_name">Admin Name *</label>
                    <input type="text" id="admin_name" name="admin_name" class="form-control" value="<?= sanitize($_POST['admin_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_email">Admin Email *</label>
                    <input type="email" id="admin_email" name="admin_email" class="form-control" value="<?= sanitize($_POST['admin_email'] ?? '') ?>" required>
                </div>
            </div>

            <div class="btn-group mt-2">
                <button type="submit" class="btn btn-primary">Create Company</button>
                <a href="<?= BASE_URL ?>admin/companies.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
