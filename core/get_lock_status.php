<?php
// get_lock_status.php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "rfid_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$status_res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key='room_status'");
if ($status_res && $status_res->num_rows > 0) {
    echo trim($status_res->fetch_assoc()['setting_value']);
} else {
    echo 'LOCKED';
}
$conn->close();
?>
