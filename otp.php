<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['otp_email'])) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}
$email = $_SESSION['otp_email'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code — <?= APP_NAME ?></title>
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
        <h2>Enter Your Code</h2>
        <div id="devBanner" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:0.75rem;margin-bottom:1rem;text-align:center">
            <div style="font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:0.05em">Dev Mode — Your Code</div>
            <div id="devCode" style="font-size:1.75rem;font-weight:800;letter-spacing:6px;color:#92400e;margin-top:0.25rem"></div>
        </div>
        <p class="text-center text-sm text-muted mb-2">
            We sent a 6-digit code to<br><strong><?= htmlspecialchars($email) ?></strong>
        </p>
        <p class="text-sm text-muted" style="margin-top:6px;text-align:center">Check your spam folder if you don't see it within a minute.</p>

        <form id="otpForm">
            <input type="text" id="otpCode" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" class="form-control otp-single-input" placeholder="000000" aria-label="6-digit verification code" autofocus style="text-align:center;font-size:1.75rem;font-weight:700;letter-spacing:8px;padding:14px 16px">
            <button type="submit" class="btn btn-primary btn-lg" style="width:100%;margin-top:1rem" id="verifyBtn">Verify Code</button>
            <div id="otpMessage" class="mt-2" style="display:none" role="alert"></div>
        </form>

        <p class="text-center mt-2 text-sm text-muted">
            Didn't receive the code?
            <a href="#" id="resendLink">Resend</a>
            <span id="resendTimer" style="display:none"> (<span id="countdown">60</span>s)</span>
        </p>
        <p class="text-center text-sm text-muted" style="margin-top:4px">Code expires in 10 minutes.</p>
        <p class="text-center text-sm mt-1">
            <a href="<?= BASE_URL ?>index.php">Use a different email</a>
        </p>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
var BASE_URL = '<?= BASE_URL ?>';

// Strip non-numeric input
$('#otpCode').on('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '');
});

// Submit OTP
$('#otpForm').on('submit', function(e) {
    e.preventDefault();
    var code = $('#otpCode').val();
    if (code.length !== 6) {
        $('#otpMessage').html('<div class="alert alert-danger">Please enter all 6 digits.</div>').show();
        $('#otpCode').focus();
        return;
    }

    var btn = $('#verifyBtn');
    btn.prop('disabled', true).text('Verifying...');
    $('#otpMessage').hide();

    $.ajax({
        url: BASE_URL + 'auth.php?action=verify_otp',
        type: 'POST',
        data: { code: code },
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                // Check for return URL from login flow
                var returnUrl = sessionStorage.getItem('return_url');
                if (returnUrl) {
                    sessionStorage.removeItem('return_url');
                    window.location.href = returnUrl;
                } else {
                    window.location.href = resp.redirect;
                }
            } else {
                $('#otpMessage').html('<div class="alert alert-danger">' + (typeof escapeHtml === 'function' ? escapeHtml(resp.message) : resp.message) + '</div>').show();
                btn.prop('disabled', false).text('Verify Code');
                $('#otpCode').focus();
            }
        },
        error: function() {
            $('#otpMessage').html('<div class="alert alert-danger">Something went wrong.</div>').show();
            btn.prop('disabled', false).text('Verify Code');
            $('#otpCode').focus();
        }
    });
});

// Resend with countdown
var resendCooldown = false;
$('#resendLink').on('click', function(e) {
    e.preventDefault();
    if (resendCooldown) return;

    $.ajax({
        url: BASE_URL + 'auth.php?action=resend_otp',
        type: 'POST',
        dataType: 'json',
        success: function(resp) {
            if (resp.success) {
                startCooldown();
                $('#otpMessage').html('<div class="alert alert-success">' + (typeof escapeHtml === 'function' ? escapeHtml(resp.message) : resp.message) + '</div>').show();
            }
        }
    });
});

function startCooldown() {
    resendCooldown = true;
    var seconds = 60;
    $('#resendLink').css('pointer-events', 'none').css('opacity', '0.5');
    $('#resendTimer').show();
    var timer = setInterval(function() {
        seconds--;
        $('#countdown').text(seconds);
        if (seconds <= 0) {
            clearInterval(timer);
            resendCooldown = false;
            $('#resendLink').css('pointer-events', '').css('opacity', '');
            $('#resendTimer').hide();
        }
    }, 1000);
}

startCooldown();

// Dev mode: show OTP and auto-fill
var devOtp = sessionStorage.getItem('dev_otp');
if (devOtp) {
    $('#devBanner').show();
    $('#devCode').text(devOtp);
    $('#otpCode').val(devOtp);
    sessionStorage.removeItem('dev_otp');
}
</script>
</body>
</html>
