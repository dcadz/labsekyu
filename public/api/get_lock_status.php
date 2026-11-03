<?php
// public/api/get_lock_status.php
// Hardware endpoint for ESP32 Solenoid Lock Controller
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$room = trim($_GET['room'] ?? $_GET['room_id'] ?? '');
if (empty($room)) {
    $room = 'ALL';
}

$isAllMode = (strtoupper($room) === 'ALL' || strtoupper($room) === 'ANY');

// 1. Handle Hardware Action Reports (from ESP32 physical button)
$action = strtolower(trim($_GET['action'] ?? ''));
if (!empty($action)) {
    try {
        $newStatus = ($action === 'unlock') ? 'UNLOCKED' : 'LOCKED';
        $is_locked = ($action === 'lock') ? 1 : 0;

        // Update system_settings
        $settingStmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('room_status', :status) 
            ON DUPLICATE KEY UPDATE setting_value = :status
        ");
        $settingStmt->execute([':status' => $newStatus]);

        if ($isAllMode) {
            $upd = $pdo->prepare("UPDATE door_status SET is_locked = :is_locked, last_override_by = 'Hardware Button'");
            $upd->execute([':is_locked' => $is_locked]);
        } else {
            $upd = $pdo->prepare("
                INSERT INTO door_status (room_id, is_locked, last_override_by) 
                VALUES (:room, :is_locked, 'Hardware Button') 
                ON DUPLICATE KEY UPDATE is_locked = :is_locked, last_override_by = 'Hardware Button'
            ");
            $upd->execute([':room' => $room, ':is_locked' => $is_locked]);
        }
    } catch (Exception $e) {}
}

// 2. Query Real-Time Status for ESP32
try {
    // Check master setting
    $masterUnlocked = false;
    $setStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'room_status' LIMIT 1");
    $settingVal = $setStmt ? $setStmt->fetchColumn() : null;
    if ($settingVal && strtoupper(trim($settingVal)) === 'UNLOCKED') {
        $masterUnlocked = true;
    }

    if ($isAllMode) {
        // In ALL mode: Check if master setting is UNLOCKED or ANY room is unlocked
        $stmt = $pdo->query("SELECT room_id FROM door_status WHERE is_locked = 0 LIMIT 1");
        $unlockedRow = $stmt ? $stmt->fetch() : false;

        if ($masterUnlocked || $unlockedRow) {
            echo "UNLOCKED";
        } else {
            echo "LOCKED";
        }
    } else {
        // Specific Room Mode
        $stmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room LIMIT 1");
        $stmt->execute([':room' => $room]);
        $status = $stmt->fetch();

        if ($masterUnlocked || ($status && (int)$status['is_locked'] === 0)) {
            echo "UNLOCKED";
        } else {
            echo "LOCKED";
        }
    }
} catch (Exception $e) {
    echo "LOCKED";
}
?>
