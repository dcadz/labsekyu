<?php
require 'core/db_connect.php';
$stmt = $pdo->query('DESCRIBE users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt = $pdo->query('SELECT DISTINCT role FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
