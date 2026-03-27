<?php
// Temporary setup script - DELETE AFTER USE
// Secured with a one-time token
$SETUP_TOKEN = 'pharmacms-setup-2026-x9k4m';

if (($_GET['token'] ?? '') !== $SETUP_TOKEN) {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$adminEmail = $_GET['admin_email'] ?? 'andrew.wallis@alliedpharmacies.com';
$adminName = $_GET['admin_name'] ?? 'Andrew Wallis';

$results = [];

$tables = [
    'companies' => "CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(100) UNIQUE NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50),
        address TEXT,
        logo VARCHAR(255),
        status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
        approved_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",
    'users' => "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT,
        email VARCHAR(255) UNIQUE NOT NULL,
        name VARCHAR(255),
        role ENUM('super_admin', 'company_admin', 'location_manager') NOT NULL,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
    )",
    'otp_tokens' => "CREATE TABLE IF NOT EXISTS otp_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(6) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )",
    'locations' => "CREATE TABLE IF NOT EXISTS locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        address TEXT,
        city VARCHAR(100),
        postcode VARCHAR(20),
        contact_name VARCHAR(255),
        contact_email VARCHAR(255),
        contact_phone VARCHAR(50),
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id)
    )",
    'location_users' => "CREATE TABLE IF NOT EXISTS location_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        location_id INT NOT NULL,
        user_id INT NOT NULL,
        UNIQUE KEY unique_assignment (location_id, user_id),
        FOREIGN KEY (location_id) REFERENCES locations(id),
        FOREIGN KEY (user_id) REFERENCES users(id)
    )",
    'screens' => "CREATE TABLE IF NOT EXISTS screens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        location_id INT NOT NULL,
        company_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        screen_key VARCHAR(64) UNIQUE NOT NULL,
        device_id VARCHAR(255) DEFAULT NULL,
        device_info TEXT DEFAULT NULL,
        orientation ENUM('landscape', 'portrait') DEFAULT 'landscape',
        resolution VARCHAR(20) DEFAULT '1920x1080',
        status ENUM('active', 'inactive', 'offline') DEFAULT 'active',
        last_ping DATETIME,
        current_mode ENUM('playlist', 'single', 'scheduled', 'emergency') DEFAULT 'playlist',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (location_id) REFERENCES locations(id),
        FOREIGN KEY (company_id) REFERENCES companies(id)
    )",
    'media' => "CREATE TABLE IF NOT EXISTS media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        location_id INT,
        uploaded_by INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_type ENUM('image', 'video') NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size INT NOT NULL,
        duration INT DEFAULT 10,
        thumbnail VARCHAR(255),
        tags VARCHAR(500),
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id),
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
    )",
    'media_locations' => "CREATE TABLE IF NOT EXISTS media_locations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        media_id INT NOT NULL,
        location_id INT NOT NULL,
        UNIQUE KEY unique_pair (media_id, location_id),
        FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
        FOREIGN KEY (location_id) REFERENCES locations(id)
    )",
    'playlists' => "CREATE TABLE IF NOT EXISTS playlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        location_id INT,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        loop_enabled TINYINT DEFAULT 1,
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id)
    )",
    'playlist_items' => "CREATE TABLE IF NOT EXISTS playlist_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        playlist_id INT NOT NULL,
        media_id INT NOT NULL,
        sort_order INT DEFAULT 0,
        duration INT DEFAULT 10,
        FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
        FOREIGN KEY (media_id) REFERENCES media(id)
    )",
    'screen_assignments' => "CREATE TABLE IF NOT EXISTS screen_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        screen_id INT NOT NULL UNIQUE,
        assignment_type ENUM('playlist', 'single', 'scheduled') NOT NULL,
        playlist_id INT,
        media_id INT,
        media_ids TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (screen_id) REFERENCES screens(id),
        FOREIGN KEY (playlist_id) REFERENCES playlists(id),
        FOREIGN KEY (media_id) REFERENCES media(id)
    )",
    'schedules' => "CREATE TABLE IF NOT EXISTS schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        screen_id INT NOT NULL,
        company_id INT NOT NULL,
        name VARCHAR(255),
        playlist_id INT,
        media_id INT,
        start_datetime DATETIME NOT NULL,
        end_datetime DATETIME NOT NULL,
        repeat_type ENUM('none', 'daily', 'weekly') DEFAULT 'none',
        repeat_days VARCHAR(20),
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (screen_id) REFERENCES screens(id),
        FOREIGN KEY (playlist_id) REFERENCES playlists(id),
        FOREIGN KEY (media_id) REFERENCES media(id)
    )",
    'emergency_broadcasts' => "CREATE TABLE IF NOT EXISTS emergency_broadcasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        created_by INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        media_id INT NOT NULL,
        target ENUM('all_locations', 'specific_locations', 'specific_screens') DEFAULT 'all_locations',
        is_active TINYINT DEFAULT 1,
        started_at DATETIME,
        ended_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id),
        FOREIGN KEY (media_id) REFERENCES media(id)
    )",
    'emergency_targets' => "CREATE TABLE IF NOT EXISTS emergency_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        broadcast_id INT NOT NULL,
        location_id INT,
        screen_id INT,
        FOREIGN KEY (broadcast_id) REFERENCES emergency_broadcasts(id) ON DELETE CASCADE
    )",
    'screen_pair_codes' => "CREATE TABLE IF NOT EXISTS screen_pair_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        screen_key VARCHAR(64) NOT NULL,
        code VARCHAR(6) NOT NULL,
        device_info TEXT,
        is_used TINYINT DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code (code),
        INDEX idx_screen_key (screen_key)
    )",
    'activity_log' => "CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        company_id INT,
        action VARCHAR(255) NOT NULL,
        details TEXT,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

echo "<h1>PharmaCMS Database Setup</h1><pre>\n";

foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $results[] = "✅ Table '$name' created/exists";
        echo "✅ Table '$name' OK\n";
    } catch (PDOException $e) {
        $results[] = "❌ Table '$name' FAILED: " . $e->getMessage();
        echo "❌ Table '$name' FAILED: " . $e->getMessage() . "\n";
    }
}

// Create admin user
try {
    $existing = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $existing->execute([$adminEmail]);
    if ($existing->fetch()) {
        echo "\n✅ Admin user already exists: $adminEmail\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO users (email, name, role, is_active) VALUES (?, ?, 'super_admin', 1)");
        $stmt->execute([$adminEmail, $adminName]);
        echo "\n✅ Admin user created: $adminEmail ($adminName)\n";
    }
} catch (PDOException $e) {
    echo "\n❌ Admin user FAILED: " . $e->getMessage() . "\n";
}

echo "\n\nDone! DELETE THIS FILE (setup_db.php) NOW.\n";
echo "</pre>";
