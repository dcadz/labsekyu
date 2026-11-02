<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$api_key = isset($_GET['api_key']) ? $_GET['api_key'] : '';
$valid_api_key = 'labsekyu_secret_key_123!';

if (!isset($_SESSION['user_id']) && $api_key !== $valid_api_key) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// core/log.php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../config/database.php';

if (isset($_GET['uid'])) {
    $uid = trim($_GET['uid']);
    $device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : 'Unknown';
    $date = date("Y-m-d");
    $time = date("H:i:s");
    $current_datetime = date("Y-m-d H:i:s");
    $day_of_week = date("l");

    // Fetch room status
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='room_status'");
    $stmt->execute();
    $room_status_row = $stmt->fetch();
    $room_status = $room_status_row ? $room_status_row['setting_value'] : 'LOCKED';

    // Verify user exists in the users table using role_id (RFID tag)
    $stmt = $pdo->prepare("SELECT id, full_name, role, section FROM users WHERE role_id=:uid");
    $stmt->execute([':uid' => $uid]);
    $user = $stmt->fetch();
    
    if (!$user) {
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value=:uid WHERE setting_key='staged_uid'");
        $stmt->execute([':uid' => $uid]);
        echo "UNREGISTERED";
        exit();
    }
    
    $student_id = $user['id'];
    $name = $user['full_name'];

    // Cooldown logic: Prevent toggling IN/OUT within 2 minutes
    $time_threshold = date("Y-m-d H:i:s", strtotime("-2 minutes"));
    $stmt = $pdo->prepare("SELECT entry_timestamp, exit_timestamp FROM attendance_logs WHERE student_id=:student_id AND log_date=:date ORDER BY log_id DESC LIMIT 1");
    $stmt->execute([':student_id' => $student_id, ':date' => $date]);
    $recent_log = $stmt->fetch();
    
    if ($recent_log) {
        if ($recent_log['exit_timestamp'] === NULL) {
            if ($recent_log['entry_timestamp'] >= $time_threshold) {
                echo "COOLDOWN_IN_EFFECT\n" . $name;
                exit();
            }
        } else {
            if ($recent_log['exit_timestamp'] >= $time_threshold) {
                echo "COOLDOWN_IN_EFFECT\n" . $name;
                exit();
            }
        }
    }

    $stmt = $pdo->prepare("SELECT log_id FROM attendance_logs WHERE student_id=:student_id AND log_date=:date AND exit_timestamp IS NULL");
    $stmt->execute([':student_id' => $student_id, ':date' => $date]);
    $open_log = $stmt->fetch();
    $is_currently_in = $open_log ? true : false;
    $log_id = $is_currently_in ? $open_log['log_id'] : null;

    if ($device_id === 'IN' || ($device_id !== 'OUT' && !$is_currently_in)) {
        if ($is_currently_in) {
            echo "ALREADY_IN\n" . $name;
        } else {
            // Find active class schedule (allow check-in 30 mins early)
            $room_id = isset($_GET['room_id']) ? trim($_GET['room_id']) : null;
            if ($room_id) {
                $stmt = $pdo->prepare("SELECT schedule_id, start_time, end_time, section FROM class_schedules WHERE day_of_week=:day AND :time >= SUBTIME(start_time, '00:30:00') AND :time <= end_time AND room_id=:room LIMIT 1");
                $stmt->execute([':day' => $day_of_week, ':time' => $time, ':room' => $room_id]);
            } else {
                $stmt = $pdo->prepare("SELECT schedule_id, start_time, end_time, section FROM class_schedules WHERE day_of_week=:day AND :time >= SUBTIME(start_time, '00:30:00') AND :time <= end_time LIMIT 1");
                $stmt->execute([':day' => $day_of_week, ':time' => $time]);
            }
            $schedule = $stmt->fetch();
            $schedule_id = $schedule ? $schedule['schedule_id'] : null;
            
            // Student restrictions
            if ($user['role'] === 'student') {
                if (!$schedule) {
                    echo "NO_SCHEDULE\n" . $name;
                    exit();
                }
                if ($user['section'] !== $schedule['section']) {
                    echo "INVALID_SECTION\n" . $name . "\n" . "Required: " . $schedule['section'];
                    exit();
                }
            }
            
            // Determine Late or Present based on schedule (15 min grace period)
            $status = "Present";
            if ($schedule) {
                $start_time_ts = strtotime($date . ' ' . $schedule['start_time']);
                $current_ts = strtotime($current_datetime);
                // 15 minutes = 900 seconds
                if ($current_ts > ($start_time_ts + 900)) {
                    $status = "Late";
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO attendance_logs (student_id, schedule_id, log_date, entry_timestamp, status, sync_source) VALUES (:student_id, :schedule_id, :date, :entry, :status, 'Live')");
            $inserted = $stmt->execute([
                ':student_id' => $student_id,
                ':schedule_id' => $schedule_id,
                ':date' => $date,
                ':entry' => $current_datetime,
                ':status' => $status
            ]);
            
            if ($inserted) {
                echo "IN_" . strtoupper($status) . "\n" . $name;
            } else {
                echo "ERROR";
            }
        }
    } else if ($device_id === 'OUT' || ($device_id !== 'IN' && $is_currently_in)) {
        if ($is_currently_in) {
            // Get the open log to find the entry timestamp and schedule id
            $stmt = $pdo->prepare("SELECT entry_timestamp, schedule_id, status FROM attendance_logs WHERE log_id=:log_id");
            $stmt->execute([':log_id' => $log_id]);
            $current_log = $stmt->fetch();
            
            $stay_percentage = 0.00;
            $new_status = $current_log['status'];
            
            if ($current_log['schedule_id']) {
                $stmt = $pdo->prepare("SELECT start_time, end_time FROM class_schedules WHERE schedule_id=:schedule_id");
                $stmt->execute([':schedule_id' => $current_log['schedule_id']]);
                $schedule = $stmt->fetch();
                
                if ($schedule) {
                    $entry_time = strtotime($current_log['entry_timestamp']);
                    $exit_time = strtotime($current_datetime);
                    $actual_duration = $exit_time - $entry_time;
                    
                    $start_time = strtotime($date . ' ' . $schedule['start_time']);
                    $end_time = strtotime($date . ' ' . $schedule['end_time']);
                    $total_class_duration = $end_time - $start_time;
                    
                    if ($total_class_duration > 0) {
                        $stay_percentage = ($actual_duration / $total_class_duration) * 100;
                        if ($stay_percentage > 100) $stay_percentage = 100;
                        
                        // If they didn't complete 85% of class, marked as Absent.
                        if ($stay_percentage < 85) {
                            $new_status = 'Absent';
                        }
                    }
                }
            } else {
                $stay_percentage = 100.00;
            }
            
            $stmt = $pdo->prepare("UPDATE attendance_logs SET exit_timestamp=:exit, stay_percentage=:pct, status=:status WHERE log_id=:log_id");
            $updated = $stmt->execute([
                ':exit' => $current_datetime,
                ':pct' => $stay_percentage,
                ':status' => $new_status,
                ':log_id' => $log_id
            ]);
            
            if ($updated) {
                echo "OUT_SUCCESS\n" . $name;
            } else {
                echo "ERROR";
            }
        } else {
            echo "NOT_IN\n" . $name;
        }
    }
} else {
    echo "BAD_REQUEST";
}
?>