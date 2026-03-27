CREATE DATABASE IF NOT EXISTS pharmacy_cms;
USE pharmacy_cms;

-- Companies (tenants)
CREATE TABLE companies (
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
);

-- Users
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT,
  email VARCHAR(255) UNIQUE NOT NULL,
  name VARCHAR(255),
  role ENUM('super_admin', 'company_admin', 'location_manager') NOT NULL,
  is_active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

-- OTP tokens
CREATE TABLE otp_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  used TINYINT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Locations
CREATE TABLE locations (
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
);

-- Location user assignments
CREATE TABLE location_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NOT NULL,
  user_id INT NOT NULL,
  UNIQUE KEY unique_assignment (location_id, user_id),
  FOREIGN KEY (location_id) REFERENCES locations(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Screens
CREATE TABLE screens (
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
);

-- Media library
CREATE TABLE media (
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
);

-- Media location assignments (junction table for multi-location support)
CREATE TABLE media_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  media_id INT NOT NULL,
  location_id INT NOT NULL,
  UNIQUE KEY unique_pair (media_id, location_id),
  FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

-- Playlists
CREATE TABLE playlists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL,
  location_id INT,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  loop_enabled TINYINT DEFAULT 1,
  is_active TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (company_id) REFERENCES companies(id)
);

-- Playlist items
CREATE TABLE playlist_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT NOT NULL,
  media_id INT NOT NULL,
  sort_order INT DEFAULT 0,
  duration INT DEFAULT 10,
  FOREIGN KEY (playlist_id) REFERENCES playlists(id) ON DELETE CASCADE,
  FOREIGN KEY (media_id) REFERENCES media(id)
);

-- Screen assignments
CREATE TABLE screen_assignments (
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
);

-- Scheduled content
CREATE TABLE schedules (
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
);

-- Emergency broadcasts
CREATE TABLE emergency_broadcasts (
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
);

-- Emergency broadcast targets
CREATE TABLE emergency_targets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  broadcast_id INT NOT NULL,
  location_id INT,
  screen_id INT,
  FOREIGN KEY (broadcast_id) REFERENCES emergency_broadcasts(id) ON DELETE CASCADE
);

-- Screen pairing codes (for Firestick/device pairing)
CREATE TABLE screen_pair_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  screen_key VARCHAR(64) NOT NULL,
  code VARCHAR(6) NOT NULL,
  device_info TEXT,
  is_used TINYINT DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_code (code),
  INDEX idx_screen_key (screen_key)
);

-- Activity log
CREATE TABLE activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  company_id INT,
  action VARCHAR(255) NOT NULL,
  details TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default super admin
INSERT INTO users (email, name, role, is_active) VALUES ('admin@pharmacms.com', 'Super Admin', 'super_admin', 1);
