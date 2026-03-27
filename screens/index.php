<?php
$pageTitle = 'Screens';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$filterLocation = (int)($_GET['location_id'] ?? 0);
$showAddPanel = isset($_GET['add']);

$userLocations = get_user_locations($userId);
$locationIds = array_column($userLocations, 'id');
$preselectedLocation = (int)($_GET['location_id'] ?? 0);

// Handle add screen POST
$errors = [];
$newScreenKey = null;
$newScreenId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_screen'])) {
    if (!verify_csrf()) { $errors[] = 'Invalid security token.'; }

    $name = trim($_POST['name'] ?? '');
    $locationId = (int)($_POST['location_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $orientation = in_array($_POST['orientation'] ?? '', ['landscape', 'portrait']) ? $_POST['orientation'] : 'landscape';
    $resolution = trim($_POST['resolution'] ?? '1920x1080');

    if (empty($name)) $errors[] = 'Screen name is required.';
    if (!$locationId) $errors[] = 'Please select a location.';
    if ($locationId && !can_access_location($userId, $locationId)) $errors[] = 'Invalid location.';

    if (empty($errors)) {
        $screenKey = generate_screen_key();

        $insertCompanyId = $companyId;
        if (is_super_admin() && $locationId) {
            $loc = fetch_one("SELECT company_id FROM locations WHERE id = ?", [$locationId]);
            $insertCompanyId = $loc['company_id'];
        }

        $screenId = insert('screens', [
            'location_id' => $locationId,
            'company_id' => $insertCompanyId,
            'name' => $name,
            'description' => $description,
            'screen_key' => $screenKey,
            'orientation' => $orientation,
            'resolution' => $resolution,
            'status' => 'active',
            'current_mode' => 'playlist'
        ]);

        log_activity('screen_created', "Created screen: {$name}");
        $newScreenKey = $screenKey;
        $newScreenId = $screenId;
        $showAddPanel = true;
    } else {
        $showAddPanel = true;
    }
}

// Fetch screens list (always fetch all — filtering is done client-side)
if ($isSuperAdmin) {
    $screens = fetch_all(
        "SELECT s.*, l.name as location_name, c.name as company_name,
            COALESCE(
                (SELECT p.name FROM screen_assignments sa JOIN playlists p ON sa.playlist_id = p.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'playlist'),
                (SELECT m.name FROM screen_assignments sa JOIN media m ON sa.media_id = m.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'single'),
                'None'
            ) as content_name
         FROM screens s
         JOIN locations l ON s.location_id = l.id
         JOIN companies c ON s.company_id = c.id
         ORDER BY c.name, l.name, s.name",
        []
    );
} elseif (empty($locationIds)) {
    $screens = [];
} else {
    $placeholders = implode(',', array_fill(0, count($locationIds), '?'));
    $screens = fetch_all(
        "SELECT s.*, l.name as location_name,
            COALESCE(
                (SELECT p.name FROM screen_assignments sa JOIN playlists p ON sa.playlist_id = p.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'playlist'),
                (SELECT m.name FROM screen_assignments sa JOIN media m ON sa.media_id = m.id WHERE sa.screen_id = s.id AND sa.assignment_type = 'single'),
                'None'
            ) as content_name
         FROM screens s
         JOIN locations l ON s.location_id = l.id
         WHERE s.location_id IN ({$placeholders})
         ORDER BY l.name, s.name",
        $locationIds
    );
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.loc-search-item:hover { background: var(--surface-secondary); }
</style>

<div class="page-header">
    <h1>Screens</h1>
    <div class="page-header-actions" style="position:relative">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="screenLocationSearch" placeholder="Search locations..." autocomplete="off" value="">
        </div>
        <select class="form-control" id="screenLocationSelect" style="display:none">
            <option value="0">All Locations</option>
            <?php foreach ($userLocations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= $filterLocation == $loc['id'] ? 'selected' : '' ?>><?= sanitize($loc['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" id="openAddPanel">+ Add Screen</button>
        <div id="locationSearchResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--surface-primary);border:1px solid var(--border-light);border-radius:var(--radius);box-shadow:var(--shadow-md);z-index:10;max-height:240px;overflow-y:auto"></div>
    </div>
</div>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th style="width:32px"><input type="checkbox" id="selectAllScreens"></th>
                <?php if ($isSuperAdmin): ?><th>Company</th><?php endif; ?>
                <th>Screen Name</th>
                <th>Location</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th>Content</th>
            </tr>
        </thead>
        <tbody id="screensTableBody">
        <?php foreach ($screens as $scr): ?>
            <tr onclick="openManagePanel(<?= $scr['id'] ?>)" role="button" tabindex="0" class="row-border-<?= screen_status_class($scr['last_ping']) === 'online' ? 'green' : (screen_status_class($scr['last_ping']) === 'idle' ? 'yellow' : 'muted') ?>" style="cursor:pointer" data-name="<?= sanitize($scr['name']) ?>" data-location="<?= sanitize($scr['location_name']) ?>" data-location-id="<?= $scr['location_id'] ?>">
                <td><input type="checkbox" class="screen-check" value="<?= $scr['id'] ?>" onclick="event.stopPropagation()"></td>
                <?php if ($isSuperAdmin): ?><td class="text-muted text-sm"><?= sanitize($scr['company_name'] ?? '') ?></td><?php endif; ?>
                <td><strong><?= sanitize($scr['name']) ?></strong></td>
                <td class="text-muted"><?= sanitize($scr['location_name']) ?></td>
                <td><?= mode_badge($scr['current_mode']) ?></td>
                <td>
                    <span class="status-dot <?= screen_status_class($scr['last_ping']) ?>"></span>
                    <?= screen_status_label($scr['last_ping']) ?>
                </td>
                <td class="text-muted text-sm"><?= $scr['last_ping'] ? time_ago($scr['last_ping']) : 'Never' ?></td>
                <td class="text-muted"><?= sanitize($scr['content_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($screens)): ?>
            <tr><td colspan="<?= $isSuperAdmin ? 8 : 7 ?>">
                <div class="empty-state" style="padding:48px 32px">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder)" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    <h4>No screens yet</h4>
                    <p>Screens are digital displays at your locations. Add a screen, then assign content to start displaying media.</p>
                    <button class="btn btn-primary" onclick="openSidePanel('addScreenPanel')">+ Add Screen</button>
                </div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Bulk Action Bar -->
<div id="bulkActionBar" style="display:none;position:fixed;bottom:0;left:var(--sidebar-width, 240px);right:0;background:var(--surface-primary);border-top:2px solid var(--primary);padding:12px 24px;z-index:100;box-shadow:var(--shadow-md)">
    <div style="display:flex;align-items:center;justify-content:space-between;max-width:1200px;margin:0 auto">
        <span style="font-size:0.85rem;font-weight:600"><span id="bulkCount">0</span> screen(s) selected</span>
        <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-outline" onclick="clearScreenSelection()">Clear</button>
            <button class="btn btn-sm btn-primary" onclick="openBulkAssignPanel()">Bulk Assign Content</button>
        </div>
    </div>
</div>

<!-- ==================== SIDE PANEL — Add Screen ==================== -->
<div class="side-panel-overlay <?= $showAddPanel ? 'active' : '' ?>" id="addScreenPanelOverlay"></div>
<div class="side-panel <?= $showAddPanel ? 'active' : '' ?>" id="addScreenPanel">
    <div class="side-panel-header">
        <h2><?= $newScreenKey ? 'Screen Created' : 'Add Screen' ?></h2>
        <button class="side-panel-close" id="closeAddPanel">&times;</button>
    </div>

    <div class="side-panel-body">
        <?php if ($newScreenKey): ?>
        <!-- Success state -->
        <div style="text-align:center;padding:16px 0">
            <div style="width:56px;height:56px;border-radius:var(--radius-full);background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" width="28" height="28">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h3 style="margin-bottom:4px;font-size:1.05rem">Screen Created!</h3>
            <p class="text-muted text-sm mb-2">Open this URL on your display device:</p>
            <div style="background:var(--surface-secondary);padding:12px;border-radius:var(--radius);font-family:monospace;font-size:0.8rem;word-break:break-all;border:1px solid var(--border-light);text-align:left">
                <?= BASE_URL ?>screens/display.php?key=<?= $newScreenKey ?>
            </div>
            <div class="btn-group mt-2" style="justify-content:center">
                <button class="btn btn-primary btn-copy" data-copy="<?= BASE_URL ?>screens/display.php?key=<?= $newScreenKey ?>">Copy URL</button>
                <a href="<?= BASE_URL ?>screens/view.php?id=<?= $newScreenId ?>" class="btn btn-outline">Configure</a>
            </div>
        </div>
        <?php else: ?>
        <!-- Add form -->
        <?php if ($errors): ?>
            <div class="alert alert-danger" style="margin-bottom:16px"><?php foreach($errors as $e) echo sanitize($e) . '<br>'; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>screens/?add">
            <?= csrf_field() ?>
            <input type="hidden" name="add_screen" value="1">

            <div class="form-group">
                <label for="name">Screen Name *</label>
                <input type="text" id="name" name="name" class="form-control" value="<?= sanitize($_POST['name'] ?? '') ?>" required placeholder="e.g. Front Window Display" autofocus>
            </div>

            <div class="form-group">
                <label for="location_id">Location *</label>
                <select id="location_id" name="location_id" class="form-control" required>
                    <option value="">Select a location</option>
                    <?php foreach ($userLocations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= ($preselectedLocation == $loc['id'] || ($_POST['location_id'] ?? 0) == $loc['id']) ? 'selected' : '' ?>><?= sanitize($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control" rows="2" placeholder="Optional notes about this screen"><?= sanitize($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="orientation">Orientation</label>
                <select id="orientation" name="orientation" class="form-control">
                    <option value="landscape">Landscape</option>
                    <option value="portrait">Portrait</option>
                </select>
            </div>

            <div class="form-group">
                <label for="resolution">Resolution</label>
                <select id="resolution" name="resolution" class="form-control">
                    <option value="1920x1080">1920x1080 (Full HD)</option>
                    <option value="3840x2160">3840x2160 (4K)</option>
                    <option value="1280x720">1280x720 (HD)</option>
                    <option value="1080x1920">1080x1920 (Portrait FHD)</option>
                </select>
            </div>

            <div class="side-panel-actions">
                <button type="submit" class="btn btn-primary" style="flex:1">Create Screen</button>
                <button type="button" class="btn btn-outline" id="cancelAddPanel">Cancel</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== SIDE PANEL — Manage Screen ==================== -->
<div class="side-panel-overlay" id="manageScreenPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="manageScreenPanel">
    <div class="side-panel-header" style="padding-bottom:0;border-bottom:none">
        <div style="width:100%">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <div style="display:flex;align-items:center;gap:10px">
                    <h2 id="manageTitle">Manage Screen</h2>
                    <a href="#" class="btn btn-sm btn-ghost" id="manageViewLink" style="display:none;font-size:0.75rem">Open full view</a>
                </div>
                <button class="side-panel-close">&times;</button>
            </div>
            <div class="panel-tabs" id="manageTabs">
                <button class="panel-tab active" data-tab="settings">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Settings
                </button>
                <button class="panel-tab" data-tab="content">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    Content
                </button>
            </div>
        </div>
    </div>
    <div class="side-panel-body" id="manageBody">
        <div class="text-center text-muted" style="padding:3rem">
            Loading...
        </div>
    </div>
    <div class="side-panel-footer" id="manageFooter" style="display:none"></div>
</div>

<!-- ==================== SIDE PANEL — Bulk Assign ==================== -->
<div class="side-panel-overlay" id="bulkAssignPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="bulkAssignPanel">
    <div class="side-panel-header">
        <h2 id="bulkAssignTitle">Bulk Assign Content</h2>
        <button class="side-panel-close">&times;</button>
    </div>
    <div class="side-panel-body" id="bulkAssignBody">
        <div class="text-center text-muted" style="padding:3rem">Loading...</div>
    </div>
</div>

<?php
$baseUrl = BASE_URL;
$csrfToken = csrf_token();
$locationsJson = json_encode($userLocations);
$colspan = $isSuperAdmin ? 8 : 7;
$extraScripts = <<<JS
<script>
var BASE_URL = '{$baseUrl}';
var CSRF_TOKEN = '{$csrfToken}';
var userLocations = {$locationsJson};

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

$(document).ready(function() {
    // ---- Searchable location filter (client-side) ----
    var locSearchData = [];
    userLocations.forEach(function(loc) {
        locSearchData.push({ id: loc.id, name: loc.name });
    });

    // Track the currently selected location ID for filtering (0 = all)
    var activeLocationId = '';

    // Client-side filter function using the global filterTable utility
    window.filterScreens = debounce(function() {
        var searchVal = $('#screenLocationSearch').val() || '';
        var filters = {};
        if (activeLocationId) {
            filters['location-id'] = String(activeLocationId);
        }

        filterTable({
            search: searchVal,
            searchFields: ['name', 'location'],
            filters: filters,
            rowSelector: '#screensTableBody tr',
            tableBody: '#screensTableBody',
            emptyMessage: 'No matching screens.',
            colspan: {$colspan}
        });
    }, 200);

    $('#screenLocationSearch').on('focus', function() {
        // Show the location dropdown on focus
        var term = $(this).val().toLowerCase();
        showLocationDropdown(term);
    });

    $('#screenLocationSearch').on('input', function() {
        var term = $(this).val().toLowerCase();
        // If user clears or edits text, reset location filter and search as text
        activeLocationId = '';
        showLocationDropdown(term);
        filterScreens();
    });

    function showLocationDropdown(term) {
        var html = '<div class="loc-search-item" data-id="0" style="padding:8px 12px;cursor:pointer;font-size:0.85rem;color:var(--text-muted);border-bottom:1px solid var(--border-light)">All Locations</div>';
        var count = 0;
        locSearchData.forEach(function(loc) {
            if (!term || loc.name.toLowerCase().indexOf(term) !== -1) {
                html += '<div class="loc-search-item" data-id="' + loc.id + '" style="padding:8px 12px;cursor:pointer;font-size:0.85rem">' + escapeHtml(loc.name) + '</div>';
                count++;
            }
        });
        if (count === 0 && term) {
            html += '<div style="padding:8px 12px;font-size:0.8rem;color:var(--text-muted);font-style:italic">No locations found</div>';
        }
        $('#locationSearchResults').html(html).show();
    }

    $(document).on('click', '.loc-search-item', function() {
        var locId = $(this).data('id');
        var locName = $(this).text();
        $('#locationSearchResults').hide();

        if (locId === 0 || locId === '0') {
            // All Locations
            activeLocationId = '';
            $('#screenLocationSearch').val('');
        } else {
            activeLocationId = String(locId);
            $('#screenLocationSearch').val(locName);
        }
        filterScreens();
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#screenLocationSearch, #locationSearchResults').length) {
            $('#locationSearchResults').hide();
        }
    });

    // ---- Add Screen Panel ----
    $('#openAddPanel').on('click', function() {
        openSidePanel('addScreenPanel');
    });

    $('#closeAddPanel, #cancelAddPanel').on('click', function() {
        closeSidePanel('addScreenPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    $('#addScreenPanelOverlay').on('click', function() {
        closeSidePanel('addScreenPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    // ---- Manage Screen: save details ----
    $(document).on('submit', '#manageEditForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: BASE_URL + 'screens/api.php?action=update',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Save Changes');
                if (resp.success) {
                    showToast('Screen updated!');
                    // Close the panel after a brief delay, then refresh the table data
                    setTimeout(function() {
                        closeSidePanel('manageScreenPanel', true);
                        refreshScreensTable();
                    }, 1200);
                } else {
                    showToast(resp.message || 'Update failed.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Save Changes');
                showToast('Save failed.', 'error');
            }
        });
    });

    // ---- Manage Screen: save content assignment ----
    $(document).on('submit', '#manageAssignForm', function(e) {
        e.preventDefault();
        var btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: BASE_URL + 'screens/api.php?action=assign',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Save Assignment');
                if (resp.success) {
                    showToast('Content assignment updated!');
                    // Close the panel after a brief delay, then refresh the table data
                    setTimeout(function() {
                        closeSidePanel('manageScreenPanel', true);
                        refreshScreensTable();
                    }, 1200);
                } else {
                    showToast(resp.message || 'Update failed.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Save Assignment');
                showToast('Save failed.', 'error');
            }
        });
    });

    // ---- Manage Screen: panel tab switching ----
    $(document).on('click', '#manageTabs .panel-tab', function() {
        var tab = $(this).data('tab');
        $('#manageTabs .panel-tab').removeClass('active');
        $(this).addClass('active');
        $('#manageBody .panel-tab-content').hide();
        $('#manage-tab-' + tab).show();
    });

    // ---- Manage Screen: mode tab click ----
    $(document).on('click', '#manageBody .assign-mode-tab', function() {
        var mode = $(this).data('mode');
        if (!mode) return;
        $('#manageBody .assign-mode-tab').removeClass('active');
        $(this).addClass('active');
        $('#manageAssignMode').val(mode);
        $('#manageBody .mode-panel').hide();
        $('#manage-mode-' + mode).show();
    });

    // ---- Manage Screen: radio item selection highlight (playlists) ----
    $(document).on('change', '#manageBody .assign-item input[type="radio"]', function() {
        $(this).closest('.assign-item-list').find('.assign-item').removeClass('selected');
        $(this).closest('.assign-item').addClass('selected');
    });

    // ---- Manage Screen: checkbox item selection highlight (media multi-select) ----
    $(document).on('change', '#manageBody .assign-item input[type="checkbox"]', function() {
        $(this).closest('.assign-item').toggleClass('selected', this.checked);
    });

    // ---- Manage Screen: media search filter ----
    $(document).on('input', '#mediaSearchFilter', function() {
        var term = $(this).val().toLowerCase();
        $('#mediaItemList .assign-item').each(function() {
            var name = $(this).data('media-name') || '';
            $(this).toggle(!term || String(name).indexOf(term) !== -1);
        });
    });

    // ---- Screen Content: inline upload ----
    function buildMediaItem(f, checked) {
        var thumbSrc = f.type === 'image'
            ? (BASE_URL + 'uploads/media/' + f.filename)
            : (f.thumbnail ? f.thumbnail : BASE_URL + 'assets/video-placeholder.svg');
        var typeIcon = f.type === 'image'
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
            : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
        return '<label class="assign-item selected" data-media-name="' + escapeHtml(f.name).toLowerCase() + '">' +
            '<input type="checkbox" name="media_ids[]" value="' + f.id + '" checked>' +
            '<div class="assign-item-thumb"><img src="' + thumbSrc + '" alt=""></div>' +
            '<div class="assign-item-info">' +
                '<div class="assign-item-name">' + escapeHtml(f.name) + '</div>' +
                '<div class="assign-item-meta">' + typeIcon + ' ' + f.type + (f.duration > 0 ? ' &middot; ' + fmtDuration(f.duration) : '') + (f.file_size ? ' &middot; ' + fmtBytes(f.file_size) : '') + '</div>' +
            '</div>' +
            '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
        '</label>';
    }

    function handleScreenUpload(files) {
        if (!files || !files.length) return;
        var scr = window._currentScreen;
        if (!scr) return;

        var fd = new FormData();
        for (var i = 0; i < files.length; i++) fd.append('files[]', files[i]);
        fd.append('location_ids[]', scr.location_id);

        var prog = $('#screenUploadProgress');
        prog.html('<div style="font-size:0.8rem;color:var(--text-secondary);padding:4px 0"><span class="spinner-sm"></span> Uploading...</div>').show();

        $.ajax({
            url: BASE_URL + 'media/upload.php',
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function(resp) {
                prog.hide();
                if (resp.success && resp.files) {
                    // Switch to single/media mode so the form saves as 'single'
                    $('#manageAssignMode').val('single');
                    $('#manageBody .assign-mode-tab').removeClass('active');
                    $('#manageBody .assign-mode-tab[data-mode="single"]').addClass('active');
                    $('#manageBody .mode-panel').hide();
                    $('#manage-mode-single').show();

                    // Ensure media list container exists
                    var list = $('#mediaItemList');
                    if (!list.length) {
                        $('#manage-mode-single').append('<div class="assign-item-list" id="mediaItemList"></div>');
                        list = $('#mediaItemList');
                    }
                    // Prepend new items, checked and selected
                    resp.files.forEach(function(f) {
                        var item = $(buildMediaItem(f, true)).hide();
                        list.prepend(item);
                        item.slideDown(200);
                    });

                    // Auto-save the assignment so items stay checked on reload
                    setTimeout(function() {
                        var formData = $('#manageAssignForm').serialize();
                        $.ajax({
                            url: BASE_URL + 'screens/api.php?action=assign',
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
    }

    $(document).on('change', '#screenMediaUpload', function() {
        handleScreenUpload(this.files);
        $(this).val('');
    });


    // ---- Select all / individual screen checkboxes ----
    $('#selectAllScreens').on('change', function() {
        $('.screen-check').prop('checked', this.checked);
        updateBulkBar();
    });
    $(document).on('change', '.screen-check', function() {
        updateBulkBar();
    });


    // ---- Bulk assign: mode tab click ----
    $(document).on('click', '[data-bulk-mode]', function() {
        var mode = $(this).data('bulk-mode');
        $('[data-bulk-mode]').removeClass('active');
        $(this).addClass('active');
        $('#bulkAssignMode').val(mode);
        $('#bulkAssignBody .mode-panel').hide();
        $('#bulk-mode-' + mode).show();
    });

    // ---- Bulk assign: checkbox highlight ----
    $(document).on('change', '#bulkAssignBody .assign-item input[type="checkbox"]', function() {
        $(this).closest('.assign-item').toggleClass('selected', this.checked);
    });

    // ---- Bulk assign: radio highlight ----
    $(document).on('change', '#bulkAssignBody .assign-item input[type="radio"]', function() {
        $(this).closest('.assign-item-list').find('.assign-item').removeClass('selected');
        $(this).closest('.assign-item').addClass('selected');
    });

    // ---- Bulk assign: form submit ----
    $(document).on('submit', '#bulkAssignForm', function(e) {
        e.preventDefault();
        var form = $(this);
        var count = form.find('input[name="screen_ids[]"]').length;

        showConfirm({
            title: 'Bulk Assign Content?',
            message: 'This will change content on ' + count + ' screen' + (count !== 1 ? 's' : '') + '. Their current assignments will be replaced.',
            confirmText: 'Assign to ' + count + ' Screen' + (count !== 1 ? 's' : ''),
            confirmClass: 'btn-primary',
            onConfirm: function() {
                var btn = form.find('button[type="submit"]');
                btn.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: BASE_URL + 'screens/api.php?action=bulk_assign',
                    type: 'POST',
                    data: form.serialize(),
                    dataType: 'json',
                    success: function(resp) {
                        btn.prop('disabled', false).text('Apply');
                        if (resp.success) {
                            showToast(resp.message);
                            closeSidePanel('bulkAssignPanel', true);
                            clearScreenSelection();
                            refreshScreensTable();
                        } else {
                            showToast(resp.message || 'Update failed.', 'error');
                        }
                    },
                    error: function() {
                        btn.prop('disabled', false).text('Apply');
                        showToast('Request failed.', 'error');
                    }
                });
            }
        });
    });

    // ---- Device Pairing: pair device ----
    $(document).on('click', '.pair-device-btn', function() {
        var btn = $(this);
        var screenId = btn.data('screen-id');
        var codeRaw = ($('#pairCodeInput').val() || '').replace(/\s/g, '');

        if (!codeRaw || codeRaw.length !== 6) {
            showToast('Please enter the 6-digit pairing code shown on the display.', 'error');
            return;
        }

        btn.prop('disabled', true).text('Pairing...');

        $.ajax({
            url: BASE_URL + 'screens/api.php?action=pair',
            type: 'POST',
            data: { csrf_token: CSRF_TOKEN, screen_id: screenId, code: codeRaw },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Pair Device');
                if (resp.success) {
                    showToast('Device paired successfully!');
                    openManagePanel(screenId);
                } else {
                    showToast(resp.message || 'Pairing failed.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Pair Device');
                showToast('Pairing request failed.', 'error');
            }
        });
    });

    // ---- Device Pairing: unpair device ----
    $(document).on('click', '.unpair-device-btn', function() {
        var btn = $(this);
        var screenId = btn.data('screen-id');
        showConfirm({
            title: 'Unpair device?',
            message: 'The display will return to the pairing screen.',
            confirmText: 'Unpair',
            confirmClass: 'btn-danger',
            onConfirm: function() {
                btn.prop('disabled', true).text('Unpairing...');

        $.ajax({
            url: BASE_URL + 'screens/api.php?action=unpair',
            type: 'POST',
            data: { csrf_token: CSRF_TOKEN, screen_id: screenId },
            dataType: 'json',
            success: function(resp) {
                btn.prop('disabled', false).text('Unpair Device');
                if (resp.success) {
                    showToast('Device unpaired.');
                    openManagePanel(screenId);
                } else {
                    showToast(resp.message || 'Unpair failed.', 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false).text('Unpair Device');
                showToast('Unpair request failed.', 'error');
            }
        });
            }
        });
    });

    // ---- Device Pairing: format code input with space ----
    $(document).on('input', '#pairCodeInput', function() {
        var val = $(this).val().replace(/\s/g, '').replace(/[^0-9]/g, '');
        if (val.length > 6) val = val.substring(0, 6);
        if (val.length > 3) val = val.substring(0, 3) + ' ' + val.substring(3);
        $(this).val(val);
    });

    // ---- Device Pairing: submit on enter key ----
    $(document).on('keypress', '#pairCodeInput', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).closest('#devicePairingSection').find('.pair-device-btn').click();
        }
    });
});

function openManagePanel(screenId) {
    $('#manageTitle').text('Manage Screen');
    $('#manageViewLink').attr('href', BASE_URL + 'screens/view.php?id=' + screenId).hide();
    $('#manageTabs').hide();
    $('#manageFooter').hide().html('');
    $('#manageTabs .panel-tab').first().addClass('active').siblings().removeClass('active');
    $('#manageBody').html('<div class="text-center text-muted" style="padding:3rem">Loading...</div>');
    openSidePanel('manageScreenPanel');

    $.getJSON(BASE_URL + 'screens/api.php?action=get&id=' + screenId, function(resp) {
        if (!resp.success) {
            $('#manageBody').html('<div class="alert alert-danger">' + (resp.message || 'Failed to load screen.') + '</div>');
            return;
        }

        var s = resp.screen;
        var a = resp.assignment || {};
        var playlists = resp.playlists || [];
        var media = resp.media || [];

        // Store for upload handler
        window._currentScreen = s;

        $('#manageTitle').text(s.name);
        $('#manageViewLink').attr('href', BASE_URL + 'screens/view.php?id=' + s.id).show();
        $('#manageTabs').show();

        // Footer: status + player URL
        var footerHtml = '' +
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">' +
                '<span class="status-dot ' + (s.last_ping ? 'online' : 'offline') + '"></span>' +
                '<span class="text-sm text-muted">' + escapeHtml(s.location_name) + ' &middot; ' + (s.last_ping ? 'Last seen recently' : 'Never connected') + '</span>' +
            '</div>' +
            '<div style="background:var(--surface-secondary);padding:8px 10px;border-radius:var(--radius);font-family:monospace;font-size:0.72rem;word-break:break-all;border:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between;gap:8px">' +
                '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis">' + BASE_URL + 'screens/display.php?key=' + s.screen_key + '</span>' +
                '<button class="btn btn-sm btn-outline btn-copy" style="flex-shrink:0;padding:2px 8px;font-size:0.72rem" data-copy="' + BASE_URL + 'screens/display.php?key=' + s.screen_key + '">Copy</button>' +
            '</div>';
        $('#manageFooter').html(footerHtml).show();

        // Build locations options
        var locOpts = '';
        userLocations.forEach(function(loc) {
            locOpts += '<option value="' + loc.id + '"' + (s.location_id == loc.id ? ' selected' : '') + '>' + escapeHtml(loc.name) + '</option>';
        });

        var assignType = a.assignment_type || 'playlist';

        // Helpers (use global fmtDuration / fmtBytes defined below)

        /* ==========================================
           TAB 1: Settings
           ========================================== */
        var settingsHtml = '' +
            '<div id="manage-tab-settings" class="panel-tab-content">' +

            /* ---- Device Pairing Section (moved to top) ---- */
            '<div class="panel-section">Device Pairing</div>' +
                '<div id="devicePairingSection" data-screen-id="' + s.id + '">' +
                    (s.device_id
                        ? (
                            '<div style="background:var(--surface-secondary);border-radius:var(--radius);padding:12px;margin-top:8px">' +
                                '<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">' +
                                    '<div style="width:8px;height:8px;border-radius:var(--radius-full);background:var(--monday-green);flex-shrink:0"></div>' +
                                    '<div>' +
                                        '<div style="font-weight:600;font-size:0.85rem">Device Paired</div>' +
                                        '<div class="text-xs text-muted">ID: ' + escapeHtml(s.device_id).substring(0, 18) + '...</div>' +
                                    '</div>' +
                                '</div>' +
                                (function() {
                                    var info = '';
                                    try {
                                        var di = JSON.parse(s.device_info || '{}');
                                        if (di.user_agent) {
                                            var ua = di.user_agent;
                                            var deviceLabel = 'Unknown device';
                                            if (ua.indexOf('AFTM') !== -1 || ua.indexOf('Amazon') !== -1 || ua.indexOf('Fire') !== -1) deviceLabel = 'Amazon Fire TV';
                                            else if (ua.indexOf('Android') !== -1) deviceLabel = 'Android Device';
                                            else if (ua.indexOf('Chrome') !== -1) deviceLabel = 'Chrome Browser';
                                            else if (ua.indexOf('Safari') !== -1) deviceLabel = 'Safari Browser';
                                            info = '<div class="text-xs text-muted" style="margin-bottom:4px">Device: ' + escapeHtml(deviceLabel) + '</div>';
                                        }
                                        if (di.ip) {
                                            info += '<div class="text-xs text-muted" style="margin-bottom:4px">IP: ' + escapeHtml(di.ip) + '</div>';
                                        }
                                        if (di.requested_at) {
                                            info += '<div class="text-xs text-muted">Paired: ' + escapeHtml(di.requested_at) + '</div>';
                                        }
                                    } catch(e) {}
                                    return info;
                                })() +
                                '<button class="btn btn-sm btn-danger-outline unpair-device-btn" data-screen-id="' + s.id + '" style="margin-top:10px;width:100%">Unpair Device</button>' +
                            '</div>'
                        )
                        : (
                            '<div style="background:var(--surface-secondary);border-radius:var(--radius);padding:12px;margin-top:8px">' +
                                '<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">' +
                                    '<div style="width:8px;height:8px;border-radius:var(--radius-full);background:var(--text-muted);flex-shrink:0"></div>' +
                                    '<div>' +
                                        '<div style="font-weight:600;font-size:0.85rem">No Device Paired</div>' +
                                        '<div class="text-xs text-muted">Open the player URL on a Firestick or display device, then enter the 6-digit code shown.</div>' +
                                    '</div>' +
                                '</div>' +
                                '<div style="display:flex;gap:8px">' +
                                    '<input type="text" class="form-control" id="pairCodeInput" placeholder="000 000" maxlength="7" style="font-size:1.1rem;text-align:center;letter-spacing:0.15em;font-weight:600;flex:1">' +
                                    '<button class="btn btn-primary pair-device-btn" data-screen-id="' + s.id + '">Pair Device</button>' +
                                '</div>' +
                            '</div>'
                        )
                    ) +
                '</div>' +

            '<form id="manageEditForm">' +
                '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">' +
                '<input type="hidden" name="id" value="' + s.id + '">' +

                '<div class="panel-section" style="margin-top:24px">Basic Information</div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                    '<div class="form-group">' +
                        '<label>Screen Name *</label>' +
                        '<input type="text" name="name" class="form-control" value="' + escapeHtml(s.name) + '" required>' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label>Location *</label>' +
                        '<select name="location_id" class="form-control" required>' + locOpts + '</select>' +
                    '</div>' +
                '</div>' +
                '<div class="form-group">' +
                    '<label>Description</label>' +
                    '<textarea name="description" class="form-control" rows="2">' + escapeHtml(s.description || '') + '</textarea>' +
                '</div>' +

                '<div class="panel-section">Display Settings</div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">' +
                    '<div class="form-group">' +
                        '<label>Orientation</label>' +
                        '<select name="orientation" class="form-control">' +
                            '<option value="landscape"' + (s.orientation === 'landscape' ? ' selected' : '') + '>Landscape</option>' +
                            '<option value="portrait"' + (s.orientation === 'portrait' ? ' selected' : '') + '>Portrait</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label>Resolution</label>' +
                        '<select name="resolution" class="form-control">' +
                            '<option value="1920x1080"' + (s.resolution === '1920x1080' ? ' selected' : '') + '>1920x1080 (FHD)</option>' +
                            '<option value="3840x2160"' + (s.resolution === '3840x2160' ? ' selected' : '') + '>3840x2160 (4K)</option>' +
                            '<option value="1280x720"' + (s.resolution === '1280x720' ? ' selected' : '') + '>1280x720 (HD)</option>' +
                            '<option value="1080x1920"' + (s.resolution === '1080x1920' ? ' selected' : '') + '>1080x1920 (Portrait)</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="form-group">' +
                        '<label>Status</label>' +
                        '<select name="status" class="form-control">' +
                            '<option value="active"' + (s.status === 'active' ? ' selected' : '') + '>Active</option>' +
                            '<option value="inactive"' + (s.status === 'inactive' ? ' selected' : '') + '>Inactive</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
                '<button type="submit" class="btn btn-primary" style="width:100%">Save Changes</button>' +
            '</form>' +
            '</div>';

        /* ==========================================
           TAB 2: Content Assignment
           ========================================== */
        var contentHtml = '' +
            '<div id="manage-tab-content" class="panel-tab-content" style="display:none">';

        contentHtml +=

            '<form id="manageAssignForm">' +
                '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">' +
                '<input type="hidden" name="screen_id" value="' + s.id + '">' +
                '<input type="hidden" name="assign_mode" id="manageAssignMode" value="' + assignType + '">' +

                '<div class="assign-mode-tabs">' +
                    '<button type="button" class="assign-mode-tab' + (assignType === 'playlist' ? ' active' : '') + '" data-mode="playlist">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' +
                        ' Playlist' +
                    '</button>' +
                    '<button type="button" class="assign-mode-tab' + (assignType === 'single' ? ' active' : '') + '" data-mode="single">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
                        ' Media' +
                    '</button>' +
                '</div>' +

                /* Playlist list */
                '<div id="manage-mode-playlist" class="mode-panel"' + (assignType !== 'playlist' ? ' style="display:none"' : '') + '>' +
                    (playlists.length === 0
                        ? '<div class="assign-empty"><p class="text-muted text-sm">No playlists yet. <a href="' + BASE_URL + 'playlists/?add">Create one</a></p></div>'
                        : (function() {
                            var h = '<div class="assign-item-list">';
                            playlists.forEach(function(pl) {
                                var sel = (a.playlist_id || 0) == pl.id;
                                h += '<label class="assign-item' + (sel ? ' selected' : '') + '">' +
                                    '<input type="radio" name="playlist_id" value="' + pl.id + '"' + (sel ? ' checked' : '') + '>' +
                                    '<div class="assign-item-icon assign-item-icon-playlist">' +
                                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>' +
                                    '</div>' +
                                    '<div class="assign-item-info">' +
                                        '<div class="assign-item-name">' + escapeHtml(pl.name) + '</div>' +
                                        '<div class="assign-item-meta">' + pl.item_count + ' item' + (pl.item_count != 1 ? 's' : '') + (pl.total_duration > 0 ? ' &middot; ' + fmtDuration(pl.total_duration) : '') + '</div>' +
                                    '</div>' +
                                    '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
                                '</label>';
                            });
                            h += '</div>';
                            return h;
                        })()
                    ) +
                '</div>' +

                /* Multi-select media list */
                '<div id="manage-mode-single" class="mode-panel"' + (assignType !== 'single' ? ' style="display:none"' : '') + '>' +
                    '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">' +
                        '<input type="text" class="form-control" id="mediaSearchFilter" placeholder="Search media..." style="font-size:0.8rem;padding:6px 10px;flex:1">' +
                        '<label class="btn btn-sm btn-primary" style="cursor:pointer;margin:0">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>' +
                            ' Upload' +
                            '<input type="file" id="screenMediaUpload" multiple accept="image/jpeg,image/png,video/mp4" style="display:none">' +
                        '</label>' +
                    '</div>' +
                    '<div id="screenUploadProgress" style="display:none;margin-bottom:8px"></div>' +
                    (media.length === 0
                        ? ''
                        : (function() {
                            var selectedIds = (a.media_ids || '').split(',').filter(function(x) { return x !== ''; });
                            // Fallback: if no media_ids but has single media_id, use that
                            if (selectedIds.length === 0 && a.media_id) selectedIds = [String(a.media_id)];
                            var h = '<div class="assign-item-list" id="mediaItemList">';
                            media.forEach(function(m) {
                                var sel = selectedIds.indexOf(String(m.id)) !== -1;
                                var thumbSrc = m.file_type === 'image'
                                    ? (BASE_URL + 'uploads/media/' + m.filename)
                                    : (m.thumbnail ? BASE_URL + 'uploads/thumbnails/' + m.thumbnail : BASE_URL + 'assets/video-placeholder.svg');
                                var typeIcon = m.file_type === 'image'
                                    ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
                                    : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>';
                                h += '<label class="assign-item' + (sel ? ' selected' : '') + '" data-media-name="' + escapeHtml(m.name).toLowerCase() + '">' +
                                    '<input type="checkbox" name="media_ids[]" value="' + m.id + '"' + (sel ? ' checked' : '') + '>' +
                                    '<div class="assign-item-thumb"><img src="' + thumbSrc + '" alt=""></div>' +
                                    '<div class="assign-item-info">' +
                                        '<div class="assign-item-name">' + escapeHtml(m.name) + '</div>' +
                                        '<div class="assign-item-meta">' + typeIcon + ' ' + m.file_type + (m.duration > 0 ? ' &middot; ' + fmtDuration(m.duration) : '') + (m.file_size ? ' &middot; ' + fmtBytes(m.file_size) : '') + '</div>' +
                                    '</div>' +
                                    '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
                                '</label>';
                            });
                            h += '</div>';
                            return h;
                        })()
                    ) +
                '</div>' +

                '<button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Save Assignment</button>' +
            '</form>' +
            '</div>';

        $('#manageBody').html(settingsHtml + contentHtml);
    }).fail(function() {
        $('#manageBody').html('<div class="alert alert-danger">Failed to load screen data.</div>');
    });
}

// escapeHtml() is now provided globally by app.js

function refreshScreensTable() {
    // Always fetch without location_id param so we get all screens
    $.get(BASE_URL + 'screens/', function(html) {
        var newDoc = $(html);
        var newTbody = newDoc.find('#screensTableBody');
        if (newTbody.length) {
            $('#screensTableBody').html(newTbody.html());
        }
        // Re-apply the current client-side filter after table refresh
        if (typeof window.filterScreens === 'function') {
            window.filterScreens();
        }
    });
}

function updateBulkBar() {
    var count = $('.screen-check:checked').length;
    $('#bulkCount').text(count);
    $('#bulkActionBar').toggle(count > 0);
    // Update select-all state
    var total = $('.screen-check').length;
    $('#selectAllScreens').prop('checked', count > 0 && count === total);
}

function clearScreenSelection() {
    $('.screen-check, #selectAllScreens').prop('checked', false);
    updateBulkBar();
}

function openBulkAssignPanel() {
    var selectedIds = [];
    $('.screen-check:checked').each(function() { selectedIds.push($(this).val()); });
    if (selectedIds.length === 0) return;

    $('#bulkAssignTitle').text('Assign Content to ' + selectedIds.length + ' Screen(s)');
    $('#bulkAssignBody').html('<div class="text-center text-muted" style="padding:3rem">Loading...</div>');
    openSidePanel('bulkAssignPanel');

    // Fetch playlists and media for the first selected screen (they share company)
    $.getJSON(BASE_URL + 'screens/api.php?action=get&id=' + selectedIds[0], function(resp) {
        if (!resp.success) {
            $('#bulkAssignBody').html('<div class="alert alert-danger">' + (resp.message || 'Failed to load data.') + '</div>');
            return;
        }

        var playlists = resp.playlists || [];
        var media = resp.media || [];
        // fmtDuration / fmtBytes are global (defined at top of script)

        var html = '<form id="bulkAssignForm">' +
            '<input type="hidden" name="csrf_token" value="' + CSRF_TOKEN + '">' +
            '<input type="hidden" name="assign_mode" id="bulkAssignMode" value="playlist">';

        // Hidden screen IDs
        selectedIds.forEach(function(sid) {
            html += '<input type="hidden" name="screen_ids[]" value="' + sid + '">';
        });

        html += '<div style="background:var(--surface-secondary);border-radius:var(--radius);padding:10px 12px;margin-bottom:16px;font-size:0.8rem;color:var(--text-secondary)">' +
            'This will update the content assignment on <strong>' + selectedIds.length + ' screen(s)</strong>. Existing assignments will be replaced.' +
        '</div>';

        // Mode tabs
        html += '<div class="assign-mode-tabs">' +
            '<button type="button" class="assign-mode-tab active" data-bulk-mode="playlist">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' +
                ' Playlist' +
            '</button>' +
            '<button type="button" class="assign-mode-tab" data-bulk-mode="single">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>' +
                ' Media' +
            '</button>' +
        '</div>';

        // Playlist list
        html += '<div id="bulk-mode-playlist" class="mode-panel">';
        if (playlists.length === 0) {
            html += '<div class="assign-empty"><p class="text-muted text-sm">No playlists yet.</p></div>';
        } else {
            html += '<div class="assign-item-list">';
            playlists.forEach(function(pl) {
                html += '<label class="assign-item">' +
                    '<input type="radio" name="playlist_id" value="' + pl.id + '">' +
                    '<div class="assign-item-icon assign-item-icon-playlist">' +
                        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>' +
                    '</div>' +
                    '<div class="assign-item-info">' +
                        '<div class="assign-item-name">' + escapeHtml(pl.name) + '</div>' +
                        '<div class="assign-item-meta">' + pl.item_count + ' item' + (pl.item_count != 1 ? 's' : '') + '</div>' +
                    '</div>' +
                    '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
                '</label>';
            });
            html += '</div>';
        }
        html += '</div>';

        // Media list (checkboxes for multi-select)
        html += '<div id="bulk-mode-single" class="mode-panel" style="display:none">';
        html += '<div style="background:var(--surface-secondary);border-radius:var(--radius);padding:10px 12px;margin-bottom:12px;display:flex;align-items:center;gap:8px;font-size:0.8rem;color:var(--text-secondary)">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="flex-shrink:0;opacity:0.6"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>' +
            'Select one or more items. Multiple items will cycle at 30 seconds each.' +
        '</div>';
        if (media.length === 0) {
            html += '<div class="assign-empty"><p class="text-muted text-sm">No media uploaded.</p></div>';
        } else {
            html += '<div class="assign-item-list">';
            media.forEach(function(m) {
                var thumbSrc = m.file_type === 'image'
                    ? (BASE_URL + 'uploads/media/' + m.filename)
                    : (m.thumbnail ? BASE_URL + 'uploads/thumbnails/' + m.thumbnail : '');
                html += '<label class="assign-item">' +
                    '<input type="checkbox" name="media_ids[]" value="' + m.id + '">' +
                    '<div class="assign-item-thumb"><img src="' + thumbSrc + '" alt=""></div>' +
                    '<div class="assign-item-info">' +
                        '<div class="assign-item-name">' + escapeHtml(m.name) + '</div>' +
                        '<div class="assign-item-meta">' + m.file_type + (m.duration > 0 ? ' &middot; ' + fmtDuration(m.duration) : '') + '</div>' +
                    '</div>' +
                    '<div class="assign-item-check"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></div>' +
                '</label>';
            });
            html += '</div>';
        }
        html += '</div>';

        html += '<button type="submit" class="btn btn-primary" style="width:100%;margin-top:16px">Apply to ' + selectedIds.length + ' Screen(s)</button>';
        html += '</form>';

        $('#bulkAssignBody').html(html);
    });
}
</script>
JS;

include __DIR__ . '/../includes/footer.php';
?>
