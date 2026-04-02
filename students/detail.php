<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบสิทธิ์
requireRole('student', '../login.php');

$user = getCurrentUser();
$db = getDB();

$error = '';
$success = '';

// Handle AJAX requests สำหรับการอัพเดตแบบ real-time
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    switch ($_GET['action']) {
        case 'get_unread_count':
            echo json_encode(['unread_count' => getUnreadNotificationCount($user['Stu_id'], 'student')]);
            exit;

        case 'get_notifications':
            $notifications = getRecentNotifications($user['Stu_id'], 'student', 10);
            echo json_encode(['notifications' => $notifications]);
            exit;

        case 'mark_as_read':
            if (isset($_POST['notification_id'])) {
                $success = markSingleNotificationAsRead($_POST['notification_id'], $user['Stu_id'], 'student');
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
            }
            exit;

        case 'mark_all_as_read':
            try {
                $success = markAllNotificationsAsRead($user['Stu_id'], 'student');
                echo json_encode(['success' => $success]);
            } catch (Exception $e) {
                error_log("mark_all_as_read AJAX error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// ดึง ID ข้อร้องเรียนจาก URL
$requestId = $_GET['id'] ?? '';

if (empty($requestId) || !is_numeric($requestId)) {
    header('Location: tracking.php');
    exit;
}

// **แก้ไข: ฟังก์ชันดึงรายละเอียดข้อร้องเรียน - อัพเดตสำหรับฐานข้อมูลใหม่**
function getRequestDetail($requestId, $studentId = null)
{
    $db = getDB();
    if (!$db) return null;

    try {
        $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                       CASE WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน' ELSE 'ระบุตัวตน' END as identity_type,
                       sr.Sv_infor as staff_response, sr.Sv_date as response_date, sr.Sv_type, sr.Sv_note,
                       aj.Aj_name as staff_name, aj.Aj_position as staff_position,
                       asn.Aj_name as assigned_name, asn.Aj_position as assigned_position,
                       rr.Result_date,
                       e.Eva_score, e.Eva_sug as evaluation_comment, e.created_at as evaluation_date,
                       s.Stu_name as student_name, s.Stu_tel as student_tel, s.Stu_email as student_email,
                       major.Unit_name as major_name, major.Unit_icon as major_icon,
                       faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                LEFT JOIN teacher asn ON r.Aj_id = asn.Aj_id
                LEFT JOIN result_request rr ON r.Re_id = rr.Re_id
                LEFT JOIN evaluation e ON r.Re_id = e.Re_id
                WHERE r.Re_id = ?";

        // เพิ่มเงื่อนไขเฉพาะเมื่อระบุ student ID (สำหรับการตรวจสอบสิทธิ์)
        if ($studentId !== null) {
            $sql .= " AND r.Stu_id = ?";
            return $db->fetch($sql, [$requestId, $studentId]);
        } else {
            return $db->fetch($sql, [$requestId]);
        }
    } catch (Exception $e) {
        error_log("getRequestDetail error: " . $e->getMessage());
        return null;
    }
}

// **แก้ไข: ฟังก์ชันตรวจสอบสิทธิ์การเข้าถึง**
function canViewRequest($requestId, $studentId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        // ตรวจสอบว่าข้อร้องเรียนมีอยู่จริง
        $request = $db->fetch("SELECT Re_id FROM request WHERE Re_id = ?", [$requestId]);
        return $request !== false;
    } catch (Exception $e) {
        error_log("canViewRequest error: " . $e->getMessage());
        return false;
    }
}

// **แก้ไข: ฟังก์ชันตรวจสอบว่าเป็นเจ้าของข้อร้องเรียนหรือไม่**
function isRequestOwner($requestId, $studentId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $request = $db->fetch("SELECT Re_id FROM request WHERE Re_id = ? AND Stu_id = ?", [$requestId, $studentId]);
        return $request !== false;
    } catch (Exception $e) {
        error_log("isRequestOwner error: " . $e->getMessage());
        return false;
    }
}

// **แก้ไข: ฟังก์ชันดึงไฟล์แนับ - อัพเดตให้ตรงกับโครงสร้างใหม่**
function getRequestFiles($requestId)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $sql = "SELECT se.*, 
                       s.Stu_name as uploader_name,
                       t.Aj_name as teacher_uploader_name,
                       t.Aj_position as teacher_position,
                       CASE 
                           WHEN se.Aj_id IS NOT NULL THEN 'teacher'
                           ELSE 'student'
                       END as uploader_type
                FROM supporting_evidence se
                LEFT JOIN student s ON se.Sup_upload_by = s.Stu_id
                LEFT JOIN teacher t ON se.Aj_id = t.Aj_id
                WHERE se.Re_id = ? 
                ORDER BY se.Sup_upload_date DESC";
        return $db->fetchAll($sql, [$requestId]);
    } catch (Exception $e) {
        error_log("getRequestFiles error: " . $e->getMessage());
        return [];
    }
}

// **เพิ่ม: ฟังก์ชันแยกไฟล์ตามผู้อัปโหลด (นักศึกษา vs อาจารย์)**
function separateFilesByUploader($files)
{
    $result = [
        'student_files' => [],
        'teacher_files' => []
    ];

    foreach ($files as $file) {
        if ($file['uploader_type'] === 'teacher' && $file['Aj_id'] !== null) {
            $result['teacher_files'][] = $file;
        } else {
            $result['student_files'][] = $file;
        }
    }

    return $result;
}

// **แก้ไข: ฟังก์ชันดึง timeline - ปรับปรุงให้ใช้ข้อมูลจริงจากฐานข้อมูล**
// **แก้ไข: ปรับปรุงฟังก์ชัน getRequestTimeline ให้ตรวจสอบค่าว่างเพื่อป้องกัน Error**
function getRequestTimeline($requestId)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $request = $db->fetch("SELECT * FROM request WHERE Re_id = ?", [$requestId]);
        if (!$request) return [];

        $timeline = [];

        // 1. ส่งข้อร้องเรียน
        $timeline[] = [
            'title' => 'ส่งข้อร้องเรียน',
            'description' => 'ส่งข้อร้องเรียนเข้าระบบ',
            'date' => $request['Re_date'],
            'status' => 'completed',
            'icon' => '📝'
        ];

        // 2. ยืนยันข้อร้องเรียน (ถ้าสถานะ >= 1)
        if ($request['Re_status'] >= '1') {
            $saveRequest = $db->fetch("
                SELECT sr.*, aj.Aj_name, aj.Aj_position 
                FROM save_request sr 
                LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id 
                WHERE sr.Re_id = ? 
                ORDER BY sr.created_at ASC 
                LIMIT 1", [$requestId]);

            // แก้ไข: ตรวจสอบว่ามีข้อมูล $saveRequest หรือไม่ ก่อนเรียกใช้
            $staffName = ($saveRequest && isset($saveRequest['Aj_name'])) ? ' โดย ' . $saveRequest['Aj_name'] : '';
            $confirmDate = ($saveRequest && isset($saveRequest['Sv_date'])) ? $saveRequest['Sv_date'] : $request['Re_date'];

            $timeline[] = [
                'title' => 'ยืนยันข้อร้องเรียน',
                'description' => 'เจ้าหน้าที่ได้รับและยืนยันข้อร้องเรียนแล้ว' . $staffName,
                'date' => $confirmDate,
                'status' => 'completed',
                'icon' => '✅'
            ];
        }

        // 3. บันทึกผลการดำเนินงาน (ถ้าสถานะ >= 2)
        if ($request['Re_status'] >= '2') {
            $resultRequest = $db->fetch("
                SELECT rr.*, aj.Aj_name, aj.Aj_position 
                FROM result_request rr 
                LEFT JOIN teacher aj ON rr.Aj_id = aj.Aj_id 
                WHERE rr.Re_id = ?", [$requestId]);

            // แก้ไข: ตรวจสอบว่ามีข้อมูล $resultRequest หรือไม่ ก่อนเรียกใช้
            $staffResultName = ($resultRequest && isset($resultRequest['Aj_name'])) ? ' โดย ' . $resultRequest['Aj_name'] : '';
            $resultDate = ($resultRequest && isset($resultRequest['Result_date'])) ? $resultRequest['Result_date'] : date('Y-m-d');

            $timeline[] = [
                'title' => 'บันทึกผลการดำเนินงาน',
                'description' => 'เจ้าหน้าที่ได้ดำเนินการและบันทึกผลแล้ว' . $staffResultName,
                'date' => $resultDate,
                'status' => 'completed',
                'icon' => '📋'
            ];
        }

        // 4. ประเมินผล (ถ้าสถานะ = 3)
        if ($request['Re_status'] == '3') {
            $evaluation = $db->fetch("SELECT * FROM evaluation WHERE Re_id = ?", [$requestId]);
            $timeline[] = [
                'title' => 'ประเมินผลความพึงพอใจ',
                'description' => 'ผู้ส่งข้อร้องเรียนได้ประเมินผลความพึงพอใจแล้ว' .
                    ($evaluation ? ' (คะแนน: ' . $evaluation['Eva_score'] . '/5)' : ''),
                'date' => ($evaluation && isset($evaluation['created_at'])) ? $evaluation['created_at'] : date('Y-m-d'),
                'status' => 'completed',
                'icon' => '⭐'
            ];
        } else if ($request['Re_status'] == '2') {
            // รอประเมิน
            $timeline[] = [
                'title' => 'รอประเมินผลความพึงพอใจ',
                'description' => 'กรุณาประเมินความพึงพอใจในการให้บริการ',
                'date' => '',
                'status' => 'current',
                'icon' => '⏳'
            ];
        } else if ($request['Re_status'] == '4') {
            // ปฏิเสธ/ยกเลิกข้อร้องเรียน
            $timeline[] = [
                'title' => 'ปฏิเสธ/ยกเลิกข้อร้องเรียน',
                'description' => 'ข้อร้องเรียนนี้ถูกปฏิเสธหรือยกเลิกแล้ว',
                'date' => $request['Re_update'] ?? date('Y-m-d'),
                'status' => 'completed',
                'icon' => '❌'
            ];
        }

        return $timeline;
    } catch (Exception $e) {
        error_log("getRequestTimeline error: " . $e->getMessage());
        return [];
    }
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!canViewRequest($requestId, $user['Stu_id'])) {
    $error = 'ไม่พบข้อร้องเรียนที่ต้องการ';
}

// ตรวจสอบว่าเป็นเจ้าของข้อร้องเรียนหรือไม่
$isOwner = isRequestOwner($requestId, $user['Stu_id']);

// ดึงข้อมูลข้อร้องเรียน
$request = null;
if (!$error) {
    $request = getRequestDetail($requestId);
    if (!$request) {
        $error = 'ไม่พบข้อร้องเรียนที่ต้องการ';
    }
}

// ดึงไฟล์แนบ
$files = $request ? getRequestFiles($request['Re_id']) : [];

// แยกไฟล์ตามผู้อัปโหลด (นักศึกษา vs อาจารย์)
$separatedFiles = separateFilesByUploader($files);
$studentFiles = $separatedFiles['student_files'];
$teacherFiles = $separatedFiles['teacher_files'];

// ดึงประวัติการอัปเดต
$timeline = $request ? getRequestTimeline($request['Re_id']) : [];

// แก้ไข: ดึงข้อมูลการประเมินทั้งสถานะ 2 และ 3 เพื่อตรวจสอบว่าประเมินจริงหรือยัง
$evaluation = null;
if ($request && ($request['Re_status'] == '3' || $request['Re_status'] == '2')) {
    try {
        $evaluation = $db->fetch("SELECT * FROM evaluation WHERE Re_id = ?", [$request['Re_id']]);
    } catch (Exception $e) {
        // ไม่ต้องทำอะไร
    }
}

// Handle actions (เฉพาะเจ้าของเท่านั้น)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $request && $isOwner) {
    if (isset($_POST['cancel_request'])) {
        if ($request['Re_status'] === '0') {
            try {
                // ใช้สถานะ 4 สำหรับการยกเลิกข้อร้องเรียน (ใช้ร่วมกับปฏิเสธคำร้อง)
                $db->execute("UPDATE request SET Re_status = ? WHERE Re_id = ?", ['4', $request['Re_id']]);
                $success = 'ยกเลิกข้อร้องเรียนเรียบร้อย';

                // Refresh request data
                $request = getRequestDetail($requestId);
                $timeline = $request ? getRequestTimeline($request['Re_id']) : [];
            } catch (Exception $e) {
                error_log("Cancel request error: " . $e->getMessage());
                $error = 'เกิดข้อผิดพลาดในการยกเลิกข้อร้องเรียน';
            }
        } else {
            $error = 'ไม่สามารถยกเลิกข้อร้องเรียนที่อยู่ระหว่างดำเนินการได้';
        }
    }
}

// ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// **เพิ่ม: ฟังก์ชันตรวจสอบประเภทไฟล์**
function getFileIcon($fileExtension)
{
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    $documentTypes = ['pdf', 'doc', 'docx', 'txt', 'rtf'];
    $spreadsheetTypes = ['xls', 'xlsx', 'csv'];
    $presentationTypes = ['ppt', 'pptx'];
    $archiveTypes = ['zip', 'rar', '7z', 'tar', 'gz'];

    $ext = strtolower($fileExtension);

    if (in_array($ext, $imageTypes)) {
        return '🖼️';
    } elseif (in_array($ext, $documentTypes)) {
        return '📄';
    } elseif (in_array($ext, $spreadsheetTypes)) {
        return '📊';
    } elseif (in_array($ext, $presentationTypes)) {
        return '📑';
    } elseif (in_array($ext, $archiveTypes)) {
        return '📦';
    } else {
        return '📁';
    }
}

// **เพิ่ม: ฟังก์ชันตรวจสอบว่าเป็นไฟล์รูปภาพหรือไม่**
function isImageFile($fileExtension)
{
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    return in_array(strtolower($fileExtension), $imageTypes);
}

// **เพิ่ม: ฟังก์ชันสร้าง URL สำหรับแสดงรูปภาพ**
function getImageDisplayUrl($filePath)
{
    // ลิสต์โฟลเดอร์ที่ต้องตรวจสอบ
    $possiblePaths = [
        $filePath, // path เดิม
        '../uploads/requests/' . basename($filePath),
        '../uploads/requests/images/' . basename($filePath),
        '../uploads/' . basename($filePath),
        '../uploads/evidence/' . basename($filePath)
    ];

    // หาไฟล์ที่มีอยู่จริง
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            // แปลง path ให้เป็น URL ที่ถูกต้อง
            // detail.php อยู่ใน students/ ดังนั้นต้องใช้ ../
            if (strpos($path, '../uploads/') === 0) {
                return $path; // ถ้า path เริ่มต้นด้วย ../uploads/ แล้วให้ใช้เลย
            } else {
                // สร้าง relative path ใหม่
                $fileName = basename($path);
                return '../uploads/requests/' . $fileName;
            }
        }
    }

    return null; // ไม่พบไฟล์
}

// **เพิ่ม: ฟังก์ชันสร้าง URL สำหรับดาวน์โหลดไฟล์**
function getFileDownloadUrl($filePath, $fileName)
{
    // ตรวจสอบว่าไฟล์มีอยู่จริง
    if (!file_exists($filePath)) {
        return null;
    }

    // ใช้เฉพาะชื่อไฟล์ในการดาวน์โหลด
    $actualFileName = basename($filePath);
    return 'download.php?file=' . urlencode($actualFileName) . '&name=' . urlencode($fileName);
}

// **แก้ไข: ฟังก์ชันตรวจสอบสิทธิ์การแก้ไข/ยกเลิก - เฉพาะเจ้าของเท่านั้น**
function canModifyRequest($request, $currentUserId)
{
    // ตรวจสอบว่าเป็นเจ้าของข้อร้องเรียนหรือไม่
    if ($request['Stu_id'] !== $currentUserId) {
        return false;
    }

    // ตรวจสอบสถานะ - สามารถแก้ไขได้เฉพาะเมื่อยังเป็น pending (0)
    return $request['Re_status'] === '0';
}

// **แก้ไข: ฟังก์ชันการแสดงข้อมูลตามโหมดไม่ระบุตัวตน**
function shouldShowPersonalInfo($request, $currentUserId = null)
{
    // ถ้าเป็นโหมดไม่ระบุตัวตน
    if ($request['Re_iden'] == 1) {
        // แสดงข้อมูลส่วนตัวได้เฉพาะเจ้าของข้อร้องเรียน
        return $currentUserId && $request['Stu_id'] === $currentUserId;
    }

    // ถ้าเป็นโหมดระบุตัวตน แสดงได้ทุกคน
    return true;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดข้อร้องเรียน - <?php echo SITE_NAME; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding-top: 70px;
        }

        /* Top Header */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #e1e5e9;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .mobile-menu-toggle {
            display: block;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .hamburger {
            width: 24px;
            height: 18px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .hamburger span {
            width: 100%;
            height: 2px;
            background: #333;
            border-radius: 1px;
            transition: all 0.3s ease;
        }

        .header-title h1 {
            font-size: 1.2rem;
            margin: 0;
            color: #333;
        }

        .header-title p {
            margin: 0;
            font-size: 0.85rem;
            color: #666;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        .header-notification {
            position: relative;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header-notification:hover {
            background: #e9ecef;
            transform: scale(1.05);
        }

        .header-notification.active {
            background: #667eea;
            color: white;
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            min-width: 20px;
            animation: pulse 2s infinite;
        }

        .notification-badge.zero {
            display: none;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Notification Dropdown */
        .notification-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 350px;
            max-height: 400px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }

        .notification-header h3 {
            margin: 0;
            font-size: 1rem;
            color: #333;
        }

        .mark-all-read {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .mark-all-read:hover {
            background: #667eea;
            color: white;
        }

        .notification-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f3f4;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: rgba(102, 126, 234, 0.05);
            border-left: 3px solid #667eea;
        }

        .notification-item.unread::before {
            content: '';
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background: #667eea;
            border-radius: 50%;
        }

        .notification-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .notification-message {
            color: #666;
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 5px;
        }

        .notification-time {
            color: #999;
            font-size: 0.75rem;
        }

        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }

        .no-notifications .icon {
            font-size: 3rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
        }

        .user-menu:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .user-name {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: #666;
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
            padding: 20px;
        }

        .main-content.shifted {
            margin-left: 300px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            color: #333;
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #666;
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 114, 128, 0.3);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn:disabled,
        .btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Detail Layout */
        .detail-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }

        .detail-main {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .detail-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        /* Request Card */
        .request-detail-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #667eea;
        }

        .request-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }

        .request-id {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }

        .request-title {
            font-size: 1.3rem;
            color: #333;
            margin-bottom: 15px;
            line-height: 1.4;
        }

        .request-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .meta-icon {
            width: 20px;
            text-align: center;
        }

        .meta-label {
            font-weight: 500;
            color: #666;
        }

        .meta-value {
            color: #333;
        }

        .request-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-status-0 {
            background: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }

        .badge-status-1 {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .badge-status-2 {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .badge-status-3 {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .badge-status-4 {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .badge-priority-1 {
            background: #e2e3e5;
            color: #383d41;
        }

        .badge-priority-2 {
            background: #ffeaa7;
            color: #856404;
        }

        .badge-priority-3 {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-priority-4 {
            background: #ff6b6b;
            color: white;
        }

        .badge-priority-5 {
            background: #6f42c1;
            color: white;
        }

        .request-description {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            line-height: 1.6;
            color: #333;
            margin-bottom: 20px;
        }

        /* Staff Response */
        .staff-response {
            background: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 20px;
            border-radius: 0 10px 10px 0;
            margin: 20px 0;
        }

        .staff-response h4 {
            color: #1976d2;
            margin-bottom: 10px;
        }

        .staff-response-content {
            line-height: 1.6;
            color: #333;
        }

        .staff-response-meta {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }

        /* Timeline */
        .timeline-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .timeline-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .timeline-header h3 {
            color: #333;
            margin: 0;
        }

        .timeline {
            position: relative;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e1e5e9;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 25px;
            margin-left: 50px;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-icon {
            position: absolute;
            left: -50px;
            top: 5px;
            width: 40px;
            height: 40px;
            background: #667eea;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .timeline-icon.completed {
            background: #28a745;
        }

        .timeline-icon.current {
            background: #ffc107;
            animation: pulse 2s infinite;
        }

        .timeline-icon.pending {
            background: #6c757d;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(255, 193, 7, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        .timeline-content {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
        }

        .timeline-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .timeline-description {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .timeline-date {
            font-size: 12px;
            color: #999;
        }

        /* Action Buttons */
        .action-buttons {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .action-buttons h3 {
            color: #333;
            margin-bottom: 15px;
        }

        .actions-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .actions-grid .btn {
            justify-content: center;
        }

        /* Files Section */
        .files-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .files-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .files-header h3 {
            color: #333;
            margin: 0;
        }

        .file-item {
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            border: 1px solid #e1e5e9;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .file-main {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }

        .file-meta {
            font-size: 12px;
            color: #666;
        }

        .file-actions {
            display: flex;
            gap: 8px;
        }

        .file-actions .btn {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Image Preview */
        .image-preview {
            margin-top: 10px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .image-preview img {
            width: 100%;
            max-width: 300px;
            height: auto;
            display: block;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .image-preview img:hover {
            transform: scale(1.02);
        }

        /* Staff Files Section (เจ้าหน้าที่แนบไฟล์) */
        .staff-files-section {
            margin-top: 20px;
        }

        .staff-files-header {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .staff-completed-badge {
            margin-bottom: 10px;
        }

        .staff-completed-badge .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            animation: completedPulse 2s ease-in-out infinite;
        }

        @keyframes completedPulse {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            }

            50% {
                transform: scale(1.02);
                box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            }
        }

        .file-uploader-info {
            margin-top: 8px;
        }

        .uploader-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #90caf9;
        }

        /* Badge styles */
        .badge-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        /* Image Modal */
        .image-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .image-modal img {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
        }

        .image-modal .close-modal {
            position: absolute;
            top: 20px;
            right: 30px;
            font-size: 3rem;
            color: white;
            cursor: pointer;
            font-weight: bold;
            z-index: 2001;
        }

        .image-modal .close-modal:hover {
            color: #ccc;
        }

        /* Evaluation Display */
        .evaluation-display {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #28a745;
        }

        .evaluation-display h3 {
            color: #28a745;
            margin-bottom: 20px;
        }

        .evaluation-score {
            text-align: center;
            margin-bottom: 20px;
        }

        .score-value {
            font-size: 3rem;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 10px;
        }

        .score-label {
            color: #666;
            font-size: 1.1rem;
        }

        .evaluation-comment {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            line-height: 1.6;
            color: #333;
            font-style: italic;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }

        .alert-success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
        }

        /* Request Info Card */
        .request-info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .request-info-card h3 {
            color: #333;
            margin-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-weight: 600;
            color: #333;
        }

        /* Anonymous Notice */
        .anonymous-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .anonymous-notice .icon {
            font-size: 2.5rem;
            flex-shrink: 0;
        }

        .anonymous-notice .content h4 {
            margin: 0 0 8px 0;
            color: #856404;
        }

        .anonymous-notice .content p {
            margin: 0;
            color: #856404;
            line-height: 1.5;
        }

        /* Toast Notification */
        .toast {
            position: fixed;
            top: 90px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            z-index: 1002;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        .toast.error {
            background: #dc3545;
        }

        .toast.info {
            background: #17a2b8;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-title h1 {
                font-size: 1rem;
            }

            .header-title p {
                display: none;
            }

            .user-menu {
                min-width: auto;
                width: 45px;
                height: 45px;
                padding: 0;
                border-radius: 50%;
                justify-content: center;
            }

            .user-info {
                display: none;
            }

            .main-content {
                padding: 15px;
            }

            .main-content.shifted {
                margin-left: 0;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .detail-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .detail-sidebar {
                order: -1;
            }

            .request-meta {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .image-preview img {
                max-width: 100%;
            }

            .notification-dropdown {
                width: calc(100vw - 40px);
                right: -150px;
            }
        }

        @media (min-width: 1024px) {
            .main-content.desktop-shifted {
                margin-left: 300px;
            }
        }
    </style>
</head>

<body>
    <!-- Top Header -->
    <header class="top-header">
        <div class="header-left">
            <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                <div class="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>
            <div class="header-title">
                <h1>📄 รายละเอียดข้อร้องเรียน</h1>
                <p>ดูรายละเอียดและติดตามความคืบหน้า</p>
            </div>            
        </div>

        <div class="header-right">
            <div class="header-notification" id="notificationButton" onclick="toggleNotificationDropdown()">
                <span style="font-size: 18px;">🔔</span>
                <span class="notification-badge<?php echo $unreadCount > 0 ? '' : ' zero'; ?>" id="notificationBadge">
                    <?php echo $unreadCount; ?>
                </span>

                <!-- Notification Dropdown -->
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h3>การแจ้งเตือน</h3>
                        <button class="mark-all-read" onclick="markAllAsRead()">อ่านทั้งหมด</button>
                    </div>
                    <div class="notification-list" id="notificationList">
                        <!-- Notifications will be loaded here -->
                    </div>
                </div>
            </div>

            <div class="user-menu">
                <div class="user-avatar">👨‍🎓</div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($user['Stu_name']); ?></span>
                    <span class="user-role">นักศึกษา</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Include Sidebar -->
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($_GET['message']) && $_GET['message'] === 'permission_denied'): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "คุณไม่มีสิทธิ์เข้าถึงหน้านั้น เนื่องจากหน้าดังกล่าวสำหรับเจ้าหน้าที่และผู้ดูแลระบบเท่านั้น",
                    null
                );
            });
        </script>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>📄 รายละเอียดข้อร้องเรียน</h1>
                    <p>ดูรายละเอียดและติดตามความคืบหน้าของข้อร้องเรียน</p>
                </div>
                <a href="tracking.php" class="btn btn-secondary">← กลับรายการ</a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span>❌</span>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span>✅</span>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($request): ?>
                <!-- Detail Layout -->
                <div class="detail-layout">
                    <!-- Main Content -->
                    <div class="detail-main">
                        <!-- Request Details -->
                        <div class="request-detail-card">
                            <div class="request-header">
                                <div class="request-id">เลขที่ #CR<?php echo str_pad($request['Re_id'], 6, '0', STR_PAD_LEFT); ?></div>
                                <div class="request-title">
                                    <?php echo htmlspecialchars($request['Re_title']); ?>
                                </div>

                                <div class="request-meta">
                                    <div class="meta-item">
                                        <span class="meta-icon">📅</span>
                                        <span class="meta-label">วันที่ส่ง:</span>
                                        <span class="meta-value"><?php echo formatThaiDateOnly($request['Re_date']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-icon">📊</span>
                                        <span class="meta-label">ประเภท:</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($request['Type_icon'] . ' ' . $request['Type_infor']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <span class="meta-icon">🔐</span>
                                        <span class="meta-label">ประเภทผู้ส่ง:</span>
                                        <span class="meta-value"><?php echo htmlspecialchars($request['identity_type']); ?></span>
                                    </div>
                                    <?php if ($request['assigned_name']): ?>
                                        <div class="meta-item">
                                            <span class="meta-icon">👤</span>
                                            <span class="meta-label">ผู้รับผิดชอบ:</span>
                                            <span class="meta-value"><?php echo htmlspecialchars($request['assigned_name']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="request-badges">
                                    <span class="badge badge-status-<?php echo $request['Re_status']; ?>">
                                        <?php echo getStatusIcon($request['Re_status']) . ' ' . getStatusText($request['Re_status']); ?>
                                    </span>
                                    <span class="badge badge-priority-<?php echo $request['Re_level']; ?>">
                                        <?php echo getPriorityIcon($request['Re_level']) . ' ' . getPriorityText($request['Re_level']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="request-description">
                                <?php echo nl2br(htmlspecialchars($request['Re_infor'])); ?>
                            </div>

                            <!-- Staff Response -->
                            <?php if ($request['staff_response']): ?>
                                <div class="staff-response">
                                    <h4>📝 การตอบกลับจากเจ้าหน้าที่</h4>
                                    <div class="staff-response-content">
                                        <?php echo nl2br(htmlspecialchars($request['staff_response'])); ?>
                                    </div>
                                    <div class="staff-response-meta">
                                        โดย: <?php echo htmlspecialchars($request['staff_name'] ?? 'เจ้าหน้าที่'); ?>
                                        <?php if ($request['staff_position']): ?>
                                            (<?php echo htmlspecialchars($request['staff_position']); ?>)
                                        <?php endif; ?>
                                        | วันที่: <?php echo formatThaiDateOnly($request['response_date']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- แก้ไข: Student Info Card - แสดงตามโหมดการระบุตัวตนและสิทธิ์ -->
                        <?php if ($request['Re_iden'] == 1): // โหมดไม่ระบุตัวตน 
                        ?>
                            <div class="request-info-card" style="border-left: 5px solid #ffc107;">
                                <h3>🔐 ข้อมูลผู้ส่งข้อร้องเรียน</h3>
                                <?php if ($isOwner): ?>
                                    <!-- เจ้าของข้อร้องเรียน - ไม่แสดงข้อมูลส่วนตัวแม้จะเป็นเจ้าของ -->
                                    <div class="anonymous-notice" style="background: #e8f5e8; border-color: #c3e6c3;">
                                        <span class="icon">🔐</span>
                                        <div class="content">
                                            <h4 style="color: #155724;">ไม่ระบุตัวตน (คุณเป็นเจ้าของ)</h4>
                                            <p style="color: #155724;">
                                                คุณส่งข้อร้องเรียนนี้ในโหมดไม่ระบุตัวตน ข้อมูลของคุณจะไม่ถูกเปิดเผยต่อผู้อื่น<br>
                                                และจะไม่แสดงข้อมูลส่วนตัวเพื่อปกป้องความเป็นส่วนตัว แต่คุณยังสามารถติดตามสถานะและจัดการข้อร้องเรียนได้ตามปกติ
                                            </p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- ผู้อื่น - ไม่แสดงข้อมูลส่วนตัว -->
                                    <div class="anonymous-notice">
                                        <span class="icon">🔐</span>
                                        <div class="content">
                                            <h4>ไม่ระบุตัวตน</h4>
                                            <p>
                                                ข้อร้องเรียนนี้ส่งมาในโหมดไม่ระบุตัวตน เพื่อปกป้องความเป็นส่วนตัวของผู้ส่ง<br>
                                                ข้อมูลส่วนบุคคลจะไม่ถูกเปิดเผย แต่ยังสามารถติดตามสถานะได้ตามปกติ
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: // โหมดระบุตัวตน - แสดงข้อมูลได้ทุกคน 
                        ?>                            
                        <?php endif; ?>

                        <!-- แก้ไข: Files Section - แยกแสดงไฟล์ตามผู้อัปโหลด -->
                        <?php if (!empty($files)): ?>
                            <!-- ส่วนที่ 1: ไฟล์หลักฐานจากนักศึกษา -->
                            <?php if (!empty($studentFiles)): ?>
                                <div class="files-section">
                                    <div class="files-header">
                                        <h3>📎 หลักฐานจากผู้ร้องเรียน (<?php echo count($studentFiles); ?> ไฟล์)</h3>
                                    </div>

                                    <div class="file-list">
                                        <?php foreach ($studentFiles as $file): ?>
                                            <div class="file-item">
                                                <div class="file-main">
                                                    <div class="file-icon" style="background: <?php echo isImageFile($file['Sup_filetype']) ? '#28a745' : '#667eea'; ?>">
                                                        <?php echo getFileIcon($file['Sup_filetype']); ?>
                                                    </div>
                                                    <div class="file-info">
                                                        <div class="file-name"><?php echo htmlspecialchars($file['Sup_filename']); ?></div>
                                                        <div class="file-meta">
                                                            ประเภท: <?php echo strtoupper($file['Sup_filetype']); ?> |
                                                            ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?> |
                                                            อัพโหลดเมื่อ: <?php echo formatThaiDateTime($file['Sup_upload_date']); ?>
                                                            <?php if ($file['uploader_name'] && shouldShowPersonalInfo($request, $user['Stu_id'])): ?>
                                                                | โดย: <?php echo htmlspecialchars($file['uploader_name']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="file-actions">
                                                        <?php
                                                        $downloadUrl = getFileDownloadUrl($file['Sup_filepath'], $file['Sup_filename']);
                                                        if ($downloadUrl):
                                                        ?>
                                                            <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn btn-primary" download>
                                                                📥 ดาวน์โหลด
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="btn btn-secondary disabled">
                                                                📥 ไฟล์ไม่พร้อมใช้งาน
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- แสดงรูปภาพ Preview สำหรับไฟล์รูปภาพ -->
                                                <?php if (isImageFile($file['Sup_filetype'])): ?>
                                                    <?php
                                                    $imageUrl = getImageDisplayUrl($file['Sup_filepath']);
                                                    if ($imageUrl):
                                                    ?>
                                                        <div class="image-preview">
                                                            <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                                                                alt="<?php echo htmlspecialchars($file['Sup_filename']); ?>"
                                                                onclick="openImageModal(this.src, '<?php echo htmlspecialchars($file['Sup_filename']); ?>')"
                                                                loading="lazy"
                                                                onerror="this.style.display='none';">
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- ส่วนที่ 2: ไฟล์หลักฐานจากเจ้าหน้าที่/อาจารย์ (แสดงเฉพาะเมื่อสถานะ >= 2) -->
                            <?php if (!empty($teacherFiles) && in_array($request['Re_status'], ['2', '3'])): ?>
                                <div class="files-section staff-files-section">
                                    <div class="files-header staff-files-header">
                                        <h3>✅ หลักฐานจากเจ้าหน้าที่ (<?php echo count($teacherFiles); ?> ไฟล์)</h3>
                                        <div class="staff-completed-badge">
                                            <span class="badge badge-success">🎉 เจ้าหน้าที่ดำเนินการเสร็จสิ้นแล้ว</span>
                                        </div>
                                    </div>

                                    <div class="file-list">
                                        <?php foreach ($teacherFiles as $file): ?>
                                            <div class="file-item">
                                                <div class="file-main">
                                                    <div class="file-icon" style="background: <?php echo isImageFile($file['Sup_filetype']) ? '#28a745' : '#667eea'; ?>">
                                                        <?php echo getFileIcon($file['Sup_filetype']); ?>
                                                    </div>
                                                    <div class="file-info">
                                                        <div class="file-name"><?php echo htmlspecialchars($file['Sup_filename']); ?></div>
                                                        <div class="file-meta">
                                                            ประเภท: <?php echo strtoupper($file['Sup_filetype']); ?> |
                                                            ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?> |
                                                            อัพโหลดเมื่อ: <?php echo formatThaiDateTime($file['Sup_upload_date']); ?>
                                                        </div>
                                                        <div class="file-uploader-info">
                                                            <span class="uploader-badge">
                                                                👨‍🏫 แนบโดย: <?php echo htmlspecialchars($file['teacher_uploader_name'] ?? 'เจ้าหน้าที่'); ?>
                                                                <?php if ($file['teacher_position']): ?>
                                                                    (<?php echo htmlspecialchars($file['teacher_position']); ?>)
                                                                <?php endif; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="file-actions">
                                                        <?php
                                                        $downloadUrl = getFileDownloadUrl($file['Sup_filepath'], $file['Sup_filename']);
                                                        if ($downloadUrl):
                                                        ?>
                                                            <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn btn-primary" download>
                                                                📥 ดาวน์โหลด
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="btn btn-secondary disabled">
                                                                📥 ไฟล์ไม่พร้อมใช้งาน
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <!-- แสดงรูปภาพ Preview สำหรับไฟล์รูปภาพ -->
                                                <?php if (isImageFile($file['Sup_filetype'])): ?>
                                                    <?php
                                                    $imageUrl = getImageDisplayUrl($file['Sup_filepath']);
                                                    if ($imageUrl):
                                                    ?>
                                                        <div class="image-preview">
                                                            <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                                                                alt="<?php echo htmlspecialchars($file['Sup_filename']); ?>"
                                                                onclick="openImageModal(this.src, '<?php echo htmlspecialchars($file['Sup_filename']); ?>')"
                                                                loading="lazy"
                                                                onerror="this.style.display='none';">
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Evaluation Display -->
                        <?php if ($evaluation): ?>
                            <div class="evaluation-display">
                                <h3>⭐ ผลการประเมินความพึงพอใจ</h3>
                                <div class="evaluation-score">
                                    <div class="score-value"><?php echo htmlspecialchars($evaluation['Eva_score']); ?>/5</div>
                                    <div class="score-label">
                                        <?php
                                        $scoreText = ['', 'ไม่พอใจ', 'น้อย', 'ปานกลาง', 'ดี', 'ดีที่สุด'];
                                        echo $scoreText[$evaluation['Eva_score']] ?? 'ไม่ระบุ';
                                        ?>
                                    </div>
                                </div>
                                <?php if ($evaluation['Eva_sug']): ?>
                                    <div class="evaluation-comment">
                                        <strong>ความเห็นเพิ่มเติม:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($evaluation['Eva_sug'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar -->
                    <div class="detail-sidebar">
                        <!-- Timeline -->
                        <div class="timeline-card">
                            <div class="timeline-header">
                                <h3>⏱️ ความคืบหน้า</h3>
                            </div>

                            <div class="timeline">
                                <?php foreach ($timeline as $item): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-icon <?php echo $item['status']; ?>">
                                            <?php echo $item['icon']; ?>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                            <div class="timeline-description"><?php echo htmlspecialchars($item['description']); ?></div>
                                            <?php if ($item['date']): ?>
                                                <div class="timeline-date"><?php echo formatThaiDateOnly($item['date']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <h3>🛠️ การกระทำ</h3>
                            <div class="actions-grid">
                                <?php if ($isOwner): ?>
                                    <?php if ($request['Re_status'] == '2'): ?>
                                        <a href="evaluation.php?id=<?php echo htmlspecialchars($request['Re_id']); ?>"
                                            class="btn btn-success">⭐ ประเมินบริการ</a>
                                    <?php endif; ?>

                                    <?php
                                    // ตรวจสอบเงื่อนไข: เป็นเจ้าของ และ สถานะต้องเป็น 0 (รอยืนยัน) เท่านั้น
                                    $isEditable = ($request['Re_status'] == '0');
                                    ?>

                                    <?php if ($isEditable): ?>
                                        <a href="complaint.php?edit=<?php echo $request['Re_id']; ?>" class="btn btn-warning">
                                            ✏️ แก้ไขข้อร้องเรียน
                                        </a>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled style="opacity: 0.6; cursor: not-allowed;">
                                            ✏️ แก้ไขข้อร้องเรียน (รับเรื่องแล้ว)
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($isEditable): ?>
                                        <form id="cancelForm" method="POST" style="margin: 0; width: 100%;">
                                            <input type="hidden" name="cancel_request" value="1">

                                            <button type="button" class="btn btn-danger" style="width: 100%;" onclick="confirmCancel()">
                                                🚫 ยกเลิกข้อร้องเรียน
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-secondary" disabled style="opacity: 0.6; cursor: not-allowed; width: 100%;">
                                            🚫 ยกเลิกข้อร้องเรียน (รับเรื่องแล้ว)
                                        </button>
                                    <?php endif; ?>

                                <?php else: ?>
                                    <!-- ไม่ใช่เจ้าของ - แสดงข้อความแจ้ง -->
                                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 10px; text-align: center;">
                                        <p style="margin: 0; color: #856404; font-size: 14px;">
                                            <strong>ℹ️ ข้อมูล</strong><br>
                                            คุณกำลังดูข้อร้องเรียนของผู้อื่น<br>
                                            สามารถดูรายละเอียดได้ แต่ไม่สามารถแก้ไขได้
                                        </p>
                                    </div>

                                    <span class="btn btn-secondary disabled">
                                        ✏️ ไม่สามารถแก้ไขได้
                                    </span>
                                    <span class="btn btn-secondary disabled">
                                        🚫 ไม่สามารถยกเลิกได้
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal" onclick="closeImageModal()">
        <span class="close-modal" onclick="closeImageModal()">&times;</span>
        <img id="modalImage" src="" alt="">
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <?php
    // โหลด JavaScript ตามสิทธิ์ผู้ใช้
    $currentRole = $_SESSION['user_role'] ?? '';
    if ($currentRole === 'teacher'): ?>
        <script src="../js/staff.js"></script>
    <?php endif; ?>

    <script>
        // 1. ฟังก์ชันแสดง Popup ยืนยันก่อนยกเลิก
        function confirmCancel() {
            Swal.fire({
                title: 'ยืนยันการยกเลิก?',
                text: "คุณต้องการยกเลิกข้อร้องเรียนนี้ใช่หรือไม่? เมื่อยกเลิกแล้วสถานะจะถูกเปลี่ยนและไม่สามารถแก้ไขได้อีก",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#dc3545',
                confirmButtonText: 'ใช่, ขอยกเลิก',
                cancelButtonText: 'ไม่, ย้อนกลับ',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // หากกดยืนยัน ให้ส่งค่า Form
                    document.getElementById('cancelForm').submit();
                }
            });
        }

        // 2. ตรวจสอบผลลัพธ์จาก PHP เพื่อแสดง Alert หลังจบการทำงาน
        document.addEventListener('DOMContentLoaded', function() {
            // กรณีเกิดข้อผิดพลาด ($error)
            <?php if ($error): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'เกิดข้อผิดพลาด',
                    text: '<?php echo str_replace("'", "\'", $error); ?>',
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#dc3545'
                });
            <?php endif; ?>

            // กรณีสำเร็จ ($success)
            <?php if ($success): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'สำเร็จ!',
                    text: '<?php echo str_replace("'", "\'", $success); ?>',
                    confirmButtonText: 'ตกลง',
                    confirmButtonColor: '#28a745'
                }).then(() => {
                    // รีโหลดหน้าเว็บเพื่ออัปเดตสถานะปุ่ม (หรือ Redirect ไป tracking ก็ได้)
                    window.location.href = window.location.href;
                });
            <?php endif; ?>
        });
    </script>

    <script>
        // Global variables to track notification state
        let currentUnreadCount = <?php echo $unreadCount; ?>;
        let notificationDropdownOpen = false;
        let notificationCheckInterval;

        // Initialize notifications on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadNotifications();
            startNotificationPolling();

            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                const notificationButton = document.getElementById('notificationButton');
                const dropdown = document.getElementById('notificationDropdown');

                if (!notificationButton.contains(e.target)) {
                    closeNotificationDropdown();
                }
            });

            // Initialize sidebar for desktop
            if (window.innerWidth >= 1024) {
                setTimeout(() => {
                    openSidebar();
                }, 500);
            }
        });

        // Update notification badge
        function updateNotificationBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                badge.textContent = count;
                if (count > 0) {
                    badge.classList.remove('zero');
                } else {
                    badge.classList.add('zero');
                }
            }
            currentUnreadCount = count;
        }

        // Toggle notification dropdown
        function toggleNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            if (notificationDropdownOpen) {
                closeNotificationDropdown();
            } else {
                openNotificationDropdown();
            }
        }

        // Open notification dropdown
        function openNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            dropdown.classList.add('show');
            button.classList.add('active');
            notificationDropdownOpen = true;

            // Load fresh notifications
            loadNotifications();
        }

        // Close notification dropdown
        function closeNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            dropdown.classList.remove('show');
            button.classList.remove('active');
            notificationDropdownOpen = false;
        }

        // Load notifications
        function loadNotifications() {
            fetch('?action=get_notifications')
                .then(response => response.json())
                .then(data => {
                    displayNotifications(data.notifications);
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    displayNotifications([]);
                });
        }

        // Display notifications in dropdown
        function displayNotifications(notifications) {
            const listContainer = document.getElementById('notificationList');

            if (!notifications || notifications.length === 0) {
                listContainer.innerHTML = `
                    <div class="no-notifications">
                        <div class="icon">🔔</div>
                        <p>ไม่มีการแจ้งเตือน</p>
                    </div>
                `;
                return;
            }

            let html = '';
            notifications.forEach(notification => {
                const isUnread = notification.Noti_status == 0;
                const time = formatRelativeTime(notification.Noti_date);

                html += `
                    <div class="notification-item ${isUnread ? 'unread' : ''}" 
                         onclick="handleNotificationClick(${notification.Noti_id}, ${notification.Re_id || 'null'})">
                        <div class="notification-title">${escapeHtml(notification.Noti_title)}</div>
                        <div class="notification-message">${escapeHtml(notification.Noti_message)}</div>
                        <div class="notification-time">${time}</div>
                    </div>
                `;
            });

            listContainer.innerHTML = html;
        }

        // Handle notification click
        function handleNotificationClick(notificationId, requestId) {
            // Mark as read
            markNotificationAsRead(notificationId);

            // Navigate to related request if available
            if (requestId) {
                window.location.href = `detail.php?id=${requestId}`;
            }
        }

        // Mark single notification as read
        function markNotificationAsRead(notificationId) {
            const formData = new FormData();
            formData.append('notification_id', notificationId);

            fetch('?action=mark_as_read', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update UI
                        updateUnreadCount();
                        loadNotifications();
                    } else {
                        showToast('เกิดข้อผิดพลาดในการอัพเดต', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error marking notification as read:', error);
                    showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                });
        }

        // Mark all notifications as read
        function markAllAsRead() {
            fetch('?action=mark_all_as_read', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateNotificationBadge(0);
                        loadNotifications();
                        showToast('อ่านการแจ้งเตือนทั้งหมดแล้ว', 'success');
                    } else {
                        showToast('เกิดข้อผิดพลาด: ' + (data.message || 'ไม่ทราบสาเหตุ'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error marking all as read:', error);
                    showToast('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                });
        }

        // Update unread count
        function updateUnreadCount() {
            fetch('?action=get_unread_count')
                .then(response => response.json())
                .then(data => {
                    if (data.unread_count !== currentUnreadCount) {
                        updateNotificationBadge(data.unread_count);

                        // Show notification sound/animation if count increased
                        if (data.unread_count > currentUnreadCount) {
                            showToast('คุณมีการแจ้งเตือนใหม่', 'info');

                            // Play notification sound if available
                            playNotificationSound();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error checking notifications:', error);
                });
        }

        // Start periodic notification checking
        function startNotificationPolling() {
            // Check every 15 seconds
            notificationCheckInterval = setInterval(updateUnreadCount, 15000);
        }

        // Stop notification polling
        function stopNotificationPolling() {
            if (notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
        }

        // Show toast message
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Play notification sound
        function playNotificationSound() {
            try {
                // Create audio context for notification sound
                const audioContext = new(window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);

                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
                gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);

                oscillator.start(audioContext.currentTime);
                oscillator.stop(audioContext.currentTime + 0.5);
            } catch (error) {
                // Ignore audio errors
                console.log('Audio notification not available');
            }
        }

        // Format relative time
        function formatRelativeTime(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);

            if (diffInSeconds < 60) {
                return 'เมื่อสักครู่';
            } else if (diffInSeconds < 3600) {
                const minutes = Math.floor(diffInSeconds / 60);
                return `${minutes} นาทีที่แล้ว`;
            } else if (diffInSeconds < 86400) {
                const hours = Math.floor(diffInSeconds / 3600);
                return `${hours} ชั่วโมงที่แล้ว`;
            } else {
                const days = Math.floor(diffInSeconds / 86400);
                return `${days} วันที่แล้ว`;
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggle = document.querySelector('.mobile-menu-toggle');

            const isOpen = sidebar.classList.contains('show');

            if (isOpen) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }

        function openSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggle = document.querySelector('.mobile-menu-toggle');

            sidebar.classList.add('show');
            toggle.classList.add('active');

            if (window.innerWidth >= 1024) {
                mainContent.classList.add('shifted');
                sidebar.classList.add('desktop-open');
                mainContent.classList.add('desktop-shifted');
            } else {
                overlay.classList.add('show');
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggle = document.querySelector('.mobile-menu-toggle');

            sidebar.classList.remove('show');
            overlay.classList.remove('show');
            toggle.classList.remove('active');
            mainContent.classList.remove('shifted');
            sidebar.classList.remove('desktop-open');
            mainContent.classList.remove('desktop-shifted');
        }

        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-menu-toggle');

            if (!sidebar.contains(e.target) &&
                !toggle.contains(e.target) &&
                sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });

        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');

            if (window.innerWidth >= 1024) {
                overlay.classList.remove('show');
                if (sidebar.classList.contains('show')) {
                    mainContent.classList.add('shifted');
                    sidebar.classList.add('desktop-open');
                    mainContent.classList.add('desktop-shifted');
                }
            } else {
                mainContent.classList.remove('shifted');
                sidebar.classList.remove('desktop-open');
                mainContent.classList.remove('desktop-shifted');
                if (sidebar.classList.contains('show')) {
                    overlay.classList.add('show');
                }
            }
        });

        // Image Modal Functions
        function openImageModal(imageSrc, fileName) {
            const modal = document.getElementById('imageModal');
            const modalImage = document.getElementById('modalImage');

            modalImage.src = imageSrc;
            modalImage.alt = fileName;
            modal.style.display = 'flex';

            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.display = 'none';

            // Restore body scroll
            document.body.style.overflow = 'auto';
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });

        function printRequest() {
            const printContent = document.querySelector('.detail-main').innerHTML;
            const printWindow = window.open('', '_blank');

            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>รายละเอียดข้อร้องเรียน #CR<?php echo str_pad($request['Re_id'] ?? '', 6, '0', STR_PAD_LEFT); ?></title>
                    <style>
                        body { font-family: 'Sarabun', Arial, sans-serif; margin: 20px; line-height: 1.6; }
                        .request-detail-card, .files-section, .evaluation-display { 
                            margin-bottom: 20px; 
                            padding: 20px; 
                            border: 1px solid #ddd; 
                            border-radius: 8px; 
                        }
                        .request-badges .badge { 
                            display: inline-block; 
                            padding: 4px 8px; 
                            margin-right: 5px; 
                            border-radius: 4px; 
                            font-size: 12px;
                        }
                        .file-item { 
                            border: 1px solid #eee; 
                            padding: 10px; 
                            margin: 5px 0; 
                            border-radius: 4px; 
                        }
                        .image-preview img {
                            max-width: 200px; 
                            height: auto; 
                            border: 1px solid #ddd; 
                            border-radius: 4px;
                        }
                        @media print {
                            .file-actions { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <h1>รายละเอียดข้อร้องเรียน</h1>
                    <p>พิมพ์เมื่อ: ${new Date().toLocaleString('th-TH')}</p>
                    <hr>
                    ${printContent}
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.print();
        }

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            stopNotificationPolling();
        });

        // Handle visibility change (pause polling when tab not active)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopNotificationPolling();
            } else {
                startNotificationPolling();
                updateUnreadCount(); // Check immediately when tab becomes active
            }
        });
    </script>
</body>

</html>