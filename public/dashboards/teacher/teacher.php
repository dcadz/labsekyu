<?php
// dashboards/teacher/index.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['teacher']);

require_once __DIR__ . '/../../../config/database.php';

$teacher_id = $_SESSION['user_id'];
$today = date('Y-m-d');

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

$day_of_week = date('l');

// Find active schedule for this teacher today (including 15-min grace period)
$activeSchedStmt = $pdo->prepare("SELECT schedule_id, subject_code, section, start_time, end_time, room_id FROM class_schedules WHERE teacher_id = :teacher_id AND day_of_week = :day AND CURRENT_TIME BETWEEN SUBTIME(start_time, '00:15:00') AND ADDTIME(end_time, '00:15:00') LIMIT 1");
$activeSchedStmt->execute([':teacher_id' => $teacher_id, ':day' => $day_of_week]);
$active_schedule = $activeSchedStmt->fetch();

$current_room = $active_schedule ? $active_schedule['room_id'] : null;

// Fetch Door Status
$door_locked = true;
if ($current_room) {
    $doorStmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = ?");
    $doorStmt->execute([$current_room]);
    $door = $doorStmt->fetch();
    $door_locked = $door ? (bool)$door['is_locked'] : true;
}

$attendance_feed = [];
$enrolled_count = 0; 
$present_count = 0;
$late_count = 0;
$absent_count = 0;

if ($active_schedule) {
    $enrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND section=?");
    $enrStmt->execute([$active_schedule['section']]);
    $enrolled_count = (int)$enrStmt->fetchColumn();
    
    $feedStmt = $pdo->prepare("
        SELECT a.*, u.full_name 
        FROM attendance_logs a
        JOIN users u ON a.student_id = u.id
        WHERE a.schedule_id = :sched_id AND a.log_date = :today
        ORDER BY a.entry_timestamp DESC
    ");
    $feedStmt->execute([':sched_id' => $active_schedule['schedule_id'], ':today' => $today]);
    $attendance_feed = $feedStmt->fetchAll();
    
    foreach ($attendance_feed as $log) {
        if ($log['status'] === 'Present') $present_count++;
        elseif ($log['status'] === 'Late') $late_count++;
        elseif ($log['status'] === 'Absent' || $log['status'] === 'Dropped') $absent_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script>
        let teacherPulseTimer = null;
        let teacherPulseSeconds = 5;

        async function toggleDoor(action, roomId) {
            if (!roomId) {
                alert('No active room assigned right now.');
                return;
            }
            const btn = document.getElementById('teacher-door-btn');
            const badge = document.getElementById('teacher-door-badge');

            if (action === 'unlock') {
                if (btn) btn.disabled = true;

                if (teacherPulseTimer) clearInterval(teacherPulseTimer);
                teacherPulseSeconds = 5;

                const updateTeacherPulse = () => {
                    if (badge) {
                        badge.className = 'door-badge unlocked';
                        badge.textContent = `UNLOCKED (Closing in ${teacherPulseSeconds}s...)`;
                    }
                };
                updateTeacherPulse();

                teacherPulseTimer = setInterval(async () => {
                    teacherPulseSeconds--;
                    if (teacherPulseSeconds > 0) {
                        updateTeacherPulse();
                    } else {
                        clearInterval(teacherPulseTimer);
                        teacherPulseTimer = null;

                        // Auto-revert UI
                        if (badge) {
                            badge.className = 'door-badge locked';
                            badge.textContent = 'LOCKED - ' + roomId;
                        }
                        if (btn) {
                            btn.disabled = false;
                            btn.setAttribute('onclick', `toggleDoor('unlock', '${roomId}')`);
                            const unlockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>`;
                            btn.innerHTML = unlockSvg + "Unlock Door";
                        }

                        // Sync locked state back to database
                        try {
                            await fetch('../../api/update_lock_status.php?status=LOCKED&room=' + encodeURIComponent(roomId));
                        } catch (e) {}
                    }
                }, 1000);

                try {
                    const res = await fetch('../../api/toggle_door.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ room_id: roomId, action: 'unlock' })
                    });
                    const data = await res.json();
                    if (!data.success) {
                        alert('Error: ' + data.error);
                        if (teacherPulseTimer) {
                            clearInterval(teacherPulseTimer);
                            teacherPulseTimer = null;
                        }
                        if (btn) btn.disabled = false;
                        location.reload();
                    }
                } catch (err) {
                    alert('Request failed');
                    if (teacherPulseTimer) {
                        clearInterval(teacherPulseTimer);
                        teacherPulseTimer = null;
                    }
                    if (btn) btn.disabled = false;
                    location.reload();
                }
            } else {
                // Manual Immediate Lock
                if (teacherPulseTimer) {
                    clearInterval(teacherPulseTimer);
                    teacherPulseTimer = null;
                }
                if (badge) {
                    badge.className = 'door-badge locked';
                    badge.textContent = 'LOCKED - ' + roomId;
                }
                if (btn) {
                    btn.disabled = false;
                    btn.setAttribute('onclick', `toggleDoor('unlock', '${roomId}')`);
                    const unlockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>`;
                    btn.innerHTML = unlockSvg + "Unlock Door";
                }
                try {
                    await fetch('../../api/toggle_door.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ room_id: roomId, action: 'lock' })
                    });
                } catch (err) {}
            }
        }
    </script>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="teacher.php" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="alerts.php" class="sidebar-link">
            <svg class="<?= ($teacherAlertCount > 0) ? 'glow-alert-icon' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if ($teacherAlertCount > 0): ?>
                <span style="background: var(--accent-red); color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?= $teacherAlertCount ?></span>
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
        <div class="admin-badge" style="background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); font-size: 0.875rem;">TEACHER</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container layout-3col" style="max-width: 100%;">
        
        <!-- Left Column -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <!-- Live Camera Feed -->
            <div class="card">
                <div class="card-header">
                    <h2>Live Camera Feed</h2>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <div id="teacher-cam-a" style="flex:1; background: #000; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 0.875rem; position: relative; aspect-ratio: 4/3; overflow: hidden;">
                        <span style="position: absolute; bottom: 0.5rem; left: 0; right: 0; text-align: center; z-index: 10; background: rgba(0,0,0,0.5); padding: 0.25rem;">Cam A - Entry - Online</span>
                    </div>
                    <div id="teacher-cam-b" style="flex:1; background: #000; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 0.875rem; position: relative; aspect-ratio: 4/3; overflow: hidden;">
                        <span style="position: absolute; bottom: 0.5rem; left: 0; right: 0; text-align: center; z-index: 10; background: rgba(0,0,0,0.5); padding: 0.25rem;">Cam B - Exit - Online</span>
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 0.5rem; font-weight: 600; text-align: center; font-size: 0.875rem;">
                    <div style="flex:1;">Camera A</div>
                    <div style="flex:1;">Camera B</div>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const roomName = "<?= $active_schedule ? htmlspecialchars($active_schedule['room_id'] ?? 'ICT Lab 1') : 'ICT Lab 1' ?>";
                    
                    const camA = document.getElementById('teacher-cam-a');
                    const camB = document.getElementById('teacher-cam-b');
                    
                    // Load Camera A (IN)
                    const urlA = `http://${window.location.hostname}:8000/video_feed/IN?room=` + encodeURIComponent(roomName) + '&t=' + new Date().getTime();
                    const directA = `http://192.168.0.87:81/stream?t=` + new Date().getTime();
                    camA.innerHTML = `<img src="${urlA}" style="width:100%; height:100%; object-fit:cover;" onerror="if(this.src!=='${directA}'){this.src='${directA}';}else{this.outerHTML='<div style=\\'display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; color:var(--accent-red);\\'><svg width=\\'40\\' height=\\'40\\' fill=\\'none\\' stroke=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path stroke-linecap=\\'round\\' stroke-linejoin=\\'round\\' stroke-width=\\'2\\' d=\\'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636\\'></path></svg><span style=\\'margin-top:0.5rem\\'>Offline - No camera detected</span></div>';}">
                                      <span style="position: absolute; bottom: 0.5rem; left: 0; right: 0; text-align: center; z-index: 10; background: rgba(0,0,0,0.5); color: #fff; padding: 0.25rem;">Cam A - Entry</span>`;
                    
                    // Load Camera B (OUT)
                    const urlB = `http://${window.location.hostname}:8000/video_feed/OUT?room=` + encodeURIComponent(roomName) + '&t=' + new Date().getTime();
                    const directB = `http://192.168.0.149:81/stream?t=` + new Date().getTime();
                    camB.innerHTML = `<img src="${urlB}" style="width:100%; height:100%; object-fit:cover;" onerror="if(this.src!=='${directB}'){this.src='${directB}';}else{this.outerHTML='<div style=\\'display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; color:var(--accent-red);\\'><svg width=\\'40\\' height=\\'40\\' fill=\\'none\\' stroke=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path stroke-linecap=\\'round\\' stroke-linejoin=\\'round\\' stroke-width=\\'2\\' d=\\'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636\\'></path></svg><span style=\\'margin-top:0.5rem\\'>Offline - No camera detected</span></div>';}">
                                      <span style="position: absolute; bottom: 0.5rem; left: 0; right: 0; text-align: center; z-index: 10; background: rgba(0,0,0,0.5); color: #fff; padding: 0.25rem;">Cam B - Exit</span>`;
                });
            </script>

            <!-- Room Utilization -->
            <div class="card">
                <div class="card-header">
                    <h2>Room Utilization</h2>
                </div>
                <div style="display: flex; gap: 1rem; background: #e2e8f0; border-radius: 0.5rem; padding: 1rem; color: #000;">
                    <div style="flex:1; text-align: center;">
                        <div style="font-size: 0.875rem; color: var(--bg-sidebar); font-weight: 600; margin-bottom: 0.25rem;">Room Number</div>
                        <div style="font-size: 1.5rem; color: var(--bg-sidebar); font-weight: 700;"><?= $active_schedule ? htmlspecialchars($active_schedule['room_id']) : 'No Room Assigned' ?></div>
                    </div>
                    <div style="flex:2; text-align: left; padding-left: 1rem; border-left: 2px solid #cbd5e1;">
                        <div style="font-size: 0.75rem; color: var(--bg-sidebar); font-weight: 600; margin-bottom: 0.25rem;">Current Class</div>
                        <?php if($active_schedule): ?>
                            <div style="font-size: 1.25rem; color: var(--bg-sidebar); font-weight: 700; margin-bottom: 0.25rem;"><?= htmlspecialchars($active_schedule['subject_code']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--bg-sidebar);"><?= htmlspecialchars($active_schedule['section']) ?></div>
                            <div style="font-size: 0.75rem; color: var(--bg-sidebar);"><?= date('g:i A', strtotime($active_schedule['start_time'])) ?> - <?= date('g:i A', strtotime($active_schedule['end_time'])) ?></div>
                        <?php else: ?>
                            <div style="font-size: 0.875rem; color: var(--bg-sidebar);">No active class.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Class Summary -->
            <div class="card">
                <div class="card-header">
                    <h2>Class Summary</h2>
                </div>
                <div class="metric-grid mb-4">
                    <div class="metric-widget blue">
                        <span class="value" id="count-enrolled"><?= $enrolled_count ?></span>
                        <span class="label">Enrolled</span>
                    </div>
                    <div class="metric-widget blue">
                        <span class="value" id="count-present" style="color: var(--accent-green)"><?= $present_count ?></span>
                        <span class="label">Present</span>
                    </div>
                    <div class="metric-widget blue">
                        <span class="value" id="count-late" style="color: var(--accent-yellow)"><?= $late_count ?></span>
                        <span class="label">Late</span>
                    </div>
                    <div class="metric-widget blue">
                        <span class="value" id="count-absent" style="color: var(--accent-red)"><?= $absent_count ?></span>
                        <span class="label">Absent</span>
                    </div>
                </div>
                
                <?php if($active_schedule): ?>
                    <form action="export_csv.php" method="GET" style="margin:0;">
                        <input type="hidden" name="schedule_id" value="<?= $active_schedule['schedule_id'] ?>">
                        <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem;">Generate Monitoring Form</button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-primary" disabled style="width: 100%; border-radius: 0.5rem; opacity: 0.5;">Generate Monitoring Form</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Middle Column: Attendance Table -->
        <div style="display: flex; flex-direction: column;">
            <table class="modern-table" style="width: 100%; height: 100%; border-spacing: 0 1rem; border-collapse: separate;">
                <thead>
                    <tr>
                        <th style="border-radius: 0.5rem 0 0 0.5rem; border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 1rem;">Student</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 1rem;">Time In</th>
                        <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 1rem;">Stay %</th>
                        <th style="border-radius: 0 0.5rem 0.5rem 0; border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 1rem;">Status</th>
                    </tr>
                </thead>
                <tbody id="attendance-tbody">
                    <?php foreach($attendance_feed as $log): ?>
                    <tr style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        <td style="border-radius: 0.5rem 0 0 0.5rem; border: none; font-size: 1rem; padding: 1.25rem 1rem; text-align: left; font-weight: 500;"><?= htmlspecialchars($log['full_name']) ?></td>
                        <td style="border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500;"><?= date('g:i A', strtotime($log['entry_timestamp'])) ?></td>
                        <td style="border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500;"><?= $log['stay_percentage'] ?>%</td>
                        <td style="border-radius: 0 0.5rem 0.5rem 0; border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500; color: var(--bg-sidebar);"><?= htmlspecialchars($log['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(!$attendance_feed): ?>
                        <tr style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <td colspan="4" style="border-radius: 0.5rem; border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500; text-align: center;">No attendance logs for current active class</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                <?php if($active_schedule): ?>
                const scheduleId = <?= json_encode($active_schedule['schedule_id']) ?>;
                
                setInterval(async () => {
                    try {
                        const res = await fetch(`../../api/get_live_attendance.php?schedule_id=${scheduleId}`);
                        const data = await res.json();
                        if (data.error) return;
                        
                        document.getElementById('count-enrolled').textContent = data.enrolled;
                        document.getElementById('count-present').textContent = data.present;
                        document.getElementById('count-late').textContent = data.late;
                        document.getElementById('count-absent').textContent = data.absent;
                        
                        const tbody = document.getElementById('attendance-tbody');
                        if (data.feed.length === 0) {
                            tbody.innerHTML = `<tr style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                                <td colspan="4" style="border-radius: 0.5rem; border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500; text-align: center;">No attendance logs for current active class</td>
                            </tr>`;
                        } else {
                            tbody.innerHTML = '';
                            data.feed.forEach(log => {
                                const tr = document.createElement('tr');
                                tr.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
                                
                                const tdName = document.createElement('td');
                                tdName.style.cssText = "border-radius: 0.5rem 0 0 0.5rem; border: none; font-size: 1rem; padding: 1.25rem 1rem; text-align: left; font-weight: 500;";
                                tdName.textContent = log.full_name;

                                const tdTime = document.createElement('td');
                                tdTime.style.cssText = "border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500;";
                                tdTime.textContent = log.formatted_time;
                                
                                const tdStay = document.createElement('td');
                                tdStay.style.cssText = "border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500;";
                                tdStay.textContent = log.stay_percentage + '%';

                                const tdStatus = document.createElement('td');
                                tdStatus.style.cssText = "border-radius: 0 0.5rem 0.5rem 0; border: none; font-size: 1rem; padding: 1.25rem 1rem; font-weight: 500; color: var(--bg-sidebar);";
                                tdStatus.textContent = log.status;

                                tr.appendChild(tdName);
                                tr.appendChild(tdTime);
                                tr.appendChild(tdStay);
                                tr.appendChild(tdStatus);
                                tbody.appendChild(tr);
                            });
                        }
                    } catch (err) {
                        console.error('Error fetching live attendance:', err);
                    }
                }, 3000); // Poll every 3 seconds
                <?php endif; ?>

                <?php if($current_room): ?>
                const currentRoom = <?= json_encode($current_room) ?>;
                setInterval(async () => {
                    try {
                        const dRes = await fetch(`../../api/get_door_status.php?room_id=${encodeURIComponent(currentRoom)}`);
                        const dData = await dRes.json();
                        if (dData.success) {
                            const isLocked = dData.is_locked;
                            const badge = document.getElementById('teacher-door-badge');
                            const btn = document.getElementById('teacher-door-btn');
                            if (badge && btn && !teacherPulseTimer) {
                                if (isLocked) {
                                    badge.className = 'door-badge locked';
                                    badge.textContent = 'LOCKED - ' + currentRoom;
                                    btn.disabled = false;
                                    btn.setAttribute('onclick', `toggleDoor('unlock', '${currentRoom}')`);
                                    btn.innerHTML = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>Unlock Door`;
                                } else {
                                    badge.className = 'door-badge unlocked';
                                    badge.textContent = 'UNLOCKED - ' + currentRoom;
                                    btn.setAttribute('onclick', `toggleDoor('lock', '${currentRoom}')`);
                                    btn.innerHTML = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>Lock Door`;
                                }
                            }
                        }
                    } catch (e) {}
                }, 800); // Poll every 800ms for fast hardware sync
                <?php endif; ?>
            });
        </script>

        <!-- Right Column: Door Status -->
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div class="door-status-panel">
                <div class="door-title">Door A status</div>
                <div class="door-subtitle">ENTRANCE</div>
                <?php if(!$current_room): ?>
                    <div class="door-badge locked" style="background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); border-color: rgba(255,255,255,0.2);">NO ACTIVE CLASS</div>
                    <button class="btn btn-primary" disabled style="width: 100%; opacity: 0.5;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        Outside Schedule
                    </button>
                <?php elseif($door_locked): ?>
                    <div class="door-badge locked" id="teacher-door-badge">LOCKED - <?= htmlspecialchars($current_room) ?></div>
                    <button id="teacher-door-btn" onclick="toggleDoor('unlock', '<?= htmlspecialchars(addslashes($current_room)) ?>')" class="btn btn-primary" style="width: 100%;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                        Unlock Door
                    </button>
                <?php else: ?>
                    <div class="door-badge unlocked" id="teacher-door-badge">UNLOCKED - <?= htmlspecialchars($current_room) ?></div>
                    <button id="teacher-door-btn" onclick="toggleDoor('lock', '<?= htmlspecialchars(addslashes($current_room)) ?>')" class="btn btn-primary" style="width: 100%;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                        Lock Door
                    </button>
                <?php endif; ?>
            </div>
        </div>

    </div>
</main>
<script src="../../assets/js/logout.js"></script>
</body>
</html>
