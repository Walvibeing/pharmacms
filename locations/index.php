<?php
$pageTitle = 'Locations';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$isSuperAdmin = is_super_admin();
$showAddPanel = isset($_GET['add']);

// Handle AJAX: load managers for a company (super admin)
if (isset($_GET['ajax_managers'])) {
    header('Content-Type: application/json');
    $cid = (int)$_GET['company_id'];
    if (!$isSuperAdmin || !$cid) { echo json_encode([]); exit; }
    $managers = fetch_all("SELECT id, name, email FROM users WHERE company_id = ? AND role = 'location_manager' AND is_active = 1 ORDER BY name", [$cid]);
    echo json_encode($managers);
    exit;
}

// Handle add location POST
$errors = [];
$newLocationId = null;
$newLocationName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_location'])) {
    if (!verify_csrf()) { $errors[] = 'Invalid security token.'; }

    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $postcode = trim($_POST['postcode'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $contactEmail = trim($_POST['contact_email'] ?? '');
    $contactPhone = trim($_POST['contact_phone'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $assignedManagers = $_POST['managers'] ?? [];

    if (empty($name)) $errors[] = 'Location name is required.';

    $insertCompanyId = $companyId;
    if ($isSuperAdmin) {
        $insertCompanyId = (int)($_POST['company_id'] ?? 0);
        if (!$insertCompanyId) $errors[] = 'Please select a company.';
    }

    if (empty($errors)) {
        $locationId = insert('locations', [
            'company_id' => $insertCompanyId,
            'name' => $name,
            'address' => $address,
            'city' => $city,
            'postcode' => $postcode,
            'contact_name' => $contactName,
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'is_active' => $isActive
        ]);

        foreach ($assignedManagers as $managerId) {
            $managerId = (int)$managerId;
            $valid = fetch_one("SELECT id FROM users WHERE id = ? AND company_id = ? AND role = 'location_manager'", [$managerId, $insertCompanyId]);
            if ($valid) {
                insert('location_users', ['location_id' => $locationId, 'user_id' => $managerId]);
            }
        }

        log_activity('location_created', "Created location: {$name}");
        $newLocationId = $locationId;
        $newLocationName = $name;
        $showAddPanel = true;
    } else {
        $showAddPanel = true;
    }
}

// Super admin: fetch companies for the add form
if ($isSuperAdmin) {
    $companies = fetch_all("SELECT * FROM companies WHERE status = 'active' ORDER BY name");
} else {
    $managers = fetch_all("SELECT id, name, email FROM users WHERE company_id = ? AND role = 'location_manager' AND is_active = 1 ORDER BY name", [$companyId]);
}

// Fetch locations list
if ($isSuperAdmin) {
    $locations = fetch_all(
        "SELECT l.*, c.name as company_name,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
            (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
         FROM locations l
         JOIN companies c ON l.company_id = c.id
         ORDER BY c.name, l.name"
    );
} elseif ($_SESSION['role'] === 'company_admin') {
    $locations = fetch_all(
        "SELECT l.*,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
            (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
         FROM locations l WHERE l.company_id = ? ORDER BY l.name",
        [$companyId]
    );
} else {
    $locations = fetch_all(
        "SELECT l.*,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count,
            (SELECT COUNT(*) FROM screens WHERE location_id = l.id AND last_ping > DATE_SUB(NOW(), INTERVAL 5 MINUTE)) as online_count,
            (SELECT COUNT(*) FROM media_locations WHERE location_id = l.id) as media_count
         FROM locations l
         INNER JOIN location_users lu ON l.id = lu.location_id
         WHERE lu.user_id = ? ORDER BY l.name",
        [$userId]
    );
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Locations</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="locationSearchInput" placeholder="Search by name, city<?= $isSuperAdmin ? ', company' : '' ?>...">
        </div>
        <select class="form-control" id="locationStatusFilter">
            <option value="all">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <?php if ($_SESSION['role'] === 'company_admin' || $isSuperAdmin): ?>
        <button class="btn btn-primary" id="openAddLocationPanel">+ Add Location</button>
        <?php endif; ?>
    </div>
</div>

<div class="table-wrapper">
    <table id="locationsTable">
        <thead>
            <tr>
                <?php if ($isSuperAdmin): ?><th>Company</th><?php endif; ?>
                <th>Name</th>
                <th>City</th>
                <th>Postcode</th>
                <th>Screens</th>
                <th>Online</th>
                <th>Media</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($locations as $loc): ?>
            <tr class="location-row row-border-<?= $loc['online_count'] > 0 ? 'green' : ($loc['is_active'] ? 'yellow' : 'muted') ?>" onclick="openLocationPanel(<?= $loc['id'] ?>)" role="button" tabindex="0" style="cursor:pointer" data-name="<?= sanitize(strtolower($loc['name'])) ?>" data-city="<?= sanitize(strtolower($loc['city'] ?? '')) ?>" data-company="<?= $isSuperAdmin ? sanitize(strtolower($loc['company_name'] ?? '')) : '' ?>" data-status="<?= $loc['is_active'] ? 'active' : 'inactive' ?>">
                <?php if ($isSuperAdmin): ?><td class="text-muted text-sm"><?= sanitize($loc['company_name'] ?? '') ?></td><?php endif; ?>
                <td><strong><?= sanitize($loc['name']) ?></strong></td>
                <td class="text-muted"><?= sanitize($loc['city'] ?? '') ?></td>
                <td class="text-muted"><?= sanitize($loc['postcode'] ?? '') ?></td>
                <td><?= $loc['screen_count'] ?></td>
                <td>
                    <span class="status-dot <?= $loc['online_count'] > 0 ? 'online' : 'offline' ?>"></span>
                    <?= $loc['online_count'] ?> <?= $loc['online_count'] > 0 ? 'online' : 'offline' ?>
                </td>
                <td class="text-muted"><?= (int)$loc['media_count'] ?> <?= (int)$loc['media_count'] === 1 ? 'file' : 'files' ?></td>
                <td><?= status_badge($loc['is_active'] ? 'active' : 'inactive') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($locations)): ?>
    <div class="empty-state" id="locationsEmptyState">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
            <circle cx="12" cy="10" r="3"/>
        </svg>
        <h4>No locations yet</h4>
        <p>Locations represent your physical sites (pharmacies, stores). Add a location, then create screens within it to manage your displays.</p>
        <?php if ($_SESSION['role'] === 'company_admin' || $isSuperAdmin): ?>
        <button class="btn btn-primary" id="emptyStateAddBtn" onclick="document.getElementById('openAddLocationPanel').click()">+ Add Location</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div id="locationsPager"></div>
    <!-- No-results state for search/filter (hidden by default) -->
    <div class="empty-state" id="locationsNoResults" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="8" y1="8" x2="14" y2="14"/><line x1="14" y1="8" x2="8" y2="14"/>
        </svg>
        <h4>No matching locations</h4>
        <p>Try adjusting your search terms or status filter to find what you are looking for.</p>
    </div>
</div>

<!-- ==================== SIDE PANEL — Add Location ==================== -->
<div class="side-panel-overlay <?= $showAddPanel ? 'active' : '' ?>" id="addLocationPanelOverlay"></div>
<div class="side-panel <?= $showAddPanel ? 'active' : '' ?>" id="addLocationPanel">
    <div class="side-panel-header">
        <h2><?= $newLocationId ? 'Location Created' : 'Add Location' ?></h2>
        <button class="side-panel-close" id="closeAddLocationPanel">&times;</button>
    </div>

    <div class="side-panel-body">
        <?php if ($newLocationId): ?>
        <!-- Success state -->
        <div style="text-align:center;padding:16px 0">
            <div style="width:56px;height:56px;border-radius:var(--radius-full);background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" width="28" height="28">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h3 style="margin-bottom:4px;font-size:1.1rem">Location Created!</h3>
            <p class="text-muted text-sm mb-2"><?= sanitize($newLocationName) ?> has been added successfully.</p>
            <div class="btn-group mt-2" style="justify-content:center">
                <button class="btn btn-primary" onclick="closeSidePanel('addLocationPanel'); openLocationPanel(<?= $newLocationId ?>)">View Location</button>
                <a href="<?= BASE_URL ?>locations/?add" class="btn btn-outline">Add Another</a>
            </div>
        </div>
        <?php else: ?>
        <!-- Add form -->
        <?php if ($errors): ?>
            <div class="alert alert-danger" style="margin-bottom:16px"><?php foreach($errors as $e) echo sanitize($e) . '<br>'; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>locations/?add">
            <?= csrf_field() ?>
            <input type="hidden" name="add_location" value="1">

            <?php if ($isSuperAdmin): ?>
            <div class="form-group">
                <label for="company_id">Company *</label>
                <select id="company_id" name="company_id" class="form-control" required>
                    <option value="">Select a company</option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['id'] ?>" <?= ($_POST['company_id'] ?? 0) == $co['id'] ? 'selected' : '' ?>><?= sanitize($co['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="loc_name">Location Name *</label>
                <input type="text" id="loc_name" name="name" class="form-control" value="<?= sanitize($_POST['name'] ?? '') ?>" required placeholder="e.g. Main Street Pharmacy" autofocus>
            </div>

            <?php $hasOptionalValues = !empty($_POST['city']) || !empty($_POST['postcode']) || !empty($_POST['address']) || !empty($_POST['contact_name']) || !empty($_POST['contact_email']) || !empty($_POST['contact_phone']); ?>
            <button type="button" class="btn btn-ghost btn-sm" id="toggleOptionalFields" style="margin-bottom:12px;color:var(--text-secondary);gap:4px" onclick="var s=document.getElementById('optionalFields');var open=s.style.display!=='none';s.style.display=open?'none':'';this.querySelector('.chevron-icon').style.transform=open?'':'rotate(90deg)';this.querySelector('.toggle-label').textContent=open?'Show optional fields':'Hide optional fields'">
                <svg class="chevron-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="transition:transform 200ms;<?= $hasOptionalValues ? 'transform:rotate(90deg)' : '' ?>"><polyline points="9 18 15 12 9 6"/></svg>
                <span class="toggle-label"><?= $hasOptionalValues ? 'Hide optional fields' : 'Show optional fields' ?></span>
            </button>

            <div id="optionalFields" style="display:<?= $hasOptionalValues ? '' : 'none' ?>">
            <div class="form-group">
                <label for="loc_city">City</label>
                <input type="text" id="loc_city" name="city" class="form-control" value="<?= sanitize($_POST['city'] ?? '') ?>" placeholder="e.g. Sydney">
            </div>

            <div class="form-group">
                <label for="loc_postcode">Postcode</label>
                <input type="text" id="loc_postcode" name="postcode" class="form-control" value="<?= sanitize($_POST['postcode'] ?? '') ?>" placeholder="e.g. 2000">
            </div>

            <div class="form-group">
                <label for="loc_address">Address</label>
                <textarea id="loc_address" name="address" class="form-control" rows="2" placeholder="Full street address"><?= sanitize($_POST['address'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="loc_contact_name">Contact Name</label>
                <input type="text" id="loc_contact_name" name="contact_name" class="form-control" value="<?= sanitize($_POST['contact_name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="loc_contact_email">Contact Email</label>
                <input type="email" id="loc_contact_email" name="contact_email" class="form-control" value="<?= sanitize($_POST['contact_email'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="loc_contact_phone">Contact Phone</label>
                <input type="text" id="loc_contact_phone" name="contact_phone" class="form-control" value="<?= sanitize($_POST['contact_phone'] ?? '') ?>">
            </div>
            </div>

            <!-- Manager assignment area -->
            <div id="managersSection">
                <?php if (!$isSuperAdmin && !empty($managers)): ?>
                <div class="form-group">
                    <label>Assign Location Managers</label>
                    <?php foreach ($managers as $mgr): ?>
                    <div class="form-check mb-1">
                        <input type="checkbox" name="managers[]" value="<?= $mgr['id'] ?>" id="mgr_<?= $mgr['id'] ?>">
                        <label for="mgr_<?= $mgr['id'] ?>"><?= sanitize($mgr['name']) ?> (<?= sanitize($mgr['email']) ?>)</label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="is_active" id="loc_is_active" checked>
                    <label for="loc_is_active">Active</label>
                </div>
            </div>

            <div class="side-panel-actions" style="padding:0;border:none;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">Create Location</button>
                <button type="button" class="btn btn-outline" id="cancelAddLocationPanel">Cancel</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== SIDE PANEL — Location (tabbed) ==================== -->
<div class="side-panel-overlay" id="locationPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="locationPanel">
    <div class="side-panel-header" style="padding-bottom:0;border-bottom:none">
        <div style="width:100%">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <h2 id="locationPanelTitle">Location</h2>
                <button class="side-panel-close" onclick="closeSidePanel('locationPanel')">&times;</button>
            </div>
            <div class="panel-tabs" id="locationTabs">
                <button class="panel-tab active" data-tab="details">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="16" height="16"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Details
                </button>
                <button class="panel-tab" data-tab="screens">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="16" height="16"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    Screens
                </button>
            </div>
        </div>
    </div>
    <div class="side-panel-body" id="locationPanelBody">
        <div class="text-center text-muted" style="padding:3rem">Loading...</div>
    </div>
</div>

<!-- ==================== SIDE PANEL — Add Screen (from location) ==================== -->
<div class="side-panel-overlay" id="addScreenPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="addScreenPanel">
    <div class="side-panel-header">
        <h2 id="addScreenTitle">Add Screen</h2>
        <button class="side-panel-close">&times;</button>
    </div>
    <div class="side-panel-body" id="addScreenBody">
    </div>
</div>

<!-- ==================== SIDE PANEL — Manage Screen (from location) ==================== -->
<div class="side-panel-overlay" id="manageScreenPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="manageScreenPanel">
    <div class="side-panel-header" style="padding-bottom:0;border-bottom:none">
        <div style="width:100%">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <h2 id="manageScreenTitle">Manage Screen</h2>
                <button class="side-panel-close">&times;</button>
            </div>
            <div class="panel-tabs" id="screenTabs">
                <button class="panel-tab active" data-tab="settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Settings
                </button>
                <button class="panel-tab" data-tab="content">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    Content
                </button>
                <button class="panel-tab" data-tab="schedule">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Schedule
                </button>
            </div>
        </div>
    </div>
    <div class="side-panel-body" id="manageScreenBody">
        <div class="text-center text-muted" style="padding:3rem">Loading...</div>
    </div>
    <div class="side-panel-footer" id="manageScreenFooter" style="display:none"></div>
</div>

<?php
$baseUrl = BASE_URL;
$isSuperAdminJs = $isSuperAdmin ? 'true' : 'false';
$csrfToken = csrf_token();
$canEdit = ($isSuperAdmin || $_SESSION['role'] === 'company_admin') ? 'true' : 'false';
$locationsJson = json_encode(array_map(function($l) { return ['id' => $l['id'], 'name' => $l['name']]; }, $locations));
$extraScripts = <<<JS
<script>
// ── Locations Search & Filter ──
(function() {
    var searchInput = document.getElementById('locationSearchInput');
    var statusFilter = document.getElementById('locationStatusFilter');
    var filterCount = document.getElementById('locationFilterCount');
    var noResults = document.getElementById('locationsNoResults');
    var table = document.getElementById('locationsTable');

    if (!searchInput || !statusFilter) return;

    function filterLocations() {
        var query = searchInput.value.toLowerCase().trim();
        var status = statusFilter.value;
        var rows = table ? table.querySelectorAll('tbody tr.location-row') : [];
        var visibleCount = 0;
        var totalCount = rows.length;

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var name = row.getAttribute('data-name') || '';
            var city = row.getAttribute('data-city') || '';
            var company = row.getAttribute('data-company') || '';
            var rowStatus = row.getAttribute('data-status') || '';

            var matchesSearch = !query || name.indexOf(query) !== -1 || city.indexOf(query) !== -1 || company.indexOf(query) !== -1;
            var matchesStatus = status === 'all' || rowStatus === status;

            if (matchesSearch && matchesStatus) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }

        // Show/hide no-results state
        if (noResults) {
            noResults.style.display = (totalCount > 0 && visibleCount === 0) ? '' : 'none';
        }

        // Update filter count text
        if (filterCount) {
            if (query || status !== 'all') {
                filterCount.textContent = visibleCount + ' of ' + totalCount + ' location' + (totalCount !== 1 ? 's' : '');
            } else {
                filterCount.textContent = '';
            }
        }
    }

    // Initialize table paginator
    var locPager = typeof TablePaginator !== 'undefined' ? new TablePaginator({
        tableBody: '#locationsTable tbody',
        pager: '#locationsPager',
        perPage: 25
    }) : null;

    function filterAndPaginate() {
        filterLocations();
        if (locPager) {
            locPager.currentPage = 1;
            locPager.refresh();
        }
    }

    searchInput.addEventListener('input', filterAndPaginate);
    statusFilter.addEventListener('change', filterAndPaginate);

    // Initial pagination
    if (locPager) locPager.refresh();
})();
</script>
<script>
$(document).ready(function() {
    var isSuperAdmin = {$isSuperAdminJs};
    var baseUrl = '{$baseUrl}';
    var csrfToken = '{$csrfToken}';
    var canEdit = {$canEdit};
    var userLocations = {$locationsJson};

    // escapeHtml() is now provided globally by app.js

    function fmtDuration(secs) {
        secs = parseInt(secs) || 0;
        if (secs >= 3600) return Math.floor(secs/3600) + 'h ' + Math.floor((secs%3600)/60) + 'm';
        if (secs >= 60) return Math.floor(secs/60) + 'm ' + (secs%60) + 's';
        return secs + 's';
    }
    function fmtBytes(b) {
        b = parseInt(b) || 0;
        if (b >= 1048576) return (b/1048576).toFixed(1) + ' MB';
        if (b >= 1024) return Math.round(b/1024) + ' KB';
        return b + ' B';
    }

    // ── Add Location Panel ──
    $('#openAddLocationPanel').on('click', function() {
        openSidePanel('addLocationPanel');
    });

    $('#closeAddLocationPanel, #cancelAddLocationPanel').on('click', function() {
        closeSidePanel('addLocationPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    $('#addLocationPanelOverlay').on('click', function() {
        closeSidePanel('addLocationPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    // Super admin: load managers when company changes (add panel)
    if (isSuperAdmin) {
        $('#company_id').on('change', function() {
            var companyId = $(this).val();
            var section = $('#managersSection');
            section.html('');

            if (!companyId) return;

            $.getJSON(baseUrl + 'locations/?ajax_managers=1&company_id=' + companyId, function(managers) {
                if (managers.length === 0) return;

                var html = '<div class="form-group"><label>Assign Location Managers</label>';
                managers.forEach(function(mgr) {
                    html += '<div class="form-check mb-1">' +
                        '<input type="checkbox" name="managers[]" value="' + mgr.id + '" id="mgr_' + mgr.id + '">' +
                        '<label for="mgr_' + mgr.id + '">' + mgr.name + ' (' + mgr.email + ')</label>' +
                        '</div>';
                });
                html += '</div>';
                section.html(html);
            });
        });
    }

    // Close overlay for location panel
    $('#locationPanelOverlay').on('click', function() { closeSidePanel('locationPanel'); });

    // Location panel tab switching
    $(document).on('click', '#locationTabs .panel-tab', function() {
        var tab = $(this).data('tab');
        $('#locationTabs .panel-tab').removeClass('active');
        $(this).addClass('active');
        $('#locationPanelBody .panel-tab-content').hide();
        $('#loc-tab-' + tab).show();
    });

    // Track current location for back-navigation
    var currentViewLocationId = null;

    window.backToViewLocation = function() {
        closeSidePanel('manageScreenPanel', true);
        closeSidePanel('addScreenPanel', true);
        if (currentViewLocationId) {
            setTimeout(function() {
                openLocationPanel(currentViewLocationId, 'screens');
            }, 50);
        }
    };

    // ── Location Panel (tabbed: Details + Screens) ──
    window.openLocationPanel = function(locationId, startTab) {
        currentViewLocationId = locationId;
        closeAllSidePanels();
        $('#locationPanelTitle').text('Location');
        $('#locationTabs').hide();
        $('#locationTabs .panel-tab').first().addClass('active').siblings().removeClass('active');
        $('#locationPanelBody').html('<div class="text-center text-muted" style="padding:3rem">Loading...</div>');
        openSidePanel('locationPanel');

        $.getJSON(baseUrl + 'locations/api.php?action=get&id=' + locationId, function(data) {
            if (!data.success) {
                $('#locationPanelBody').html('<div class="alert alert-danger">' + escapeHtml(data.message) + '</div>');
                return;
            }

            var loc = data.location;
            var screens = data.screens;
            var managers = data.assigned_managers;
            var allManagers = data.all_managers;
            var assignedIds = data.assigned_ids;

            // Title with address subtitle
            var addrParts = [];
            if (loc.city) addrParts.push(escapeHtml(loc.city));
            if (loc.postcode) addrParts.push(escapeHtml(loc.postcode));
            var subtitle = addrParts.length ? '<div style="font-size:0.78rem;font-weight:400;color:var(--text-secondary);margin-top:2px">' + addrParts.join(', ') + '</div>' : '';
            $('#locationPanelTitle').html(escapeHtml(loc.name) + subtitle);
            $('#locationTabs').show();

            // Pulse style for online dots
            if (!document.getElementById('viewLocPulseStyle')) {
                var styleEl = document.createElement('style');
                styleEl.id = 'viewLocPulseStyle';
                styleEl.textContent = '@keyframes statusPulse{0%{box-shadow:0 0 0 0 rgba(0,200,117,0.45)}70%{box-shadow:0 0 0 6px rgba(0,200,117,0)}100%{box-shadow:0 0 0 0 rgba(0,200,117,0)}}';
                document.head.appendChild(styleEl);
            }

            /* ========================================
               TAB 1: Details (edit form)
               ======================================== */
            var detailsHtml = '<div id="loc-tab-details" class="panel-tab-content">';
            detailsHtml += '<form id="editLocationForm" onsubmit="return saveLocation(event, ' + loc.id + ')">';
            detailsHtml += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
            detailsHtml += '<input type="hidden" name="action" value="update">';
            detailsHtml += '<input type="hidden" name="id" value="' + loc.id + '">';

            detailsHtml += '<div class="form-group"><label>Location Name *</label>';
            detailsHtml += '<input type="text" name="name" class="form-control" value="' + escapeHtml(loc.name) + '" required></div>';

            detailsHtml += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
            detailsHtml += '<div class="form-group"><label>City</label>';
            detailsHtml += '<input type="text" name="city" class="form-control" value="' + escapeHtml(loc.city || '') + '"></div>';
            detailsHtml += '<div class="form-group"><label>Postcode</label>';
            detailsHtml += '<input type="text" name="postcode" class="form-control" value="' + escapeHtml(loc.postcode || '') + '"></div></div>';

            detailsHtml += '<div class="form-group"><label>Address</label>';
            detailsHtml += '<textarea name="address" class="form-control" rows="2">' + escapeHtml(loc.address || '') + '</textarea></div>';

            detailsHtml += '<div class="form-group"><label>Contact Name</label>';
            detailsHtml += '<input type="text" name="contact_name" class="form-control" value="' + escapeHtml(loc.contact_name || '') + '"></div>';

            detailsHtml += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">';
            detailsHtml += '<div class="form-group"><label>Contact Email</label>';
            detailsHtml += '<input type="email" name="contact_email" class="form-control" value="' + escapeHtml(loc.contact_email || '') + '"></div>';
            detailsHtml += '<div class="form-group"><label>Contact Phone</label>';
            detailsHtml += '<input type="text" name="contact_phone" class="form-control" value="' + escapeHtml(loc.contact_phone || '') + '"></div></div>';

            // Manager assignments
            if (allManagers && allManagers.length > 0) {
                detailsHtml += '<div class="form-group"><label>Assign Location Managers</label>';
                allManagers.forEach(function(mgr) {
                    var checked = assignedIds && assignedIds.indexOf(mgr.id) !== -1 ? ' checked' : '';
                    detailsHtml += '<div class="form-check mb-1">';
                    detailsHtml += '<input type="checkbox" name="managers[]" value="' + mgr.id + '" id="edit_mgr_' + mgr.id + '"' + checked + '>';
                    detailsHtml += '<label for="edit_mgr_' + mgr.id + '">' + escapeHtml(mgr.name) + ' (' + escapeHtml(mgr.email) + ')</label>';
                    detailsHtml += '</div>';
                });
                detailsHtml += '</div>';
            }

            detailsHtml += '<div class="form-group"><div class="form-check">';
            detailsHtml += '<input type="hidden" name="is_active" value="0">';
            detailsHtml += '<input type="checkbox" name="is_active" id="edit_loc_active" value="1"' + (loc.is_active == 1 ? ' checked' : '') + '>';
            detailsHtml += '<label for="edit_loc_active">Active</label>';
            detailsHtml += '</div></div>';

            detailsHtml += '<div id="editLocationMessage"></div>';
            detailsHtml += '<button type="submit" class="btn btn-primary" id="editLocationSaveBtn" style="width:100%">Save Changes</button>';
            detailsHtml += '</form></div>';

            /* ========================================
               TAB 2: Screens
               ======================================== */
            var screensHtml = '<div id="loc-tab-screens" class="panel-tab-content" style="display:none">';

            screensHtml += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">' +
                '<div style="font-size:0.78rem;color:var(--text-secondary);font-weight:500">' + screens.length + ' screen' + (screens.length !== 1 ? 's' : '') + ' at this location</div>' +
                '<button class="btn btn-sm btn-primary" onclick="openAddScreenForLocation(' + loc.id + ', \'' + escapeHtml(loc.name).replace(/'/g, "\\'") + '\')">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:4px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Screen</button>' +
            '</div>';

            if (screens.length === 0) {
                screensHtml += '<div style="background:var(--surface-secondary);border-radius:var(--radius-lg);padding:32px;text-align:center;border:1px dashed var(--border-light)">';
                screensHtml += '<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder)" stroke-width="1.5" stroke-linecap="round" style="margin-bottom:8px"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>';
                screensHtml += '<div style="font-weight:600;font-size:0.85rem;color:var(--text-primary);margin-bottom:4px">No screens yet</div>';
                screensHtml += '<div class="text-muted text-sm" style="margin:0">Add a screen to start displaying content at this location.</div></div>';
            } else {
                screensHtml += '<div style="display:grid;gap:8px">';
                screens.forEach(function(scr) {
                    var borderColor = scr.status_class === 'online' ? 'var(--monday-green)' : (scr.status_class === 'idle' ? 'var(--monday-yellow)' : 'var(--monday-red)');
                    var pulseStyle = scr.status_class === 'online' ? 'animation:statusPulse 2s infinite;' : '';

                    screensHtml += '<div onclick="openManageScreen(' + scr.id + ')" style="background:var(--surface-primary);border-radius:var(--radius-lg);padding:14px 14px 14px 16px;display:flex;align-items:center;justify-content:space-between;border:1px solid var(--border-light);border-left:4px solid ' + borderColor + ';cursor:pointer;transition:all var(--duration) var(--ease);box-shadow:var(--shadow-xs)" onmouseover="this.style.boxShadow=\'var(--shadow-sm)\';this.style.transform=\'translateY(-1px)\'" onmouseout="this.style.boxShadow=\'var(--shadow-xs)\';this.style.transform=\'none\'">';
                    screensHtml += '<div style="flex:1;min-width:0">';
                    screensHtml += '<div style="font-weight:600;font-size:0.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px">' + escapeHtml(scr.name) + '</div>';
                    screensHtml += '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">';
                    screensHtml += scr.mode_badge;
                    screensHtml += '<span style="display:inline-block;width:8px;height:8px;border-radius:var(--radius-full);background:' + borderColor + ';flex-shrink:0;' + pulseStyle + '"></span>';
                    screensHtml += '<span class="text-xs text-muted">' + scr.status_label + '</span>';
                    if (scr.resolution) {
                        screensHtml += '<span style="font-size:0.65rem;padding:1px 6px;background:var(--surface-secondary);border:1px solid var(--border-light);border-radius:var(--radius-full);color:var(--text-secondary);font-weight:500">' + escapeHtml(scr.resolution) + '</span>';
                    }
                    screensHtml += '</div>';
                    screensHtml += '<div class="text-xs text-muted" style="margin-top:3px;display:flex;align-items:center;gap:4px">';
                    screensHtml += '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
                    screensHtml += escapeHtml(scr.content_name);
                    screensHtml += '</div>';
                    screensHtml += '</div>';
                    screensHtml += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;margin-left:8px"><polyline points="9 18 15 12 9 6"/></svg>';
                    screensHtml += '</div>';
                });
                screensHtml += '</div>';
            }
            screensHtml += '</div>';

            $('#locationPanelBody').html(detailsHtml + screensHtml);

            // If a start tab was requested, switch to it
            if (startTab && startTab !== 'details') {
                $('#locationTabs .panel-tab').removeClass('active');
                $('#locationTabs .panel-tab[data-tab="' + startTab + '"]').addClass('active');
                $('#locationPanelBody .panel-tab-content').hide();
                $('#loc-tab-' + startTab).show();
            }
        }).fail(function() {
            $('#locationPanelBody').html('<div class="alert alert-danger">Failed to load location details.</div>');
        });
    };

    // Aliases for backward compatibility
    window.openViewLocation = function(locationId) { openLocationPanel(locationId, 'details'); };
    window.openEditLocation = function(locationId) { openLocationPanel(locationId, 'details'); };

    // ── Save Location (AJAX) ──
    window.saveLocation = function(e, locationId) {
        e.preventDefault();
        var frm = $('#editLocationForm');
        var btn = $('#editLocationSaveBtn');
        var msg = $('#editLocationMessage');

        btn.prop('disabled', true).text('Saving...');
        msg.html('');

        $.ajax({
            url: baseUrl + 'locations/api.php?action=update',
            method: 'POST',
            data: frm.serialize(),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    msg.html('<div class="alert alert-success" style="margin-top:8px">' + escapeHtml(data.message) + '</div>');
                    btn.text('Saved!');
                    showToast('Location updated!');
                    refreshLocationsTable();
                    // Update the panel title
                    var newName = frm.find('[name="name"]').val();
                    if (newName) {
                        $('#locationPanelTitle').html(escapeHtml(newName));
                    }
                    setTimeout(function() { btn.prop('disabled', false).text('Save Changes'); }, 1500);
                } else {
                    msg.html('<div class="alert alert-danger" style="margin-top:8px">' + escapeHtml(data.message) + '</div>');
                    btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function() {
                msg.html('<div class="alert alert-danger" style="margin-top:8px">An error occurred. Please try again.</div>');
                btn.prop('disabled', false).text('Save Changes');
            }
        });

        return false;
    };

    // ══════════════════════════════════════════
    //  SCREEN MANAGEMENT (from locations page)
    // ══════════════════════════════════════════

    // ── Add Screen Panel events ──
    $(document).on('submit', '#addScreenForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Creating...');

        $.ajax({
            url: baseUrl + 'screens/api.php?action=create',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Create Screen');
                if (resp.success) {
                    var playerUrl = baseUrl + 'screens/display.php?key=' + resp.screen_key;
                    $('#addScreenBody').html(
                        '<div style="text-align:center;padding:16px 0">' +
                            '<div style="width:56px;height:56px;border-radius:var(--radius-full);background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" width="28" height="28"><polyline points="20 6 9 17 4 12"/></svg>' +
                            '</div>' +
                            '<h3 style="margin-bottom:4px;font-size:1.1rem">Screen Created!</h3>' +
                            '<p class="text-muted text-sm mb-2">Open this URL on your display device:</p>' +
                            '<div style="background:var(--surface-secondary);padding:10px;border-radius:var(--radius);font-family:monospace;font-size:0.75rem;word-break:break-all;border:1px solid var(--border-light);text-align:left;margin-bottom:12px">' + playerUrl + '</div>' +
                            '<div class="btn-group" style="justify-content:center">' +
                                '<button class="btn btn-primary btn-copy" data-copy="' + playerUrl + '">Copy URL</button>' +
                                '<button class="btn btn-outline" onclick="closeSidePanel(\'addScreenPanel\');openManageScreen(' + resp.screen_id + ')">Manage Screen</button>' +
                            '</div>' +
                        '</div>'
                    );
                    showToast('Screen created!');
                } else {
                    showToast(resp.message || 'Failed to create screen.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Create Screen');
                showToast('Failed to create screen.', 'error');
            }
        });
    });

    // ── Manage Screen: panel tab switching ──
    $(document).on('click', '#screenTabs .panel-tab', function() {
        var tab = $(this).data('tab');
        $('#screenTabs .panel-tab').removeClass('active');
        $(this).addClass('active');
        $('#manageScreenBody .panel-tab-content').hide();
        $('#screen-tab-' + tab).show();
    });

    // ── Manage Screen: save settings ──
    $(document).on('submit', '#screenEditForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: baseUrl + 'screens/api.php?action=update',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Save Changes');
                if (resp.success) {
                    showToast('Screen updated!');
                    closeSidePanel('manageScreenPanel', true);
                    refreshLocationsTable();
                }
                else showToast(resp.message || 'Update failed.', 'error');
            },
            error: function() { btn.prop('disabled', false).text('Save Changes'); showToast('Save failed.', 'error'); }
        });
    });

    // ── Manage Screen: save content assignment ──
    $(document).on('submit', '#screenAssignForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: baseUrl + 'screens/api.php?action=assign',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Save Assignment');
                if (resp.success) {
                    showToast('Content updated!');
                    closeSidePanel('manageScreenPanel', true);
                    refreshLocationsTable();
                }
                else showToast(resp.message || 'Update failed.', 'error');
            },
            error: function() { btn.prop('disabled', false).text('Save Assignment'); showToast('Save failed.', 'error'); }
        });
    });

    // ── Manage Screen: mode tab click ──
    $(document).on('click', '#manageScreenBody .assign-mode-tab', function() {
        var mode = $(this).data('mode');
        if (!mode) return;
        $('#manageScreenBody .assign-mode-tab').removeClass('active');
        $(this).addClass('active');
        $('#screenAssignMode').val(mode);
        $('#manageScreenBody .mode-panel').hide();
        $('#screen-mode-' + mode).show();
    });

    // ── Manage Screen: checkbox item highlight ──
    $(document).on('change', '#manageScreenBody .assign-item input[type="checkbox"]', function() {
        $(this).closest('.assign-item').toggleClass('selected', this.checked);
    });
    // Keep radio highlight for playlists
    $(document).on('change', '#manageScreenBody .assign-item input[type="radio"]', function() {
        $(this).closest('.assign-item-list').find('.assign-item').removeClass('selected');
        $(this).closest('.assign-item').addClass('selected');
    });

    // ── Manage Screen: content search/filter ──
    $(document).on('input', '.screen-content-filter', function() {
        var query = $(this).val().toLowerCase().trim();
        var panel = $(this).closest('.mode-panel');
        panel.find('.assign-item').each(function() {
            var name = $(this).attr('data-search-name') || '';
            $(this).toggle(name.indexOf(query) !== -1);
        });
    });

    // ── Manage Screen: inline media upload ──
    function buildLocMediaItem(f) {
        var thumbSrc = f.type === 'image'
            ? (baseUrl + 'uploads/media/' + f.filename)
            : (f.thumbnail ? f.thumbnail : baseUrl + 'assets/video-placeholder.svg');
        var typeIcon = f.type === 'image'
            ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:0.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
            : '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:0.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        return '<label class="assign-item selected" data-search-name="' + escapeHtml(f.name).toLowerCase() + '">' +
            '<input type="checkbox" name="media_ids[]" value="' + f.id + '" checked>' +
            '<div class="assign-item-thumb"><img src="' + thumbSrc + '" alt=""></div>' +
            '<div class="assign-item-info"><div class="assign-item-name">' + escapeHtml(f.name) + '</div>' +
            '<div class="assign-item-meta">' + typeIcon + ' ' + f.type + (f.duration > 0 ? ' &middot; ' + fmtDuration(f.duration) : '') + (f.file_size ? ' &middot; ' + fmtBytes(f.file_size) : '') + '</div></div>' +
            '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div></label>';
    }

    $(document).on('change', '.locScreenMediaUpload', function() {
        var input = $(this);
        var files = this.files;
        if (!files || !files.length) return;

        var locationId = input.data('location-id');
        var panel = input.closest('.mode-panel');
        var prog = panel.find('.locScreenUploadProgress');

        var fd = new FormData();
        for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
        fd.append('location_ids[]', locationId);

        prog.html('<div style="font-size:0.8rem;color:var(--text-secondary);padding:4px 0"><span class="spinner-sm"></span> Uploading...</div>').show();

        $.ajax({
            url: baseUrl + 'media/upload.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(resp) {
                prog.hide();
                if (resp.success && resp.files) {
                    // Switch to single/media mode
                    $('#screenAssignMode').val('single');
                    $('#manageScreenBody .assign-mode-tab').removeClass('active');
                    $('#manageScreenBody .assign-mode-tab[data-mode="single"]').addClass('active');
                    $('#manageScreenBody .mode-panel').hide();
                    panel.show();

                    var list = panel.find('#locMediaItemList');
                    if (!list.length) {
                        // Remove "No media yet" text and create list
                        panel.find('.text-center.text-muted').remove();
                        panel.append('<div class="assign-item-list" id="locMediaItemList"></div>');
                        list = panel.find('#locMediaItemList');
                    }
                    resp.files.forEach(function(f) {
                        var item = $(buildLocMediaItem(f)).hide();
                        list.prepend(item);
                        item.slideDown(200);
                    });

                    // Auto-save the assignment so items stay checked on reload
                    setTimeout(function() {
                        var formData = $('#screenAssignForm').serialize();
                        $.ajax({
                            url: baseUrl + 'screens/api.php?action=assign',
                            type: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(saveResp) {
                                if (saveResp.success) {
                                    showToast(resp.files.length + ' file(s) uploaded & assigned!');
                                }
                            }
                        });
                    }, 300);
                } else if (!resp.success) {
                    prog.html('<div style="font-size:0.8rem;color:var(--monday-red);padding:4px 0">' + escapeHtml(resp.message) + '</div>').show();
                    setTimeout(function() { prog.slideUp(200); }, 3000);
                }
            },
            error: function() {
                prog.html('<div style="font-size:0.8rem;color:var(--monday-red);padding:4px 0">Upload failed.</div>').show();
                setTimeout(function() { prog.slideUp(200); }, 3000);
            }
        });
        input.val('');
    });

    // ── Schedule: content type toggle ──
    $(document).on('change', '#locSchContentType', function() {
        if (this.value === 'playlist') { $('#locSchPlaylistGroup').show(); $('#locSchMediaGroup').hide(); }
        else { $('#locSchPlaylistGroup').hide(); $('#locSchMediaGroup').show(); }
    });

    // ── Schedule: repeat type toggle ──
    $(document).on('change', '#locSchRepeatType', function() {
        $('#locSchRepeatDaysGroup').toggle(this.value === 'weekly');
    });

    // ── Schedule: add ──
    $(document).on('submit', '#locAddScheduleForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Adding...');
        var days = [];
        $('.loc-sch-day:checked').each(function() { days.push($(this).val()); });
        var formData = $(this).serialize();
        if (days.length > 0) formData += '&repeat_days=' + encodeURIComponent(days.join(','));
        $.ajax({
            url: baseUrl + 'screens/api.php?action=add_schedule',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Add Schedule');
                if (resp.success) {
                    showToast('Schedule added!');
                    var sid = $('#locAddScheduleForm [name="screen_id"]').val();
                    openManageScreen(parseInt(sid));
                    setTimeout(function() { $('#screenTabs .panel-tab[data-tab="schedule"]').click(); }, 200);
                } else showToast(resp.message || 'Failed.', 'error');
            },
            error: function() { btn.prop('disabled', false).text('Add Schedule'); showToast('Failed.', 'error'); }
        });
    });

    // ── Schedule: delete ──
    $(document).on('click', '.loc-delete-schedule', function() {
        var el = $(this);
        showConfirm({
            title: 'Delete schedule?',
            message: 'This schedule will be removed.',
            confirmText: 'Delete',
            confirmClass: 'btn-danger',
            onConfirm: function() {
                $.ajax({
                    url: baseUrl + 'screens/api.php?action=delete_schedule',
                    type: 'POST',
                    data: { csrf_token: csrfToken, id: el.data('id') },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success) { el.closest('.schedule-row').fadeOut(200, function() { $(this).remove(); }); showToast('Deleted.'); }
                        else showToast(resp.message || 'Failed.', 'error');
                    },
                    error: function() { showToast('Failed.', 'error'); }
                });
            }
        });
    });

    // ══════════════════════════════════════════
    //  SCREEN MANAGEMENT FUNCTIONS
    // ══════════════════════════════════════════

    window.openAddScreenForLocation = function(locationId, locationName) {
        closeAllSidePanels();
        $('#addScreenTitle').html('<button onclick="backToViewLocation()" class="btn btn-sm btn-ghost" style="margin-right:8px;padding:4px" title="Back to location"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg></button>Add Screen to ' + escapeHtml(locationName || 'Location'));
        $('#addScreenBody').html(
            '<form id="addScreenForm">' +
                '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                '<input type="hidden" name="location_id" value="' + locationId + '">' +
                '<div class="form-group"><label>Screen Name *</label>' +
                '<input type="text" name="name" class="form-control" required placeholder="e.g. Front Window Display" autofocus></div>' +
                '<div class="form-group"><label>Description</label>' +
                '<textarea name="description" class="form-control" rows="2" placeholder="Optional notes"></textarea></div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                    '<div class="form-group"><label>Orientation</label>' +
                    '<select name="orientation" class="form-control"><option value="landscape">Landscape</option><option value="portrait">Portrait</option></select></div>' +
                    '<div class="form-group"><label>Resolution</label>' +
                    '<select name="resolution" class="form-control"><option value="1920x1080">1920x1080 (FHD)</option><option value="3840x2160">3840x2160 (4K)</option><option value="1280x720">1280x720 (HD)</option><option value="1080x1920">1080x1920 (Portrait)</option></select></div>' +
                '</div>' +
                '<div style="display:flex;gap:8px;margin-top:16px">' +
                    '<button type="submit" class="btn btn-primary" style="flex:1">Create Screen</button>' +
                    '<button type="button" class="btn btn-outline side-panel-close" style="flex:0 0 auto;font-size:0.82rem;padding:8px 16px">Cancel</button>' +
                '</div>' +
            '</form>'
        );
        openSidePanel('addScreenPanel');
    };

    window.openManageScreen = function(screenId) {
        closeAllSidePanels();
        $('#manageScreenTitle').text('Manage Screen');
        $('#screenTabs').hide();
        $('#manageScreenFooter').hide().html('');
        $('#screenTabs .panel-tab').first().addClass('active').siblings().removeClass('active');
        $('#manageScreenBody').html('<div class="text-center text-muted" style="padding:3rem">Loading...</div>');
        openSidePanel('manageScreenPanel');

        $.getJSON(baseUrl + 'screens/api.php?action=get&id=' + screenId, function(resp) {
            if (!resp.success) {
                $('#manageScreenBody').html('<div class="alert alert-danger">' + (resp.message || 'Failed to load.') + '</div>');
                return;
            }

            var s = resp.screen;
            var a = resp.assignment || {};
            var playlists = resp.playlists || [];
            var media = resp.media || [];
            var schedules = resp.schedules || [];

            $('#manageScreenTitle').html('<button onclick="backToViewLocation()" class="btn btn-sm btn-ghost" style="margin-right:8px;padding:4px" title="Back to location"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5"/><polyline points="12 19 5 12 12 5"/></svg></button>' + escapeHtml(s.name));
            $('#screenTabs').show();

            // Footer
            var displayUrl = baseUrl + 'screens/display.php?key=' + s.screen_key;
            var pingStatus = s.last_ping ? 'online' : 'offline';
            var pingLabel = s.last_ping ? 'Online' : 'Offline';
            var pingDetail = s.last_ping ? 'Last seen recently' : 'Never connected';
            var footerHtml = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">' +
                '<div style="display:flex;align-items:center;gap:10px">' +
                    '<div style="width:32px;height:32px;border-radius:var(--radius);display:flex;align-items:center;justify-content:center;background:' + (s.last_ping ? 'var(--monday-green-light)' : 'var(--monday-red-light)') + '">' +
                        '<span class="status-dot ' + pingStatus + '" style="width:10px;height:10px"></span>' +
                    '</div>' +
                    '<div>' +
                        '<div style="font-weight:var(--font-semibold);font-size:var(--text-sm);color:var(--text-primary)">' + pingLabel + '</div>' +
                        '<div class="text-xs text-muted">' + escapeHtml(pingDetail) + '</div>' +
                    '</div>' +
                '</div>' +
                '<a href="' + displayUrl + '" target="_blank" rel="noopener" class="btn btn-sm btn-outline" style="gap:5px;display:inline-flex;align-items:center">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>' +
                    ' Preview</a>' +
                '</div>' +
                '<div style="background:var(--surface-secondary);padding:10px 12px;border-radius:var(--radius);font-family:monospace;font-size:var(--text-xs);word-break:break-all;border:1px solid var(--border-light);display:flex;align-items:center;gap:10px">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' +
                    '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;color:var(--text-secondary)">' + displayUrl + '</span>' +
                    '<button class="btn btn-sm btn-outline btn-copy" style="flex-shrink:0;padding:3px 10px;font-size:var(--text-xs)" data-copy="' + displayUrl + '">Copy</button>' +
                '</div>';
            $('#manageScreenFooter').html(footerHtml).show();

            var locOpts = '';
            userLocations.forEach(function(loc) {
                locOpts += '<option value="' + loc.id + '"' + (s.location_id == loc.id ? ' selected' : '') + '>' + escapeHtml(loc.name) + '</option>';
            });

            var assignType = a.assignment_type || 'playlist';
            // fmtDuration / fmtBytes are global (defined at top of script)

            /* === TAB 1: Settings === */
            var statusBannerBg = s.last_ping ? 'var(--monday-green-light)' : 'var(--monday-red-light)';
            var statusBannerColor = s.last_ping ? 'var(--status-green-text)' : 'var(--status-red-text)';
            var statusBannerIcon = s.last_ping
                ? '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
                : '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            var statusBannerText = s.last_ping ? 'Screen is online and connected' : 'Screen is offline';
            var statusBannerSub = s.last_ping ? 'Last seen recently' : 'Never connected';

            // sectionHeaderStyle not needed — using .panel-section CSS class

            var settingsHtml = '<div id="screen-tab-settings" class="panel-tab-content">' +
                '<form id="screenEditForm">' +
                    '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                    '<input type="hidden" name="id" value="' + s.id + '">' +

                    /* Status banner - compact */
                    '<div style="background:' + statusBannerBg + ';border-radius:var(--radius);padding:10px 14px;margin-bottom:16px;display:flex;align-items:center;gap:10px;color:' + statusBannerColor + '">' +
                        statusBannerIcon +
                        '<div style="font-weight:var(--font-semibold);font-size:var(--text-sm)">' + statusBannerText + '</div>' +
                    '</div>' +

                    /* Screen Name + Location row */
                    '<div class="form-group"><label>Screen Name *</label>' +
                    '<input type="text" name="name" class="form-control" value="' + escapeHtml(s.name) + '" required></div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                        '<div class="form-group"><label>Location *</label>' +
                        '<select name="location_id" class="form-control" required>' + locOpts + '</select></div>' +
                        '<div class="form-group"><label>Status</label>' +
                        '<select name="status" class="form-control">' +
                            '<option value="active"' + (s.status === 'active' ? ' selected' : '') + '>Active</option>' +
                            '<option value="inactive"' + (s.status === 'inactive' ? ' selected' : '') + '>Inactive</option>' +
                        '</select></div>' +
                    '</div>' +
                    '<div class="form-group"><label>Description</label>' +
                    '<textarea name="description" class="form-control" rows="2" placeholder="Optional notes about this screen">' + escapeHtml(s.description || '') + '</textarea></div>' +

                    /* Display Settings - grouped in a card */
                    '<div class="panel-section">Display Settings</div>' +
                    '<div style="margin-top:4px">' +
                        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                            '<div class="form-group" style="margin-bottom:0"><label>Orientation</label>' +
                            '<select name="orientation" class="form-control">' +
                                '<option value="landscape"' + (s.orientation === 'landscape' ? ' selected' : '') + '>Landscape</option>' +
                                '<option value="portrait"' + (s.orientation === 'portrait' ? ' selected' : '') + '>Portrait</option>' +
                            '</select></div>' +
                            '<div class="form-group" style="margin-bottom:0"><label>Resolution</label>' +
                            '<select name="resolution" class="form-control">' +
                                '<option value="1920x1080"' + (s.resolution === '1920x1080' ? ' selected' : '') + '>1920×1080 (FHD)</option>' +
                                '<option value="3840x2160"' + (s.resolution === '3840x2160' ? ' selected' : '') + '>3840×2160 (4K)</option>' +
                                '<option value="1280x720"' + (s.resolution === '1280x720' ? ' selected' : '') + '>1280×720 (HD)</option>' +
                                '<option value="1080x1920"' + (s.resolution === '1080x1920' ? ' selected' : '') + '>1080×1920 (Portrait)</option>' +
                            '</select></div>' +
                        '</div>' +
                    '</div>' +

                    /* Player URL - compact inline */
                    '<div style="display:flex;align-items:center;gap:8px;margin-top:14px;padding:8px 12px;background:var(--surface-secondary);border-radius:var(--radius);border:1px solid var(--border-light)">' +
                        '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" style="flex-shrink:0"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>' +
                        '<code style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.7rem;color:var(--text-secondary)">' + displayUrl + '</code>' +
                        '<button type="button" class="btn btn-sm btn-outline btn-copy" style="flex-shrink:0;padding:2px 10px;font-size:0.68rem" data-copy="' + displayUrl + '">Copy</button>' +
                    '</div>' +

                    '<button type="submit" class="btn btn-primary" style="width:100%;margin-top:20px">Save Changes</button>' +
                '</form></div>';

            /* === TAB 2: Content === */
            var contentHtml = '<div id="screen-tab-content" class="panel-tab-content" style="display:none">';

            contentHtml += '<form id="screenAssignForm">' +
                    '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                    '<input type="hidden" name="screen_id" value="' + s.id + '">' +
                    '<input type="hidden" name="assign_mode" id="screenAssignMode" value="' + assignType + '">' +
                    /* Section label above tabs */
                    '<div style="font-size:var(--text-xs);font-weight:var(--font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);margin-bottom:10px">Assign Content</div>' +
                    '<div class="assign-mode-tabs" style="margin-bottom:14px">' +
                        '<button type="button" class="assign-mode-tab' + (assignType === 'playlist' ? ' active' : '') + '" data-mode="playlist" style="font-weight:600">' +
                            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Playlist</button>' +
                        '<button type="button" class="assign-mode-tab' + (assignType === 'single' ? ' active' : '') + '" data-mode="single" style="font-weight:600">' +
                            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg> Single Item</button>' +
                    '</div>';

            // Playlist list with search filter
            contentHtml += '<div id="screen-mode-playlist" class="mode-panel"' + (assignType !== 'playlist' ? ' style="display:none"' : '') + '>';
            if (playlists.length === 0) {
                contentHtml += '<div class="assign-empty" style="padding:32px 20px">' +
                    '<div style="width:44px;height:44px;border-radius:var(--radius-full);background:var(--monday-purple-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--monday-purple)" stroke-width="1.5"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' +
                    '</div>' +
                    '<div style="font-weight:var(--font-semibold);font-size:var(--text-sm);color:var(--text-primary);margin-bottom:4px">No playlists yet</div>' +
                    '<div style="font-size:var(--text-xs);color:var(--text-secondary);line-height:1.5">Create a playlist in the Media section to assign it here</div>' +
                '</div>';
            } else {
                if (playlists.length > 5) {
                    contentHtml += '<div style="margin-bottom:10px;position:relative">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
                        '<input type="text" class="form-control screen-content-filter" data-target="playlist" placeholder="Search playlists..." style="padding:7px 12px 7px 30px;font-size:var(--text-sm)">' +
                    '</div>';
                }
                contentHtml += '<div style="font-size:var(--text-xs);color:var(--text-secondary);margin-bottom:8px;font-weight:500">' + playlists.length + ' playlist' + (playlists.length != 1 ? 's' : '') + ' available</div>';
                contentHtml += '<div class="assign-item-list">';
                playlists.forEach(function(pl) {
                    var sel = (a.playlist_id || 0) == pl.id;
                    contentHtml += '<label class="assign-item' + (sel ? ' selected' : '') + '" data-search-name="' + escapeHtml(pl.name).toLowerCase() + '">' +
                        '<input type="radio" name="playlist_id" value="' + pl.id + '"' + (sel ? ' checked' : '') + '>' +
                        '<div class="assign-item-icon assign-item-icon-playlist"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>' +
                        '<div class="assign-item-info"><div class="assign-item-name">' + escapeHtml(pl.name) + '</div>' +
                        '<div class="assign-item-meta">' + pl.item_count + ' item' + (pl.item_count != 1 ? 's' : '') + (pl.total_duration > 0 ? ' &middot; ' + fmtDuration(pl.total_duration) : '') + '</div></div>' +
                        '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div></label>';
                });
                contentHtml += '</div>';
            }
            contentHtml += '</div>';

            // Single media list with search filter
            contentHtml += '<div id="screen-mode-single" class="mode-panel"' + (assignType !== 'single' ? ' style="display:none"' : '') + '>';
            contentHtml += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">';
            if (media.length > 3) {
                contentHtml += '<div style="flex:1;position:relative">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
                    '<input type="text" class="form-control screen-content-filter" data-target="single" placeholder="Search media..." style="padding:7px 12px 7px 30px;font-size:var(--text-sm);width:100%">' +
                '</div>';
            } else {
                contentHtml += '<div style="flex:1"></div>';
            }
            contentHtml += '<label class="btn btn-sm btn-primary" style="cursor:pointer;margin:0">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                ' Upload' +
                '<input type="file" class="locScreenMediaUpload" data-screen-id="' + s.id + '" data-location-id="' + s.location_id + '" multiple accept="image/jpeg,image/png,video/mp4" style="display:none">' +
            '</label></div>';
            contentHtml += '<div class="locScreenUploadProgress" style="display:none;margin-bottom:8px"></div>';
            if (media.length === 0) {
                contentHtml += '<div class="text-center text-muted text-sm" style="padding:20px 0">No media yet</div>';
            } else {
                var selMediaIds = (a.media_ids || '').split(',').filter(function(x) { return x !== ''; });
                if (selMediaIds.length === 0 && a.media_id) selMediaIds = [String(a.media_id)];
                contentHtml += '<div class="assign-item-list" id="locMediaItemList">';
                media.forEach(function(m) {
                    var sel = selMediaIds.indexOf(String(m.id)) !== -1;
                    var thumbSrc = m.file_type === 'image' ? (baseUrl + 'uploads/media/' + m.filename) : (m.thumbnail ? baseUrl + 'uploads/thumbnails/' + m.thumbnail : baseUrl + 'assets/video-placeholder.svg');
                    var typeIcon = m.file_type === 'image'
                        ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:0.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
                        : '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="opacity:0.5"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
                    contentHtml += '<label class="assign-item' + (sel ? ' selected' : '') + '" data-search-name="' + escapeHtml(m.name).toLowerCase() + '">' +
                        '<input type="checkbox" name="media_ids[]" value="' + m.id + '"' + (sel ? ' checked' : '') + '>' +
                        '<div class="assign-item-thumb"><img src="' + thumbSrc + '" alt=""></div>' +
                        '<div class="assign-item-info"><div class="assign-item-name">' + escapeHtml(m.name) + '</div>' +
                        '<div class="assign-item-meta">' + typeIcon + ' ' + m.file_type + (m.duration > 0 ? ' &middot; ' + fmtDuration(m.duration) : '') + (m.file_size ? ' &middot; ' + fmtBytes(m.file_size) : '') + '</div></div>' +
                        '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div></label>';
                });
                contentHtml += '</div>';
            }
            contentHtml += '</div>';

            contentHtml += '<div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border-light);position:sticky;bottom:0;background:var(--surface-primary);padding-bottom:2px">' +
                '<button type="submit" class="btn btn-primary" style="width:100%;font-weight:var(--font-semibold);gap:6px;display:inline-flex;align-items:center;justify-content:center">' +
                    '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Assignment</button>' +
            '</div></form></div>';

            /* === TAB 3: Schedule === */
            var now = new Date();
            var schedListHtml = '';
            if (schedules.length === 0) {
                schedListHtml = '<div style="border:2px dashed var(--border-default);border-radius:var(--radius-lg);padding:28px 20px;text-align:center">' +
                    '<div style="width:48px;height:48px;border-radius:var(--radius-full);background:var(--surface-secondary);display:inline-flex;align-items:center;justify-content:center;margin-bottom:10px">' +
                        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' +
                    '</div>' +
                    '<div style="font-weight:var(--font-semibold);font-size:var(--text-sm);color:var(--text-primary);margin-bottom:4px">No schedules configured</div>' +
                    '<div class="text-xs text-muted">Add a schedule to automatically switch content at specific times</div>' +
                '</div>';
            } else {
                schedules.forEach(function(sc) {
                    var startDate = new Date(sc.start_datetime);
                    var endDate = new Date(sc.end_datetime);
                    var isPast = endDate < now && sc.repeat_type === 'none';
                    var isActive = startDate <= now && endDate >= now;
                    var isUpcoming = startDate > now;
                    var repeatLabel = sc.repeat_type === 'none' ? 'Once' : (sc.repeat_type === 'daily' ? 'Daily' : 'Weekly' + (sc.repeat_days ? ' (' + sc.repeat_days + ')' : ''));

                    /* Color coding: green=active, blue=upcoming, grey=past */
                    var statusColor, statusBg, statusLabel, statusBorderColor;
                    if (sc.repeat_type !== 'none') {
                        /* Repeating schedules are treated as active */
                        statusColor = 'var(--status-green-text)';
                        statusBg = 'var(--monday-green-light)';
                        statusLabel = 'Recurring';
                        statusBorderColor = 'var(--monday-green)';
                    } else if (isActive) {
                        statusColor = 'var(--status-green-text)';
                        statusBg = 'var(--monday-green-light)';
                        statusLabel = 'Active';
                        statusBorderColor = 'var(--monday-green)';
                    } else if (isUpcoming) {
                        statusColor = 'var(--status-blue-text)';
                        statusBg = 'var(--monday-blue-light)';
                        statusLabel = 'Upcoming';
                        statusBorderColor = 'var(--monday-blue)';
                    } else {
                        statusColor = 'var(--text-secondary)';
                        statusBg = 'var(--surface-secondary)';
                        statusLabel = 'Ended';
                        statusBorderColor = 'var(--border-default)';
                    }

                    schedListHtml += '<div class="schedule-row" style="display:flex;gap:12px;padding:12px 14px;border-radius:var(--radius);margin-bottom:8px;border-left:3px solid ' + statusBorderColor + ';background:var(--surface-primary);box-shadow:var(--shadow-xs);transition:box-shadow var(--duration) var(--ease);' + (isPast && sc.repeat_type === 'none' ? 'opacity:0.55;' : '') + '">' +
                        /* Left: timeline indicator */
                        '<div style="display:flex;flex-direction:column;align-items:center;gap:2px;padding-top:2px">' +
                            '<div style="width:10px;height:10px;border-radius:var(--radius-full);background:' + statusBorderColor + ';flex-shrink:0"></div>' +
                            '<div style="width:1.5px;flex:1;min-height:20px;background:' + statusBorderColor + ';opacity:0.3"></div>' +
                            '<div style="width:6px;height:6px;border-radius:var(--radius-full);border:1.5px solid ' + statusBorderColor + ';flex-shrink:0;opacity:0.5"></div>' +
                        '</div>' +
                        /* Right: content */
                        '<div style="flex:1;min-width:0">' +
                            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px">' +
                                '<div style="font-weight:var(--font-semibold);font-size:var(--text-sm);color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(sc.name || 'Untitled') + '</div>' +
                                '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0">' +
                                    '<span style="font-size:0.65rem;font-weight:var(--font-semibold);padding:2px 8px;border-radius:var(--radius-full);background:' + statusBg + ';color:' + statusColor + ';text-transform:uppercase;letter-spacing:0.03em">' + statusLabel + '</span>' +
                                    '<button class="btn btn-sm btn-danger-outline loc-delete-schedule" data-id="' + sc.id + '" style="padding:2px 8px;font-size:var(--text-xs);line-height:1">&times;</button>' +
                                '</div>' +
                            '</div>' +
                            '<div class="text-xs text-muted" style="margin-bottom:4px">' + escapeHtml(sc.content_name || 'N/A') + ' &middot; ' + repeatLabel + '</div>' +
                            '<div style="display:flex;align-items:center;gap:6px;font-size:var(--text-xs);color:var(--text-secondary)">' +
                                '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
                                '<span>' + formatScheduleDate(sc.start_datetime) + '</span>' +
                                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="opacity:0.4"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>' +
                                '<span>' + formatScheduleDate(sc.end_datetime) + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                });
            }

            var schPlOpts = '<option value="">-- Select Playlist --</option>';
            playlists.forEach(function(pl) { schPlOpts += '<option value="' + pl.id + '">' + escapeHtml(pl.name) + '</option>'; });
            var schMdOpts = '<option value="">-- Select Media --</option>';
            media.forEach(function(m) { schMdOpts += '<option value="' + m.id + '">' + escapeHtml(m.name) + ' (' + m.file_type + ')</option>'; });

            var scheduleHtml = '<div id="screen-tab-schedule" class="panel-tab-content" style="display:none">' +
                '<div style="margin-bottom:20px">' +
                    '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">' +
                        '<div style="font-size:var(--text-xs);font-weight:var(--font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary)">Schedules</div>' +
                        '<div style="font-size:var(--text-xs);color:var(--text-muted);background:var(--surface-secondary);padding:2px 8px;border-radius:var(--radius-full);font-weight:500">' + schedules.length + ' total</div>' +
                    '</div>' +
                    '<div style="max-height:280px;overflow-y:auto;padding-right:4px">' + schedListHtml + '</div>' +
                '</div>' +
                '<button class="btn btn-sm btn-outline" id="locToggleAddSchedule" style="width:100%;gap:6px;display:flex;align-items:center;justify-content:center;border-style:dashed;font-weight:var(--font-semibold)" onclick="$(\'#locAddScheduleSection\').slideToggle(200); $(this).hide();">' +
                    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Schedule</button>' +
                '<div id="locAddScheduleSection" style="display:none;margin-top:16px;border-top:1px solid var(--border-light);padding-top:16px">' +
                '<div style="font-size:var(--text-xs);font-weight:var(--font-semibold);text-transform:uppercase;letter-spacing:0.05em;color:var(--text-secondary);margin-bottom:10px">New Schedule</div>' +
                '<form id="locAddScheduleForm">' +
                    '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                    '<input type="hidden" name="screen_id" value="' + s.id + '">' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                        '<div class="form-group"><label>Schedule Name *</label><input type="text" name="name" class="form-control" required placeholder="e.g. Morning Promotions"></div>' +
                        '<div class="form-group"><label>Content Type</label><select name="content_type" class="form-control" id="locSchContentType"><option value="playlist">Playlist</option><option value="media">Single Media</option></select></div>' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                        '<div>' +
                            '<div class="form-group" id="locSchPlaylistGroup"><label>Playlist</label><select name="playlist_id" class="form-control">' + schPlOpts + '</select></div>' +
                            '<div class="form-group" id="locSchMediaGroup" style="display:none"><label>Media Item</label><select name="media_id" class="form-control">' + schMdOpts + '</select></div>' +
                        '</div>' +
                        '<div class="form-group"><label>Repeat</label><select name="repeat_type" class="form-control" id="locSchRepeatType"><option value="none">None (one-time)</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></div>' +
                    '</div>' +
                    '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                        '<div class="form-group"><label>Start *</label><input type="datetime-local" name="start_datetime" class="form-control" required></div>' +
                        '<div class="form-group"><label>End *</label><input type="datetime-local" name="end_datetime" class="form-control" required></div>' +
                    '</div>' +
                    '<div class="form-group" id="locSchRepeatDaysGroup" style="display:none"><label>Days</label><div class="d-flex gap-1" style="flex-wrap:wrap">' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Mon"><span style="font-size:0.8rem">Mon</span></label>' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Tue"><span style="font-size:0.8rem">Tue</span></label>' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Wed"><span style="font-size:0.8rem">Wed</span></label>' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Thu"><span style="font-size:0.8rem">Thu</span></label>' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Fri"><span style="font-size:0.8rem">Fri</span></label>' +
                        '<label class="form-check" style="margin-right:4px"><input type="checkbox" class="loc-sch-day" value="Sat"><span style="font-size:0.8rem">Sat</span></label>' +
                        '<label class="form-check"><input type="checkbox" class="loc-sch-day" value="Sun"><span style="font-size:0.8rem">Sun</span></label>' +
                    '</div></div>' +
                    '<button type="submit" class="btn btn-primary" style="width:100%;font-weight:var(--font-semibold);gap:6px;display:inline-flex;align-items:center;justify-content:center;margin-top:4px">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Add Schedule</button>' +
                '</form></div></div>';

            $('#manageScreenBody').html(settingsHtml + contentHtml + scheduleHtml);
        }).fail(function() {
            $('#manageScreenBody').html('<div class="alert alert-danger">Failed to load screen data.</div>');
        });
    };

    window.formatScheduleDate = function(dtStr) {
        if (!dtStr) return '';
        var d = new Date(dtStr);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var h = d.getHours();
        var ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        var mins = d.getMinutes();
        return months[d.getMonth()] + ' ' + d.getDate() + ' ' + h + ':' + (mins < 10 ? '0' : '') + mins + ampm;
    };

    window.refreshLocationsTable = function() {
        $.getJSON(baseUrl + 'locations/api.php?action=list', function(resp) {
            if (!resp.success) return;
            var locs = resp.locations;
            var isSA = resp.is_super_admin;
            var canEdit = resp.can_edit;
            var rows = '';
            for (var i = 0; i < locs.length; i++) {
                var l = locs[i];
                var borderClass = l.online_count > 0 ? 'row-border-green' : (l.is_active == 1 ? 'row-border-yellow' : 'row-border-muted');
                var statusClass = l.is_active == 1 ? 'active' : 'inactive';
                var badgeClass = l.is_active == 1 ? 'badge-success' : 'badge-secondary';
                var badgeLabel = l.is_active == 1 ? 'Active' : 'Inactive';
                var dotClass = l.online_count > 0 ? 'online' : 'offline';
                rows += '<tr class="location-row ' + borderClass + '" onclick="openLocationPanel(' + l.id + ')" role="button" tabindex="0" style="cursor:pointer" data-name="' + escapeHtml(l.name).toLowerCase() + '" data-city="' + escapeHtml(l.city || '').toLowerCase() + '" data-company="' + (isSA ? escapeHtml(l.company_name || '').toLowerCase() : '') + '" data-status="' + statusClass + '">';
                if (isSA) rows += '<td class="text-muted text-sm">' + escapeHtml(l.company_name || '') + '</td>';
                rows += '<td><strong>' + escapeHtml(l.name) + '</strong></td>';
                rows += '<td class="text-muted">' + escapeHtml(l.city || '') + '</td>';
                rows += '<td class="text-muted">' + escapeHtml(l.postcode || '') + '</td>';
                rows += '<td>' + l.screen_count + '</td>';
                rows += '<td><span class="status-dot ' + dotClass + '"></span> ' + l.online_count + '</td>';
                var mc = parseInt(l.media_count) || 0;
                rows += '<td class="text-muted">' + mc + ' ' + (mc === 1 ? 'file' : 'files') + '</td>';
                rows += '<td><span class="badge ' + badgeClass + '">' + badgeLabel + '</span></td></tr>';
            }
            $('.table-wrapper table tbody').html(rows);
            // Update stat cards
            var s = resp.stats;
            var cards = document.querySelectorAll('.stat-card .stat-value');
            if (cards.length >= 3) {
                cards[0].textContent = s.total;
                cards[1].textContent = s.screens;
                cards[2].textContent = s.online;
            }
            if (cards.length >= 4) cards[3].textContent = s.inactive;
            // Re-apply search/filter
            var searchInput = document.getElementById('locationSearchInput');
            if (searchInput) searchInput.dispatchEvent(new Event('input'));
        });
    };
});
</script>
JS;

include __DIR__ . '/../includes/footer.php';
?>
