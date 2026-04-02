<?php
/**
 * ajax/get_staff_list.php - ดึงรายชื่อเจ้าหน้าที่สำหรับการมอบหมายงาน
 * Clean version ที่จัดการ output buffer และ error handling อย่างเข้มงวด
 */

// ปิด error reporting ทั้งหมดเพื่อป้องกัน HTML output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);

// ล้าง output buffer ทั้งหมด
while (ob_get_level()) {
    ob_end_clean();
}

// เริ่ม output buffering ใหม่
ob_start();

// ตั้งค่า headers
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// ฟังก์ชันส่ง JSON response
function sendJsonResponse($data) {
    // ล้าง buffer
    if (ob_get_contents()) {
        ob_clean();
    }
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    if (ob_get_level()) {
        ob_end_flush();
    }
    exit;
}

// ฟังก์ชันส่ง error response
function sendErrorResponse($message, $errorCode = 'GENERAL_ERROR') {
    sendJsonResponse([
        'success' => false,
        'message' => $message,
        'error_code' => $errorCode,
        'staff_list' => [],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

try {
    // กำหนด SECURE_ACCESS
    if (!defined('SECURE_ACCESS')) {
        define('SECURE_ACCESS', true);
    }

    // ตรวจสอบไฟล์ที่จำเป็น
    $configPath = __DIR__ . '/../../config/config.php';
    $dbPath = __DIR__ . '/../../config/database.php';
    $authPath = __DIR__ . '/../../includes/auth.php';
    $functionsPath = __DIR__ . '/../../includes/functions.php';

    if (!file_exists($configPath)) {
        sendErrorResponse('ไฟล์ config.php ไม่พบ', 'CONFIG_NOT_FOUND');
    }

    if (!file_exists($dbPath)) {
        sendErrorResponse('ไฟล์ database.php ไม่พบ', 'DATABASE_NOT_FOUND');
    }

    if (!file_exists($authPath)) {
        sendErrorResponse('ไฟล์ auth.php ไม่พบ', 'AUTH_NOT_FOUND');
    }

    // รวมไฟล์ที่จำเป็น
    require_once $configPath;
    require_once $dbPath;
    require_once $authPath;
    
    if (file_exists($functionsPath)) {
        require_once $functionsPath;
    }

    // เริ่ม session ถ้ายังไม่เริ่ม
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // ตรวจสอบการล็อกอิน
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        sendErrorResponse('กรุณาเข้าสู่ระบบใหม่', 'NOT_LOGGED_IN');
    }

    // ตรวจสอบ role
    $userRole = $_SESSION['user_role'] ?? '';
    if ($userRole !== 'teacher') {
        sendErrorResponse('คุณไม่มีสิทธิ์ในการเข้าถึงข้อมูลนี้', 'INSUFFICIENT_ROLE');
    }

    // ตรวจสอบ permission level
    $permission = intval($_SESSION['permission'] ?? 0);
    if (!in_array($permission, [2, 3])) {
        sendErrorResponse("คุณไม่มีสิทธิ์ในการมอบหมายงาน (Permission: {$permission})", 'INSUFFICIENT_PERMISSION');
    }

    // เชื่อมต่อฐานข้อมูล
    $db = null;
    if (function_exists('getDB')) {
        $db = getDB();
    }

    if (!$db) {
        sendErrorResponse('ไม่สามารถเชื่อมต่อฐานข้อมูลได้', 'DATABASE_CONNECTION_FAILED');
    }

    // ทดสอบการเชื่อมต่อ
    try {
        $testResult = $db->fetch("SELECT 1 as test");
        if (!$testResult) {
            sendErrorResponse('การทดสอบฐานข้อมูลล้มเหลว', 'DATABASE_TEST_FAILED');
        }
    } catch (Exception $dbTest) {
        sendErrorResponse('การทดสอบฐานข้อมูลล้มเหลว: ' . $dbTest->getMessage(), 'DATABASE_TEST_ERROR');
    }

    // สร้าง SQL query
    $sql = "SELECT t.Aj_id, t.Aj_name, t.Aj_position, t.Aj_per,
                   ou.Unit_name, ou.Unit_icon, ou.Unit_type
            FROM teacher t
            LEFT JOIN organization_unit ou ON t.Unit_id = ou.Unit_id
            WHERE t.Aj_status = 1 
            ORDER BY t.Aj_per DESC, ou.Unit_name ASC, t.Aj_name ASC";

    // ดึงข้อมูลเจ้าหน้าที่
    $staffList = $db->fetchAll($sql);

    if ($staffList === false) {
        sendErrorResponse('เกิดข้อผิดพลาดในการดึงข้อมูลจากฐานข้อมูล', 'QUERY_FAILED');
    }

    if (empty($staffList)) {
        sendErrorResponse('ไม่พบข้อมูลเจ้าหน้าที่ที่ใช้งานอยู่', 'NO_STAFF_FOUND');
    }

    // จัดกลุ่มข้อมูลตามหน่วยงาน
    $groupedStaff = [];
    $ungrouped = [];

    foreach ($staffList as $staff) {
        if (!empty($staff['Unit_name'])) {
            $unitKey = $staff['Unit_name'];
            if (!isset($groupedStaff[$unitKey])) {
                $groupedStaff[$unitKey] = [
                    'unit_name' => $staff['Unit_name'],
                    'unit_icon' => $staff['Unit_icon'] ?? '',
                    'unit_type' => $staff['Unit_type'] ?? '',
                    'staff_count' => 0,
                    'staff' => []
                ];
            }
            $groupedStaff[$unitKey]['staff'][] = $staff;
            $groupedStaff[$unitKey]['staff_count']++;
        } else {
            $ungrouped[] = $staff;
        }
    }

    // เรียงลำดับหน่วยงาน
    ksort($groupedStaff);

    // เตรียมข้อมูล response
    $response = [
        'success' => true,
        'staff_list' => $staffList,
        'grouped_staff' => $groupedStaff,
        'ungrouped_staff' => $ungrouped,
        'total_count' => count($staffList),
        'permission_level' => $permission,
        'meta' => [
            'session_user' => $_SESSION['user_name'] ?? 'Unknown',
            'session_role' => $_SESSION['user_role'] ?? 'Unknown',
            'query_time' => date('Y-m-d H:i:s'),
            'groups_count' => count($groupedStaff),
            'ungrouped_count' => count($ungrouped)
        ]
    ];

    // ส่ง response
    sendJsonResponse($response);

} catch (Throwable $e) {
    // จับ error ทุกประเภท (รวม Fatal Error)
    
    // บันทึก error log
    $errorMsg = sprintf(
        "Staff List Error: %s in %s:%d\nStack trace:\n%s",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    
    error_log($errorMsg);

    // ส่ง error response
    sendErrorResponse(
        'เกิดข้อผิดพลาดในระบบ: ' . $e->getMessage(),
        'SYSTEM_ERROR'
    );
}

// Fallback ในกรณีที่ไม่มีการส่ง response
sendErrorResponse('เกิดข้อผิดพลาดที่ไม่คาดคิด', 'UNEXPECTED_ERROR');
?>