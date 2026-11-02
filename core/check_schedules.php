<?php
require __DIR__ . '/../config/database.php';
$stmt = $pdo->query('DESCRIBE class_schedules');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
