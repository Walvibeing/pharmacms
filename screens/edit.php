<?php
// Redirect to screens index - editing is now done via side panel
require_once __DIR__ . '/../config.php';

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    header('Location: ' . BASE_URL . 'screens/');
} else {
    header('Location: ' . BASE_URL . 'screens/');
}
exit;
