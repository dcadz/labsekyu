<?php
// dashboards/tech_support/register_student.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['tech_support']);

require_once __DIR__ . '/../../core/db_connect.php';

$pendingCountStmt = $pdo->query("SELECT COUNT(*) FROM teacher_alerts WHERE status = 'pending'");
$pendingCount = $pendingCountStmt->fetchColumn();

$sweetalert_type = '';
$sweetalert_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $student_id = trim($_POST['student_id'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $password = $_POST['password'] ?? '';
    
    $face_base64 = $_POST['face_image_base64'] ?? '';
    
    // Check which image input is populated
    $has_camera = !empty($face_base64);
    $has_upload = (isset($_FILES['face_image_file']) && $_FILES['face_image_file']['error'] === UPLOAD_ERR_OK);

    if (empty($first_name) || empty($last_name) || empty($student_id) || empty($email) || empty($section) || empty($password)) {
        $sweetalert_type = 'error';
        $sweetalert_message = 'All required student details must be filled.';
    } elseif (!$has_camera && !$has_upload) {
        $sweetalert_type = 'error';
        $sweetalert_message = 'Please provide a face image via Camera or Upload.';
    } else {
        // Check if email or student ID exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR role_id = ?");
        $stmt->execute([$email, $student_id]);
        if ($stmt->fetch()) {
            $sweetalert_type = 'warning';
            $sweetalert_message = 'Credentials already exist. Please contact tech support.';
        } else {
            // Concatenate full name
            $full_name = trim(preg_replace('/\s+/', ' ', "$first_name $middle_name $last_name"));
            // Hash explicit password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'student';

            // Insert into users
            $insert = $pdo->prepare("INSERT INTO users (full_name, first_name, middle_name, last_name, email, password_hash, role, role_id, section) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($insert->execute([$full_name, $first_name, $middle_name, $last_name, $email, $password_hash, $role, $student_id, $section])) {
                
                // Also insert into legacy students table for RFID hardware compatibility
                $insertStudent = $pdo->prepare("INSERT INTO students (uid, full_name) VALUES (?, ?) ON DUPLICATE KEY UPDATE full_name = ?");
                $insertStudent->execute([$student_id, $full_name, $full_name]);
                
                $safe_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $student_id);
                $uploadDir = __DIR__ . '/../../assets/face_data/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                // Save image with student_id as filename
                $filePath = $uploadDir . $safe_id . '.jpg';

                // Save Face Image (Camera takes precedence if both somehow exist)
                if ($has_camera) {
                    $imageBase64 = $face_base64;
                    if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64, $type)) {
                        $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                    }
                    $imageData = base64_decode($imageBase64);
                    file_put_contents($filePath, $imageData);
                } elseif ($has_upload) {
                    move_uploaded_file($_FILES['face_image_file']['tmp_name'], $filePath);
                }

                $sweetalert_type = 'success';
                $sweetalert_message = "Student registered and automatically enrolled in section $section successfully!";
            } else {
                $sweetalert_type = 'error';
                $sweetalert_message = "Failed to create user.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Student Face | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--bg-sidebar); font-weight: bold; }
        .form-group input, .form-group select { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-sizing: border-box; }
        #video, #canvas, #photo { border-radius: 0.5rem; max-width: 100%; height: auto; }
        #canvas, #photo { display: none; }
        .camera-container {
            position: relative; background: #e2e8f0; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; min-height: 240px; overflow: hidden;
        }
        
        .register-grid-lower {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 3rem;
            margin-top: 3rem;
        }

        .student-details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }
        
        .student-details-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 1024px) {
            .register-grid-lower, .student-details-grid, .student-details-grid-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <!-- Sidebar items -->
        <a href="techsupport.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Home
        </a>
        <a href="schedule.php" class="sidebar-link">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Schedule
        </a>
        <a href="register_student.php" class="sidebar-link active">
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
    <div class="dashboard-container" style="width: 100%;">
        
        <form method="POST" action="" enctype="multipart/form-data" id="masterRegistrationForm">
            
            <!-- UPPER GRID (1 Grid) -->
            <div class="card" style="margin: 0;">
                <div class="card-header">
                    <h2>Student Details</h2>
                    <p style="font-size: 0.875rem; color: #64748b; margin-top: 0.25rem;">Enter the student's personal information and login credentials.</p>
                </div>

                <div class="student-details-grid">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" placeholder="e.g. Juan" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" placeholder="e.g. Dela">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" placeholder="e.g. Cruz" required>
                    </div>
                </div>
                
                <div class="student-details-grid-2">
                    <div class="form-group">
                        <label>Student ID *</label>
                        <input type="text" name="student_id" placeholder="e.g. 2021-0001-A" required>
                    </div>
                    <div class="form-group">
                        <label>Section *</label>
                        <input type="text" name="section" placeholder="e.g. BSIT 4-A" required>
                    </div>
                </div>

                <div class="student-details-grid-2">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" placeholder="e.g. juan@student.edu.ph" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" placeholder="Set default password" required>
                    </div>
                </div>
            </div>

            <!-- LOWER GRID (2 Grids) -->
            <div class="register-grid-lower">
                
                <!-- Grid 1: Register with Camera -->
                <div class="card" style="margin: 0; height: 100%;">
                    <div class="card-header">
                        <h2>Option A: Capture Face via Camera</h2>
                    </div>

                    <div class="form-group">
                        <div class="camera-container mb-4">
                            <video id="video" autoplay playsinline></video>
                            <canvas id="canvas"></canvas>
                            <img id="photo" alt="Captured Face" />
                        </div>
                        <input type="hidden" name="face_image_base64" id="face_image_base64">
                        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                            <button type="button" class="btn btn-primary" id="startBtn" style="flex: 1;">Start Camera</button>
                            <button type="button" class="btn btn-primary" id="snapBtn" style="flex: 1; display: none;">Capture Photo</button>
                            <button type="button" class="btn btn-primary" id="retakeBtn" style="flex: 1; display: none; background: #64748b;">Retake</button>
                        </div>
                    </div>
                </div>

                <!-- Grid 2: Register with Upload -->
                <div class="card" style="margin: 0; height: 100%;">
                    <div class="card-header">
                        <h2>Option B: Upload Face Image</h2>
                    </div>

                    <div class="form-group">
                        <div style="background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 0.5rem; padding: 2rem; text-align: center; color: #64748b; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 240px;">
                            <input type="file" name="face_image_file" id="face_image_file" accept="image/*" style="border: none; background: transparent; cursor: pointer;">
                            <p style="margin-top: 0.5rem; font-size: 0.875rem;">Supported formats: JPG, PNG</p>
                        </div>
                    </div>
                </div>

            </div> <!-- /.register-grid-lower -->

            <!-- SINGLE MASTER SUBMIT BUTTON -->
            <div style="margin-top: 3rem; margin-bottom: 2rem;">
                <button type="button" id="masterSubmitBtn" class="btn btn-primary" style="width: 100%; border-radius: 0.5rem; font-size: 1.1rem; padding: 1rem;">Complete Registration</button>
            </div>

        </form>

    </div>
</main>
<script src="../../assets/js/logout.js"></script>
<script>
    // SweetAlert2 Handling
    const swalType = "<?= htmlspecialchars(addslashes($sweetalert_type)) ?>";
    const swalMsg = "<?= htmlspecialchars(addslashes($sweetalert_message)) ?>";
    
    if (swalType) {
        if (swalType === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                text: swalMsg,
                showConfirmButton: false,
                timer: 2000
            }).then(() => {
                // Clear URL to prevent form resubmission
                window.location = window.location.href;
            });
        } else if (swalType === 'warning') {
            Swal.fire({
                icon: 'warning',
                title: 'Duplicate Credential',
                text: swalMsg
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: swalMsg
            });
        }
    }

    // Camera Handling
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const photo = document.getElementById('photo');
    const startBtn = document.getElementById('startBtn');
    const snapBtn = document.getElementById('snapBtn');
    const retakeBtn = document.getElementById('retakeBtn');
    const faceInput = document.getElementById('face_image_base64');
    let stream;

    if (startBtn) {
        startBtn.addEventListener('click', async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                video.srcObject = stream;
                video.play();
                startBtn.style.display = 'none';
                snapBtn.style.display = 'block';
                photo.style.display = 'none';
                video.style.display = 'block';
                
                // Clear file upload input if using camera
                document.getElementById('face_image_file').value = '';
            } catch (err) {
                Swal.fire('Camera Error', 'Error accessing webcam: ' + err, 'error');
            }
        });
    }

    if (snapBtn) {
        snapBtn.addEventListener('click', () => {
            const context = canvas.getContext('2d');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const data = canvas.toDataURL('image/jpeg');
            photo.setAttribute('src', data);
            faceInput.value = data;
            
            video.style.display = 'none';
            photo.style.display = 'block';
            snapBtn.style.display = 'none';
            retakeBtn.style.display = 'block';
            
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    }

    if (retakeBtn) {
        retakeBtn.addEventListener('click', async () => {
            retakeBtn.style.display = 'none';
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                video.srcObject = stream;
                video.play();
                video.style.display = 'block';
                photo.style.display = 'none';
                snapBtn.style.display = 'block';
                faceInput.value = '';
            } catch (err) {
                Swal.fire('Camera Error', 'Error accessing webcam: ' + err, 'error');
            }
        });
    }

    // File Upload event
    document.getElementById('face_image_file').addEventListener('change', function() {
        if(this.value) {
            // Clear camera inputs if a file is chosen
            faceInput.value = '';
            photo.style.display = 'none';
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            video.style.display = 'none';
            startBtn.style.display = 'block';
            snapBtn.style.display = 'none';
            retakeBtn.style.display = 'none';
        }
    });

    // Master Submit Validation
    document.getElementById('masterSubmitBtn').addEventListener('click', function() {
        const form = document.getElementById('masterRegistrationForm');
        
        // Native HTML5 validation check for required fields (Name, Email, etc)
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const base64Val = faceInput.value;
        const fileVal = document.getElementById('face_image_file').value;

        if (!base64Val && !fileVal) {
            Swal.fire('Missing Face Image', 'Please capture a face via camera OR upload an image file.', 'warning');
            return;
        }

        // Submits the form
        form.submit();
    });
</script>
</body>
</html>
