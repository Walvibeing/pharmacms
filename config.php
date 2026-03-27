<?php
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'pharmacy_cms');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.example.com');
define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
define('SMTP_USER', getenv('SMTP_USER') ?: 'your@email.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'yourpassword');
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@yourdomain.com');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'PharmaCMS');

define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8082/');
define('UPLOAD_PATH', __DIR__ . '/uploads/media/');
define('THUMB_PATH', __DIR__ . '/uploads/thumbnails/');
define('MAX_FILE_SIZE', 524288000); // 500MB
define('OTP_EXPIRY_MINUTES', 10);
define('APP_NAME', 'PharmaCMS');
define('DEV_MODE', false); // Shows OTP on screen instead of emailing

// Session security
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', 3600); // 1 hour
