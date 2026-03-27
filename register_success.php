<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Received — <?= APP_NAME ?></title>
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-card" style="text-align:center">
        <div class="auth-logo">
            <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" width="24" height="24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
            <span><?= APP_NAME ?></span>
        </div>
        <div style="margin:2rem 0">
            <svg viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2" width="64" height="64" style="margin:0 auto">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="9 12 11.5 14.5 16 9.5"/>
            </svg>
        </div>
        <h2 style="color:var(--gray-900)">Registration Received!</h2>
        <p class="text-muted" style="margin:1rem 0;line-height:1.6">
            We'll review your application and notify you by email once approved.<br>
            This usually takes 1 business day.
        </p>
        <p class="text-sm text-muted" style="margin-top:8px">You'll receive an email at your registered address when approved. If you don't hear from us within 2 business days, check your spam folder or contact support.</p>
        <a href="<?= BASE_URL ?>index.php" class="btn btn-outline" style="margin-top:1rem">Back to Login</a>
    </div>
</div>
</body>
</html>
