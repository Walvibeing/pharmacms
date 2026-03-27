<?php
$pageTitle = 'Content Schedules';
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$filterScreen = (int)($_GET['screen_id'] ?? 0);
$filterLocation = (int)($_GET['location_id'] ?? 0);

$userLocations = get_user_locations($userId);
$locationIds = array_column($userLocations, 'id');

if ($isSuperAdmin) {
    $screens = fetch_all(
        "SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id ORDER BY l.name, s.name"
    );

    $where = "1=1";
    $params = [];

    if ($filterScreen) {
        $where .= " AND sc.screen_id = ?";
        $params[] = $filterScreen;
    } elseif ($filterLocation) {
        $where .= " AND sc.screen_id IN (SELECT id FROM screens WHERE location_id = ?)";
        $params[] = $filterLocation;
    }

    $schedules = fetch_all(
        "SELECT sc.*, s.name as screen_name, l.name as location_name, co.name as company_name,
            COALESCE(p.name, m.name) as content_name
         FROM schedules sc
         JOIN screens s ON sc.screen_id = s.id
         JOIN locations l ON s.location_id = l.id
         JOIN companies co ON sc.company_id = co.id
         LEFT JOIN playlists p ON sc.playlist_id = p.id
         LEFT JOIN media m ON sc.media_id = m.id
         WHERE {$where} AND sc.is_active = 1
         ORDER BY sc.start_datetime ASC",
        $params
    );
} elseif (empty($locationIds)) {
    $schedules = [];
    $screens = [];
} else {
    $placeholders = implode(',', array_fill(0, count($locationIds), '?'));

    $screens = fetch_all(
        "SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.location_id IN ({$placeholders}) ORDER BY l.name, s.name",
        $locationIds
    );

    $where = "sc.company_id = ?";
    $params = [$companyId];

    if ($filterScreen) {
        $where .= " AND sc.screen_id = ?";
        $params[] = $filterScreen;
    } elseif ($filterLocation) {
        $where .= " AND sc.screen_id IN (SELECT id FROM screens WHERE location_id = ?)";
        $params[] = $filterLocation;
    }

    $schedules = fetch_all(
        "SELECT sc.*, s.name as screen_name, l.name as location_name,
            COALESCE(p.name, m.name) as content_name
         FROM schedules sc
         JOIN screens s ON sc.screen_id = s.id
         JOIN locations l ON s.location_id = l.id
         LEFT JOIN playlists p ON sc.playlist_id = p.id
         LEFT JOIN media m ON sc.media_id = m.id
         WHERE {$where} AND sc.is_active = 1
         ORDER BY sc.start_datetime ASC",
        $params
    );
}

// Separate upcoming vs past
$now = date('Y-m-d H:i:s');
$upcoming = array_filter($schedules, fn($s) => $s['end_datetime'] >= $now);
$past = array_filter($schedules, fn($s) => $s['end_datetime'] < $now);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Content Schedules</h1>
    <div class="page-header-actions" style="position:relative">
        <div class="search-wrapper location-filter-wrapper">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <input type="text" class="search-input" id="scheduleLocationSearch" placeholder="All Locations" autocomplete="off" value="<?php if ($filterLocation) { foreach ($userLocations as $loc) { if ($loc['id'] == $filterLocation) echo sanitize($loc['name']); } } ?>">
            <div id="scheduleLocationResults" class="search-dropdown"></div>
        </div>
        <div class="search-wrapper location-filter-wrapper">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            <input type="text" class="search-input" id="scheduleScreenSearch" placeholder="All Screens" autocomplete="off" value="<?php if ($filterScreen) { foreach ($screens as $scr) { if ($scr['id'] == $filterScreen) echo sanitize($scr['name']); } } ?>">
            <div id="scheduleScreenResults" class="search-dropdown"></div>
        </div>
        <a href="<?= BASE_URL ?>screens/" class="btn btn-outline">Manage via Screen View</a>
    </div>
</div>

<div class="table-wrapper mb-2">
    <div class="table-header"><h3>Upcoming Schedules</h3></div>
    <table>
        <thead>
            <tr><th>Name</th><th>Screen</th><th>Location</th><th>Content</th><th>Start</th><th>End</th><th>Repeat</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($upcoming as $sch): ?>
        <tr style="border-left:3px solid var(--monday-blue)">
            <td><strong><?= sanitize($sch['name'] ?? 'Untitled') ?></strong></td>
            <td class="text-muted"><?= sanitize($sch['screen_name']) ?></td>
            <td class="text-muted"><?= sanitize($sch['location_name']) ?></td>
            <td><?= sanitize($sch['content_name'] ?? 'N/A') ?></td>
            <td class="text-sm"><?= date('M j g:ia', strtotime($sch['start_datetime'])) ?></td>
            <td class="text-sm"><?= date('M j g:ia', strtotime($sch['end_datetime'])) ?></td>
            <td><?= ucfirst($sch['repeat_type']) ?></td>
            <td>
                <form method="POST" action="<?= BASE_URL ?>schedules/delete.php" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $sch['id'] ?>">
                    <input type="hidden" name="redirect" value="<?= BASE_URL ?>schedules/">
                    <button type="submit" class="btn btn-sm btn-danger-outline btn-delete-confirm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($upcoming)): ?>
        <tr><td colspan="8">
            <div class="empty-state" style="padding:48px 32px">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="12 14 12 18"/><polyline points="10 16 12 14 14 16"/></svg>
                <h4>No upcoming schedules</h4>
                <p>Schedules let you automatically switch content on your screens at specific times. Create schedules from the Screens page.</p>
                <a href="<?= BASE_URL ?>screens/" class="btn btn-primary">Go to Screens</a>
            </div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if (!empty($past)): ?>
<details class="table-wrapper">
    <summary class="table-header" style="cursor:pointer"><h3>Past Schedules (<?= count($past) ?>)</h3></summary>
    <table>
        <thead>
            <tr><th>Name</th><th>Screen</th><th>Content</th><th>Start</th><th>End</th></tr>
        </thead>
        <tbody>
        <?php foreach ($past as $sch): ?>
        <tr style="opacity:0.6;border-left:3px solid var(--border-default)">
            <td><?= sanitize($sch['name'] ?? 'Untitled') ?></td>
            <td class="text-muted"><?= sanitize($sch['screen_name']) ?></td>
            <td class="text-muted"><?= sanitize($sch['content_name'] ?? '') ?></td>
            <td class="text-sm"><?= date('M j g:ia', strtotime($sch['start_datetime'])) ?></td>
            <td class="text-sm"><?= date('M j g:ia', strtotime($sch['end_datetime'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
