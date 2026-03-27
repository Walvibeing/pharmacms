<?php
// Redirect to locations index - viewing is now done via side panel
require_once __DIR__ . '/../config.php';

$id = (int)($_GET['id'] ?? 0);
header('Location: ' . BASE_URL . 'locations/');
exit;
