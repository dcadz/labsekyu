<?php
// dashboards/tech_support/index.php
require_once __DIR__ . '/../../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../../config/database.php';

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM teacher_alerts WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

// --- ROOM SECURITY & LOCK STATUS ---
// Inactive rooms default to locked (Turned Off) to prevent unauthorized access.
// NEVER auto-unlock rooms simply by opening the dashboard page.
$currentTime = date('H:i:s');
$currentDay = date('l');

// Lock any scheduled rooms that are NOT currently in session
$allScheduledRoomsStmt = $pdo->prepare("SELECT DISTINCT room_id FROM class_schedules WHERE day_of_week = ?");
$allScheduledRoomsStmt->execute([$currentDay]);
$allScheduledRooms = $allScheduledRoomsStmt->fetchAll(PDO::FETCH_COLUMN);

// Define all default building rooms
$rooms_300 = ['301', '302', '303', '304', '305', '306', '307', '308', '309'];
$rooms_200 = ['201', '202', '203', '204', '205', '206', '207', '208'];
$rooms_100 = ['101', '102', '103', '104', '105', '106', '107', '108'];
$allDefaultRooms = array_merge($rooms_300, $rooms_200, $rooms_100);

// Auto-populate all default building rooms into door_status if missing so ALL rooms are functional
try {
    $existingRooms = $pdo->query("SELECT room_id FROM door_status")->fetchAll(PDO::FETCH_COLUMN);
    $missingRooms = array_diff($allDefaultRooms, $existingRooms);
    if (!empty($missingRooms)) {
        $insStmt = $pdo->prepare("INSERT INTO door_status (room_id, is_locked) VALUES (?, 1) ON DUPLICATE KEY UPDATE room_id=room_id");
        foreach ($missingRooms as $mRoom) {
            $insStmt->execute([$mRoom]);
        }
    }
} catch (Exception $e) {}

// 1. Find rooms actively in class right now
$activeRoomsStmt = $pdo->prepare("
    SELECT c.*, u.full_name as teacher_name 
    FROM class_schedules c 
    JOIN users u ON c.teacher_id = u.id 
    WHERE c.day_of_week = ? 
      AND c.start_time <= ? 
      AND c.end_time >= ? 
    ORDER BY c.room_id ASC
");
$activeRoomsStmt->execute([$currentDay, $currentTime, $currentTime]);
$allActiveClasses = $activeRoomsStmt->fetchAll();
$scheduledActiveRooms = array_values(array_unique(array_column($allActiveClasses, 'room_id')));

// Fetch All Door Statuses to determine occupancy/unlocked status
$doorStmt = $pdo->query("SELECT room_id FROM door_status WHERE is_locked = 0");
$unlockedRooms = $doorStmt->fetchAll(PDO::FETCH_COLUMN);

// Automatically detect the initial room to display:
// Priority: Show room that is actively in class right now!
if (!empty($allActiveClasses)) {
    $firstActive = $allActiveClasses[0];
    $initialRoom = $firstActive['room_id'];
    $current_class = [
        'room' => $firstActive['room_id'],
        'code' => $firstActive['section'],
        'subject' => $firstActive['subject_code'] . ' - ' . strtoupper($firstActive['teacher_name']),
        'time' => date('g:i A', strtotime($firstActive['start_time'])) . ' - ' . date('g:i A', strtotime($firstActive['end_time']))
    ];
} else {
    // If no room is active in class right now, show Room 101 as VACANT
    $initialRoom = '101';
    $current_class = [
        'room' => '101',
        'code' => 'VACANT',
        'subject' => 'No active class',
        'time' => '--:--'
    ];
}

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
        let currentSelectedRoom = '<?= htmlspecialchars($initialRoom) ?>';

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

        async function toggleAllRooms(action) {
            const verb = action === 'unlock' ? 'UNLOCK' : 'LOCK';
            if (!confirm(`Are you sure you want to ${verb} ALL building doors?`)) return;
            try {
                const res = await fetch('../../api/toggle_door.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_id: 'all', action: action })
                });
                const data = await res.json();
                if (data.success) {
                    refreshDoorStatus(currentSelectedRoom);
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (err) {
                alert('Request failed');
            }
        }

        async function toggleDoor(action) {
            const btnA = document.getElementById('btn-door-a');
            const badgeA = document.getElementById('badge-door-a');
            const room = currentSelectedRoom;

            if (btnA) btnA.disabled = true;

            try {
                const res = await fetch('../../api/toggle_door.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ room_id: room, action: action })
                });
                const data = await res.json();
                if (!data.success) {
                    alert('Error: ' + (data.error || 'Failed to toggle door'));
                    if (btnA) btnA.disabled = false;
                    return;
                }

                const isLocked = (action === 'lock' || data.status === 'LOCKED');
                if (badgeA && currentSelectedRoom === room) {
                    badgeA.className = isLocked ? 'door-badge locked' : 'door-badge unlocked';
                    badgeA.innerText = (isLocked ? 'LOCKED' : 'UNLOCKED') + ' - Room ' + room;
                }
                if (btnA) {
                    btnA.disabled = false;
                    const unlockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>`;
                    const lockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>`;
                    btnA.innerHTML = isLocked ? (unlockSvg + "Unlock Door") : (lockSvg + "Lock Door");
                    btnA.onclick = () => toggleDoor(isLocked ? 'unlock' : 'lock');
                }
                refreshDoorStatus(room);
            } catch (err) {
                console.error('Failed to communicate with lock:', err);
                if (btnA) btnA.disabled = false;
                refreshDoorStatus(room);
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
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                <h2>ROOM OCCUPANCY</h2>
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button class="btn btn-primary" onclick="toggleAllRooms('unlock')" style="padding: 0.5rem 0.875rem; font-size: 0.875rem; background: var(--accent-green);">Unlock All</button>
                    <button class="btn btn-primary" onclick="toggleAllRooms('lock')" style="padding: 0.5rem 0.875rem; font-size: 0.875rem; background: #475569;">Lock All</button>
                    <button class="btn btn-primary" onclick="addRoom()" style="padding: 0.5rem 0.875rem; font-size: 0.875rem;">+ Add Room</button>
                    <button class="btn btn-primary" onclick="deleteRoom()" style="padding: 0.5rem 0.875rem; font-size: 0.875rem; background: var(--accent-red);">Delete Room</button>
                </div>
            </div>
            <div class="tier-label">3rd Floor</div>
            <div class="tier-grid">
                <?php
                $rooms_300 = ['301', '302', '303', '304', '305', '306', '307', '308', '309'];
                foreach ($rooms_300 as $room) {
                    $isOcc = in_array($room, $scheduledActiveRooms) || in_array($room, $unlockedRooms);
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
                    $isOcc = in_array($room, $scheduledActiveRooms) || in_array($room, $unlockedRooms);
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
                    $isOcc = in_array($room, $scheduledActiveRooms) || in_array($room, $unlockedRooms);
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
                        $isOcc = in_array($room, $scheduledActiveRooms) || in_array($room, $unlockedRooms);
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
                <!-- Single Door A Panel (Matching Live View size & padding) -->
                <div class="card door-status-panel" style="flex: 1; display: flex; flex-direction: column; justify-content: space-between; padding: 1.5rem;">
                    <div class="card-header" style="margin-bottom: 0;">
                        <h2 style="color: var(--bg-sidebar); font-size: 1.125rem; font-weight: 700; text-transform: uppercase;">Door A Status</h2>
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.5rem 0;">
                        <div class="door-title" style="text-transform: uppercase; font-size: 1.25rem; font-weight: 700; color: var(--bg-sidebar); margin-bottom: 0.25rem;">DOOR A (Entrance)</div>
                        <div class="door-subtitle" style="font-size: 0.875rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1.5rem;">ENTRANCE CONTROL</div>
                        <div class="door-badge locked" id="badge-door-a" style="margin-bottom: 1.5rem; font-size: 1rem; font-weight: 700; width: 100%; max-width: 300px; padding: 0.875rem 1.5rem;">LOCKED</div>
                        <button id="btn-door-a" onclick="toggleDoor('unlock')" class="btn btn-primary" style="width: 100%; max-width: 300px; min-height: 48px; font-size: 1rem; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>
                            Unlock Door
                        </button>
                    </div>
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
    async function refreshDoorStatus(roomNumber) {
        try {
            // 1. Fetch building-wide door status to keep ALL room tiles live on the grid
            const allRes = await fetch('../../api/get_door_status.php?room_id=all');
            const allData = await allRes.json();
            if (allData.success && allData.doors) {
                for (const [rId, isLocked] of Object.entries(allData.doors)) {
                    const gridCell = document.getElementById('grid-room-' + rId);
                    if (gridCell) {
                        gridCell.style.background = isLocked ? '#64748b' : 'var(--accent-green)';
                    }
                }
            }

            // 2. Fetch specific door details for the currently active room
            const res = await fetch('../../api/get_door_status.php?room_id=' + encodeURIComponent(roomNumber));
            const data = await res.json();
            
            if(data.success) {
                const isLocked = data.is_locked;
                
                // Update Door Badge and Button (Door A) only if not in active 5s pulse countdown
                if (currentSelectedRoom === roomNumber) {
                    const badgeA = document.getElementById('badge-door-a');
                    if (badgeA) {
                        const lockedHtml = 'LOCKED - Room ' + roomNumber;
                        const unlockedHtml = 'UNLOCKED - Room ' + roomNumber;
                        badgeA.className = isLocked ? 'door-badge locked' : 'door-badge unlocked';
                        badgeA.innerText = isLocked ? lockedHtml : unlockedHtml;
                    }

                    // Update Button (Door A)
                    const btnA = document.getElementById('btn-door-a');
                    if (btnA) {
                        btnA.disabled = false;
                        const unlockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"></path></svg>`;
                        const lockSvg = `<svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 0.5rem;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>`;
                        btnA.innerHTML = isLocked ? (unlockSvg + "Unlock Door") : (lockSvg + "Lock Door");
                        btnA.onclick = () => toggleDoor(isLocked ? 'unlock' : 'lock');
                    }
                }
            }
        } catch (err) {
            console.error("Failed to fetch door status", err);
        }
    }

    async function changeRoom(roomNumber) {
        currentSelectedRoom = roomNumber;
        
        // Highlight active room cell on grid with glowing outline
        document.querySelectorAll('.tier-grid > div').forEach(div => {
            div.style.outline = 'none';
            div.style.boxShadow = 'none';
        });
        const selectedCell = document.getElementById('grid-room-' + roomNumber);
        if (selectedCell) {
            selectedCell.style.outline = '3px solid #38bdf8';
            selectedCell.style.boxShadow = '0 0 14px rgba(56, 189, 248, 0.7)';
        }

        // Update Title
        document.getElementById('camera-title').innerText = "Live camera field - Room " + roomNumber;
        
        // Update Camera Streams with fallback
        const camA = document.getElementById('cam-enter-status');
        const camB = document.getElementById('cam-exit-status');
        
        const streamA = `http://${window.location.hostname}:8000/video_feed/IN?room=` + encodeURIComponent(roomNumber) + '&t=' + Date.now();
        const directA = `http://192.168.137.90:81/stream?t=` + Date.now();
        camA.innerHTML = `<img src="${streamA}" style="width:100%; height:100%; object-fit:cover;" onerror="if(this.src!=='${directA}'){this.src='${directA}';}else{this.outerHTML='<div style=\\'display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; color:var(--accent-red);\\'><svg width=\\'40\\' height=\\'40\\' fill=\\'none\\' stroke=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path stroke-linecap=\\'round\\' stroke-linejoin=\\'round\\' stroke-width=\\'2\\' d=\\'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636\\'></path></svg><span style=\\'margin-top:0.5rem\\'>Offline - No camera detected</span></div>';}">
                          <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); color: #fff; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera A (Log In)</span>`;

        const streamB = `http://${window.location.hostname}:8000/video_feed/OUT?room=` + encodeURIComponent(roomNumber) + '&t=' + Date.now();
        const directB = `http://192.168.137.135:81/stream?t=` + Date.now();
        camB.innerHTML = `<img src="${streamB}" style="width:100%; height:100%; object-fit:cover;" onerror="if(this.src!=='${directB}'){this.src='${directB}';}else{this.outerHTML='<div style=\\'display:flex; height:100%; align-items:center; justify-content:center; flex-direction:column; color:var(--accent-red);\\'><svg width=\\'40\\' height=\\'40\\' fill=\\'none\\' stroke=\\'currentColor\\' viewBox=\\'0 0 24 24\\'><path stroke-linecap=\\'round\\' stroke-linejoin=\\'round\\' stroke-width=\\'2\\' d=\\'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636\\'></path></svg><span style=\\'margin-top:0.5rem\\'>Offline - No camera detected</span></div>';}">
                          <span style="position: absolute; bottom: 0.75rem; font-size: 0.75rem; background: rgba(0,0,0,0.5); color: #fff; padding: 0.25rem 0.5rem; border-radius: 0.25rem;">Camera B (Log Out)</span>`;

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
        await refreshDoorStatus(roomNumber);
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        // Initialize default state with the detected active room
        changeRoom('<?= htmlspecialchars($initialRoom) ?>');

        // Real-time door status polling every 800ms for instant hardware sync
        setInterval(() => {
            if (currentSelectedRoom) {
                refreshDoorStatus(currentSelectedRoom);
            }
        }, 800);
    });
</script>
</body>
</html>