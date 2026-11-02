<?php
// api/get_door_status.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support', 'teacher']);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$room_id = $_GET['room_id'] ?? $_GET['room'] ?? '101';

try {
    if (strtolower($room_id) === 'all') {
        $stmt = $pdo->query("SELECT room_id, is_locked FROM door_status");
        $doors = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $doors[$row['room_id']] = (bool)$row['is_locked'];
        }
        echo json_encode(['success' => true, 'doors' => $doors]);
        exit;
    }

    $stmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room_id");
    $stmt->execute([':room_id' => $room_id]);
    $door = $stmt->fetch();
    
    // Default to locked (1) if no record exists for that room
    $is_locked = $door ? (bool)$door['is_locked'] : true;
    
    echo json_encode(['success' => true, 'is_locked' => $is_locked, 'room_id' => $room_id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
