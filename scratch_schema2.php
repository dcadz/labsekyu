<?php
require_once __DIR__ . '/core/db_connect.php';
print_r($pdo->query('SHOW CREATE TABLE students')->fetch());
