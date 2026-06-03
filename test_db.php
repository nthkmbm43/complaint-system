<?php
require 'config/config.php';
require 'config/database.php';
$db = getDB();
$students = $db->fetchAll('SELECT Stu_id, Stu_name, created_at FROM student ORDER BY created_at DESC LIMIT 5');
print_r($students);
