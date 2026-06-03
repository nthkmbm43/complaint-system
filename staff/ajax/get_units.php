<?php
// ajax/get_units.php - ดึงสาขาตามคณะที่เลือก
define('SECURE_ACCESS', true);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบการล็อกอิน
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDB();

$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;

if ($faculty_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid faculty_id']);
    exit;
}

try {
    // ดึงสาขาที่อยู่ภายใต้คณะที่เลือก
    $majors = $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_icon 
        FROM organization_unit 
        WHERE Unit_type = 'major' AND Unit_parent_id = ?
        ORDER BY Unit_name
    ", [$faculty_id]);

    echo json_encode([
        'success' => true,
        'majors' => $majors
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
