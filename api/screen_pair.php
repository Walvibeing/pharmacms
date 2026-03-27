<?php
/**
 * Screen Pairing API
 * Handles device pairing flow for Firestick/display devices.
 *
 * Endpoints:
 *   GET  ?action=request_code&key=SCREEN_KEY   - Generate a 6-digit pairing code
 *   GET  ?action=check_paired&key=SCREEN_KEY   - Poll to check if device has been paired
 *   POST ?action=verify_code                    - Admin verifies code to link device to screen
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');
// Restrict CORS to same origin (remove for same-origin deployments)
$allowedOrigin = defined('SCREEN_PLAYER_ORIGIN') ? SCREEN_PLAYER_ORIGIN : '';
if ($allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
// If no origin configured, don't send CORS header (same-origin only)

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// GET: Request a pairing code for a screen
// ============================================================
if ($action === 'request_code' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = $_GET['key'] ?? '';
    if (empty($key)) {
        json_response(['error' => 'Missing screen key.'], 400);
    }

    $screen = fetch_one("SELECT id, name, screen_key FROM screens WHERE screen_key = ?", [$key]);
    if (!$screen) {
        json_response(['error' => 'Screen not found.'], 404);
    }

    // Expire any previous unused codes for this screen key
    query(
        "UPDATE screen_pair_codes SET is_used = 1 WHERE screen_key = ? AND is_used = 0",
        [$key]
    );

    // Generate a unique 6-digit code
    $attempts = 0;
    do {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $existing = fetch_one(
            "SELECT id FROM screen_pair_codes WHERE code = ? AND is_used = 0 AND expires_at > NOW()",
            [$code]
        );
        $attempts++;
    } while ($existing && $attempts < 10);

    // Code valid for 10 minutes
    $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Collect device info from headers if available
    $deviceInfo = json_encode([
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'requested_at' => date('Y-m-d H:i:s')
    ]);

    insert('screen_pair_codes', [
        'screen_key' => $key,
        'code' => $code,
        'device_info' => $deviceInfo,
        'is_used' => 0,
        'expires_at' => $expiresAt
    ]);

    json_response([
        'success' => true,
        'code' => $code,
        'expires_at' => $expiresAt,
        'screen_name' => $screen['name']
    ]);
}

// ============================================================
// GET: Check if this screen has been paired
// ============================================================
if ($action === 'check_paired' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = $_GET['key'] ?? '';
    if (empty($key)) {
        json_response(['error' => 'Missing screen key.'], 400);
    }

    $screen = fetch_one("SELECT id, device_id, device_info FROM screens WHERE screen_key = ?", [$key]);
    if (!$screen) {
        json_response(['error' => 'Screen not found.'], 404);
    }

    $paired = !empty($screen['device_id']);

    json_response([
        'success' => true,
        'paired' => $paired
    ]);
}

// ============================================================
// POST: Verify a pairing code (admin action)
// ============================================================
if ($action === 'verify_code' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // This endpoint requires admin authentication
    session_start();
    if (empty($_SESSION['user_id'])) {
        json_response(['error' => 'Authentication required.'], 401);
    }

    $code = trim($_POST['code'] ?? '');
    $screenId = (int)($_POST['screen_id'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        json_response(['success' => false, 'message' => 'Invalid security token.'], 403);
    }

    if (empty($code) || !$screenId) {
        json_response(['success' => false, 'message' => 'Code and screen ID are required.'], 400);
    }

    // Find the screen
    $screen = fetch_one("SELECT id, screen_key, name FROM screens WHERE id = ?", [$screenId]);
    if (!$screen) {
        json_response(['success' => false, 'message' => 'Screen not found.'], 404);
    }

    // Look up the pairing code
    $pairCode = fetch_one(
        "SELECT * FROM screen_pair_codes WHERE code = ? AND is_used = 0 AND expires_at > NOW()",
        [$code]
    );

    if (!$pairCode) {
        json_response(['success' => false, 'message' => 'Invalid or expired pairing code.']);
    }

    // Verify that the code belongs to this screen's key
    if ($pairCode['screen_key'] !== $screen['screen_key']) {
        json_response(['success' => false, 'message' => 'This code does not match the selected screen.']);
    }

    // Mark code as used
    query("UPDATE screen_pair_codes SET is_used = 1 WHERE id = ?", [$pairCode['id']]);

    // Generate a device ID and update the screen
    $deviceId = sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );

    $deviceInfo = $pairCode['device_info'] ?: '{}';

    query(
        "UPDATE screens SET device_id = ?, device_info = ? WHERE id = ?",
        [$deviceId, $deviceInfo, $screenId]
    );

    json_response([
        'success' => true,
        'message' => 'Device paired successfully.',
        'device_id' => $deviceId
    ]);
}

json_response(['error' => 'Invalid action.'], 400);
