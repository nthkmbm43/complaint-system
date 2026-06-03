<?php
require 'config/config.php';
require 'config/database.php';
$db = getDB();
try {
    $tables = $db->fetchAll('SHOW TABLES');
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
