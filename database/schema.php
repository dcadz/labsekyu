<?php
$mysqli = new mysqli('localhost', 'root', '', 'rfid_db');
$result = $mysqli->query('SHOW CREATE TABLE attendance');
$row = $result->fetch_assoc();
echo $row['Create Table'];
?>
