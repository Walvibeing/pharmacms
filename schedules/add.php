<?php
session_start();
require_once __DIR__ . '/../config.php';
$_SESSION['flash']['info'] = 'Schedules are managed per-screen. Select a screen below to set up content schedules.';
header('Location: ' . BASE_URL . 'screens/');
exit;
