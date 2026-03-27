<?php
$currentPage = basename($_SERVER['SCRIPT_FILENAME'], '.php');
$currentDir = basename(dirname($_SERVER['SCRIPT_FILENAME']));
$role = $_SESSION['role'] ?? '';
$companyName = $currentUser['company_name'] ?? APP_NAME;
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <a href="<?= BASE_URL ?>dashboard.php" class="sidebar-logo">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" width="20" height="20"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            </div>
            <span><?= APP_NAME ?></span>
        </a>
        <?php if ($role !== 'super_admin' && !empty($companyName)): ?>
            <div class="sidebar-company"><?= sanitize($companyName) ?></div>
        <?php elseif ($role === 'super_admin'): ?>
            <div class="sidebar-company">System Administrator</div>
        <?php endif; ?>
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <a href="<?= BASE_URL ?>dashboard.php" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" <?= $currentPage === 'dashboard' ? 'aria-current="page"' : '' ?> title="Dashboard" aria-label="Dashboard">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            <span>Dashboard</span>
        </a>

        <?php if ($role === 'super_admin'): ?>
        <div class="nav-section" role="heading" aria-level="2">Admin</div>
        <a href="<?= BASE_URL ?>admin/companies.php" class="nav-item <?= $currentDir === 'admin' && in_array($currentPage, ['companies', 'company_view', 'company_add', 'company_edit']) ? 'active' : '' ?>" <?= $currentDir === 'admin' && in_array($currentPage, ['companies', 'company_view', 'company_add', 'company_edit']) ? 'aria-current="page"' : '' ?> title="Companies" aria-label="Companies">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            <span>Companies</span>
        </a>
        <a href="<?= BASE_URL ?>admin/users.php" class="nav-item <?= $currentDir === 'admin' && $currentPage === 'users' ? 'active' : '' ?>" <?= $currentDir === 'admin' && $currentPage === 'users' ? 'aria-current="page"' : '' ?> title="All Users" aria-label="All Users">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>All Users</span>
        </a>
        <?php endif; ?>

        <div class="nav-section" role="heading" aria-level="2">Workspace</div>
        <a href="<?= BASE_URL ?>locations/" class="nav-item <?= $currentDir === 'locations' ? 'active' : '' ?>" <?= $currentDir === 'locations' ? 'aria-current="page"' : '' ?> title="Locations" aria-label="Locations">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span>Locations</span>
        </a>
        <a href="<?= BASE_URL ?>screens/" class="nav-item <?= $currentDir === 'screens' && $currentPage !== 'display' ? 'active' : '' ?>" <?= $currentDir === 'screens' && $currentPage !== 'display' ? 'aria-current="page"' : '' ?> title="Screens" aria-label="Screens">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            <span>Screens</span>
        </a>
        <a href="<?= BASE_URL ?>media/" class="nav-item <?= $currentDir === 'media' ? 'active' : '' ?>" <?= $currentDir === 'media' ? 'aria-current="page"' : '' ?> title="Media Library" aria-label="Media Library">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
            <span>Media Library</span>
        </a>
        <a href="<?= BASE_URL ?>playlists/" class="nav-item <?= $currentDir === 'playlists' ? 'active' : '' ?>" <?= $currentDir === 'playlists' ? 'aria-current="page"' : '' ?> title="Content Rotations" aria-label="Content Rotations">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            <span>Content Rotations</span>
        </a>
        <a href="<?= BASE_URL ?>schedules/" class="nav-item <?= $currentDir === 'schedules' ? 'active' : '' ?>" <?= $currentDir === 'schedules' ? 'aria-current="page"' : '' ?> title="Schedules" aria-label="Schedules">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Schedules</span>
        </a>

        <?php if (in_array($role, ['super_admin', 'company_admin', 'location_manager'])): ?>
        <div class="nav-section" role="heading" aria-level="2">Management</div>
        <a href="<?= BASE_URL ?>emergency/" class="nav-item nav-item-emergency <?= $currentDir === 'emergency' ? 'active' : '' ?>" <?= $currentDir === 'emergency' ? 'aria-current="page"' : '' ?> title="Emergency" aria-label="Emergency">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--monday-red)"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span>Emergency</span>
        </a>
        <a href="<?= BASE_URL ?>users/" class="nav-item <?= $currentDir === 'users' ? 'active' : '' ?>" <?= $currentDir === 'users' ? 'aria-current="page"' : '' ?> title="Team Members" aria-label="Team Members">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4-4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            <span>Team Members</span>
        </a>
        <?php endif; ?>
    </nav>

    <a href="#" class="sidebar-help-link" title="Help & Support" aria-label="Help & Support" onclick="event.preventDefault(); showHelpPanel()">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Help & Support</span>
    </a>

    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
            <div class="user-details">
                <div class="user-name"><?= sanitize($_SESSION['name'] ?? 'User') ?></div>
                <div class="user-role"><?php
$roleLabels = ['super_admin' => 'System Admin', 'company_admin' => 'Administrator', 'location_manager' => 'Location Manager'];
echo $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
?></div>
            </div>
        </div>
        <a href="<?= BASE_URL ?>logout.php" class="logout-btn" title="Sign out" onclick="event.preventDefault(); var href=this.href; showConfirm({title:'Sign out?',message:'You will be logged out of PharmaCMS.',confirmText:'Sign Out',confirmClass:'btn-primary',onConfirm:function(){window.location.href=href}})">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</aside>
