<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบสิทธิ์
requireRole('student');

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

// ตรวจสอบ ID ของข้อร้องเรียน
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: tracking.php');
    exit;
}

$requestId = (int)$_GET['id'];

// ดึงข้อมูลข้อร้องเรียนสำหรับแก้ไข - อัพเดตให้ใช้โครงสร้างใหม่
$request = $db->fetch("
    SELECT r.*, t.Type_infor, t.Type_icon
    FROM request r 
    LEFT JOIN type t ON r.Type_id = t.Type_id 
    WHERE r.Re_id = ? AND r.Stu_id = ?
", [$requestId, $user['Stu_id']]);

if (!$request) {
    $_SESSION['error'] = 'ไม่สามารถแก้ไขข้อร้องเรียนนี้ได้ หรือไม่มีสิทธิ์เข้าถึง';
    header('Location: tracking.php');
    exit;
}

// ตรวจสอบสิทธิ์การแก้ไข
$canEditResult = canEditRequest($requestId, $user['Stu_id']);
if (!$canEditResult['allowed']) {
    $_SESSION['error'] = $canEditResult['reason'];
    header('Location: tracking.php');
    exit;
}

// ดึงไฟล์แนบที่มีอยู่ - อัพเดตให้ใช้โครงสร้างใหม่
$existingFiles = $db->fetchAll("
    SELECT * FROM supporting_evidence 
    WHERE Re_id = ? 
    ORDER BY Sup_upload_date DESC
", [$requestId]);

// ฟังก์ชันตรวจสอบประเภทไฟล์จากนามสกุล
function getFileTypeInfo($fileName, $filePath = null)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // ตรวจสอบว่าไฟล์เป็นรูปภาพหรือไม่
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $isImage = in_array($extension, $imageExtensions);

    if ($isImage) {
        return [
            'type' => 'image',
            'icon' => '🖼️',
            'badge' => 'รูปภาพ',
            'class' => 'image',
            'is_image' => true
        ];
    }

    // กำหนดประเภทไฟล์อื่นๆ
    switch ($extension) {
        case 'pdf':
            return [
                'type' => 'pdf',
                'icon' => '📕',
                'badge' => 'PDF',
                'class' => 'pdf',
                'is_image' => false
            ];
        case 'doc':
        case 'docx':
            return [
                'type' => 'document',
                'icon' => '📘',
                'badge' => 'Word',
                'class' => 'document',
                'is_image' => false
            ];
        case 'xls':
        case 'xlsx':
            return [
                'type' => 'document',
                'icon' => '📗',
                'badge' => 'Excel',
                'class' => 'document',
                'is_image' => false
            ];
        default:
            return [
                'type' => 'unknown',
                'icon' => '📄',
                'badge' => 'ไฟล์',
                'class' => 'document',
                'is_image' => false
            ];
    }
}

// ประมวลผลการแก้ไข
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_complaint'])) {
        // รับข้อมูลจากฟอร์ม
        $title = sanitizeInput($_POST['title']);
        $information = sanitizeInput($_POST['description']);
        $typeId = sanitizeInput($_POST['category']);
        $location = sanitizeInput($_POST['location']);
        $isAnonymous = isset($_POST['anonymous']) ? 1 : 0;

        // ตรวจสอบข้อมูลจำเป็น
        if (empty($information) || empty($typeId)) {
            $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        } else {
            try {
                $db->beginTransaction();

                // อัพเดทข้อมูลข้อร้องเรียน - ใช้โครงสร้างใหม่
                $updateData = [
                    'Re_title' => $title,
                    'Re_infor' => $information,
                    'Type_id' => $typeId,
                    'Re_iden' => $isAnonymous
                ];

                $result = $db->update('request', $updateData, 'Re_id = ? AND Stu_id = ?', [$requestId, $user['Stu_id']]);

                if ($result) {
                    // จัดการไฟล์แนบใหม่ (ถ้ามี)
                    if (!empty($_FILES['attachments']['name'][0])) {
                        $uploadDir = '../uploads/evidence/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }

                        $allowedTypes = ALLOWED_FILE_TYPES;
                        $maxFileSize = MAX_FILE_SIZE;

                        foreach ($_FILES['attachments']['name'] as $index => $fileName) {
                            if (!empty($fileName)) {
                                $fileSize = $_FILES['attachments']['size'][$index];
                                $fileTmpName = $_FILES['attachments']['tmp_name'][$index];
                                $fileError = $_FILES['attachments']['error'][$index];

                                // ตรวจสอบข้อผิดพลาด
                                if ($fileError !== UPLOAD_ERR_OK) {
                                    continue;
                                }

                                // ตรวจสอบขนาดไฟล์
                                if ($fileSize > $maxFileSize) {
                                    continue;
                                }

                                // ตรวจสอบประเภทไฟล์
                                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                                if (!in_array($fileExtension, $allowedTypes)) {
                                    continue;
                                }

                                // สร้างชื่อไฟล์ใหม่
                                $newFileName = $requestId . '_' . time() . '_' . $index . '.' . $fileExtension;
                                $uploadPath = $uploadDir . $newFileName;

                                // อัปโหลดไฟล์
                                if (move_uploaded_file($fileTmpName, $uploadPath)) {
                                    // บันทึกในฐานข้อมูล - ใช้โครงสร้างใหม่
                                    $evidenceData = [
                                        'Sup_filename' => $fileName,
                                        'Sup_filepath' => $uploadPath,
                                        'Sup_filetype' => $fileExtension,
                                        'Sup_filesize' => $fileSize,
                                        'Sup_upload_by' => $user['Stu_id'],
                                        'Re_id' => $requestId
                                    ];
                                    $db->insert('supporting_evidence', $evidenceData);
                                }
                            }
                        }
                    }

                    $db->commit();
                    $success = 'แก้ไขข้อร้องเรียนเรียบร้อยแล้ว';

                    // รีเฟรชข้อมูล
                    $request = $db->fetch("
                        SELECT r.*, t.Type_infor, t.Type_icon
                        FROM request r 
                        LEFT JOIN type t ON r.Type_id = t.Type_id 
                        WHERE r.Re_id = ? AND r.Stu_id = ?
                    ", [$requestId, $user['Stu_id']]);

                    $existingFiles = $db->fetchAll("
                        SELECT * FROM supporting_evidence 
                        WHERE Re_id = ? 
                        ORDER BY Sup_upload_date DESC
                    ", [$requestId]);
                } else {
                    throw new Exception('ไม่สามารถอัพเดทข้อมูลได้');
                }
            } catch (Exception $e) {
                $db->rollback();
                $error = 'เกิดข้อผิดพลาดในการแก้ไข: ' . $e->getMessage();
            }
        }
    }
}

// ดึงข้อมูลประเภทข้อร้องเรียน
$complaintTypes = $db->fetchAll("SELECT * FROM type ORDER BY Type_infor");

// จัดการการลบไฟล์ผ่าน AJAX
if (isset($_POST['delete_file']) && isset($_POST['file_id'])) {
    $fileId = (int)$_POST['file_id'];

    try {
        // ดึงข้อมูลไฟล์ก่อนลบ
        $fileData = $db->fetch("
            SELECT * FROM supporting_evidence 
            WHERE Sup_id = ? AND Re_id = ? AND Sup_upload_by = ?
        ", [$fileId, $requestId, $user['Stu_id']]);

        if ($fileData) {
            // ลบไฟล์จากเซิร์ฟเวอร์
            if (file_exists($fileData['Sup_filepath'])) {
                unlink($fileData['Sup_filepath']);
            }

            // ลบจากฐานข้อมูล
            $result = $db->delete('supporting_evidence', 'Sup_id = ?', [$fileId]);

            header('Content-Type: application/json');
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'ลบไฟล์สำเร็จ']);
            } else {
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถลบไฟล์ได้']);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'ไม่พบไฟล์หรือไม่มีสิทธิ์ลบ']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
    }
    exit;
}

// ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// สร้าง complaint ID สำหรับแสดง
$complaintId = 'CR' . str_pad($request['Re_id'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แก้ไขข้อร้องเรียน - <?php echo SITE_NAME; ?></title>
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
            color: #333;
            font-size: 1.2rem;
            margin: 0;
        }

        .header-title p {
            color: #666;
            font-size: 0.85rem;
            margin: 0;
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
            min-width: 200px;
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
            max-width: 1000px;
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

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
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

        .alert-warning {
            background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
            color: #856404;
        }

        /* Edit Status Card */
        .edit-status-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #28a745;
        }

        .edit-status-card h3 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .edit-status-card p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .complaint-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }

        .info-value {
            color: #333;
            font-size: 0.9rem;
        }

        /* Form Styles */
        .complaint-form {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .required {
            color: #dc3545;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-help {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: #666;
        }

        /* Anonymous Section */
        .anonymous-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .anonymous-toggle {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 15px;
        }

        .toggle-switch {
            position: relative;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked+.toggle-slider {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        input:checked+.toggle-slider:before {
            transform: translateX(26px);
        }

        .toggle-info h3 {
            color: #333;
            margin-bottom: 8px;
        }

        .toggle-info p {
            color: #666;
            margin: 0;
            line-height: 1.5;
        }

        /* Existing Files Section */
        .existing-files {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .existing-files h3 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
            margin-bottom: 15px;
        }

        .file-item-with-image {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
            margin-bottom: 15px;
        }

        .file-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .existing-image-preview {
            max-width: 120px;
            max-height: 120px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e1e5e9;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .file-info {
            flex: 1;
        }

        .file-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .file-details {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 8px;
        }

        .file-type-badge {
            display: inline-block;
            background: #e3f2fd;
            color: #1976d2;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .file-type-badge.image {
            background: #e8f5e8;
            color: #2e7d32;
        }

        .file-type-badge.pdf {
            background: #ffebee;
            color: #c62828;
        }

        .file-type-badge.document {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .file-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        /* File Input */
        .file-input {
            border: 2px dashed #ddd;
            background: #f8f9fa;
            cursor: pointer;
        }

        .file-input:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }

        .file-preview {
            margin-top: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .file-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .image-preview {
            max-width: 120px;
            max-height: 120px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e1e5e9;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* Date Display */
        .date-display {
            background: #f8f9fa;
            padding: 12px 15px;
            border-radius: 8px;
            border: 2px solid #e1e5e9;
            font-size: 14px;
            color: #495057;
            font-weight: 500;
        }

        .date-icon {
            margin-right: 8px;
            color: #667eea;
        }

        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
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

        /* Notification */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 1001;
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 250px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
        }

        .notification.error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

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

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .complaint-info {
                grid-template-columns: 1fr;
            }

            .file-item-with-image {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .existing-image-preview {
                max-width: 150px;
                max-height: 150px;
                margin-bottom: 10px;
            }

            .file-info {
                width: 100%;
                text-align: center;
            }

            .file-actions {
                margin-top: 10px;
                justify-content: center;
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
                <h1>✏️ แก้ไขข้อร้องเรียน</h1>
                <p>แก้ไขข้อมูลข้อร้องเรียน #<?php echo htmlspecialchars($complaintId); ?></p>
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>✏️ แก้ไขข้อร้องเรียน</h1>
                    <p>แก้ไขข้อมูลข้อร้องเรียน #<?php echo htmlspecialchars($complaintId); ?></p>
                </div>
                <div>
                    <a href="tracking.php" class="btn btn-secondary">← กลับไปติดตาม</a>
                    <a href="detail.php?id=<?php echo $request['Re_id']; ?>" class="btn btn-outline">👁️ ดูรายละเอียด</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="icon">❌</span>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span class="icon">✅</span>
                    <?php echo htmlspecialchars($success); ?>
                    <div style="margin-top: 10px;">
                        <a href="tracking.php" class="btn btn-sm btn-primary">ดูสถานะ</a>
                        <a href="detail.php?id=<?php echo $request['Re_id']; ?>" class="btn btn-sm btn-secondary">ดูรายละเอียด</a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Edit Status Card -->
            <div class="edit-status-card">
                <h3>
                    <span>ℹ️</span>
                    สถานะการแก้ไข
                </h3>
                <p>
                    <strong>คุณสามารถแก้ไขข้อร้องเรียนนี้ได้</strong> เนื่องจากยังอยู่ในสถานะรอดำเนินการและส่งในวันเดียวกัน
                    หากเจ้าหน้าที่เริ่มดำเนินการหรือผ่านไปอีกวันแล้ว จะไม่สามารถแก้ไขได้อีก
                </p>

                <div class="complaint-info">
                    <div class="info-item">
                        <div class="info-label">รหัสข้อร้องเรียน</div>
                        <div class="info-value">#<?php echo htmlspecialchars($complaintId); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">สถานะ</div>
                        <div class="info-value">
                            <?php echo getStatusText($request['Re_status']); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">วันที่ส่ง</div>
                        <div class="info-value"><?php echo formatThaiDateOnly($request['Re_date']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">ระดับความสำคัญ</div>
                        <div class="info-value">
                            <span style="color: <?php echo getPriorityColor($request['Re_level'], $request['Re_status']); ?>">
                                <?php echo getPriorityDisplayText($request['Re_level'], $request['Re_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Anonymous Mode Section -->
            <div class="anonymous-section">
                <div class="anonymous-toggle">
                    <label class="toggle-switch">
                        <input type="checkbox" id="anonymousMode" name="anonymous"
                            <?php echo $request['Re_iden'] ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-info">
                        <h3>🔒 การร้องเรียนแบบไม่ระบุตัวตน</h3>
                        <p>เมื่อเปิดใช้งาน เจ้าหน้าที่จะไม่สามารถเห็นข้อมูลผู้ร้องเรียนได้ แต่คุณยังสามารถติดตามสถานะได้ตามปกติ</p>
                    </div>
                </div>
            </div>

            <!-- Existing Files Section -->
            <?php if (!empty($existingFiles)): ?>
                <div class="existing-files">
                    <h3>📎 ไฟล์แนบปัจจุบัน</h3>
                    <div class="file-list">
                        <?php foreach ($existingFiles as $file):
                            $fileInfo = getFileTypeInfo($file['Sup_filename'], $file['Sup_filepath']);
                            $isImage = $fileInfo['is_image'];
                        ?>
                            <div class="<?php echo $isImage ? 'file-item-with-image' : 'file-item'; ?>" id="file-<?php echo $file['Sup_id']; ?>">
                                <?php if ($isImage && file_exists($file['Sup_filepath'])): ?>
                                    <img src="show_file.php?id=<?php echo $file['Sup_id']; ?>"
                                        alt="ตัวอย่างรูปภาพ"
                                        class="existing-image-preview"
                                        loading="lazy">
                                <?php else: ?>
                                    <div class="file-icon"><?php echo $fileInfo['icon']; ?></div>
                                <?php endif; ?>

                                <div class="file-info">
                                    <div class="file-name"><?php echo htmlspecialchars($file['Sup_filename']); ?></div>
                                    <div class="file-details">
                                        ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?> |
                                        อัปโหลด: <?php echo formatThaiDateTime($file['Sup_upload_date']); ?>
                                    </div>
                                    <span class="file-type-badge <?php echo $fileInfo['class']; ?>">
                                        <?php echo $fileInfo['badge']; ?>
                                    </span>
                                </div>
                                <div class="file-actions">
                                    <?php if ($isImage && file_exists($file['Sup_filepath'])): ?>
                                        <button onclick="viewFullImage('show_file.php?id=<?php echo $file['Sup_id']; ?>')" class="btn btn-sm btn-primary">
                                            👁️ ดูเต็ม
                                        </button>
                                    <?php else: ?>
                                        <a href="show_file.php?id=<?php echo $file['Sup_id']; ?>&download=1" class="btn btn-sm btn-primary">
                                            📥 ดาวน์โหลด
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="deleteFile(<?php echo $file['Sup_id']; ?>)" class="btn btn-sm btn-danger">
                                        🗑️ ลบ
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <form method="POST" enctype="multipart/form-data" class="complaint-form">
                <div class="form-section">
                    <h3>📋 ข้อมูลข้อร้องเรียน</h3>

                    <div class="form-group">
                        <label for="title">หัวข้อข้อร้องเรียน</label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            placeholder="หัวข้อสั้นๆ อธิบายปัญหา"
                            value="<?php echo htmlspecialchars($request['Re_title'] ?? ''); ?>"
                            maxlength="100">
                        <small class="form-help">หัวข้อสั้นๆ ที่สรุปปัญหาหลัก (ไม่บังคับ)</small>
                    </div>

                    <div class="form-group">
                        <label for="category">ประเภทข้อร้องเรียน <span class="required">*</span></label>
                        <select id="category" name="category" required>
                            <option value="">เลือกประเภท</option>
                            <?php foreach ($complaintTypes as $type): ?>
                                <option value="<?php echo $type['Type_id']; ?>"
                                    <?php echo ($request['Type_id'] == $type['Type_id']) ? 'selected' : ''; ?>>
                                    <?php echo $type['Type_icon']; ?> <?php echo htmlspecialchars($type['Type_infor']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="description">รายละเอียดข้อร้องเรียน <span class="required">*</span></label>
                        <textarea
                            id="description"
                            name="description"
                            placeholder="กرุณาอธิบายรายละเอียดของปัญหาที่พบ ให้ครบถ้วนเพื่อให้เจ้าหน้าที่สามารถดำเนินการได้อย่างมีประสิทธิภาพ"
                            rows="6"
                            required><?php echo htmlspecialchars($request['Re_infor']); ?></textarea>
                        <small class="form-help">อธิบายปัญหาให้ละเอียดที่สุด เพื่อให้เจ้าหน้าที่เข้าใจและดำเนินการได้ถูกต้อง</small>
                    </div>
                </div>

                <div class="form-section">
                    <h3>📍 ข้อมูลเพิ่มเติม</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="location">สถานที่เกิดเหตุ</label>
                            <input
                                type="text"
                                id="location"
                                name="location"
                                placeholder="เช่น อาคาร 1 ชั้น 2 ห้อง 201"
                                value=""
                                maxlength="255">
                        </div>
                        <div class="form-group">
                            <label for="incident_date">วันที่เกิดเหตุ</label>
                            <div class="date-display">
                                <span class="date-icon">📅</span>
                                <?php echo formatThaiDateOnly($request['Re_date']); ?>
                            </div>
                            <small class="form-help">วันที่เกิดเหตุจะไม่เปลี่ยนแปลง</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>📎 เพิ่มไฟล์หลักฐาน</h3>

                    <div class="form-group">
                        <label for="attachments">ไฟล์แนบเพิ่มเติม (ถ้ามี)</label>
                        <input
                            type="file"
                            id="attachments"
                            name="attachments[]"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                            class="file-input">

                        <small class="form-help">
                            รองรับไฟล์: รูปภาพ (JPG, PNG), PDF, Word (DOC, DOCX), Excel (XLS, XLSX)<br>
                            ขนาดไฟล์สูงสุด: <?php echo round(MAX_FILE_SIZE / 1048576, 1); ?> MB ต่อไฟล์<br>
                            สามารถแนบได้หลายไฟล์
                        </small>
                    </div>

                    <div class="file-preview" id="filePreview" style="display: none;">
                        <h4>ไฟล์ใหม่ที่เลือก:</h4>
                        <div class="file-list" id="fileList"></div>
                    </div>

                </div>

                <div class="form-actions">
                    <button type="submit" name="update_complaint" class="btn btn-primary">
                        ✅ บันทึกการแก้ไข
                    </button>
                    <a href="tracking.php" class="btn btn-secondary">
                        ❌ ยกเลิก
                    </a>
                </div>
            </form>
        </div>
    </main>

    <!-- Modal สำหรับดูรูปภาพเต็ม -->
    <div id="imageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 10000; cursor: pointer;" onclick="closeImageModal()">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 90%; max-height: 90%;">
            <img id="fullImage" src="" alt="รูปภาพเต็ม" style="max-width: 100%; max-height: 100%; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.5);">
        </div>
        <div style="position: absolute; top: 20px; right: 30px; color: white; font-size: 30px; cursor: pointer;" onclick="closeImageModal()">×</div>
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

        // จัดการการแสดงไฟล์ใหม่ที่เลือก
        document.getElementById('attachments').addEventListener('change', function(e) {
            const files = e.target.files;
            const preview = document.getElementById('filePreview');
            const fileList = document.getElementById('fileList');

            if (files.length > 0) {
                preview.style.display = 'block';
                fileList.innerHTML = '';

                Array.from(files).forEach((file, index) => {
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExtension);

                    // กำหนดไอคอนและประเภทไฟล์
                    let fileIcon = '📄';
                    let fileTypeBadge = 'document';
                    let badgeText = 'เอกสาร';

                    if (isImage) {
                        fileIcon = '🖼️';
                        fileTypeBadge = 'image';
                        badgeText = 'รูปภาพ';
                    } else if (['pdf'].includes(fileExtension)) {
                        fileIcon = '📕';
                        fileTypeBadge = 'pdf';
                        badgeText = 'PDF';
                    } else if (['doc', 'docx'].includes(fileExtension)) {
                        fileIcon = '📘';
                        fileTypeBadge = 'document';
                        badgeText = 'Word';
                    } else if (['xls', 'xlsx'].includes(fileExtension)) {
                        fileIcon = '📗';
                        fileTypeBadge = 'document';
                        badgeText = 'Excel';
                    }

                    const fileItem = document.createElement('div');
                    fileItem.className = isImage ? 'file-item-with-image' : 'file-item';

                    if (isImage) {
                        // สร้างตัวอย่างรูปภาพ
                        const img = document.createElement('img');
                        img.className = 'image-preview';
                        img.src = URL.createObjectURL(file);
                        img.alt = 'ตัวอย่างรูปภาพ';
                        img.onload = function() {
                            URL.revokeObjectURL(this.src); // ล้าง object URL เมื่อโหลดเสร็จ
                        };

                        fileItem.innerHTML = `
                            <div class="file-info">
                                <div class="file-name">${file.name}</div>
                                <div class="file-details">ขนาด: ${formatFileSize(file.size)}</div>
                                <span class="file-type-badge ${fileTypeBadge}">${badgeText}</span>
                            </div>
                            <div class="file-actions">
                                <button type="button" onclick="removeNewFile(${index})" class="btn btn-sm btn-danger">🗑️ ลบ</button>
                            </div>
                        `;

                        // เพิ่มรูปภาพก่อนข้อมูลไฟล์
                        fileItem.insertBefore(img, fileItem.firstChild);
                    } else {
                        // ไฟล์ที่ไม่ใช่รูปภาพ
                        fileItem.innerHTML = `
                            <span class="file-icon">${fileIcon}</span>
                            <div class="file-info">
                                <div class="file-name">${file.name}</div>
                                <div class="file-details">ขนาด: ${formatFileSize(file.size)}</div>
                                <span class="file-type-badge ${fileTypeBadge}">${badgeText}</span>
                            </div>
                            <div class="file-actions">
                                <button type="button" onclick="removeNewFile(${index})" class="btn btn-sm btn-danger">🗑️ ลบ</button>
                            </div>
                        `;
                    }

                    fileList.appendChild(fileItem);
                });
            } else {
                preview.style.display = 'none';
            }
        });

        // ฟังก์ชันลบไฟล์ใหม่
        function removeNewFile(index) {
            const input = document.getElementById('attachments');
            const dt = new DataTransfer();
            const files = input.files;

            for (let i = 0; i < files.length; i++) {
                if (i !== index) {
                    dt.items.add(files[i]);
                }
            }

            input.files = dt.files;
            input.dispatchEvent(new Event('change'));
        }

        // ฟังก์ชันลบไฟล์เดิม
        function deleteFile(fileId) {
            if (confirm('คุณต้องการลบไฟล์นี้หรือไม่?')) {
                fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `delete_file=1&file_id=${fileId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const fileElement = document.getElementById(`file-${fileId}`);
                            if (fileElement) {
                                fileElement.remove();
                            }
                            showNotification('ลบไฟล์สำเร็จ', 'success');
                        } else {
                            showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showNotification('เกิดข้อผิดพลาดในการลบไฟล์', 'error');
                    });
            }
        }

        // ฟังก์ชันดูรูปภาพเต็ม
        function viewFullImage(imageSrc) {
            const modal = document.getElementById('imageModal');
            const fullImage = document.getElementById('fullImage');

            fullImage.src = imageSrc;
            modal.style.display = 'block';
            document.body.style.overflow = 'hidden'; // ป้องกันการเลื่อนหน้า
        }

        // ฟังก์ชันปิด modal รูปภาพ
        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto'; // คืนค่าการเลื่อนหน้า
        }

        // ปิด modal เมื่อกด ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });

        // ฟังก์ชันจัดรูปแบบขนาดไฟล์
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // ฟังก์ชันแสดงการแจ้งเตือน
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <span class="notification-icon">${type === 'success' ? '✅' : '❌'}</span>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // ตรวจสอบฟอร์มก่อนส่ง
        document.querySelector('.complaint-form').addEventListener('submit', function(e) {
            const description = document.getElementById('description').value.trim();
            const category = document.getElementById('category').value;

            if (!description || !category) {
                e.preventDefault();
                showNotification('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน', 'error');
                return false;
            }

            if (confirm('คุณต้องการบันทึกการแก้ไขหรือไม่?')) {
                return true;
            } else {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>

</html>