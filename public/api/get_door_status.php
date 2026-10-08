<?php
// api/get_door_status.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support', 'teacher']);

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$room_id = $_GET['room_id'] ?? 'ICT Lab 1';

try {
    $stmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room_id");
    $stmt->execute([':room_id' => $room_id]);
    $door = $stmt->fetch();
    
    // Default to locked (1) if no record exists for that room
    $is_locked = $door ? (bool)$door['is_locked'] : true;
    
    echo json_encode(['success' => true, 'is_locked' => $is_locked]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
