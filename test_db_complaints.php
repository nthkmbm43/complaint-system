<?php
require 'config/config.php';
require 'config/database.php';
$db = getDB();
try {
    $complaints = $db->fetchAll('SELECT Re_id, Re_title, Re_date, Stu_id FROM request ORDER BY Re_date DESC LIMIT 5');
    print_r($complaints);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
