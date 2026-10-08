<?php
// dashboards/tech_support/schedule.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../../config/database.php';

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM teacher_alerts WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

// Handle form submission
$message = '';
$message_type = 'success'; // or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_schedule') {
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

// Fetch all teachers for dropdown
$teacherStmt = $pdo->query("SELECT id, full_name FROM users WHERE role = 'teacher'");
$teachers = $teacherStmt->fetchAll();

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
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: #fff; font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
        
        .action-btn { background: none; border: none; cursor: pointer; color: var(--bg-sidebar); display: inline-flex; align-items: center; justify-content: center; padding: 0.25rem; }
        .action-btn:hover { color: var(--accent-blue); }
        .action-btn.delete:hover { color: var(--accent-red); }

        .card { background: var(--bg-card); color: #fff; }
        .card-header h2 { color: #fff; }

        .schedule-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .schedule-grid { grid-template-columns: 1fr; }
        }
        
        .loading-spinner {
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid #fff;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            display: inline-block;
            vertical-align: middle;
            margin-right: 0.5rem;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
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
        <a href="user_management.php" class="sidebar-link">
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
    <div class="dashboard-container" style="display: flex; flex-direction: column; width: 100%;">
        
        <div class="schedule-grid">
            
            <!-- Col 1: Forms -->
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                
                <!-- OCR Scan Schedule Form -->
                <div class="card" style="margin: 0; background: linear-gradient(135deg, rgba(59,130,246,0.1) 0%, rgba(37,99,235,0.1) 100%); border: 1px solid rgba(59,130,246,0.3);">
                    <div class="card-header" style="border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                        <h2 style="display: flex; align-items: center; gap: 0.5rem;">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            Scan Schedule
                        </h2>
                    </div>
                    <p style="font-size: 0.875rem; color: #cbd5e1; margin-bottom: 1rem;">Upload a picture of a schedule to automatically extract and fill the form below.</p>
                    
                    <div class="form-group">
                        <input type="file" id="schedule-image" accept="image/*" style="background: rgba(0,0,0,0.2); border: 1px dashed rgba(255,255,255,0.3); padding: 1.5rem; text-align: center; cursor: pointer; color: white;">
                    </div>
                    <button type="button" id="scan-btn" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;" onclick="scanSchedule()">
                        Scan Image
                    </button>
                    <div id="scan-result" style="margin-top: 1rem; font-size: 0.875rem; color: #10b981; display: none;">Scan successful! Data applied below. Please review.</div>
                </div>

                <!-- Add/Edit Class Schedule Form -->
                <div class="card" style="margin: 0; height: 100%;">
                    <div class="card-header" style="align-items: center;">
                        <h2 id="form-title">Add Class Schedule</h2>
                    </div>
                    
                    <?php if ($message): ?>
                        <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; color: <?= $message_type === 'success' ? '#10b981' : '#ef4444' ?>; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>
                
                    <form method="POST" action="" id="scheduleForm" onsubmit="return confirmSave(event, this, 'save this schedule')">
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
                                $hardcoded_rooms = array_merge(
                                    ['101','102','103','104','105','106','107','108'],
                                    ['201','202','203','204','205','206','207','208'],
                                    ['301','302','303','304','305','306','307','308','309']
                                );
                                
                                $doorStmt = $pdo->query("SELECT room_id FROM door_status");
                                $existing_rooms = $doorStmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                $all_rooms = array_unique(array_merge($hardcoded_rooms, $existing_rooms));
                                sort($all_rooms);

                                foreach($all_rooms as $r) {
                                    $val = htmlspecialchars($r);
                                    echo "<option value=\"$val\">$val</option>";
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
                        <button type="submit" id="form-submit-btn" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; margin-top: 1rem;">Save Schedule</button>
                        <button type="button" id="form-cancel-btn" class="btn" style="width: 100%; border-radius: 0.5rem; margin-top: 0.5rem; display: none; background: #e2e8f0; color: #333;" onclick="resetForm()">Cancel Edit</button>
                    </form>
                </div>
            </div>

            <!-- Col 2: Schedule List -->
            <div>
                <div class="card" style="margin: 0; height: 100%; max-height: 85vh; display: flex; flex-direction: column;">
                    <div class="card-header">
                        <h2>Class Schedule</h2>
                    </div>
                    <div style="overflow-y: auto; flex: 1;">
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
                                    <tr><td colspan="5" style="text-align: center; padding: 1rem;">No schedules found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($schedules as $sched): ?>
                                    <tr style="box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #f8fafc;">
                                        <td style="padding: 0.75rem; font-weight: bold; border-radius: 0.5rem 0 0 0.5rem; color: #333;"><?= htmlspecialchars($sched['subject_code']) ?></td>
                                        <td style="padding: 0.75rem; color: #333;"><?= htmlspecialchars($sched['section']) ?></td>
                                        <td style="padding: 0.75rem; color: #333;"><?= htmlspecialchars($sched['teacher_name']) ?></td>
                                        <td style="padding: 0.75rem;">
                                            <div style="font-weight: bold; color: var(--accent-blue);"><?= htmlspecialchars($sched['room_id']) ?></div>
                                            <div style="font-size: 0.75rem; color: #64748b;"><?= $sched['day_of_week'] ?>, <?= date('g:i A', strtotime($sched['start_time'])) ?> - <?= date('g:i A', strtotime($sched['end_time'])) ?></div>
                                        </td>
                                        <td style="padding: 0.75rem; text-align: center; border-radius: 0 0.5rem 0.5rem 0;">
                                            <button type="button" class="action-btn" onclick="editSchedule(<?= $sched['schedule_id'] ?>, '<?= htmlspecialchars(addslashes($sched['subject_code'])) ?>', '<?= htmlspecialchars(addslashes($sched['section'])) ?>', '<?= htmlspecialchars(addslashes($sched['teacher_name'])) ?>', '<?= htmlspecialchars(addslashes($sched['room_id'])) ?>', '<?= $sched['day_of_week'] ?>', '<?= $sched['start_time'] ?>', '<?= $sched['end_time'] ?>')" title="Edit">
                                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                            </button>
                                            <button type="button" class="action-btn delete" title="Delete" onclick="deleteSchedule(<?= $sched['schedule_id'] ?>)">
                                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</main>

<form id="deleteScheduleForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_schedule">
    <input type="hidden" name="schedule_id" id="delete_schedule_id" value="">
</form>

<script>
    function confirmSave(e, form, actionText) {
        e.preventDefault();
        Swal.fire({
            title: 'Are you sure?',
            text: 'Do you want to ' + actionText + '?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes',
            cancelButtonText: 'No',
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#ef4444'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    }

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

    function deleteSchedule(scheduleId) {
        Swal.fire({
            title: 'Are you sure?',
            text: 'You will not be able to recover this schedule!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_schedule_id').value = scheduleId;
                document.getElementById('deleteScheduleForm').submit();
            }
        });
    }

    function scanSchedule() {
        const fileInput = document.getElementById('schedule-image');
        if (!fileInput.files.length) {
            Swal.fire('Error', 'Please select an image file first.', 'error');
            return;
        }

        const formData = new FormData();
        formData.append('image', fileInput.files[0]);

        const btn = document.getElementById('scan-btn');
        btn.innerHTML = '<span class="loading-spinner"></span> Scanning...';
        btn.disabled = true;

        fetch(`http://${window.location.hostname}:8000/ocr_schedule`, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const parsed = data.data;
                
                if (parsed.subject) document.getElementById('form-subject').value = parsed.subject;
                if (parsed.section) document.getElementById('form-section').value = parsed.section;
                if (parsed.teacher) document.getElementById('form-teacher').value = parsed.teacher;
                if (parsed.room) document.getElementById('form-room').value = parsed.room;
                if (parsed.day) document.getElementById('form-day').value = parsed.day;
                
                // Assuming format HH:MM:SS is returned for start and end
                if (parsed.start_time) {
                    const startEl = document.getElementById('form-start');
                    for (let opt of startEl.options) {
                        if (opt.value.startsWith(parsed.start_time)) {
                            startEl.value = opt.value;
                            break;
                        }
                    }
                }
                if (parsed.end_time) {
                    const endEl = document.getElementById('form-end');
                    for (let opt of endEl.options) {
                        if (opt.value.startsWith(parsed.end_time)) {
                            endEl.value = opt.value;
                            break;
                        }
                    }
                }

                document.getElementById('scan-result').style.display = 'block';
                document.getElementById('scan-result').innerText = 'Scan successful! Data applied below. Please review.';
            } else {
                Swal.fire('Error', data.message || 'Failed to extract data from image.', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire('Error', 'Failed to connect to the OCR server.', 'error');
        })
        .finally(() => {
            btn.innerHTML = 'Scan Image';
            btn.disabled = false;
        });
    }
</script>
<script src="../../assets/js/logout.js"></script>
</body>
</html>
