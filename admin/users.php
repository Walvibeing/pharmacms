<?php
$pageTitle = 'All Users';
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['super_admin']);

$users = fetch_all(
    "SELECT u.*, c.name as company_name, c.status as company_status
     FROM users u
     LEFT JOIN companies c ON u.company_id = c.id
     ORDER BY u.created_at DESC"
);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>All Users</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="adminUserSearch" placeholder="Search by name or email...">
        </div>
        <select id="adminRoleFilter" class="form-control">
            <option value="">All Roles</option>
            <option value="super_admin">Super Admin</option>
            <option value="company_admin">Company Admin</option>
            <option value="location_manager">Location Manager</option>
        </select>
    </div>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Company</th>
                <th>Role</th>
                <th>Active</th>
                <th>Created</th>
            </tr>
        </thead>
        <tbody id="adminUsersTableBody">
        <?php foreach ($users as $u): ?>
            <tr data-name="<?= sanitize(strtolower($u['name'])) ?>" data-email="<?= sanitize(strtolower($u['email'])) ?>" data-role="<?= sanitize($u['role']) ?>" class="row-border-<?= $u['is_active'] ? 'green' : 'red' ?>">
                <td><strong><?= sanitize($u['name']) ?></strong></td>
                <td class="text-muted"><?= sanitize($u['email']) ?></td>
                <td>
                    <?php if ($u['company_name']): ?>
                        <a href="<?= BASE_URL ?>admin/company_view.php?id=<?= $u['company_id'] ?>"><?= sanitize($u['company_name']) ?></a>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= ucfirst(str_replace('_', ' ', $u['role'])) ?></td>
                <td><span class="badge <?= $u['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td class="text-sm text-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <tr><td colspan="6">
                <div class="empty-state" style="padding:32px">
                    <h4>No users found</h4>
                    <p>Users will appear here once companies register and create accounts.</p>
                </div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
$extraScripts = <<<'JS'
<script>
function applyAdminUserFilter() {
    window.filterTable({
        search: $('#adminUserSearch').val(),
        searchFields: ['name', 'email'],
        filters: { role: $('#adminRoleFilter').val() },
        rowSelector: '#adminUsersTableBody tr',
        tableBody: '#adminUsersTableBody',
        emptyMessage: 'No matching users found.',
        colspan: 6
    });
}

$('#adminUserSearch').on('input', window.debounce(applyAdminUserFilter, 200));
$('#adminRoleFilter').on('change', applyAdminUserFilter);
</script>
JS;
?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
