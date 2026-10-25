CREATE DATABASE IF NOT EXISTS drr_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE drr_platform;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','civilian') NOT NULL DEFAULT 'civilian',
    full_name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS civilian_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    barangay VARCHAR(120) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS households (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_code VARCHAR(40) UNIQUE NOT NULL,
    head_name VARCHAR(120) NOT NULL,
    purok VARCHAR(80) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    risk_level ENUM('Safe','Caution','Danger') DEFAULT 'Caution',
    members INT DEFAULT 1,
    infants INT DEFAULT 0,
    seniors INT DEFAULT 0,
    pwd_count INT DEFAULT 0,
    pregnant INT DEFAULT 0,
    medical_need VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS evacuation_centers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    barangay VARCHAR(100) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    capacity INT NOT NULL,
    current_occupancy INT DEFAULT 0,
    status ENUM('Open','Warning','Full','Closed') DEFAULT 'Open'
);

CREATE TABLE IF NOT EXISTS sms_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_number VARCHAR(40) NOT NULL,
    report_type VARCHAR(30) DEFAULT 'SOS',
    citizen_name VARCHAR(120),
    location_text VARCHAR(255),
    need_text VARCHAR(255),
    raw_message TEXT NOT NULL,
    status ENUM('New','Dispatched','Resolved') DEFAULT 'New',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS relief_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(80) NOT NULL,
    quantity_received INT DEFAULT 0,
    quantity_distributed INT DEFAULT 0,
    unit VARCHAR(40) DEFAULT 'packs',
    donor VARCHAR(150),
    batch_code VARCHAR(60),
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS relief_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    family_code VARCHAR(40) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity INT NOT NULL,
    issued_by VARCHAR(120),
    receipt_code VARCHAR(80) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS civilian_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    user_name VARCHAR(120) NOT NULL,
    user_email VARCHAR(150) DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    danger_level ENUM('Low','Moderate','High','Critical') DEFAULT 'Moderate',
    report_text TEXT,
    photo_path VARCHAR(255) DEFAULT NULL,
    status ENUM('New','Approved','In Progress','Reassigned','Resolved') DEFAULT 'New',
    assigned_to VARCHAR(150) DEFAULT NULL,
    assigned_team VARCHAR(120) DEFAULT NULL,
    escalation_level ENUM('Monitoring','Dispatch','Escalated') DEFAULT 'Monitoring',
    admin_note TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_role ENUM('admin','civilian') NOT NULL,
    user_name VARCHAR(120) NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at)
);

INSERT INTO users (username, email, password, role, full_name)
VALUES
('admin', 'admin@gmail.com', 'admin123', 'admin', 'System Administrator'),
('civilian', 'civilian@gmail.com', 'civil123', 'civilian', 'Citizen User')
ON DUPLICATE KEY UPDATE
    email = VALUES(email),
    password = VALUES(password),
    role = VALUES(role),
    full_name = VALUES(full_name);

INSERT IGNORE INTO civilian_accounts (full_name, email, password, phone, barangay)
VALUES
('Citizen User', 'civilian@gmail.com', 'civil123', '09171234567', 'Barangay Central'),
('Maria Santos', 'maria.santos@gmail.com', 'maria123', '09181234567', 'Barangay East');

INSERT IGNORE INTO households
(family_code,head_name,purok,latitude,longitude,risk_level,members,infants,seniors,pwd_count,pregnant,medical_need)
VALUES
('HH-1001','Maria Santos','Purok 1',10.3157,123.8854,'Danger',5,1,1,0,0,'Maintenance medicine'),
('HH-1002','Juan Dela Cruz','Purok 3',10.3212,123.8971,'Caution',4,0,0,1,0,NULL),
('HH-1003','Ana Reyes','Purok 5',10.3075,123.8722,'Safe',3,0,1,0,1,NULL);

INSERT IGNORE INTO evacuation_centers
(name,barangay,latitude,longitude,capacity,current_occupancy,status)
VALUES
('Barangay Hall Gym','Barangay Central',10.3180,123.8900,200,188,'Warning'),
('East Central School','Barangay East',10.3260,123.9020,350,126,'Open'),
('West Community Center','Barangay West',10.3040,123.8750,150,150,'Full');

INSERT INTO relief_inventory
(item_name,category,quantity_received,quantity_distributed,unit,donor,batch_code)
SELECT 'Family Relief Pack','Food',500,312,'packs','National Agency','BATCH-2026-001'
WHERE NOT EXISTS (SELECT 1 FROM relief_inventory WHERE batch_code='BATCH-2026-001');

INSERT INTO relief_inventory
(item_name,category,quantity_received,quantity_distributed,unit,donor,batch_code)
SELECT 'Drinking Water','Water',1000,645,'bottles','Private Donors','BATCH-2026-002'
WHERE NOT EXISTS (SELECT 1 FROM relief_inventory WHERE batch_code='BATCH-2026-002');

INSERT INTO relief_inventory
(item_name,category,quantity_received,quantity_distributed,unit,donor,batch_code)
SELECT 'Hygiene Kit','Hygiene',300,97,'kits','NGO Partner','BATCH-2026-003'
WHERE NOT EXISTS (SELECT 1 FROM relief_inventory WHERE batch_code='BATCH-2026-003');
