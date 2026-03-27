<?php
// Redirect to locations index - editing is now done via side panel
require_once __DIR__ . '/../config.php';

header('Location: ' . BASE_URL . 'locations/');
exit;
