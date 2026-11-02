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

// 1. Handle Hardware Action Reports (from ESP32 physical button or auto-pulse complete)
$action = strtolower(trim($_GET['action'] ?? ''));
if (!empty($action)) {
    try {
        if ($isAllMode) {
            if ($action === 'lock') {
                // Lock all currently unlocked rooms
                $upd = $pdo->prepare("
                    UPDATE door_status 
                    SET is_locked = 1, last_override_by = 'Hardware Button' 
                    WHERE is_locked = 0
                ");
                $upd->execute();

                try {
                    $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', 'Building', 'Door locked via Hardware Button')");
                    $alertStmt->execute();
                } catch (Exception $ex) {}
            } elseif ($action === 'unlock') {
                // Unlock the most recently active room, or default to Room 101
                $recentStmt = $pdo->query("SELECT room_id FROM door_status ORDER BY last_updated DESC LIMIT 1");
                $targetRoom = $recentStmt->fetchColumn() ?: '101';

                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, 0, 'Hardware Button') 
                    ON DUPLICATE KEY UPDATE is_locked = 0, last_override_by = 'Hardware Button'
                ");
                $upd->execute([':room' => $targetRoom]);

                try {
                    $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :location, :event_type)");
                    $alertStmt->execute([
                        ':location' => $targetRoom,
                        ':event_type' => "Room {$targetRoom} opened by Hardware Button"
                    ]);
                } catch (Exception $ex) {}
            }
        } else {
            // Specific Room Mode
            if ($action === 'lock') {
                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, 1, 'Hardware Button') 
                    ON DUPLICATE KEY UPDATE is_locked = 1, last_override_by = 'Hardware Button'
                ");
                $upd->execute([':room' => $room]);

                try {
                    $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :location, :event_type)");
                    $alertStmt->execute([
                        ':location' => $room,
                        ':event_type' => "Room {$room} closed by Hardware Button"
                    ]);
                } catch (Exception $ex) {}
            } elseif ($action === 'unlock') {
                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, 0, 'Hardware Button') 
                    ON DUPLICATE KEY UPDATE is_locked = 0, last_override_by = 'Hardware Button'
                ");
                $upd->execute([':room' => $room]);

                try {
                    $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :location, :event_type)");
                    $alertStmt->execute([
                        ':location' => $room,
                        ':event_type' => "Room {$room} opened by Hardware Button"
                    ]);
                } catch (Exception $ex) {}
            } elseif ($action === 'toggle') {
                $curStmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room LIMIT 1");
                $curStmt->execute([':room' => $room]);
                $cur = $curStmt->fetch();
                $newLock = ($cur && (int)$cur['is_locked'] === 1) ? 0 : 1;

                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, :newLock, 'Hardware Button') 
                    ON DUPLICATE KEY UPDATE is_locked = :newLock, last_override_by = 'Hardware Button'
                ");
                $upd->execute([':room' => $room, ':newLock' => $newLock]);
            }
        }
    } catch (Exception $e) {
        // Silently continue
    }
}

// 2. Query Real-Time Status for ESP32
try {
    if ($isAllMode) {
        // In ALL mode: Check if ANY room in the building is unlocked
        $stmt = $pdo->query("SELECT room_id FROM door_status WHERE is_locked = 0 ORDER BY last_updated DESC LIMIT 1");
        $unlocked = $stmt->fetch();

        if ($unlocked) {
            echo "UNLOCKED";
        } else {
            echo "LOCKED";
        }
    } else {
        // Specific Room Mode
        $stmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room LIMIT 1");
        $stmt->execute([':room' => $room]);
        $status = $stmt->fetch();

        if ($status && (int)$status['is_locked'] === 0) {
            echo "UNLOCKED";
        } else {
            echo "LOCKED";
        }
    }
} catch (Exception $e) {
    echo "LOCKED";
}

