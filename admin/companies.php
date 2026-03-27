<?php
$pageTitle = 'Companies';
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['super_admin']);

$filterStatus = $_GET['status'] ?? '';

$where = "1=1";
$params = [];
if ($filterStatus && in_array($filterStatus, ['pending', 'active', 'suspended'])) {
    $where .= " AND c.status = ?";
    $params[] = $filterStatus;
}

$companies = fetch_all(
    "SELECT c.*,
        (SELECT COUNT(*) FROM locations WHERE company_id = c.id) as location_count,
        (SELECT COUNT(*) FROM screens WHERE company_id = c.id) as screen_count,
        (SELECT COUNT(*) FROM users WHERE company_id = c.id) as user_count
     FROM companies c
     WHERE {$where}
     ORDER BY c.created_at DESC",
    $params
);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Companies</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" placeholder="Search companies..." id="companySearch">
        </div>
        <select class="form-control" onchange="window.location='<?= BASE_URL ?>admin/companies.php?status='+this.value">
            <option value="">All Statuses</option>
            <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="suspended" <?= $filterStatus === 'suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>
        <button onclick="openSidePanel('addCompanyPanel')" class="btn btn-primary">+ Add Company</button>
    </div>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Company Name</th>
                <th>Email</th>
                <th>Locations</th>
                <th>Screens</th>
                <th>Users</th>
                <th>Status</th>
                <th>Registered</th>
            </tr>
        </thead>
        <tbody id="companiesTableBody">
        <?php foreach ($companies as $c): ?>
            <tr data-name="<?= sanitize(strtolower($c['name'])) ?>" data-email="<?= sanitize(strtolower($c['email'])) ?>" onclick="window.location='<?= BASE_URL ?>admin/company_view.php?id=<?= $c['id'] ?>'" role="button" tabindex="0" class="row-border-<?= $c['status'] === 'active' ? 'green' : ($c['status'] === 'pending' ? 'yellow' : 'red') ?>" style="cursor:pointer">
                <td><strong><?= sanitize($c['name']) ?></strong></td>
                <td class="text-muted text-sm"><?= sanitize($c['email']) ?></td>
                <td><?= $c['location_count'] ?></td>
                <td><?= $c['screen_count'] ?></td>
                <td><?= $c['user_count'] ?></td>
                <td><?= status_badge($c['status']) ?></td>
                <td class="text-sm text-muted"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($companies)): ?>
            <tr><td colspan="7">
                <div class="empty-state" style="padding:32px">
                    <h4>No companies found</h4>
                    <p>Add your first company to get started.</p>
                    <button onclick="openSidePanel('addCompanyPanel')" class="btn btn-primary">+ Add Company</button>
                </div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Company Side Panel -->
<div class="side-panel-overlay" id="addCompanyPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="addCompanyPanel">
    <div class="side-panel-header">
        <h2>Add Company</h2>
        <button class="side-panel-close" onclick="closeSidePanel('addCompanyPanel', true)">&times;</button>
    </div>
    <div class="side-panel-body" id="addCompanyBody">
        <form id="addCompanyForm">
            <?= csrf_field() ?>

            <h3 style="font-size:0.95rem;font-weight:600;margin-bottom:12px">Company Details</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label for="pc_company_name">Company Name *</label>
                    <input type="text" id="pc_company_name" name="company_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="pc_company_email">Company Email *</label>
                    <input type="email" id="pc_company_email" name="company_email" class="form-control" required>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label for="pc_company_phone">Phone</label>
                    <input type="text" id="pc_company_phone" name="company_phone" class="form-control">
                </div>
                <div class="form-group">
                    <label for="pc_status">Status</label>
                    <select id="pc_status" name="status" class="form-control">
                        <option value="active">Active (ready to use)</option>
                        <option value="pending">Pending (needs approval)</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="pc_company_address">Address</label>
                <textarea id="pc_company_address" name="company_address" class="form-control" rows="2"></textarea>
            </div>

            <h3 style="font-size:0.95rem;font-weight:600;margin-bottom:8px;margin-top:20px">Company Admin User</h3>
            <p class="text-sm text-muted" style="margin-bottom:12px">This person will be the company's primary administrator.</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="form-group">
                    <label for="pc_admin_name">Admin Name *</label>
                    <input type="text" id="pc_admin_name" name="admin_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="pc_admin_email">Admin Email *</label>
                    <input type="email" id="pc_admin_email" name="admin_email" class="form-control" required>
                </div>
            </div>
        </form>
        <div id="addCompanySuccess" style="display:none;text-align:center;padding:2rem 1rem">
            <div style="width:48px;height:48px;border-radius:50%;background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h3 style="margin-bottom:8px" id="addCompanySuccessTitle">Company Created!</h3>
            <p class="text-muted text-sm" id="addCompanySuccessMsg"></p>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:20px">
                <button class="btn btn-primary" onclick="window.location.reload()">Done</button>
                <button class="btn btn-outline" onclick="resetAddCompanyForm()">Add Another</button>
            </div>
        </div>
    </div>
    <div class="side-panel-actions" id="addCompanyActions">
        <button type="submit" form="addCompanyForm" class="btn btn-primary" id="addCompanySubmitBtn">Create Company</button>
        <button class="btn btn-outline" onclick="closeSidePanel('addCompanyPanel')">Cancel</button>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
$('#companySearch').on('input', window.debounce(function() {
    window.filterTable({
        search: $('#companySearch').val(),
        searchFields: ['name', 'email'],
        filters: {},
        rowSelector: '#companiesTableBody tr',
        tableBody: '#companiesTableBody',
        emptyMessage: 'No matching companies found.',
        colspan: 7
    });
}, 200));

// Add Company form submission
$('#addCompanyForm').on('submit', function(e) {
    e.preventDefault();
    var btn = $('#addCompanySubmitBtn');
    btn.prop('disabled', true).text('Creating...');

    $.ajax({
        url: 'api.php?action=create_company',
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                $('#addCompanyForm').hide();
                $('#addCompanyActions').hide();
                $('#addCompanySuccessMsg').text(resp.company_name + ' has been created with an admin user.');
                $('#addCompanySuccess').show();
                showToast(resp.message);
            } else {
                showToast(resp.error || 'Something went wrong', 'error');
                btn.prop('disabled', false).text('Create Company');
            }
        },
        error: function() {
            showToast('Failed to create company', 'error');
            btn.prop('disabled', false).text('Create Company');
        }
    });
});

function resetAddCompanyForm() {
    $('#addCompanyForm')[0].reset();
    $('#addCompanyForm').show();
    $('#addCompanyActions').show();
    $('#addCompanySuccess').hide();
    $('#addCompanySubmitBtn').prop('disabled', false).text('Create Company');
}

// Close panel via overlay
$('#addCompanyPanelOverlay').on('click', function() {
    closeSidePanel('addCompanyPanel');
});
</script>
JS;
?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
