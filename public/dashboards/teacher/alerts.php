<?php
// dashboards/teacher/alerts.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['teacher']);
require_once __DIR__ . '/../../../config/database.php';

$teacher_id = $_SESSION['user_id'];
$sweetalert_type = '';
$sweetalert_message = '';

// Update last read timestamp for this teacher so the glow stops
$pdo->prepare("UPDATE users SET last_alert_read = CURRENT_TIMESTAMP WHERE id = ?")->execute([$teacher_id]);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'report_tech') {
        $room = $_POST['room'] ?? '';
        $message = trim($_POST['message'] ?? '');
        if (!empty($room) && !empty($message)) {
            $ins = $pdo->prepare("INSERT INTO teacher_alerts (teacher_id, room, message) VALUES (?, ?, ?)");
            if ($ins->execute([$teacher_id, $room, $message])) {
                $sweetalert_type = 'success';
                $sweetalert_message = 'Ticket submitted to Tech Support.';
            } else {
                $sweetalert_type = 'error';
                $sweetalert_message = 'Failed to submit ticket.';
            }
        }
    } elseif ($_POST['action'] === 'delete_report') {
        $report_id = $_POST['report_id'] ?? '';
        if ($report_id) {
            $del = $pdo->prepare("DELETE FROM teacher_alerts WHERE id = ? AND teacher_id = ?");
            if ($del->execute([$report_id, $teacher_id])) {
                $sweetalert_type = 'success';
                $sweetalert_message = 'Report deleted successfully.';
            } else {
                $sweetalert_type = 'error';
                $sweetalert_message = 'Failed to delete report.';
            }
        }
    }
}

// Fetch all available rooms from tech support (door_status)
$roomStmt = $pdo->query("SELECT DISTINCT room_id as room FROM door_status ORDER BY room_id ASC");
$all_rooms = $roomStmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch teacher's ticket history
$myReportsStmt = $pdo->prepare("SELECT * FROM teacher_alerts WHERE teacher_id = ? ORDER BY created_at DESC");
$myReportsStmt->execute([$teacher_id]);
$my_reports = $myReportsStmt->fetchAll();

// Fetch alerts (Late, Dropped/Absent) for classes taught by this teacher
$stmt = $pdo->prepare("
    SELECT a.status, a.entry_timestamp, a.log_date, u.full_name, c.subject_code, c.section
    FROM attendance_logs a
    JOIN users u ON a.student_id = u.id
    JOIN class_schedules c ON a.schedule_id = c.schedule_id
    WHERE c.teacher_id = :teacher_id AND a.status IN ('Late', 'Dropped', 'Absent')
    ORDER BY a.entry_timestamp DESC, a.log_date DESC
    LIMIT 50
");
$stmt->execute([':teacher_id' => $teacher_id]);
$alerts = $stmt->fetchAll();

// Fetch system alerts targeted to rooms assigned to this teacher
$sysStmt = $pdo->prepare("
    SELECT DISTINCT sa.alert_id, sa.severity, sa.location, sa.event_type, sa.created_at
    FROM system_alerts sa
    WHERE sa.location IN (
        SELECT DISTINCT c.room_id 
        FROM class_schedules c 
        WHERE c.teacher_id = :teacher_id
    )
    ORDER BY sa.created_at DESC
    LIMIT 20
");
$sysStmt->execute([':teacher_id' => $teacher_id]);
$system_alerts = $sysStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .alert-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .icon-late { background: rgba(234, 179, 8, 0.2); color: var(--accent-yellow); }
        .icon-drop { background: rgba(239, 68, 68, 0.2); color: var(--accent-red); }
        .icon-High { background: rgba(239, 68, 68, 0.2); color: var(--accent-red); }
        .icon-Med { background: rgba(234, 179, 8, 0.2); color: var(--accent-yellow); }
        .icon-Low { background: rgba(34, 197, 94, 0.2); color: var(--accent-green); }
        
        .icon-ticket { background: rgba(59, 130, 246, 0.2); color: #3b82f6; } /* blue */
        .icon-resolved { background: rgba(34, 197, 94, 0.2); color: var(--accent-green); }
        
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; margin-bottom: 0.25rem; }
        .alert-desc { font-size: 0.875rem; color: var(--text-secondary); }
        .alert-time { font-size: 0.75rem; color: var(--text-secondary); text-align: right; }
        
        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            height: 100%;
        }

        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--bg-sidebar); font-weight: bold; }
        .form-group select, .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-sizing: border-box; }
        
        @media (max-width: 1024px) {
            .grid-layout { grid-template-columns: 1fr; }
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
        <a href="schedule.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="alerts.php" class="sidebar-link active">
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
        <div class="admin-badge" style="background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); font-size: 0.875rem;">TEACHER</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container" style="max-width: 1200px;">
        
        <h1 style="font-size: 2rem; margin-bottom: 2rem; color: var(--text-primary);">Alerts & Reporting</h1>

        <div class="grid-layout">
            <!-- Left Column: Report Form -->
            <div class="card">
                <h2 style="font-size: 1.25rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Report to Tech Support</h2>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="report_tech">
                    <div class="form-group">
                        <label style="color: var(--text-primary); font-weight: 600;">Select Room</label>
                        <select name="room" required style="background: #ffffff; color: var(--text-primary); border: 1px solid var(--border-color); width: 100%; padding: 0.75rem; border-radius: 0.5rem;">
                            <option value="">-- Choose a Room --</option>
                            <?php foreach($all_rooms as $rm): ?>
                                <option value="<?= htmlspecialchars($rm) ?>"><?= htmlspecialchars($rm) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="color: var(--text-primary); font-weight: 600;">Message / Issue Description</label>
                        <textarea name="message" rows="4" required placeholder="Describe the issue..." style="background: #ffffff; color: var(--text-primary); border: 1px solid var(--border-color); width: 100%; padding: 0.75rem; border-radius: 0.5rem;"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Submit Ticket</button>
                </form>
            </div>

            <!-- Right Column: My Reports -->
            <div class="card" style="overflow-y: auto; max-height: 400px;">
                <h2 style="font-size: 1.25rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">My Reports</h2>
                
                <?php if(empty($my_reports)): ?>
                    <div style="text-align: center; color: var(--text-secondary); padding: 2rem;">
                        <p>No reports submitted yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($my_reports as $rep): 
                        $isResolved = ($rep['status'] === 'resolved');
                        $iconClass = $isResolved ? 'icon-resolved' : 'icon-ticket';
                        $iconSvg = $isResolved 
                            ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>'
                            : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>';
                    ?>
                    <div class="alert-card" style="border-left: 4px solid <?= $isResolved ? 'var(--accent-green)' : '#3b82f6' ?>;">
                        <div class="alert-icon <?= $iconClass ?>">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <?= $iconSvg ?>
                            </svg>
                        </div>
                        <div class="alert-content">
                            <div class="alert-title">
                                <?= htmlspecialchars($rep['room']) ?> - <span style="color: <?= $isResolved ? 'var(--accent-green)' : '#3b82f6' ?>;"><?= strtoupper($rep['status']) ?></span>
                            </div>
                            <div class="alert-desc">
                                <?= htmlspecialchars($rep['message']) ?>
                            </div>
                        </div>
                        <div class="alert-time" style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
                            <span><?= date('M d, Y g:i A', strtotime($rep['created_at'])) ?></span>
                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this report?');">
                                <input type="hidden" name="action" value="delete_report">
                                <input type="hidden" name="report_id" value="<?= $rep['id'] ?>">
                                <button type="submit" style="background: none; border: none; color: var(--accent-red); cursor: pointer; padding: 0.25rem;">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if(!empty($system_alerts)): ?>
            <h2 style="font-size: 1.25rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">System Dispatch (Tech Support)</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
            <?php foreach($system_alerts as $sa): 
                $sev = $sa['severity'];
                $iconClass = "icon-{$sev}";
                $iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                if($sev == 'High') $iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>';
            ?>
            <div class="alert-card" style="border-left: 4px solid <?= $sev == 'High' ? 'var(--accent-red)' : ($sev == 'Med' ? 'var(--accent-yellow)' : 'var(--accent-green)') ?>; margin-bottom: 0;">
                <div class="alert-icon <?= $iconClass ?>">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $iconSvg ?>
                    </svg>
                </div>
                <div class="alert-content">
                    <div class="alert-title">
                        <?= htmlspecialchars($sa['location']) ?> - <span style="color: var(--text-primary);"><?= htmlspecialchars($sa['severity']) ?> Priority</span>
                    </div>
                    <div class="alert-desc">
                        <?= htmlspecialchars($sa['event_type']) ?>
                    </div>
                </div>
                <div class="alert-time">
                    <?= date('M d, Y g:i A', strtotime($sa['created_at'])) ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <h2 style="font-size: 1.25rem; margin-top: 2rem; margin-bottom: 1rem; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Student Attendance</h2>
        <?php if(empty($alerts)): ?>
            <div style="text-align: center; color: var(--text-secondary); padding: 3rem;">
                <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 1rem; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <p>All good! No late or dropped students found.</p>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem;">
            <?php foreach($alerts as $alert): 
                $isLate = ($alert['status'] === 'Late');
                $iconClass = $isLate ? 'icon-late' : 'icon-drop';
                $iconSvg = $isLate 
                    ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>' 
                    : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>';
            ?>
            <div class="alert-card" style="margin-bottom: 0;">
                <div class="alert-icon <?= $iconClass ?>">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $iconSvg ?>
                    </svg>
                </div>
                <div class="alert-content">
                    <div class="alert-title">
                        <?= htmlspecialchars($alert['full_name']) ?> - <span style="color: <?= $isLate ? 'var(--accent-yellow)' : 'var(--accent-red)' ?>;"><?= strtoupper($alert['status']) ?></span>
                    </div>
                    <div class="alert-desc">
                        <?= htmlspecialchars($alert['subject_code']) ?> (<?= htmlspecialchars($alert['section']) ?>)
                    </div>
                </div>
                <div class="alert-time">
                    <div><?= date('M d, Y', strtotime($alert['log_date'])) ?></div>
                    <?php if($alert['entry_timestamp']): ?>
                        <div><?= date('g:i A', strtotime($alert['entry_timestamp'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</main>

<script src="../../assets/js/logout.js"></script>
<script>
<?php if ($sweetalert_type): ?>
    Swal.fire({
        icon: '<?= $sweetalert_type ?>',
        title: '<?= $sweetalert_type === 'success' ? 'Success!' : 'Error!' ?>',
        text: '<?= addslashes($sweetalert_message) ?>',
        confirmButtonColor: '#3b82f6'
    });
<?php endif; ?>
</script>
</body>
</html>
