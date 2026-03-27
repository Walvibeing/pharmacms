<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'schedules/');
if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'schedules/'); }

$id = (int)($_POST['id'] ?? 0);
$redirectUrl = $_POST['redirect'] ?? BASE_URL . 'schedules/';
$companyId = $_SESSION['company_id'] ?? null;

if (is_super_admin()) {
    $schedule = fetch_one("SELECT * FROM schedules WHERE id = ?", [$id]);
} else {
    $schedule = fetch_one("SELECT * FROM schedules WHERE id = ? AND company_id = ?", [$id, $companyId]);
}
if (!$schedule) { flash('error', 'Schedule not found.'); redirect($redirectUrl); }

update('schedules', ['is_active' => 0], 'id = ?', [$id]);

log_activity('schedule_deleted', "Deleted schedule: {$schedule['name']}");
flash('success', 'Schedule removed.');
redirect($redirectUrl);
