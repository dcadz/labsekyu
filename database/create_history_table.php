<?php
$mysqli = new mysqli('localhost', 'root', '', 'rfid_db');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// Create attendance_history table similar to attendance
$sql = "CREATE TABLE IF NOT EXISTS `attendance_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `log_date` date NOT NULL,
  `time_in` time NOT NULL,
  `time_out` time DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `device_id` varchar(50) DEFAULT 'Unknown',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

if ($mysqli->query($sql) === TRUE) {
    echo "Table attendance_history created successfully.";
} else {
    echo "Error creating table: " . $mysqli->error;
}
$mysqli->close();
?>
