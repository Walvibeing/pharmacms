<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';

$pageTitle = $pageTitle ?? APP_NAME;
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = fetch_one("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?", [$_SESSION['user_id']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css">
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<div class="app-layout">
<?php include __DIR__ . '/nav.php'; ?>
<div class="sidebar-overlay-mobile" id="sidebarOverlay"></div>
<main class="main-content" id="main-content">
    <button class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="container">
<?php
$successFlash = flash('success');
$errorFlash = flash('error');
if ($successFlash): ?>
    <div class="alert alert-success" role="alert"><?= sanitize($successFlash) ?></div>
<?php endif; ?>
<?php if ($errorFlash): ?>
    <div class="alert alert-danger" role="alert"><?= sanitize($errorFlash) ?></div>
<?php endif; ?>
<?php
// Breadcrumb — skip on dashboard
if ($currentPage !== 'dashboard'):
    $breadcrumbSep = '<span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 18 15 12 9 6"/></svg></span>';
    $dirLabels = [
        'locations' => 'Locations',
        'screens' => 'Screens',
        'media' => 'Media Library',
        'playlists' => 'Playlists',
        'schedules' => 'Schedules',
        'users' => 'Team Members',
        'emergency' => 'Emergency',
        'admin' => null,
    ];
    $adminLabels = [
        'companies' => 'Companies',
        'company_view' => 'Companies',
        'company_add' => 'Companies',
        'company_edit' => 'Companies',
        'users' => 'All Users',
    ];
?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?= BASE_URL ?>dashboard.php">Dashboard</a>
        <?php if ($currentDir === 'admin' && isset($adminLabels[$currentPage])): ?>
            <?= $breadcrumbSep ?>
            <span aria-current="page"><?= $adminLabels[$currentPage] ?></span>
        <?php elseif (isset($dirLabels[$currentDir])): ?>
            <?= $breadcrumbSep ?>
            <span aria-current="page"><?= $dirLabels[$currentDir] ?></span>
        <?php endif; ?>
    </nav>
<?php endif; ?>
