<?php
// dashboards/student/save_face.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['student']);

require_once __DIR__ . '/../../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$imageBase64 = $input['imageBase64'] ?? null;

if (!$imageBase64) {
    echo json_encode(['success' => false, 'error' => 'No image data provided']);
    exit();
}

// Extract base64 part
if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64, $type)) {
    $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
    $type = strtolower($type[1]); // jpg, png, gif

    if (!in_array($type, ['jpg', 'jpeg', 'png'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid image type']);
        exit();
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid image format']);
    exit();
}

$imageData = base64_decode($imageBase64);
if ($imageData === false) {
    echo json_encode(['success' => false, 'error' => 'Failed to decode image']);
    exit();
}

// Get the student's role_id (UID) to name the file
$student_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = :id");
$stmt->execute([':id' => $student_id]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit();
}

$role_id = $student['role_id'];
// Sanitize role_id for filename
$safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);

$uploadDir = __DIR__ . '/../../../storage/face_data/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$filename = $safe_id . '.jpg';
$filePath = $uploadDir . $filename;

if (file_put_contents($filePath, $imageData)) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file to server']);
}
?>
