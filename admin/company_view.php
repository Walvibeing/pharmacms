<?php
$pageTitle = 'Company Details';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin']);

$id = (int)($_GET['id'] ?? 0);
$company = fetch_one("SELECT * FROM companies WHERE id = ?", [$id]);
if (!$company) { flash('error', 'Company not found.'); redirect(BASE_URL . 'admin/companies.php'); }

// Handle impersonate
if (isset($_POST['impersonate']) && verify_csrf()) {
    $admin = fetch_one("SELECT * FROM users WHERE company_id = ? AND role = 'company_admin' AND is_active = 1 LIMIT 1", [$id]);
    if ($admin) {
        $_SESSION['original_user_id'] = $_SESSION['user_id'];
        $_SESSION['original_role'] = $_SESSION['role'];
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['company_id'] = $admin['company_id'];
        $_SESSION['role'] = $admin['role'];
        $_SESSION['name'] = $admin['name'] . ' (impersonating)';
        $_SESSION['email'] = $admin['email'];
        redirect(BASE_URL . 'dashboard.php');
    } else {
        flash('error', 'No active admin user found for this company.');
    }
}

$locations = fetch_all("SELECT l.*, (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count FROM locations l WHERE l.company_id = ? ORDER BY l.name", [$id]);
$users = fetch_all("SELECT * FROM users WHERE company_id = ? ORDER BY name", [$id]);
$screens = fetch_all("SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.company_id = ? ORDER BY l.name, s.name", [$id]);

include __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb">
    <a href="<?= BASE_URL ?>admin/companies.php">Companies</a> <span>/</span> <?= sanitize($company['name']) ?>
</div>

<div class="page-header">
    <div>
        <h1><?= sanitize($company['name']) ?></h1>
        <div class="text-sm text-muted mt-1">
            <?= sanitize($company['email']) ?> &middot;
            <?= status_badge($company['status']) ?> &middot;
            Registered <?= date('M j, Y', strtotime($company['created_at'])) ?>
        </div>
    </div>
    <div class="btn-group">
        <form method="POST" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="impersonate" value="1">
            <button type="button" class="btn btn-outline" onclick="var f=this.closest('form'); showConfirm({title:'Switch Company View?', message:'You will view the system as this company.', confirmText:'Switch', confirmClass:'btn-primary', onConfirm: function(){ f.submit(); }})">Act As Company</button>
        </form>
        <?php if ($company['status'] === 'pending'): ?>
        <form method="POST" action="<?= BASE_URL ?>admin/approve.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="company_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-primary">Approve</button>
        </form>
        <?php elseif ($company['status'] === 'active'): ?>
        <form method="POST" action="<?= BASE_URL ?>admin/approve.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="company_id" value="<?= $id ?>">
            <input type="hidden" name="action" value="suspend">
            <button type="button" class="btn btn-danger" onclick="var f=this.closest('form'); showConfirm({title:'Suspend Company?', message:'This will suspend the company and restrict their access.', confirmText:'Suspend', confirmClass:'btn-danger', onConfirm: function(){ f.submit(); }})">Suspend</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="grid-3 mb-2">
    <div class="stat-card">
        <div class="stat-label">Locations</div>
        <div class="stat-value"><?= count($locations) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Screens</div>
        <div class="stat-value"><?= count($screens) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Users</div>
        <div class="stat-value"><?= count($users) ?></div>
    </div>
</div>

<div class="grid-2">
    <div>
        <div class="table-wrapper mb-2">
            <div class="table-header"><h3>Locations</h3></div>
            <table>
                <thead><tr><th>Name</th><th>City</th><th>Screens</th></tr></thead>
                <tbody>
                <?php foreach ($locations as $loc): ?>
                <tr>
                    <td><strong><?= sanitize($loc['name']) ?></strong></td>
                    <td class="text-muted"><?= sanitize($loc['city'] ?? '') ?></td>
                    <td><?= $loc['screen_count'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($locations)): ?>
                <tr><td colspan="3" class="text-center text-muted">No locations.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-wrapper">
            <div class="table-header"><h3>Screens</h3></div>
            <table>
                <thead><tr><th>Screen</th><th>Location</th><th>Status</th><th>Mode</th></tr></thead>
                <tbody>
                <?php foreach ($screens as $scr): ?>
                <tr>
                    <td><?= sanitize($scr['name']) ?></td>
                    <td class="text-muted"><?= sanitize($scr['location_name']) ?></td>
                    <td><span class="status-dot <?= screen_status_class($scr['last_ping']) ?>"></span> <?= screen_status_label($scr['last_ping']) ?></td>
                    <td><?= mode_badge($scr['current_mode']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($screens)): ?>
                <tr><td colspan="4" class="text-center text-muted">No screens.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="table-wrapper">
        <div class="table-header"><h3>Users</h3></div>
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= sanitize($u['name']) ?></strong></td>
                <td class="text-muted text-sm"><?= sanitize($u['email']) ?></td>
                <td><?= ucfirst(str_replace('_', ' ', $u['role'])) ?></td>
                <td><?= $u['is_active'] ? '<span class="text-success">Yes</span>' : '<span class="text-danger">No</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
