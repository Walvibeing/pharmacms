<?php
$pageTitle = 'Emergency Broadcast';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$role = $_SESSION['role'] ?? '';
$isLocationManager = ($role === 'location_manager');

if ($isSuperAdmin) {
    $activeBroadcast = fetch_one(
        "SELECT eb.*, m.name as media_name, m.file_type, m.filename, m.thumbnail, u.name as creator_name, c.name as company_name
         FROM emergency_broadcasts eb
         JOIN media m ON eb.media_id = m.id
         LEFT JOIN users u ON eb.created_by = u.id
         JOIN companies c ON eb.company_id = c.id
         WHERE eb.is_active = 1
         ORDER BY eb.id DESC LIMIT 1"
    );
} else {
    $activeBroadcast = fetch_one(
        "SELECT eb.*, m.name as media_name, m.file_type, m.filename, m.thumbnail, u.name as creator_name
         FROM emergency_broadcasts eb
         JOIN media m ON eb.media_id = m.id
         LEFT JOIN users u ON eb.created_by = u.id
         WHERE eb.company_id = ? AND eb.is_active = 1
         ORDER BY eb.id DESC LIMIT 1",
        [$companyId]
    );
}

// Count affected screens
$affectedCount = 0;
if ($activeBroadcast) {
    $bcCompanyId = $activeBroadcast['company_id'];
    if ($activeBroadcast['target'] === 'all_locations') {
        $affectedCount = fetch_one("SELECT COUNT(*) as c FROM screens WHERE company_id = ?", [$bcCompanyId])['c'];
    } else {
        $targets = fetch_all("SELECT * FROM emergency_targets WHERE broadcast_id = ?", [$activeBroadcast['id']]);
        foreach ($targets as $t) {
            if ($t['screen_id']) $affectedCount++;
            if ($t['location_id']) {
                $affectedCount += fetch_one("SELECT COUNT(*) as c FROM screens WHERE location_id = ?", [$t['location_id']])['c'];
            }
        }
    }
}

if ($isSuperAdmin) {
    $pastBroadcasts = fetch_all(
        "SELECT eb.*, m.name as media_name, u.name as creator_name, c.name as company_name
         FROM emergency_broadcasts eb
         JOIN media m ON eb.media_id = m.id
         LEFT JOIN users u ON eb.created_by = u.id
         JOIN companies c ON eb.company_id = c.id
         WHERE eb.is_active = 0
         ORDER BY eb.created_at DESC LIMIT 50"
    );
} else {
    $pastBroadcasts = fetch_all(
        "SELECT eb.*, m.name as media_name, u.name as creator_name
         FROM emergency_broadcasts eb
         JOIN media m ON eb.media_id = m.id
         LEFT JOIN users u ON eb.created_by = u.id
         WHERE eb.company_id = ? AND eb.is_active = 0
         ORDER BY eb.created_at DESC LIMIT 20",
        [$companyId]
    );
}

// Data for the side panel form
if ($isSuperAdmin) {
    $mediaItems = fetch_all("SELECT m.*, c.name as company_name FROM media m JOIN companies c ON m.company_id = c.id WHERE m.is_active = 1 ORDER BY c.name, m.name");
    $locations = fetch_all("SELECT l.*, c.name as company_name, (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count FROM locations l JOIN companies c ON l.company_id = c.id WHERE l.is_active = 1 ORDER BY c.name, l.name");
    $screens = fetch_all("SELECT s.*, l.name as location_name, c.name as company_name FROM screens s JOIN locations l ON s.location_id = l.id JOIN companies c ON s.company_id = c.id WHERE s.status = 'active' ORDER BY c.name, l.name, s.name");
} else {
    $mediaItems = fetch_all("SELECT * FROM media WHERE company_id = ? AND is_active = 1 ORDER BY name", [$companyId]);
    $locations = fetch_all("SELECT l.*, (SELECT COUNT(*) FROM screens WHERE location_id = l.id) as screen_count FROM locations l WHERE l.company_id = ? AND l.is_active = 1 ORDER BY l.name", [$companyId]);
    $screens = fetch_all("SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.company_id = ? AND s.status = 'active' ORDER BY l.name, s.name", [$companyId]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Emergency Broadcast</h1>
    <?php if (!$activeBroadcast && !$isLocationManager): ?>
    <div class="page-header-actions">
        <button onclick="openSidePanel('addBroadcastPanel')" class="btn btn-danger">+ New Broadcast</button>
    </div>
    <?php endif; ?>
</div>

<div class="alert alert-info" style="margin-bottom:20px">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <span>Emergency broadcasts immediately override all content on targeted screens with a full-screen alert. Use this only for urgent safety or health messages. Broadcasts remain active until manually ended.</span>
</div>

<?php if ($isLocationManager): ?>
<div class="alert alert-info" style="margin-bottom:16px">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    Emergency broadcasts can only be activated by Administrators. If you need an urgent message displayed on screens, contact your company administrator immediately.
</div>
<?php endif; ?>

<?php if ($activeBroadcast): ?>
<div class="alert alert-emergency" role="alert" style="margin-bottom:24px">
    <div style="display:flex;align-items:center;justify-content:space-between">
        <div>
            <strong style="font-size:1.05rem">&#9888; Active: <?= sanitize($activeBroadcast['title']) ?></strong>
            <div style="margin-top:4px;opacity:0.9;font-size:0.85rem">
                Target: <?= ucfirst(str_replace('_', ' ', $activeBroadcast['target'])) ?> &middot;
                Started <?= date('M j, g:ia', strtotime($activeBroadcast['started_at'])) ?> &middot;
                <?= $affectedCount ?> screen(s) &middot;
                <?= sanitize($activeBroadcast['creator_name'] ?? 'Unknown') ?>
            </div>
        </div>
        <?php if (!$isLocationManager): ?>
        <button class="btn btn-sm" style="background:rgba(255,255,255,0.2);color:#fff;border:none" onclick="endBroadcast(<?= $activeBroadcast['id'] ?>)">END BROADCAST</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="table-wrapper">
    <div class="table-header"><h3>Broadcast History</h3></div>
    <table>
        <thead>
            <tr><?php if ($isSuperAdmin): ?><th>Company</th><?php endif; ?><th>Title</th><th>Media</th><th>Target</th><th>Created By</th><th>Started</th><th>Ended</th></tr>
        </thead>
        <tbody>
        <?php foreach ($pastBroadcasts as $b): ?>
        <tr>
            <?php if ($isSuperAdmin): ?><td class="text-muted text-sm"><?= sanitize($b['company_name'] ?? '') ?></td><?php endif; ?>
            <td><strong><?= sanitize($b['title']) ?></strong></td>
            <td class="text-muted"><?= sanitize($b['media_name']) ?></td>
            <td><?= ucfirst(str_replace('_', ' ', $b['target'])) ?></td>
            <td class="text-muted"><?= sanitize($b['creator_name'] ?? '') ?></td>
            <td class="text-sm"><?= $b['started_at'] ? date('M j g:ia', strtotime($b['started_at'])) : '' ?></td>
            <td class="text-sm"><?= $b['ended_at'] ? date('M j g:ia', strtotime($b['ended_at'])) : '' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($pastBroadcasts)): ?>
        <tr><td colspan="<?= $isSuperAdmin ? 7 : 6 ?>" class="text-center text-muted">No past broadcasts.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Broadcast Side Panel -->
<div class="side-panel-overlay" id="addBroadcastPanelOverlay"></div>
<div class="side-panel side-panel-lg" id="addBroadcastPanel">
    <div class="side-panel-header">
        <h2>Activate Emergency Broadcast</h2>
        <button class="side-panel-close">&times;</button>
    </div>
    <div class="side-panel-body">
        <div class="alert alert-warning" style="margin-bottom:16px">
            <strong>Warning:</strong> This will immediately override content on targeted screens.
        </div>
        <form id="addBroadcastForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
                <label for="bc_title">Broadcast Title *</label>
                <input type="text" id="bc_title" name="title" class="form-control" required placeholder="e.g. Store Closure Notice">
            </div>

            <div class="form-group">
                <label for="bc_media_id">Media to Display *</label>
                <select id="bc_media_id" name="media_id" class="form-control" required>
                    <option value="">-- Select media --</option>
                    <?php foreach ($mediaItems as $m): ?>
                        <option value="<?= $m['id'] ?>"><?php if ($isSuperAdmin && !empty($m['company_name'])) echo sanitize($m['company_name']) . ' -- '; ?><?= sanitize($m['name']) ?> (<?= $m['file_type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Target</label>
                <div class="form-check mb-1">
                    <input type="radio" name="target" value="all_locations" id="bc_target_all" checked>
                    <label for="bc_target_all">All Screens</label>
                </div>
                <div class="form-check mb-1">
                    <input type="radio" name="target" value="specific_locations" id="bc_target_locations">
                    <label for="bc_target_locations">Specific Locations</label>
                </div>
                <div class="form-check">
                    <input type="radio" name="target" value="specific_screens" id="bc_target_screens">
                    <label for="bc_target_screens">Specific Screens</label>
                </div>
            </div>

            <div class="form-group" id="bcLocationCheckboxes" style="display:none">
                <label>Select Locations</label>
                <?php foreach ($locations as $loc): ?>
                <div class="form-check mb-1">
                    <input type="checkbox" name="location_ids[]" value="<?= $loc['id'] ?>" id="bc_loc_<?= $loc['id'] ?>">
                    <label for="bc_loc_<?= $loc['id'] ?>"><?php if ($isSuperAdmin && !empty($loc['company_name'])) echo sanitize($loc['company_name']) . ' -- '; ?><?= sanitize($loc['name']) ?> (<?= $loc['screen_count'] ?> screens)</label>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="form-group" id="bcScreenCheckboxes" style="display:none">
                <label>Select Screens</label>
                <?php foreach ($screens as $scr): ?>
                <div class="form-check mb-1">
                    <input type="checkbox" name="screen_ids[]" value="<?= $scr['id'] ?>" id="bc_scr_<?= $scr['id'] ?>">
                    <label for="bc_scr_<?= $scr['id'] ?>"><?php if ($isSuperAdmin && !empty($scr['company_name'])) echo sanitize($scr['company_name']) . ' -- '; ?><?= sanitize($scr['name']) ?> -- <?= sanitize($scr['location_name']) ?></label>
                </div>
                <?php endforeach; ?>
            </div>
        </form>
    </div>
    <div class="side-panel-actions">
        <button type="submit" form="addBroadcastForm" class="btn btn-danger" id="btnActivateBroadcast">ACTIVATE BROADCAST</button>
        <button class="btn btn-outline side-panel-close">Cancel</button>
    </div>
</div>

<?php
$baseUrl = BASE_URL;
$extraScripts = <<<SCRIPT
<script>
var BASE_URL = '{$baseUrl}';

// Target radio toggle
$('#addBroadcastPanel input[name="target"]').on('change', function() {
    $('#bcLocationCheckboxes').toggle(this.value === 'specific_locations');
    $('#bcScreenCheckboxes').toggle(this.value === 'specific_screens');
});

// Submit broadcast form via AJAX
$('#addBroadcastForm').on('submit', function(e) {
    e.preventDefault();
    var btn = $('#btnActivateBroadcast');
    var origText = btn.text();

    var formData = $(this).serialize();
    showConfirm({
        title: 'Activate emergency broadcast?',
        message: 'This will immediately override content on targeted screens.',
        confirmText: 'Activate',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            btn.prop('disabled', true).text('Activating...');
            $.ajax({
                url: BASE_URL + 'emergency/api.php?action=create',
                type: 'POST',
                data: formData,
                dataType: 'json',
                success: function(resp) {
                    if (resp.success) {
                        showToast(resp.message || 'Emergency broadcast activated!');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        showToast(resp.message || 'Failed to activate broadcast.', 'error');
                        btn.prop('disabled', false).text(origText);
                    }
                },
                error: function() {
                    showToast('Request failed. Please try again.', 'error');
                    btn.prop('disabled', false).text(origText);
                }
            });
        }
    });
});

// End broadcast via AJAX
function endBroadcast(id) {
    showConfirm({
        title: 'End emergency broadcast?',
        message: 'Screens will return to their normal content.',
        confirmText: 'End Broadcast',
        confirmClass: 'btn-danger',
        onConfirm: function() {
            doEndBroadcast(id);
        }
    });
}

function doEndBroadcast(id) {
    $.ajax({
        url: BASE_URL + 'emergency/api.php?action=deactivate',
        type: 'POST',
        data: {
            id: id,
            csrf_token: $('input[name="csrf_token"]').val() || $('meta[name="csrf-token"]').attr('content')
        },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                showToast(resp.message || 'Broadcast ended.');
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                showToast(resp.message || 'Failed to end broadcast.', 'error');
            }
        },
        error: function() {
            showToast('Request failed. Please try again.', 'error');
        }
    });
}
</script>
SCRIPT;

include __DIR__ . '/../includes/footer.php';
?>
