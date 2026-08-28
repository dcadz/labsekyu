<?php
// dashboards/teacher/schedule.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['teacher']);
require_once __DIR__ . '/../../core/db_connect.php';

$teacher_id = $_SESSION['user_id'];
$message = '';

// Fetch system alerts count targeted to rooms assigned to this teacher
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


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $subject_code = trim($_POST['subject_code'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $room_id = trim($_POST['room_id'] ?? 'ICT Lab 1'); // Defaulting to ICT Lab 1 for now
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';

    if ($subject_code && $section && $room_id && $day_of_week && $start_time && $end_time) {
        $stmt = $pdo->prepare("INSERT INTO class_schedules (subject_code, section, teacher_id, room_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$subject_code, $section, $teacher_id, $room_id, $day_of_week, $start_time, $end_time])) {
            $message = "<div style='color: var(--accent-green); margin-bottom: 1rem;'>Schedule added successfully!</div>";
        } else {
            $message = "<div style='color: var(--accent-red); margin-bottom: 1rem;'>Error adding schedule.</div>";
        }
    } else {
        $message = "<div style='color: var(--accent-red); margin-bottom: 1rem;'>Please fill in all required fields.</div>";
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
        
        <!-- Add Schedule Form -->
        <div class="card">
            <div class="card-header">
                <h2>Add Class Schedule</h2>
            </div>
            <?= $message ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Subject Code</label>
                    <input type="text" name="subject_code" placeholder="e.g. IT 312" required>
                </div>
                
                <div class="form-group">
                    <label>Section</label>
                    <input type="text" name="section" placeholder="e.g. BSIT 3-A" required>
                </div>
                
                <div class="form-group">
                    <label>Room</label>
                    <input type="text" name="room_id" value="ICT Lab 1" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Day of Week</label>
                        <select name="day_of_week" required>
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
                        <input type="time" name="start_time" required>
                    </div>
                    
                    <div class="form-group" style="grid-column: 2;">
                        <label>End Time</label>
                        <input type="time" name="end_time" required>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">Add Schedule</button>
            </form>
        </div>

        <!-- My Schedules -->
        <div class="card" style="height: fit-content;">
            <div class="card-header">
                <h2>My Schedules</h2>
            </div>
            <table class="modern-table" style="width: 100%; text-align: left;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 1rem; color: var(--text-secondary);">Class</th>
                        <th style="padding: 1rem; color: var(--text-secondary);">Day & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="2" style="padding: 1rem; text-align: center; color: var(--text-secondary);">No schedules added yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($schedules as $sched): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: 1rem;">
                                    <div style="font-weight: 500;"><?= htmlspecialchars($sched['subject_code']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= htmlspecialchars($sched['section']) ?> (<?= htmlspecialchars($sched['room_id']) ?>)</div>
                                </td>
                                <td style="padding: 1rem;">
                                    <div><?= htmlspecialchars($sched['day_of_week']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);"><?= date('g:i A', strtotime($sched['start_time'])) ?> - <?= date('g:i A', strtotime($sched['end_time'])) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
    </div>
</main>

<script src="../../assets/js/logout.js"></script>
</body>
</html>
