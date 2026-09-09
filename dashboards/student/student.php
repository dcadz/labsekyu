<?php
// dashboards/student/student.php
require_once __DIR__ . '/../../core/auth_check.php';
checkAccess(['student']);
require_once __DIR__ . '/../../core/db_connect.php';

$student_id = $_SESSION['user_id'];
$student_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | LABSEKYU</title>
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <style>
        .video-container {
            width: 100%;
            aspect-ratio: 1/1;
            max-width: 500px;
            margin: 0 auto;
            position: relative;
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .crosshairs-overlay {
            position: absolute;
            top: 2rem; left: 2rem; right: 2rem; bottom: 2rem;
            border: 4px solid #fff;
            z-index: 10;
            pointer-events: none;
            clip-path: polygon(0% 0%, 20% 0%, 20% 4px, 4px 4px, 4px 20%, 0% 20%, 0% 80%, 4px 80%, 4px calc(100% - 4px), 20% calc(100% - 4px), 20% 100%, 0% 100%, 0% 0%,
                               80% 0%, 100% 0%, 100% 20%, calc(100% - 4px) 20%, calc(100% - 4px) 4px, 80% 4px, 80% 0%,
                               100% 80%, 100% 100%, 80% 100%, 80% calc(100% - 4px), calc(100% - 4px) calc(100% - 4px), calc(100% - 4px) 80%, 100% 80%);
        }
        video, canvas, #uploaded-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            position: absolute;
            top: 0; left: 0;
            z-index: 5;
        }
        canvas, #uploaded-preview {
            display: none;
        }
        .upload-box {
            background: #fde047;
            border: 4px dashed #000;
            border-radius: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            text-align: center;
            color: #000;
            cursor: pointer;
            transition: var(--transition-fast);
            aspect-ratio: 16/9;
        }
        .upload-box:hover {
            background: #fef08a;
        }
        .upload-box svg {
            width: 48px;
            height: 48px;
            color: #ca8a04;
            margin-bottom: 1rem;
        }
        
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-size: 0.875rem; color: var(--text-primary); font-weight: 600; }
        .form-group input { width: 100%; padding: 0.75rem; border-radius: 0.5rem; border: 1px solid var(--border-color); background: rgba(255,255,255,0.1); color: white; font-size: 1rem; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-nav">
        <a href="#" class="sidebar-link active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            Register Face
        </a>
        <a href="schedule.php" class="sidebar-link">
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
        
        <p id="status-msg" style="text-align:center; margin-bottom:1rem; display:none; padding: 1rem; background: var(--bg-card); border-radius: 0.5rem;"></p>

        <!-- Credentials Form -->
        <div class="card mb-4">
            <div class="card-header">
                <h2>Student Credentials</h2>
            </div>
            <div class="grid-cols-2">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="full_name" value="" placeholder="First, Middle, Last Name">
                </div>
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" id="role_id" value="" placeholder="202X-XXXX-X">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="email" value="" placeholder="name@students.isatu.edu.ph">
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <input type="text" id="section" value="" placeholder="Course Year-Section">
                </div>
            </div>
        </div>

        <div class="grid-cols-2 mb-4">
            <!-- Left: Camera Capture -->
            <div style="display: flex; flex-direction: column; align-items: center; gap: 1.5rem;">
                <div class="video-container">
                    <div class="crosshairs-overlay"></div>
                    <video id="video" autoplay playsinline></video>
                    <canvas id="canvas"></canvas>
                </div>
                <button id="capture-btn" class="btn btn-primary" style="border-radius: 0.5rem; padding: 1rem 2rem;" disabled>Looking for face...</button>
                <button id="retake-btn" class="btn btn-danger" style="display:none; border-radius: 0.5rem; padding: 1rem 2rem;">Retake Photo</button>
            </div>

            <!-- Right: File Upload -->
            <div style="display: flex; flex-direction: column; align-items: center; gap: 1.5rem; justify-content: center;">
                <label class="upload-box" id="upload-label-box">
                    <svg fill="currentColor" viewBox="0 0 24 24"><path d="M11 15h2V9.83l1.59 1.59L16 10l-4-4-4 4 1.41 1.41L11 9.83V15zM5 19h14v-2H5v2z"></path></svg>
                    <div style="font-size: 0.875rem; font-weight: 500;">Upload Photo to Enroll in<br>Facial Recognition Database</div>
                    <input type="file" id="upload-input" accept="image/*" style="display:none;">
                </label>
                <img id="uploaded-preview" alt="Preview" style="max-width: 100%; aspect-ratio: 16/9; display: none; border-radius: 1rem; border: 4px dashed #000; object-fit: cover; position: static;" />
                <button id="clear-upload-btn" class="btn btn-danger" style="display:none; border-radius: 0.5rem; padding: 1rem 2rem;">Clear Upload</button>
            </div>
        </div>

        <!-- Submit Button -->
        <div style="text-align: center; margin-bottom: 3rem;">
            <button id="submit-btn" class="btn btn-primary" style="padding: 1rem 3rem; font-size: 1.25rem;">Confirm & Submit Credentials</button>
        </div>

        <!-- Settings Section Removed for Registration Kiosk -->

    </div>
</main>

<script src="../../assets/js/face-api.min.js"></script>
<script>
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const uploadedPreview = document.getElementById('uploaded-preview');
    const uploadLabelBox = document.getElementById('upload-label-box');
    
    const captureBtn = document.getElementById('capture-btn');
    const retakeBtn = document.getElementById('retake-btn');
    const uploadInput = document.getElementById('upload-input');
    const clearUploadBtn = document.getElementById('clear-upload-btn');
    
    const submitBtn = document.getElementById('submit-btn');
    const statusMsg = document.getElementById('status-msg');

    let capturedDataUrl = null;
    let stream = null;

    async function startCamera() {
        try {
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri('../../assets/models'),
                faceapi.nets.faceLandmark68Net.loadFromUri('../../assets/models'),
                faceapi.nets.faceRecognitionNet.loadFromUri('../../assets/models')
            ]);
            
            stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            video.srcObject = stream;
        } catch (err) {
            console.error("Camera access denied or unavailable", err);
            statusMsg.innerHTML = "Camera access denied. <button onclick='startCamera()' class='btn btn-primary' style='padding: 0.5rem 1rem; font-size:0.875rem; margin-top: 0.5rem;'>Retry Camera</button>";
            statusMsg.style.color = "var(--accent-red)";
            statusMsg.style.display = "block";
            captureBtn.style.display = "none";
        }
    }

    startCamera();

    let faceDetectionCounter = 3;

    video.addEventListener('play', () => {
        setInterval(async () => {
            if (video.paused || video.ended) return;
            
            const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks()
                .withFaceDescriptor();
            
            if (detection && captureBtn.style.display !== 'none') {
                captureBtn.disabled = false;
                captureBtn.style.opacity = 1;
                
                if (faceDetectionCounter > 0) {
                    captureBtn.innerText = `Auto-capturing in ${faceDetectionCounter}...`;
                    faceDetectionCounter--;
                } else {
                    captureBtn.innerText = "Capturing...";
                    faceDetectionCounter = 3;
                    captureBtn.click();
                }
            } else if (captureBtn.style.display !== 'none') {
                faceDetectionCounter = 3;
                captureBtn.disabled = true;
                captureBtn.innerText = "Looking for face...";
                captureBtn.style.opacity = 0.5;
            }
        }, 1000);
    });

    // Capture Photo Logic
    captureBtn.addEventListener('click', () => {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        
        capturedDataUrl = canvas.toDataURL('image/jpeg');
        
        video.style.display = 'none';
        canvas.style.display = 'block';
        
        captureBtn.style.display = 'none';
        retakeBtn.style.display = 'inline-flex';
        
        // Clear upload if exists
        uploadInput.value = '';
        uploadedPreview.style.display = 'none';
        uploadLabelBox.style.display = 'flex';
        clearUploadBtn.style.display = 'none';
    });

    retakeBtn.addEventListener('click', () => {
        canvas.style.display = 'none';
        video.style.display = 'block';
        capturedDataUrl = null;
        
        captureBtn.style.display = 'inline-flex';
        retakeBtn.style.display = 'none';
    });

    // Upload Photo Logic
    uploadInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                capturedDataUrl = event.target.result;
                
                uploadedPreview.src = capturedDataUrl;
                uploadedPreview.style.display = 'block';
                uploadLabelBox.style.display = 'none';
                clearUploadBtn.style.display = 'inline-flex';
                
                // Clear camera capture if exists
                if (retakeBtn.style.display !== 'none') {
                    retakeBtn.click();
                }
            };
            reader.readAsDataURL(file);
        }
    });

    clearUploadBtn.addEventListener('click', () => {
        capturedDataUrl = null;
        uploadInput.value = '';
        uploadedPreview.style.display = 'none';
        uploadLabelBox.style.display = 'flex';
        clearUploadBtn.style.display = 'none';
    });

    // Unified Submission
    submitBtn.addEventListener('click', async () => {
        const confirmSubmit = confirm("Are you sure you want to update your credentials and face data?");
        if (!confirmSubmit) return;

        submitBtn.disabled = true;
        submitBtn.textContent = "Saving...";
        
        const payload = {
            full_name: document.getElementById('full_name').value,
            role_id: document.getElementById('role_id').value,
            email: document.getElementById('email').value,
            section: document.getElementById('section').value,
            imageBase64: capturedDataUrl // Can be null if they only updated credentials
        };

        try {
            const res = await fetch('update_student.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            statusMsg.style.display = "block";
            if (data.success) {
                statusMsg.textContent = "Credentials and Face Data updated successfully!";
                statusMsg.style.color = "var(--accent-green)";
                setTimeout(() => {
                    // Reset form for next user
                    document.getElementById('full_name').value = '';
                    document.getElementById('role_id').value = '';
                    document.getElementById('email').value = '';
                    document.getElementById('section').value = '';
                    
                    if (retakeBtn.style.display !== 'none') {
                        retakeBtn.click();
                    }
                    if (clearUploadBtn.style.display !== 'none') {
                        clearUploadBtn.click();
                    }
                    
                    statusMsg.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.textContent = "Confirm & Submit Credentials";
                }, 2000);
            } else {
                statusMsg.textContent = "Error: " + data.error;
                statusMsg.style.color = "var(--accent-red)";
                submitBtn.disabled = false;
                submitBtn.textContent = "Confirm & Submit Credentials";
            }
        } catch (err) {
            statusMsg.textContent = "Network error. Try again.";
            statusMsg.style.color = "var(--accent-red)";
            statusMsg.style.display = "block";
            submitBtn.disabled = false;
            submitBtn.textContent = "Confirm & Submit Credentials";
        }
    });


</script>
<script src="../../assets/js/logout.js"></script>
</body>
</html>
