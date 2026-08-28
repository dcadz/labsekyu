<?php
// database/migrate.php
require_once __DIR__ . '/../core/db_connect.php';

try {
    // 1. Ensure user table has role & role_id
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            first_name VARCHAR(50) NULL,
            middle_name VARCHAR(50) NULL,
            last_name VARCHAR(50) NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('tech_support', 'teacher', 'student') NOT NULL DEFAULT 'student',
            role_id VARCHAR(50) NOT NULL,
            academic_rank VARCHAR(50) NULL,
            section VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('first_name', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(50) NULL AFTER full_name");
        $pdo->exec("ALTER TABLE users ADD COLUMN middle_name VARCHAR(50) NULL AFTER first_name");
        $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(50) NULL AFTER middle_name");
    }
    if (!in_array('role', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('tech_support', 'teacher', 'student') NOT NULL DEFAULT 'student' AFTER email");
    }
    if (!in_array('role_id', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role_id VARCHAR(50) NOT NULL AFTER role");
    }
    if (!in_array('academic_rank', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN academic_rank VARCHAR(50) NULL AFTER role_id");
    }
    if (!in_array('section', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN section VARCHAR(50) NULL AFTER academic_rank");
    }

    // 2. Ensure class_schedules table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS class_schedules (
            schedule_id INT AUTO_INCREMENT PRIMARY KEY,
            subject_code VARCHAR(50) NOT NULL,
            section VARCHAR(50) NOT NULL,
            teacher_id INT NULL,
            room_id VARCHAR(50) NOT NULL DEFAULT 'ICT Lab 1',
            day_of_week VARCHAR(20) NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL
        );
    ");

    // 3. Ensure attendance_logs has entry/exit and duration metrics
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attendance_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            schedule_id INT NULL,
            log_date DATE NOT NULL,
            entry_timestamp DATETIME NULL,
            exit_timestamp DATETIME NULL,
            stay_percentage DECIMAL(5,2) DEFAULT 0.00,
            status ENUM('Present', 'Late', 'Dropped', 'Absent') DEFAULT 'Absent',
            sync_source ENUM('Live', 'SD Cache') DEFAULT 'Live'
        );
    ");

    // Add match_percentage if not exists
    $log_columns = $pdo->query("SHOW COLUMNS FROM attendance_logs")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('match_percentage', $log_columns)) {
        $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN match_percentage DECIMAL(5,2) DEFAULT 100.00 AFTER sync_source");
    }

    // 4. Ensure door_status tracking table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS door_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id VARCHAR(50) NOT NULL UNIQUE,
            is_locked TINYINT(1) DEFAULT 1,
            last_override_by VARCHAR(50) NULL,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
    ");

    // 5. Ensure system_alerts tracking table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_alerts (
            alert_id INT AUTO_INCREMENT PRIMARY KEY,
            severity ENUM('High', 'Med', 'Low') NOT NULL DEFAULT 'Low',
            location VARCHAR(100) NOT NULL,
            event_type VARCHAR(150) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    echo "Database migration completed successfully without data loss.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage());
}
?>