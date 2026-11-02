<?php
// api/serve_face.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify session
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

// Ensure the user requesting is either a teacher/admin, OR the student requesting their own face.
$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['id'] ?? '');

if (empty($id)) {
    http_response_code(400);
    exit('Bad Request');
}

if ($_SESSION['role'] === 'student') {
    if (empty($_SESSION['role_id'])) {
        require_once __DIR__ . '/../config/database.php';
        $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $_SESSION['role_id'] = $stmt->fetchColumn() ?: '';
    }
    if ($_SESSION['role_id'] !== $id) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$face_dir = __DIR__ . '/../storage/face_data/';
$file_path = $face_dir . $id . '.jpg';

if (file_exists($file_path)) {
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
} else {
    http_response_code(404);
    exit('Not Found');
}
?>
