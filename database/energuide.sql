-- ============================================================
-- ENERGUIDE DATABASE SETUP
-- Compatible with the supplied normal-reading PHP update.
-- Creates the database/tables if they do not already exist.
-- Does NOT drop or delete an existing database.
-- ============================================================

CREATE DATABASE IF NOT EXISTS energuide
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE energuide;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('owner','admin','staff') DEFAULT 'owner',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(50) NOT NULL,
    room_type ENUM('Studio Unit','Bachelor Unit','2-Bedroom Penthouse') DEFAULT 'Studio Unit',
    status ENUM('Occupied','Vacant','Maintenance') DEFAULT 'Occupied',
    meter_id VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS meter_readings (
    reading_id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT NOT NULL,
    reading_date DATE NOT NULL,
    billing_period VARCHAR(20) DEFAULT NULL,
    previous_reading DECIMAL(10,2) NOT NULL DEFAULT 0,
    current_reading DECIMAL(10,2) NOT NULL,
    consumption_kwh DECIMAL(10,2) NOT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_meter_readings_room FOREIGN KEY (room_id)
      REFERENCES rooms(room_id) ON DELETE CASCADE,
    CONSTRAINT fk_meter_readings_user FOREIGN KEY (created_by)
      REFERENCES users(user_id) ON DELETE SET NULL,
    UNIQUE KEY unique_room_billing_period (room_id, billing_period),
    KEY idx_room_reading_date (room_id, reading_date),
    KEY idx_billing_period (billing_period)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS electricity_rates (
    rate_id INT AUTO_INCREMENT PRIMARY KEY,
    rate_per_kwh DECIMAL(10,4) NOT NULL,
    effective_date DATE NOT NULL,
    billing_month VARCHAR(20) DEFAULT NULL,
    rate_category VARCHAR(50) DEFAULT 'Commercial High Voltage',
    status ENUM('Active','Inactive') DEFAULT 'Active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate_billing_month (billing_month),
    KEY idx_rate_effective_date (effective_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS beneco_bills (
    bill_id INT AUTO_INCREMENT PRIMARY KEY,
    room_id INT DEFAULT NULL,
    billing_month VARCHAR(20) NOT NULL,
    bill_date DATE NOT NULL,
    total_kwh DECIMAL(10,2) DEFAULT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    rate_category VARCHAR(50) DEFAULT 'Commercial High Voltage',
    account_number VARCHAR(50) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    image_path VARCHAR(255) DEFAULT NULL,
    image_original_name VARCHAR(255) DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_beneco_bills_user FOREIGN KEY (created_by)
      REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_beneco_bills_room FOREIGN KEY (room_id)
      REFERENCES rooms(room_id) ON DELETE SET NULL,
    KEY idx_bill_month (billing_month),
    KEY idx_bill_room_month (room_id, billing_month),
    KEY idx_bill_date (bill_date)
) ENGINE=InnoDB;

-- Add room assignment safely when upgrading an existing database.
SET @has_bill_room = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneco_bills' AND COLUMN_NAME='room_id');
SET @sql_bill_room = IF(@has_bill_room=0, 'ALTER TABLE beneco_bills ADD COLUMN room_id INT DEFAULT NULL', 'SELECT 1');
PREPARE stmt_bill_room FROM @sql_bill_room; EXECUTE stmt_bill_room; DEALLOCATE PREPARE stmt_bill_room;
SET @has_bill_room_fk = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='beneco_bills' AND CONSTRAINT_NAME='fk_beneco_bills_room');
SET @sql_bill_room_fk = IF(@has_bill_room_fk=0, 'ALTER TABLE beneco_bills ADD CONSTRAINT fk_beneco_bills_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt_bill_room_fk FROM @sql_bill_room_fk; EXECUTE stmt_bill_room_fk; DEALLOCATE PREPARE stmt_bill_room_fk;
SET @has_bill_room_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneco_bills' AND INDEX_NAME='idx_bill_room_month');
SET @sql_bill_room_idx = IF(@has_bill_room_idx=0, 'ALTER TABLE beneco_bills ADD INDEX idx_bill_room_month (room_id, billing_month)', 'SELECT 1');
PREPARE stmt_bill_room_idx FROM @sql_bill_room_idx; EXECUTE stmt_bill_room_idx; DEALLOCATE PREPARE stmt_bill_room_idx;

-- Add bill-image columns safely when upgrading an existing database.
SET @has_image_path = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneco_bills' AND COLUMN_NAME='image_path');
SET @sql_image_path = IF(@has_image_path=0, 'ALTER TABLE beneco_bills ADD COLUMN image_path VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_image_path FROM @sql_image_path; EXECUTE stmt_image_path; DEALLOCATE PREPARE stmt_image_path;
SET @has_image_name = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='beneco_bills' AND COLUMN_NAME='image_original_name');
SET @sql_image_name = IF(@has_image_name=0, 'ALTER TABLE beneco_bills ADD COLUMN image_original_name VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_image_name FROM @sql_image_name; EXECUTE stmt_image_name; DEALLOCATE PREPARE stmt_image_name;

-- Default owner login (password: password). Change it after first login.
INSERT INTO users (name, email, password, role)
SELECT 'System Owner', 'owner@energuide.com',
       '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'owner'
WHERE NOT EXISTS (
    SELECT 1 FROM users WHERE email = 'owner@energuide.com'
);

-- Six rental rooms, arranged by floor.
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 1, 'Room 1', 'Studio Unit', 'Occupied', 'Meter 1', 'Basement' 
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 1);
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 2, 'Room 2', 'Studio Unit', 'Occupied', 'Meter 2', 'Basement'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 2);
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 3, 'Room 3', 'Studio Unit', 'Occupied', 'Meter 3', 'Basement'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 3);
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 4, 'Room 4', 'Studio Unit', 'Occupied', 'Meter 4', '1st Floor'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 4);
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 5, 'Room 5', 'Studio Unit', 'Occupied', 'Meter 5', '2nd Floor'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 5);
INSERT INTO rooms (room_id, room_name, room_type, status, meter_id, notes)
SELECT 6, 'Room 6', 'Studio Unit', 'Occupied', 'Meter 6', '3rd Floor'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE room_id = 6);

-- No High/Medium/Low consumption thresholds are created.
-- No sample electricity rate is inserted. Configure the verified rate
-- for each billing month in EnerGuide Settings before calculating costs.
