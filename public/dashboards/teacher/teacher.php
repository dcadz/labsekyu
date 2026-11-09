<?php
// dashboards/teacher/teacher.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['teacher']);

require_once __DIR__ . '/../../../config/database.php';

$teacher_id = $_SESSION['user_id'];
$today = date('Y-m-d');
$day_of_week = date('l');

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

// Find active schedule for this teacher today (including 15-min grace period)
$activeSchedStmt = $pdo->prepare("
    SELECT schedule_id, subject_code, section, start_time, end_time, room_id 
    FROM class_schedules 
    WHERE teacher_id = :teacher_id 
      AND day_of_week = :day 
      AND CURRENT_TIME BETWEEN SUBTIME(start_time, '00:15:00') AND ADDTIME(end_time, '00:15:00') 
    LIMIT 1
");
$activeSchedStmt->execute([':teacher_id' => $teacher_id, ':day' => $day_of_week]);
$active_schedule = $activeSchedStmt->fetch();

// If no active schedule right at this minute, fallback to today's schedule or latest schedule
if (!$active_schedule) {
    $todaySched = $pdo->prepare("SELECT schedule_id, subject_code, section, start_time, end_time, room_id FROM class_schedules WHERE teacher_id = :teacher_id AND day_of_week = :day ORDER BY start_time ASC LIMIT 1");
    $todaySched->execute([':teacher_id' => $teacher_id, ':day' => $day_of_week]);
    $active_schedule = $todaySched->fetch();
}
if (!$active_schedule) {
    $latestSched = $pdo->prepare("SELECT schedule_id, subject_code, section, start_time, end_time, room_id FROM class_schedules WHERE teacher_id = :teacher_id ORDER BY schedule_id DESC LIMIT 1");
    $latestSched->execute([':teacher_id' => $teacher_id]);
    $active_schedule = $latestSched->fetch();
}

$current_room = $active_schedule ? $active_schedule['room_id'] : '101';

// Fetch Door Status
$door_locked = true;
if ($current_room) {
    $doorStmt = $pdo->prepare("SELECT is_locked FROM door_status WHERE room_id = ?");
    $doorStmt->execute([$current_room]);
    $door = $doorStmt->fetch();
    $door_locked = $door ? (bool)$door['is_locked'] : true;

    // Check system_settings master status
    $setStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'room_status' LIMIT 1");
    $settingVal = $setStmt ? $setStmt->fetchColumn() : null;
    if ($settingVal && strtoupper(trim($settingVal)) === 'UNLOCKED') {
        $door_locked = false;
    }
}

$attendance_feed = [];
$enrolled_count = 0; 
$present_count = 0;
$late_count = 0;
$absent_count = 0;
$total_class_duration = 0;

if ($active_schedule) {
    $enrStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM users 
        WHERE role = 'student' 
          AND TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(?, ' ', ''), '-', ''))
    ");
    $enrStmt->execute([$active_schedule['section']]);
    $enrolled_count = (int)$enrStmt->fetchColumn();

    if (!empty($active_schedule['start_time']) && !empty($active_schedule['end_time'])) {
        $s_ts = strtotime($today . ' ' . $active_schedule['start_time']);
        $e_ts = strtotime($today . ' ' . $active_schedule['end_time']);
        if ($e_ts > $s_ts) {
            $total_class_duration = $e_ts - $s_ts;
        }
    }
    
    $feedStmt = $pdo->prepare("
        SELECT a.log_id, a.student_id AS user_id, a.entry_timestamp, a.exit_timestamp, a.stay_percentage, a.status, u.full_name, u.role_id AS student_id 
        FROM attendance_logs a
        JOIN users u ON a.student_id = u.id
        WHERE a.log_date = :today 
          AND (
            a.schedule_id = :sched_id 
            OR TRIM(REPLACE(REPLACE(u.section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:sec, ' ', ''), '-', ''))
          )
        ORDER BY a.entry_timestamp ASC
    ");
    $feedStmt->execute([
        ':sched_id' => $active_schedule['schedule_id'], 
        ':today' => $today,
        ':sec' => $active_schedule['section']
    ]);
} else {
    $feedStmt = $pdo->prepare("
        SELECT a.log_id, a.student_id AS user_id, a.entry_timestamp, a.exit_timestamp, a.stay_percentage, a.status, u.full_name, u.role_id AS student_id 
        FROM attendance_logs a
        JOIN users u ON a.student_id = u.id
        WHERE a.log_date = :today
        ORDER BY a.entry_timestamp ASC
    ");
    $feedStmt->execute([':today' => $today]);
}

$raw_logs = $feedStmt->fetchAll(PDO::FETCH_ASSOC);

// Group session rows per student
$student_groups = [];
foreach ($raw_logs as $log) {
    $uid = $log['user_id'];
    if (!isset($student_groups[$uid])) {
        $student_groups[$uid] = [
            'user_id' => $uid,
            'student_id' => $log['student_id'],
            'full_name' => $log['full_name'],
            'sessions' => []
        ];
    }
    $student_groups[$uid]['sessions'][] = $log;
}

foreach ($student_groups as $uid => $data) {
    $sessions = $data['sessions'];
    $first_entry_raw = $sessions[0]['entry_timestamp'];
    $first_entry_ts = strtotime($first_entry_raw);
    
    $is_currently_in = false;
    $latest_exit_raw = null;
    $cumulative_seconds = 0;
    
    foreach ($sessions as $s) {
        $in_t = strtotime($s['entry_timestamp']);
        if (!empty($s['exit_timestamp'])) {
            $out_t = strtotime($s['exit_timestamp']);
            if ($out_t >= $in_t) {
                $cumulative_seconds += ($out_t - $in_t);
            }
            if (!$latest_exit_raw || $out_t > strtotime($latest_exit_raw)) {
                $latest_exit_raw = $s['exit_timestamp'];
            }
        } else {
            $is_currently_in = true;
            $now_t = time();
            if ($now_t >= $in_t) {
                $cumulative_seconds += ($now_t - $in_t);
            }
        }
    }
    
    $stay_percentage = 0.0;
    if ($total_class_duration > 0) {
        $stay_percentage = round(($cumulative_seconds / $total_class_duration) * 100, 1);
        if ($stay_percentage > 100.0) $stay_percentage = 100.0;
    } else {
        $stay_percentage = $is_currently_in ? 100.0 : 0.0;
    }
    
    $is_late = false;
    if ($active_schedule && !empty($active_schedule['start_time'])) {
        $class_start_ts = strtotime($today . ' ' . $active_schedule['start_time']);
        if ($first_entry_ts > ($class_start_ts + 900)) {
            $is_late = true;
        }
    }
    
    if ($is_currently_in) {
        $status = $is_late ? 'Late' : 'Present';
    } else {
        $status = ($stay_percentage >= 85.0) ? ($is_late ? 'Late' : 'Present') : 'Absent';
    }
    
    $formatted_time_in = !empty($first_entry_raw) ? date('g:i A', $first_entry_ts) : '--';
    $formatted_time_out = $is_currently_in ? 'Still in room' : (!empty($latest_exit_raw) ? date('g:i A', strtotime($latest_exit_raw)) : '--');
    
    $attendance_feed[] = [
        'student_id' => $data['student_id'],
        'full_name' => $data['full_name'],
        'is_currently_in' => $is_currently_in,
        'entry_timestamp' => $first_entry_raw,
        'exit_timestamp' => $is_currently_in ? null : $latest_exit_raw,
        'formatted_time_in' => $formatted_time_in,
        'formatted_time_out' => $formatted_time_out,
        'stay_percentage' => $stay_percentage,
        'status' => $status
    ];
}

usort($attendance_feed, function($a, $b) {
    return strtotime($b['entry_timestamp']) <=> strtotime($a['entry_timestamp']);
});

foreach ($attendance_feed as $item) {
    if ($item['status'] === 'Present') $present_count++;
    elseif ($item['status'] === 'Late') $late_count++;
    elseif ($item['status'] === 'Absent') $absent_count++;
}

if ($enrolled_count > 0) {
    $scanned_count = count($attendance_feed);
    if ($enrolled_count > $scanned_count) {
        $absent_count += ($enrolled_count - $scanned_count);
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
        function handleCamError(camKey, imgElement) {
            const directUrl = (camKey === 'A') ? 'http://192.168.137.90:81/stream' : 'http://192.168.137.135:81/stream';
            // First fallback: If port 8000 AI relay fails, connect directly to ESP32 stream
            if (imgElement.src.indexOf(':8000') !== -1) {
                console.warn(`Camera ${camKey} port 8000 feed unavailable. Falling back to direct ESP32 stream: ${directUrl}`);
                imgElement.src = directUrl + '?t=' + Date.now();
                return;
            }
            imgElement.style.display = 'none';
            const container = imgElement.parentElement;
            if (!container.querySelector('.cam-offline-placeholder')) {
                const div = document.createElement('div');
                div.className = 'cam-offline-placeholder';
                div.style.cssText = 'display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; gap:0.5rem; font-size:0.85rem; padding:1.5rem; text-align:center; height:100%; width:100%;';
                div.innerHTML = `
                    <svg width="40" height="40" fill="none" stroke="#ef4444" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                    </svg>
                    <div style="font-weight:700; color:#cbd5e1; font-size:0.95rem;">Camera Stream Offline</div>
                    <div style="font-size:0.75rem; color:#64748b;">${camKey === 'A' ? 'Camera A (Log In / Entrance • 192.168.137.90)' : 'Camera B (Log Out / Exit • 192.168.137.135)'}</div>
                    <div style="font-size:0.75rem; color:#38bdf8; margin-top:0.25rem;">Start AI Server: python storage/hardware_scripts/server.py</div>
                    <button type="button" onclick="retryCam('${camKey}')" class="btn" style="padding:0.35rem 1rem; font-size:0.75rem; background:#1e293b; color:#38bdf8; border:1px solid #334155; border-radius:0.35rem; margin-top:0.5rem; cursor:pointer; font-weight:600;">Reconnect Stream</button>
                `;
                container.appendChild(div);
            }
        }

        function retryCam(camKey) {
            const img = document.getElementById('teacher-cam-' + camKey);
            const container = document.getElementById('teacher-cam-' + camKey + '-container');
            if (img && container) {
                const placeholder = container.querySelector('.cam-offline-placeholder');
                if (placeholder) placeholder.remove();
                img.style.display = 'block';
                img.src = `http://${window.location.hostname}:8000/video_feed/${camKey === 'A' ? 'IN' : 'OUT'}?t=` + Date.now();
            }
        }

        // ==================================================================================
        // --- Synchronized Continuous Toggle Door Mode (No 5-Second Revert) ---
        // ==================================================================================
        function updateDoorUI(isLocked, roomId) {
            const badge = document.getElementById('teacher-door-badge');
            const btn = document.getElementById('teacher-door-btn');
            if (badge) {
                if (isLocked) {
                    badge.className = 'door-badge locked';
                    badge.textContent = 'LOCKED - ' + roomId;
                } else {
                    badge.className = 'door-badge unlocked';
                    badge.textContent = 'UNLOCKED - ' + roomId;
                }
            }
            if (btn) {
                btn.disabled = false;
                if (isLocked) {
                    btn.setAttribute('onclick', `toggleDoor('unlock', '${roomId}')`);
                    btn.innerHTML = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>Unlock Door`;
                } else {
                    btn.setAttribute('onclick', `toggleDoor('lock', '${roomId}')`);
                    btn.innerHTML = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>Lock Door`;
                }
            }
        }

        async function toggleDoor(action, roomId) {
            if (!roomId) {
                alert('No room assigned right now.');
                return;
            }
            const btn = document.getElementById('teacher-door-btn');
            if (btn) btn.disabled = true;

            try {
                const res = await fetch('../../api/toggle_door.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_id: roomId, action: action })
                });
                const data = await res.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Failed to update door'));
                    if (btn) btn.disabled = false;
                    return;
                }

                // Permanently update UI strictly based on API response
                const isLocked = (data.status === 'LOCKED' || action === 'lock');
                updateDoorUI(isLocked, roomId);
            } catch (err) {
                alert('Request failed');
                if (btn) btn.disabled = false;
            }
        }
    </script>
    <style>
        .class-meta-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .class-meta-details h2 {
            font-size: 1.5rem;
            color: var(--bg-sidebar);
            margin-bottom: 0.25rem;
            font-weight: 700;
        }
        .class-meta-details p {
            font-size: 0.875rem;
            color: #475569;
            font-weight: 500;
        }
        .door-status-panel {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            text-align: center;
        }
        .door-badge {
            font-size: 1.05rem;
            font-weight: 700;
            padding: 0.65rem 1.25rem;
            border-radius: 0.5rem;
            display: inline-block;
            width: 100%;
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        /* Green LOCKED state: mirrors physical 0V zero-heat secure status */
        .door-badge.locked {
            background: #dcfce7;
            color: #15803d;
            border: 1.5px solid #86efac;
        }
        /* Unlocked pulse state */
        .door-badge.unlocked {
            background: #fee2e2;
            color: #b91c1c;
            border: 1.5px solid #f87171;
            animation: pulse-border 1.5s infinite;
        }
        @keyframes pulse-border {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
            50% { box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2); }
        }
        .cam-frame-box {
            background: #0f172a;
            border-radius: 0.5rem;
            aspect-ratio: 16/9;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1.5px solid #334155;
            min-height: 240px;
        }
        .cam-overlay-tag {
            position: absolute;
            bottom: 0.75rem;
            left: 0.75rem;
            font-size: 0.75rem;
            background: rgba(15, 23, 42, 0.85);
            color: #ffffff;
            padding: 0.35rem 0.75rem;
            border-radius: 0.35rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.4rem;
            backdrop-filter: blur(4px);
            z-index: 10;
        }
        @media (max-width: 1024px) {
            .dual-camera-frame {
                grid-template-columns: 1fr !important;
            }
            .teacher-lower-layout {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
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
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if (isset($teacherAlertCount) && $teacherAlertCount > 0): ?>
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
        <div class="admin-badge">TEACHER</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container" style="max-width: 1400px;">
        
        <!-- Top Class Bar -->
        <div class="class-meta-container">
            <div class="class-meta-details">
                <?php if($active_schedule): ?>
                    <h2><?= htmlspecialchars($active_schedule['subject_code']) ?> - <?= htmlspecialchars($active_schedule['section']) ?></h2>
                    <p>Session (Room <?= htmlspecialchars($active_schedule['room_id']) ?>) &bull; <?= date('g:i A', strtotime($active_schedule['start_time'])) ?> - <?= date('g:i A', strtotime($active_schedule['end_time'])) ?></p>
                <?php else: ?>
                    <h2>No Scheduled Class Right Now</h2>
                    <p>Room: <?= htmlspecialchars($current_room) ?> (Manual Override & Testing Available)</p>
                <?php endif; ?>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <span class="badge" style="background: var(--bg-sidebar); color: white; padding: 0.5rem 1rem; font-weight: 600; border-radius: 0.5rem;">
                    <?= date('l, F j, Y') ?>
                </span>
            </div>
        </div>

        <!-- =================================================================================== -->
        <!-- LIVE LAB STREAM FIELD: CAMERA A & CAMERA B VISIBLE TOGETHER IN ONE FRAME            -->
        <!-- =================================================================================== -->
        <div class="card" style="margin-bottom: 1.5rem; border: 1px solid #cbd5e1; padding: 1.25rem;">
            <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                <h2 style="font-size: 1.15rem; color: var(--bg-sidebar); font-weight: 700; display: flex; align-items: center; gap: 0.5rem;">
                    <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                    Live Lab Stream - Room <?= htmlspecialchars($current_room) ?>
                </h2>
                <div style="display: flex; gap: 1.25rem; align-items: center; font-size: 0.75rem; font-weight: 600;">
                    <span style="display: flex; align-items: center; gap: 0.35rem; color: #16a34a;">
                        <span style="width: 8px; height: 8px; background: #16a34a; border-radius: 50%; display: inline-block;"></span>
                        Camera A (Log In / Entrance)
                    </span>
                    <span style="display: flex; align-items: center; gap: 0.35rem; color: #2563eb;">
                        <span style="width: 8px; height: 8px; background: #2563eb; border-radius: 50%; display: inline-block;"></span>
                        Camera B (Log Out / Exit)
                    </span>
                </div>
            </div>

            <!-- Single frame containing both Camera A and Camera B side-by-side -->
            <div class="camera-split dual-camera-frame" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem;">
                
                <!-- Left Pane: Camera A (Log In / Entrance) -->
                <div class="cam-frame-box" id="teacher-cam-A-container">
                    <img id="teacher-cam-A" 
                         src="http://<?= $_SERVER['SERVER_NAME'] ?? 'localhost' ?>:8000/video_feed/IN?t=<?= time() ?>" 
                         style="width: 100%; height: 100%; object-fit: cover;" 
                         onerror="handleCamError('A', this)" 
                         alt="Camera A Feed" />
                    <span class="cam-overlay-tag">
                        <span style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block;"></span>
                        Camera A (Log In / Entrance)
                    </span>
                </div>

                <!-- Right Pane: Camera B (Log Out / Exit) -->
                <div class="cam-frame-box" id="teacher-cam-B-container">
                    <img id="teacher-cam-B" 
                         src="http://<?= $_SERVER['SERVER_NAME'] ?? 'localhost' ?>:8000/video_feed/OUT?t=<?= time() ?>" 
                         style="width: 100%; height: 100%; object-fit: cover;" 
                         onerror="handleCamError('B', this)" 
                         alt="Camera B Feed" />
                    <span class="cam-overlay-tag">
                        <span style="width: 8px; height: 8px; background: #3b82f6; border-radius: 50%; display: inline-block;"></span>
                        Camera B (Log Out / Exit)
                    </span>
                </div>

            </div>
        </div>

        <!-- =================================================================================== -->
        <!-- LOWER SECTION: SMART DOOR LOCK + METRICS (LEFT) & LIVE ATTENDANCE TABLE (RIGHT)     -->
        <!-- =================================================================================== -->
        <div class="teacher-lower-layout" style="display: grid; grid-template-columns: 320px 1fr; gap: 1.5rem; margin-bottom: 2rem;">
            
            <!-- Left Column: IoT Door Lock Control & Class Metrics -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Smart Door Lock Panel -->
                <div class="door-status-panel">
                    <div style="font-weight: 700; font-size: 1rem; color: var(--bg-sidebar);">SMART DOOR LOCK</div>
                    <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; margin-top: -0.5rem;">FAIL-SECURE SOLENOID</div>
                    
                    <?php if($door_locked): ?>
                        <div class="door-badge locked" id="teacher-door-badge">LOCKED - <?= htmlspecialchars($current_room) ?></div>
                        <button id="teacher-door-btn" onclick="toggleDoor('unlock', '<?= htmlspecialchars(addslashes($current_room)) ?>')" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; font-weight: 600;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                            Unlock Door
                        </button>
                    <?php else: ?>
                        <div class="door-badge unlocked" id="teacher-door-badge">UNLOCKED - <?= htmlspecialchars($current_room) ?></div>
                        <button id="teacher-door-btn" onclick="toggleDoor('lock', '<?= htmlspecialchars(addslashes($current_room)) ?>')" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; font-weight: 600;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                            Lock Door
                        </button>
                    <?php endif; ?>
                    
                    <p style="font-size: 0.75rem; color: #64748b; line-height: 1.4; margin-top: 0.25rem;">
                        Continuous Toggle Mode. 10-minute emergency thermal cutoff active.
                    </p>
                </div>

                <!-- Metrics & Export Card -->
                <div class="card" style="display: flex; flex-direction: column; gap: 1rem; border: 1px solid #cbd5e1;">
                    <div class="metrics-column" style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <div class="metric-widget blue">
                            <span class="value" id="count-enrolled" style="color: var(--bg-sidebar)"><?= $enrolled_count ?></span>
                            <span class="label">Total Enrolled</span>
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
                            <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; font-weight: 600;">Download Monitoring CSV</button>
                        </form>
                    <?php else: ?>
                        <button class="btn btn-primary" disabled style="width: 100%; border-radius: 0.5rem; opacity: 0.5;">Download Monitoring CSV</button>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Right Column: Live Attendance Monitoring Table -->
            <div style="display: flex; flex-direction: column;">
                <div class="card" style="height: 100%; border: 1px solid #cbd5e1; overflow: hidden; display: flex; flex-direction: column;">
                    <div class="card-header" style="border-bottom: 1px solid #e2e8f0; padding-bottom: 0.75rem; margin-bottom: 0.75rem;">
                        <h2 style="font-size: 1.15rem; color: var(--bg-sidebar); font-weight: 700; display: flex; align-items: center; justify-content: space-between;">
                            <span>Live Attendance Monitoring</span>
                            <span style="font-size: 0.75rem; color: #16a34a; font-weight: 600; display: flex; align-items: center; gap: 0.35rem;">
                                <span style="width: 8px; height: 8px; background: #16a34a; border-radius: 50%; display: inline-block;"></span>
                                Auto-Refreshing (3s)
                            </span>
                        </h2>
                    </div>

                    <div style="overflow-y: auto; flex: 1;">
                        <table class="modern-table" style="width: 100%; border-spacing: 0 0.5rem; border-collapse: separate;">
                            <thead>
                                <tr>
                                    <th style="border-radius: 0.5rem 0 0 0.5rem; border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 0.875rem; font-weight: 700; padding: 0.75rem;">Student</th>
                                    <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 0.875rem; font-weight: 700; padding: 0.75rem;">Log In (Time In)</th>
                                    <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 0.875rem; font-weight: 700; padding: 0.75rem;">Log Out (Time Out)</th>
                                    <th style="border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 0.875rem; font-weight: 700; padding: 0.75rem;">Stay %</th>
                                    <th style="border-radius: 0 0.5rem 0.5rem 0; border: none; background: #e2e8f0; color: var(--bg-sidebar); font-size: 0.875rem; font-weight: 700; padding: 0.75rem;">Status</th>
                                </tr>
                            </thead>
                            <tbody id="attendance-tbody">
                                <?php foreach($attendance_feed as $log): ?>
                                <tr style="box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #ffffff;">
                                    <td style="border-radius: 0.5rem 0 0 0.5rem; border: none; font-size: 0.875rem; padding: 0.85rem; text-align: left; font-weight: 600; color: #1e293b;">
                                        <div><?= htmlspecialchars($log['full_name']) ?></div>
                                        <?php if(!empty($log['student_id'])): ?>
                                            <div style="font-size: 0.75rem; color: #64748b; font-weight: 400;"><?= htmlspecialchars($log['student_id']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 500; color: #1e293b;">
                                        <?= !empty($log['entry_timestamp']) ? date('g:i A', strtotime($log['entry_timestamp'])) : '--' ?>
                                    </td>
                                    <td style="border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 500; color: #1e293b;">
                                        <?= !empty($log['exit_timestamp']) ? date('g:i A', strtotime($log['exit_timestamp'])) : '<span style="color: #16a34a; font-weight: 600;">In Room</span>' ?>
                                    </td>
                                    <td style="border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 600; color: #1e293b;">
                                        <?= $log['stay_percentage'] ?>%
                                    </td>
                                    <?php
                                        $sColor = "var(--bg-sidebar)";
                                        if ($log['status'] === "Present") $sColor = "#16a34a";
                                        elseif ($log['status'] === "Late") $sColor = "#d97706";
                                        elseif ($log['status'] === "Absent") $sColor = "#dc2626";
                                    ?>
                                    <td style="border-radius: 0 0.5rem 0.5rem 0; border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 700; color: <?= $sColor ?>;">
                                        <?= htmlspecialchars($log['status']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(!$attendance_feed): ?>
                                    <tr style="box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #ffffff;">
                                        <td colspan="5" style="border-radius: 0.5rem; border: none; font-size: 0.95rem; padding: 1.5rem 1rem; font-weight: 500; text-align: center; color: #64748b;">
                                            No attendance logs for current active class
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // If door was initially unlocked on load, start countdown
    <?php if(!$door_locked): ?>
    startUnlockCountdown(<?= json_encode($current_room) ?>, 5);
    <?php endif; ?>

    // 1. Live Attendance Polling (Every 3 seconds)
    const scheduleId = <?= json_encode($active_schedule['schedule_id'] ?? null) ?>;
    setInterval(async () => {
        try {
            const queryParam = scheduleId ? `?schedule_id=${scheduleId}` : '';
            const res = await fetch(`../../api/get_live_attendance.php${queryParam}`);
            const data = await res.json();
            if (!data.success && data.error) return;
            
            if (document.getElementById('count-enrolled')) document.getElementById('count-enrolled').textContent = data.enrolled ?? 0;
            if (document.getElementById('count-present')) document.getElementById('count-present').textContent = data.present ?? 0;
            if (document.getElementById('count-late')) document.getElementById('count-late').textContent = data.late ?? 0;
            if (document.getElementById('count-absent')) document.getElementById('count-absent').textContent = data.absent ?? 0;
            
            const tbody = document.getElementById('attendance-tbody');
            if (tbody) {
                if (!data.feed || data.feed.length === 0) {
                    tbody.innerHTML = `<tr style="box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #ffffff;">
                        <td colspan="5" style="border-radius: 0.5rem; border: none; font-size: 0.95rem; padding: 1.5rem 1rem; font-weight: 500; text-align: center; color: #64748b;">No attendance logs for current active class</td>
                    </tr>`;
                } else {
                    tbody.innerHTML = '';
                    data.feed.forEach(log => {
                        const tr = document.createElement('tr');
                        tr.style.cssText = 'box-shadow: 0 2px 4px rgba(0,0,0,0.05); background: #ffffff;';
                        
                        const tdName = document.createElement('td');
                        tdName.style.cssText = "border-radius: 0.5rem 0 0 0.5rem; border: none; font-size: 0.875rem; padding: 0.85rem; text-align: left; font-weight: 600; color: #1e293b;";
                        tdName.innerHTML = `<div>${log.full_name}</div>` + (log.student_id ? `<div style="font-size: 0.75rem; color: #64748b; font-weight: 400;">${log.student_id}</div>` : '');

                        const tdIn = document.createElement('td');
                        tdIn.style.cssText = "border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 500; color: #1e293b;";
                        tdIn.textContent = log.formatted_time_in || '--';
                        
                        const tdOut = document.createElement('td');
                        tdOut.style.cssText = "border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 500; color: #1e293b;";
                        if (log.formatted_time_out === 'Still in room') {
                            tdOut.innerHTML = '<span style="color: #16a34a; font-weight: 600;">In Room</span>';
                        } else {
                            tdOut.textContent = log.formatted_time_out || '--';
                        }

                        const tdStay = document.createElement('td');
                        tdStay.style.cssText = "border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 600; color: #1e293b;";
                        tdStay.textContent = (log.stay_percentage || 0) + '%';

                        const tdStatus = document.createElement('td');
                        let sColor = "var(--bg-sidebar)";
                        if (log.status === "Present") sColor = "#16a34a";
                        else if (log.status === "Late") sColor = "#d97706";
                        else if (log.status === "Absent") sColor = "#dc2626";
                        tdStatus.style.cssText = `border-radius: 0 0.5rem 0.5rem 0; border: none; font-size: 0.875rem; padding: 0.85rem; font-weight: 700; color: ${sColor};`;
                        tdStatus.textContent = log.status;

                        tr.appendChild(tdName);
                        tr.appendChild(tdIn);
                        tr.appendChild(tdOut);
                        tr.appendChild(tdStay);
                        tr.appendChild(tdStatus);
                        tbody.appendChild(tr);
                    });
                }
            }
        } catch (err) {}
    }, 3000);

    // 2. Continuous Hardware Door Status Synchronization (Toggle Mode)
    const currentRoom = <?= json_encode($current_room) ?>;
    setInterval(async () => {
        try {
            const dRes = await fetch(`../../api/get_door_status.php?room_id=${encodeURIComponent(currentRoom)}`);
            const dData = await dRes.json();
            if (dData.success) {
                updateDoorUI(dData.is_locked, currentRoom);
            }
        } catch (e) {}
    }, 800);
});
</script>
<script src="../../assets/js/logout.js"></script>
</body>
</html>
