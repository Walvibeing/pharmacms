<?php
session_start();
require_once __DIR__ . '/config.php';
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/favicon.svg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/style.css">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" width="24" height="24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></div>
            <span><?= APP_NAME ?></span>
        </div>
        <p class="auth-tagline">Digital Signage for Pharmacy Networks</p>

        <form id="loginForm">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" class="form-control" placeholder="you@company.com" autocomplete="email" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%" id="loginBtn">Send Login Code</button>
            <p class="text-sm text-muted" style="margin-top:8px;text-align:center">We'll email you a one-time login code — no password needed.</p>
            <div id="loginMessage" class="mt-2" style="display:none"></div>
        </form>

        <p class="text-center mt-2 text-sm text-muted">
            New company? <a href="<?= BASE_URL ?>register.php">Register here</a>
        </p>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
var BASE_URL = '<?= BASE_URL ?>';
$('#loginForm').on('submit', function(e) {
    e.preventDefault();
    var btn = $('#loginBtn');
    btn.prop('disabled', true).text('Sending...');
    $('#loginMessage').hide();

    $.ajax({
        url: BASE_URL + 'auth.php?action=request_otp',
        type: 'POST',
        data: { email: $('#email').val() },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                if (resp.dev_login) {
                    var returnUrl = new URLSearchParams(window.location.search).get('return') || '';
                    window.location.href = returnUrl || resp.redirect;
                    return;
                }
                $('#loginMessage').html('<div class="alert alert-success">Check your inbox (and spam folder) for your login code.</div>').show();
                // Preserve return URL through login flow
                var returnUrl = new URLSearchParams(window.location.search).get('return') || '';
                if (returnUrl) sessionStorage.setItem('return_url', returnUrl);
                setTimeout(function() { window.location.href = BASE_URL + 'otp.php'; }, 1500);
            } else {
                $('#loginMessage').html('<div class="alert alert-danger">' + (typeof escapeHtml === 'function' ? escapeHtml(resp.message) : resp.message) + '</div>').show();
                btn.prop('disabled', false).text('Send Login Code');
            }
        },
        error: function() {
            $('#loginMessage').html('<div class="alert alert-danger">Something went wrong. Please try again.</div>').show();
            btn.prop('disabled', false).text('Send Login Code');
        }
    });
});
</script>
</body>
</html>
