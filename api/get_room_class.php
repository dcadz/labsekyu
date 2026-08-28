<?php
require_once __DIR__ . '/../core/db_connect.php';

header('Content-Type: application/json');

$room_id = $_GET['room_id'] ?? '';

if (!$room_id) {
    echo JSON_encode(['success' => false, 'error' => 'No room specified']);
    exit;
}

$day_of_week = date('l');
$current_time = date('H:i:s');

try {
    // Find current class
    $stmt = $pdo->prepare("
        SELECT c.schedule_id, c.subject_code, c.section, c.start_time, c.end_time, u.full_name as teacher_name
        FROM class_schedules c
        LEFT JOIN users u ON c.teacher_id = u.id
        WHERE c.room_id = ? 
          AND c.day_of_week = ?
          AND c.start_time <= ? 
          AND c.end_time >= ?
        LIMIT 1
    ");
    $stmt->execute([$room_id, $day_of_week, $current_time, $current_time]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no current class, find the next one today
    if (!$class) {
        $stmt = $pdo->prepare("
            SELECT c.schedule_id, c.subject_code, c.section, c.start_time, c.end_time, u.full_name as teacher_name
            FROM class_schedules c
            LEFT JOIN users u ON c.teacher_id = u.id
            WHERE c.room_id = ? 
              AND c.day_of_week = ?
              AND c.start_time > ?
            ORDER BY c.start_time ASC
            LIMIT 1
        ");
        $stmt->execute([$room_id, $day_of_week, $current_time]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Calculate Summary Stats
    $today = date('Y-m-d');
    
    // 1. Total System Schedules
    $stmt_sys_schedules = $pdo->query("SELECT COUNT(*) FROM class_schedules");
    $total_system_schedules = $stmt_sys_schedules->fetchColumn();

    // 2. Total classes today for this room (extra metric)
    $stmt_classes = $pdo->prepare("SELECT COUNT(*) FROM class_schedules WHERE room_id = ? AND day_of_week = ?");
    $stmt_classes->execute([$room_id, $day_of_week]);
    $classes_today = $stmt_classes->fetchColumn();

    // 3. Total Registered Students (system wide)
    $stmt_registered = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
    $total_registered_students = $stmt_registered->fetchColumn();

    $active_students = 0;

    if ($class) {
        // 4. Active students (present in attendance logs for this specific class)
        $stmt_active = $pdo->prepare("SELECT COUNT(*) FROM attendance_logs WHERE schedule_id = ? AND log_date = ? AND status IN ('Present', 'Late')");
        $stmt_active->execute([$class['schedule_id'], $today]);
        $active_students = $stmt_active->fetchColumn();
    }

    if ($class) {
        // Format times
        $start = date('g:i A', strtotime($class['start_time']));
        $end = date('g:i A', strtotime($class['end_time']));
        
        echo json_encode([
            'success' => true,
            'class_found' => true,
            'code' => $class['section'],
            'subject' => $class['subject_code'] . ' - ' . strtoupper($class['teacher_name']),
            'time' => $start . ' - ' . $end,
            'teacher' => $class['teacher_name'],
            'summary' => [
                'total_system_schedules' => $total_system_schedules,
                'classes_today' => $classes_today,
                'total_registered_students' => $total_registered_students,
                'active_students' => $active_students
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'class_found' => false,
            'code' => 'VACANT',
            'subject' => 'No scheduled class',
            'time' => '--:--',
            'summary' => [
                'total_system_schedules' => $total_system_schedules,
                'classes_today' => $classes_today,
                'total_registered_students' => $total_registered_students,
                'active_students' => 0
            ]
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
