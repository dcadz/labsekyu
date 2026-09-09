<?php
require 'core/db_connect.php';
$stmt = $pdo->query('DESCRIBE class_schedules');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
