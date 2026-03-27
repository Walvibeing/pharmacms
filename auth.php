<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// PHPMailer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function require_login() {
    if (empty($_SESSION['user_id'])) {
        $return = $_SERVER['REQUEST_URI'] ?? '';
        $url = BASE_URL . 'index.php';
        if ($return) $url .= '?return=' . urlencode($return);
        header('Location: ' . $url);
        exit;
    }
    // Session timeout: 1 hour idle
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
        session_unset();
        session_destroy();
        session_start();
        $url = BASE_URL . 'index.php';
        header('Location: ' . $url);
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function require_role($roles) {
    require_login();
    if (!is_array($roles)) $roles = [$roles];
    if (!in_array($_SESSION['role'], $roles)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><title>Access Denied</title>';
        echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">';
        echo '<style>body{font-family:"Inter",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5;margin:0}';
        echo '.card{background:#fff;padding:3rem;border-radius:12px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}';
        echo 'h1{color:#e74c3c;margin-bottom:.5rem}a{color:#4f46e5;text-decoration:none}</style></head>';
        echo '<body><div class="card"><h1>403 — Access Denied</h1><p>You don\'t have permission to access this page.</p>';
        echo '<p><a href="' . BASE_URL . 'dashboard.php">Back to Dashboard</a></p></div></body></html>';
        exit;
    }
}

function current_user() {
    if (empty($_SESSION['user_id'])) return null;
    return fetch_one("SELECT u.*, c.name as company_name, c.status as company_status FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?", [$_SESSION['user_id']]);
}

function get_user_locations($user_id) {
    $user = fetch_one("SELECT role, company_id FROM users WHERE id = ?", [$user_id]);
    if (!$user) return [];

    if ($user['role'] === 'super_admin') {
        return fetch_all("SELECT * FROM locations ORDER BY name");
    }

    if ($user['role'] === 'company_admin') {
        return fetch_all("SELECT * FROM locations WHERE company_id = ? ORDER BY name", [$user['company_id']]);
    }

    // location_manager — only assigned locations
    return fetch_all(
        "SELECT l.* FROM locations l
         INNER JOIN location_users lu ON l.id = lu.location_id
         WHERE lu.user_id = ? ORDER BY l.name",
        [$user_id]
    );
}

function can_access_location($user_id, $location_id) {
    $user = fetch_one("SELECT role, company_id FROM users WHERE id = ?", [$user_id]);
    if (!$user) return false;
    if ($user['role'] === 'super_admin') return true;

    $location = fetch_one("SELECT * FROM locations WHERE id = ?", [$location_id]);
    if (!$location || $location['company_id'] != $user['company_id']) return false;

    if ($user['role'] === 'company_admin') return true;

    return (bool)fetch_one("SELECT 1 FROM location_users WHERE user_id = ? AND location_id = ?", [$user_id, $location_id]);
}

function can_access_screen($user_id, $screen_id) {
    $screen = fetch_one("SELECT * FROM screens WHERE id = ?", [$screen_id]);
    if (!$screen) return false;
    return can_access_location($user_id, $screen['location_id']);
}

function log_activity($action, $details = null) {
    $userId = $_SESSION['user_id'] ?? null;
    $companyId = $_SESSION['company_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    insert('activity_log', [
        'user_id' => $userId,
        'company_id' => $companyId,
        'action' => $action,
        'details' => $details,
        'ip_address' => $ip
    ]);
}

function send_email($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email send failed: " . $e->getMessage());
        return false;
    }
}

function generate_otp() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function company_id() {
    return $_SESSION['company_id'] ?? null;
}

function is_super_admin() {
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

// Handle AJAX auth actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    if ($_GET['action'] === 'request_otp') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        // Rate limit: max 5 OTP requests per email per 15 minutes
        $recentRequests = fetch_one(
            "SELECT COUNT(*) as cnt FROM otp_tokens WHERE user_id IN (SELECT id FROM users WHERE email = ?) AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)",
            [$email]
        );
        if (($recentRequests['cnt'] ?? 0) >= 5) {
            echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please wait 15 minutes before trying again.']);
            exit;
        }

        $user = fetch_one("SELECT u.*, c.status as company_status FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.email = ?", [$email]);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'If an account exists with this email, a login code will be sent.']);
            exit;
        }

        if (!$user['is_active']) {
            echo json_encode(['success' => false, 'message' => 'If an account exists with this email, a login code will be sent.']);
            exit;
        }

        if ($user['role'] !== 'super_admin' && $user['company_status'] !== 'active') {
            echo json_encode(['success' => false, 'message' => 'If an account exists with this email, a login code will be sent.']);
            exit;
        }

        // Invalidate old tokens
        query("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND used = 0", [$user['id']]);

        $otp = generate_otp();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        insert('otp_tokens', [
            'user_id' => $user['id'],
            'token' => $otp,
            'expires_at' => $expiresAt
        ]);

        $emailBody = "
        <div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
            <h2 style='color:#1f1f2e;margin-bottom:1rem'>" . APP_NAME . " Login Code</h2>
            <p style='color:#555;font-size:15px'>Your one-time login code is:</p>
            <div style='background:#f0f2f5;padding:1.5rem;text-align:center;border-radius:8px;margin:1.5rem 0'>
                <span style='font-size:32px;font-weight:700;letter-spacing:8px;color:#1f1f2e'>{$otp}</span>
            </div>
            <p style='color:#888;font-size:13px'>This code expires in " . OTP_EXPIRY_MINUTES . " minutes. If you didn't request this, you can ignore this email.</p>
        </div>";

        if (!defined('DEV_MODE') || !DEV_MODE) {
            send_email($email, APP_NAME . ' — Your Login Code', $emailBody);
        }

        // Dev mode: skip OTP and log in directly
        if (defined('DEV_MODE') && DEV_MODE) {
            query("UPDATE otp_tokens SET used = 1 WHERE id = (SELECT id FROM (SELECT id FROM otp_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1) tmp)", [$user['id']]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['company_id'] = $user['company_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['email'] = $user['email'];
            log_activity('login', 'Dev mode auto-login');
            echo json_encode(['success' => true, 'dev_login' => true, 'redirect' => BASE_URL . 'dashboard.php']);
            exit;
        }

        $_SESSION['otp_user_id'] = $user['id'];
        $_SESSION['otp_email'] = $email;

        $response = ['success' => true, 'message' => 'Login code sent to your email.'];
        echo json_encode($response);
        exit;
    }

    if ($_GET['action'] === 'verify_otp') {
        $code = trim($_POST['code'] ?? '');
        $userId = $_SESSION['otp_user_id'] ?? null;

        if (!$userId || strlen($code) !== 6) {
            echo json_encode(['success' => false, 'message' => 'Invalid request. Please try again.']);
            exit;
        }

        // Rate limit: max 5 verification attempts per session
        if (!isset($_SESSION['otp_attempts'])) $_SESSION['otp_attempts'] = 0;
        $_SESSION['otp_attempts']++;
        if ($_SESSION['otp_attempts'] > 5) {
            // Invalidate the OTP
            query("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND used = 0", [$userId]);
            unset($_SESSION['otp_user_id'], $_SESSION['otp_email'], $_SESSION['otp_attempts']);
            echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new code.']);
            exit;
        }

        $token = fetch_one(
            "SELECT * FROM otp_tokens WHERE user_id = ? AND token = ? AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1",
            [$userId, $code]
        );

        if (!$token) {
            echo json_encode(['success' => false, 'message' => 'Invalid or expired code. Please try again.']);
            exit;
        }

        // Mark token as used
        query("UPDATE otp_tokens SET used = 1 WHERE id = ?", [$token['id']]);

        // Get user details
        $user = fetch_one("SELECT * FROM users WHERE id = ?", [$userId]);

        // Set session
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['company_id'] = $user['company_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['email'] = $user['email'];

        unset($_SESSION['otp_user_id'], $_SESSION['otp_email']);
        unset($_SESSION['otp_attempts']);

        log_activity('login', 'User logged in');

        echo json_encode(['success' => true, 'redirect' => BASE_URL . 'dashboard.php']);
        exit;
    }

    if ($_GET['action'] === 'resend_otp') {
        $userId = $_SESSION['otp_user_id'] ?? null;
        $email = $_SESSION['otp_email'] ?? null;

        if (!$userId || !$email) {
            echo json_encode(['success' => false, 'message' => 'Session expired. Please start over.']);
            exit;
        }

        // Invalidate old tokens
        query("UPDATE otp_tokens SET used = 1 WHERE user_id = ? AND used = 0", [$userId]);

        $otp = generate_otp();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        insert('otp_tokens', [
            'user_id' => $userId,
            'token' => $otp,
            'expires_at' => $expiresAt
        ]);

        $emailBody = "
        <div style='font-family:Inter,Arial,sans-serif;max-width:480px;margin:0 auto;padding:2rem'>
            <h2 style='color:#1f1f2e;margin-bottom:1rem'>" . APP_NAME . " Login Code</h2>
            <p style='color:#555;font-size:15px'>Your new one-time login code is:</p>
            <div style='background:#f0f2f5;padding:1.5rem;text-align:center;border-radius:8px;margin:1.5rem 0'>
                <span style='font-size:32px;font-weight:700;letter-spacing:8px;color:#1f1f2e'>{$otp}</span>
            </div>
            <p style='color:#888;font-size:13px'>This code expires in " . OTP_EXPIRY_MINUTES . " minutes.</p>
        </div>";

        send_email($email, APP_NAME . ' — Your Login Code', $emailBody);

        echo json_encode(['success' => true, 'message' => 'A new code has been sent to your email.']);
        exit;
    }
}
