<?php
require_once __DIR__ . '/../core/db_connect.php';

try {
    $hash = password_hash('password123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, password_hash, role, role_id, section)
        SELECT full_name, CONCAT(uid, '@labsekyu.local'), ?, 'student', uid, 'Migrated'
        FROM students
        WHERE uid NOT IN (SELECT role_id FROM users WHERE role_id IS NOT NULL)
    ");
    $stmt->execute([$hash]);
    echo "Migrated " . $stmt->rowCount() . " students successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
