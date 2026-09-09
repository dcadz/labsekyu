<?php
// dashboards/tech_support/user_management.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../core/db_connect.php';

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM teacher_alerts WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

// Handle form submission
$message = '';
$message_type = 'success'; // or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_teacher_face') {
        $teacher_id = $_POST['teacher_id'] ?? '';
        $has_upload = (isset($_FILES['face_image_file']) && $_FILES['face_image_file']['error'] === UPLOAD_ERR_OK);
        
        if (!empty($teacher_id) && $has_upload) {
            $stmt = $pdo->prepare("SELECT role_id FROM users WHERE id = ? AND role = 'teacher'");
            $stmt->execute([$teacher_id]);
            $teacher = $stmt->fetch();
            
            if ($teacher) {
                $role_id = $teacher['role_id'];
                $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);
                $uploadDir = __DIR__ . '/../../assets/face_data/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $filePath = $uploadDir . $safe_id . '.jpg';
                move_uploaded_file($_FILES['face_image_file']['tmp_name'], $filePath);
                
                $cfile = new CURLFile($filePath, 'image/jpeg', 'face.jpg');
                $post = array('uid' => $role_id, 'image' => $cfile);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "http://localhost:8000/api/register_face");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpcode != 200) {
                    $respData = json_decode($response, true);
                    $errMsg = $respData['error'] ?? 'Face rejected by recognition server.';
                    if (file_exists($filePath)) unlink($filePath);
                    
                    $message = "Face update failed: " . $errMsg;
                    $message_type = "error";
                } else {
                    $message = "Teacher's face updated successfully!";
                    $message_type = "success";
                }
            } else {
                $message = "Invalid teacher.";
                $message_type = "error";
            }
        } else {
            $message = "Please upload a photo.";
            $message_type = "error";
        }
    } elseif ($action === 'add_teacher') {
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $academic_rank = trim($_POST['academic_rank'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $full_name = trim("$first_name $middle_name $last_name");

        if (!empty($first_name) && !empty($last_name) && !empty($email) && !empty($password)) {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $message = "Email already exists. Please choose another.";
                $message_type = "error";
            } else {
                $has_upload = (isset($_FILES['face_image_file']) && $_FILES['face_image_file']['error'] === UPLOAD_ERR_OK);
                if (!$has_upload) {
                    $message = "Please upload a face photo for the teacher.";
                    $message_type = "error";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $role_id = 'TCH-' . strtoupper(substr(md5($email . time()), 0, 6));
                    
                    $insert_stmt = $pdo->prepare("INSERT INTO users (full_name, first_name, middle_name, last_name, email, password_hash, role, role_id, academic_rank) VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, ?)");
                    if ($insert_stmt->execute([$full_name, $first_name, $middle_name, $last_name, $email, $password_hash, $role_id, $academic_rank])) {
                        
                        $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $role_id);
                        $uploadDir = __DIR__ . '/../../assets/face_data/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        $filePath = $uploadDir . $safe_id . '.jpg';
                        move_uploaded_file($_FILES['face_image_file']['tmp_name'], $filePath);
                        
                        $cfile = new CURLFile($filePath, 'image/jpeg', 'face.jpg');
                        $post = array('uid' => $role_id, 'image' => $cfile);
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, "http://localhost:8000/api/register_face");
                        curl_setopt($ch, CURLOPT_POST, 1);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        $response = curl_exec($ch);
                        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        if ($httpcode != 200) {
                            $respData = json_decode($response, true);
                            $errMsg = $respData['error'] ?? 'Face rejected by recognition server.';
                            if (file_exists($filePath)) unlink($filePath);
                            
                            $pdo->prepare("DELETE FROM users WHERE role_id = ?")->execute([$role_id]);
                            
                            $message = "Teacher registered but face enrollment failed: " . $errMsg;
                            $message_type = "error";
                        } else {
                            $message = "Teacher successfully registered and face enrolled!";
                            $message_type = "success";
                        }
                    } else {
                        $message = "Failed to register teacher.";
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

// Fetch all teachers for management
$teacherStmt = $pdo->query("SELECT id, full_name, email, academic_rank FROM users WHERE role = 'teacher' ORDER BY full_name ASC");
$teachers = $teacherStmt->fetchAll();

// Fetch all students for management
$studentStmt = $pdo->query("SELECT id, full_name, email, section FROM users WHERE role = 'student' ORDER BY full_name ASC");
$students = $studentStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: #fff; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 400px; color: #333; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-header h3 { margin: 0; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        
        .action-btn { background: none; border: none; cursor: pointer; color: var(--bg-sidebar); display: inline-flex; align-items: center; justify-content: center; padding: 0.25rem; }
        .action-btn:hover { color: var(--accent-blue); }
        .action-btn.delete:hover { color: var(--accent-red); }

        .card { background: var(--bg-card); color: #fff; height: 100%; }
        .card-header h2 { color: #fff; }

        .management-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .management-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="techsupport.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="user_management.php" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            User Management
        </a>
        <a href="register_student.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Register Face
        </a>
        <a href="alerts.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                <span style="background: var(--accent-red); color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <div style="margin-top: auto; padding: 1rem;">
            <a href="../../auth/logout.php" onclick="confirmLogout(event);" style="display: block; width: 100%; padding: 0.75rem; background: #ffffff; color: #dc2626; font-weight: bold; text-align: center; border-radius: 0.5rem; text-decoration: none; box-sizing: border-box; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Logout</a>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="sidebar-brand">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
            LABSEKYU
        </div>
        <div class="admin-badge">ADMIN</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container" style="display: flex; flex-direction: column; width: 100%;">
        
        <?php if ($message): ?>
            <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; color: <?= $message_type === 'success' ? '#15803d' : '#b91c1c' ?>; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="management-grid">
            <!-- Add Teacher Form -->
            <div>
                <div class="card" style="margin: 0;">
                    <div class="card-header">
                        <h2>Teacher Registration</h2>
                    </div>
                    
                    <form method="POST" action="" id="teacherForm" enctype="multipart/form-data" onsubmit="return confirmSave(event, this, 'register this teacher')">
                        <input type="hidden" name="action" value="add_teacher">
                        
                        <div class="form-group">
                            <label>Academic Rank</label>
                            <select name="academic_rank" required>
                                <option value="">Select Rank...</option>
                                <option value="Instructor I">Instructor I</option>
                                <option value="Instructor II">Instructor II</option>
                                <option value="Instructor III">Instructor III</option>
                                <option value="Assistant Professor I">Assistant Professor I</option>
                                <option value="Assistant Professor II">Assistant Professor II</option>
                                <option value="Assistant Professor III">Assistant Professor III</option>
                                <option value="Assistant Professor IV">Assistant Professor IV</option>
                                <option value="Associate Professor I">Associate Professor I</option>
                                <option value="Associate Professor II">Associate Professor II</option>
                                <option value="Associate Professor III">Associate Professor III</option>
                                <option value="Associate Professor IV">Associate Professor IV</option>
                                <option value="Associate Professor V">Associate Professor V</option>
                                <option value="Professor I">Professor I</option>
                                <option value="Professor II">Professor II</option>
                                <option value="Professor III">Professor III</option>
                                <option value="Professor IV">Professor IV</option>
                                <option value="Professor V">Professor V</option>
                                <option value="Professor VI">Professor VI</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label>Middle Name</label>
                            <input type="text" name="middle_name">
                        </div>
                        <div class="form-group">
                            <label>Surname</label>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label>Face Photo</label>
                            <input type="file" name="face_image_file" accept="image/*" required>
                        </div>
                        <button type="submit" class="btn btn-success" style="width: 100%; border-radius: 0.5rem; margin-top: 1rem;">Register Teacher</button>
                    </form>
                </div>
            </div>

            <!-- Users Lists -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                
                <!-- Teachers List -->
                <div class="card" style="margin: 0; max-height: 50vh; display: flex; flex-direction: column;">
                    <div class="card-header" style="border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                        <h2>Teachers</h2>
                    </div>
                    
                    <div style="overflow-y: auto; flex: 1;">
                        <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate; font-size: 0.875rem;">
                            <thead>
                                <tr>
                                    <th style="border: none; background: #e2e8f0; color: #333; text-align: left; padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">Name</th>
                                    <th style="border: none; background: #e2e8f0; color: #333; text-align: center; padding: 0.5rem; border-radius: 0 0.5rem 0.5rem 0; width: 130px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($teachers as $tc): ?>
                                <tr style="background: #f8fafc;">
                                    <td style="padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">
                                        <div style="font-weight: bold; color: #333;"><?= htmlspecialchars($tc['full_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($tc['email']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($tc['academic_rank'] ?? 'N/A') ?></div>
                                    </td>
                                    <td style="padding: 0.5rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0; white-space: nowrap;">
                                        <button class="action-btn" title="Edit" onclick="openEditUserModal(<?= $tc['id'] ?>, 'teacher', '<?= htmlspecialchars(addslashes($tc['full_name'])) ?>', '<?= htmlspecialchars(addslashes($tc['email'])) ?>', '', '<?= htmlspecialchars(addslashes($tc['academic_rank'] ?? '')) ?>')">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                        </button>
                                        <button class="action-btn" title="Upload Face" onclick="openUploadFaceModal(<?= $tc['id'] ?>, '<?= htmlspecialchars(addslashes($tc['full_name'])) ?>')">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                        </button>
                                        <button class="action-btn" title="Reset Password" onclick="openPasswordModal(<?= $tc['id'] ?>, '<?= htmlspecialchars(addslashes($tc['full_name'])) ?>')">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                                        </button>
                                        <button class="action-btn delete" title="Delete" onclick="deleteUser(<?= $tc['id'] ?>)">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Students List -->
                <div class="card" style="margin: 0; max-height: 50vh; display: flex; flex-direction: column;">
                    <div class="card-header" style="border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                        <h2>Students</h2>
                    </div>
                    
                    <div style="overflow-y: auto; flex: 1;">
                        <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate; font-size: 0.875rem;">
                            <thead>
                                <tr>
                                    <th style="border: none; background: #e2e8f0; color: #333; text-align: left; padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">Name</th>
                                    <th style="border: none; background: #e2e8f0; color: #333; text-align: center; padding: 0.5rem; border-radius: 0 0.5rem 0.5rem 0; width: 130px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students as $st): ?>
                                <tr style="background: #f8fafc;">
                                    <td style="padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">
                                        <div style="font-weight: bold; color: #333;"><?= htmlspecialchars($st['full_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($st['email']) ?></div>
                                        <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($st['section']) ?></div>
                                    </td>
                                    <td style="padding: 0.5rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0; white-space: nowrap;">
                                        <button class="action-btn" title="Edit" onclick="openEditUserModal(<?= $st['id'] ?>, 'student', '<?= htmlspecialchars(addslashes($st['full_name'])) ?>', '<?= htmlspecialchars(addslashes($st['email'])) ?>', '<?= htmlspecialchars(addslashes($st['section'])) ?>', '')">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                        </button>
                                        <button class="action-btn" title="Reset Password" onclick="openPasswordModal(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['full_name'])) ?>')">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                                        </button>
                                        <button class="action-btn delete" title="Delete" onclick="deleteUser(<?= $st['id'] ?>)">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</main>

<!-- Reset Password Modal -->
<div id="passwordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Reset Password</h3>
            <button class="close-btn" onclick="document.getElementById('passwordModal').style.display='none'">&times;</button>
        </div>
        <form id="passwordForm" onsubmit="submitPasswordReset(event)">
            <input type="hidden" id="reset_user_id" name="user_id">
            <p style="margin-bottom: 1rem; color: #64748b; font-size: 0.875rem;">Resetting password for: <strong id="reset_user_name" style="color: #333;"></strong></p>
            <div class="form-group">
                <label style="color: #333;">New Password</label>
                <input type="password" id="new_password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;">Update Password</button>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit User</h3>
            <button class="close-btn" onclick="document.getElementById('editUserModal').style.display='none'">&times;</button>
        </div>
        <form id="editUserForm" onsubmit="submitEditUser(event)">
            <input type="hidden" id="edit_user_id" name="user_id">
            
            <div class="form-group">
                <label style="color: #333;">Full Name</label>
                <input type="text" id="edit_full_name" required>
            </div>
            <div class="form-group">
                <label style="color: #333;">Email</label>
                <input type="email" id="edit_email" required>
            </div>
            
            <div class="form-group" id="edit_section_group" style="display: none;">
                <label style="color: #333;">Section</label>
                <input type="text" id="edit_section">
            </div>
            <div class="form-group" id="edit_rank_group" style="display: none;">
                <label style="color: #333;">Academic Rank</label>
                <select id="edit_academic_rank">
                    <option value="">None</option>
                    <option value="Instructor I">Instructor I</option>
                    <option value="Instructor II">Instructor II</option>
                    <option value="Instructor III">Instructor III</option>
                    <option value="Assistant Professor I">Assistant Professor I</option>
                    <option value="Assistant Professor II">Assistant Professor II</option>
                    <option value="Assistant Professor III">Assistant Professor III</option>
                    <option value="Assistant Professor IV">Assistant Professor IV</option>
                    <option value="Associate Professor I">Associate Professor I</option>
                    <option value="Associate Professor II">Associate Professor II</option>
                    <option value="Associate Professor III">Associate Professor III</option>
                    <option value="Associate Professor IV">Associate Professor IV</option>
                    <option value="Associate Professor V">Associate Professor V</option>
                    <option value="Professor I">Professor I</option>
                    <option value="Professor II">Professor II</option>
                    <option value="Professor III">Professor III</option>
                    <option value="Professor IV">Professor IV</option>
                    <option value="Professor V">Professor V</option>
                    <option value="Professor VI">Professor VI</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;">Save Changes</button>
        </form>
    </div>
</div>

<script>
    function confirmSave(event, formElement, actionText) {
        event.preventDefault();
        Swal.fire({
            title: 'Are you sure?',
            text: `Do you want to ${actionText}?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, proceed!'
        }).then((result) => {
            if (result.isConfirmed) {
                formElement.submit();
            }
        });
        return false;
    }

    function openPasswordModal(id, name) {
        document.getElementById('reset_user_id').value = id;
        document.getElementById('reset_user_name').textContent = name;
        document.getElementById('new_password').value = '';
        document.getElementById('passwordModal').style.display = 'flex';
    }

    function submitPasswordReset(e) {
        e.preventDefault();
        const userId = document.getElementById('reset_user_id').value;
        const newPassword = document.getElementById('new_password').value;

        fetch('../../api/manage_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'reset_password',
                user_id: userId,
                password: newPassword
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'Password reset successfully!',
                    showConfirmButton: false,
                    timer: 2000
                }).then(() => {
                    document.getElementById('passwordModal').style.display = 'none';
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while resetting the password.', 'error');
        });
    }

    function openEditUserModal(id, role, name, email, section, rank) {
        document.getElementById('edit_user_id').value = id;
        document.getElementById('edit_full_name').value = name;
        document.getElementById('edit_email').value = email;
        
        if (role === 'student') {
            document.getElementById('edit_section_group').style.display = 'block';
            document.getElementById('edit_rank_group').style.display = 'none';
            document.getElementById('edit_section').value = section;
            document.getElementById('edit_academic_rank').value = '';
        } else {
            document.getElementById('edit_section_group').style.display = 'none';
            document.getElementById('edit_rank_group').style.display = 'block';
            document.getElementById('edit_section').value = '';
            document.getElementById('edit_academic_rank').value = rank;
        }
        
        document.getElementById('editUserModal').style.display = 'flex';
    }

    function submitEditUser(e) {
        e.preventDefault();
        
        fetch('../../api/manage_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'edit_user',
                user_id: document.getElementById('edit_user_id').value,
                full_name: document.getElementById('edit_full_name').value,
                email: document.getElementById('edit_email').value,
                section: document.getElementById('edit_section').value,
                academic_rank: document.getElementById('edit_academic_rank').value
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: 'User updated successfully!',
                    showConfirmButton: false,
                    timer: 2000
                }).then(() => {
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'An error occurred while updating the user.', 'error');
        });
    }

    function deleteUser(userId) {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will not be able to recover this user!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('../../api/manage_user.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'delete_user',
                        user_id: userId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'User has been deleted.',
                            showConfirmButton: false,
                            timer: 2000
                        }).then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('Error', 'An error occurred while deleting the user.', 'error');
                });
            }
        });
    }

    function openUploadFaceModal(id, name) {
        document.getElementById('uploadFaceTeacherId').value = id;
        document.getElementById('uploadFaceTeacherName').innerText = name;
        document.getElementById('uploadFaceModal').style.display = 'flex';
    }
</script>

<!-- Upload Face Modal -->
<div id="uploadFaceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Upload Face for <span id="uploadFaceTeacherName" style="color:var(--accent-blue);"></span></h3>
            <button class="close-btn" onclick="document.getElementById('uploadFaceModal').style.display='none'">&times;</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" onsubmit="return confirmSave(event, this, 'upload this face')">
            <input type="hidden" name="action" value="upload_teacher_face">
            <input type="hidden" name="teacher_id" id="uploadFaceTeacherId">
            <div class="form-group">
                <label>Select Photo</label>
                <input type="file" name="face_image_file" accept="image/*" required>
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%; border-radius: 0.5rem; margin-top: 1rem;">Upload & Register Face</button>
        </form>
    </div>
</div>

<script src="../../assets/js/logout.js"></script>
</body>
</html>
