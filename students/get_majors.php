<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ปิด error display เพื่อไม่ให้มี output ปนเปื้อน
ini_set('display_errors', 0);
error_reporting(0);

// ปิด output buffering ทั้งหมด
while (ob_get_level()) {
    ob_end_clean();
}

// Set header เป็น JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

try {
    // ตรวจสอบการล็อกอิน
    requireRole('student', 'login.php');

    // ตรวจสอบ faculty_id
    if (!isset($_GET['faculty_id']) || empty($_GET['faculty_id'])) {
        echo json_encode([]);
        exit;
    }

    $facultyId = (int)$_GET['faculty_id'];

    if ($facultyId <= 0) {
        echo json_encode([]);
        exit;
    }

    // เชื่อมต่อฐานข้อมูล
    $db = getDB();
    if (!$db) {
        throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
    }

    // ดึงข้อมูลสาขา
    $majors = $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_icon 
        FROM organization_unit 
        WHERE Unit_parent_id = ? AND Unit_type = 'major' AND Unit_status = 'active'
        ORDER BY Unit_name
    ", [$facultyId]);

    // ส่งข้อมูลกลับเป็น JSON
    echo json_encode($majors ?: [], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // Log error แต่ไม่แสดงให้ user เห็น
    error_log("get_majors.php error: " . $e->getMessage());

    // ส่ง empty array กลับ
    echo json_encode([]);
}
exit;
