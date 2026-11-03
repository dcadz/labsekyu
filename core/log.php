<?php
// core/log.php - LABSEKYU Hardware & AI Attendance Logging Engine
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/database.php';

// Accept API Key from GET, POST, JSON, or HTTP Headers
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);
$params = is_array($jsonInput) ? array_merge($_GET, $_POST, $jsonInput) : array_merge($_GET, $_POST);

$headerApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$apiKey = $params['api_key'] ?? $headerApiKey ?? '';
$validApiKey = 'labsekyu_secret_key_123!';

if (!isset($_SESSION['user_id']) && $apiKey !== $validApiKey) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = strtolower(trim($params['action'] ?? ''));
$uid = trim($params['uid'] ?? '');
$device_id = strtoupper(trim($params['device_id'] ?? 'IN'));
$room_id = trim($params['room_id'] ?? '');

if (empty($uid)) {
    http_response_code(400);
    if (!empty($params)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Missing student UID']);
    } else {
        echo "BAD_REQUEST";
    }
    exit;
}

$date = date("Y-m-d");
$time = date("H:i:s");
$current_datetime = date("Y-m-d H:i:s");
$day_of_week = date("l");

// 1. Verify user exists in the database by role_id (Student ID / RFID) or id
$stmt = $pdo->prepare("SELECT id, full_name, role, section, role_id FROM users WHERE role_id = :uid OR id = :uid LIMIT 1");
$stmt->execute([':uid' => $uid]);
$user = $stmt->fetch();

if (!$user) {
    // Stage unrecognized UID for fast registration in UI
    $stgStmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('staged_uid', :uid) ON DUPLICATE KEY UPDATE setting_value = :uid");
    $stgStmt->execute([':uid' => $uid]);
    
    if (isset($params['format']) && $params['format'] === 'json' || !empty($jsonInput)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'found' => false, 'status' => 'UNREGISTERED', 'uid' => $uid]);
    } else {
        echo "UNREGISTERED";
    }
    exit;
}

$student_id = $user['id'];
$name = $user['full_name'];
$role_id = $user['role_id'];
$student_section = trim($user['section'] ?? '');

// 2. Resolve Class Schedule for this Student
// Priority 1: Current active schedule for student's section (with 30m grace period)
$schedule = null;
if (!empty($student_section)) {
    $stmt = $pdo->prepare("
        SELECT schedule_id, start_time, end_time, section, room_id, subject_code 
        FROM class_schedules 
        WHERE day_of_week = :day 
          AND TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:section, ' ', ''), '-', ''))
          AND :time >= SUBTIME(start_time, '00:30:00') 
          AND :time <= ADDTIME(end_time, '00:30:00')
        LIMIT 1
    ");
    $stmt->execute([':day' => $day_of_week, ':section' => $student_section, ':time' => $time]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Priority 2: Student's section schedule today (any time today)
if (!$schedule && !empty($student_section)) {
    $stmt = $pdo->prepare("
        SELECT schedule_id, start_time, end_time, section, room_id, subject_code 
        FROM class_schedules 
        WHERE day_of_week = :day 
          AND TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:section, ' ', ''), '-', ''))
        ORDER BY start_time ASC 
        LIMIT 1
    ");
    $stmt->execute([':day' => $day_of_week, ':section' => $student_section]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Priority 3: Match by room_id if room was provided and schedule matches
if (!$schedule && !empty($room_id)) {
    $stmt = $pdo->prepare("SELECT schedule_id, start_time, end_time, section, room_id, subject_code FROM class_schedules WHERE day_of_week = :day AND :time >= SUBTIME(start_time, '00:30:00') AND :time <= end_time AND room_id = :room LIMIT 1");
    $stmt->execute([':day' => $day_of_week, ':time' => $time, ':room' => $room_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Priority 4: Latest schedule for student's section
if (!$schedule && !empty($student_section)) {
    $stmt = $pdo->prepare("
        SELECT schedule_id, start_time, end_time, section, room_id, subject_code 
        FROM class_schedules 
        WHERE TRIM(REPLACE(REPLACE(section, ' ', ''), '-', '')) = TRIM(REPLACE(REPLACE(:section, ' ', ''), '-', ''))
        ORDER BY schedule_id DESC 
        LIMIT 1
    ");
    $stmt->execute([':section' => $student_section]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
}

$schedule_id = $schedule ? $schedule['schedule_id'] : null;

// Total class duration in seconds
$total_class_duration = 0;
if ($schedule && !empty($schedule['start_time']) && !empty($schedule['end_time'])) {
    $s_ts = strtotime($date . ' ' . $schedule['start_time']);
    $e_ts = strtotime($date . ' ' . $schedule['end_time']);
    if ($e_ts > $s_ts) {
        $total_class_duration = $e_ts - $s_ts;
    }
}

// 3. Query all attendance records for this student today
$schedFilter = $schedule_id ? "AND schedule_id = :sched_id" : "";
$queryParams = [':student_id' => $student_id, ':date' => $date];
if ($schedule_id) $queryParams[':sched_id'] = $schedule_id;

$stmt = $pdo->prepare("
    SELECT * 
    FROM attendance_logs 
    WHERE student_id = :student_id AND log_date = :date $schedFilter 
    ORDER BY log_id ASC
");
$stmt->execute($queryParams);
$today_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$has_record_today = (!empty($today_sessions));

// Check if student currently has an OPEN session (exit_timestamp IS NULL)
$open_session = null;
foreach ($today_sessions as $sess) {
    if (empty($sess['exit_timestamp'])) {
        $open_session = $sess;
        break;
    }
}
$is_currently_in = ($open_session !== null);

// Calculate cumulative stay seconds so far today
$cumulative_stay_seconds = 0;
foreach ($today_sessions as $sess) {
    $in_time = strtotime($sess['entry_timestamp']);
    if ($in_time) {
        if (!empty($sess['exit_timestamp'])) {
            $out_time = strtotime($sess['exit_timestamp']);
            if ($out_time >= $in_time) {
                $cumulative_stay_seconds += ($out_time - $in_time);
            }
        } else {
            // Open session actively accumulating
            $now_time = time();
            if ($now_time >= $in_time) {
                $cumulative_stay_seconds += ($now_time - $in_time);
            }
        }
    }
}

$stay_percentage = 0.00;
if ($total_class_duration > 0) {
    $stay_percentage = round(($cumulative_stay_seconds / $total_class_duration) * 100, 2);
    if ($stay_percentage > 100) $stay_percentage = 100.00;
} else {
    $stay_percentage = $is_currently_in ? 100.00 : 0.00;
}

// ACTION: STATUS QUERY (Called by server.py to inspect student presence)
if ($action === 'status') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'found' => true,
        'user_id' => (int)$student_id,
        'student_id' => $role_id,
        'full_name' => $name,
        'section' => $user['section'],
        'schedule_id' => $schedule_id,
        'has_record_today' => $has_record_today,
        'is_currently_in' => $is_currently_in,
        'open_log_id' => $open_session ? (int)$open_session['log_id'] : null,
        'entry_timestamp' => $open_session ? $open_session['entry_timestamp'] : ($today_sessions[0]['entry_timestamp'] ?? null),
        'exit_timestamp' => end($today_sessions)['exit_timestamp'] ?? null,
        'stay_percentage' => $stay_percentage,
        'cumulative_stay_seconds' => $cumulative_stay_seconds,
        'status' => $today_sessions ? end($today_sessions)['status'] : null
    ]);
    exit;
}

// 4. Process Attendance Events
$isJsonRequest = (!empty($jsonInput) || (isset($params['format']) && $params['format'] === 'json'));

// --- LOG IN / RE-ENTRY (CAMERA A) ---
if ($device_id === 'IN') {
    if ($is_currently_in) {
        // Student is already inside the room
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'event' => 'ALREADY_IN',
                'message' => "{$name} is already in the room.",
                'is_currently_in' => true,
                'entry_timestamp' => $open_session['entry_timestamp']
            ]);
        } else {
            echo "ALREADY_IN\n" . $name;
        }
        exit;
    }

    // Determine initial status based on first entry vs class schedule (15 mins grace period)
    $firstSession = $today_sessions[0] ?? null;
    $initial_status = "Present";
    if ($firstSession) {
        // If already entered earlier today, retain the initial on-time/late status
        $initial_status = ($firstSession['status'] === 'Late') ? 'Late' : 'Present';
    } else if ($schedule && !empty($schedule['start_time'])) {
        $start_time_ts = strtotime($date . ' ' . $schedule['start_time']);
        $current_ts = strtotime($current_datetime);
        if ($current_ts > ($start_time_ts + 900)) { // 15 mins
            $initial_status = "Late";
        }
    }

    // Insert a new session log row (entry_timestamp = NOW, exit_timestamp = NULL)
    $insertStmt = $pdo->prepare("
        INSERT INTO attendance_logs (student_id, schedule_id, log_date, entry_timestamp, status, sync_source) 
        VALUES (:student_id, :schedule_id, :date, :entry, :status, 'Live')
    ");
    $inserted = $insertStmt->execute([
        ':student_id' => $student_id,
        ':schedule_id' => $schedule_id,
        ':date' => $date,
        ':entry' => $current_datetime,
        ':status' => $initial_status
    ]);

    if ($inserted) {
        $is_reentry = !empty($today_sessions);
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'event' => 'TIME_IN',
                'is_reentry' => $is_reentry,
                'student_id' => $role_id,
                'full_name' => $name,
                'status' => $initial_status,
                'schedule_id' => $schedule_id,
                'entry_timestamp' => $current_datetime,
                'formatted_time' => date('g:i A', strtotime($current_datetime))
            ]);
        } else {
            echo "IN_" . strtoupper($initial_status) . "\n" . $name;
        }
    } else {
        http_response_code(500);
        echo $isJsonRequest ? json_encode(['success' => false, 'error' => 'Database insert error']) : "ERROR";
    }
    exit;

// --- LOG OUT (CAMERA B) ---
} else if ($device_id === 'OUT') {
    if (!$is_currently_in || !$open_session) {
        // Student is already logged out
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'NOT_IN',
                'message' => "{$name} is not currently logged in room."
            ]);
        } else {
            echo "NOT_IN\n" . $name;
        }
        exit;
    }

    $log_id = $open_session['log_id'];

    // 1. Close open session with current timestamp
    $closeStmt = $pdo->prepare("UPDATE attendance_logs SET exit_timestamp = :exit WHERE log_id = :log_id");
    $closeStmt->execute([':exit' => $current_datetime, ':log_id' => $log_id]);

    // 2. Re-calculate total cumulative stay seconds across ALL completed sessions today
    $stmt = $pdo->prepare("
        SELECT entry_timestamp, exit_timestamp, status 
        FROM attendance_logs 
        WHERE student_id = :student_id AND log_date = :date $schedFilter 
        ORDER BY log_id ASC
    ");
    $stmt->execute($queryParams);
    $allSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_stay = 0;
    foreach ($allSessions as $sess) {
        $in_t = strtotime($sess['entry_timestamp']);
        $out_t = !empty($sess['exit_timestamp']) ? strtotime($sess['exit_timestamp']) : strtotime($current_datetime);
        if ($in_t && $out_t >= $in_t) {
            $total_stay += ($out_t - $in_t);
        }
    }

    $cumulative_percentage = 0.00;
    if ($total_class_duration > 0) {
        $cumulative_percentage = round(($total_stay / $total_class_duration) * 100, 2);
        if ($cumulative_percentage > 100) $cumulative_percentage = 100.00;
    } else {
        $cumulative_percentage = 100.00;
    }

    // 3. Strict 85% Stay Rule Enforcement:
    // "as long as the students stays 85 percent in the room during the class schedule then it marks as present.
    // however, if the students log out and did not go back to the room then make the status as absent
    // if the students fails to re log in in camera a after they go out."
    if ($cumulative_percentage < 85.00) {
        $final_status = 'Absent';
    } else {
        // Reached 85% requirement:
        // Check if student was originally late on first entry
        $firstSession = $allSessions[0] ?? null;
        $isLateInitial = false;
        if ($firstSession && $schedule && !empty($schedule['start_time'])) {
            $class_start_ts = strtotime($date . ' ' . $schedule['start_time']);
            $first_entry_ts = strtotime($firstSession['entry_timestamp']);
            if ($first_entry_ts > ($class_start_ts + 900)) {
                $isLateInitial = true;
            }
        }
        $final_status = $isLateInitial ? 'Late' : 'Present';
    }

    // 4. Update the closed session and sync status across all rows for this class
    $updateStmt = $pdo->prepare("
        UPDATE attendance_logs 
        SET exit_timestamp = :exit, stay_percentage = :pct, status = :status 
        WHERE log_id = :log_id
    ");
    $updated = $updateStmt->execute([
        ':exit' => $current_datetime,
        ':pct' => $cumulative_percentage,
        ':status' => $final_status,
        ':log_id' => $log_id
    ]);

    if ($updated) {
        if ($isJsonRequest) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'event' => 'TIME_OUT',
                'student_id' => $role_id,
                'full_name' => $name,
                'status' => $final_status,
                'stay_percentage' => $cumulative_percentage,
                'total_stay_seconds' => $total_stay,
                'exit_timestamp' => $current_datetime,
                'formatted_time' => date('g:i A', strtotime($current_datetime))
            ]);
        } else {
            echo "OUT_SUCCESS\n" . $name;
        }
    } else {
        http_response_code(500);
        echo $isJsonRequest ? json_encode(['success' => false, 'error' => 'Database update error']) : "ERROR";
    }
    exit;
}

echo "BAD_REQUEST";
