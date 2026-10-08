<?php
// dashboards/teacher/export_csv.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['teacher']);

require_once __DIR__ . '/../../../config/database.php';

$schedule_id = $_GET['schedule_id'] ?? null;
$today = date('Y-m-d');

if (!$schedule_id) {
    die("No schedule ID provided.");
}

// Ensure the teacher actually owns this schedule
$stmt = $pdo->prepare("SELECT subject_code, section FROM class_schedules WHERE schedule_id = :sched_id AND teacher_id = :teacher_id");
$stmt->execute([':sched_id' => $schedule_id, ':teacher_id' => $_SESSION['user_id']]);
$schedule = $stmt->fetch();

if (!$schedule) {
    die("Unauthorized or invalid schedule.");
}

$feedStmt = $pdo->prepare("
    SELECT u.full_name, a.entry_timestamp, a.exit_timestamp, a.stay_percentage, a.status 
    FROM attendance_logs a
    JOIN users u ON a.student_id = u.id
    WHERE a.schedule_id = :sched_id AND a.log_date = :today
    ORDER BY u.full_name ASC
");
$feedStmt->execute([':sched_id' => $schedule_id, ':today' => $today]);
$logs = $feedStmt->fetchAll();

$filename = "Monitoring_Form_" . preg_replace('/[^a-zA-Z0-9]/', '_', $schedule['subject_code'] . '_' . $schedule['section']) . "_" . $today . ".csv";

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

fputcsv($output, ['Student Name', 'Time In', 'Time Out', 'Stay (%)', 'Status']);

foreach ($logs as $log) {
    $time_in = $log['entry_timestamp'] ? date('H:i:s', strtotime($log['entry_timestamp'])) : '--:--:--';
    $time_out = $log['exit_timestamp'] ? date('H:i:s', strtotime($log['exit_timestamp'])) : '--:--:--';
    fputcsv($output, [
        $log['full_name'],
        $time_in,
        $time_out,
        $log['stay_percentage'] . '%',
        $log['status']
    ]);
}

fclose($output);
exit();
?>
