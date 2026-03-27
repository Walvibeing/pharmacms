<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'media/');
if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'media/'); }

$id = (int)($_POST['id'] ?? 0);
$companyId = $_SESSION['company_id'] ?? null;

if (is_super_admin()) {
    $media = fetch_one("SELECT * FROM media WHERE id = ?", [$id]);
} else {
    $media = fetch_one("SELECT * FROM media WHERE id = ? AND company_id = ?", [$id, $companyId]);
}
if (!$media) { flash('error', 'Media not found.'); redirect(BASE_URL . 'media/'); }

// Check if used in active playlists
$inUse = fetch_one("SELECT COUNT(*) as c FROM playlist_items WHERE media_id = ?", [$id]);
if ($inUse['c'] > 0) {
    flash('error', 'Cannot delete — this media is used in ' . $inUse['c'] . ' playlist(s). Remove it from playlists first.');
    redirect(BASE_URL . 'media/');
}

// Soft delete
update('media', ['is_active' => 0], 'id = ?', [$id]);

log_activity('media_deleted', "Deleted media: {$media['name']}");
flash('success', 'Media deleted.');
redirect(BASE_URL . 'media/');
