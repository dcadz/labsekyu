<?php
// dashboards/student/delete_account.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['student']);
require_once __DIR__ . '/../../core/db_connect.php';

header('Content-Type: application/json');

$student_id = $_SESSION['user_id'];

try {
    // 1. Get role_id to delete the face image
    $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = :id");
    $stmt->execute([':id' => $student_id]);
    $role_id = $stmt->fetchColumn();

    if ($role_id) {
        $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);
        $path = __DIR__ . "/../../assets/face_data/" . $safe_id . ".jpg";
        if (file_exists($path)) {
            unlink($path);
        }
    }

    // 2. Delete the user record
    // Assuming the database has cascading deletes for related records, or there are no strictly related records for a student that block deletion.
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $student_id]);

    // 3. Destroy session
    session_destroy();

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
