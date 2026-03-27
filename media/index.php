<?php
$pageTitle = 'Media Library';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];

$filterLocation = $_GET['location_id'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');

$locations = get_user_locations($userId);

// Build query
if ($isSuperAdmin) {
    $where = ["m.is_active = 1"];
    $params = [];
} else {
    $where = ["m.company_id = ?", "m.is_active = 1"];
    $params = [$companyId];
}

if ($filterLocation !== '') {
    $where[] = "m.location_id = ?";
    $params[] = (int)$filterLocation;
}
if ($filterType === 'image' || $filterType === 'video') {
    $where[] = "m.file_type = ?";
    $params[] = $filterType;
}
if ($search !== '') {
    $where[] = "(m.name LIKE ? OR m.tags LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$whereClause = implode(' AND ', $where);
$media = fetch_all(
    "SELECT m.*,
        (SELECT COUNT(*) FROM playlist_items pi WHERE pi.media_id = m.id) as playlist_count,
        u.name as uploader_name,
        c.name as company_name
     FROM media m
     LEFT JOIN users u ON m.uploaded_by = u.id
     LEFT JOIN companies c ON m.company_id = c.id
     WHERE {$whereClause}
     ORDER BY m.created_at DESC",
    $params
);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Media Library</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" placeholder="Search media..." id="searchInput" value="<?= sanitize($search) ?>">
        </div>
        <input type="hidden" id="filterLocation" value="<?= sanitize($filterLocation) ?>">
        <div class="search-wrapper location-filter-wrapper" style="position:relative">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <input type="text" class="search-input" id="mediaLocationSearch" placeholder="All Locations" autocomplete="off" value="<?php if ($filterLocation) { foreach ($locations as $loc) { if ($loc['id'] == $filterLocation) echo sanitize($loc['name']); } } ?>">
            <div id="mediaLocationResults" class="search-dropdown"></div>
        </div>
        <select class="form-control" onchange="filterClientSide();" id="filterType">
            <option value="">All Types</option>
            <option value="image" <?= $filterType === 'image' ? 'selected' : '' ?>>Images</option>
            <option value="video" <?= $filterType === 'video' ? 'selected' : '' ?>>Videos</option>
        </select>
        <button class="btn btn-outline" id="bulkSelectToggle" onclick="toggleBulkSelectMode()">Select</button>
        <button class="btn btn-primary" data-panel="uploadPanel">+ Upload Media</button>
    </div>
</div>

<?php if (empty($media)): ?>
<div class="card">
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
        </svg>
        <h4>No media yet</h4>
        <p>Media are the images and videos that display on your screens. Upload files to build your content library.</p>
        <button class="btn btn-primary" data-panel="uploadPanel">+ Upload Media</button>
    </div>
</div>
<?php else: ?>
<div class="media-grid" id="mediaGrid">
    <?php foreach ($media as $m): ?>
    <div class="media-card" data-media-id="<?= $m['id'] ?>" data-file-type="<?= $m['file_type'] ?>" data-name="<?= sanitize(strtolower($m['name'])) ?>" data-tags="<?= sanitize(strtolower($m['tags'] ?? '')) ?>" data-company="<?= sanitize(strtolower($m['company_name'] ?? '')) ?>" data-status="active" onclick="openMediaPreview(<?= $m['id'] ?>)" style="cursor:pointer">
        <div class="thumb-container">
            <?php if ($m['file_type'] === 'image'): ?>
                <img src="<?= media_url($m['filename']) ?>" alt="<?= sanitize($m['name']) ?>" loading="lazy">
            <?php else: ?>
                <img src="<?= thumb_url($m['thumbnail']) ?>" alt="<?= sanitize($m['name']) ?>" loading="lazy">
                <div class="play-overlay">
                    <svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg>
                </div>
            <?php endif; ?>
        </div>
        <div class="media-info">
            <div class="media-name" title="<?= sanitize($m['name']) ?>"><?= sanitize($m['name']) ?></div>
            <?php if ($isSuperAdmin && !empty($m['company_name'])): ?>
                <div class="text-xs text-muted"><?= sanitize($m['company_name']) ?></div>
            <?php endif; ?>
            <div class="media-meta">
                <span class="badge <?= $m['file_type'] === 'image' ? 'badge-info' : 'badge-primary' ?>"><?= ucfirst($m['file_type']) ?></span>
                <span><?= format_bytes($m['file_size']) ?></span>
                <?php /* Dimensions: requires width/height INT columns on media table. Migration: ALTER TABLE media ADD COLUMN width INT DEFAULT 0, ADD COLUMN height INT DEFAULT 0; */ ?>
                <?php if (!empty($m['width'] ?? 0) && !empty($m['height'] ?? 0)): ?>
                <span><?= (int)$m['width'] ?>&times;<?= (int)$m['height'] ?></span>
                <?php endif; ?>
                <span><?= $m['duration'] ?>s</span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- No-results state for search/filter (hidden by default) -->
<div class="card" id="mediaNoResults" style="display:none">
    <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            <line x1="8" y1="8" x2="14" y2="14"/><line x1="14" y1="8" x2="8" y2="14"/>
        </svg>
        <h4>No matching media</h4>
        <p>Try adjusting your search terms or filters.</p>
    </div>
</div>
<?php endif; ?>

<!-- Pagination -->
<div id="mediaPager"></div>

<!-- Bulk Action Bar (hidden by default) -->
<div id="bulkActionBar" style="display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);z-index:1000;background:var(--surface-primary);border:1px solid var(--border-light);border-radius:var(--radius);padding:10px 20px;box-shadow:0 8px 32px rgba(0,0,0,0.18);align-items:center;gap:16px;font-size:0.85rem">
    <span id="bulkSelectedCount" style="font-weight:600;color:var(--text-primary);white-space:nowrap">0 selected</span>
    <div style="width:1px;height:24px;background:var(--border-light)"></div>
    <div style="position:relative;display:inline-block" id="bulkTagDropdownWrap">
        <button class="btn btn-outline btn-sm" onclick="toggleBulkTagDropdown()">Tag</button>
        <div id="bulkTagDropdown" style="display:none;position:absolute;bottom:calc(100% + 8px);left:0;background:var(--surface-primary);border:1px solid var(--border-light);border-radius:var(--radius);padding:12px;box-shadow:0 8px 24px rgba(0,0,0,0.15);min-width:260px;z-index:1001">
            <div class="form-group" style="margin-bottom:8px">
                <label style="font-size:0.75rem;font-weight:600;margin-bottom:4px;display:block">Tags</label>
                <input type="text" class="form-control" id="bulkTagInput" placeholder="e.g. promo, sale, holiday" style="font-size:0.8rem">
            </div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.75rem;cursor:pointer">
                    <input type="radio" name="bulkTagMode" value="append" checked> Append
                </label>
                <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.75rem;cursor:pointer">
                    <input type="radio" name="bulkTagMode" value="replace"> Replace
                </label>
            </div>
            <button class="btn btn-primary btn-sm" onclick="executeBulkTag()" style="width:100%;font-size:0.8rem">Apply Tags</button>
        </div>
    </div>
    <button class="btn btn-danger-outline btn-sm" onclick="executeBulkDelete()">Delete</button>
    <div style="width:1px;height:24px;background:var(--border-light)"></div>
    <button class="btn btn-outline btn-sm" onclick="exitBulkSelectMode()">Cancel</button>
</div>

<!-- Upload Side Panel -->
<div class="side-panel-overlay" id="uploadPanelOverlay"></div>
<div class="side-panel" id="uploadPanel">
    <div class="side-panel-header">
        <h2>Upload Media <span class="upload-file-count-badge" id="uploadFileCountBadge" style="display:none"></span></h2>
        <button class="side-panel-close">&times;</button>
    </div>
    <div class="side-panel-body">
        <!-- Select state: location + dropzone + file list -->
        <div id="uploadSelectState">
            <div class="form-group">
                <label>Location(s)</label>
                <div style="margin-bottom:6px">
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8rem;cursor:pointer">
                        <input type="checkbox" id="uploadAllLocations" checked> All Locations
                    </label>
                </div>
                <div id="uploadLocationCheckboxes" style="display:none;max-height:160px;overflow-y:auto;border:1px solid var(--border-light);border-radius:var(--radius);padding:4px 0">
                    <?php foreach ($locations as $loc): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;font-size:0.8rem" onmouseover="this.style.background='var(--surface-secondary)'" onmouseout="this.style.background=''">
                        <input type="checkbox" class="upload-loc-check" value="<?= $loc['id'] ?>"> <?= sanitize($loc['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="upload-zone" id="uploadZone">
                <div class="upload-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="56" height="56">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                </div>
                <p style="font-size:0.95rem;font-weight:500;margin-bottom:4px;color:var(--text-primary)">Drag and drop files here</p>
                <p style="font-size:0.85rem;color:var(--text-secondary);margin-bottom:8px">or <span style="color:var(--primary);cursor:pointer;font-weight:500">click to browse</span></p>
                <p class="text-xs text-muted" style="margin:0">JPEG, PNG, MP4 &mdash; Max 500 MB per file</p>
            </div>
            <input type="file" id="fileInput" multiple accept="image/jpeg,image/png,video/mp4" style="display:none">

            <!-- File list preview (hidden until files selected) -->
            <div id="uploadFileList" class="upload-file-list" style="display:none">
                <div class="upload-file-list-header">
                    <span class="upload-file-list-title">Selected Files</span>
                    <button type="button" class="btn btn-sm btn-outline" id="uploadClearAllBtn">Clear All</button>
                </div>
                <div id="uploadFileListItems" class="upload-file-list-items">
                    <!-- File rows injected by JS -->
                </div>
            </div>

            <!-- Upload action button (hidden until files selected) -->
            <button type="button" class="btn btn-primary" id="uploadStartBtn" style="display:none;width:100%;margin-top:12px;padding:10px 0;font-size:0.95rem">
                Upload Files
            </button>
        </div>

        <!-- Uploading state: progress bar -->
        <div id="uploadProgressState" style="display:none">
            <div style="text-align:center;padding:32px 0 16px">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2" class="upload-spin-icon">
                    <path d="M21 12a9 9 0 11-6.219-8.56"/>
                </svg>
                <p style="font-size:0.95rem;font-weight:500;margin-top:12px;color:var(--text-primary)" id="uploadProgressLabel">Uploading...</p>
            </div>
            <div class="upload-progress" style="display:block">
                <div class="progress-bar-container">
                    <div class="progress-bar" id="uploadProgressBar"></div>
                </div>
                <div class="text-center text-sm mt-1" id="uploadProgressText">0%</div>
            </div>
        </div>

        <!-- Success state -->
        <div id="uploadSuccessState" style="display:none">
            <div style="text-align:center;padding:48px 0 24px">
                <div style="width:64px;height:64px;border-radius:var(--radius-full);background:var(--success, var(--monday-green));display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>
                <p style="font-size:1.05rem;font-weight:600;color:var(--text-primary);margin-bottom:4px" id="uploadSuccessMessage">Files uploaded successfully!</p>
                <p style="font-size:0.85rem;color:var(--text-secondary);margin:0" id="uploadSuccessDetail"></p>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="button" class="btn btn-outline" id="uploadMoreBtn" style="flex:1">Upload More</button>
                <button type="button" class="btn btn-primary" id="uploadDoneBtn" style="flex:1">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- Media Preview Side Panel (LG - 600px) -->
<div class="side-panel-overlay" id="previewPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="previewPanel">
    <div class="side-panel-header">
        <h2 id="previewPanelTitle">Media Preview</h2>
        <button class="side-panel-close">&times;</button>
    </div>
    <div class="side-panel-body" id="previewPanelBody">
        <div class="text-center text-muted" style="padding:48px 0">Loading...</div>
    </div>
    <div class="side-panel-actions" id="previewPanelActions" style="display:none;justify-content:space-between;align-items:center">
        <button class="btn btn-danger-outline btn-sm" id="previewDeleteBtn" onclick="deletePreviewMedia()" style="display:none">Delete</button>
        <div id="previewDeleteSpacer"></div>
        <button class="btn btn-primary" id="previewSaveBtn" onclick="savePreviewMedia()">Save Changes</button>
    </div>
</div>

<?php
$baseUrl = BASE_URL;
$csrfToken = csrf_token();
$isSuperAdminJs = $isSuperAdmin ? 'true' : 'false';
$locationsJsonMedia = json_encode($locations);
$extraScripts = <<<SCRIPT
<script>
var BASE_URL = '{$baseUrl}';
var CSRF_TOKEN = '{$csrfToken}';
var currentPreviewId = null;
var mediaLocations = {$locationsJsonMedia};

// ---- Searchable location filter ----
(function() {
    var input = $('#mediaLocationSearch');
    var results = $('#mediaLocationResults');
    var hidden = $('#filterLocation');

    function showDropdown() {
        var term = input.val().toLowerCase();
        var html = '<div class="dropdown-item muted" data-id="">All Locations</div>';
        var count = 0;
        mediaLocations.forEach(function(loc) {
            if (!term || loc.name.toLowerCase().indexOf(term) !== -1) {
                html += '<div class="dropdown-item" data-id="' + loc.id + '">' + escapeHtml(loc.name) + '</div>';
                count++;
            }
        });
        if (count === 0 && term) {
            html += '<div class="dropdown-empty">No locations found</div>';
        }
        results.html(html).show();
    }

    input.on('focus input', showDropdown);

    results.on('click', '.dropdown-item', function() {
        var id = $(this).data('id');
        hidden.val(id || '');
        input.val(id ? $(this).text() : '');
        input.attr('placeholder', 'All Locations');
        results.hide();
        applyFilters();
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.location-filter-wrapper').length) {
            results.hide();
        }
    });
})();

function applyFilters() {
    var loc = $('#filterLocation').val();
    var type = $('#filterType').val();
    var q = $('#searchInput').val();
    var params = [];
    if (loc) params.push('location_id=' + loc);
    if (type) params.push('type=' + type);
    if (q) params.push('q=' + encodeURIComponent(q));
    window.location.search = params.length ? '?' + params.join('&') : '';
}

/* ---- Client-side search debounce ---- */
var searchTimer = null;
$('#searchInput').on('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        filterClientSide();
    }, 250);
});

var currentMediaPage = 1;
var mediaPerPage = 48;

function filterClientSide() {
    var term = $('#searchInput').val().toLowerCase();
    var typeFilter = $('#filterType').val();
    var totalCount = 0;
    var matchCount = 0;
    $('.media-card').each(function() {
        totalCount++;
        var name = $(this).data('name') || $(this).find('.media-name').text().toLowerCase();
        var tags = $(this).data('tags') || '';
        var company = $(this).data('company') || '';
        var fileType = $(this).data('file-type');
        var matchesSearch = !term || String(name).indexOf(term) !== -1 || String(tags).indexOf(term) !== -1 || String(company).indexOf(term) !== -1;
        var matchesType = !typeFilter || fileType === typeFilter;
        $(this).data('filter-match', matchesSearch && matchesType);
        if (matchesSearch && matchesType) matchCount++;
    });

    // Reset to page 1 on filter change, then render pagination
    currentMediaPage = 1;
    paginateMedia(1);

    // Show/hide no-results state
    var noResults = document.getElementById('mediaNoResults');
    if (noResults) {
        noResults.style.display = (totalCount > 0 && matchCount === 0) ? '' : 'none';
    }

    // Update filter count indicator
    var filterCount = document.getElementById('mediaFilterCount');
    if (filterCount) {
        if (term || typeFilter) {
            filterCount.textContent = matchCount + ' of ' + totalCount + ' items';
        } else {
            filterCount.textContent = '';
        }
    }
}

function paginateMedia(page) {
    var allCards = jQuery('.media-card');
    var matching = allCards.filter(function() { return jQuery(this).data('filter-match') !== false; });
    var total = matching.length;
    var totalPages = Math.ceil(total / mediaPerPage) || 1;
    page = Math.max(1, Math.min(page, totalPages));
    currentMediaPage = page;
    var start = (page - 1) * mediaPerPage;
    var end = start + mediaPerPage;

    // Hide all, then show current page of matching items
    allCards.hide();
    matching.each(function(i) {
        if (i >= start && i < end) jQuery(this).show();
    });

    renderMediaPager(totalPages, total);
}

function renderMediaPager(totalPages, totalItems) {
    var pagerEl = jQuery('#mediaPager');
    if (totalPages <= 1) { pagerEl.html(''); return; }
    var p = currentMediaPage;
    var html = '<div class="pagination">';
    html += '<button class="pagination-btn" onclick="paginateMedia(' + (p - 1) + ')" ' + (p === 1 ? 'disabled' : '') + '>&lsaquo; Prev</button>';
    for (var i = 1; i <= totalPages; i++) {
        if (totalPages > 7) {
            if (i === 1 || i === totalPages || (i >= p - 1 && i <= p + 1)) {
                html += '<button class="pagination-btn ' + (i === p ? 'active' : '') + '" onclick="paginateMedia(' + i + ')">' + i + '</button>';
            } else if (i === p - 2 || i === p + 2) {
                html += '<span class="pagination-dots">&hellip;</span>';
            }
        } else {
            html += '<button class="pagination-btn ' + (i === p ? 'active' : '') + '" onclick="paginateMedia(' + i + ')">' + i + '</button>';
        }
    }
    html += '<button class="pagination-btn" onclick="paginateMedia(' + (p + 1) + ')" ' + (p === totalPages ? 'disabled' : '') + '>Next &rsaquo;</button>';
    html += '<span class="pagination-info">' + totalItems + ' items</span>';
    html += '</div>';
    pagerEl.html(html);
}

// Initialize pagination on page load
filterClientSide();

/* ---- Legacy editMedia shim (redirects to preview panel) ---- */
function editMedia(id) {
    openMediaPreview(id);
}

/* ---- Media Preview Panel ---- */
function openMediaPreview(id) {
    currentPreviewId = id;
    $('#previewPanelTitle').text('Media Preview');
    $('#previewPanelBody').html('<div class="text-center text-muted" style="padding:48px 0">Loading...</div>');
    $('#previewPanelActions').hide();
    openSidePanel('previewPanel');

    $.ajax({
        url: BASE_URL + 'media/api.php?action=get&id=' + id,
        type: 'GET',
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                renderPreviewPanel(resp.media, resp.playlists);
            } else {
                $('#previewPanelBody').html('<div class="text-center text-danger" style="padding:48px 0">' + (resp.message || 'Failed to load media.') + '</div>');
            }
        },
        error: function() {
            $('#previewPanelBody').html('<div class="text-center text-danger" style="padding:48px 0">Failed to load media details.</div>');
        }
    });
}

function renderPreviewPanel(media, playlists) {
    $('#previewPanelTitle').text(media.name);
    var isImage = media.file_type === 'image';
    var isVideo = media.file_type === 'video';
    var inPlaylists = playlists && playlists.length > 0;

    // SVG icon fragments for info bar
    var svgSize = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;opacity:0.55"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>';
    var svgClock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;opacity:0.55"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
    var svgCalendar = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;opacity:0.55"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
    var svgList = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.6"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';
    var dot = '<span style="opacity:0.3">&middot;</span>';

    // Preview area with dark background
    var previewHtml = '<div style="background:#1a1a2e;border-radius:var(--radius);overflow:hidden;margin-bottom:16px;display:flex;align-items:center;justify-content:center;min-height:200px">';
    if (isImage) {
        previewHtml += '<img src="' + media.media_src + '" alt="' + escapeHtml(media.name) + '" style="max-width:100%;max-height:400px;object-fit:contain;display:block">';
    } else if (isVideo) {
        previewHtml += '<video controls style="max-width:100%;max-height:400px;object-fit:contain;display:block">';
        previewHtml += '<source src="' + media.media_src + '" type="' + (media.mime_type || 'video/mp4') + '">';
        previewHtml += 'Your browser does not support the video tag.';
        previewHtml += '</video>';
    }
    previewHtml += '</div>';

    // Compact info bar with icon tokens and middots
    var badgeColor = isImage ? 'background:var(--monday-blue-light);color:var(--monday-blue)' : 'background:var(--monday-purple-light);color:var(--monday-purple)';
    var infoTokens = [];
    infoTokens.push('<span style="display:inline-block;padding:1px 8px;border-radius:var(--radius-full);font-size:0.72rem;font-weight:600;letter-spacing:0.02em;' + badgeColor + '">' + (isImage ? 'Image' : 'Video') + '</span>');
    infoTokens.push(svgSize + ' ' + media.file_size_formatted);
    // Dimensions: requires width/height INT columns on media table. Migration: ALTER TABLE media ADD COLUMN width INT DEFAULT 0, ADD COLUMN height INT DEFAULT 0;
    if (media.width && media.height && parseInt(media.width) > 0 && parseInt(media.height) > 0) {
        infoTokens.push(media.width + '\u00d7' + media.height);
    }
    infoTokens.push(svgClock + ' ' + media.duration + 's');
    infoTokens.push(svgCalendar + ' ' + media.created_at_formatted);

    previewHtml += '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px 12px;padding:10px 14px;background:var(--surface-secondary);border-radius:var(--radius);margin-bottom:16px;font-size:0.8rem;font-weight:500;color:var(--text-secondary)">';
    previewHtml += infoTokens.join(dot);
    previewHtml += '</div>';

    // Secondary info line for company/location
    var secondaryParts = [];
    if (media.company_name) {
        secondaryParts.push(escapeHtml(media.company_name));
    }
    if (media.location_name) {
        secondaryParts.push(escapeHtml(media.location_name));
    }
    if (secondaryParts.length > 0) {
        previewHtml += '<div style="font-size:0.8rem;color:var(--text-muted);margin-bottom:16px;padding:0 2px">' + secondaryParts.join(' &middot; ') + '</div>';
    }

    // Edit form fields (no header)
    previewHtml += '<div style="margin-bottom:16px">';
    previewHtml += '<div class="form-group"><label>Name</label>';
    previewHtml += '<input type="text" class="form-control" id="previewMediaName" value="' + escapeHtml(media.name) + '"></div>';
    previewHtml += '<div class="form-group"><label>Tags</label>';
    previewHtml += '<input type="text" class="form-control" id="previewMediaTags" value="' + escapeHtml(media.tags || '') + '" placeholder="e.g. promo, sale, holiday">';
    previewHtml += '<div style="font-size:0.72rem;color:var(--text-muted);margin-top:4px">Comma-separated tags</div></div>';

    // Location assignment editor
    var hasLocationAssignments = media.location_ids && media.location_ids.length > 0;
    var isAllLocations = !hasLocationAssignments;
    previewHtml += '<div class="form-group"><label>Location(s)</label>';
    previewHtml += '<div style="margin-bottom:4px"><label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8rem;cursor:pointer"><input type="checkbox" id="previewAllLocations"' + (isAllLocations ? ' checked' : '') + '> All Locations</label></div>';
    previewHtml += '<div id="previewLocationCheckboxes" style="' + (isAllLocations ? 'display:none;' : '') + 'max-height:140px;overflow-y:auto;border:1px solid var(--border-light);border-radius:var(--radius);padding:4px 0">';
    for (var li = 0; li < mediaLocations.length; li++) {
        var loc = mediaLocations[li];
        var locChecked = hasLocationAssignments && media.location_ids.indexOf(String(loc.id)) !== -1;
        if (!locChecked && hasLocationAssignments) {
            // Also check with integer comparison
            for (var lj = 0; lj < media.location_ids.length; lj++) {
                if (parseInt(media.location_ids[lj]) === parseInt(loc.id)) {
                    locChecked = true;
                    break;
                }
            }
        }
        previewHtml += '<label style="display:flex;align-items:center;gap:8px;padding:6px 10px;cursor:pointer;font-size:0.8rem" onmouseover="this.style.background=\'var(--surface-secondary)\'" onmouseout="this.style.background=\'\'"><input type="checkbox" class="preview-loc-check" value="' + loc.id + '"' + (locChecked ? ' checked' : '') + '> ' + escapeHtml(loc.name) + '</label>';
    }
    previewHtml += '</div></div>';

    previewHtml += '<div class="form-group"><label>' + (isVideo ? 'Duration (seconds)' : 'Display Duration (seconds)') + '</label>';
    previewHtml += '<input type="number" class="form-control" id="previewMediaDuration" value="' + media.duration + '" min="1" max="3600"></div>';
    previewHtml += '</div>';

    // Playlist usage as pills
    previewHtml += '<div style="margin-bottom:8px">';
    if (inPlaylists) {
        previewHtml += '<div style="display:flex;flex-wrap:wrap;gap:6px">';
        for (var i = 0; i < playlists.length; i++) {
            previewHtml += '<a href="' + BASE_URL + 'playlists/?manage=' + playlists[i].id + '" style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;background:var(--surface-secondary);border-radius:var(--radius-full);font-size:0.72rem;font-weight:500;color:var(--text-secondary);text-decoration:none;transition:background 0.15s" onmouseover="this.style.background=\'var(--monday-blue-light)\'" onmouseout="this.style.background=\'var(--surface-secondary)\'">' + svgList + ' ' + escapeHtml(playlists[i].name) + '</a>';
        }
        previewHtml += '</div>';
    } else {
        previewHtml += '<span style="font-size:0.8rem;color:var(--text-secondary);opacity:0.7;font-style:italic">Not assigned to any playlists</span>';
    }
    previewHtml += '</div>';

    // Push to Screen section
    previewHtml += '<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border-light)">';
    previewHtml += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">';
    previewHtml += '<span style="font-size:0.8rem;font-weight:600;color:var(--text-primary)">Push to Screen</span>';
    previewHtml += '<button type="button" class="btn btn-sm btn-outline" id="pushToScreenBtn" onclick="showPushToScreen()" style="font-size:0.72rem;padding:3px 10px">Select Screens</button>';
    previewHtml += '</div>';
    previewHtml += '<div id="pushToScreenSection" style="display:none">';
    previewHtml += '<div class="text-center text-muted text-sm" style="padding:12px">Loading screens...</div>';
    previewHtml += '</div>';
    previewHtml += '</div>';

    $('#previewPanelBody').html(previewHtml);
    $('#previewPanelActions').css('display', 'flex');

    // Show/hide footer delete based on playlist usage
    if (inPlaylists) {
        $('#previewDeleteBtn').hide();
        $('#previewDeleteSpacer').show();
    } else {
        $('#previewDeleteBtn').show();
        $('#previewDeleteSpacer').hide();
    }
}

function savePreviewMedia() {
    if (!currentPreviewId) return;
    var data = {
        id: currentPreviewId,
        name: $('#previewMediaName').val(),
        tags: $('#previewMediaTags').val(),
        duration: $('#previewMediaDuration').val(),
        csrf_token: CSRF_TOKEN
    };
    // Location assignments
    if ($('#previewAllLocations').is(':checked')) {
        data.all_locations = '1';
    } else {
        var locs = [];
        $('.preview-loc-check:checked').each(function() { locs.push($(this).val()); });
        data['location_ids[]'] = locs;
    }
    $('#previewSaveBtn').prop('disabled', true).text('Saving...');
    $.ajax({
        url: BASE_URL + 'media/api.php?action=update',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: function(resp) {
            $('#previewSaveBtn').prop('disabled', false).text('Save Changes');
            if (resp.success) {
                showToast('Media updated successfully.');
                refreshMediaGrid();
            } else {
                showToast(resp.message || 'Update failed.', 'error');
            }
        },
        error: function() {
            $('#previewSaveBtn').prop('disabled', false).text('Save Changes');
            showToast('Request failed. Please try again.', 'error');
        }
    });
}

function deletePreviewMedia() {
    if (!currentPreviewId) return;
    var mediaId = currentPreviewId;
    showConfirm({
        title: 'Delete this media?',
        message: 'This action cannot be undone. Any playlists or screens using this media will be affected.',
        confirmText: 'Delete',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            $.ajax({
                url: BASE_URL + 'media/api.php?action=delete',
                type: 'POST',
                data: { id: mediaId, csrf_token: CSRF_TOKEN },
                dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        showToast('Media deleted.');
                        closeSidePanel('previewPanel');
                        refreshMediaGrid();
                    } else {
                        showToast(resp.message || 'Delete failed.', 'error');
                    }
                },
                error: function() {
                    showToast('Delete failed. Please try again.', 'error');
                }
            });
        }
    });
}

function showPushToScreen() {
    var section = document.getElementById('pushToScreenSection');
    if (section.style.display !== 'none') {
        section.style.display = 'none';
        return;
    }
    section.style.display = '';
    section.innerHTML = '<div class="text-center text-muted text-sm" style="padding:12px">Loading screens...</div>';

    $.getJSON(BASE_URL + 'media/api.php?action=get_screens', function(resp) {
        if (!resp.success || !resp.screens || resp.screens.length === 0) {
            section.innerHTML = '<div class="text-sm text-muted" style="padding:8px 0;font-style:italic">No active screens found.</div>';
            return;
        }

        var html = '<div style="max-height:200px;overflow-y:auto;border:1px solid var(--border-light);border-radius:var(--radius);margin-bottom:8px">';
        resp.screens.forEach(function(scr) {
            var isOnThis = false;
            if (scr.assignment_type === 'single') {
                var ids = (scr.media_ids || '').split(',');
                if (!ids.length && scr.media_id) ids = [String(scr.media_id)];
                isOnThis = ids.indexOf(String(currentPreviewId)) !== -1;
            }
            html += '<label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-bottom:1px solid var(--border-light);cursor:pointer;font-size:0.8rem" onmouseover="this.style.background=\'var(--surface-secondary)\'" onmouseout="this.style.background=\'\'">';
            html += '<input type="checkbox" name="push_screen_id" value="' + scr.id + '"' + (isOnThis ? ' checked disabled' : '') + '>';
            html += '<div style="flex:1;min-width:0">';
            html += '<div style="font-weight:500">' + escapeHtml(scr.name) + '</div>';
            html += '<div class="text-xs text-muted">' + escapeHtml(scr.location_name) + '</div>';
            html += '</div>';
            if (isOnThis) {
                html += '<span style="font-size:0.72rem;color:var(--monday-green);font-weight:500">Active</span>';
            }
            html += '</label>';
        });
        html += '</div>';
        html += '<button type="button" class="btn btn-primary btn-sm" id="doPushToScreenBtn" onclick="doPushToScreen()" style="width:100%;font-size:0.8rem">Push to Selected Screens</button>';
        section.innerHTML = html;
    });
}

function doPushToScreen() {
    if (!currentPreviewId) return;
    var screenIds = [];
    $('input[name="push_screen_id"]:checked:not(:disabled)').each(function() {
        screenIds.push($(this).val());
    });
    if (screenIds.length === 0) {
        showToast('Select at least one screen.', 'error');
        return;
    }

    var btn = document.getElementById('doPushToScreenBtn');
    btn.disabled = true;
    btn.textContent = 'Pushing...';

    $.ajax({
        url: BASE_URL + 'media/api.php?action=push_to_screen',
        type: 'POST',
        data: {
            media_id: currentPreviewId,
            'screen_ids[]': screenIds,
            csrf_token: CSRF_TOKEN
        },
        dataType: 'json',
        success: function(resp) {
            btn.disabled = false;
            btn.textContent = 'Push to Selected Screens';
            if (resp.success) {
                showToast(resp.message);
                document.getElementById('pushToScreenSection').style.display = 'none';
            } else {
                showToast(resp.message || 'Push failed.', 'error');
            }
        },
        error: function() {
            btn.disabled = false;
            btn.textContent = 'Push to Selected Screens';
            showToast('Request failed.', 'error');
        }
    });
}

// escapeHtml() is now provided globally by app.js

function formatBytes(bytes) {
    bytes = parseInt(bytes) || 0;
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

var IS_SUPER_ADMIN = {$isSuperAdminJs};
var bulkSelectMode = false;
var bulkSelectedIds = [];

function toggleBulkSelectMode() {
    if (bulkSelectMode) {
        exitBulkSelectMode();
    } else {
        enterBulkSelectMode();
    }
}

function enterBulkSelectMode() {
    bulkSelectMode = true;
    bulkSelectedIds = [];
    $('#bulkSelectToggle').text('Cancel Select').removeClass('btn-outline').addClass('btn-primary');
    $('#bulkActionBar').css('display', 'flex');
    updateBulkCount();

    // Add checkbox overlays to each media card
    $('.media-card').each(function() {
        var card = $(this);
        // Disable the normal click-to-preview
        card.attr('data-original-onclick', card.attr('onclick') || '');
        card.removeAttr('onclick');
        card.css('cursor', 'pointer');

        // Add checkbox overlay
        var checkbox = $('<div class="bulk-checkbox-overlay" style="position:absolute;top:8px;left:8px;z-index:10;width:24px;height:24px;border-radius:6px;border:2px solid rgba(255,255,255,0.8);background:rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all 0.15s ease;pointer-events:none">' +
            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="opacity:0"><polyline points="20 6 9 17 4 12"/></svg>' +
            '</div>');
        card.find('.thumb-container').css('position', 'relative').append(checkbox);

        // Click handler for selection
        card.on('click.bulkSelect', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var mediaId = getMediaIdFromCard(card);
            if (!mediaId) return;
            var idx = bulkSelectedIds.indexOf(mediaId);
            if (idx === -1) {
                bulkSelectedIds.push(mediaId);
                markCardSelected(card, true);
            } else {
                bulkSelectedIds.splice(idx, 1);
                markCardSelected(card, false);
            }
            updateBulkCount();
        });
    });
}

function exitBulkSelectMode() {
    bulkSelectMode = false;
    bulkSelectedIds = [];
    $('#bulkSelectToggle').text('Select').removeClass('btn-primary').addClass('btn-outline');
    $('#bulkActionBar').css('display', 'none');
    closeBulkTagDropdown();

    // Remove checkbox overlays and restore click handlers
    $('.media-card').each(function() {
        var card = $(this);
        card.off('click.bulkSelect');
        card.find('.bulk-checkbox-overlay').remove();
        card.find('.thumb-container').css('position', '');
        card.css({'outline': '', 'outline-offset': ''});
        var originalOnclick = card.attr('data-original-onclick');
        if (originalOnclick) {
            card.attr('onclick', originalOnclick);
        }
        card.removeAttr('data-original-onclick');
    });
}

function getMediaIdFromCard(card) {
    // Primary: data-media-id attribute
    var mediaId = parseInt(card.data('media-id'));
    if (mediaId) return mediaId;
    // Fallback: extract from the original onclick attribute
    var onclickStr = card.attr('data-original-onclick') || card.attr('onclick') || '';
    var match = onclickStr.match(/openMediaPreview\((\d+)\)/);
    if (match) return parseInt(match[1]);
    return 0;
}

function markCardSelected(card, selected) {
    var overlay = card.find('.bulk-checkbox-overlay');
    if (selected) {
        overlay.css({'background': 'var(--monday-blue)', 'border-color': 'var(--monday-blue)'});
        overlay.find('svg').css('opacity', '1');
        card.css({'outline': '2px solid var(--monday-blue)', 'outline-offset': '-2px'});
    } else {
        overlay.css({'background': 'rgba(0,0,0,0.3)', 'border-color': 'rgba(255,255,255,0.8)'});
        overlay.find('svg').css('opacity', '0');
        card.css({'outline': '', 'outline-offset': ''});
    }
}

function updateBulkCount() {
    var count = bulkSelectedIds.length;
    $('#bulkSelectedCount').text(count + ' selected');
}

function toggleBulkTagDropdown() {
    var dd = $('#bulkTagDropdown');
    if (dd.is(':visible')) {
        closeBulkTagDropdown();
    } else {
        dd.show();
        $('#bulkTagInput').focus();
    }
}

function closeBulkTagDropdown() {
    $('#bulkTagDropdown').hide();
    $('#bulkTagInput').val('');
}

// Close tag dropdown when clicking outside
$(document).on('click', function(e) {
    if (!$(e.target).closest('#bulkTagDropdownWrap').length) {
        closeBulkTagDropdown();
    }
});

function executeBulkTag() {
    if (bulkSelectedIds.length === 0) {
        showToast('No media selected.', 'error');
        return;
    }
    var tags = $('#bulkTagInput').val().trim();
    if (!tags) {
        showToast('Please enter at least one tag.', 'error');
        return;
    }
    var mode = $('input[name="bulkTagMode"]:checked').val();

    $.ajax({
        url: BASE_URL + 'media/api.php?action=bulk_tag',
        type: 'POST',
        data: {
            'ids[]': bulkSelectedIds,
            tags: tags,
            mode: mode,
            csrf_token: CSRF_TOKEN
        },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                showToast(resp.message);
                closeBulkTagDropdown();
                exitBulkSelectMode();
                refreshMediaGrid();
            } else {
                showToast(resp.message || 'Tag update failed.', 'error');
            }
        },
        error: function() {
            showToast('Request failed. Please try again.', 'error');
        }
    });
}

function executeBulkDelete() {
    if (bulkSelectedIds.length === 0) {
        showToast('No media selected.', 'error');
        return;
    }
    showConfirm({
        title: 'Delete ' + bulkSelectedIds.length + ' media item(s)?',
        message: 'This will delete all selected media. Items currently used in playlists will be skipped. This action cannot be undone.',
        confirmText: 'Delete',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            $.ajax({
                url: BASE_URL + 'media/api.php?action=bulk_delete',
                type: 'POST',
                data: {
                    'ids[]': bulkSelectedIds,
                    csrf_token: CSRF_TOKEN
                },
                dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message);
                        exitBulkSelectMode();
                        refreshMediaGrid();
                    } else {
                        showToast(resp.message || 'Bulk delete failed.', 'error');
                    }
                },
                error: function() {
                    showToast('Request failed. Please try again.', 'error');
                }
            });
        }
    });
}

window.refreshMediaGrid = function() {
    var params = [];
    var loc = $('#filterLocation').val();
    var type = $('#filterType').val();
    var q = $('#searchInput').val();
    if (loc) params.push('location_id=' + encodeURIComponent(loc));
    if (type) params.push('type=' + encodeURIComponent(type));
    if (q) params.push('q=' + encodeURIComponent(q));
    var queryString = params.length ? '&' + params.join('&') : '';

    $.getJSON(BASE_URL + 'media/api.php?action=list' + queryString, function(resp) {
        if (!resp.success) return;

        var media = resp.media;
        var stats = resp.stats;
        var isSuperAdmin = resp.is_super_admin;

        // Build grid HTML
        if (media.length === 0) {
            var emptyHtml = '<div class="card"><div class="empty-state">';
            emptyHtml += '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">';
            emptyHtml += '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>';
            emptyHtml += '</svg>';
            emptyHtml += '<h4>No media yet</h4>';
            emptyHtml += '<p>Media are the images and videos that display on your screens. Upload files to build your content library.</p>';
            emptyHtml += '<button class="btn btn-primary" data-panel="uploadPanel">+ Upload Media</button>';
            emptyHtml += '</div></div>';
            if ($('#mediaGrid').length) {
                $('#mediaGrid').replaceWith(emptyHtml);
            } else {
                var target = $('.empty-state').closest('.card');
                if (target.length) {
                    target.replaceWith(emptyHtml);
                }
            }
            return;
        }

        var gridHtml = '';
        for (var i = 0; i < media.length; i++) {
            var m = media[i];
            var safeName = escapeHtml(m.name);
            var safeTags = escapeHtml(m.tags || '');
            var safeCompany = escapeHtml(m.company_name || '');
            var badgeClass = m.file_type === 'image' ? 'badge-info' : 'badge-primary';
            var fileTypeLabel = m.file_type.charAt(0).toUpperCase() + m.file_type.slice(1);

            gridHtml += '<div class="media-card" data-media-id="' + m.id + '" data-file-type="' + escapeHtml(m.file_type) + '" data-name="' + safeName.toLowerCase() + '" data-tags="' + safeTags.toLowerCase() + '" data-company="' + safeCompany.toLowerCase() + '" data-status="active" onclick="openMediaPreview(' + m.id + ')" style="cursor:pointer">';
            gridHtml += '<div class="thumb-container">';
            if (m.file_type === 'image') {
                gridHtml += '<img src="' + escapeHtml(m.media_url) + '" alt="' + safeName + '" loading="lazy">';
            } else {
                gridHtml += '<img src="' + escapeHtml(m.thumb_url) + '" alt="' + safeName + '" loading="lazy">';
                gridHtml += '<div class="play-overlay"><svg viewBox="0 0 24 24"><polygon points="5,3 19,12 5,21"/></svg></div>';
            }
            gridHtml += '</div>';
            gridHtml += '<div class="media-info">';
            gridHtml += '<div class="media-name" title="' + safeName + '">' + safeName + '</div>';
            if (isSuperAdmin && m.company_name) {
                gridHtml += '<div class="text-xs text-muted">' + safeCompany + '</div>';
            }
            gridHtml += '<div class="media-meta">';
            gridHtml += '<span class="badge ' + badgeClass + '">' + fileTypeLabel + '</span>';
            gridHtml += '<span>' + escapeHtml(m.file_size_formatted) + '</span>';
            // Dimensions (requires width/height columns on media table)
            if (m.width && m.height && parseInt(m.width) > 0 && parseInt(m.height) > 0) {
                gridHtml += '<span>' + m.width + '\u00d7' + m.height + '</span>';
            }
            gridHtml += '<span>' + m.duration + 's</span>';
            gridHtml += '</div>';
            gridHtml += '</div>';
            gridHtml += '</div>';
        }

        if ($('#mediaGrid').length) {
            $('#mediaGrid').html(gridHtml);
        } else {
            var target = $('.empty-state').closest('.card');
            if (target.length) {
                target.replaceWith('<div class="media-grid" id="mediaGrid">' + gridHtml + '</div>');
            }
        }

        // Re-apply client-side filters
        filterClientSide();

        // If bulk select mode was active, re-apply it to new cards
        if (bulkSelectMode) {
            bulkSelectMode = false; // temporarily disable to re-enter cleanly
            bulkSelectedIds = [];
            enterBulkSelectMode();
        }
    });
};
</script>
SCRIPT;

include __DIR__ . '/../includes/footer.php';
?>
