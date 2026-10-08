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
    <title>Login | LABSEKYU</title>
    <!-- FontAwesome for the precise icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-blue: #3661be;
            --text-peach: #FFD590;
            --text-dark: #00102a;
            --input-gray: #cfd6e0;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: var(--bg-blue);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* The main container holding everything */
        .login-container {
            width: 90%;
            max-width: 900px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            z-index: 10;
        }
        
        .header-section h1 {
            color: var(--text-peach);
            font-size: 3.5rem;
            letter-spacing: 2px;
            margin-bottom: 1rem;
            text-transform: uppercase;
        }
        .header-section h2 {
            color: var(--text-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 3rem;
        }

        /* Error message styling */
        .error-msg {
            background: #ff4d4f;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: bold;
            display: <?php echo $error ? 'block' : 'none'; ?>;
        }

        /* STATE 1: Role Selection Grid */
        .roles-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            width: 100%;
            transition: opacity 0.4s ease, transform 0.4s ease;
        }
        .roles-grid.hidden {
            opacity: 0;
            transform: scale(0.9);
            pointer-events: none;
            position: absolute; /* Take it out of flow so Form Grid can take its place */
        }
        
        .role-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .role-card:hover {
            transform: scale(1.1);
        }
        .role-icon {
            font-size: 8rem;
            color: #000;
            margin-bottom: 1.5rem;
            transition: color 0.3s ease;
        }
        .role-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: #000;
            transition: all 0.3s ease;
        }
        .role-card:hover .role-name {
            color: #fff;
            text-shadow: 0 0 10px rgba(255,255,255,0.8), 0 0 20px rgba(255,255,255,0.6);
        }

        /* STATE 2: Login Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 3rem;
            width: 100%;
            max-width: 800px;
            align-items: center;
            opacity: 0;
            transform: translateY(20px);
            pointer-events: none;
            transition: opacity 0.4s ease 0.2s, transform 0.4s ease 0.2s;
            position: absolute;
            top: 150px; /* Offset to sit below header */
        }
        .form-grid.active {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
            position: relative;
            top: 0;
        }
        
        .form-left {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .form-left .role-icon {
            font-size: 10rem;
            margin-bottom: 1rem;
        }
        .form-left .role-name {
            font-size: 2rem;
            color: #000;
        }
        
        .form-right {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
        }
        #loginForm {
            display: flex;
            flex-direction: column;
            gap: 1.2rem; /* The 3 tiers gap */
            width: 100%;
        }
        .form-input-group.floating {
            position: relative;
            width: 100%;
        }
        .form-input-group.floating input {
            width: 100%;
            padding: 1.2rem 1.2rem; /* Standard height */
            font-size: 1rem;
            font-weight: bold;
            text-align: left; /* Align left */
            background-color: var(--input-gray);
            border: none;
            border-radius: 8px; /* Slightly less rounded */
            color: #000;
            outline: none;
            transition: box-shadow 0.3s ease, padding 0.3s ease;
        }
        .form-input-group.floating label {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.95rem;
            font-weight: 600;
            color: #666;
            pointer-events: none;
            transition: all 0.3s ease;
        }
        /* Floating animation */
        .form-input-group.floating input:focus + label,
        .form-input-group.floating input:not(:placeholder-shown) + label {
            top: 0.5rem;
            font-size: 0.75rem;
            color: #333;
        }
        .form-input-group.floating input:focus,
        .form-input-group.floating input:not(:placeholder-shown) {
            padding: 1.6rem 1.2rem 0.8rem 1.2rem; /* Shift padding when floating */
        }
        .form-input-group.floating input:focus {
            box-shadow: 0 0 0 4px rgba(255, 213, 144, 0.4);
        }
        
        /* Password Toggle Icon */
        .toggle-password {
            position: absolute;
            right: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 1.2rem;
            transition: color 0.3s ease;
            z-index: 5;
        }
        .toggle-password:hover {
            color: #333;
        }
        
        /* Third Tier: Login Button */
        .login-btn {
            width: 100%;
            padding: 1.2rem;
            font-size: 1.1rem;
            font-weight: bold;
            background-color: var(--text-dark);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: background-color 0.3s ease, transform 0.1s;
        }
        .login-btn:hover {
            background-color: #001f4d;
        }
        .login-btn:active {
            transform: scale(0.98);
        }

        /* Loading Spinner */
        .spinner {
            display: none;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1.5s ease-in-out forwards; /* 1 revolution matching the redirect delay */
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .login-btn.is-loading .spinner {
            display: block;
        }
        .login-btn.is-loading .btn-text {
            display: none;
        }
        
        .back-btn {
            background: none;
            border: none;
            color: rgba(255,255,255,0.6);
            margin-top: 1.5rem;
            cursor: pointer;
            font-size: 1rem;
            text-decoration: underline;
            transition: color 0.3s ease;
        }
        .back-btn:hover {
            color: white;
        }

        /* Full page overlay loading for post-login success */
        .success-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: var(--bg-blue);
            z-index: 100;
            display: <?php echo $loading ? 'flex' : 'none'; ?>;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .success-overlay .spinner {
            display: block;
            width: 60px;
            height: 60px;
            border-width: 6px;
            margin-bottom: 20px;
        }
        .success-overlay h2 {
            color: white;
            font-size: 2rem;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>

<!-- Loading Overlay -->
<div class="success-overlay">
    <div class="spinner"></div>
    <h2>Authenticating...</h2>
</div>

<div class="login-container">
    <div class="header-section">
        <h1>LABSEKYU</h1>
        <h2>Welcome back, are you?</h2>
    </div>

    <?php if ($error): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- STATE 1: Role Selection Grid -->
    <div class="roles-grid" id="rolesGrid">
        <div class="role-card" onclick="selectRole('tech_support', 'Admin', 'fa-user-gear')">
            <i class="fa-solid fa-user-gear role-icon"></i>
            <div class="role-name">Admin</div>
        </div>
        <div class="role-card" onclick="selectRole('teacher', 'Teacher', 'fa-chalkboard-user')">
            <i class="fa-solid fa-chalkboard-user role-icon"></i>
            <div class="role-name">Teacher</div>
        </div>
        <div class="role-card" onclick="selectRole('student', 'Student', 'fa-book-reader')">
            <i class="fa-solid fa-book-reader role-icon"></i>
            <div class="role-name">Student</div>
        </div>
    </div>

    <!-- STATE 2: Login Form Grid -->
    <div class="form-grid" id="formGrid">
        <div class="form-left">
            <i class="fa-solid role-icon" id="selectedIcon"></i>
            <div class="role-name" id="selectedRoleName">Role Name</div>
        </div>
        <div class="form-right">
            <form id="loginForm" method="POST" action="">
                <input type="hidden" name="role" id="roleInput" value="">
                
                <div class="form-input-group floating">
                    <input type="text" id="email" name="email" placeholder=" " required autofocus>
                    <label for="email">Username</label>
                </div>
                <div class="form-input-group floating">
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label for="password">Password</label>
                    <i class="fa-solid fa-eye toggle-password" id="togglePassword"></i>
                </div>
                
                <!-- 3rd Tier -->
                <button type="submit" class="login-btn" id="loginSubmitBtn">
                    <span class="btn-text">Sign In</span>
                    <div class="spinner"></div>
                </button>
            </form>
            <button class="back-btn" onclick="goBack()">← Back to Role Selection</button>
        </div>
    </div>
</div>

<script>
    const rolesGrid = document.getElementById('rolesGrid');
    const formGrid = document.getElementById('formGrid');
    const selectedIcon = document.getElementById('selectedIcon');
    const selectedRoleName = document.getElementById('selectedRoleName');
    const roleInput = document.getElementById('roleInput');
    const loginForm = document.getElementById('loginForm');
    const loginSubmitBtn = document.getElementById('loginSubmitBtn');
    const errorMsg = document.querySelector('.error-msg');

    // On page load, check if there was an error submitting a specific role
    window.onload = function() {
        const submittedRole = "<?php echo htmlspecialchars($_POST['role'] ?? ''); ?>";
        if (submittedRole) {
            let roleName = 'Student';
            let iconClass = 'fa-book-reader';
            if(submittedRole === 'tech_support') { roleName = 'Admin'; iconClass = 'fa-user-gear'; }
            if(submittedRole === 'teacher') { roleName = 'Teacher'; iconClass = 'fa-chalkboard-user'; }
            selectRole(submittedRole, roleName, iconClass, true); // true = no animation delay
        }
    }

    function selectRole(roleId, roleName, iconClass, instant = false) {
        if(errorMsg && !instant) {
            errorMsg.style.display = 'none'; // hide error on new selection
        }
        
        // Hide grid
        rolesGrid.classList.add('hidden');
        
        // Populate form left side
        selectedIcon.className = 'fa-solid role-icon ' + iconClass;
        selectedRoleName.textContent = roleName;
        roleInput.value = roleId;

        // Show form
        if (instant) {
            formGrid.style.transition = 'none';
            formGrid.classList.add('active');
            setTimeout(() => { formGrid.style.transition = ''; }, 50);
        } else {
            formGrid.classList.add('active');
        }
    }

    function goBack() {
        formGrid.classList.remove('active');
        rolesGrid.classList.remove('hidden');
        if(errorMsg) errorMsg.style.display = 'none';
    }

    loginForm.addEventListener('submit', function() {
        loginSubmitBtn.classList.add('is-loading');
    });

    // Password Toggle Feature
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function () {
            // Toggle type attribute
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            // Toggle eye icon class
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    }
</script>

</body>
</html>
