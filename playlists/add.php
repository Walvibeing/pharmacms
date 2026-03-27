<?php
// Redirect to playlists index with side panel open
require_once __DIR__ . '/../config.php';

header('Location: ' . BASE_URL . 'playlists/?add');
exit;
