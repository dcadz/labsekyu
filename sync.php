<?php
require_once __DIR__ . '/core/db_connect.php';

$stmt = $pdo->query("SELECT role_id FROM users WHERE role_id IS NOT NULL AND role_id != ''");
$db_users = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Tell python to list all known faces, then we delete the ones not in db
echo json_encode($db_users);
