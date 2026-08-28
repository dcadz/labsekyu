<?php
// api/toggle_door.php
require_once __DIR__ . '/../core/auth_check.php';
checkAccess(['tech_support', 'teacher']);

require_once __DIR__ . '/../core/db_connect.php';

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

try {
    $stmt = $pdo->prepare("INSERT INTO door_status (room_id, is_locked, last_override_by) VALUES (:room_id, :is_locked, :user_id) ON DUPLICATE KEY UPDATE is_locked = :is_locked, last_override_by = :user_id");
    $stmt->execute([
        ':room_id' => $room_id,
        ':is_locked' => $is_locked,
        ':user_id' => $user_id
    ]);
    
    // Fetch user name
    $userNameStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :user_id");
    $userNameStmt->execute([':user_id' => $user_id]);
    $userRow = $userNameStmt->fetch();
    $userName = $userRow ? $userRow['full_name'] : 'Unknown User';

    $actionText = ($action === 'lock') ? "Room {$room_id} closed" : "Room {$room_id} opened";
    
    // Also log to system_alerts
    $alertStmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES ('Low', :location, :event_type)");
    $alertStmt->execute([
        ':location' => $room_id,
        ':event_type' => $actionText . ' by ' . $userName
    ]);

    echo json_encode(['success' => true, 'is_locked' => $is_locked]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
