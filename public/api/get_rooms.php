<?php
// api/get_rooms.php
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json');

try {
    // Fetch unique rooms that have been added to the system
    $stmt = $pdo->query("SELECT DISTINCT room_id FROM door_status ORDER BY room_id ASC");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fallback if the database is completely empty
    if (empty($rooms)) {
        $rooms = [
            ['room_id' => 'ICT Lab 1']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $rooms]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
