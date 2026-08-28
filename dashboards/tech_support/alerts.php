<?php
// dashboards/tech_support/alerts.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support']);
require_once __DIR__ . '/../../core/db_connect.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_alert_id'])) {
        $stmt = $pdo->prepare("DELETE FROM system_alerts WHERE alert_id = ?");
        if ($stmt->execute([$_POST['delete_alert_id']])) {
            $message = "Alert deleted successfully!";
        } else {
            $message = "Failed to delete alert.";
        }
    } elseif (isset($_POST['delete_all'])) {
        $stmt = $pdo->query("TRUNCATE TABLE system_alerts");
        $message = "All system alerts have been cleared.";
    } elseif (isset($_POST['resolve_ticket_id'])) {
        $stmt = $pdo->prepare("UPDATE teacher_alerts SET status = 'resolved' WHERE id = ?");
        if ($stmt->execute([$_POST['resolve_ticket_id']])) {
            $message = "Teacher ticket resolved!";
        } else {
            $message = "Failed to resolve ticket.";
        }
    } else {
        $severity = $_POST['severity'] ?? 'Med';
        $location = $_POST['location'] ?? '';
        $event_type = $_POST['event_type'] ?? '';

        if (!empty($location) && !empty($event_type)) {
            $stmt = $pdo->prepare("INSERT INTO system_alerts (severity, location, event_type) VALUES (?, ?, ?)");
            if ($stmt->execute([$severity, $location, $event_type])) {
                $message = "Alert successfully dispatched!";
            } else {
                $message = "Failed to send alert.";
            }
        }
    }
}

// Fetch system alerts
$alertStmt = $pdo->query("SELECT * FROM system_alerts ORDER BY created_at DESC LIMIT 50");
$alerts = $alertStmt->fetchAll();

// Fetch pending teacher tickets
$teacherAlertStmt = $pdo->query("
    SELECT t.id, t.room, t.message, t.created_at, u.full_name 
    FROM teacher_alerts t
    JOIN users u ON t.teacher_id = u.id
    WHERE t.status = 'pending'
    ORDER BY t.created_at DESC
");
$teacher_tickets = $teacherAlertStmt->fetchAll();
$pendingCount = count($teacher_tickets);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Alerts | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--bg-sidebar); font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; }
        
        .alert-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .icon-High { background: rgba(239, 68, 68, 0.2); color: var(--accent-red); }
        .icon-Med { background: rgba(234, 179, 8, 0.2); color: var(--accent-yellow); }
        .icon-Low { background: rgba(34, 197, 94, 0.2); color: var(--accent-green); }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; margin-bottom: 0.25rem; }
        .alert-desc { font-size: 0.875rem; color: var(--text-secondary); }
        .alert-time { font-size: 0.75rem; color: var(--text-secondary); text-align: right; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="techsupport.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="register_student.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Register Face
        </a>
        <a href="alerts.php" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                <span style="background: var(--accent-red); color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?= $pendingCount ?></span>
            <?php endif; ?>
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
        <div class="admin-badge">ADMIN</div>
    </div>
</aside>

<main class="main-content">
    <div class="dashboard-container layout-2col-1-2" style="max-width: 1200px;">
        
        <!-- Create Alert Form -->
        <div class="card">
            <div class="card-header">
                <h2>Dispatch System Alert</h2>
                <p style="font-size: 0.875rem; color: #64748b; margin-top: 0.25rem;">Use this to notify the system or teachers about unopened rooms or security issues.</p>
            </div>
            
            <?php if ($message): ?>
                <div style="background: rgba(34, 197, 94, 0.2); color: #15803d; padding: 1rem; border-radius: 0.5rem; margin-bottom: 1rem;">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label>Severity</label>
                    <select name="severity" required>
                        <option value="High">High (Security Issue)</option>
                        <option value="Med" selected>Medium (Teacher not opening room)</option>
                        <option value="Low">Low (General Notice)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Room / Location</label>
                    <select name="location" required>
                        <option value="">Select Room</option>
                        <?php 
                        $rooms = ['ICT 101', 'ICT 102', 'ICT 103', 'ICT 104', 'ICT 105', 'ICT 106', 'ICT 107', 'ICT 108',
                                  'ICT 201', 'ICT 202', 'ICT 203', 'ICT 204', 'ICT 205', 'ICT 206', 'ICT 207', 'ICT 208',
                                  'ICT 301', 'ICT 302', 'ICT 303', 'ICT 304', 'ICT 305', 'ICT 306', 'ICT 307', 'ICT 308', 'ICT 309'];
                        foreach($rooms as $rm): ?>
                            <option value="<?= $rm ?>"><?= $rm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Alert Description</label>
                    <input type="text" name="event_type" placeholder="e.g. Room not opened for IT 127" required>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; margin-top: 1rem;">Send Alert</button>
            </form>
        </div>

        <!-- System Alerts Feed -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2>Recent System Alerts</h2>
                <?php if(!empty($alerts)): ?>
                <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete ALL system alerts? This cannot be undone.');">
                    <input type="hidden" name="delete_all" value="1">
                    <button type="submit" style="background: #8b0000; border: none; color: #ffffff; padding: 0.5rem 1rem; border-radius: 0.5rem; cursor: pointer; font-size: 0.875rem; font-weight: bold; box-shadow: 0 4px 6px rgba(0,0,0,0.3); transition: background 0.3s ease;" onmouseover="this.style.background='#a50000'" onmouseout="this.style.background='#8b0000'">Delete All</button>
                </form>
                <?php endif; ?>
            </div>
            
            <?php if(empty($alerts)): ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 3rem;">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 1rem; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p>No system alerts at the moment.</p>
                </div>
            <?php else: ?>
                <?php foreach($alerts as $alert): 
                    $sev = $alert['severity'];
                    $iconClass = "icon-{$sev}";
                    $iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>';
                    if($sev == 'High') $iconSvg = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>';
                ?>
                <div class="alert-card">
                    <div class="alert-icon <?= $iconClass ?>">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?= $iconSvg ?>
                        </svg>
                    </div>
                    <div class="alert-content">
                        <div class="alert-title">
                            <?= htmlspecialchars($alert['location']) ?> - <span style="color: var(--text-primary);"><?= htmlspecialchars($alert['severity']) ?> Priority</span>
                        </div>
                        <div class="alert-desc">
                            <?= htmlspecialchars($alert['event_type']) ?>
                        </div>
                    </div>
                    <div class="alert-time">
                        <div><?= date('M d, Y g:i A', strtotime($alert['created_at'])) ?></div>
                        <button class="btn-delete-alert" data-id="<?= $alert['alert_id'] ?>" style="background:none; border:none; color:var(--accent-red); cursor:pointer; margin-top:0.5rem;" title="Delete Alert">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
    
    <!-- Teacher Tickets Feed -->
    <div class="dashboard-container" style="max-width: 1200px; margin-top: 2rem;">
        <div class="card" style="border: 1px solid var(--accent-yellow);">
            <div class="card-header">
                <h2>Pending Teacher Tickets</h2>
                <p style="font-size: 0.875rem; color: #64748b; margin-top: 0.25rem;">Issues reported directly by teachers.</p>
            </div>
            
            <?php if(empty($teacher_tickets)): ?>
                <div style="text-align: center; color: var(--text-secondary); padding: 3rem;">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-bottom: 1rem; opacity: 0.5;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <p>No pending issues reported by teachers.</p>
                </div>
            <?php else: ?>
                <?php foreach($teacher_tickets as $ticket): ?>
                <div class="alert-card" style="border-left: 4px solid var(--accent-yellow);">
                    <div class="alert-icon" style="background: rgba(234, 179, 8, 0.2); color: var(--accent-yellow);">
                        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                    </div>
                    <div class="alert-content">
                        <div class="alert-title">
                            <?= htmlspecialchars($ticket['full_name']) ?> <span style="color: var(--text-secondary); font-weight: normal; font-size: 0.875rem;">reported an issue in</span> <?= htmlspecialchars($ticket['room']) ?>
                        </div>
                        <div class="alert-desc" style="color: var(--text-primary); font-style: italic; margin-top: 0.5rem; background: rgba(0,0,0,0.1); padding: 0.5rem; border-radius: 0.25rem;">
                            "<?= htmlspecialchars($ticket['message']) ?>"
                        </div>
                    </div>
                    <div class="alert-time" style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
                        <div><?= date('M d, Y g:i A', strtotime($ticket['created_at'])) ?></div>
                        <form method="POST" action="">
                            <input type="hidden" name="resolve_ticket_id" value="<?= $ticket['id'] ?>">
                            <button type="submit" class="btn btn-primary" style="padding: 0.25rem 0.75rem; font-size: 0.875rem; border-radius: 0.25rem; background: var(--accent-green); border: none; cursor: pointer;">Resolve</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<form id="delete-form" method="POST" style="display:none;">
    <input type="hidden" name="delete_alert_id" id="delete_alert_id">
</form>
<script src="../../assets/js/logout.js"></script>
<script>
document.querySelectorAll('.btn-delete-alert').forEach(btn => {
    btn.addEventListener('click', function() {
        const alertId = this.dataset.id;
        Swal.fire({
            title: 'Delete System Alert?',
            text: "Are you sure you want to remove this alert?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('delete_alert_id').value = alertId;
                document.getElementById('delete-form').submit();
            }
        });
    });
});
<?php if ($message): ?>
Swal.fire({
    title: '<?= htmlspecialchars($message) ?>',
    icon: '<?= strpos($message, 'Failed') !== false ? 'error' : 'success' ?>',
    timer: 3000,
    showConfirmButton: false
});
<?php endif; ?>
</script>
</body>
</html>
