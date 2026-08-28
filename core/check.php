<?php
require_once __DIR__ . '/db_connect.php';

try {
    $stmt = $pdo->query('SELECT * FROM attendance_logs');
    $rows = $stmt->fetchAll();
    foreach ($rows as $row) {
        print_r($row);
    }
} catch (PDOException $e) {
    echo "Error fetching data: " . $e->getMessage();
}
?>
