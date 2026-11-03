<?php
// dashboards/teacher/export_csv.php
// LABSEKYU CSV Attendance Export Generator
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['teacher']);

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../../../config/database.php';

$schedule_id = $_GET['schedule_id'] ?? null;
$today = date('Y-m-d');

if (!$schedule_id) {
    die("No schedule ID provided.");
}

// Fetch schedule details and verify ownership
$stmt = $pdo->prepare("SELECT schedule_id, subject_code, section, teacher_id, start_time, end_time FROM class_schedules WHERE schedule_id = :sched_id AND teacher_id = :teacher_id");
$stmt->execute([':sched_id' => $schedule_id, ':teacher_id' => $_SESSION['user_id']]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    die("Unauthorized or invalid schedule.");
}

// Calculate total class duration in seconds
$total_class_duration = 0;
if (!empty($schedule['start_time']) && !empty($schedule['end_time'])) {
    $s_ts = strtotime($today . ' ' . $schedule['start_time']);
    $e_ts = strtotime($today . ' ' . $schedule['end_time']);
    if ($e_ts > $s_ts) {
        $total_class_duration = $e_ts - $s_ts;
    }
}

// Resolve Teacher Name
$teacherStmt = $pdo->prepare("SELECT full_name FROM users WHERE id = :id");
$teacherStmt->execute([':id' => $_SESSION['user_id']]);
$teacherRow = $teacherStmt->fetch(PDO::FETCH_ASSOC);
$teacherName = $teacherRow ? $teacherRow['full_name'] : ($_SESSION['full_name'] ?? 'Teacher');

// Clean filename components: [coursecode_section_teachername_date.csv]
$cleanCourse  = preg_replace('/[^a-zA-Z0-9]/', '', $schedule['subject_code']);
$cleanSection = preg_replace('/[^a-zA-Z0-9]/', '', $schedule['section']);
$cleanTeacher = preg_replace('/[^a-zA-Z0-9]/', '', $teacherName);
$filename = sprintf("%s_%s_%s_%s.csv", $cleanCourse, $cleanSection, $cleanTeacher, $today);

// Fetch all enrolled students in this section (normalized match)
$studentsStmt = $pdo->prepare("
    SELECT id, role_id AS student_id, full_name 
    FROM users 
    WHERE role = 'student' 
      AND TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:section, ' ', ''), '-', ''))
    ORDER BY full_name ASC
");
$studentsStmt->execute([':section' => $schedule['section']]);
$enrolledStudents = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch today's logs for this schedule or section
$logsStmt = $pdo->prepare("
    SELECT a.student_id AS user_id, u.role_id, u.full_name, a.entry_timestamp, a.exit_timestamp, a.status 
    FROM attendance_logs a
    JOIN users u ON a.student_id = u.id
    WHERE a.log_date = :today 
      AND (
        a.schedule_id = :sched_id 
        OR TRIM(REPLACE(REPLACE(u.section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:section, ' ', ''), '-', ''))
      )
    ORDER BY a.entry_timestamp ASC
");
$logsStmt->execute([':sched_id' => $schedule_id, ':today' => $today, ':section' => $schedule['section']]);
$logsList = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

// Group session logs by user id
$studentLogs = [];
foreach ($logsList as $log) {
    $uid = $log['user_id'];
    if (!isset($studentLogs[$uid])) {
        $studentLogs[$uid] = [];
    }
    $studentLogs[$uid][] = $log;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Required header: student_id, full name, time in, time out, status [present, late, absent]
fputcsv($output, ['student_id', 'full name', 'time in', 'time out', 'status']);

// Helper closure to calculate student state
$computeStudentStatus = function($sessions) use ($total_class_duration, $schedule, $today) {
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
    if (!empty($schedule['start_time'])) {
        $class_start_ts = strtotime($today . ' ' . $schedule['start_time']);
        if ($first_entry_ts > ($class_start_ts + 900)) {
            $is_late = true;
        }
    }
    
    if ($is_currently_in) {
        $status = $is_late ? 'late' : 'present';
    } else {
        if ($stay_percentage >= 85.0) {
            $status = $is_late ? 'late' : 'present';
        } else {
            $status = 'absent';
        }
    }
    
    $time_in = !empty($first_entry_raw) ? date('H:i:s', $first_entry_ts) : '--:--:--';
    $time_out = $is_currently_in ? 'In Room' : (!empty($latest_exit_raw) ? date('H:i:s', strtotime($latest_exit_raw)) : '--:--:--');
    
    return [$time_in, $time_out, $status];
};

if (!empty($enrolledStudents)) {
    foreach ($enrolledStudents as $stu) {
        $uid = $stu['id'];
        if (isset($studentLogs[$uid])) {
            list($time_in, $time_out, $status) = $computeStudentStatus($studentLogs[$uid]);
        } else {
            $time_in = '--:--:--';
            $time_out = '--:--:--';
            $status = 'absent';
        }

        fputcsv($output, [
            $stu['student_id'] ?: 'N/A',
            $stu['full_name'],
            $time_in,
            $time_out,
            $status
        ]);
    }
} elseif (!empty($studentLogs)) {
    foreach ($studentLogs as $uid => $sessions) {
        list($time_in, $time_out, $status) = $computeStudentStatus($sessions);
        fputcsv($output, [
            $sessions[0]['role_id'] ?: 'N/A',
            $sessions[0]['full_name'],
            $time_in,
            $time_out,
            $status
        ]);
    }
} else {
    fputcsv($output, ['N/A', 'No attendance records or enrolled students found', '--:--:--', '--:--:--', 'absent']);
}

fclose($output);
exit();
