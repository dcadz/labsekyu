<?php
// dashboards/student/update_student.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['student']);
require_once __DIR__ . '/../../core/db_connect.php';

header('Content-Type: application/json');

$student_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

$full_name = trim($data['full_name'] ?? '');
$role_id = trim($data['role_id'] ?? ''); // Student ID
$email = trim($data['email'] ?? '');
$section = trim($data['section'] ?? '');
$imageBase64 = $data['imageBase64'] ?? null;

if (empty($full_name) || empty($role_id) || empty($email) || empty($section)) {
    echo json_encode(['success' => false, 'error' => 'All credential fields (including section) are required.']);
    exit();
}

try {
    // Check if the role_id is already used
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role_id = :role_id");
    $stmt->execute([':role_id' => $role_id]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // If it exists, update it
        $stmt = $pdo->prepare("UPDATE users SET full_name = :name, email = :email, section = :section WHERE role_id = :role_id");
        $stmt->execute([
            ':name' => $full_name,
            ':email' => $email,
            ':section' => $section,
            ':role_id' => $role_id
        ]);
        $new_user_id = $existing['id'];
    } else {
        // Insert new user
        $default_password = password_hash('password123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (full_name, role_id, email, password_hash, role, section) VALUES (:name, :role_id, :email, :password, 'student', :section)");
        $stmt->execute([
            ':name' => $full_name,
            ':role_id' => $role_id,
            ':email' => $email,
            ':password' => $default_password,
            ':section' => $section
        ]);
        $new_user_id = $pdo->lastInsertId();
    }

    $safe_new_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);
    $new_path = __DIR__ . "/../../assets/face_data/" . $safe_new_id . ".jpg";

    // Handle Image
    if ($imageBase64) {
        // Explode base64
        $parts = explode(',', $imageBase64);
        if (count($parts) === 2) {
            $image_data = base64_decode($parts[1]);
            if ($image_data !== false) {
                file_put_contents($new_path, $image_data);
            }
        }
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    // Check if role_id (Student ID) or email is already taken
    if ($e->getCode() == 23000) { // Integrity constraint violation
        echo json_encode(['success' => false, 'error' => 'Student ID or Email is already registered to another user.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>
