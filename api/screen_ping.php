<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');
// Restrict CORS to same origin (remove for same-origin deployments)
$allowedOrigin = defined('SCREEN_PLAYER_ORIGIN') ? SCREEN_PLAYER_ORIGIN : '';
if ($allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}
// If no origin configured, don't send CORS header (same-origin only)

$key = $_GET['key'] ?? '';
if (empty($key)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing screen key.']);
    exit;
}

$screen = fetch_one("SELECT id FROM screens WHERE screen_key = ?", [$key]);
if (!$screen) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Screen not found.']);
    exit;
}

query("UPDATE screens SET last_ping = NOW() WHERE id = ?", [$screen['id']]);

echo json_encode(['status' => 'ok']);
