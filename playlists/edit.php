<?php
// Redirect to playlists index — editing now handled via side panel
require_once __DIR__ . '/../config.php';
header('Location: ' . BASE_URL . 'playlists/');
exit;
