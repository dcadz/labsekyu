<?php
// public/api/get_live_attendance.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'tech_support'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json');

$teacher_id = $_SESSION['user_id'] ?? null;
$schedule_id = $_GET['schedule_id'] ?? null;
$day_of_week = date('l');
$today = date('Y-m-d');

// If schedule_id is not passed or empty, resolve teacher's active/latest schedule
if (empty($schedule_id) && $teacher_id) {
    $stmt = $pdo->prepare("
        SELECT schedule_id 
        FROM class_schedules 
        WHERE teacher_id = :tid 
          AND day_of_week = :day 
          AND CURRENT_TIME BETWEEN SUBTIME(start_time, '00:30:00') AND ADDTIME(end_time, '00:30:00') 
        LIMIT 1
    ");
    $stmt->execute([':tid' => $teacher_id, ':day' => $day_of_week]);
    $row = $stmt->fetch();
    if ($row) {
        $schedule_id = $row['schedule_id'];
    } else {
        $stmt = $pdo->prepare("SELECT schedule_id FROM class_schedules WHERE teacher_id = :tid ORDER BY schedule_id DESC LIMIT 1");
        $stmt->execute([':tid' => $teacher_id]);
        $row = $stmt->fetch();
        if ($row) $schedule_id = $row['schedule_id'];
    }
}

$present_count = 0;
$late_count = 0;
$absent_count = 0;
$enrolled_count = 0; 
$sched_section = '';
$sched = null;
$total_class_duration = 0;

if (!empty($schedule_id)) {
    $schedStmt = $pdo->prepare("SELECT schedule_id, section, start_time, end_time FROM class_schedules WHERE schedule_id = ?");
    $schedStmt->execute([$schedule_id]);
    $sched = $schedStmt->fetch(PDO::FETCH_ASSOC);
    if ($sched) {
        $sched_section = $sched['section'];
        $enrStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM users 
            WHERE role = 'student' 
              AND TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(?, ' ', ''), '-', ''))
        ");
        $enrStmt->execute([$sched_section]);
        $enrolled_count = (int)$enrStmt->fetchColumn();

        if (!empty($sched['start_time']) && !empty($sched['end_time'])) {
            $s_ts = strtotime($today . ' ' . $sched['start_time']);
            $e_ts = strtotime($today . ' ' . $sched['end_time']);
            if ($e_ts > $s_ts) {
                $total_class_duration = $e_ts - $s_ts;
            }
        }
    }
    
    $feedStmt = $pdo->prepare("
        SELECT a.log_id, a.student_id AS user_id, a.entry_timestamp, a.exit_timestamp, a.stay_percentage, a.status, u.full_name, u.role_id AS student_id 
        FROM attendance_logs a
        JOIN users u ON a.student_id = u.id
        WHERE a.log_date = :today 
          AND (
            a.schedule_id = :sched_id 
            OR (
              TRIM(REPLACE(REPLACE(u.section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:sec, ' ', ''), '-', ''))
              AND :sec != ''
            )
          )
        ORDER BY a.entry_timestamp ASC
    ");
    $feedStmt->execute([
        ':sched_id' => $schedule_id, 
        ':today' => $today, 
        ':sec' => $sched_section
    ]);
} else {
    // Return all attendance logs for today as fallback
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

// Group multiple session rows by student to present a single unified row per student
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

$attendance_feed = [];

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
            // Open session actively inside the room
            $is_currently_in = true;
            $now_t = time();
            if ($now_t >= $in_t) {
                $cumulative_seconds += ($now_t - $in_t);
            }
        }
    }
    
    // Calculate Stay Percentage
    $stay_percentage = 0.0;
    if ($total_class_duration > 0) {
        $stay_percentage = round(($cumulative_seconds / $total_class_duration) * 100, 1);
        if ($stay_percentage > 100.0) $stay_percentage = 100.0;
    } else {
        $stay_percentage = $is_currently_in ? 100.0 : 0.0;
    }
    
    // Determine Late Arrival (grace period = 15 mins)
    $is_late = false;
    if ($sched && !empty($sched['start_time'])) {
        $class_start_ts = strtotime($today . ' ' . $sched['start_time']);
        if ($first_entry_ts > ($class_start_ts + 900)) {
            $is_late = true;
        }
    }
    
    // Status Resolution:
    // If inside room: Present (or Late)
    // If stepped out (logged out via Camera B):
    //    If stay_percentage >= 85%: Present (or Late)
    //    If stay_percentage < 85%: Absent (student left and didn't stay 85%)
    if ($is_currently_in) {
        $status = $is_late ? 'Late' : 'Present';
    } else {
        if ($stay_percentage >= 85.0) {
            $status = $is_late ? 'Late' : 'Present';
        } else {
            $status = 'Absent';
        }
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

// Sort feed by entry timestamp descending (most recent first)
usort($attendance_feed, function($a, $b) {
    return strtotime($b['entry_timestamp']) <=> strtotime($a['entry_timestamp']);
});

foreach ($attendance_feed as $item) {
    if ($item['status'] === 'Present') $present_count++;
    elseif ($item['status'] === 'Late') $late_count++;
    elseif ($item['status'] === 'Absent') $absent_count++;
}

// Any enrolled student in this section who has not scanned in today is counted as absent
if ($enrolled_count > 0) {
    $scanned_count = count($attendance_feed);
    if ($enrolled_count > $scanned_count) {
        $absent_count += ($enrolled_count - $scanned_count);
    }
}

echo json_encode([
    'success' => true,
    'schedule_id' => $schedule_id,
    'section' => $sched_section,
    'enrolled' => $enrolled_count,
    'present' => $present_count,
    'late' => $late_count,
    'absent' => $absent_count,
    'feed' => $attendance_feed
]);
