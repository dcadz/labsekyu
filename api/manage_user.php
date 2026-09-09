<?php
// api/manage_user.php
require_once __DIR__ . '/../core/auth_check.php';
// Only tech support can reset passwords in this context
checkAccess(['tech_support']);

require_once __DIR__ . '/../core/db_connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
        exit;
    }

    $action = $input['action'] ?? '';
    
    if ($action === 'reset_password') {
        $user_id = $input['user_id'] ?? '';
        $new_password = $input['password'] ?? '';
        
        if (empty($user_id) || empty($new_password)) {
            echo json_encode(['success' => false, 'message' => 'User ID and New Password are required']);
            exit;
        }
        
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        if ($stmt->execute([$password_hash, $user_id])) {
            echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update password']);
        }
        exit;
    }
    
    if ($action === 'delete_user') {
        $user_id = $input['user_id'] ?? '';
        if (empty($user_id)) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit;
        }
        
        // Fetch role_id to delete from legacy table and face database
        $stmtId = $pdo->prepare("SELECT role_id, role FROM users WHERE id = ?");
        $stmtId->execute([$user_id]);
        $u = $stmtId->fetch();
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'tech_support'");
        if ($stmt->execute([$user_id])) {
            if ($u && !empty($u['role_id'])) {
                if ($u['role'] === 'student') {
                    $delLegacy = $pdo->prepare("DELETE FROM students WHERE uid = ?");
                    $delLegacy->execute([$u['role_id']]);
                }
                
                // Remove face encoding from Python backend
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "http://localhost:8000/api/delete_face");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, ['uid' => $u['role_id']]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                curl_exec($ch);
                curl_close($ch);
            }
            echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete user']);
        }
        exit;
    }
    
    if ($action === 'edit_user') {
        $user_id = $input['user_id'] ?? '';
        $full_name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $section = trim($input['section'] ?? '');
        $academic_rank = trim($input['academic_rank'] ?? '');
        
        if (empty($user_id) || empty($full_name) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'User ID, Name, and Email are required']);
            exit;
        }
        
        // Fetch role_id to update legacy table
        $stmtId = $pdo->prepare("SELECT role_id, role FROM users WHERE id = ?");
        $stmtId->execute([$user_id]);
        $u = $stmtId->fetch();
        
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, section = ?, academic_rank = ? WHERE id = ?");
        if ($stmt->execute([$full_name, $email, $section, $academic_rank, $user_id])) {
            if ($u && $u['role'] === 'student' && !empty($u['role_id'])) {
                $updLegacy = $pdo->prepare("UPDATE students SET full_name = ? WHERE uid = ?");
                $updLegacy->execute([$full_name, $u['role_id']]);
            }
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
exit;
?>
