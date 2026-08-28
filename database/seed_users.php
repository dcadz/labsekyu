<?php
require_once __DIR__ . '/../core/db_connect.php';

$password = 'password123';
$hash = password_hash($password, PASSWORD_DEFAULT);

$users = [
    [
        'role_id' => 'TECH-001',
        'full_name' => 'Admin Support',
        'email' => 'admin@isatu.edu.ph',
        'role' => 'tech_support'
    ],
    [
        'role_id' => 'TCH-101',
        'full_name' => 'Prof. Alan Turing',
        'email' => 'teacher@isatu.edu.ph',
        'role' => 'teacher'
    ],
    [
        'role_id' => 'STU-2024-001',
        'full_name' => 'Ada Lovelace',
        'email' => 'student@isatu.edu.ph',
        'role' => 'student'
    ]
];

echo "Seeding users...\n";

$stmt = $pdo->prepare("INSERT INTO users (role_id, full_name, email, password_hash, role) VALUES (:role_id, :full_name, :email, :password_hash, :role) ON DUPLICATE KEY UPDATE full_name = VALUES(full_name)");

foreach ($users as $user) {
    $stmt->execute([
        ':role_id' => $user['role_id'],
        ':full_name' => $user['full_name'],
        ':email' => $user['email'],
        ':password_hash' => $hash,
        ':role' => $user['role']
    ]);
    echo "Created/Updated {$user['role']}: {$user['email']} (Password: $password)\n";
}

// Seed a dummy class schedule for the teacher so the dashboard shows data
$teacherStmt = $pdo->prepare("SELECT id FROM users WHERE email = 'teacher@isatu.edu.ph'");
$teacherStmt->execute();
$teacher = $teacherStmt->fetch();

if ($teacher) {
    // Current day for active class mock
    $day = date('l'); 
    
    // Create a schedule that is currently active (spans the whole day for testing)
    $schedStmt = $pdo->prepare("INSERT INTO class_schedules (teacher_id, subject_code, section, day_of_week, start_time, end_time) VALUES (:tid, 'CS101', 'Block A', :day, '00:00:00', '23:59:59')");
    try {
        $schedStmt->execute([
            ':tid' => $teacher['id'],
            ':day' => $day
        ]);
        echo "Created active test schedule for teacher.\n";
    } catch(PDOException $e) {
        // Likely already exists due to no unique constraint handling in this quick script, ignore.
        echo "Schedule already exists or error: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
?>
