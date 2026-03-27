<?php
$pageTitle = 'Team Members';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

$isSuperAdmin = is_super_admin();
$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$isLocationManager = ($role === 'location_manager');

// Fetch users list
if ($isSuperAdmin) {
    $users = fetch_all(
        "SELECT u.*, c.name as company_name,
            GROUP_CONCAT(l.name SEPARATOR ', ') as assigned_locations
         FROM users u
         LEFT JOIN companies c ON u.company_id = c.id
         LEFT JOIN location_users lu ON u.id = lu.user_id
         LEFT JOIN locations l ON lu.location_id = l.id
         GROUP BY u.id
         ORDER BY c.name, u.name"
    );
} else {
    $users = fetch_all(
        "SELECT u.*,
            GROUP_CONCAT(l.name SEPARATOR ', ') as assigned_locations
         FROM users u
         LEFT JOIN location_users lu ON u.id = lu.user_id
         LEFT JOIN locations l ON lu.location_id = l.id
         WHERE u.company_id = ?
         GROUP BY u.id
         ORDER BY u.name",
        [$companyId]
    );
}

// Super admin: fetch companies for the invite form
if ($isSuperAdmin) {
    $companies = fetch_all("SELECT * FROM companies WHERE status = 'active' ORDER BY name");
}

// Fetch locations for invite form (company admin)
if (!$isSuperAdmin) {
    $locations = fetch_all("SELECT id, name FROM locations WHERE company_id = ? AND is_active = 1 ORDER BY name", [$companyId]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Team Members</h1>
    <div class="page-header-actions">
        <div class="search-wrapper">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" class="search-input" id="userSearchInput" placeholder="Search by name or email...">
        </div>
        <select id="userRoleFilter" class="form-control">
            <option value="">All Roles</option>
            <option value="super_admin">System Admin</option>
            <option value="company_admin">Administrator</option>
            <option value="location_manager">Location Manager</option>
        </select>
        <select id="userStatusFilter" class="form-control">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <?php if (!$isLocationManager): ?>
        <button class="btn btn-primary" onclick="openSidePanel('inviteUserPanel')">+ Invite User</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($isLocationManager): ?>
<div class="alert alert-info" style="margin-bottom:16px">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    You can view the team directory below. To add or manage team members, contact your company Administrator.
</div>
<?php endif; ?>

<div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <?php if ($isSuperAdmin): ?><th>Company</th><?php endif; ?>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Assigned Locations</th>
                <!-- Last Login: requires `last_login` DATETIME column on users table. Migration needed: ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL; -->
                <th>Last Login</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody id="usersTableBody">
        <?php foreach ($users as $u): ?>
            <tr data-name="<?= sanitize(strtolower($u['name'])) ?>" data-email="<?= sanitize(strtolower($u['email'])) ?>" data-role="<?= sanitize($u['role']) ?>" data-status="<?= $u['is_active'] ? 'active' : 'inactive' ?>" data-last-login="<?= sanitize($u['last_login'] ?? '') ?>" <?php if (!$isLocationManager): ?>onclick="openEditUser(<?= $u['id'] ?>)" role="button" tabindex="0" style="cursor:pointer"<?php endif; ?> class="row-border-<?= $u['is_active'] ? 'green' : 'red' ?>">
                <?php if ($isSuperAdmin): ?><td class="text-muted text-sm"><?= sanitize($u['company_name'] ?? 'System') ?></td><?php endif; ?>
                <td><strong><?= sanitize($u['name']) ?></strong></td>
                <td class="text-muted"><?= sanitize($u['email']) ?></td>
                <td><?php
$userRoleLabels = ['super_admin' => 'System Admin', 'company_admin' => 'Administrator', 'location_manager' => 'Location Manager'];
echo $userRoleLabels[$u['role']] ?? ucfirst(str_replace('_', ' ', $u['role']));
?></td>
                <td class="text-muted text-sm"><?= sanitize($u['assigned_locations'] ?? '—') ?></td>
                <td class="text-muted text-sm"><?= !empty($u['last_login'] ?? null) ? time_ago($u['last_login']) : 'Never' ?></td>
                <td><?= status_badge($u['is_active'] ? 'active' : 'inactive') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <tr id="noUsersRow"><td colspan="<?= $isSuperAdmin ? 7 : 6 ?>">
                <div class="empty-state" style="padding:48px 32px">
                    <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--text-placeholder)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <h4>No team members yet</h4>
                    <p>Team members can manage screens and content at their assigned locations.<?php if (!$isLocationManager): ?> Invite a user to get started.<?php endif; ?></p>
                    <?php if (!$isLocationManager): ?>
                    <button class="btn btn-primary" onclick="openSidePanel('inviteUserPanel')">+ Invite User</button>
                    <?php endif; ?>
                </div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ==================== SIDE PANEL -- Invite User ==================== -->
<div class="side-panel-overlay" id="inviteUserPanelOverlay"></div>
<div class="side-panel" id="inviteUserPanel">
    <div class="side-panel-header">
        <h2 id="inviteUserTitle">Invite User</h2>
        <button class="side-panel-close" onclick="closeSidePanel('inviteUserPanel')">&times;</button>
    </div>

    <div class="side-panel-body" id="inviteUserBody">
        <form id="inviteUserForm">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <?php if ($isSuperAdmin): ?>
            <div class="form-group">
                <label for="invite_company_id">Company *</label>
                <select id="invite_company_id" name="company_id" class="form-control" required>
                    <option value="">Select a company</option>
                    <?php foreach ($companies as $co): ?>
                        <option value="<?= $co['id'] ?>"><?= sanitize($co['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="invite_name">Name *</label>
                <input type="text" id="invite_name" name="name" class="form-control" required placeholder="Full name" autofocus>
            </div>

            <div class="form-group">
                <label for="invite_email">Email *</label>
                <input type="email" id="invite_email" name="email" class="form-control" required placeholder="user@example.com">
            </div>

            <div class="form-group">
                <label for="invite_role">Role</label>
                <select id="invite_role" name="role" class="form-control">
                    <option value="company_admin">Administrator — Full access to all locations and settings</option>
                    <option value="location_manager" selected>Location Manager — Access to assigned locations only</option>
                </select>
            </div>

            <div class="form-group" id="inviteLocationSection">
                <label>Assign to Locations</label>
                <div id="inviteLocationsList">
                    <?php if (!$isSuperAdmin): ?>
                        <?php if (!empty($locations)): ?>
                            <?php foreach ($locations as $loc): ?>
                            <div class="form-check mb-1">
                                <input type="checkbox" name="locations[]" value="<?= $loc['id'] ?>" id="invite_loc_<?= $loc['id'] ?>">
                                <label for="invite_loc_<?= $loc['id'] ?>"><?= sanitize($loc['name']) ?></label>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-muted text-sm">No locations available.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted text-sm">Select a company first.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="inviteUserMessage"></div>

            <div class="side-panel-actions" style="padding:0;border:none;margin-top:8px">
                <button type="submit" class="btn btn-primary" id="inviteUserSubmitBtn" style="flex:1">Send Invitation</button>
                <button type="button" class="btn btn-outline" onclick="closeSidePanel('inviteUserPanel')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ==================== SIDE PANEL -- Edit User ==================== -->
<div class="side-panel-overlay" id="editUserPanelOverlay"></div>
<div class="side-panel" id="editUserPanel">
    <div class="side-panel-header">
        <h2 id="editUserTitle">Edit User</h2>
        <button class="side-panel-close" onclick="closeSidePanel('editUserPanel')">&times;</button>
    </div>
    <div class="side-panel-body" id="editUserBody">
        <div class="text-center text-muted" style="padding:2rem">Loading...</div>
    </div>
</div>

<?php
$baseUrl = BASE_URL;
$isSuperAdminJs = $isSuperAdmin ? 'true' : 'false';
$csrfToken = csrf_token();
$extraScripts = <<<JS
<script>
$(document).ready(function() {
    var isSuperAdmin = {$isSuperAdminJs};
    var baseUrl = '{$baseUrl}';
    var csrfToken = '{$csrfToken}';

    // escapeHtml() is now provided globally by app.js

    // ── Table filtering (uses global filterTable utility) ──
    var colspan = isSuperAdmin ? 7 : 6;

    function applyFilter() {
        window.filterTable({
            search: $('#userSearchInput').val(),
            searchFields: ['name', 'email'],
            filters: {
                role: $('#userRoleFilter').val(),
                status: $('#userStatusFilter').val()
            },
            rowSelector: '#usersTableBody tr',
            tableBody: '#usersTableBody',
            emptyMessage: 'No matching team members.',
            colspan: colspan
        });
    }

    $('#userSearchInput').on('input', window.debounce(applyFilter, 200));
    $('#userRoleFilter, #userStatusFilter').on('change', applyFilter);

    // ── Invite User Panel ──
    // Toggle location section based on role
    $('#invite_role').on('change', function() {
        $('#inviteLocationSection').toggle(this.value === 'location_manager');
    });

    // Super admin: load locations when company changes
    if (isSuperAdmin) {
        $('#invite_company_id').on('change', function() {
            var companyId = $(this).val();
            var section = $('#inviteLocationsList');
            section.html('');

            if (!companyId) {
                section.html('<p class="text-muted text-sm">Select a company first.</p>');
                return;
            }

            section.html('<p class="text-muted text-sm">Loading locations...</p>');

            $.getJSON(baseUrl + 'users/api.php?action=get_locations&company_id=' + companyId, function(locations) {
                if (locations.length === 0) {
                    section.html('<p class="text-muted text-sm">No locations for this company.</p>');
                    return;
                }

                var html = '';
                locations.forEach(function(loc) {
                    html += '<div class="form-check mb-1">' +
                        '<input type="checkbox" name="locations[]" value="' + loc.id + '" id="invite_loc_' + loc.id + '">' +
                        '<label for="invite_loc_' + loc.id + '">' + escapeHtml(loc.name) + '</label>' +
                        '</div>';
                });
                section.html(html);
            });
        });
    }

    // Invite form submit (AJAX)
    $('#inviteUserForm').on('submit', function(e) {
        e.preventDefault();
        var btn = $('#inviteUserSubmitBtn');
        var msg = $('#inviteUserMessage');

        btn.prop('disabled', true).text('Sending...');
        msg.html('');

        $.ajax({
            url: baseUrl + 'users/api.php?action=invite',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    // Show success state
                    $('#inviteUserTitle').text('User Invited');
                    $('#inviteUserBody').html(
                        '<div style="text-align:center;padding:16px 0">' +
                            '<div style="width:56px;height:56px;border-radius:50%;background:var(--monday-green-light);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px">' +
                                '<svg viewBox="0 0 24 24" fill="none" stroke="var(--monday-green)" stroke-width="2.5" stroke-linecap="round" width="28" height="28"><polyline points="20 6 9 17 4 12"/></svg>' +
                            '</div>' +
                            '<h3 style="margin-bottom:4px;font-size:1.1rem">Invitation Sent!</h3>' +
                            '<p class="text-muted text-sm mb-2">' + escapeHtml(data.user_name) + ' has been invited. A welcome email has been sent.</p>' +
                            '<div class="btn-group mt-2" style="justify-content:center">' +
                                '<button class="btn btn-primary" onclick="closeSidePanel(\'inviteUserPanel\'); location.reload();">Done</button>' +
                                '<button class="btn btn-outline" onclick="resetInvitePanel()">Invite Another</button>' +
                            '</div>' +
                        '</div>'
                    );
                    showToast('User invited successfully!');
                } else {
                    msg.html('<div class="alert alert-danger" style="margin-top:8px">' + escapeHtml(data.message) + '</div>');
                    btn.prop('disabled', false).text('Send Invitation');
                }
            },
            error: function() {
                msg.html('<div class="alert alert-danger" style="margin-top:8px">An error occurred. Please try again.</div>');
                btn.prop('disabled', false).text('Send Invitation');
            }
        });

        return false;
    });

    // Reset invite panel to fresh form
    window.resetInvitePanel = function() {
        location.reload();
    };

    // ── Edit User Panel ──
    window.openEditUser = function(userId) {
        closeAllSidePanels();
        $('#editUserTitle').text('Edit User');
        $('#editUserBody').html('<div class="text-center text-muted" style="padding:2rem">Loading...</div>');
        openSidePanel('editUserPanel');

        $.getJSON(baseUrl + 'users/api.php?action=get&id=' + userId, function(data) {
            if (!data.success) {
                $('#editUserBody').html('<div class="alert alert-danger">' + escapeHtml(data.message) + '</div>');
                return;
            }

            var user = data.user;
            $('#editUserTitle').text('Edit: ' + user.name);

            var html = '<form id="editUserForm" onsubmit="return saveUser(event, ' + user.id + ')">';
            html += '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
            html += '<input type="hidden" name="id" value="' + user.id + '">';

            // Email (disabled, read-only)
            html += '<div class="form-group">';
            html += '<label>Email</label>';
            html += '<input type="email" class="form-control" value="' + escapeHtml(user.email) + '" disabled>';
            html += '</div>';

            // Name
            html += '<div class="form-group">';
            html += '<label>Name *</label>';
            html += '<input type="text" name="name" class="form-control" value="' + escapeHtml(user.name) + '" required>';
            html += '</div>';

            // Role
            html += '<div class="form-group">';
            html += '<label>Role</label>';
            html += '<select name="role" class="form-control" id="editUserRole" onchange="$(\'#editLocationSection\').toggle(this.value===\'location_manager\')">';
            html += '<option value="company_admin"' + (user.role === 'company_admin' ? ' selected' : '') + '>Company Admin</option>';
            html += '<option value="location_manager"' + (user.role === 'location_manager' ? ' selected' : '') + '>Location Manager</option>';
            html += '</select>';
            html += '</div>';

            // Location assignments
            var showLocations = user.role === 'location_manager';
            html += '<div class="form-group" id="editLocationSection"' + (!showLocations ? ' style="display:none"' : '') + '>';
            html += '<label>Assign to Locations</label>';
            if (user.available_locations && user.available_locations.length > 0) {
                user.available_locations.forEach(function(loc) {
                    var checked = user.assigned_location_ids.indexOf(loc.id) !== -1 ? ' checked' : '';
                    html += '<div class="form-check mb-1">';
                    html += '<input type="checkbox" name="locations[]" value="' + loc.id + '" id="edit_loc_' + loc.id + '"' + checked + '>';
                    html += '<label for="edit_loc_' + loc.id + '">' + escapeHtml(loc.name) + '</label>';
                    html += '</div>';
                });
            } else {
                html += '<p class="text-muted text-sm">No locations available.</p>';
            }
            html += '</div>';

            // Active checkbox
            html += '<div class="form-group">';
            html += '<div class="form-check">';
            html += '<input type="hidden" name="is_active" value="0">';
            html += '<input type="checkbox" name="is_active" id="edit_is_active" value="1"' + (user.is_active == 1 ? ' checked' : '') + '>';
            html += '<label for="edit_is_active">Active</label>';
            html += '</div>';
            html += '</div>';

            html += '<div id="editUserMessage"></div>';

            html += '<div class="side-panel-actions" style="padding:0;border:none;margin-top:12px">';
            html += '<button type="submit" class="btn btn-primary" id="editUserSaveBtn" style="flex:1">Save Changes</button>';
            html += '<button type="button" class="btn btn-outline" onclick="closeSidePanel(\'editUserPanel\')">Cancel</button>';
            html += '</div>';

            html += '</form>';

            $('#editUserBody').html(html);
        }).fail(function() {
            $('#editUserBody').html('<div class="alert alert-danger">Failed to load user details.</div>');
        });
    };

    // ── Save User (AJAX) ──
    window.saveUser = function(e, userId) {
        e.preventDefault();
        var frm = $('#editUserForm');
        var btn = $('#editUserSaveBtn');
        var msg = $('#editUserMessage');

        btn.prop('disabled', true).text('Saving...');
        msg.html('');

        $.ajax({
            url: baseUrl + 'users/api.php?action=update',
            method: 'POST',
            data: frm.serialize(),
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    msg.html('<div class="alert alert-success" style="margin-top:8px">' + escapeHtml(data.message) + '</div>');
                    btn.text('Saved!');
                    showToast('User updated successfully!');
                    setTimeout(function() {
                        closeSidePanel('editUserPanel');
                        location.reload();
                    }, 800);
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

    // ── Overlay clicks ──
    $('#inviteUserPanelOverlay').on('click', function() { closeSidePanel('inviteUserPanel'); });
    $('#editUserPanelOverlay').on('click', function() { closeSidePanel('editUserPanel'); });
});
</script>
JS;

include __DIR__ . '/../includes/footer.php';
?>
