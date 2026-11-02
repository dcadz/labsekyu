<?php
// dashboards/student/update_student.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['student']);
require_once __DIR__ . '/../../../config/database.php';

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
    $stmt = $pdo->prepare("UPDATE users SET full_name = :name, role_id = :role_id, email = :email, section = :section WHERE id = :student_id");
    $stmt->execute([
        ':name' => $full_name,
        ':role_id' => $role_id,
        ':email' => $email,
        ':section' => $section,
        ':student_id' => $student_id
    ]);
    
    $_SESSION['full_name'] = $full_name;
    $_SESSION['role_id'] = $role_id;

    $safe_new_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);
    $face_dir = __DIR__ . "/../../../storage/face_data/";
    if (!is_dir($face_dir)) {
        mkdir($face_dir, 0777, true);
    }
    $new_path = $face_dir . $safe_new_id . ".jpg";

    // Handle Image
    if ($imageBase64) {
        // Explode base64
        $parts = explode(',', $imageBase64);
        if (count($parts) === 2) {
            $image_data = base64_decode($parts[1]);
            if ($image_data !== false) {
                file_put_contents($new_path, $image_data);
                
                // Send to Python Face Recognition Server for Encoding and Duplicate Checking
                $cfile = new CURLFile($new_path, 'image/jpeg', 'face.jpg');
                $post = array('uid' => $role_id, 'image' => $cfile);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:8000/api/register_face");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-API-Key: labsekyu_secret_key_123!'));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpcode != 200) {
                    $respData = json_decode($response, true);
                    $errMsg = $respData['error'] ?? 'Face rejected by recognition server.';
                    unlink($new_path); // Remove invalid face
                    echo json_encode(['success' => false, 'error' => $errMsg]);
                    exit();
                }
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
