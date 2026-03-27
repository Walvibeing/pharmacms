<?php
$pageTitle = 'Screen Details';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$id = (int)($_GET['id'] ?? 0);
if (!can_access_screen($_SESSION['user_id'], $id)) {
    flash('error', 'Access denied.');
    redirect(BASE_URL . 'screens/');
}

$screen = fetch_one("SELECT s.*, l.name as location_name FROM screens s JOIN locations l ON s.location_id = l.id WHERE s.id = ?", [$id]);
if (!$screen) { flash('error', 'Screen not found.'); redirect(BASE_URL . 'screens/'); }

$companyId = $screen['company_id'];

// Current assignment
$assignment = fetch_one("SELECT * FROM screen_assignments WHERE screen_id = ?", [$id]);

// Playlists for dropdown
$playlists = fetch_all("SELECT * FROM playlists WHERE company_id = ? AND is_active = 1 ORDER BY name", [$companyId]);

// Media for dropdown
$mediaItems = fetch_all("SELECT * FROM media WHERE company_id = ? AND is_active = 1 ORDER BY name", [$companyId]);

// Schedules for this screen
$schedules = fetch_all(
    "SELECT sc.*,
        COALESCE(p.name, m.name) as content_name
     FROM schedules sc
     LEFT JOIN playlists p ON sc.playlist_id = p.id
     LEFT JOIN media m ON sc.media_id = m.id
     WHERE sc.screen_id = ? AND sc.is_active = 1
     ORDER BY sc.start_datetime",
    [$id]
);

// Activity log
$activity = fetch_all(
    "SELECT al.*, u.name as user_name FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE al.details LIKE ? ORDER BY al.created_at DESC LIMIT 20",
    ['%screen%' . $screen['name'] . '%']
);

// Handle content assignment POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_mode'])) {
    if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'screens/view.php?id=' . $id); }

    $mode = $_POST['assign_mode'];

    // Delete existing assignment
    delete_row('screen_assignments', 'screen_id = ?', [$id]);

    if ($mode === 'playlist' && !empty($_POST['playlist_id'])) {
        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'playlist',
            'playlist_id' => (int)$_POST['playlist_id']
        ]);
        update('screens', ['current_mode' => 'playlist'], 'id = ?', [$id]);
        log_activity('screen_assigned', "Assigned playlist to screen: {$screen['name']}");
    } elseif ($mode === 'single' && !empty($_POST['media_id'])) {
        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'single',
            'media_id' => (int)$_POST['media_id']
        ]);
        update('screens', ['current_mode' => 'single'], 'id = ?', [$id]);
        log_activity('screen_assigned', "Assigned single media to screen: {$screen['name']}");
    } elseif ($mode === 'scheduled') {
        insert('screen_assignments', [
            'screen_id' => $id,
            'assignment_type' => 'scheduled'
        ]);
        update('screens', ['current_mode' => 'scheduled'], 'id = ?', [$id]);
    }

    flash('success', 'Content assignment updated.');
    redirect(BASE_URL . 'screens/view.php?id=' . $id);
}

// Handle schedule add POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'screens/view.php?id=' . $id); }

    $schName = trim($_POST['schedule_name'] ?? '');
    $schType = $_POST['schedule_content_type'] ?? 'playlist';
    $schPlaylistId = !empty($_POST['schedule_playlist_id']) ? (int)$_POST['schedule_playlist_id'] : null;
    $schMediaId = !empty($_POST['schedule_media_id']) ? (int)$_POST['schedule_media_id'] : null;
    $startDt = $_POST['start_datetime'] ?? '';
    $endDt = $_POST['end_datetime'] ?? '';
    $repeatType = in_array($_POST['repeat_type'] ?? '', ['none', 'daily', 'weekly']) ? $_POST['repeat_type'] : 'none';
    $repeatDays = isset($_POST['repeat_days']) ? implode(',', $_POST['repeat_days']) : null;

    if ($schName && $startDt && $endDt) {
        insert('schedules', [
            'screen_id' => $id,
            'company_id' => $companyId,
            'name' => $schName,
            'playlist_id' => $schType === 'playlist' ? $schPlaylistId : null,
            'media_id' => $schType === 'media' ? $schMediaId : null,
            'start_datetime' => $startDt,
            'end_datetime' => $endDt,
            'repeat_type' => $repeatType,
            'repeat_days' => $repeatDays,
            'is_active' => 1
        ]);
        log_activity('schedule_created', "Added schedule '{$schName}' to screen: {$screen['name']}");
        flash('success', 'Schedule added.');
    } else {
        flash('error', 'Please fill in all required schedule fields.');
    }
    redirect(BASE_URL . 'screens/view.php?id=' . $id . '#schedules');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb">
    <a href="<?= BASE_URL ?>screens/">Screens</a> <span>/</span> <?= sanitize($screen['name']) ?>
</div>

<div class="page-header">
    <div>
        <h1>
            <span class="status-dot <?= screen_status_class($screen['last_ping']) ?>"></span>
            <?= sanitize($screen['name']) ?>
        </h1>
        <div class="text-sm text-muted mt-1">
            <?= sanitize($screen['location_name']) ?> &middot;
            <?= mode_badge($screen['current_mode']) ?> &middot;
            Last seen: <?= $screen['last_ping'] ? time_ago($screen['last_ping']) : 'Never' ?> &middot;
            <code class="text-xs"><?= $screen['screen_key'] ?></code>
        </div>
    </div>
    <div class="btn-group">
        <a href="<?= BASE_URL ?>screens/display.php?key=<?= $screen['screen_key'] ?>" target="_blank" class="btn btn-outline">Open Player</a>
        <a href="<?= BASE_URL ?>screens/" class="btn btn-outline">Back to Screens</a>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn active" data-tab="tab-content">Content Assignment</button>
    <button class="tab-btn" data-tab="tab-schedule">Schedule</button>
    <button class="tab-btn" data-tab="tab-activity">Activity</button>
</div>

<!-- Tab 1: Content Assignment -->
<div class="tab-panel active" id="tab-content">
    <form method="POST">
        <?= csrf_field() ?>
        <div class="mode-cards">
            <div class="mode-card <?= (!$assignment || ($assignment['assignment_type'] ?? '') === 'playlist') ? 'active' : '' ?>" data-mode="playlist">
                <h4>Playlist Mode</h4>
                <p>Cycle through a playlist of media items</p>
            </div>
            <div class="mode-card <?= ($assignment['assignment_type'] ?? '') === 'single' ? 'active' : '' ?>" data-mode="single">
                <h4>Single Item</h4>
                <p>Display a single image or video</p>
            </div>
            <div class="mode-card <?= ($assignment['assignment_type'] ?? '') === 'scheduled' ? 'active' : '' ?>" data-mode="scheduled">
                <h4>Scheduled</h4>
                <p>Content changes based on schedule</p>
            </div>
            <div class="mode-card <?= $screen['current_mode'] === 'emergency' ? 'active' : '' ?>" style="border-color:var(--danger-light)">
                <h4 style="color:var(--danger)">Emergency</h4>
                <p>Overridden by emergency broadcast</p>
            </div>
        </div>

        <input type="hidden" name="assign_mode" id="assignMode" value="<?= $assignment['assignment_type'] ?? 'playlist' ?>">

        <div id="mode-playlist" class="mode-panel" style="<?= (!$assignment || ($assignment['assignment_type'] ?? '') === 'playlist') ? '' : 'display:none' ?>">
            <div class="form-group">
                <label>Select Playlist</label>
                <select name="playlist_id" class="form-control" style="max-width:400px">
                    <option value="">— Choose a playlist —</option>
                    <?php foreach ($playlists as $pl): ?>
                        <option value="<?= $pl['id'] ?>" <?= ($assignment['playlist_id'] ?? 0) == $pl['id'] ? 'selected' : '' ?>><?= sanitize($pl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="mode-single" class="mode-panel" style="<?= ($assignment['assignment_type'] ?? '') === 'single' ? '' : 'display:none' ?>">
            <div class="form-group">
                <label>Select Media Item</label>
                <select name="media_id" class="form-control" style="max-width:400px">
                    <option value="">— Choose a media item —</option>
                    <?php foreach ($mediaItems as $mi): ?>
                        <option value="<?= $mi['id'] ?>" <?= ($assignment['media_id'] ?? 0) == $mi['id'] ? 'selected' : '' ?>><?= sanitize($mi['name']) ?> (<?= $mi['file_type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div id="mode-scheduled" class="mode-panel" style="<?= ($assignment['assignment_type'] ?? '') === 'scheduled' ? '' : 'display:none' ?>">
            <p class="text-muted text-sm">Content will be determined by the schedule. Configure schedules in the Schedule tab.</p>
        </div>

        <button type="submit" class="btn btn-primary mt-2">Save Assignment</button>
    </form>
</div>

<!-- Tab 2: Schedule -->
<div class="tab-panel" id="tab-schedule">
    <div class="table-wrapper mb-2">
        <div class="table-header">
            <h3>Scheduled Content</h3>
        </div>
        <table>
            <thead><tr><th>Name</th><th>Content</th><th>Start</th><th>End</th><th>Repeat</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($schedules as $sch): ?>
            <tr>
                <td><strong><?= sanitize($sch['name'] ?? 'Untitled') ?></strong></td>
                <td class="text-muted"><?= sanitize($sch['content_name'] ?? 'N/A') ?></td>
                <td class="text-sm"><?= date('M j, Y g:ia', strtotime($sch['start_datetime'])) ?></td>
                <td class="text-sm"><?= date('M j, Y g:ia', strtotime($sch['end_datetime'])) ?></td>
                <td><?= ucfirst($sch['repeat_type']) ?></td>
                <td>
                    <form method="POST" action="<?= BASE_URL ?>schedules/delete.php" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $sch['id'] ?>">
                        <input type="hidden" name="redirect" value="<?= BASE_URL ?>screens/view.php?id=<?= $id ?>#schedules">
                        <button type="submit" class="btn btn-sm btn-danger-outline btn-delete-confirm">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($schedules)): ?>
            <tr><td colspan="6" class="text-center text-muted">No schedules configured.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-header"><h3>Add Schedule</h3></div>
        <div class="card-body">
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="add_schedule" value="1">
                <div class="form-row">
                    <div class="form-group">
                        <label>Schedule Name *</label>
                        <input type="text" name="schedule_name" class="form-control" required placeholder="e.g. Morning Promotions">
                    </div>
                    <div class="form-group">
                        <label>Content Type</label>
                        <select name="schedule_content_type" class="form-control" id="schedContentType">
                            <option value="playlist">Playlist</option>
                            <option value="media">Single Media</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="schedPlaylistGroup">
                        <label>Playlist</label>
                        <select name="schedule_playlist_id" class="form-control">
                            <option value="">— Select —</option>
                            <?php foreach ($playlists as $pl): ?>
                                <option value="<?= $pl['id'] ?>"><?= sanitize($pl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="schedMediaGroup" style="display:none">
                        <label>Media Item</label>
                        <select name="schedule_media_id" class="form-control">
                            <option value="">— Select —</option>
                            <?php foreach ($mediaItems as $mi): ?>
                                <option value="<?= $mi['id'] ?>"><?= sanitize($mi['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date/Time *</label>
                        <input type="datetime-local" name="start_datetime" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Date/Time *</label>
                        <input type="datetime-local" name="end_datetime" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Repeat</label>
                        <select name="repeat_type" class="form-control" id="repeatType">
                            <option value="none">None</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div class="form-group" id="repeatDaysGroup" style="display:none">
                        <label>Days of Week</label>
                        <div class="d-flex gap-1" style="flex-wrap:wrap">
                            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                            <div class="form-check">
                                <input type="checkbox" name="repeat_days[]" value="<?= $d ?>" id="day_<?= $d ?>">
                                <label for="day_<?= $d ?>"><?= $d ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-1">Add Schedule</button>
            </form>
        </div>
    </div>
</div>

<!-- Tab 3: Activity -->
<div class="tab-panel" id="tab-activity">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $act): ?>
            <tr>
                <td><?= sanitize($act['user_name'] ?? 'System') ?></td>
                <td><?= sanitize($act['action']) ?></td>
                <td class="text-muted text-sm"><?= sanitize($act['details'] ?? '') ?></td>
                <td class="text-muted text-sm"><?= time_ago($act['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($activity)): ?>
            <tr><td colspan="4" class="text-center text-muted">No activity recorded.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
$(document).ready(function() {
    // Mode card selection
    $('.mode-card[data-mode]').on('click', function() {
        var mode = $(this).data('mode');
        if (!mode) return;
        $('.mode-card').removeClass('active');
        $(this).addClass('active');
        $('#assignMode').val(mode);
        $('.mode-panel').hide();
        $('#mode-' + mode).show();
    });

    // Schedule content type toggle
    $('#schedContentType').on('change', function() {
        if (this.value === 'playlist') {
            $('#schedPlaylistGroup').show();
            $('#schedMediaGroup').hide();
        } else {
            $('#schedPlaylistGroup').hide();
            $('#schedMediaGroup').show();
        }
    });

    // Repeat type toggle
    $('#repeatType').on('change', function() {
        $('#repeatDaysGroup').toggle(this.value === 'weekly');
    });

    // Auto-activate tab from hash
    if (window.location.hash === '#schedules') {
        $('[data-tab="tab-schedule"]').click();
    }
});
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
