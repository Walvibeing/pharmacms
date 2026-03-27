<?php
// Redirect to locations index with side panel open
require_once __DIR__ . '/../config.php';

$params = 'add';
header('Location: ' . BASE_URL . 'locations/?' . $params);
exit;
