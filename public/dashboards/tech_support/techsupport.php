<?php
// dashboards/tech_support/index.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../../config/database.php';

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM teacher_alerts WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

// --- SCHEDULE-BASED AUTO UNLOCK LOGIC ---
$currentTime = date('H:i:s');
$currentDay = date('l');

// 1. Find rooms active RIGHT NOW
$activeRoomsStmt = $pdo->prepare("SELECT DISTINCT room_id FROM class_schedules WHERE day_of_week = ? AND start_time <= ? AND end_time >= ?");
$activeRoomsStmt->execute([$currentDay, $currentTime, $currentTime]);
$scheduledActiveRooms = $activeRoomsStmt->fetchAll(PDO::FETCH_COLUMN);

if (!empty($scheduledActiveRooms)) {
    $inQuery = implode(',', array_fill(0, count($scheduledActiveRooms), '?'));
    $updateDoors = $pdo->prepare("UPDATE door_status SET is_locked = 0 WHERE room_id IN ($inQuery)");
    $updateDoors->execute(array_values($scheduledActiveRooms));
}

// 2. Lock scheduled rooms that are NOT active right now
$allScheduledRoomsStmt = $pdo->prepare("SELECT DISTINCT room_id FROM class_schedules WHERE day_of_week = ?");
$allScheduledRoomsStmt->execute([$currentDay]);
$allScheduledRooms = $allScheduledRoomsStmt->fetchAll(PDO::FETCH_COLUMN);

$roomsToLock = array_diff($allScheduledRooms, $scheduledActiveRooms);
if (!empty($roomsToLock)) {
    $inQuery = implode(',', array_fill(0, count($roomsToLock), '?'));
    $lockDoors = $pdo->prepare("UPDATE door_status SET is_locked = 1 WHERE room_id IN ($inQuery)");
    $lockDoors->execute(array_values($roomsToLock));
}
// ----------------------------------------

// Fetch All Door Statuses to determine occupancy/unlocked status
$doorStmt = $pdo->query("SELECT room_id FROM door_status WHERE is_locked = 0");
$unlockedRooms = $doorStmt->fetchAll(PDO::FETCH_COLUMN);

// Mock current class for Room Utilization
$current_class = [
    'room' => 'ICT 204',
    'code' => 'BSIT 4-A',
    'subject' => 'IT 127 - MR. CABIA',
    'time' => '8:00 PM - 9:30 AM'
];

$today = date('Y-m-d');
$feedStmt = $pdo->prepare("
    SELECT a.*, u.full_name, c.section 
    FROM attendance_logs a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN class_schedules c ON a.schedule_id = c.schedule_id
    WHERE a.log_date = :today
    ORDER BY a.entry_timestamp DESC
    LIMIT 5
");
$feedStmt->execute([':today' => $today]);
$attendance_feed = $feedStmt->fetchAll();

// If empty, mock data for the design demonstration
if (empty($attendance_feed)) {
    $attendance_feed = [
        ['full_name' => 'Mikyla Grace Guion', 'entry_timestamp' => '2026-08-26 07:02:00', 'stay_percentage' => '92', 'status' => 'Present'],
        ['full_name' => 'John Dave Cadiz', 'entry_timestamp' => '2026-08-26 07:10:00', 'stay_percentage' => '88', 'status' => 'Present'],
        ['full_name' => 'Liza Claire Gagante', 'entry_timestamp' => '2026-08-26 07:31:00', 'stay_percentage' => '74', 'status' => 'Late'],
        ['full_name' => 'Angel Blase Condag', 'entry_timestamp' => '2026-08-26 07:35:00', 'stay_percentage' => '61', 'status' => 'Late'],
        ['full_name' => 'Billy Brendan Cabia', 'entry_timestamp' => '2026-08-26 07:04:00', 'stay_percentage' => '95', 'status' => 'Present']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tech Support | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= time() ?>">
    <script>
        let currentSelectedRoom = 'ICT Lab 1';

        async function addRoom() {
            const roomName = prompt("Enter new room name (e.g., 'Lab 401'):");
            if (!roomName) return;

            try {
                const res = await fetch('../../api/add_room.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_name: roomName })
                });
                const data = await res.json();
                if (data.success) {
                    // Reload page to reflect new grid
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('Request failed');
            }
        }

        async function deleteRoom() {
            if (!confirm(`Are you sure you want to delete room '${currentSelectedRoom}'?`)) return;
            try {
                const res = await fetch('../../api/delete_room.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_name: currentSelectedRoom })
                });
                const data = await res.json();
                if (data.success) {
                    const gridCell = document.getElementById('grid-room-' + currentSelectedRoom);
                    if (gridCell) {
                        gridCell.remove();
                    }
                    alert('Room deleted successfully.');
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('Request failed');
            }
        }

        async function toggleDoor(action) {
            try {
                const res = await fetch('../../api/toggle_door.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_id: currentSelectedRoom, action: action })
                });
                const data = await res.json();
                if (data.success) {
                    // Refresh the door UI for the current room
                    changeRoom(currentSelectedRoom, true); // true to skip camera refresh
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('Request failed');
            }
        }
    </script>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="techsupport.php" class="sidebar-link active">
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
        <a href="alerts.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
            Alerts
            <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                <span style="background: var(--accent-red); color: white; border-radius: 50%; padding: 2px 8px; font-size: 0.75rem; margin-left: auto; font-weight: bold;"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <div style="margin-top: auto; padding: 1rem;">
            <a href="../../logout.php" onclick="confirmLogout(event);" style="display: block; width: 100%; padding: 0.75rem; background: #ffffff; color: #dc2626; font-weight: bold; text-align: center; border-radius: 0.5rem; text-decoration: none; box-sizing: border-box; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">Logout</a>
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
    <div class="dashboard-container" style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 1400px;">
        
        <!-- ROOM OCCUPANCY -->
        <div class="card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2>ROOM OCCUPANCY</h2>
                <div>
                    <button class="btn btn-primary" onclick="addRoom()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">+ Add Room</button>
                    <button class="btn btn-primary" onclick="deleteRoom()" style="padding: 0.5rem 1rem; font-size: 0.875rem; background: var(--accent-red); margin-left: 0.5rem;">Delete Room</button>
                </div>
            </div>
            <div class="tier-label">3rd Floor</div>
            <div class="tier-grid">
                <?php
                $rooms_300 = ['301', '302', '303', '304', '305', '306', '307', '308', '309'];
                foreach ($rooms_300 as $room) {
                    $isOcc = in_array($room, $unlockedRooms);
                    $bg = $isOcc ? 'var(--accent-green)' : '#64748b';
                    $color = '#fff';
                    echo "<div id=\"grid-room-$room\" onclick=\"changeRoom('$room')\" style='background: $bg; color: $color; text-align: center; padding: 1.2rem 0.5rem; font-weight: bold; font-size: 1.125rem; cursor: pointer; transition: opacity 0.2s, background 0.3s;' onmouseover=\"this.style.opacity='0.8'\" onmouseout=\"this.style.opacity='1'\">$room</div>";
                }
                ?>
            </div>

            <div class="tier-label">2nd Floor</div>
            <div class="tier-grid">
                <?php
                $rooms_200 = ['201', '202', '203', '204', '205', '206', '207', '208'];
                foreach ($rooms_200 as $room) {
                    $isOcc = in_array($room, $unlockedRooms);
                    $bg = $isOcc ? 'var(--accent-green)' : '#64748b';
                    $color = '#fff';
                    echo "<div id=\"grid-room-$room\" onclick=\"changeRoom('$room')\" style='background: $bg; color: $color; text-align: center; padding: 1.2rem 0.5rem; font-weight: bold; font-size: 1.125rem; cursor: pointer; transition: opacity 0.2s, background 0.3s;' onmouseover=\"this.style.opacity='0.8'\" onmouseout=\"this.style.opacity='1'\">$room</div>";
                }
                ?>
            </div>

            <div class="tier-label">1st Floor</div>
            <div class="tier-grid">
                <?php
                $rooms_100 = ['101', '102', '103', '104', '105', '106', '107', '108'];
                foreach ($rooms_100 as $room) {
                    $isOcc = in_array($room, $unlockedRooms);
                    $bg = $isOcc ? 'var(--accent-green)' : '#64748b';
                    $color = '#fff';
                    echo "<div id=\"grid-room-$room\" onclick=\"changeRoom('$room')\" style='background: $bg; color: $color; text-align: center; padding: 1.2rem 0.5rem; font-weight: bold; font-size: 1.125rem; cursor: pointer; transition: opacity 0.2s, background 0.3s;' onmouseover=\"this.style.opacity='0.8'\" onmouseout=\"this.style.opacity='1'\">$room</div>";
                }
                ?>
            </div>

            <!-- DYNAMIC ROOMS (MAX 9 GRID) -->
            <div class="tier-label" style="margin-top: 1.5rem;">Dynamic Rooms</div>
            <div class="tier-grid" style="grid-template-columns: repeat(9, 1fr);">
                <?php
                // Fetch all rooms from DB
                $allDbRoomsStmt = $pdo->query("SELECT room_id FROM door_status");
                $allDbRooms = $allDbRoomsStmt->fetchAll(PDO::FETCH_COLUMN);
                $hardcodedRooms = array_merge($rooms_300, $rooms_200, $rooms_100);
                
                $dynamicRooms = array_diff($allDbRooms, $hardcodedRooms);
                
                if (empty($dynamicRooms)) {
                    echo "<div style='grid-column: 1 / -1; color: var(--text-secondary); font-size: 0.875rem;'>No dynamic rooms added yet.</div>";
                } else {
                    foreach ($dynamicRooms as $room) {
                        $isOcc = in_array($room, $unlockedRooms);
                        $bg = $isOcc ? 'var(--accent-green)' : '#64748b';
                        $color = '#fff';
                        echo "<div id=\"grid-room-$room\" onclick=\"changeRoom('$room')\" style='background: $bg; color: $color; text-align: center; padding: 1.2rem 0.5rem; font-weight: bold; font-size: 1.125rem; cursor: pointer; transition: opacity 0.2s, background 0.3s;' onmouseover=\"this.style.opacity='0.8'\" onmouseout=\"this.style.opacity='1'\">$room</div>";
                    }
                }
                ?>
            </div>

        </div>

        <!-- 2-COLUMN LAYOUT -->
        <div class="layout-2col-2-1">
            <!-- LEFT COLUMN -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- Live Camera Field -->
                <div class="card" style="flex: 1; display: flex; flex-direction: column;">
                    <div class="card-header">
                        <h2 id="camera-title">Live camera field</h2>
                    </div>
                    <div class="camera-split">
                        <div id="cam-enter-status" style="flex:1; background: #000; border-radius: 0.5rem; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-secondary); min-height: 250px; position: relative; overflow: hidden;">
                            <span>Select a room</span>
                            <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera A (Log In)</span>
                        </div>
                        <div id="cam-exit-status" style="flex:1; background: #000; border-radius: 0.5rem; display: flex; flex-direction: column; align-items: center; justify-content: center; color: var(--text-secondary); min-height: 250px; position: relative; overflow: hidden;">
                            <span>Select a room</span>
                            <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera B (Log Out)</span>
                        </div>
                    </div>
                </div>

                <!-- ROOM UTILIZATION -->
                <div class="card">
                    <div class="card-header">
                        <h2>ROOM UTILIZATION</h2>
                    </div>
                    <div class="responsive-flex-wrap" style="display: flex; gap: 1rem; background: #e2e8f0; border-radius: 0.5rem; padding: 1rem; color: #000; margin-top: 1rem;">
                        <div style="flex:1; text-align: center;">
                            <div style="font-size: 0.875rem; color: var(--bg-sidebar); font-weight: 600; margin-bottom: 0.25rem;">Room Number</div>
                            <div id="utilization-room" style="font-size: 1.5rem; color: var(--bg-sidebar); font-weight: 700;"><?= $current_class['room'] ?></div>
                        </div>
                        <div style="flex:2; text-align: left; padding-left: 1rem; border-left: 2px solid #cbd5e1;">
                            <div style="font-size: 0.75rem; color: var(--bg-sidebar); font-weight: 600; margin-bottom: 0.25rem;">Current Class</div>
                            <div id="utilization-code" style="font-size: 1.25rem; color: var(--bg-sidebar); font-weight: 700; margin-bottom: 0.25rem;"><?= $current_class['code'] ?></div>
                            <div id="utilization-subject" style="font-size: 0.75rem; color: var(--bg-sidebar);"><?= $current_class['subject'] ?></div>
                            <div id="utilization-time" style="font-size: 0.75rem; color: var(--bg-sidebar);"><?= $current_class['time'] ?></div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;" id="door-controls">
                <!-- Door A & Door B -->
                <div class="door-status-panel" style="margin-bottom: 0; padding: 1.5rem;">
                    <div class="door-title" style="text-transform: uppercase;">DOOR A (Entrance)</div>
                    <div class="door-badge locked" id="badge-door-a" style="margin: 1rem 0;">LOCKED</div>
                    <button id="btn-door-a" class="btn btn-primary" style="width: 100%;">Unlock Door</button>
                </div>

                <div class="door-status-panel" style="padding: 1.5rem;">
                    <div class="door-title" style="text-transform: uppercase;">DOOR B (Exit)</div>
                    <div class="door-badge locked" id="badge-door-b" style="margin: 1rem 0;">LOCKED</div>
                    <button id="btn-door-b" class="btn btn-primary" style="width: 100%;">Unlock Door</button>
                </div>

                <!-- Class summary -->
                <div class="card">
                    <div class="card-header">
                        <h2>Class summary</h2>
                    </div>
                    <div class="metric-grid" style="margin-top: 1rem;">
                        <div class="metric-widget blue">
                            <span class="value" id="summary-schedules">0</span>
                            <span class="label">Total System Schedules</span>
                        </div>
                        <div class="metric-widget blue">
                            <span class="value" style="color: var(--accent-green)" id="summary-enrolled">0</span>
                            <span class="label">Total Registered Students</span>
                        </div>
                        <div class="metric-widget blue">
                            <span class="value" style="color: var(--accent-yellow)" id="summary-active">0</span>
                            <span class="label">Total Active Students</span>
                        </div>
                        <div class="metric-widget blue">
                            <span class="value" style="color: #cbd5e1" id="summary-classes-today">0</span>
                            <span class="label">Classes Today (Room)</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</main>
<script src="../../assets/js/logout.js"></script>
<script>
    async function changeRoom(roomNumber, skipCamera = false) {
        currentSelectedRoom = roomNumber;
        
        // Update Title
        if(!skipCamera) {
            document.getElementById('camera-title').innerText = "Live camera field - Room " + roomNumber;
            
            const camA = document.getElementById('cam-enter-status');
            const camB = document.getElementById('cam-exit-status');
            
            // Connecting state
            camA.innerHTML = '<span style="color: var(--accent-yellow);">Connecting...</span>';
            camB.innerHTML = '<span style="color: var(--accent-yellow);">Connecting...</span>';

            // Load Camera A (IN)
            const urlA = `http://${window.location.hostname}:8000/video_feed/IN?room=` + encodeURIComponent(roomNumber) + '&t=' + new Date().getTime();
            camA.innerHTML = `<img src="${urlA}" style="width:100%; height:100%; object-fit:cover;" onerror="this.outerHTML='<span style=\\'color:var(--accent-red)\\'>Offline - No camera detected</span>'">
                              <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera A (Log In)</span>`;

            // Load Camera B (OUT)
            const urlB = `http://${window.location.hostname}:8000/video_feed/OUT?room=` + encodeURIComponent(roomNumber) + '&t=' + new Date().getTime();
            camB.innerHTML = `<img src="${urlB}" style="width:100%; height:100%; object-fit:cover;" onerror="this.outerHTML='<span style=\\'color:var(--accent-red)\\'>Offline - No camera detected</span>'">
                              <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera B (Log Out)</span>`;
        }
        
        // Update Room Number in Utilization early
        document.getElementById('utilization-room').innerText = roomNumber;
        document.getElementById('utilization-code').innerText = 'Loading...';
        document.getElementById('utilization-subject').innerText = 'Loading...';
        document.getElementById('utilization-time').innerText = 'Loading...';

        // Fetch Room Class Utilization
        try {
            const classRes = await fetch('../../api/get_room_class.php?room_id=' + encodeURIComponent(roomNumber));
            const classData = await classRes.json();
            if (classData.success) {
                document.getElementById('utilization-code').innerText = classData.code;
                document.getElementById('utilization-subject').innerText = classData.subject;
                document.getElementById('utilization-time').innerText = classData.time;
                
                if (classData.summary) {
                    // Update class summary section
                    document.getElementById('summary-schedules').innerText = classData.summary.total_system_schedules;
                    document.getElementById('summary-enrolled').innerText = classData.summary.total_registered_students;
                    document.getElementById('summary-active').innerText = classData.summary.active_students;
                    document.getElementById('summary-classes-today').innerText = classData.summary.classes_today;
                }
            } else {
                document.getElementById('utilization-code').innerText = 'VACANT';
                document.getElementById('utilization-subject').innerText = 'No class found';
                document.getElementById('utilization-time').innerText = '--:--';
                document.getElementById('summary-schedules').innerText = '0';
                document.getElementById('summary-enrolled').innerText = '0';
                document.getElementById('summary-active').innerText = '0';
                document.getElementById('summary-classes-today').innerText = '0';
            }
        } catch(err) {
            console.error("Failed to fetch class info", err);
        }
        
        // Fetch real door status from DB
        try {
            const res = await fetch('../../api/get_door_status.php?room_id=' + encodeURIComponent(roomNumber));
            const data = await res.json();
            
            if(data.success) {
                const isLocked = data.is_locked;
                
                // Update Door Badges
                const badgeA = document.getElementById('badge-door-a');
                const badgeB = document.getElementById('badge-door-b');
                
                const lockedHtml = 'LOCKED - Room ' + roomNumber;
                const unlockedHtml = 'UNLOCKED - Room ' + roomNumber;

                badgeA.className = isLocked ? 'door-badge locked' : 'door-badge unlocked';
                badgeA.innerText = isLocked ? lockedHtml : unlockedHtml;
                
                badgeB.className = isLocked ? 'door-badge locked' : 'door-badge unlocked';
                badgeB.innerText = isLocked ? lockedHtml : unlockedHtml;

                // Update Grid Color Live!
                const gridCell = document.getElementById('grid-room-' + roomNumber);
                if (gridCell) {
                    gridCell.style.background = isLocked ? '#64748b' : 'var(--accent-green)';
                }

                // Update Buttons
                const btnA = document.getElementById('btn-door-a');
                const btnB = document.getElementById('btn-door-b');
                
                btnA.innerText = isLocked ? "Unlock Door" : "Lock Door";
                btnB.innerText = isLocked ? "Unlock Door" : "Lock Door";
                
                btnA.onclick = () => toggleDoor(isLocked ? 'unlock' : 'lock');
                btnB.onclick = () => toggleDoor(isLocked ? 'unlock' : 'lock');
            }
        } catch (err) {
            console.error("Failed to fetch door status", err);
        }
    }
    
    // Initialize default state
    changeRoom('ICT 204');
</script>
</body>
</html>