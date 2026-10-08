<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'tech_support'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
header('Content-Type: application/json');

$schedule_id = $_GET['schedule_id'] ?? null;
if (!$schedule_id) {
    echo json_encode(['error' => 'No schedule_id provided']);
    exit;
}

$today = date('Y-m-d');

$present_count = 0;
$late_count = 0;
$absent_count = 0;
$enrolled_count = 0; 
$schedStmt = $pdo->prepare("SELECT section FROM class_schedules WHERE schedule_id = ?");
$schedStmt->execute([$schedule_id]);
$sched = $schedStmt->fetch(PDO::FETCH_ASSOC);
if ($sched) {
    $enrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND section=?");
    $enrStmt->execute([$sched['section']]);
    $enrolled_count = (int)$enrStmt->fetchColumn();
}

$feedStmt = $pdo->prepare("
    SELECT a.entry_timestamp, a.stay_percentage, a.status, u.full_name 
    FROM attendance_logs a
    JOIN users u ON a.student_id = u.id
    WHERE a.schedule_id = :sched_id AND a.log_date = :today
    ORDER BY a.entry_timestamp DESC
");
$feedStmt->execute([':sched_id' => $schedule_id, ':today' => $today]);
$attendance_feed = $feedStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($attendance_feed as &$log) {
    if ($log['status'] === 'Present') $present_count++;
    elseif ($log['status'] === 'Late') $late_count++;
    elseif ($log['status'] === 'Absent' || $log['status'] === 'Dropped') $absent_count++;
    
    $log['formatted_time'] = date('g:i A', strtotime($log['entry_timestamp']));
}

echo json_encode([
    'enrolled' => $enrolled_count,
    'present' => $present_count,
    'late' => $late_count,
    'absent' => $absent_count,
    'feed' => $attendance_feed
]);
