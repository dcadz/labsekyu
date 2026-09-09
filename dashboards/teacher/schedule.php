<?php
// dashboards/teacher/schedule.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['teacher']);
require_once __DIR__ . '/../../core/db_connect.php';

$teacher_id = $_SESSION['user_id'];
$message = '';
$message_type = 'success';

// Fetch system alerts count
$sysAlertsCountStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sa.alert_id) 
    FROM system_alerts sa
    JOIN users u ON u.id = :teacher_id
    WHERE sa.location IN (
        SELECT DISTINCT c.room_id 
        FROM class_schedules c 
        WHERE c.teacher_id = :teacher_id
    )
    AND (u.last_alert_read IS NULL OR sa.created_at > u.last_alert_read)
");
$sysAlertsCountStmt->execute([':teacher_id' => $teacher_id]);
$teacherAlertCount = $sysAlertsCountStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete') {
        $schedule_id = $_POST['schedule_id'] ?? '';
        if ($schedule_id) {
            $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE schedule_id = ? AND teacher_id = ?");
            if ($stmt->execute([$schedule_id, $teacher_id])) {
                $message = "Schedule deleted successfully!";
            } else {
                $message = "Error deleting schedule.";
                $message_type = "error";
            }
        }
    } elseif ($action === 'add' || $action === 'edit') {
        $subject_code = trim($_POST['subject_code'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $room_id = trim($_POST['room_id'] ?? 'ICT Lab 1');
        $day_of_week = $_POST['day_of_week'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $schedule_id = $_POST['schedule_id'] ?? '';

        if ($subject_code && $section && $room_id && $day_of_week && $start_time && $end_time) {
            
            // Check overlaps
            $overlapQuery = "SELECT schedule_id FROM class_schedules 
                             WHERE room_id = ? AND day_of_week = ? 
                             AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))";
            $overlapParams = [$room_id, $day_of_week, $end_time, $start_time, $end_time, $start_time, $start_time, $end_time];
            
            if ($action === 'edit' && !empty($schedule_id)) {
                $overlapQuery .= " AND schedule_id != ?";
                $overlapParams[] = $schedule_id;
            }

            $overlapStmt = $pdo->prepare($overlapQuery);
            $overlapStmt->execute($overlapParams);
            
            if ($overlapStmt->fetch()) {
                $message = "Error: This schedule conflicts with an existing class in $room_id on $day_of_week during this time!";
                $message_type = "error";
            } else {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO class_schedules (subject_code, section, teacher_id, room_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt->execute([$subject_code, $section, $teacher_id, $room_id, $day_of_week, $start_time, $end_time])) {
                        $message = "Schedule added successfully!";
                    } else {
                        $message = "Error adding schedule.";
                        $message_type = "error";
                    }
                } else if ($action === 'edit') {
                    $stmt = $pdo->prepare("UPDATE class_schedules SET subject_code=?, section=?, room_id=?, day_of_week=?, start_time=?, end_time=? WHERE schedule_id=? AND teacher_id=?");
                    if ($stmt->execute([$subject_code, $section, $room_id, $day_of_week, $start_time, $end_time, $schedule_id, $teacher_id])) {
                        $message = "Schedule updated successfully!";
                    } else {
                        $message = "Error updating schedule.";
                        $message_type = "error";
                    }
                }
            }
        } else {
            $message = "Please fill in all required fields.";
            $message_type = "error";
        }
    }
}

// Fetch teacher's current schedules
$schedStmt = $pdo->prepare("SELECT * FROM class_schedules WHERE teacher_id = ? ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC");
$schedStmt->execute([$teacher_id]);
$schedules = $schedStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Schedule | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        .btn-submit {
            grid-column: span 2;
            padding: 0.75rem;
            background: var(--accent-green);
            color: #000;
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
        }
        .btn-cancel {
            background: #e2e8f0;
            color: #333;
            margin-top: 0.5rem;
            display: none;
        }
        .action-btn { background: none; border: none; cursor: pointer; color: var(--text-secondary); display: inline-flex; align-items: center; justify-content: center; padding: 0.25rem; }
        .action-btn:hover { color: var(--accent-blue); }
        .action-btn.delete:hover { color: var(--accent-red); }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="teacher.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="alerts.php" class="sidebar-link">
            <svg class="<?= ($teacherAlertCount > 0) ? 'glow-alert-icon' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if (isset($teacherAlertCount) && $teacherAlertCount > 0): ?>
                <span style="background: var(--accent-red); color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?= $teacherAlertCount ?></span>
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
        <div class="admin-badge" style="background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); font-size: 0.875rem;">TEACHER</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container" style="max-width: 1000px; display: grid; gap: 2rem; grid-template-columns: 1fr 1fr;">
        
        <!-- Add/Edit Schedule Form -->
        <div class="card" style="height: fit-content;">
            <div class="card-header">
                <h2 id="form-title">Add Class Schedule</h2>
            </div>
            
            <?php if ($message): ?>
                <div style="background: <?= $message_type === 'success' ? 'rgba(34, 197, 94, 0.2)' : 'rgba(239, 68, 68, 0.2)' ?>; color: <?= $message_type === 'success' ? '#10b981' : '#ef4444' ?>; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="scheduleForm">
                <input type="hidden" name="action" id="form-action" value="add">
                <input type="hidden" name="schedule_id" id="form-schedule-id" value="">
                
                <div class="form-group">
                    <label>Subject Code</label>
                    <input type="text" name="subject_code" id="form-subject" placeholder="e.g. IT 312" required>
                </div>
                
                <div class="form-group">
                    <label>Section</label>
                    <input type="text" name="section" id="form-section" placeholder="e.g. BSIT 3-A" required>
                </div>
                
                <div class="form-group">
                    <label>Room</label>
                    <select name="room_id" id="room_select" required>
                        <option value="">Loading rooms...</option>
                    </select>
                </div>
                
                <div class="form-grid">
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
                    
                    <div class="form-group">
                        <label>Start Time</label>
                        <select name="start_time" id="form-start" required>
                            <?php
                            $start = strtotime('07:00');
                            $end = strtotime('21:00');
                            for ($i = $start; $i <= $end; $i += 1800) {
                                $val = date('H:i:s', $i);
                                $label = date('h:i A', $i);
                                echo "<option value=\"$val\">$label</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="grid-column: 2;">
                        <label>End Time</label>
                        <select name="end_time" id="form-end" required>
                            <?php
                            for ($i = $start; $i <= $end; $i += 1800) {
                                $val = date('H:i:s', $i);
                                $label = date('h:i A', $i);
                                echo "<option value=\"$val\">$label</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit" id="form-submit-btn">Save Schedule</button>
                <button type="button" class="btn-submit btn-cancel" id="form-cancel-btn" onclick="resetForm()">Cancel Edit</button>
            </form>
        </div>

        <!-- My Schedules -->
        <div class="card" style="height: fit-content; max-height: 85vh; display: flex; flex-direction: column;">
            <div class="card-header">
                <h2>My Schedules</h2>
            </div>
            <div style="overflow-y: auto;">
                <table class="modern-table" style="width: 100%; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <th style="padding: 1rem; color: var(--text-secondary);">Class</th>
                            <th style="padding: 1rem; color: var(--text-secondary);">Day & Time</th>
                            <th style="padding: 1rem; color: var(--text-secondary); text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($schedules)): ?>
                            <tr>
                                <td colspan="3" style="padding: 1rem; text-align: center; color: var(--text-secondary);">No schedules added yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($schedules as $sched): ?>
                                <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                    <td style="padding: 1rem;">
                                        <div style="font-weight: 500; color: #fff;"><?= htmlspecialchars($sched['subject_code']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($sched['section']) ?> (<?= htmlspecialchars($sched['room_id']) ?>)</div>
                                    </td>
                                    <td style="padding: 1rem; color: #fff;">
                                        <div><?= htmlspecialchars($sched['day_of_week']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= date('g:i A', strtotime($sched['start_time'])) ?> - <?= date('g:i A', strtotime($sched['end_time'])) ?></div>
                                    </td>
                                    <td style="padding: 1rem; text-align: center;">
                                        <button type="button" class="action-btn" onclick="editSchedule(<?= $sched['schedule_id'] ?>, '<?= htmlspecialchars(addslashes($sched['subject_code'])) ?>', '<?= htmlspecialchars(addslashes($sched['section'])) ?>', '<?= htmlspecialchars(addslashes($sched['room_id'])) ?>', '<?= $sched['day_of_week'] ?>', '<?= $sched['start_time'] ?>', '<?= $sched['end_time'] ?>')" title="Edit">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                        </button>
                                        <button type="button" class="action-btn delete" onclick="deleteSchedule(<?= $sched['schedule_id'] ?>)" title="Delete">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
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
</main>

<form id="deleteScheduleForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="schedule_id" id="delete_schedule_id" value="">
</form>

<script src="../../assets/js/logout.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const roomSelect = document.getElementById('room_select');
    try {
        const response = await fetch('../../api/get_rooms.php');
        const data = await response.json();
        
        if (data.success && data.data.length > 0) {
            roomSelect.innerHTML = '';
            data.data.forEach(room => {
                const option = document.createElement('option');
                option.value = room.room_id;
                option.textContent = room.room_id;
                roomSelect.appendChild(option);
            });
            // Try to re-select if we were editing and room list loaded after
            if (window.pendingRoomSelect) {
                roomSelect.value = window.pendingRoomSelect;
                window.pendingRoomSelect = null;
            }
        } else {
            roomSelect.innerHTML = '<option value="ICT Lab 1">ICT Lab 1 (Default)</option>';
        }
    } catch (err) {
        console.error('Error fetching rooms:', err);
        roomSelect.innerHTML = '<option value="ICT Lab 1">ICT Lab 1 (Fallback)</option>';
    }
});

function editSchedule(id, subject, section, room, day, start, end) {
    document.getElementById('form-title').innerText = "Edit Class Schedule";
    document.getElementById('form-action').value = "edit";
    document.getElementById('form-schedule-id').value = id;
    
    document.getElementById('form-subject').value = subject;
    document.getElementById('form-section').value = section;
    
    const roomSelect = document.getElementById('room_select');
    if (roomSelect.options.length > 1) {
        roomSelect.value = room;
    } else {
        window.pendingRoomSelect = room; // Wait for fetch to complete
    }
    
    document.getElementById('form-day').value = day;
    document.getElementById('form-start').value = start;
    document.getElementById('form-end').value = end;
    
    document.getElementById('form-submit-btn').innerText = "Update Schedule";
    document.getElementById('form-cancel-btn').style.display = "block";
    
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    document.getElementById('form-title').innerText = "Add Class Schedule";
    document.getElementById('form-action').value = "add";
    document.getElementById('form-schedule-id').value = "";
    
    document.getElementById('scheduleForm').reset();
    
    document.getElementById('form-submit-btn').innerText = "Add Schedule";
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
</script>
</body>
</html>
