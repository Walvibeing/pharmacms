<?php
$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['super_admin']);

$pendingCompanies = fetch_one("SELECT COUNT(*) as c FROM companies WHERE status = 'pending'")['c'];
$activeCompanies = fetch_one("SELECT COUNT(*) as c FROM companies WHERE status = 'active'")['c'];
$suspendedCompanies = fetch_one("SELECT COUNT(*) as c FROM companies WHERE status = 'suspended'")['c'];

$totalScreens = fetch_one("SELECT COUNT(*) as c FROM screens")['c'];
$onlineScreens = fetch_one("SELECT COUNT(*) as c FROM screens WHERE last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")['c'];

$recentRegistrations = fetch_all("SELECT * FROM companies ORDER BY created_at DESC LIMIT 10");
$recentActivity = fetch_all("SELECT al.*, u.name as user_name, c.name as company_name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id LEFT JOIN companies c ON al.company_id = c.id ORDER BY al.created_at DESC LIMIT 15");

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>System Administration</h1>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Companies by Status</h3></div>
        <div class="card-body" style="text-align:center">
            <canvas id="companiesChart" height="200"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Screen Status</h3></div>
        <div class="card-body" style="text-align:center">
            <canvas id="screensChart" height="200"></canvas>
        </div>
    </div>
</div>

<div class="grid-2 mt-2">
    <div class="table-wrapper">
        <div class="table-header">
            <h3>Recent Registrations</h3>
            <a href="<?= BASE_URL ?>admin/companies.php" class="btn btn-sm btn-outline">View All</a>
        </div>
        <table>
            <thead><tr><th>Company</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($recentRegistrations as $reg): ?>
            <tr>
                <td><strong><?= sanitize($reg['name']) ?></strong><br><span class="text-xs text-muted"><?= sanitize($reg['email']) ?></span></td>
                <td><?= status_badge($reg['status']) ?></td>
                <td class="text-sm text-muted"><?= time_ago($reg['created_at']) ?></td>
                <td>
                    <?php if ($reg['status'] === 'pending'): ?>
                    <form method="POST" action="<?= BASE_URL ?>admin/approve.php" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="company_id" value="<?= $reg['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                    </form>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>admin/company_view.php?id=<?= $reg['id'] ?>" class="btn btn-sm btn-outline">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrapper">
        <div class="table-header"><h3>Recent Activity</h3></div>
        <table>
            <thead><tr><th>User</th><th>Company</th><th>Action</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $act): ?>
            <tr>
                <td class="text-sm"><?= sanitize($act['user_name'] ?? 'System') ?></td>
                <td class="text-sm text-muted"><?= sanitize($act['company_name'] ?? '—') ?></td>
                <td class="text-sm"><?= sanitize($act['action']) ?></td>
                <td class="text-xs text-muted"><?= time_ago($act['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extraScripts = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('companiesChart'), {
    type: 'doughnut',
    data: {
        labels: ['Active', 'Pending', 'Suspended'],
        datasets: [{
            data: [{$activeCompanies}, {$pendingCompanies}, {$suspendedCompanies}],
            backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

var offlineScreens = {$totalScreens} - {$onlineScreens};
new Chart(document.getElementById('screensChart'), {
    type: 'bar',
    data: {
        labels: ['Online', 'Offline'],
        datasets: [{
            data: [{$onlineScreens}, offlineScreens],
            backgroundColor: ['#10b981', '#ef4444'],
            borderRadius: 6,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
    }
});
</script>
SCRIPT;

include __DIR__ . '/../includes/footer.php';
?>
