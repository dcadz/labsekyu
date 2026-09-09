<?php
require_once __DIR__ . '/../core/db_connect.php';

header('Content-Type: application/json');

// Only accept POST requests
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
    // Check if room already exists
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM door_status WHERE room_id = ?");
    $checkStmt->execute([$room_name]);
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Room already exists']);
        exit;
    }

    // Insert new room. Default to locked (is_locked = 1)
    $insertStmt = $pdo->prepare("INSERT INTO door_status (room_id, is_locked) VALUES (?, 1)");
    $insertStmt->execute([$room_name]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
