<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอิน
requireRole('student', 'login.php');

$user = getCurrentUser();
$db = getDB();

$error = '';
$success = '';
$editMode = false;
$editRequest = null;

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

// ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// ฟังก์ชันสร้างโครงสร้างโฟลเดอร์
function ensureUploadDirectories()
{
    $directories = [
        '../uploads',
        '../uploads/requests',
        '../uploads/requests/images',
        '../uploads/requests/documents',
        '../uploads/requests/thumbs',
        '../uploads/temp'
    ];

    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: $dir");
                return false;
            }
        }
    }
    return true;
}

// สร้างโครงสร้างโฟลเดอร์
ensureUploadDirectories();

// [เพิ่มใหม่] ฟังก์ชันตรวจสอบว่าเป็นรูปภาพหรือไม่
function isImageFile($fileExtension)
{
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    return in_array(strtolower($fileExtension), $imageTypes);
}

// [เพิ่มใหม่] ฟังก์ชันหา URL รูปภาพ (รองรับทั้ง path เก่าและใหม่)
function getImageDisplayUrl($filePath)
{
    $possiblePaths = [
        $filePath, // path เดิมจาก DB
        '../uploads/requests/images/' . basename($filePath), // path ใหม่
        '../uploads/requests/' . basename($filePath), // path เก่า
        '../uploads/' . basename($filePath)
    ];

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            // แปลงเป็น URL สำหรับแสดงผล
            if (strpos($path, '../uploads/') === 0) {
                return $path;
            }
            return '../uploads/requests/images/' . basename($path);
        }
    }
    return null;
}

// ตรวจสอบว่าเป็นการแก้ไขหรือไม่
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editRequestId = (int)$_GET['edit'];

    // ตรวจสอบเฉพาะว่าเป็นเจ้าของและสถานะยังเป็น 0 (ไม่จำกัดวันที่)
    $editRequest = null;
    try {
        $checkRequest = $db->fetch(
            "SELECT * FROM request WHERE Re_id = ? AND Stu_id = ?",
            [$editRequestId, $user['Stu_id']]
        );
        if ($checkRequest && $checkRequest['Re_status'] === '0') {
            $editRequest = $checkRequest;
            $editMode = true;
        } elseif ($checkRequest) {
            $error = 'ไม่สามารถแก้ไขได้เนื่องจากข้อร้องเรียนได้รับการดำเนินการแล้ว';
        } else {
            $error = 'ไม่สามารถแก้ไขข้อร้องเรียนนี้ได้ หรือไม่มีสิทธิ์เข้าถึง';
        }
    } catch (Exception $e) {
        $error = 'เกิดข้อผิดพลาดในการตรวจสอบสิทธิ์';
    }
}

// ตรวจสอบ GD Extension
function isGDEnabled()
{
    return extension_loaded('gd') && function_exists('imagecreatetruecolor');
}

// ฟังก์ชันจัดการการอัพโหลดไฟล์
function handleFileUploads($requestId, $studentId)
{
    if (empty($_FILES['attachments']['name'][0])) {
        return ['success' => true, 'files' => []];
    }

    $uploadedFiles = [];
    $imageCount = 0;
    $documentCount = 0;
    $totalFiles = 0;

    // นับไฟล์ที่มีจริง
    foreach ($_FILES['attachments']['name'] as $fileName) {
        if (!empty($fileName)) {
            $totalFiles++;
        }
    }

    // ตรวจสอบจำนวนไฟล์รวม
    if ($totalFiles > 8) {
        return ['success' => false, 'message' => 'ไม่สามารถแนบไฟล์เกิน 8 ไฟล์ได้ กรุณาลดจำนวนไฟล์หรือรวมไฟล์เป็น PDF'];
    }

    $allowedTypes = ALLOWED_FILE_TYPES;
    $maxFileSize = MAX_FILE_SIZE;
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif'];
    $documentTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

    foreach ($_FILES['attachments']['name'] as $index => $fileName) {
        if (empty($fileName)) continue;

        $fileSize = $_FILES['attachments']['size'][$index];
        $fileTmpName = $_FILES['attachments']['tmp_name'][$index];
        $fileError = $_FILES['attachments']['error'][$index];

        // ตรวจสอบข้อผิดพลาดการอัพโหลด
        if ($fileError !== UPLOAD_ERR_OK) {
            continue;
        }

        // ตรวจสอบขนาดไฟล์
        if ($fileSize > $maxFileSize) {
            return ['success' => false, 'message' => "ไฟล์ {$fileName} มีขนาดเกิน " . formatFileSize($maxFileSize)];
        }

        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // ตรวจสอบประเภทไฟล์
        if (!in_array($fileExtension, $allowedTypes)) {
            return ['success' => false, 'message' => "ไฟล์ {$fileName} ประเภทไฟล์ไม่รองรับ"];
        }

        // นับประเภทไฟล์
        if (in_array($fileExtension, $imageTypes)) {
            $imageCount++;
            $fileCategory = 'images';
        } elseif (in_array($fileExtension, $documentTypes)) {
            $documentCount++;
            $fileCategory = 'documents';
        } else {
            $fileCategory = 'documents';
        }

        // ตรวจสอบจำนวนไฟล์แต่ละประเภท
        if ($imageCount > 5) {
            return ['success' => false, 'message' => 'สามารถแนบรูปภาพได้สูงสุด 5 ไฟล์'];
        }

        if ($documentCount > 3) {
            return ['success' => false, 'message' => 'สามารถแนบเอกสารได้สูงสุด 3 ไฟล์'];
        }

        // สร้างชื่อไฟล์ใหม่
        $originalName = pathinfo($fileName, PATHINFO_FILENAME);
        $sanitizedName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $originalName);
        $newFileName = $requestId . '_' . time() . '_' . uniqid() . '.' . $fileExtension;

        // กำหนดโฟลเดอร์ปลายทาง
        $uploadDir = "../uploads/requests/{$fileCategory}/";
        $uploadPath = $uploadDir . $newFileName;

        // อัพโหลดไฟล์
        if (move_uploaded_file($fileTmpName, $uploadPath)) {
            // สร้าง thumbnail สำหรับรูปภาพ (ถ้าระบบรองรับ)
            if (in_array($fileExtension, $imageTypes)) {
                $thumbPath = "../uploads/requests/thumbs/thumb_" . $newFileName;

                if (isGDEnabled()) {
                    try {
                        createImageThumbnail($uploadPath, $thumbPath, 300, 300);
                    } catch (Exception $e) {
                        error_log("Thumbnail creation failed: " . $e->getMessage());
                    }
                }
            }

            // บันทึกข้อมูลลงฐานข้อมูล
            $evidenceId = saveSupportingEvidence(
                $requestId,
                $fileName, // ชื่อไฟล์เดิม
                $uploadPath, // path ที่เก็บจริง
                $fileExtension,
                $fileSize,
                $studentId
            );

            if ($evidenceId) {
                $uploadedFiles[] = [
                    'id' => $evidenceId,
                    'original_name' => $fileName,
                    'new_name' => $newFileName,
                    'path' => $uploadPath,
                    'size' => $fileSize,
                    'type' => $fileExtension,
                    'category' => $fileCategory
                ];
            } else {
                // ลบไฟล์หากบันทึกฐานข้อมูลไม่สำเร็จ
                unlink($uploadPath);
                if (isset($thumbPath) && file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
                return ['success' => false, 'message' => "ไม่สามารถบันทึกข้อมูลไฟล์ {$fileName} ได้"];
            }
        } else {
            return ['success' => false, 'message' => "ไม่สามารถอัพโหลดไฟล์ {$fileName} ได้"];
        }
    }

    return ['success' => true, 'files' => $uploadedFiles, 'imageCount' => $imageCount];
}

// ฟังก์ชันสร้าง thumbnail
function createImageThumbnail($sourcePath, $destPath, $maxWidth = 300, $maxHeight = 300)
{
    if (!isGDEnabled()) {
        return false;
    }

    if (!file_exists($sourcePath)) {
        return false;
    }

    try {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];

        // คำนวณขนาดใหม่
        $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $newWidth = (int)($sourceWidth * $ratio);
        $newHeight = (int)($sourceHeight * $ratio);

        // สร้างภาพต้นฉบับ
        $sourceImage = null;
        switch ($mimeType) {
            case 'image/jpeg':
                if (function_exists('imagecreatefromjpeg')) {
                    $sourceImage = imagecreatefromjpeg($sourcePath);
                }
                break;
            case 'image/png':
                if (function_exists('imagecreatefrompng')) {
                    $sourceImage = imagecreatefrompng($sourcePath);
                }
                break;
            case 'image/gif':
                if (function_exists('imagecreatefromgif')) {
                    $sourceImage = imagecreatefromgif($sourcePath);
                }
                break;
            default:
                return false;
        }

        if (!$sourceImage) {
            return false;
        }

        // สร้างภาพใหม่
        $destImage = imagecreatetruecolor($newWidth, $newHeight);
        if (!$destImage) {
            imagedestroy($sourceImage);
            return false;
        }

        // จัดการความโปร่งใส
        if ($mimeType == 'image/png' || $mimeType == 'image/gif') {
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $transparent = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // ปรับขนาดภาพ
        $resizeResult = imagecopyresampled(
            $destImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $sourceWidth,
            $sourceHeight
        );

        if (!$resizeResult) {
            imagedestroy($sourceImage);
            imagedestroy($destImage);
            return false;
        }

        // สร้างโฟลเดอร์ปลายทางถ้าไม่มี
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // บันทึกภาพ
        $result = false;
        switch ($mimeType) {
            case 'image/jpeg':
                if (function_exists('imagejpeg')) {
                    $result = imagejpeg($destImage, $destPath, 85);
                }
                break;
            case 'image/png':
                if (function_exists('imagepng')) {
                    $result = imagepng($destImage, $destPath, 8);
                }
                break;
            case 'image/gif':
                if (function_exists('imagegif')) {
                    $result = imagegif($destImage, $destPath);
                }
                break;
        }

        // ล้างหน่วยความจำ
        imagedestroy($sourceImage);
        imagedestroy($destImage);

        return $result;
    } catch (Exception $e) {
        error_log("Exception in createImageThumbnail: " . $e->getMessage());
        return false;
    } catch (Error $e) {
        error_log("Error in createImageThumbnail: " . $e->getMessage());
        return false;
    }
}

// ประมวลผลการส่งข้อร้องเรียน
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_complaint'])) {
        // รับข้อมูลจากฟอร์ม
        $requestTitle = sanitizeInput($_POST['request_title']); // **NEW: รับค่าหัวข้อร้องเรียน**
        $information = sanitizeInput($_POST['description']);
        $typeId = sanitizeInput($_POST['category']);

        // ตรวจสอบโหมดไม่ระบุตัวตน
        $isAnonymous = 0;
        if (isset($_POST['anonymous']) && $_POST['anonymous'] == '1') {
            $isAnonymous = 1;
        }

        // ตรวจสอบข้อมูลจำเป็น
        if (empty($information) || empty($typeId)) {
            $error = 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน';
        } else {
            if ($editMode) {
                // โหมดแก้ไข
                try {
                    $db->beginTransaction();

                    // ใช้ SQL ตรงๆ เพื่อความชัวร์เรื่อง Parameter (แก้ไข Error HY093)
                    $sql = "UPDATE request SET Re_title = ?, Re_infor = ?, Type_id = ?, Re_iden = ? WHERE Re_id = ? AND Stu_id = ?";
                    $params = [
                        $requestTitle,
                        $information,
                        $typeId,
                        $isAnonymous,
                        $editRequest['Re_id'],
                        $user['Stu_id']
                    ];

                    $result = $db->execute($sql, $params);

                    if ($result) {
                        // จัดการไฟล์แนบใหม่ (ถ้ามี)
                        $fileResult = handleFileUploads($editRequest['Re_id'], $user['Stu_id']);

                        if (!$fileResult['success']) {
                            throw new Exception($fileResult['message']);
                        }

                        $db->commit();
                        $anonymousText = $isAnonymous ? ' (โหมดไม่ระบุตัวตน)' : ' (โหมดระบุตัวตน)';
                        $success = 'แก้ไขข้อร้องเรียนเรียบร้อยแล้ว' . $anonymousText;

                        if (!empty($fileResult['files'])) {
                            $success .= ' และอัพโหลดไฟล์ ' . count($fileResult['files']) . ' ไฟล์';
                        }

                        // รีเฟรชข้อมูลที่แก้ไข
                        $editRequest = $db->fetch("SELECT * FROM request WHERE Re_id = ? AND Stu_id = ?", [$editRequest['Re_id'], $user['Stu_id']]);
                    } else {
                        throw new Exception('ไม่สามารถอัพเดทข้อมูลได้');
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    $error = 'เกิดข้อผิดพลาดในการแก้ไข: ' . $e->getMessage();
                }
            } else {
                // โหมดส่งใหม่
                try {
                    $db->beginTransaction();

                    // สร้างข้อร้องเรียนใหม่ - ส่ง Stu_id ไปเสมอ
                    $requestData = [
                        'Re_title' => $requestTitle,
                        'Re_infor' => $information,
                        'Re_status' => '0', // รอยืนยัน
                        'Re_level' => '0',  // รอพิจารณา
                        'Re_iden' => $isAnonymous, // 0=ระบุตัวตน, 1=ไม่ระบุตัวตน
                        'Re_date' => date('Y-m-d'),
                        'Stu_id' => $user['Stu_id'], // ส่งไปเสมอเพื่อให้สามารถติดตามได้
                        'Type_id' => $typeId
                    ];

                    $newRequestId = $db->insert('request', $requestData);

                    if ($newRequestId) {
                        // จัดการไฟล์แนบ
                        $fileResult = handleFileUploads($newRequestId, $user['Stu_id']);

                        if (!$fileResult['success']) {
                            throw new Exception($fileResult['message']);
                        }

                        $db->commit();

                        // สร้างรหัสข้อร้องเรียนสำหรับแสดง
                        $complaintId = 'CR' . str_pad($newRequestId, 6, '0', STR_PAD_LEFT);
                        $anonymousText = $isAnonymous ? ' (โหมดไม่ระบุตัวตน)' : ' (โหมดระบุตัวตน)';
                        $success = "ส่งข้อร้องเรียนสำเร็จ! รหัสอ้างอิง: {$complaintId}" . $anonymousText;

                        if (!empty($fileResult['files'])) {
                            $success .= ' และอัพโหลดไฟล์ ' . count($fileResult['files']) . ' ไฟล์';
                        }

                        // แจ้งเตือนเรื่อง GD Extension (ถ้าไม่มี)
                        if (!isGDEnabled() && !empty($fileResult['files'])) {
                            $success .= ' (หมายเหตุ: ไม่สามารถสร้างรูปขนาดเล็กได้เนื่องจากระบบไม่รองรับ)';
                        }

                        // ส่งการแจ้งเตือนอัตโนมัติ
                        if (function_exists('sendAutoNotification')) {
                            sendAutoNotification('request_received', $newRequestId, $user['Stu_id'], 'student');
                        }

                        // ล้างฟอร์ม
                        $_POST = [];
                    } else {
                        throw new Exception('ไม่สามารถบันทึกข้อร้องเรียนได้');
                    }
                } catch (Exception $e) {
                    $db->rollback();
                    $error = 'เกิดข้อผิดพลาดในการส่งข้อร้องเรียน: ' . $e->getMessage();
                }
            }
        }
    }
}

// ดึงข้อมูลประเภทข้อร้องเรียนพร้อมไอคอน
$complaintTypes = getComplaintTypesList();

// ดึงไฟล์แนบที่มีอยู่ (สำหรับโหมดแก้ไข)
$existingFiles = [];
if ($editMode) {
    $existingFiles = getSupportingEvidence($editRequest['Re_id']);
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $editMode ? 'แก้ไขข้อร้องเรียน' : 'ส่งข้อร้องเรียน'; ?> - <?php echo SITE_NAME; ?></title>
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
            background: #dc3545;
            color: white;
        }

        .btn-secondary:hover {
            background: #c82333;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }

        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* System Status Alert */
        .system-alert {
            background: linear-gradient(135deg, #ffeaa7 0%, #fdcb6e 100%);
            color: #2d3436;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #e17055;
        }

        .system-alert .alert-icon {
            font-size: 1.2rem;
        }

        .system-alert .alert-content {
            flex: 1;
        }

        .system-alert h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
        }

        .system-alert p {
            margin: 0;
            font-size: 12px;
            opacity: 0.8;
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
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }

        .anonymous-section.active {
            border-color: #ff6b6b;
            background: rgba(255, 107, 107, 0.05);
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
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
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

        .anonymous-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
        }

        .anonymous-status.enabled {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }

        .anonymous-status.disabled {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
        }

        .anonymous-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }

        .warning-content {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .warning-icon {
            font-size: 1.2rem;
            flex-shrink: 0;
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

        .file-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
        }

        .file-item-with-image {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e1e5e9;
        }

        .file-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
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

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .file-size {
            font-size: 12px;
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
            align-items: center;
            gap: 10px;
        }

        .btn-remove {
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .btn-remove:hover {
            background: #c82333;
            transform: scale(1.1);
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

        /* Tips Section */
        .tips-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .tips-section h3 {
            color: #333;
            margin-bottom: 25px;
            text-align: center;
        }

        .tips-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .tip-item {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .tip-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .tip-item h4 {
            color: #333;
            margin-bottom: 10px;
            font-size: 1.1rem;
        }

        .tip-item p {
            color: #666;
            line-height: 1.5;
            margin: 0;
            font-size: 14px;
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

        .notification.warning {
            background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
        }

        .notification.error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        }

        .notification.info {
            background: linear-gradient(135deg, #339af0 0%, #228be6 100%);
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

        /* File Counter */
        .file-counter {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 10px;
            display: inline-block;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .counter-excellent {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-color: #c3e6cb;
        }

        .counter-good {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
            border-color: #bee5eb;
        }

        .counter-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
            border-color: #ffeaa7;
        }

        .counter-danger {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-color: #f5c6cb;
        }

        .counter-critical {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            border-color: #ee5a52;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Progress Bar */
        .file-progress {
            margin-top: 8px;
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .file-progress-bar {
            height: 100%;
            transition: all 0.4s ease;
            border-radius: 10px;
        }

        .progress-excellent {
            background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
        }

        .progress-good {
            background: linear-gradient(90deg, #17a2b8 0%, #20c997 100%);
        }

        .progress-warning {
            background: linear-gradient(90deg, #ffc107 0%, #fd7e14 100%);
        }

        .progress-danger {
            background: linear-gradient(90deg, #fd7e14 0%, #dc3545 100%);
        }

        .progress-critical {
            background: linear-gradient(90deg, #dc3545 0%, #6f42c1 100%);
            animation: progressPulse 1s infinite alternate;
        }

        @keyframes progressPulse {
            0% {
                opacity: 0.8;
            }

            100% {
                opacity: 1;
            }
        }

        /* Add More Files Button */
        .add-more-files {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .add-more-files:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .add-more-files:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* File Management Section */
        .file-management {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border: 1px dashed #dee2e6;
        }

        .file-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }

        .file-stat-item {
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e1e5e9;
        }

        .stat-number {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Existing Files */
        .existing-files {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .existing-files h4 {
            color: #333;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .existing-file-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            background: white;
            border-radius: 6px;
            margin-bottom: 5px;
            border: 1px solid #e1e5e9;
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

            .tips-grid {
                grid-template-columns: 1fr;
            }

            .file-item-with-image {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .image-preview {
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

            .file-name {
                font-size: 14px;
                line-height: 1.4;
            }

            .anonymous-toggle {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .anonymous-status {
                margin: 0 auto;
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
                <h1><?php echo $editMode ? '✏️ แก้ไขข้อร้องเรียน' : '📝 ส่งข้อร้องเรียน'; ?></h1>
                <p><?php echo $editMode ? 'แก้ไขข้อมูลข้อร้องเรียน #CR' . str_pad($editRequest['Re_id'], 6, '0', STR_PAD_LEFT) : 'ยื่นข้อร้องเรียนหรือข้อเสนอแนะ'; ?></p>
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
                    <h1><?php echo $editMode ? '✏️ แก้ไขข้อร้องเรียน' : '📝 ส่งข้อร้องเรียน'; ?></h1>
                    <p><?php echo $editMode ? 'แก้ไขข้อมูลข้อร้องเรียน #CR' . str_pad($editRequest['Re_id'], 6, '0', STR_PAD_LEFT) : 'ยื่นข้อร้องเรียนหรือข้อเสนอแนะ'; ?></p>
                </div>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    <?php if ($error): ?>
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด!',
                            text: '<?php echo str_replace("'", "\'", $error); ?>', // ป้องกัน error quote
                            confirmButtonText: 'ตกลง',
                            confirmButtonColor: '#dc3545', // สีแดง
                            background: '#fff',
                            backdrop: `rgba(0,0,123,0.4)`
                        });
                    <?php endif; ?>

                    <?php if ($success): ?>
                        Swal.fire({
                            icon: 'success',
                            title: 'ดำเนินการสำเร็จ!',
                            html: '<?php echo str_replace("'", "\'", $success); ?>', // ใช้ html เพื่อรองรับการขึ้นบรรทัดใหม่
                            showCancelButton: true,
                            confirmButtonText: '📋 ไปยังหน้าติดตามสถานะ',
                            cancelButtonText: '📝 เขียนเรื่องใหม่ / หน้าเดิม',
                            confirmButtonColor: '#667eea', // สีธีมหลัก
                            cancelButtonColor: '#6c757d',
                            reverseButtons: true
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // ถ้ากดปุ่มยืนยัน ให้ไปหน้า tracking
                                window.location.href = 'tracking.php';
                            } else {
                                // ถ้ากดปุ่มยกเลิก หรือปิด popup
                                // กรณีเป็นโหมดแก้ไข อาจจะ redirect กลับหน้า index หรืออยู่หน้าเดิมก็ได้
                                <?php if ($editMode): ?>
                                    window.location.href = 'tracking.php'; // ถ้าแก้ไขเสร็จแล้ว ควรกลับไปหน้า tracking
                                <?php else: ?>
                                    // ถ้าส่งใหม่เสร็จแล้ว อยู่หน้าเดิม (เคลียร์ฟอร์มแล้ว)
                                <?php endif; ?>
                            }
                        });
                    <?php endif; ?>
                });
            </script>

            <!-- Complaint Form -->
            <form method="POST" enctype="multipart/form-data" class="complaint-form" id="complaintForm">

                <!-- Anonymous Mode Section -->
                <?php if (!$editMode): ?>
                    <div class="anonymous-section" id="anonymousSection">
                        <div class="anonymous-toggle">
                            <label class="toggle-switch">
                                <input type="hidden" name="anonymous" value="0" id="anonymousHidden">
                                <input type="checkbox"
                                    id="anonymousMode"
                                    name="anonymous"
                                    value="1"
                                    onchange="toggleAnonymousMode()"
                                    <?php echo (isset($_POST['anonymous']) && $_POST['anonymous'] == '1') || ($editMode && $editRequest['Re_iden'] == '1') ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            <div class="toggle-info">
                                <h3>🔒 การร้องเรียนแบบไม่ระบุตัวตน</h3>
                                <p>เมื่อเปิดใช้งาน เจ้าหน้าที่จะไม่สามารถเห็นข้อมูลผู้ร้องเรียนได้ แต่คุณยังสามารถติดตามสถานะได้ตามปกติ</p>
                            </div>
                            <div class="anonymous-status disabled" id="anonymousStatus">
                                <span>🔓</span>
                                <span>ระบุตัวตน</span>
                            </div>
                        </div>

                        <div class="anonymous-warning" id="anonymousWarning" style="display: none;">
                            <div class="warning-content">
                                <span class="warning-icon">⚠️</span>
                                <div>
                                    <strong>คำเตือน:</strong> หากมีการรายงานจากเจ้าหน้าที่ว่าเป็นการร้องเรียนที่ไม่เหมาะสม
                                    บัญชีของคุณอาจถูกระงับ และคุณจะต้องติดต่อเจ้าหน้าที่โดยตรง
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-section">
                    <h3>📋 ข้อมูลข้อร้องเรียน</h3>

                    <div class="form-group">
                        <label for="category">📂 ประเภทข้อร้องเรียน <span class="required">*</span></label>
                        <div class="select-wrapper">
                            <select id="category" name="category" required>
                                <option value="">เลือกประเภท</option>
                                <?php
                                $selectedCategory = $editMode ? $editRequest['Type_id'] : (isset($_POST['category']) ? $_POST['category'] : '');
                                foreach ($complaintTypes as $type):
                                ?>
                                    <option value="<?php echo $type['Type_id']; ?>"
                                        data-icon="<?php echo htmlspecialchars($type['Type_icon']); ?>"
                                        <?php echo ($selectedCategory == $type['Type_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['Type_icon'] . ' ' . $type['Type_infor']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <small class="form-help">💡 เลือกประเภทข้อร้องเรียนที่ตรงกับปัญหาของคุณมากที่สุด</small>
                        <div class="form-group">
                            <label for="request_title">หัวข้อร้องเรียน <span class="required">*</span></label>
                            <input type="text" name="request_title" id="request_title"
                                placeholder="เช่น ปัญหาแอร์เสียในห้องเรียน A405"
                                value="<?php echo htmlspecialchars($editMode ? $editRequest['Re_title'] : (isset($_POST['request_title']) ? $_POST['request_title'] : '')); ?>"
                                required maxlength="100">
                            <small class="form-help">ตั้งชื่อหัวข้อให้กระชับและตรงประเด็น (ไม่เกิน 100 ตัวอักษร)</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description">✍️ รายละเอียดข้อร้องเรียน <span class="required">*</span></label>
                        <textarea
                            id="description"
                            name="description"
                            placeholder="กรุณาอธิบายรายละเอียดของปัญหาที่พบ ให้ครบถ้วนเพื่อให้เจ้าหน้าที่สามารถดำเนินการได้อย่างมีประสิทธิภาพ"
                            rows="6"
                            required><?php echo htmlspecialchars($editMode ? $editRequest['Re_infor'] : (isset($_POST['description']) ? $_POST['description'] : '')); ?></textarea>
                        <small class="form-help">💡 อธิบายปัญหาให้ละเอียดที่สุด เพื่อให้เจ้าหน้าที่เข้าใจและดำเนินการได้ถูกต้อง</small>
                    </div>
                </div>

                <div class="form-section">
                    <h3>📍 ข้อมูลเพิ่มเติม</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="incident_date">📅 วันที่ร้องเรียน</label>
                            <div class="date-display">
                                <span class="date-icon">📅</span>
                                <?php echo date('d/m/Y'); ?> (วันนี้)
                            </div>
                            <small class="form-help">📝 วันที่จะถูกบันทึกเป็นวันที่ปัจจุบันโดยอัตโนมัติ</small>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>📎 แนบไฟล์หลักฐาน</h3>

                    <!-- แสดงไฟล์ที่มีอยู่แล้ว (สำหรับโหมดแก้ไข) -->
                    <?php if ($editMode && !empty($existingFiles)): ?>
                        <div class="existing-files">
                            <h4>📁 ไฟล์ที่แนบไว้แล้ว:</h4>
                            <?php foreach ($existingFiles as $file): ?>
                                <div class="existing-file-item" style="align-items: flex-start; padding: 10px;">
                                    <?php
                                    // 1. ตรวจสอบว่าเป็นรูปภาพหรือไม่
                                    $isImg = isImageFile($file['Sup_filetype']);
                                    // 2. หา Path ของรูปภาพ
                                    $imgUrl = $isImg ? getImageDisplayUrl($file['Sup_filepath']) : null;
                                    ?>

                                    <div style="flex-shrink: 0; width: 80px; height: 80px; margin-right: 15px;">
                                        <?php if ($isImg && $imgUrl): ?>
                                            <img src="<?php echo htmlspecialchars($imgUrl); ?>"
                                                alt="preview"
                                                style="width: 100%; height: 100%; object-fit: cover; border-radius: 6px; border: 1px solid #ddd; cursor: pointer;"
                                                onclick="openImageModal(this.src)">
                                        <?php else: ?>
                                            <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: #f1f3f5; border-radius: 6px; font-size: 2rem; border: 1px solid #eee;">
                                                <?php echo $isImg ? '🖼️' : '📄'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div style="flex: 1; min-width: 0;">
                                        <div style="font-weight: 600; color: #333; margin-bottom: 5px; word-break: break-all;">
                                            <?php echo htmlspecialchars($file['Sup_filename']); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #666;">
                                            ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?>
                                        </div>
                                        <?php if ($isImg && !$imgUrl): ?>
                                            <div style="font-size: 11px; color: #dc3545; margin-top: 5px;">⚠️ ไม่สามารถโหลดรูปตัวอย่างได้</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="attachments">📄 ไฟล์แนบ (ถ้ามี)</label>
                        <input
                            type="file"
                            id="attachments"
                            name="attachments[]"
                            multiple
                            accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx"
                            class="file-input">
                        <small class="form-help">
                            📋 รองรับไฟล์: รูปภาพ (JPG, PNG), PDF, Word (DOC, DOCX), Excel (XLS, XLSX)<br>
                            📏 ขนาดไฟล์สูงสุด: <?php echo round(MAX_FILE_SIZE / 1048576, 1); ?> MB ต่อไฟล์<br>
                            📎 จำนวนไฟล์สูงสุด: 8 ไฟล์ (รูปภาพ 5 ไฟล์, เอกสาร 3 ไฟล์)<br>
                            <?php if (!isGDEnabled()): ?>
                                ⚠️ หมายเหตุ: ระบบไม่สามารถสร้างรูปขนาดเล็กได้ แต่ยังอัพโหลดไฟล์ได้ปกติ
                            <?php endif; ?>
                        </small>

                        <!-- File Management Section -->
                        <div class="file-management" id="fileManagement" style="display: none;">
                            <div class="file-stats">
                                <div class="file-stat-item">
                                    <div class="stat-number" id="totalFilesCount">0</div>
                                    <div class="stat-label">ไฟล์ทั้งหมด</div>
                                </div>
                                <div class="file-stat-item">
                                    <div class="stat-number" id="imagesCount">0</div>
                                    <div class="stat-label">รูปภาพ</div>
                                </div>
                                <div class="file-stat-item">
                                    <div class="stat-number" id="documentsCount">0</div>
                                    <div class="stat-label">เอกสาร</div>
                                </div>
                                <div class="file-stat-item">
                                    <div class="stat-number" id="totalSizeDisplay">0 MB</div>
                                    <div class="stat-label">ขนาดรวม</div>
                                </div>
                            </div>

                            <div class="file-counter" id="fileCounter">
                                📊 สถานะการแนบไฟล์: <span id="fileStatus">พร้อมแนบไฟล์</span>
                                <div class="file-progress">
                                    <div class="file-progress-bar" id="fileProgressBar" style="width: 0%"></div>
                                </div>
                            </div>

                            <button type="button" class="add-more-files" id="addMoreFiles" onclick="triggerFileInput()">
                                ➕ แนบไฟล์เพิ่มเติม
                            </button>
                        </div>
                    </div>

                    <div class="file-preview" id="filePreview" style="display: none;">
                        <h4>📁 ไฟล์ที่เลือก:</h4>
                        <div class="file-list" id="fileList"></div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="submit_complaint" class="btn btn-primary">
                        <?php echo $editMode ? '💾 บันทึกการแก้ไข' : '📤 ส่งข้อร้องเรียน'; ?>
                    </button>
                    
                    <button type="reset" class="btn btn-outline" onclick="clearAllData()">
                        🔄 ล้างข้อมูลทั้งหมด
                    </button>
                    <?php if ($editMode): ?>
                        <a href="tracking.php" class="btn btn-secondary">
                            ❌ ยกเลิกการแก้ไข
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Tips Section -->
            <?php if (!$editMode): ?>
                <div class="tips-section">
                    <h3>💡 เคล็ดลับการส่งข้อร้องเรียนที่ดี</h3>
                    <div class="tips-grid">
                        <div class="tip-item">
                            <div class="tip-icon">🎯</div>
                            <h4>ระบุปัญหาชัดเจน</h4>
                            <p>อธิบายปัญหาที่เกิดขึ้นให้ชัดเจน ระบุสถานที่และเวลาที่เกิดเหตุ</p>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📸</div>
                            <h4>แนบหลักฐาน</h4>
                            <p>แนบรูปภาพหรือเอกสารที่เกี่ยวข้อง เพื่อให้เจ้าหน้าที่เข้าใจปัญหาได้ดีขึ้น</p>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">🤝</div>
                            <h4>ใช้ภาษาสุภาพ</h4>
                            <p>ใช้ภาษาที่สุภาพและสร้างสรรค์ เพื่อให้การแก้ไขปัญหาเป็นไปอย่างมีประสิทธิภาพ</p>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">📋</div>
                            <h4>เลือกประเภทที่ถูกต้อง</h4>
                            <p>เลือกประเภทข้อร้องเรียนให้ถูกต้อง เพื่อให้เจ้าหน้าที่ที่เหมาะสมได้รับเรื่อง</p>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon">💾</div>
                            <h4>บันทึกร่างงาน</h4>
                            <p>สามารถบันทึกร่างพร้อมไฟล์แนบได้ หากต้องการมาแก้ไขต่อในภายหลัง (ขีดจำกัด 3MB)</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <?php
    // โหลด JavaScript ตามสิทธิ์ผู้ใช้
    $currentRole = $_SESSION['user_role'] ?? '';
    if ($currentRole === 'teacher'): ?>
        <script src="../js/staff.js"></script>
    <?php endif; ?>


    <div id="imageModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.9); align-items: center; justify-content: center;">
        <span onclick="document.getElementById('imageModal').style.display='none'; document.body.style.overflow='';" style="position: absolute; top: 20px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer;">&times;</span>
        <img class="modal-content" id="modalImage" style="margin: auto; display: block; max-width: 90%; max-height: 90vh; border-radius: 5px; box-shadow: 0 0 20px rgba(0,0,0,0.5);">
    </div>

    <script>
        // ฟังก์ชันเปิด Modal
        function openImageModal(src) {
            var modal = document.getElementById("imageModal");
            var modalImg = document.getElementById("modalImage");
            modal.style.display = "flex";
            modalImg.src = src;
            document.body.style.overflow = 'hidden'; // ป้องกันการเลื่อนหน้าหลัง
        }

        // ปิด Modal เมื่อกดพื้นหลัง
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
                document.body.style.overflow = '';
            }
        });

        // ตัวแปรสำหรับจัดการไฟล์
        let allFiles = [];
        let fileId = 0;
        let totalSize = 0;

        // ประเภทไฟล์
        const imageTypes = ['jpg', 'jpeg', 'png', 'gif'];
        const documentTypes = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];

        // การตั้งค่าขีดจำกัด
        const MAX_FILES = 8;
        const MAX_IMAGES = 5;
        const MAX_DOCUMENTS = 3;
        const MAX_FILE_SIZE = <?php echo MAX_FILE_SIZE; ?>;
        const MAX_TOTAL_SIZE = MAX_FILE_SIZE * MAX_FILES;

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

            // Initialize anonymous mode
            const checkbox = document.getElementById('anonymousMode');
            const hiddenInput = document.getElementById('anonymousHidden');

            if (checkbox && hiddenInput) {
                if (checkbox.checked) {
                    toggleAnonymousMode();
                } else {
                    hiddenInput.disabled = false;
                }
            }
        });

        // ฟังก์ชัน toggleAnonymousMode
        function toggleAnonymousMode() {
            const checkbox = document.getElementById('anonymousMode');
            const hiddenInput = document.getElementById('anonymousHidden');
            const warning = document.getElementById('anonymousWarning');
            const section = document.getElementById('anonymousSection');
            const status = document.getElementById('anonymousStatus');

            if (checkbox && hiddenInput && warning && section && status) {
                if (checkbox.checked) {
                    hiddenInput.disabled = true;
                    warning.style.display = 'block';
                    section.classList.add('active');
                    status.className = 'anonymous-status enabled';
                    status.innerHTML = '<span>🔒</span><span>ไม่ระบุตัวตน</span>';
                    showToast('🔒 เปิดใช้งานโหมดไม่ระบุตัวตน', 'warning');
                } else {
                    hiddenInput.disabled = false;
                    warning.style.display = 'none';
                    section.classList.remove('active');
                    status.className = 'anonymous-status disabled';
                    status.innerHTML = '<span>🔓</span><span>ระบุตัวตน</span>';
                    showToast('🔓 ปิดใช้งานโหมดไม่ระบุตัวตน', 'info');
                }
            }
        }

        // ฟังก์ชันตรวจสอบฟอร์มก่อนส่ง
        function validateForm() {
            const checkbox = document.getElementById('anonymousMode');
            const isAnonymous = checkbox ? checkbox.checked : false;

            if (isAnonymous) {
                const confirmed = confirm(
                    '🔒 ยืนยันการส่งข้อร้องเรียนแบบไม่ระบุตัวตน\n\n' +
                    '• เจ้าหน้าที่จะไม่สามารถเห็นข้อมูลส่วนตัวของคุณได้\n' +
                    '• คุณยังสามารถติดตามสถานะได้ตามปกติ\n' +
                    '• หากมีการรายงานว่าไม่เหมาะสม บัญชีอาจถูกระงับ\n\n' +
                    'คุณต้องการดำเนินการต่อหรือไม่?'
                );
                if (!confirmed) {
                    return false;
                }
                showToast('🔒 กำลังส่งข้อร้องเรียนแบบไม่ระบุตัวตน...', 'warning');
            } else {
                showToast('🔓 กำลังส่งข้อร้องเรียนแบบระบุตัวตน...', 'info');
            }

            return true;
        }

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
                window.location.href = `tracking.php?id=${requestId}`;
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

        // ฟังก์ชันเปิด File Input
        function triggerFileInput() {
            document.getElementById('attachments').click();
        }

        // ฟังก์ชันคำนวณสถิติไฟล์
        function calculateFileStats() {
            let imageCount = 0;
            let documentCount = 0;
            totalSize = 0;

            allFiles.forEach(file => {
                const extension = file.name.split('.').pop().toLowerCase();
                if (imageTypes.includes(extension)) {
                    imageCount++;
                } else if (documentTypes.includes(extension)) {
                    documentCount++;
                }
                totalSize += file.size;
            });

            return {
                totalFiles: allFiles.length,
                imageCount,
                documentCount,
                totalSize
            };
        }

        // ฟังก์ชันคำนวณเปอร์เซ็นต์และกำหนดสี
        function getStatusInfo(stats) {
            const filePercentage = (stats.totalFiles / MAX_FILES) * 100;
            const imagePercentage = (stats.imageCount / MAX_IMAGES) * 100;
            const documentPercentage = (stats.documentCount / MAX_DOCUMENTS) * 100;
            const sizePercentage = (stats.totalSize / MAX_TOTAL_SIZE) * 100;

            const maxPercentage = Math.max(filePercentage, imagePercentage, documentPercentage, sizePercentage);

            let status, className, progressClass, message;

            if (maxPercentage <= 30) {
                status = 'excellent';
                className = 'counter-excellent';
                progressClass = 'progress-excellent';
                message = '🟢 พร้อมแนบไฟล์ - ยังแนบได้อีกเยอะ';
            } else if (maxPercentage <= 60) {
                status = 'good';
                className = 'counter-good';
                progressClass = 'progress-good';
                message = '🔵 แนบไฟล์ได้ปกติ - ยังมีที่ว่าง';
            } else if (maxPercentage <= 80) {
                status = 'warning';
                className = 'counter-warning';
                progressClass = 'progress-warning';
                message = '🟡 ใกล้เต็มแล้ว - ควรเลือกไฟล์อย่างรอบคอบ';
            } else if (maxPercentage <= 95) {
                status = 'danger';
                className = 'counter-danger';
                progressClass = 'progress-danger';
                message = '🟠 เกือบเต็ม - แนบได้อีกนิดหน่อย';
            } else {
                status = 'critical';
                className = 'counter-critical';
                progressClass = 'progress-critical';
                message = '🔴 เต็มแล้ว - ไม่สามารถแนบไฟล์เพิ่มได้';
            }

            return {
                percentage: Math.min(maxPercentage, 100),
                status,
                className,
                progressClass,
                message
            };
        }

        // อัพเดท UI
        function updateFileUI() {
            const stats = calculateFileStats();
            const statusInfo = getStatusInfo(stats);

            // อัพเดทตัวเลขสถิติ
            document.getElementById('totalFilesCount').textContent = stats.totalFiles;
            document.getElementById('imagesCount').textContent = stats.imageCount;
            document.getElementById('documentsCount').textContent = stats.documentCount;
            document.getElementById('totalSizeDisplay').textContent = formatFileSize(stats.totalSize);

            // อัพเดทสถานะและสี
            const counter = document.getElementById('fileCounter');
            const progressBar = document.getElementById('fileProgressBar');
            const fileStatus = document.getElementById('fileStatus');
            const addMoreBtn = document.getElementById('addMoreFiles');

            counter.className = `file-counter ${statusInfo.className}`;
            progressBar.className = `file-progress-bar ${statusInfo.progressClass}`;
            progressBar.style.width = `${statusInfo.percentage}%`;
            fileStatus.textContent = statusInfo.message;

            // ปิด/เปิดปุ่มเพิ่มไฟล์
            const canAddMore = stats.totalFiles < MAX_FILES &&
                stats.imageCount < MAX_IMAGES &&
                stats.documentCount < MAX_DOCUMENTS &&
                stats.totalSize < MAX_TOTAL_SIZE;

            addMoreBtn.disabled = !canAddMore;
            if (!canAddMore) {
                addMoreBtn.textContent = '🚫 ไม่สามารถแนบเพิ่มได้';
            } else {
                addMoreBtn.textContent = '➕ แนบไฟล์เพิ่มเติม';
            }

            // แสดง/ซ่อน File Management
            const fileManagement = document.getElementById('fileManagement');
            if (allFiles.length > 0) {
                fileManagement.style.display = 'block';
            } else {
                fileManagement.style.display = 'none';
            }
        }

        // แสดงรายการไฟล์
        function displayFileList() {
            const preview = document.getElementById('filePreview');
            const fileList = document.getElementById('fileList');

            if (allFiles.length > 0) {
                preview.style.display = 'block';
                fileList.innerHTML = '';

                allFiles.forEach((file, index) => {
                    const fileExtension = file.name.split('.').pop().toLowerCase();
                    const isImage = imageTypes.includes(fileExtension);

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
                        fileIcon = '📊';
                        fileTypeBadge = 'document';
                        badgeText = 'Excel';
                    }

                    const fileItem = document.createElement('div');
                    fileItem.className = isImage ? 'file-item-with-image' : 'file-item';

                    if (isImage) {
                        const img = document.createElement('img');
                        img.className = 'image-preview';
                        img.src = URL.createObjectURL(file);
                        img.alt = 'ตัวอย่างรูปภาพ';
                        img.onload = function() {
                            URL.revokeObjectURL(this.src);
                        };

                        fileItem.innerHTML = `
                            <div class="file-info">
                                <div class="file-name">${file.name}</div>
                                <div class="file-size">ขนาด: ${formatFileSize(file.size)}</div>
                                <span class="file-type-badge ${fileTypeBadge}">${badgeText}</span>
                            </div>
                            <div class="file-actions">
                                <button type="button" onclick="removeFile(${index})" class="btn-remove" title="ลบไฟล์">×</button>
                            </div>
                        `;

                        fileItem.insertBefore(img, fileItem.firstChild);
                    } else {
                        fileItem.innerHTML = `
                            <span class="file-icon">${fileIcon}</span>
                            <div class="file-info">
                                <div class="file-name">${file.name}</div>
                                <div class="file-size">ขนาด: ${formatFileSize(file.size)}</div>
                                <span class="file-type-badge ${fileTypeBadge}">${badgeText}</span>
                            </div>
                            <div class="file-actions">
                                <button type="button" onclick="removeFile(${index})" class="btn-remove" title="ลบไฟล์">×</button>
                            </div>
                        `;
                    }

                    fileList.appendChild(fileItem);
                });
            } else {
                preview.style.display = 'none';
            }
        }

        // ฟังก์ชันเพิ่มไฟล์ใหม่
        function addNewFiles(newFiles) {
            const stats = calculateFileStats();
            let tempImageCount = stats.imageCount;
            let tempDocumentCount = stats.documentCount;
            let tempTotalFiles = stats.totalFiles;
            let tempTotalSize = stats.totalSize;

            for (let file of newFiles) {
                // ตรวจสอบขนาดไฟล์
                if (file.size > MAX_FILE_SIZE) {
                    showNotification(`❌ ไฟล์ ${file.name} มีขนาดเกิน ${formatFileSize(MAX_FILE_SIZE)}`, 'error');
                    continue;
                }

                // ตรวจสอบจำนวนไฟล์รวม
                if (tempTotalFiles >= MAX_FILES) {
                    showNotification('❌ ไม่สามารถแนบไฟล์เกิน 8 ไฟล์ได้', 'error');
                    break;
                }

                const fileExtension = file.name.split('.').pop().toLowerCase();
                const isImage = imageTypes.includes(fileExtension);
                const isDocument = documentTypes.includes(fileExtension);

                // ตรวจสอบจำนวนไฟล์แต่ละประเภท
                if (isImage && tempImageCount >= MAX_IMAGES) {
                    showNotification('❌ สามารถแนบรูปภาพได้สูงสุด 5 ไฟล์', 'error');
                    continue;
                }

                if (isDocument && tempDocumentCount >= MAX_DOCUMENTS) {
                    showNotification('❌ สามารถแนบเอกสารได้สูงสุด 3 ไฟล์', 'error');
                    continue;
                }

                // ตรวจสอบขนาดรวม
                if (tempTotalSize + file.size > MAX_TOTAL_SIZE) {
                    showNotification('❌ ขนาดไฟล์รวมเกินกำหนด', 'error');
                    continue;
                }

                // ตรวจสอบไฟล์ซ้ำ
                const isDuplicate = allFiles.some(existingFile =>
                    existingFile.name === file.name && existingFile.size === file.size
                );

                if (isDuplicate) {
                    showNotification(`⚠️ ไฟล์ ${file.name} มีอยู่แล้ว`, 'warning');
                    continue;
                }

                // เพิ่มไฟล์เข้า Array
                file.id = fileId++;
                allFiles.push(file);

                // อัพเดทตัวนับ
                if (isImage) tempImageCount++;
                if (isDocument) tempDocumentCount++;
                tempTotalFiles++;
                tempTotalSize += file.size;

                showNotification(`✅ เพิ่มไฟล์ ${file.name} เรียบร้อย`, 'success');
            }

            // แจ้งเตือนเมื่อรูปภาพเกิน 4 รูป
            if (tempImageCount > 4 && stats.imageCount <= 4) {
                showNotification('⚠️ คำแนะนำ: หากท่านมีรูปภาพมากกว่า 4 รูป แนะนำให้รวมเป็นไฟล์ PDF เดียว เพื่อความสะดวกในการจัดการ', 'warning');
            }

            updateFileInput();
            updateFileUI();
            displayFileList();
        }

        // อัพเดท Input Element ให้ตรงกับไฟล์ที่เลือก
        function updateFileInput() {
            const input = document.getElementById('attachments');
            const dt = new DataTransfer();

            allFiles.forEach(file => {
                dt.items.add(file);
            });

            input.files = dt.files;
        }

        // จัดการการเลือกไฟล์
        document.getElementById('attachments').addEventListener('change', function(e) {
            const newFiles = Array.from(e.target.files);

            // เคลียร์ input เพื่อให้สามารถเลือกไฟล์เดิมได้อีก
            e.target.value = '';

            if (newFiles.length > 0) {
                addNewFiles(newFiles);
            }
        });

        // ฟังก์ชันลบไฟล์
        function removeFile(index) {
            const removedFile = allFiles[index];
            allFiles.splice(index, 1);

            showNotification(`🗑️ ลบไฟล์ ${removedFile.name} แล้ว`, 'info');

            updateFileInput();
            updateFileUI();
            displayFileList();
        }

        // ฟังก์ชันจัดรูปแบบขนาดไฟล์
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // ฟังก์ชันล้างข้อมูลทั้งหมด
        function clearAllData() {
            document.getElementById('category').value = '';
            document.getElementById('description').value = '';

            const anonymousCheckbox = document.getElementById('anonymousMode');
            if (anonymousCheckbox) {
                anonymousCheckbox.checked = false;
                toggleAnonymousMode();
            }

            allFiles = [];
            fileId = 0;
            totalSize = 0;

            document.getElementById('attachments').value = '';

            updateFileUI();
            displayFileList();

            showNotification('🗑️ ล้างข้อมูลทั้งหมดเรียบร้อย', 'info');
        }

        // ฟังก์ชันแปลงไฟล์เป็น Base64
        function fileToBase64(file) {
            return new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload = () => resolve(reader.result);
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        }

        // ฟังก์ชันแปลง Base64 กลับเป็นไฟล์
        function base64ToFile(base64String, fileName) {
            const arr = base64String.split(',');
            const mime = arr[0].match(/:(.*?);/)[1];
            const bstr = atob(arr[1]);
            let n = bstr.length;
            const u8arr = new Uint8Array(n);
            while (n--) {
                u8arr[n] = bstr.charCodeAt(n);
            }
            return new File([u8arr], fileName, {
                type: mime
            });
        }

        // ฟังก์ชันบันทึกร่าง
        async function saveDraft() {
            const checkbox = document.getElementById('anonymousMode');
            const formData = {
                category: document.getElementById('category').value,
                description: document.getElementById('description').value,
                anonymous: checkbox ? checkbox.checked : false,
                files: []
            };

            // ตรวจสอบขนาดไฟล์ก่อนบันทึก
            const totalFileSize = allFiles.reduce((sum, file) => sum + file.size, 0);
            const maxDraftFileSize = 3 * 1024 * 1024; // 3MB สำหรับร่าง

            if (totalFileSize > maxDraftFileSize) {
                showNotification('⚠️ ไฟล์มีขนาดใหญ่เกินไป จะบันทึกเฉพาะข้อความเท่านั้น (ข้อจำกัด: 3MB สำหรับร่าง)', 'warning');
            } else if (allFiles.length > 0) {
                try {
                    showNotification('🔄 กำลังบันทึกไฟล์... กรุณารอสักครู่', 'info');

                    for (let file of allFiles) {
                        try {
                            const base64 = await fileToBase64(file);
                            formData.files.push({
                                name: file.name,
                                size: file.size,
                                type: file.type,
                                lastModified: file.lastModified,
                                base64: base64
                            });
                        } catch (error) {
                            console.error('Error converting file to base64:', error);
                        }
                    }
                } catch (error) {
                    showNotification('❌ เกิดข้อผิดพลาดในการบันทึกไฟล์ จะบันทึกเฉพาะข้อความ', 'error');
                }
            }

            try {
                localStorage.setItem('complaintDraft', JSON.stringify(formData));
                const fileText = formData.files.length > 0 ? ` และไฟล์ ${formData.files.length} ไฟล์` : '';
                const anonymousText = formData.anonymous ? ' (โหมดไม่ระบุตัวตน)' : '';
                showNotification(`💾 บันทึกร่างเรียบร้อย${fileText}${anonymousText}`, 'success');
            } catch (error) {
                if (error.name === 'QuotaExceededError') {
                    const textOnlyData = {
                        category: formData.category,
                        description: formData.description,
                        anonymous: formData.anonymous,
                        files: []
                    };
                    try {
                        localStorage.setItem('complaintDraft', JSON.stringify(textOnlyData));
                        showNotification('⚠️ พื้นที่เก็บข้อมูลเต็ม บันทึกเฉพาะข้อความเรียบร้อย', 'warning');
                    } catch (e) {
                        showNotification('❌ ไม่สามารถบันทึกร่างได้ พื้นที่เก็บข้อมูลเต็ม', 'error');
                    }
                } else {
                    showNotification('❌ เกิดข้อผิดพลาดในการบันทึกร่าง', 'error');
                }
            }
        }


        // ฟังก์ชันแสดงการแจ้งเตือน (สำหรับ notifications ที่มี style ใหม่)
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <span class="notification-icon">${type === 'success' ? '✅' : type === 'warning' ? '⚠️' : type === 'error' ? '❌' : 'ℹ️'}</span>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 4000);
        }

        // การตรวจสอบก่อนส่งฟอร์ม
        document.getElementById('complaintForm').addEventListener('submit', function(e) {
            const formData = new FormData(this);
            const anonymousValue = formData.get('anonymous');

            // Emergency fallback
            if (!formData.has('anonymous')) {
                const checkbox = document.getElementById('anonymousMode');
                const isAnonymous = checkbox && checkbox.checked;
                const emergencyInput = document.createElement('input');
                emergencyInput.type = 'hidden';
                emergencyInput.name = 'anonymous';
                emergencyInput.value = isAnonymous ? '1' : '0';
                this.appendChild(emergencyInput);
                showNotification(`🔧 Emergency fix: ส่งค่า anonymous=${emergencyInput.value}`, 'warning');
            }

            if (!validateForm()) {
                e.preventDefault();
                return false;
            }

            const stats = calculateFileStats();

            if (stats.totalFiles > MAX_FILES) {
                e.preventDefault();
                showNotification('❌ ไม่สามารถแนบไฟล์เกิน 8 ไฟล์ได้', 'error');
                return false;
            }

            if (stats.imageCount > MAX_IMAGES) {
                e.preventDefault();
                showNotification('❌ สามารถแนบรูปภาพได้สูงสุด 5 ไฟล์', 'error');
                return false;
            }

            if (stats.documentCount > MAX_DOCUMENTS) {
                e.preventDefault();
                showNotification('❌ สามารถแนบเอกสารได้สูงสุด 3 ไฟล์', 'error');
                return false;
            }

            return true;
        });

        // จัดการเมื่อฟอร์มถูก reset
        document.querySelector('form').addEventListener('reset', function(e) {
            setTimeout(() => {
                clearAllData();
            }, 10);
        });
    </script>
</body>

</html>