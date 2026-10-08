<?php
// api/device/door.php
require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

$room = $_GET['room'] ?? '';

if (empty($room)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing room parameter']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = :room");
    $stmt->execute([':room' => $room]);
    $status = $stmt->fetch();

    if ($status) {
        if ($status['is_locked'] == 1) {
            echo json_encode(['status' => 'LOCKED']);
        } else {
            echo json_encode(['status' => 'UNLOCKED']);
        }
    } else {
        // Default to locked if room not found
        echo json_encode(['status' => 'LOCKED']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
