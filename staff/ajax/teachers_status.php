<?php
// ajax/teachers_status.php - Teacher status management (suspend/unsuspend)
define('SECURE_ACCESS', true);

// เพิ่ม output buffering และ error handling
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ล้าง output buffer ก่อนส่ง JSON response
ob_clean();

// Set JSON response header ก่อนอื่นหมด
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'ไม่อนุญาตให้ใช้วิธีนี้']);
    exit;
}

// Check authentication and permissions
if (!isLoggedIn() || !hasRole('teacher')) {
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

try {
    $db = getDB();
    $user = getCurrentUser();
    $userPermission = $_SESSION['permission'] ?? 0;

    // Check if user has edit permissions
    if ($userPermission < 2) {
        echo json_encode(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ในการจัดการสถานะอาจารย์/เจ้าหน้าที่']);
        exit;
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'ข้อมูลที่ส่งมาไม่ถูกต้อง']);
        exit;
    }

    $action = $input['action'] ?? '';

    switch ($action) {
        case 'toggle_status':
            handleToggleStatus($db, $input, $userPermission);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'การดำเนินการไม่ถูกต้อง']);
    }
} catch (Exception $e) {
    error_log("Teachers status error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดภายในระบบ']);
}

function handleToggleStatus($db, $input, $userPermission)
{
    try {
        // Validate required fields
        if (empty($input['teacher_id']) || !isset($input['new_status'])) {
            echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
            return;
        }

        $teacherId = intval($input['teacher_id']);
        $newStatus = intval($input['new_status']);
        $suspendReason = trim($input['suspend_reason'] ?? '');
        $releaseReason = trim($input['release_reason'] ?? '');

        // Validate status
        if ($newStatus < 0 || $newStatus > 1) {
            echo json_encode(['success' => false, 'message' => 'สถานะไม่ถูกต้อง']);
            return;
        }

        // Get teacher data
        $teacher = $db->fetchOne("SELECT * FROM teacher WHERE Aj_id = ?", [$teacherId]);

        if (!$teacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์/เจ้าหน้าที่']);
            return;
        }

        // ป้องกันการระงับตัวเอง
        $currentUserId = $_SESSION['user_id'] ?? 0;
        if ($teacherId == $currentUserId && $newStatus == 0) {
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถระงับบัญชีของตัวเองได้']);
            return;
        }

        // ตรวจสอบการเปลี่ยนแปลงสถานะ
        if ($teacher['Aj_status'] == $newStatus) {
            $statusText = $newStatus == 1 ? 'ใช้งานได้' : 'ถูกระงับ';
            echo json_encode(['success' => false, 'message' => "อาจารย์/เจ้าหน้าที่มีสถานะ{$statusText}อยู่แล้ว"]);
            return;
        }

        // เริ่ม transaction
        $db->beginTransaction();

        // อัปเดตสถานะ
        $sql = "UPDATE teacher SET 
                    Aj_status = ?, 
                    updated_at = NOW()
                WHERE Aj_id = ?";

        $result = $db->execute($sql, [$newStatus, $teacherId]);

        if ($result) {
            // บันทึกประวัติการเปลี่ยนแปลงสถานะ (ถ้าต้องการ)
            // สามารถเพิ่มตารางสำหรับเก็บประวัติการระงับ/ยกเลิกระงับได้

            $db->commit();

            $actionText = $newStatus == 1 ? 'ยกเลิกระงับ' : 'ระงับ';
            $teacherName = htmlspecialchars($teacher['Aj_name']);

            echo json_encode([
                'success' => true,
                'message' => "{$actionText}อาจารย์/เจ้าหน้าที่ {$teacherName} สำเร็จ",
                'new_status' => $newStatus
            ]);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถเปลี่ยนสถานะได้']);
        }
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Toggle status error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการเปลี่ยนสถานะ']);
    }
}
