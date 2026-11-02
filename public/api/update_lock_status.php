<?php
// update_lock_status.php
// Hardware & UI status synchronization endpoint for LABSEKYU
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$rawStatus = $_REQUEST['status'] ?? '';
$room = trim($_REQUEST['room'] ?? $_REQUEST['room_id'] ?? 'ALL');

if (!empty($rawStatus)) {
    $status = strtoupper(trim($rawStatus));
    
    if ($status === 'LOCKED' || $status === 'UNLOCKED') {
        $is_locked = ($status === 'LOCKED') ? 1 : 0;
        $isAllMode = (strtoupper($room) === 'ALL' || strtoupper($room) === 'ANY' || empty($room));

        try {
            // 1. Update the master room status in system_settings table (matching user specification)
            $settingStmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES ('room_status', :status) 
                ON DUPLICATE KEY UPDATE setting_value = :status
            ");
            $settingStmt->execute([':status' => $status]);

            // 2. Update real-time door_status table so all dashboards (Tech Support & Teacher) sync instantly
            if ($isAllMode) {
                if ($is_locked === 1) {
                    $upd = $pdo->prepare("
                        UPDATE door_status 
                        SET is_locked = 1, last_override_by = 'Hardware Pulse' 
                        WHERE is_locked = 0
                    ");
                    $upd->execute();
                } else {
                    $recentStmt = $pdo->query("SELECT room_id FROM door_status ORDER BY last_updated DESC LIMIT 1");
                    $targetRoom = $recentStmt->fetchColumn() ?: '101';
                    $upd = $pdo->prepare("
                        INSERT INTO door_status (room_id, is_locked, last_override_by) 
                        VALUES (:room, 0, 'Hardware Pulse') 
                        ON DUPLICATE KEY UPDATE is_locked = 0, last_override_by = 'Hardware Pulse'
                    ");
                    $upd->execute([':room' => $targetRoom]);
                }
            } else {
                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, :is_locked, 'Hardware Pulse') 
                    ON DUPLICATE KEY UPDATE is_locked = :is_locked, last_override_by = 'Hardware Pulse'
                ");
                $upd->execute([
                    ':room' => $room,
                    ':is_locked' => $is_locked
                ]);
            }

            // 3. Optional: Log to system_alerts if locking after 5-second pulse
            try {
                $alertLocation = $isAllMode ? 'Building' : $room;
                $actionText = ($is_locked === 1) ? "Door relocked after 5s pulse" : "Door unlocked";
                $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :loc, :ev)");
                $alertStmt->execute([':loc' => $alertLocation, ':ev' => $actionText]);
            } catch (Exception $ex) {}

            echo "SUCCESS: Status updated to " . $status;
        } catch (PDOException $e) {
            echo "ERROR: Database update failed.";
        }
    } else {
        echo "ERROR: Invalid status parameter.";
    }
} else {
    echo "ERROR: Missing status parameter.";
}
?>

