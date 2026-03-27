<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'emergency/');
if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'emergency/'); }

$id = (int)($_POST['id'] ?? 0);
$companyId = $_SESSION['company_id'] ?? null;

if (is_super_admin()) {
    $broadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE id = ? AND is_active = 1", [$id]);
} else {
    $broadcast = fetch_one("SELECT * FROM emergency_broadcasts WHERE id = ? AND company_id = ? AND is_active = 1", [$id, $companyId]);
}
if (!$broadcast) { flash('error', 'Broadcast not found.'); redirect(BASE_URL . 'emergency/'); }

$bcCompanyId = $broadcast['company_id'];

// End the broadcast
update('emergency_broadcasts', [
    'is_active' => 0,
    'ended_at' => date('Y-m-d H:i:s')
], 'id = ?', [$id]);

// Restore screens to their previous mode based on assignments
$affectedScreens = [];
if ($broadcast['target'] === 'all_locations') {
    $affectedScreens = fetch_all("SELECT id FROM screens WHERE company_id = ?", [$bcCompanyId]);
} else {
    $targets = fetch_all("SELECT * FROM emergency_targets WHERE broadcast_id = ?", [$id]);
    foreach ($targets as $t) {
        if ($t['screen_id']) {
            $affectedScreens[] = ['id' => $t['screen_id']];
        }
        if ($t['location_id']) {
            $locScreens = fetch_all("SELECT id FROM screens WHERE location_id = ?", [$t['location_id']]);
            $affectedScreens = array_merge($affectedScreens, $locScreens);
        }
    }
}

foreach ($affectedScreens as $scr) {
    $sa = fetch_one("SELECT assignment_type FROM screen_assignments WHERE screen_id = ?", [$scr['id']]);
    $mode = $sa ? $sa['assignment_type'] : 'playlist';
    update('screens', ['current_mode' => $mode], 'id = ?', [$scr['id']]);
}

log_activity('emergency_ended', "Ended emergency broadcast: {$broadcast['title']}");
flash('success', 'Emergency broadcast ended. Screens returning to normal content.');
redirect(BASE_URL . 'emergency/');
