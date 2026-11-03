<?php
// api/toggle_door.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support', 'teacher']);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$room_id = $input['room_id'] ?? 'ICT Lab 1';
$action = $input['action'] ?? null; // 'lock' or 'unlock'

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'Action required']);
    exit();
}

$is_locked = ($action === 'lock') ? 1 : 0;
$user_id = $_SESSION['user_id'] ?? 'Unknown';
$role = $_SESSION['role'] ?? 'Unknown';

try {
    // Fetch user name
    $userNameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :user_id");
    $userNameStmt->execute([':user_id' => $user_id]);
    $userRow = $userNameStmt->fetch();
    $userName = $userRow ? $userRow['full_name'] : 'Unknown User';

    $statusStr = ($is_locked === 1) ? 'LOCKED' : 'UNLOCKED';

    // 1. Synchronize master room_status in system_settings
    $settingStmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value) 
        VALUES ('room_status', :status) 
        ON DUPLICATE KEY UPDATE setting_value = :status
    ");
    $settingStmt->execute([':status' => $statusStr]);

    // 2. Handle 'ALL' rooms mode for tech_support
    if (strtolower($room_id) === 'all' && $role === 'tech_support') {
        $stmt = $pdo->prepare("UPDATE door_status SET is_locked = :is_locked, last_override_by = :user_id");
        $stmt->execute([':is_locked' => $is_locked, ':user_id' => $userName]);

        $actionText = ($action === 'lock') ? "All building doors locked" : "All building doors unlocked";
        $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Med', 'Building', :event_type)");
        $alertStmt->execute([':event_type' => $actionText . ' by ' . $userName]);

        echo json_encode(['success' => true, 'is_locked' => $is_locked, 'status' => $statusStr, 'all_rooms' => true]);
        exit();
    }

    // 3. Update room in door_status
    $stmt = $pdo->prepare("
        INSERT INTO door_status (room_id, is_locked, last_override_by) 
        VALUES (:room_id, :is_locked, :user_id) 
        ON DUPLICATE KEY UPDATE is_locked = :is_locked, last_override_by = :user_id
    ");
    $stmt->execute([
        ':room_id' => $room_id,
        ':is_locked' => $is_locked,
        ':user_id' => $userName
    ]);

    // Also update any other rooms if single hardware lock controls the lab
    if ($is_locked === 0) {
        $pdo->prepare("UPDATE door_status SET is_locked = 0 WHERE room_id = '101' OR room_id = :room_id")->execute([':room_id' => $room_id]);
    } else {
        $pdo->prepare("UPDATE door_status SET is_locked = 1 WHERE room_id = '101' OR room_id = :room_id")->execute([':room_id' => $room_id]);
    }

    $actionText = ($action === 'lock') ? "Room {$room_id} locked" : "Room {$room_id} unlocked";
    
    // Log to system_alerts
    try {
        $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :location, :event_type)");
        $alertStmt->execute([
            ':location' => $room_id,
            ':event_type' => $actionText . ' by ' . $userName
        ]);
    } catch (Exception $ex) {}

    echo json_encode(['success' => true, 'is_locked' => $is_locked, 'status' => $statusStr, 'room_id' => $room_id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
