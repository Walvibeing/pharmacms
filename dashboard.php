<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/includes/helpers.php';

$role = $_SESSION['role'];
$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

// Super Admin stats
if ($role === 'super_admin') {
    $recentRegistrations = fetch_all("SELECT * FROM companies ORDER BY created_at DESC LIMIT 5");
    $recentScreens = fetch_all("SELECT s.*, l.name as location_name, c.name as company_name FROM screens s JOIN locations l ON s.location_id = l.id JOIN companies c ON s.company_id = c.id WHERE s.last_ping > DATE_SUB(NOW(), INTERVAL 30 MINUTE) ORDER BY s.last_ping DESC LIMIT 10");
    $totalCompanies = fetch_one("SELECT COUNT(*) as cnt FROM companies")['cnt'] ?? 0;
    $pendingCompanies = fetch_one("SELECT COUNT(*) as cnt FROM companies WHERE status = 'pending'")['cnt'] ?? 0;
    $totalScreensGlobal = fetch_one("SELECT COUNT(*) as cnt FROM screens")['cnt'] ?? 0;
    $offlineScreensGlobal = fetch_one("SELECT COUNT(*) as cnt FROM screens WHERE last_ping IS NULL OR last_ping < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")['cnt'] ?? 0;
    $totalUsersGlobal = fetch_one("SELECT COUNT(*) as cnt FROM users WHERE is_active = 1")['cnt'] ?? 0;
}

// Company Admin stats
if ($role === 'company_admin') {
    $locations = fetch_all("SELECT l.*, (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count, (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count FROM locations l WHERE l.company_id = ? ORDER BY l.name", [$companyId]);
    $emergencyBroadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE company_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1", [$companyId]);
    $recentActivity = fetch_all("SELECT al.*, u.name as user_name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE al.company_id = ? ORDER BY al.created_at DESC LIMIT 10", [$companyId]);
    $totalScreens = fetch_one("SELECT COUNT(*) as cnt FROM screens WHERE company_id = ?", [$companyId])['cnt'] ?? 0;
    $onlineScreens = fetch_one("SELECT COUNT(*) as cnt FROM screens WHERE company_id = ? AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)", [$companyId])['cnt'] ?? 0;
    $offlineScreens = $totalScreens - $onlineScreens;
    $totalLocations = count($locations ?? []);
    $totalMedia = fetch_one("SELECT COUNT(*) as cnt FROM media WHERE company_id = ?", [$companyId])['cnt'] ?? 0;

    // Getting Started checklist for company admin
    $gsAdminHasLocations = $totalLocations > 0;
    $gsAdminHasMedia = $totalMedia > 0;
    $gsAdminHasPlaylists = (fetch_one("SELECT COUNT(*) as cnt FROM playlists WHERE company_id = ? AND is_active = 1", [$companyId])['cnt'] ?? 0) > 0;
    $gsAdminHasScreens = $totalScreens > 0;
    $gsAdminStepsComplete = (int)$gsAdminHasLocations + (int)$gsAdminHasMedia + (int)$gsAdminHasPlaylists + (int)$gsAdminHasScreens;
    $gsAdminAllComplete = ($gsAdminStepsComplete === 4);
}

// Location Manager stats
if ($role === 'location_manager') {
    $locations = fetch_all(
        "SELECT l.*, (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count, (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count FROM locations l INNER JOIN location_users lu ON l.id = lu.location_id WHERE lu.user_id = ? ORDER BY l.name",
        [$userId]
    );
    $emergencyBroadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE company_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1", [$companyId]);
    $lmTotalScreens = 0;
    $lmOnlineScreens = 0;
    foreach ($locations as $loc) {
        $lmTotalScreens += $loc['screen_count'];
        $lmOnlineScreens += $loc['online_count'];
    }

    // Getting Started checklist data
    $gsHasLocations = count($locations) > 0;
    $gsHasMedia = (fetch_one("SELECT COUNT(*) as cnt FROM media WHERE company_id = ? AND is_active = 1", [$companyId])['cnt'] ?? 0) > 0;
    $gsHasPlaylists = (fetch_one("SELECT COUNT(*) as cnt FROM playlists WHERE company_id = ? AND is_active = 1", [$companyId])['cnt'] ?? 0) > 0;

    $locationIds = array_map(function($l) { return $l['id']; }, $locations);
    $gsHasAssignments = false;
    if (!empty($locationIds)) {
        $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
        $gsHasAssignments = (fetch_one(
            "SELECT COUNT(*) as cnt FROM screen_assignments sa INNER JOIN screens s ON sa.screen_id = s.id WHERE s.location_id IN ($placeholders)",
            $locationIds
        )['cnt'] ?? 0) > 0;
    }

    $gsStepsComplete = (int)$gsHasLocations + (int)$gsHasMedia + (int)$gsHasPlaylists + (int)$gsHasAssignments;
    $gsAllComplete = ($gsStepsComplete === 4);
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($role === 'super_admin'): ?>
<!-- ==================== SUPER ADMIN DASHBOARD ==================== -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Companies</div>
        <div class="stat-value"><?= $totalCompanies ?></div>
        <div class="stat-sub"><?= $pendingCompanies > 0 ? '<a href="' . BASE_URL . 'admin/companies.php?status=pending" style="color:var(--monday-yellow);text-decoration:none">' . $pendingCompanies . ' pending approval</a>' : 'All approved' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Total Screens</div>
        <div class="stat-value"><?= $totalScreensGlobal ?></div>
        <div class="stat-sub"><span class="status-dot online"></span> <?= $totalScreensGlobal - $offlineScreensGlobal ?> online</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Offline Screens</div>
        <div class="stat-value"><?= $offlineScreensGlobal ?></div>
        <div class="stat-sub"><?= $offlineScreensGlobal > 0 ? '<span style="color:var(--monday-red)">Needs attention</span>' : 'All screens online' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Active Users</div>
        <div class="stat-value"><?= $totalUsersGlobal ?></div>
    </div>
</div>

<div class="page-header">
    <div>
        <h1>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= sanitize(explode(' ', $_SESSION['name'] ?? 'Admin')[0]) ?>!</h1>
        <p class="text-muted text-sm mt-1">Here's what's happening across the platform</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>admin/companies.php" class="btn btn-primary">Manage Companies</a>
    </div>
</div>

<div class="grid-2">
    <div class="table-wrapper">
        <div class="table-header" style="display:flex;align-items:center;gap:12px">
            <h3>Recent Registrations</h3>
            <a href="<?= BASE_URL ?>admin/companies.php" class="btn btn-sm btn-outline" style="margin-left:auto">View All</a>
        </div>
        <table>
            <thead>
                <tr><th>Company</th><th>Email</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentRegistrations as $reg): ?>
                <tr>
                    <td><strong><?= sanitize($reg['name']) ?></strong></td>
                    <td class="text-muted"><?= sanitize($reg['email']) ?></td>
                    <td><?= status_badge($reg['status']) ?></td>
                    <td>
                        <?php if ($reg['status'] === 'pending'): ?>
                        <form method="POST" action="<?= BASE_URL ?>admin/approve.php" style="display:inline">
                            <input type="hidden" name="company_id" value="<?= $reg['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-primary">Approve</button>
                        </form>
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>admin/company_view.php?id=<?= $reg['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentRegistrations)): ?>
                <tr><td colspan="4" class="text-center text-muted">No registrations yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-wrapper">
        <div class="table-header" style="display:flex;align-items:center;gap:12px">
            <h3>Recently Active Screens</h3>
            <a href="<?= BASE_URL ?>screens/" class="btn btn-sm btn-outline" style="margin-left:auto">View All</a>
        </div>
        <table>
            <thead>
                <tr><th>Screen</th><th>Company</th><th>Location</th><th>Last Seen</th></tr>
            </thead>
            <tbody>
            <?php foreach ($recentScreens as $scr): ?>
                <tr>
                    <td><span class="status-dot online"></span> <a href="<?= BASE_URL ?>screens/?id=<?= $scr['id'] ?>" style="text-decoration:none;color:inherit"><?= sanitize($scr['name']) ?></a></td>
                    <td class="text-muted"><?= sanitize($scr['company_name']) ?></td>
                    <td class="text-muted"><?= sanitize($scr['location_name']) ?></td>
                    <td class="text-muted text-sm"><?= time_ago($scr['last_ping']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recentScreens)): ?>
                <tr><td colspan="4" class="text-center text-muted">No active screens.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($role === 'company_admin'): ?>
<!-- ==================== COMPANY ADMIN DASHBOARD ==================== -->

<?php if ($emergencyBroadcast): ?>
<div class="alert-emergency">
    <div>
        <strong>EMERGENCY BROADCAST ACTIVE:</strong> <?= sanitize($emergencyBroadcast['title']) ?>
        <span class="text-sm" style="opacity:.8;margin-left:1rem">Started <?= time_ago($emergencyBroadcast['started_at']) ?></span>
    </div>
    <a href="#" class="btn" onclick="event.preventDefault(); showConfirm({title:'End Emergency Broadcast?', message:'This will end the broadcast on all screens. Content will return to normal rotation.', confirmText:'End Broadcast', confirmClass:'btn-danger', onConfirm: function(){ window.location='<?= BASE_URL ?>emergency/end.php?id=<?= $emergencyBroadcast['id'] ?>'; }})">END BROADCAST</a>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= sanitize(explode(' ', $_SESSION['name'] ?? 'User')[0]) ?>!</h1>
        <p class="text-muted text-sm mt-1">Here's your workspace overview</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>media/" class="btn btn-sm btn-outline">Upload Media</a>
        <a href="<?= BASE_URL ?>playlists/" class="btn btn-sm btn-outline">Manage Playlists</a>
        <a href="<?= BASE_URL ?>screens/" class="btn btn-sm btn-outline">View Screens</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Screens</div>
        <div class="stat-value"><?= $totalScreens ?></div>
        <div class="stat-sub"><span class="status-dot online"></span> <?= $onlineScreens ?> online</div>
    </div>
    <div class="stat-card" onclick="window.location='<?= BASE_URL ?>screens/?status=offline'" style="cursor:pointer">
        <div class="stat-label">Offline Screens</div>
        <div class="stat-value"><?= $offlineScreens ?></div>
        <div class="stat-sub"><?= $offlineScreens > 0 ? 'Needs attention' : 'All screens online' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Locations</div>
        <div class="stat-value"><?= $totalLocations ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Media Files</div>
        <div class="stat-value"><?= $totalMedia ?></div>
    </div>
</div>

<?php if (!$gsAdminAllComplete): ?>
<div class="card" id="gettingStartedCard" style="margin-bottom:24px;border-left:4px solid var(--monday-blue)">
    <div style="padding:20px 24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <div>
                <h3 style="font-size:1rem;font-weight:700">Getting Started</h3>
                <p class="text-sm text-muted"><?= $gsAdminStepsComplete ?> of 4 steps complete</p>
            </div>
            <button class="btn btn-sm btn-ghost" onclick="document.getElementById('gettingStartedCard').style.display='none';localStorage.setItem('hideGettingStarted','1')">Dismiss</button>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsAdminHasLocations): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Add a location</div>
                <div class="text-sm text-muted">Add your pharmacy locations to the system</div>
            </div>
            <?php if (!$gsAdminHasLocations): ?>
            <a href="<?= BASE_URL ?>locations/?add" class="btn btn-sm btn-outline">Add Location</a>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsAdminHasMedia): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Upload media</div>
                <div class="text-sm text-muted">Add images or videos for your pharmacy displays</div>
            </div>
            <?php if (!$gsAdminHasMedia): ?>
            <a href="<?= BASE_URL ?>media/" class="btn btn-sm btn-outline">Upload Media</a>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsAdminHasPlaylists): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Create a content rotation</div>
                <div class="text-sm text-muted">Organize media into rotations that play on screens</div>
            </div>
            <?php if (!$gsAdminHasPlaylists): ?>
            <a href="<?= BASE_URL ?>playlists/" class="btn btn-sm btn-outline">Create Rotation</a>
            <?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsAdminHasScreens): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Register a screen</div>
                <div class="text-sm text-muted">Connect a display device to show your content</div>
            </div>
            <?php if (!$gsAdminHasScreens): ?>
            <a href="<?= BASE_URL ?>screens/?add" class="btn btn-sm btn-outline">Add Screen</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="page-header">
    <h2>Locations</h2>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>locations/?add" class="btn btn-primary">+ Add Location</a>
    </div>
</div>

<div class="grid-3">
<?php foreach ($locations as $loc): ?>
    <div class="location-card" onclick="window.location='<?= BASE_URL ?>locations/?view=<?= $loc['id'] ?>'">
        <h4><?= sanitize($loc['name']) ?></h4>
        <div class="meta">
            <?php if ($loc['city']): ?><div><?= sanitize($loc['city']) ?><?= $loc['postcode'] ? ', ' . sanitize($loc['postcode']) : '' ?></div><?php endif; ?>
            <div><?= $loc['screen_count'] ?> screen<?= $loc['screen_count'] !== 1 ? 's' : '' ?></div>
            <div>
                <span class="status-dot <?= $loc['online_count'] > 0 ? 'online' : 'offline' ?>"></span>
                <?= $loc['online_count'] ?> online
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if (empty($locations)): ?>
    <div class="empty-state" style="grid-column:1/-1">
        <h4>No locations yet</h4>
        <p>Add your first pharmacy location to get started.</p>
        <a href="<?= BASE_URL ?>locations/?add" class="btn btn-primary">+ Add Location</a>
    </div>
<?php endif; ?>
</div>

<?php if (!empty($recentActivity)): ?>
<div class="mt-3">
    <div class="table-wrapper">
        <div class="table-header"><h3>Recent Activity</h3></div>
        <table>
            <thead><tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recentActivity as $act): ?>
            <tr>
                <td><?= sanitize($act['user_name'] ?? 'System') ?></td>
                <td><?= sanitize($act['action']) ?></td>
                <td class="text-muted text-sm"><?= sanitize($act['details'] ?? '') ?></td>
                <td class="text-muted text-sm"><?= time_ago($act['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ==================== LOCATION MANAGER DASHBOARD ==================== -->

<?php if (isset($emergencyBroadcast) && $emergencyBroadcast): ?>
<div class="alert-emergency">
    <div>
        <strong>EMERGENCY BROADCAST ACTIVE:</strong> <?= sanitize($emergencyBroadcast['title']) ?>
    </div>
</div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>Good <?= date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening') ?>, <?= sanitize(explode(' ', $_SESSION['name'] ?? 'User')[0]) ?>!</h1>
        <p class="text-muted text-sm mt-1">Your assigned locations</p>
    </div>
    <div class="page-header-actions">
        <a href="<?= BASE_URL ?>media/" class="btn btn-sm btn-outline">Upload Media</a>
        <a href="<?= BASE_URL ?>playlists/" class="btn btn-sm btn-outline">Playlists</a>
        <a href="<?= BASE_URL ?>screens/" class="btn btn-sm btn-outline">View Screens</a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">My Screens</div>
        <div class="stat-value"><?= $lmTotalScreens ?></div>
        <div class="stat-sub"><span class="status-dot online"></span> <?= $lmOnlineScreens ?> online</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Offline</div>
        <div class="stat-value"><?= $lmTotalScreens - $lmOnlineScreens ?></div>
        <div class="stat-sub"><?= ($lmTotalScreens - $lmOnlineScreens) > 0 ? 'Needs attention' : 'All online' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">My Locations</div>
        <div class="stat-value"><?= count($locations) ?></div>
    </div>
</div>

<?php if (!$gsAllComplete): ?>
<div class="card" id="gettingStartedCard" style="margin-bottom:24px;border-left:4px solid var(--monday-blue)">
    <div style="padding:20px 24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
            <div>
                <h3 style="font-size:1rem;font-weight:700">Getting Started</h3>
                <p class="text-sm text-muted"><?= $gsStepsComplete ?> of 4 steps complete</p>
            </div>
            <button class="btn btn-sm btn-ghost" onclick="document.getElementById('gettingStartedCard').style.display='none';localStorage.setItem('hideGettingStarted','1')">Dismiss</button>
        </div>

        <!-- Step 1: Explore your locations -->
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsHasLocations): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Explore your locations</div>
                <div class="text-sm text-muted">View the locations assigned to you</div>
            </div>
            <?php if (!$gsHasLocations): ?>
            <a href="<?= BASE_URL ?>locations/" class="btn btn-sm btn-outline">View Locations</a>
            <?php endif; ?>
        </div>

        <!-- Step 2: Upload media -->
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsHasMedia): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Upload media</div>
                <div class="text-sm text-muted">Add images or videos to your media library</div>
            </div>
            <?php if (!$gsHasMedia): ?>
            <a href="<?= BASE_URL ?>media/" class="btn btn-sm btn-outline">Upload Media</a>
            <?php endif; ?>
        </div>

        <!-- Step 3: Create a playlist -->
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsHasPlaylists): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Create a playlist</div>
                <div class="text-sm text-muted">Organize your media into playlists for screens</div>
            </div>
            <?php if (!$gsHasPlaylists): ?>
            <a href="<?= BASE_URL ?>playlists/" class="btn btn-sm btn-outline">Create Playlist</a>
            <?php endif; ?>
        </div>

        <!-- Step 4: Assign content to a screen -->
        <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-top:1px solid var(--border-light)">
            <?php if ($gsHasAssignments): ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="11" fill="#00ca72"/><path d="M6 11.5l3 3 7-7" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 22 22" fill="none"><circle cx="11" cy="11" r="10" stroke="#c5c7d0" stroke-width="2"/></svg>
            <?php endif; ?>
            <div style="flex:1">
                <div style="font-weight:500;font-size:0.9rem">Assign content to a screen</div>
                <div class="text-sm text-muted">Set a playlist or media to display on your screens</div>
            </div>
            <?php if (!$gsHasAssignments): ?>
            <a href="<?= BASE_URL ?>screens/" class="btn btn-sm btn-outline">Manage Screens</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid-3">
<?php foreach ($locations as $loc): ?>
    <div class="location-card" onclick="window.location='<?= BASE_URL ?>locations/?view=<?= $loc['id'] ?>'">
        <h4><?= sanitize($loc['name']) ?></h4>
        <div class="meta">
            <?php if ($loc['city']): ?><div><?= sanitize($loc['city']) ?></div><?php endif; ?>
            <div><?= $loc['screen_count'] ?> screen<?= $loc['screen_count'] !== 1 ? 's' : '' ?></div>
            <div>
                <span class="status-dot <?= $loc['online_count'] > 0 ? 'online' : 'offline' ?>"></span>
                <?= $loc['online_count'] ?> online
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if (empty($locations)): ?>
    <div class="empty-state" style="grid-column:1/-1">
        <h4>No locations assigned</h4>
        <p>Contact your company admin to be assigned to a location.</p>
    </div>
<?php endif; ?>
</div>

<?php endif; ?>

<script>
(function() {
    if (localStorage.getItem('hideGettingStarted') === '1') {
        var card = document.getElementById('gettingStartedCard');
        if (card) card.style.display = 'none';
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
