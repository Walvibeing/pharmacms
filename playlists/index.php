<?php
$pageTitle = 'Playlists';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$showAddPanel = isset($_GET['add']);

// Handle create playlist POST
$errors = [];
$newPlaylistId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_playlist'])) {
    if (!verify_csrf()) { $errors[] = 'Invalid security token.'; }

    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $locationId = !empty($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $loopEnabled = isset($_POST['loop_enabled']) ? 1 : 0;

    if (empty($name)) $errors[] = 'Playlist name is required.';

    $insertCompanyId = $companyId;
    if ($isSuperAdmin && $locationId) {
        $loc = fetch_one("SELECT company_id FROM locations WHERE id = ?", [$locationId]);
        if ($loc) $insertCompanyId = $loc['company_id'];
    }

    if ($isSuperAdmin && empty($insertCompanyId)) {
        $errors[] = 'Please select a location to assign this playlist to a company.';
    }

    if (empty($errors)) {
        $playlistId = insert('playlists', [
            'company_id' => $insertCompanyId,
            'location_id' => $locationId,
            'name' => $name,
            'description' => $description,
            'loop_enabled' => $loopEnabled,
            'is_active' => 1
        ]);

        log_activity('playlist_created', "Created playlist: {$name}");
        $newPlaylistId = $playlistId;
        $showAddPanel = true;
    } else {
        $showAddPanel = true;
    }
}

$locations = get_user_locations($userId);

// Fetch playlists
if ($isSuperAdmin) {
    $playlists = fetch_all(
        "SELECT p.*, c.name as company_name,
            (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
            (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
            (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
         FROM playlists p
         JOIN companies c ON p.company_id = c.id
         ORDER BY c.name, p.created_at DESC"
    );
} else {
    $playlists = fetch_all(
        "SELECT p.*,
            (SELECT COUNT(*) FROM playlist_items pi WHERE pi.playlist_id = p.id) as item_count,
            (SELECT COALESCE(SUM(pi2.duration), 0) FROM playlist_items pi2 WHERE pi2.playlist_id = p.id) as total_duration,
            (SELECT COUNT(*) FROM screen_assignments sa WHERE sa.playlist_id = p.id) as screen_count
         FROM playlists p
         WHERE p.company_id = ?
         ORDER BY p.created_at DESC",
        [$companyId]
    );
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Playlists</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="playlistSearchInput" placeholder="Search by name<?= $isSuperAdmin ? ', company' : '' ?>...">
        </div>
        <select class="form-control" id="playlistStatusFilter">
            <option value="all">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <button class="btn btn-primary" id="openAddPlaylistPanel">+ Create Playlist</button>
    </div>
</div>

<div class="table-wrapper">
    <table id="playlistsTable">
        <thead>
            <tr>
                <?php if ($isSuperAdmin): ?><th>Company</th><?php endif; ?>
                <th>Name</th>
                <th>Items</th>
                <th>Duration</th>
                <th>Used on Screens</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($playlists as $pl): ?>
            <tr class="playlist-row" onclick="openManagePlaylist(<?= $pl['id'] ?>)" role="button" tabindex="0" style="cursor:pointer;border-left:3px solid <?= $pl['is_active'] ? ($pl['screen_count'] > 0 ? 'var(--monday-green)' : 'var(--monday-yellow)') : 'var(--border-light)' ?>" data-name="<?= sanitize(strtolower($pl['name'])) ?>" data-company="<?= $isSuperAdmin ? sanitize(strtolower($pl['company_name'] ?? '')) : '' ?>" data-status="<?= $pl['is_active'] ? 'active' : 'inactive' ?>">
                <?php if ($isSuperAdmin): ?><td class="text-muted text-sm"><?= sanitize($pl['company_name'] ?? '') ?></td><?php endif; ?>
                <td><strong><?= sanitize($pl['name']) ?></strong></td>
                <td><?= $pl['item_count'] ?></td>
                <td class="text-muted"><?= format_duration($pl['total_duration']) ?></td>
                <td><?= $pl['screen_count'] ?></td>
                <td><?= status_badge($pl['is_active'] ? 'active' : 'inactive') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (empty($playlists)): ?>
    <div class="empty-state" id="playlistsEmptyState">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
        </svg>
        <h4>No playlists yet</h4>
        <p>Playlists are ordered collections of media that rotate on your screens. Create a playlist, add media to it, then assign it to a screen.</p>
        <button class="btn btn-primary" onclick="document.getElementById('openAddPlaylistPanel').click()">+ Create Playlist</button>
    </div>
    <?php endif; ?>
    <!-- No-results state for search/filter (hidden by default) -->
    <div class="empty-state" id="playlistsNoResults" style="display:none">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="8" y1="8" x2="14" y2="14"/><line x1="14" y1="8" x2="8" y2="14"/>
        </svg>
        <h4>No matching playlists</h4>
        <p>Try adjusting your search terms or status filter to find what you are looking for.</p>
    </div>
</div>

<!-- Playlists Search & Filter (uses global filterTable utility) -->
<script>
(function() {
    var $search = $('#playlistSearchInput');
    var $status = $('#playlistStatusFilter');
    var noResults = document.getElementById('playlistsNoResults');

    if (!$search.length || !$status.length) return;

    function applyFilter() {
        var statusVal = $status.val();
        window.filterTable({
            search: $search.val(),
            searchFields: ['name', 'company'],
            filters: { status: statusVal === 'all' ? '' : statusVal },
            rowSelector: '#playlistsTable tbody tr.playlist-row',
            tableBody: '#playlistsTable tbody',
            emptyMessage: 'No matching playlists found.',
            colspan: $('#playlistsTable thead th').length
        });
        // Toggle the external no-results div based on visible rows
        if (noResults) {
            var totalRows = $('#playlistsTable tbody tr.playlist-row').length;
            var visibleRows = $('#playlistsTable tbody tr.playlist-row:visible').length;
            noResults.style.display = (totalRows > 0 && visibleRows === 0) ? '' : 'none';
        }
    }

    $search.on('input', window.debounce(applyFilter, 200));
    $status.on('change', applyFilter);
})();
</script>

<!-- ==================== SIDE PANEL — Create Playlist ==================== -->
<div class="side-panel-overlay <?= $showAddPanel ? 'active' : '' ?>" id="addPlaylistPanelOverlay"></div>
<div class="side-panel side-panel-sm <?= $showAddPanel ? 'active' : '' ?>" id="addPlaylistPanel">
    <div class="side-panel-header">
        <h2><?= $newPlaylistId ? 'Playlist Created' : 'Create Playlist' ?></h2>
        <button class="side-panel-close" id="closeAddPlaylistPanel">&times;</button>
    </div>
    <div class="side-panel-body">
        <?php if ($newPlaylistId): ?>
        <div style="text-align:center;padding:16px 0">
            <div style="width:56px;height:56px;border-radius:var(--radius-full);background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">
                <svg viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" width="28" height="28">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h3 style="margin-bottom:4px;font-size:1.1rem">Playlist Created!</h3>
            <p class="text-muted text-sm mb-2">Now add media items to your playlist.</p>
            <div class="btn-group mt-2" style="justify-content:center">
                <button class="btn btn-primary" onclick="closeSidePanel('addPlaylistPanel');setTimeout(function(){openEditItems(<?= $newPlaylistId ?>)},200)">Add Media Items</button>
                <a href="<?= BASE_URL ?>playlists/?add" class="btn btn-outline">Create Another</a>
            </div>
        </div>
        <?php else: ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger" style="margin-bottom:16px"><?php foreach($errors as $e) echo sanitize($e) . '<br>'; ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>playlists/?add">
            <?= csrf_field() ?>
            <input type="hidden" name="add_playlist" value="1">

            <div class="form-group">
                <label for="pl_name">Playlist Name *</label>
                <input type="text" id="pl_name" name="name" class="form-control" required placeholder="e.g. Morning Rotation" value="<?= sanitize($_POST['name'] ?? '') ?>" autofocus>
            </div>

            <div class="form-group">
                <label for="pl_description">Description</label>
                <textarea id="pl_description" name="description" class="form-control" rows="2" placeholder="Optional description"><?= sanitize($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-group">
                <label for="pl_location_id">Location <?= $isSuperAdmin ? '*' : '(optional)' ?></label>
                <select id="pl_location_id" name="location_id" class="form-control" <?= $isSuperAdmin ? 'required' : '' ?>>
                    <option value=""><?= $isSuperAdmin ? '— Select a location —' : 'Company-wide' ?></option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>"><?= sanitize($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" name="loop_enabled" id="pl_loop_enabled" checked>
                    <label for="pl_loop_enabled">Loop playlist (restart when finished)</label>
                </div>
            </div>

            <div class="side-panel-actions" style="padding:0;border:none;margin-top:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">Create &amp; Add Media</button>
                <button type="button" class="btn btn-outline" id="cancelAddPlaylistPanel">Cancel</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== SIDE PANEL — Manage Playlist (Edit Items + Settings) ==================== -->
<div class="side-panel-overlay" id="editItemsPanelOverlay"></div>
<div class="side-panel side-panel-xl" id="editItemsPanel">
    <div class="side-panel-header" style="border-bottom:1px solid var(--border-light);padding:16px 20px">
        <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0">
            <div style="width:36px;height:36px;border-radius:var(--radius);background:linear-gradient(135deg,var(--monday-blue),var(--monday-blue-dark,#0060b9));display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </div>
            <div style="min-width:0">
                <h2 id="editItemsTitle" style="margin:0;font-size:1.05rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">Manage Playlist</h2>
                <div style="display:flex;align-items:center;gap:8px;margin-top:2px">
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:var(--text-muted);background:var(--surface-secondary);padding:1px 8px;border-radius:var(--radius-lg);font-weight:500"><span id="editItemCount">0</span> items</span>
                    <span style="font-size:0.75rem;color:var(--border-dark,#ccc)">&bull;</span>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:0.78rem;color:var(--text-muted)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="editTotalDuration">0:00</span>
                    </span>
                </div>
            </div>
        </div>
        <button type="button" id="editSettingsToggle" style="display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:var(--radius);border:1px solid var(--border-light);background:var(--surface-secondary);cursor:pointer;font-size:0.75rem;font-weight:600;color:var(--text-secondary);margin-left:auto;margin-right:8px" title="Playlist settings">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </button>
        <button class="side-panel-close" style="margin-left:0">&times;</button>
    </div>
    <!-- Collapsible Settings Bar -->
    <div id="editSettingsBar" style="display:none;border-bottom:1px solid var(--border-light);padding:12px 20px;background:var(--surface-secondary)">
        <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div style="flex:1;min-width:180px">
                <label style="font-size:0.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;display:block">Name</label>
                <input type="text" class="form-control" id="editPlaylistName" style="font-size:0.85rem;padding:6px 10px">
            </div>
            <div style="flex:1;min-width:180px">
                <label style="font-size:0.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.03em;margin-bottom:3px;display:block">Description</label>
                <input type="text" class="form-control" id="editPlaylistDesc" placeholder="Optional" style="font-size:0.85rem;padding:6px 10px">
            </div>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.82rem;font-weight:500;white-space:nowrap;padding-bottom:6px">
                <input type="checkbox" id="editPlaylistLoop" style="width:15px;height:15px;accent-color:var(--monday-green)"> Loop
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:0.82rem;font-weight:500;white-space:nowrap;padding-bottom:6px">
                <input type="checkbox" id="editPlaylistActive" style="width:15px;height:15px;accent-color:var(--monday-green)"> Active
            </label>
        </div>
    </div>
    <div class="side-panel-body" id="editItemsBody" style="padding:0;overflow:hidden;display:flex">
        <!-- Left: Media Library -->
        <div style="width:280px;flex-shrink:0;border-right:1px solid var(--border-light);display:flex;flex-direction:column;background:var(--surface-secondary)">
            <div style="padding:12px 14px;border-bottom:1px solid var(--border-light);display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:0.8rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.04em">Media Library</span>
                <div style="display:flex;align-items:center;gap:6px">
                    <span id="editMediaLibraryCount" style="font-size:0.72rem;color:var(--text-muted);background:var(--surface-primary);padding:1px 7px;border-radius:var(--radius-lg);font-weight:500">0</span>
                    <button type="button" onclick="refreshMediaLibrary()" class="btn-icon" title="Refresh media library" style="padding:3px;border-radius:var(--radius-sm)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                </div>
            </div>
            <div style="padding:8px 10px;border-bottom:1px solid var(--border-light)">
                <div style="position:relative">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);pointer-events:none"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="form-control" placeholder="Search media..." id="editMediaSearch" style="font-size:0.8rem;padding:7px 10px 7px 30px;background:var(--surface-primary)">
                </div>
            </div>
            <div id="editMediaLibrary" style="flex:1;overflow-y:auto;padding:6px">
                <div class="text-center text-muted text-sm" style="padding:24px 8px">Loading...</div>
            </div>
        </div>
        <!-- Right: Playlist Items -->
        <div style="flex:1;display:flex;flex-direction:column;min-width:0">
            <div style="padding:10px 16px;border-bottom:1px solid var(--border-light);display:flex;justify-content:space-between;align-items:center">
                <div style="display:flex;align-items:center;gap:8px">
                    <span style="font-size:0.84rem;font-weight:600;color:var(--text-primary)">Playlist Items</span>
                    <span class="text-muted" id="editItemCountTop" style="font-size:0.78rem;background:var(--surface-secondary);padding:1px 8px;border-radius:var(--radius-lg)">0 items</span>
                </div>
                <span style="font-size:0.74rem;color:var(--text-muted);display:flex;align-items:center;gap:4px">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M7 10l5-6 5 6"/><path d="M7 14l5 6 5-6"/></svg>
                    Drag to reorder
                </span>
            </div>
            <div id="editPlaylistItemsList" style="flex:1;overflow-y:auto;padding:8px;min-height:200px">
            </div>
            <!-- Footer with total duration and save -->
            <div style="padding:12px 16px;border-top:2px solid var(--border-light);background:var(--surface-secondary);display:flex;align-items:center;justify-content:space-between;gap:12px">
                <div style="display:flex;align-items:center;gap:10px">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <div>
                        <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);font-weight:600">Total Duration</div>
                        <div style="font-size:1.1rem;font-weight:800;color:var(--text-primary)" id="editTotalDurationBottom">0:00</div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button class="btn btn-outline btn-sm" id="duplicatePlaylistBtn" onclick="duplicatePlaylist(editPlaylistId)" style="font-size:0.82rem;display:flex;align-items:center;gap:4px" title="Duplicate this playlist">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        Duplicate
                    </button>
                    <button class="btn btn-outline btn-sm side-panel-close" style="font-size:0.82rem">Cancel</button>
                    <button class="btn btn-primary" id="saveEditItemsBtn" style="padding:8px 24px;font-weight:600;font-size:0.88rem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;vertical-align:-2px"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Playlist
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$baseUrl = BASE_URL;
$csrfToken = csrf_token();
$extraScripts = <<<JS
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
var BASE_URL = '{$baseUrl}';
var CSRF_TOKEN = '{$csrfToken}';
var editPlaylistId = null;

$(document).ready(function() {
    // ---- Create Playlist Panel ----
    $('#openAddPlaylistPanel').on('click', function() {
        openSidePanel('addPlaylistPanel');
    });

    $('#closeAddPlaylistPanel, #cancelAddPlaylistPanel').on('click', function() {
        closeSidePanel('addPlaylistPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    $('#addPlaylistPanelOverlay').on('click', function() {
        closeSidePanel('addPlaylistPanel');
        if (window.location.search.indexOf('add') > -1) {
            history.replaceState(null, '', window.location.pathname);
        }
    });

    // ---- Edit Items Panel: events ----
    $(document).on('click', '#editMediaLibrary .playlist-media-item', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var type = $(this).data('type');
        var duration = $(this).data('duration') || 10;
        var imgSrc = $(this).find('img').attr('src') || '';

        $('#editItemsEmpty').remove();
        var html = buildPlaylistItemHtml(id, name, type, duration, imgSrc);
        $('#editPlaylistItemsList').append(html);
        updateEditCounts();
        showToast('Added: ' + name);
    });

    $(document).on('click', '#editPlaylistItemsList .remove-item', function() {
        $(this).closest('.playlist-item').remove();
        updateEditCounts();
    });

    $(document).on('change', '#editPlaylistItemsList .duration-input', function() {
        updateEditCounts();
    });

    $('#editMediaSearch').on('input', function() {
        var q = $(this).val().toLowerCase();
        $('#editMediaLibrary .playlist-media-item').each(function() {
            var name = $(this).data('name').toString().toLowerCase();
            $(this).toggle(name.indexOf(q) > -1);
        });
    });

    // Settings toggle
    $('#editSettingsToggle').on('click', function() {
        var bar = $('#editSettingsBar');
        var isVisible = bar.is(':visible');
        bar.slideToggle(150);
        $(this).toggleClass('active');
        if (!isVisible) {
            $(this).css({'background': 'var(--monday-blue-light, #e6f0ff)', 'color': 'var(--monday-blue)', 'border-color': 'var(--monday-blue-light, #cce0ff)'});
        } else {
            $(this).css({'background': 'var(--surface-secondary)', 'color': 'var(--text-secondary)', 'border-color': 'var(--border-light)'});
        }
    });

    $('#saveEditItemsBtn').on('click', function() {
        var items = [];
        $('#editPlaylistItemsList .playlist-item').each(function() {
            items.push({
                media_id: $(this).data('media-id'),
                duration: $(this).find('.duration-input').val()
            });
        });

        var settingsName = $('#editPlaylistName').val().trim();
        if (!settingsName) {
            showToast('Playlist name is required.', 'error');
            $('#editSettingsBar').slideDown(150);
            $('#editSettingsToggle').addClass('active').css({'background': 'var(--monday-blue-light, #e6f0ff)', 'color': 'var(--monday-blue)', 'border-color': 'var(--monday-blue-light, #cce0ff)'});
            $('#editPlaylistName').focus();
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true).html('Saving...');

        // Save items and settings in parallel
        var saveItems = $.ajax({
            url: BASE_URL + 'playlists/api.php?action=save_items',
            type: 'POST',
            data: {
                csrf_token: CSRF_TOKEN,
                id: editPlaylistId,
                items: JSON.stringify(items)
            },
            dataType: 'json'
        });

        var saveSettings = $.ajax({
            url: BASE_URL + 'playlists/api.php?action=update',
            type: 'POST',
            data: {
                csrf_token: CSRF_TOKEN,
                id: editPlaylistId,
                name: settingsName,
                description: $('#editPlaylistDesc').val() || '',
                loop_enabled: $('#editPlaylistLoop').is(':checked') ? '1' : '0',
                is_active: $('#editPlaylistActive').is(':checked') ? '1' : '0'
            },
            dataType: 'json'
        });

        $.when(saveItems, saveSettings).done(function(itemsResp, settingsResp) {
            btn.prop('disabled', false).html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;vertical-align:-2px"><polyline points="20 6 9 17 4 12"/></svg>Save Playlist');
            var r1 = itemsResp[0];
            var r2 = settingsResp[0];
            if (r1.success && r2.success) {
                showToast('Playlist saved!');
                closeSidePanel('editItemsPanel', true);
                refreshPlaylistsTable();
            } else {
                showToast(r1.message || r2.message || 'Save failed.', 'error');
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="margin-right:4px;vertical-align:-2px"><polyline points="20 6 9 17 4 12"/></svg>Save Playlist');
            showToast('Save failed.', 'error');
        });
    });

});

function openManagePlaylist(playlistId) {
    openEditItems(playlistId);
}

// escapeHtml() is now provided globally by app.js

function openEditItems(playlistId) {
    editPlaylistId = playlistId;
    closeAllSidePanels();

    openSidePanel('editItemsPanel');
    $('#editItemsTitle').text('Manage Playlist');
    $('#editMediaSearch').val('');
    $('#editSettingsBar').hide();
    $('#editSettingsToggle').removeClass('active').css({'background': 'var(--surface-secondary)', 'color': 'var(--text-secondary)', 'border-color': 'var(--border-light)'});
    $('#editMediaLibrary').html('<div class="text-center text-muted text-sm" style="padding:24px 8px">Loading...</div>');
    $('#editMediaLibraryCount').text('0');
    $('#editPlaylistItemsList').html('<div class="text-center text-muted text-sm" style="padding:24px 8px">Loading...</div>');

    $.when(
        $.getJSON(BASE_URL + 'playlists/api.php?action=get&id=' + playlistId),
        $.getJSON(BASE_URL + 'playlists/api.php?action=get_media&id=' + playlistId)
    ).done(function(playlistResp, mediaResp) {
        var pData = playlistResp[0];
        var mData = mediaResp[0];

        if (!pData.success) {
            $('#editPlaylistItemsList').html('<div class="alert alert-danger">Failed to load playlist.</div>');
            return;
        }

        var pl = pData.playlist;
        $('#editItemsTitle').text(pl.name);

        // Populate settings fields
        $('#editPlaylistName').val(pl.name);
        $('#editPlaylistDesc').val(pl.description || '');
        $('#editPlaylistLoop').prop('checked', pl.loop_enabled == 1);
        $('#editPlaylistActive').prop('checked', pl.is_active == 1);

        // Render media library with hover "+" overlay
        var mediaHtml = '';
        var mediaCount = 0;
        if (mData.success && mData.media.length > 0) {
            mediaCount = mData.media.length;
            mData.media.forEach(function(m) {
                var imgSrc = m.file_type === 'image'
                    ? BASE_URL + 'uploads/media/' + m.filename
                    : (m.thumbnail ? BASE_URL + 'uploads/thumbnails/' + m.thumbnail : BASE_URL + 'assets/video-placeholder.svg');
                mediaHtml += '<div class="playlist-media-item" data-id="' + m.id + '" data-name="' + escapeHtml(m.name) + '" data-type="' + m.file_type + '" data-duration="' + (m.duration || 10) + '" style="position:relative">' +
                    '<img src="' + imgSrc + '" alt="" style="border-radius:var(--radius-sm)">' +
                    '<div style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;background:rgba(0,102,255,0.08);opacity:0;transition:opacity 0.15s;border-radius:var(--radius-md);pointer-events:none" class="media-add-overlay">' +
                        '<span style="width:22px;height:22px;border-radius:var(--radius-full);background:var(--monday-blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,0.15)">+</span>' +
                    '</div>' +
                    '<div><div class="item-name">' + escapeHtml(m.name) + '</div>' +
                    '<div class="item-type">' + m.file_type.charAt(0).toUpperCase() + m.file_type.slice(1) + ' &middot; ' + (m.duration || 10) + 's</div></div></div>';
            });
        } else {
            mediaHtml = '<div class="text-center text-muted text-sm" style="padding:24px 8px">No media available.<br><a href="' + BASE_URL + 'media/">Upload Media</a></div>';
        }
        $('#editMediaLibrary').html(mediaHtml);
        $('#editMediaLibraryCount').text(mediaCount);

        // Hover behavior for "+" overlay on media library items
        $('#editMediaLibrary').off('mouseenter.addOverlay mouseleave.addOverlay')
            .on('mouseenter.addOverlay', '.playlist-media-item', function() {
                $(this).find('.media-add-overlay').css('opacity', '1');
            })
            .on('mouseleave.addOverlay', '.playlist-media-item', function() {
                $(this).find('.media-add-overlay').css('opacity', '0');
            });

        // Render playlist items
        var items = pData.items || [];
        var itemsHtml = '';
        items.forEach(function(item) {
            var imgSrc = item.file_type === 'image'
                ? BASE_URL + 'uploads/media/' + item.filename
                : (item.thumbnail ? BASE_URL + 'uploads/thumbnails/' + item.thumbnail : BASE_URL + 'assets/video-placeholder.svg');
            itemsHtml += buildPlaylistItemHtml(item.media_id, item.media_name, item.file_type, item.duration, imgSrc);
        });

        if (items.length === 0) {
            itemsHtml = '<div id="editItemsEmpty" style="margin:24px 16px;padding:40px 20px;border:2px dashed var(--border-light);border-radius:var(--radius-lg);text-align:center">' +
                '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder,#bbb)" stroke-width="1.5" stroke-linecap="round" style="margin-bottom:10px"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>' +
                '<div style="font-size:0.88rem;font-weight:600;color:var(--text-secondary);margin-bottom:4px">No items yet</div>' +
                '<div style="font-size:0.8rem;color:var(--text-muted)">Drag media here or click items in the library to add</div>' +
            '</div>';
        }

        $('#editPlaylistItemsList').html(itemsHtml);

        $('#editPlaylistItemsList').sortable({
            handle: '.drag-handle',
            placeholder: 'playlist-item',
            opacity: 0.7,
            update: function() { updateEditCounts(); }
        });

        updateEditCounts();
    }).fail(function() {
        $('#editPlaylistItemsList').html('<div class="alert alert-danger">Failed to load data.</div>');
    });
}

function buildPlaylistItemHtml(mediaId, name, fileType, duration, imgSrc) {
    var badgeClass = fileType === 'image' ? 'badge-info' : 'badge-primary';
    var idx = $('#editPlaylistItemsList .playlist-item').length + 1;
    return '<div class="playlist-item" data-media-id="' + mediaId + '" style="display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid var(--border-light);border-radius:var(--radius);margin-bottom:4px;background:var(--surface-primary);transition:box-shadow 0.15s,border-color 0.15s" onmouseover="this.style.borderColor=\'var(--monday-blue-light,#cce0ff)\';this.style.boxShadow=\'0 1px 4px rgba(0,102,255,0.08)\';this.querySelector(\'.remove-item\').style.opacity=\'1\'" onmouseout="this.style.borderColor=\'var(--border-light)\';this.style.boxShadow=\'none\';this.querySelector(\'.remove-item\').style.opacity=\'0\'">' +
        '<span class="drag-handle" style="cursor:grab;display:flex;flex-direction:column;gap:1px;padding:4px 2px;color:var(--text-placeholder,#bbb);flex-shrink:0" title="Drag to reorder">' +
            '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2.5" cy="1.5" r="1.2"/><circle cx="7.5" cy="1.5" r="1.2"/><circle cx="2.5" cy="5" r="1.2"/><circle cx="7.5" cy="5" r="1.2"/><circle cx="2.5" cy="8.5" r="1.2"/><circle cx="7.5" cy="8.5" r="1.2"/><circle cx="2.5" cy="12" r="1.2"/><circle cx="7.5" cy="12" r="1.2"/></svg>' +
        '</span>' +
        '<span style="font-size:0.7rem;color:var(--text-muted);width:18px;text-align:center;flex-shrink:0;font-weight:500" class="item-index">' + idx + '</span>' +
        '<img src="' + imgSrc + '" alt="" style="width:40px;height:28px;object-fit:cover;border-radius:var(--radius-sm);flex-shrink:0;background:var(--surface-secondary)">' +
        '<div class="item-info" style="flex:1;min-width:0;display:flex;flex-direction:column;gap:1px">' +
            '<div class="item-name" style="font-size:0.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escapeHtml(name) + '</div>' +
            '<span class="badge ' + badgeClass + '" style="font-size:0.68rem;padding:0px 5px;width:fit-content">' + fileType.charAt(0).toUpperCase() + fileType.slice(1) + '</span>' +
        '</div>' +
        '<div style="display:flex;align-items:center;border:1px solid var(--border-light);border-radius:var(--radius-md);overflow:hidden;flex-shrink:0;height:28px">' +
            '<input type="number" class="duration-input" value="' + duration + '" min="1" max="3600" title="Duration (seconds)" style="width:48px;border:none;text-align:center;font-size:0.82rem;font-weight:600;padding:4px 2px;outline:none;-moz-appearance:textfield;background:transparent" onwheel="this.blur()">' +
            '<span style="padding:0 7px 0 0;font-size:0.74rem;color:var(--text-muted);font-weight:500;background:var(--surface-secondary);height:100%;display:flex;align-items:center;border-left:1px solid var(--border-light)"><span style="padding-left:5px">s</span></span>' +
        '</div>' +
        '<button class="remove-item" title="Remove" style="width:22px;height:22px;border-radius:var(--radius-full);border:none;background:transparent;color:var(--monday-red,#d83a52);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;opacity:0;transition:opacity 0.15s,background 0.15s" onmouseover="this.style.background=\'var(--monday-red-light,#fde8eb)\'" onmouseout="this.style.background=\'transparent\'">' +
            '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '</button>' +
    '</div>';
}

function updateEditCounts() {
    var count = $('#editPlaylistItemsList .playlist-item').length;
    var total = 0;
    $('#editPlaylistItemsList .duration-input').each(function() {
        total += parseInt($(this).val()) || 0;
    });
    var mins = Math.floor(total / 60);
    var secs = total % 60;
    var formatted = mins + ':' + (secs < 10 ? '0' : '') + secs;

    $('#editItemCount').text(count);
    $('#editTotalDuration').text(formatted);
    $('#editItemCountTop').text(count + ' item' + (count !== 1 ? 's' : ''));
    $('#editTotalDurationBottom').text(formatted);

    // Re-number index badges
    $('#editPlaylistItemsList .playlist-item').each(function(i) {
        $(this).find('.item-index').text(i + 1);
    });

    if (count === 0 && $('#editItemsEmpty').length === 0) {
        $('#editPlaylistItemsList').html(
            '<div id="editItemsEmpty" style="margin:24px 16px;padding:40px 20px;border:2px dashed var(--border-light);border-radius:var(--radius-lg);text-align:center">' +
                '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder,#bbb)" stroke-width="1.5" stroke-linecap="round" style="margin-bottom:10px"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>' +
                '<div style="font-size:0.88rem;font-weight:600;color:var(--text-secondary);margin-bottom:4px">No items yet</div>' +
                '<div style="font-size:0.8rem;color:var(--text-muted)">Drag media here or click items in the library to add</div>' +
            '</div>'
        );
    }
}

function refreshMediaLibrary() {
    if (!editPlaylistId) return;
    $('#editMediaLibrary').html('<div class="text-center text-muted text-sm" style="padding:24px 8px">Refreshing...</div>');
    $.getJSON(BASE_URL + 'playlists/api.php?action=get_media&id=' + editPlaylistId, function(mData) {
        var mediaHtml = '';
        var mediaCount = 0;
        if (mData.success && mData.media.length > 0) {
            mediaCount = mData.media.length;
            mData.media.forEach(function(m) {
                var imgSrc = m.file_type === 'image'
                    ? BASE_URL + 'uploads/media/' + m.filename
                    : (m.thumbnail ? BASE_URL + 'uploads/thumbnails/' + m.thumbnail : BASE_URL + 'assets/video-placeholder.svg');
                mediaHtml += '<div class="playlist-media-item" data-id="' + m.id + '" data-name="' + escapeHtml(m.name) + '" data-type="' + m.file_type + '" data-duration="' + (m.duration || 10) + '" style="position:relative">' +
                    '<img src="' + imgSrc + '" alt="" style="border-radius:var(--radius-sm)">' +
                    '<div style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;background:rgba(0,102,255,0.08);opacity:0;transition:opacity 0.15s;border-radius:var(--radius-md);pointer-events:none" class="media-add-overlay">' +
                        '<span style="width:22px;height:22px;border-radius:var(--radius-full);background:var(--monday-blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 1px 4px rgba(0,0,0,0.15)">+</span>' +
                    '</div>' +
                    '<div><div class="item-name">' + escapeHtml(m.name) + '</div>' +
                    '<div class="item-type">' + m.file_type.charAt(0).toUpperCase() + m.file_type.slice(1) + ' &middot; ' + (m.duration || 10) + 's</div></div></div>';
            });
        } else {
            mediaHtml = '<div class="text-center text-muted text-sm" style="padding:24px 8px">No media available.<br><a href="' + BASE_URL + 'media/">Upload Media</a></div>';
        }
        $('#editMediaLibrary').html(mediaHtml);
        $('#editMediaLibraryCount').text(mediaCount);
        $('#editMediaSearch').val('');

        // Re-bind hover overlays
        $('#editMediaLibrary').off('mouseenter.addOverlay mouseleave.addOverlay')
            .on('mouseenter.addOverlay', '.playlist-media-item', function() {
                $(this).find('.media-add-overlay').css('opacity', '1');
            })
            .on('mouseleave.addOverlay', '.playlist-media-item', function() {
                $(this).find('.media-add-overlay').css('opacity', '0');
            });

        showToast('Media library refreshed');
    }).fail(function() {
        showToast('Failed to refresh media', 'error');
    });
}

function formatDuration(seconds) {
    seconds = parseInt(seconds) || 0;
    if (seconds >= 3600) {
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }
    var mins = Math.floor(seconds / 60);
    var secs = seconds % 60;
    return mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function statusBadgeHtml(isActive) {
    if (isActive) {
        return '<span class="badge badge-success">Active</span>';
    }
    return '<span class="badge badge-secondary">Inactive</span>';
}

function refreshPlaylistsTable() {
    $.getJSON(BASE_URL + 'playlists/api.php?action=list', function(resp) {
        if (!resp.success) return;

        var playlists = resp.playlists || [];
        var isSuperAdmin = resp.is_super_admin;
        var stats = resp.stats;
        var tbodyHtml = '';

        playlists.forEach(function(pl) {
            var status = pl.is_active == 1 ? 'active' : 'inactive';
            var borderColor = pl.is_active == 1 ? (pl.screen_count > 0 ? 'var(--monday-green)' : 'var(--monday-yellow)') : 'var(--border-light)';
            var companyName = pl.company_name || '';

            tbodyHtml += '<tr class="playlist-row" onclick="openManagePlaylist(' + pl.id + ')" role="button" tabindex="0"' +
                ' data-name="' + escapeHtml((pl.name || '').toLowerCase()) + '"' +
                ' data-company="' + (isSuperAdmin ? escapeHtml(companyName.toLowerCase()) : '') + '"' +
                ' data-status="' + status + '"' +
                ' style="cursor:pointer;border-left:3px solid ' + borderColor + '">';

            if (isSuperAdmin) {
                tbodyHtml += '<td class="text-muted text-sm">' + escapeHtml(companyName) + '</td>';
            }

            tbodyHtml += '<td><strong>' + escapeHtml(pl.name) + '</strong></td>' +
                '<td>' + pl.item_count + '</td>' +
                '<td class="text-muted">' + formatDuration(pl.total_duration) + '</td>' +
                '<td>' + pl.screen_count + '</td>' +
                '<td>' + statusBadgeHtml(pl.is_active == 1) + '</td>';

            tbodyHtml += '</tr>';
        });

        $('#playlistsTable tbody').html(tbodyHtml);

        // Re-apply any active search/status filters
        var searchInput = document.getElementById('playlistSearchInput');
        var statusFilter = document.getElementById('playlistStatusFilter');
        if (searchInput && statusFilter && (searchInput.value || statusFilter.value !== 'all')) {
            searchInput.dispatchEvent(new Event('input'));
        }
    });
}

window.duplicatePlaylist = function(playlistId) {
    if (!playlistId) {
        showToast('No playlist selected.', 'error');
        return;
    }
    showConfirm({
        title: 'Duplicate Playlist?',
        message: 'This will create a copy of the playlist with all its media items.',
        confirmText: 'Duplicate',
        confirmClass: 'btn-primary',
        onConfirm: function() {
            $.ajax({
                url: BASE_URL + 'playlists/api.php?action=duplicate',
                method: 'POST',
                data: { id: playlistId, csrf_token: CSRF_TOKEN },
                dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message);
                        closeSidePanel('editItemsPanel', true);
                        refreshPlaylistsTable();
                    } else {
                        showToast(resp.message || 'Failed to duplicate', 'error');
                    }
                },
                error: function() {
                    showToast('Failed to duplicate playlist', 'error');
                }
            });
        }
    });
};
</script>
JS;

include __DIR__ . '/../includes/footer.php';
?>
