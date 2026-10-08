<?php
// cron_absent.php
// Run this script periodically via a cron job (e.g., every 5 minutes)

require_once __DIR__ . '/config/database.php';

date_default_timezone_set('Asia/Manila');

$today = date('Y-m-d');
$current_time = date('H:i:s');
$current_day_of_week = date('l');

try {
    // 1. Find schedules that have ended today but haven't been processed yet.
    // (Assuming you add an 'is_processed' column to class_schedules, or check dynamically).
    // For this example, we'll process schedules that ended within the last 15 minutes to avoid duplicate processing if run every 5 mins.
    $time_15_mins_ago = date('H:i:s', strtotime('-15 minutes'));

    $stmt = $pdo->prepare("
        SELECT schedule_id, section 
        FROM class_schedules 
        WHERE day_of_week = :day 
          AND end_time <= :current_time 
          AND end_time >= :time_ago
    ");
    $stmt->execute([
        ':day' => $current_day_of_week,
        ':current_time' => $current_time,
        ':time_ago' => $time_15_mins_ago
    ]);
    
    $ended_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ended_schedules as $sched) {
        $schedule_id = $sched['schedule_id'];
        $section = $sched['section'];

        // 2. Get all enrolled students for this section
        $studentStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'student' AND section = :section");
        $studentStmt->execute([':section' => $section]);
        $enrolled_students = $studentStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($enrolled_students)) {
            continue;
        }

        // 3. Find students who ALREADY have an attendance log for this schedule today
        $placeholders = implode(',', array_fill(0, count($enrolled_students), '?'));
        $logStmt = $pdo->prepare("
            SELECT student_id 
            FROM attendance_logs 
            WHERE schedule_id = ? 
              AND log_date = ? 
              AND student_id IN ($placeholders)
        ");
        
        $params = array_merge([$schedule_id, $today], $enrolled_students);
        $logStmt->execute($params);
        $present_students = $logStmt->fetchAll(PDO::FETCH_COLUMN);

        // 4. Determine absent students
        $absent_students = array_diff($enrolled_students, $present_students);

        // 5. Insert 'Absent' records for missing students
        if (!empty($absent_students)) {
            $insertQuery = "INSERT INTO attendance_logs (student_id, schedule_id, log_date, status, sync_source) VALUES ";
            $insertValues = [];
            $insertParams = [];

            foreach ($absent_students as $student_id) {
                $insertValues[] = "(?, ?, ?, 'Absent', 'System')";
                $insertParams[] = $student_id;
                $insertParams[] = $schedule_id;
                $insertParams[] = $today;
            }

            $insertQuery .= implode(', ', $insertValues);
            $insertStmt = $pdo->prepare($insertQuery);
            $insertStmt->execute($insertParams);
        }
    }
    
    echo "Absent logic processed successfully.\n";

} catch (PDOException $e) {
    error_log("Cron Absent Error: " . $e->getMessage());
    echo "Error processing absences.\n";
}
?>
