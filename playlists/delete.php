<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_login();
require_role(['super_admin', 'company_admin', 'location_manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . 'playlists/');
if (!verify_csrf()) { flash('error', 'Invalid token.'); redirect(BASE_URL . 'playlists/'); }

$id = (int)($_POST['id'] ?? 0);
$companyId = $_SESSION['company_id'] ?? null;

if (is_super_admin()) {
    $playlist = fetch_one("SELECT * FROM playlists WHERE id = ?", [$id]);
} else {
    $playlist = fetch_one("SELECT * FROM playlists WHERE id = ? AND company_id = ?", [$id, $companyId]);
}
if (!$playlist) { flash('error', 'Playlist not found.'); redirect(BASE_URL . 'playlists/'); }

// Remove screen assignments referencing this playlist
delete_row('screen_assignments', 'playlist_id = ?', [$id]);

// Delete playlist items (cascade will handle, but explicit)
delete_row('playlist_items', 'playlist_id = ?', [$id]);

// Delete the playlist
delete_row('playlists', 'id = ?', [$id]);

log_activity('playlist_deleted', "Deleted playlist: {$playlist['name']}");
flash('success', 'Playlist deleted.');
redirect(BASE_URL . 'playlists/');
