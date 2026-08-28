<?php
// dashboards/student/schedule.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['student']);
require_once __DIR__ . '/../../core/db_connect.php';

// Fetch master schedule (ordered by day and time)
$stmt = $pdo->query("SELECT * FROM class_schedules ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time ASC");
$schedules = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="student.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Register Face
        </a>
        <a href="#" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <div style="margin-top: auto; padding: 1rem;">
            <a href="../../auth/logout.php" onclick="confirmLogout(event);" style="display: block; width: 100%; padding: 0.75rem; background: #ffffff; color: #dc2626; font-weight: bold; text-align: center; border-radius: 0.5rem; text-decoration: none; box-sizing: border-box; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Logout</a>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="sidebar-brand">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path></svg>
            LABSEKYU
        </div>
        <div class="admin-badge" style="background: rgba(255, 255, 255, 0.1); color: var(--text-secondary); font-size: 0.875rem;">STUDENT</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container" style="max-width: 1200px;">
        <h1 style="font-size: 2rem; margin-bottom: 2rem; color: var(--text-primary);">Master Class Schedule</h1>
        
        <div class="card">
            <table class="modern-table" style="width: 100%; text-align: left;">
                <thead>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <th style="padding: 1rem; color: var(--text-secondary);">Subject</th>
                        <th style="padding: 1rem; color: var(--text-secondary);">Section</th>
                        <th style="padding: 1rem; color: var(--text-secondary);">Room</th>
                        <th style="padding: 1rem; color: var(--text-secondary);">Day</th>
                        <th style="padding: 1rem; color: var(--text-secondary);">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="5" style="padding: 2rem; text-align: center; color: var(--text-secondary);">No class schedules have been set by Tech Support yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($schedules as $sched): ?>
                            <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                                <td style="padding: 1rem; font-weight: 500;"><?= htmlspecialchars($sched['subject_code']) ?></td>
                                <td style="padding: 1rem;"><?= htmlspecialchars($sched['section']) ?></td>
                                <td style="padding: 1rem; color: var(--accent-yellow);"><?= htmlspecialchars($sched['room_id']) ?></td>
                                <td style="padding: 1rem;"><?= htmlspecialchars($sched['day_of_week']) ?></td>
                                <td style="padding: 1rem;">
                                    <?= date('g:i A', strtotime($sched['start_time'])) ?> - <?= date('g:i A', strtotime($sched['end_time'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<script src="../../assets/js/logout.js"></script>
</body>
</html>
