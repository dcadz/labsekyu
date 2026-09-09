<?php
require_once __DIR__ . '/../core/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$room_name = trim($data['room_name'] ?? '');

if (empty($room_name)) {
    echo json_encode(['success' => false, 'error' => 'Room name cannot be empty']);
    exit;
}

try {
    $deleteStmt = $pdo->prepare("DELETE FROM door_status WHERE room_id = ?");
    $deleteStmt->execute([$room_name]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
