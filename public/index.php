<?php
// auth/login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If accessed via GET, we enforce a fresh session by clearing the current one.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_unset();
    session_destroy();
    
    // Restart session for the new login attempt
    session_start();
} else {
    // If it's a POST request (they are logging in right now), let it proceed.
    // If they somehow had an active session during a POST request, we still let the new login override it.
}

require_once __DIR__ . '/../config/database.php';

$error = '';
$loading = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // The role input comes from the UI selection
    $selected_role = $_POST['role'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, role, role_id, password_hash FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Optional: enforce that the user is logging into their correct role portal
            if ($user['role'] !== $selected_role) {
                $error = 'Account does not have access to the ' . htmlspecialchars($selected_role) . ' portal.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role_id'] = $user['role_id'];

                $redirect = "dashboards/" . $user['role'] . "/";
                if ($user['role'] === 'student') {
                    $face_path = __DIR__ . '/../storage/face_data/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $user['role_id']) . '.jpg';
                    if (!file_exists($face_path)) {
                        $redirect = "dashboards/student/student.php";
                    } else {
                        $redirect = "dashboards/student/schedule.php";
                    }
                }
                if ($user['role'] === 'teacher') $redirect = "dashboards/teacher/teacher.php";
                if ($user['role'] === 'tech_support') $redirect = "dashboards/tech_support/techsupport.php";
                
                // Show loading animation before redirect (simulated via JS delay)
                echo "<script>
                    setTimeout(() => {
                        window.location.href = '$redirect';
                    }, 1500);
                </script>";
                $loading = true;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LABSEKYU - Secure Laboratory Access</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --brand-primary: #0A3161; /* University Blue */
            --brand-accent: #F2A900; /* Gold */
            --bg-canvas: #F4F6F8; /* Off-White/Canvas */
            --bg-surface: #FFFFFF; /* White */
            --text-main: #1A1A1A;
            --text-muted: #666666;
            --border-color: #E0E4E8;
            --error-red: #D32F2F;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Split Screen Layout */
        .split-layout {
            display: flex;
            width: 100vw;
            height: 100vh;
        }

        /* Left Side: Branding & Info */
        .branding-side {
            flex: 1;
            background-color: var(--brand-primary);
            color: #FFFFFF;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 4rem;
            position: relative;
        }

        .branding-side::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(242, 169, 0, 0.1) 0%, transparent 100%);
            pointer-events: none;
        }

        .brand-title {
            font-size: 4rem;
            font-weight: 800;
            letter-spacing: 2px;
            margin-bottom: 1rem;
            color: #FFFFFF;
        }
        
        .brand-subtitle {
            font-size: 1.5rem;
            color: var(--brand-accent);
            margin-bottom: 3rem;
            font-weight: 500;
        }

        .system-status {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.2);
            max-width: 400px;
        }

        .status-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 0.75rem;
        }
        .status-item:last-child { margin-bottom: 0; }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: #4CAF50;
            box-shadow: 0 0 8px #4CAF50;
        }

        /* Right Side: Login Container */
        .login-side {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--bg-canvas);
            padding: 2rem;
        }

        .login-card {
            background: var(--bg-surface);
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 480px;
            position: relative;
            overflow: hidden;
        }

        /* Error Message */
        .error-alert {
            background-color: #FDECEA;
            color: var(--error-red);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: <?php echo $error ? 'flex' : 'none'; ?>;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            border-left: 4px solid var(--error-red);
        }

        /* View State Management */
        .view-state {
            display: none;
            opacity: 0;
            transform: translateX(20px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .view-state.active {
            display: block;
            opacity: 1;
            transform: translateX(0);
        }

        /* Role Selection Grid */
        h2.section-title {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            color: var(--brand-primary);
        }
        p.section-desc {
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        .role-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .role-card {
            background: var(--bg-surface);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 48px; /* Fitts's Law */
        }

        .role-card:hover {
            border-color: var(--brand-accent);
            background-color: rgba(242, 169, 0, 0.05);
            transform: translateY(-2px);
        }
        
        .role-card .role-icon {
            background: var(--bg-canvas);
            color: var(--brand-primary);
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            transition: all 0.2s;
        }

        .role-card:hover .role-icon {
            background: var(--brand-primary);
            color: var(--brand-accent);
        }

        .role-card .role-info {
            display: flex;
            flex-direction: column;
        }
        .role-card .role-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--brand-primary);
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }
        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-main);
        }
        .form-group input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
            background-color: var(--bg-canvas);
        }
        .form-group input:focus {
            outline: none;
            border-color: var(--brand-primary);
            background-color: var(--bg-surface);
        }
        .form-group .input-icon {
            position: absolute;
            left: 1rem;
            top: 2.4rem;
            color: var(--text-muted);
        }

        .btn-primary {
            width: 100%;
            background-color: var(--brand-primary);
            color: #FFFFFF;
            border: none;
            padding: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
            min-height: 48px; /* Fitts's Law */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-primary:hover {
            background-color: #082446;
        }

        .btn-back {
            background: none;
            border: none;
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: color 0.2s;
        }
        .btn-back:hover { color: var(--brand-primary); }

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .btn-primary.is-loading .spinner { display: block; }
        .btn-primary.is-loading span { display: none; }

        /* Success Overlay */
        .success-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: var(--brand-primary);
            z-index: 100;
            display: <?php echo $loading ? 'flex' : 'none'; ?>;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            border-radius: 16px;
        }
        .success-overlay .spinner {
            display: block;
            width: 50px;
            height: 50px;
            border-width: 4px;
            margin-bottom: 1rem;
            border-top-color: var(--brand-accent);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .split-layout { flex-direction: column; }
            .branding-side { padding: 2rem; flex: 0 0 auto; }
            .brand-title { font-size: 2.5rem; }
            .brand-subtitle { font-size: 1.2rem; margin-bottom: 1.5rem; }
            .login-side { padding: 1rem; align-items: flex-start; }
            .login-card { padding: 2rem; }
        }
    </style>
</head>
<body>

<div class="split-layout">
    <!-- Left Side: Branding -->
    <div class="branding-side">
        <h1 class="brand-title">LABSEKYU</h1>
        <div class="brand-subtitle">College of Computing and Informatics</div>
    </div>

    <!-- Right Side: Login Forms -->
    <div class="login-side">
        <div class="login-card">
            
            <!-- Success Overlay -->
            <div class="success-overlay">
                <div class="spinner"></div>
                <h2>Authenticating...</h2>
            </div>

            <?php if ($error): ?>
                <div class="error-alert" id="errorAlert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- View 1: Select Role -->
            <div class="view-state active" id="viewSelectRole">
                <h2 class="section-title">Welcome Back</h2>

                <div class="role-grid">
                    <div class="role-card" onclick="selectRole('student', 'Student Portal')">
                        <div class="role-icon"><i class="fa-solid fa-book-reader"></i></div>
                        <div class="role-info">
                            <span class="role-name">Student</span>
                        </div>
                    </div>
                    <div class="role-card" onclick="selectRole('teacher', 'Teacher Portal')">
                        <div class="role-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                        <div class="role-info">
                            <span class="role-name">Teacher</span>
                        </div>
                    </div>
                    <div class="role-card" onclick="selectRole('tech_support', 'Admin Portal')">
                        <div class="role-icon"><i class="fa-solid fa-server"></i></div>
                        <div class="role-info">
                            <span class="role-name">Tech Support</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- View 2: Credentials Form -->
            <div class="view-state" id="viewCredentials">
                <button class="btn-back" onclick="goBack()">
                    <i class="fa-solid fa-arrow-left"></i> Back to Roles
                </button>
                
                <h2 class="section-title" id="selectedRoleTitle">Portal Login</h2>

                <form id="loginForm" method="POST" action="">
                    <input type="hidden" name="role" id="roleInput" value="">
                    
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <i class="fa-solid fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" placeholder="user@university.edu" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>

                    <button type="submit" class="btn-primary" id="loginSubmitBtn">
                        <span>Log In</span>
                        <div class="spinner"></div>
                    </button>
                </form>
            </div>

        </div>
    </div>
</div>

<script>
    const viewSelectRole = document.getElementById('viewSelectRole');
    const viewCredentials = document.getElementById('viewCredentials');
    const roleInput = document.getElementById('roleInput');
    const selectedRoleTitle = document.getElementById('selectedRoleTitle');
    const errorAlert = document.getElementById('errorAlert');
    const loginForm = document.getElementById('loginForm');
    const loginSubmitBtn = document.getElementById('loginSubmitBtn');
    const emailInput = document.getElementById('email');

    // Handle postback persistence if an error occurred
    window.onload = function() {
        const submittedRole = "<?php echo htmlspecialchars($_POST['role'] ?? ''); ?>";
        if (submittedRole) {
            let roleTitle = 'Student Portal';
            if(submittedRole === 'tech_support') roleTitle = 'Admin Portal';
            if(submittedRole === 'teacher') roleTitle = 'Teacher Portal';
            
            // Switch views instantly without hiding error
            viewSelectRole.classList.remove('active');
            viewCredentials.classList.add('active');
            roleInput.value = submittedRole;
            selectedRoleTitle.textContent = roleTitle;
        }
    }

    function selectRole(roleId, roleTitle) {
        if(errorAlert) errorAlert.style.display = 'none';
        
        roleInput.value = roleId;
        selectedRoleTitle.textContent = roleTitle;

        viewSelectRole.classList.remove('active');
        setTimeout(() => {
            viewCredentials.classList.add('active');
            emailInput.focus();
        }, 150);
    }

    function goBack() {
        if(errorAlert) errorAlert.style.display = 'none';
        
        viewCredentials.classList.remove('active');
        setTimeout(() => {
            viewSelectRole.classList.add('active');
        }, 150);
    }

    loginForm.addEventListener('submit', function() {
        loginSubmitBtn.classList.add('is-loading');
    });
</script>

</body>
</html>
