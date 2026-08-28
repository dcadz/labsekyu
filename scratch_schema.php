<?php
require_once __DIR__ . '/core/db_connect.php';
$res = $pdo->query('SHOW CREATE TABLE users')->fetch(); 
print_r($res); 
$res2 = $pdo->query('SHOW CREATE TABLE attendance_logs')->fetch(); 
print_r($res2);
$res3 = $pdo->query('SHOW CREATE TABLE class_schedules')->fetch(); 
print_r($res3);
