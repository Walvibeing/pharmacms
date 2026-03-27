<?php
// Redirect to screens index with side panel open
require_once __DIR__ . '/../config.php';

$locationId = $_GET['location_id'] ?? '';
$params = 'add';
if ($locationId) {
    $params .= '&location_id=' . urlencode($locationId);
}

header('Location: ' . BASE_URL . 'screens/?' . $params);
exit;
