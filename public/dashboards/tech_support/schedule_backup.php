<?php
// dashboards/tech_support/schedule.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../../config/database.php';

// Handle form submission
$message = '';
$message_type = 'success'; // or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_teacher') {
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
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $role_id = 'TCH-' . strtoupper(substr(md5($email . time()), 0, 6));
                
                $insert_stmt = $pdo->prepare("INSERT INTO users (full_name, first_name, middle_name, last_name, email, password_hash, role, role_id, academic_rank) VALUES (?, ?, ?, ?, ?, ?, 'teacher', ?, ?)");
                if ($insert_stmt->execute([$full_name, $first_name, $middle_name, $last_name, $email, $password_hash, $role_id, $academic_rank])) {
                    $message = "Teacher successfully registered!";
                } else {
                    $message = "Failed to register teacher.";
                    $message_type = "error";
                }
            }
        }
    } elseif ($action === 'delete_schedule') {
        $schedule_id = $_POST['schedule_id'] ?? '';
        if ($schedule_id) {
            $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE schedule_id = ?");
            if ($stmt->execute([$schedule_id])) {
                $message = "Schedule deleted successfully!";
            } else {
                $message = "Failed to delete schedule.";
                $message_type = "error";
            }
        }
    } elseif ($action === 'add_schedule' || $action === 'edit_schedule') {
        $subject_code = $_POST['subject_code'] ?? '';
        $section = $_POST['section'] ?? '';
        $teacher_name = trim($_POST['teacher_name'] ?? '');
        $room_id = $_POST['room_id'] ?? '';
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $schedule_id = $_POST['schedule_id'] ?? ''; // Only for edit

        if (!empty($subject_code) && !empty($section) && !empty($teacher_name) && !empty($room_id) && !empty($day_of_week) && !empty($start_time) && !empty($end_time)) {
            // 1. Resolve teacher
            $stmt = $pdo->prepare("SELECT id FROM users WHERE full_name = ? AND role = 'teacher' LIMIT 1");
            $stmt->execute([$teacher_name]);
            $teacher = $stmt->fetch();
            
            if ($teacher) {
                $teacher_id = $teacher['id'];
            } else {
                // Auto-create teacher if doesn't exist (fallback)
                $email = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $teacher_name)) . rand(1000,9999) . '@labsekyu.local';
                $password_hash = password_hash('default123', PASSWORD_DEFAULT);
                $role_id = 'TCH-' . strtoupper(substr(md5($teacher_name . time()), 0, 6));
                
                $insert_stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, role, role_id) VALUES (?, ?, ?, 'teacher', ?)");
                if ($insert_stmt->execute([$teacher_name, $email, $password_hash, $role_id])) {
                    $teacher_id = $pdo->lastInsertId();
                } else {
                    $teacher_id = null;
                    $message = "Failed to create new teacher.";
                    $message_type = "error";
                }
            }

            if ($teacher_id) {
                // 2. Overlap Check
                $overlapQuery = "SELECT schedule_id FROM class_schedules 
                                 WHERE room_id = ? AND day_of_week = ? 
                                 AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))";
                $overlapParams = [$room_id, $day_of_week, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time];
                
                if ($action === 'edit_schedule' && !empty($schedule_id)) {
                    $overlapQuery .= " AND schedule_id != ?";
                    $overlapParams[] = $schedule_id;
                }

                $overlapStmt = $pdo->prepare($overlapQuery);
                $overlapStmt->execute($overlapParams);
                if ($overlapStmt->fetch()) {
                    $message = "Error: This schedule conflicts with an existing class in $room_id on $day_of_week during this time!";
                    $message_type = "error";
                } else {
                    // 3. Save or Update
                    if ($action === 'add_schedule') {
                        $stmt = $pdo->prepare("INSERT INTO class_schedules (subject_code, section, teacher_id, room_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt->execute([$subject_code, $section, $teacher_id, $room_id, $day_of_week, $start_time, $end_time])) {
                            $message = "Schedule successfully added!";
                        } else {
                            $message = "Failed to add schedule.";
                            $message_type = "error";
                        }
                    } else if ($action === 'edit_schedule' && !empty($schedule_id)) {
                        $stmt = $pdo->prepare("UPDATE class_schedules SET subject_code=?, section=?, teacher_id=?, room_id=?, day_of_week=?, start_time=?, end_time=? WHERE schedule_id=?");
                        if ($stmt->execute([$subject_code, $section, $teacher_id, $room_id, $day_of_week, $start_time, $end_time, $schedule_id])) {
                            $message = "Schedule successfully updated!";
                        } else {
                            $message = "Failed to update schedule.";
                            $message_type = "error";
                        }
                    }
                }
            }
        }
    }
}

// Fetch all teachers for dropdown and management
$teacherStmt = $pdo->query("SELECT id, full_name, email, academic_rank FROM users WHERE role = 'teacher'");
$teachers = $teacherStmt->fetchAll();

// Fetch all students for management
$studentStmt = $pdo->query("SELECT id, full_name, email, section FROM users WHERE role = 'student'");
$students = $studentStmt->fetchAll();

// Fetch all schedules for display
$schedStmt = $pdo->query("SELECT c.*, u.full_name as teacher_name FROM class_schedules c LEFT JOIN users u ON c.teacher_id = u.id ORDER BY c.day_of_week, c.start_time");
$schedules = $schedStmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schedules | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--bg-sidebar); font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
        
        /* Modal Styles */
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 2rem; border-radius: 0.5rem; width: 90%; max-width: 400px; color: #333; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .modal-header h3 { margin: 0; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; }
        
        .action-btn { background: none; border: none; cursor: pointer; color: var(--bg-sidebar); display: inline-flex; align-items: center; justify-content: center; padding: 0.25rem; }
        .action-btn:hover { color: var(--accent-blue); }
        .action-btn.delete:hover { color: var(--accent-red); }

        /* Tabs */
        .tab-container { margin-bottom: 1rem; border-bottom: 1px solid #cbd5e1; display: flex; gap: 1rem; }
        .tab-btn { background: none; border: none; padding: 0.5rem 1rem; font-weight: bold; color: #64748b; cursor: pointer; border-bottom: 2px solid transparent; }
        .tab-btn.active { color: var(--accent-blue); border-bottom-color: var(--accent-blue); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="techsupport.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="register_student.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Register Face
        </a>
        <a href="alerts.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
        </a>
        <div style="margin-top: auto; padding: 1rem;">
            <a href="../../logout.php" onclick="confirmLogout(event);" style="display: block; width: 100%; padding: 0.75rem; background: #ffffff; color: #dc2626; font-weight: bold; text-align: center; border-radius: 0.5rem; text-decoration: none; box-sizing: border-box; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Logout</a>
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
    <div class="dashboard-container" style="display: grid; grid-template-columns: 1fr 2fr; gap: 1.5rem; max-width: 1400px; align-items: start;">
        
        <!-- LEFT COLUMN: USER MANAGEMENT -->
        <div class="card" style="position: sticky; top: 1.5rem;">
            <div class="card-header">
                <h2>User Management</h2>
            </div>
            
            <div class="tab-container">
                <button class="tab-btn active" id="btn-tab-students" onclick="switchUserTab('students')">Students</button>
                <button class="tab-btn" id="btn-tab-teachers" onclick="switchUserTab('teachers')">Teachers</button>
            </div>
            
            <div id="tab-students" class="tab-content active">
                <div style="overflow-x: auto; max-height: 70vh; overflow-y: auto;">
                    <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate; font-size: 0.875rem;">
                        <thead>
                            <tr>
                                <th style="border: none; background: #e2e8f0; text-align: left; padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">Name</th>
                                <th style="border: none; background: #e2e8f0; text-align: center; padding: 0.5rem; border-radius: 0 0.5rem 0.5rem 0; width: 90px;">Actions</th>
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
                                <td style="padding: 0.5rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0;">
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
            
            <div id="tab-teachers" class="tab-content">
                <div style="overflow-x: auto; max-height: 70vh; overflow-y: auto;">
                    <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate; font-size: 0.875rem;">
                        <thead>
                            <tr>
                                <th style="border: none; background: #e2e8f0; text-align: left; padding: 0.5rem; border-radius: 0.5rem 0 0 0.5rem;">Name</th>
                                <th style="border: none; background: #e2e8f0; text-align: center; padding: 0.5rem; border-radius: 0 0.5rem 0.5rem 0; width: 90px;">Actions</th>
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
                                <td style="padding: 0.5rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0;">
                                    <button class="action-btn" title="Edit" onclick="openEditUserModal(<?= $tc['id'] ?>, 'teacher', '<?= htmlspecialchars(addslashes($tc['full_name'])) ?>', '<?= htmlspecialchars(addslashes($tc['email'])) ?>', '', '<?= htmlspecialchars(addslashes($tc['academic_rank'] ?? '')) ?>')">
                                        <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
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
        </div>
        
        <!-- RIGHT COLUMN -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- Teacher Registration Form -->
            <div class="card">
                <div class="card-header">
                    <h2>Teacher Registration</h2>
                </div>
                
                <form method="POST" action="">
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
                    <button type="submit" class="btn btn-success" style="width: 100%; border-radius: 0.5rem;">Register Teacher</button>
                </form>
            </div>

            <!-- Add Schedule Form -->
            <div class="card">
                <div class="card-header" style="align-items: center;">
                    <h2 id="form-title">Add Class Schedule</h2>
                </div>
            
            <?php if ($message): ?>
                <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; 
                            color: <?= $message_type === 'success' ? '#15803d' : '#991b1b' ?>; 
                            padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="scheduleForm">
                <input type="hidden" name="action" id="form-action" value="add_schedule">
                <input type="hidden" name="schedule_id" id="form-schedule-id" value="">
                
                <div class="form-group">
                    <label>Subject Code (e.g. IT XXX)</label>
                    <input type="text" name="subject_code" id="form-subject" required>
                </div>
                <div class="form-group">
                    <label>Section (e.g. BSIT X-X)</label>
                    <input type="text" name="section" id="form-section" required>
                </div>
                <div class="form-group">
                    <label>Teacher</label>
                    <input type="text" name="teacher_name" id="form-teacher" list="teachers-list" placeholder="Select or type new teacher..." required autocomplete="off">
                    <datalist id="teachers-list">
                        <?php foreach($teachers as $t): ?>
                            <option value="<?= htmlspecialchars($t['full_name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Room</label>
                    <select name="room_id" id="form-room" required>
                        <?php
                        $all_rooms = array_merge(
                            ['101','102','103','104','105','106','107','108'],
                            ['201','202','203','204','205','206','207','208'],
                            ['301','302','303','304','305','306','307','308','309']
                        );
                        foreach($all_rooms as $r) {
                            echo "<option value=\"ICT $r\">ICT $r</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Day of Week</label>
                    <select name="day_of_week" id="form-day" required>
                        <option value="Monday">Monday</option>
                        <option value="Tuesday">Tuesday</option>
                        <option value="Wednesday">Wednesday</option>
                        <option value="Thursday">Thursday</option>
                        <option value="Friday">Friday</option>
                        <option value="Saturday">Saturday</option>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label>Start Time</label>
                        <select name="start_time" id="form-start" required>
                            <?php
                            $start = strtotime('07:00');
                            $end = strtotime('21:00');
                            for ($i = $start; $i <= $end; $i += 1800) {
                                $time_val = date('H:i:s', $i);
                                $time_label = date('g:i A', $i);
                                echo "<option value=\"$time_val\">$time_label</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>End Time</label>
                        <select name="end_time" id="form-end" required>
                            <?php
                            for ($i = $start; $i <= $end; $i += 1800) {
                                $time_val = date('H:i:s', $i);
                                $time_label = date('g:i A', $i);
                                echo "<option value=\"$time_val\">$time_label</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <button type="submit" id="form-submit-btn" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;">Save Schedule</button>
                <button type="button" id="form-cancel-btn" class="btn" style="width: 100%; border-radius: 0.5rem; margin-top: 0.5rem; display: none; background: #e2e8f0; color: #333;" onclick="resetForm()">Cancel Edit</button>
            </form>
            </div>
        </div>

        <!-- Schedule List -->
        <div class="card">
            <div class="card-header">
                <h2>Current Class Schedules</h2>
            </div>
            <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate;">
                <thead>
                    <tr>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); text-align: left; padding: 0.75rem; border-radius: 0.5rem 0 0 0.5rem;">Subject</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); text-align: left; padding: 0.75rem;">Section</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); text-align: left; padding: 0.75rem;">Teacher</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); text-align: left; padding: 0.75rem;">Room & Time</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); text-align: center; padding: 0.75rem; border-radius: 0 0.5rem 0.5rem 0; width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr><td colspan="4" style="text-align: center; padding: 1rem;">No schedules found.</td></tr>
                    <?php else: ?>
                        <?php foreach($schedules as $sched): ?>
                        <tr style="box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #f8fafc;">
                            <td style="padding: 0.75rem; font-weight: bold; border-radius: 0.5rem 0 0 0.5rem;"><?= htmlspecialchars($sched['subject_code']) ?></td>
                            <td style="padding: 0.75rem;"><?= htmlspecialchars($sched['section']) ?></td>
                            <td style="padding: 0.75rem;"><?= htmlspecialchars($sched['teacher_name']) ?></td>
                            <td style="padding: 0.75rem;">
                                <div style="font-weight: bold; color: var(--accent-blue);"><?= htmlspecialchars($sched['room_id']) ?></div>
                                <div style="font-size: 0.75rem; color: #64748b;"><?= $sched['day_of_week'] ?>, <?= date('g:i A', strtotime($sched['start_time'])) ?> - <?= date('g:i A', strtotime($sched['end_time'])) ?></div>
                            </td>
                            <td style="padding: 0.75rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0;">
                                <button type="button" class="action-btn" onclick="editSchedule(<?= $sched['schedule_id'] ?>, '<?= htmlspecialchars(addslashes($sched['subject_code'])) ?>', '<?= htmlspecialchars(addslashes($sched['section'])) ?>', '<?= htmlspecialchars(addslashes($sched['teacher_name'])) ?>', '<?= htmlspecialchars(addslashes($sched['room_id'])) ?>', '<?= $sched['day_of_week'] ?>', '<?= $sched['start_time'] ?>', '<?= $sched['end_time'] ?>')" title="Edit">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                                <form method="POST" style="display: inline-block;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                    <input type="hidden" name="action" value="delete_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= $sched['schedule_id'] ?>">
                                    <button type="submit" class="action-btn delete" title="Delete">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
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
                <label>Full Name</label>
                <input type="text" id="edit_full_name" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="edit_email" required>
            </div>
            
            <div class="form-group" id="edit_section_group" style="display: none;">
                <label>Section</label>
                <input type="text" id="edit_section">
            </div>
            <div class="form-group" id="edit_rank_group" style="display: none;">
                <label>Academic Rank</label>
                <select id="edit_academic_rank">
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
            <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;">Update User</button>
        </form>
    </div>
</div>

<script>
    function editSchedule(id, subject, section, teacher, room, day, start, end) {
        document.getElementById('form-title').innerText = "Edit Class Schedule";
        document.getElementById('form-action').value = "edit_schedule";
        document.getElementById('form-schedule-id').value = id;
        
        document.getElementById('form-subject').value = subject;
        document.getElementById('form-section').value = section;
        document.getElementById('form-teacher').value = teacher;
        document.getElementById('form-room').value = room;
        document.getElementById('form-day').value = day;
        document.getElementById('form-start').value = start;
        document.getElementById('form-end').value = end;
        
        document.getElementById('form-submit-btn').innerText = "Update Schedule";
        document.getElementById('form-cancel-btn').style.display = "block";
        
        // Scroll to form
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('form-title').innerText = "Add Class Schedule";
        document.getElementById('form-action').value = "add_schedule";
        document.getElementById('form-schedule-id').value = "";
        
        document.getElementById('scheduleForm').reset();
        
        document.getElementById('form-submit-btn').innerText = "Save Schedule";
        document.getElementById('form-cancel-btn').style.display = "none";
    }

    function switchUserTab(tab) {
        document.getElementById('tab-students').classList.remove('active');
        document.getElementById('tab-teachers').classList.remove('active');
        document.getElementById('btn-tab-students').classList.remove('active');
        document.getElementById('btn-tab-teachers').classList.remove('active');
        
        document.getElementById('tab-' + tab).classList.add('active');
        document.getElementById('btn-tab-' + tab).classList.add('active');
    }

    function openPasswordModal(userId, userName) {
        document.getElementById('reset_user_id').value = userId;
        document.getElementById('reset_user_name').innerText = userName;
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
                alert('Password reset successfully!');
                document.getElementById('passwordModal').style.display = 'none';
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while resetting the password.');
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
                alert('User updated successfully!');
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => console.error('Error:', error));
    }

    function deleteUser(userId) {
        if (confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
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
                    alert('User deleted successfully!');
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => console.error('Error:', error));
        }
    }
</script>
<script src="../../assets/js/logout.js"></script>
</body>
</html>
