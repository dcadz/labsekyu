<?php
// core/auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function checkAccess(array $allowedRoles) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: /labsekyu2/public/index.php");
        exit();
    }

    if (!in_array($_SESSION['role'], $allowedRoles)) {
        http_response_code(403);
        die("Unauthorized Access: You do not have permission to view this dashboard.");
    }
}
?>