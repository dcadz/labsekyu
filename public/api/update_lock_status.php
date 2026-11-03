<?php
// public/api/update_lock_status.php
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
            // 1. Update the master room status in system_settings table
            $settingStmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value) 
                VALUES ('room_status', :status) 
                ON DUPLICATE KEY UPDATE setting_value = :status
            ");
            $settingStmt->execute([':status' => $status]);

            // 2. Update real-time door_status table
            if ($isAllMode) {
                // Update all rows in door_status
                $upd = $pdo->prepare("UPDATE door_status SET is_locked = :is_locked, last_override_by = 'Hardware/System'");
                $upd->execute([':is_locked' => $is_locked]);

                // Ensure default rooms exist if table was empty
                $count = $pdo->query("SELECT COUNT(*) FROM door_status")->fetchColumn();
                if ($count == 0) {
                    $insertDefault = $pdo->prepare("INSERT INTO door_status (room_id, is_locked, last_override_by) VALUES ('101', :is_locked, 'Hardware/System'), ('ICT Lab 1', :is_locked, 'Hardware/System')");
                    $insertDefault->execute([':is_locked' => $is_locked]);
                }
            } else {
                $upd = $pdo->prepare("
                    INSERT INTO door_status (room_id, is_locked, last_override_by) 
                    VALUES (:room, :is_locked, 'Hardware/System') 
                    ON DUPLICATE KEY UPDATE is_locked = :is_locked, last_override_by = 'Hardware/System'
                ");
                $upd->execute([
                    ':room' => $room,
                    ':is_locked' => $is_locked
                ]);
            }

            // 3. Log alert
            try {
                $alertLocation = $isAllMode ? 'Building' : $room;
                $actionText = ($is_locked === 1) ? "Door locked via Hardware/System" : "Door unlocked via Hardware/System";
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
