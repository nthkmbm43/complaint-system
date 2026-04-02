<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบการล็อกอิน
requireLogin('../login.php');

$user = getCurrentUser();
$db = getDB();

// ตรวจสอบพารามิเตอร์
$fileName = $_GET['file'] ?? '';
$displayName = $_GET['name'] ?? $fileName;

if (empty($fileName)) {
    http_response_code(404);
    die('ไม่พบไฟล์ที่ต้องการ');
}

// กำหนด path ไฟล์ที่ถูกต้อง
// ไฟล์นี้อยู่ใน students/ ส่วน uploads อยู่ในระดับเดียวกัน
$uploadsDir = '../uploads/requests/';
$filePath = $uploadsDir . $fileName;

// ตรวจสอบว่าไฟล์มีอยู่จริง
if (!file_exists($filePath)) {
    // ลองหาในโฟลเดอร์อื่นๆ
    $alternativePaths = [
        '../uploads/requests/images/' . $fileName,
        '../uploads/' . $fileName,
        '../uploads/evidence/' . $fileName
    ];

    $foundPath = null;
    foreach ($alternativePaths as $altPath) {
        if (file_exists($altPath)) {
            $foundPath = $altPath;
            break;
        }
    }

    if ($foundPath) {
        $filePath = $foundPath;
    } else {
        http_response_code(404);
        die('ไม่พบไฟล์ที่ต้องการ: ' . $fileName);
    }
}

// ตรวจสอบสิทธิ์การเข้าถึงไฟล์
try {
    // หาข้อมูลไฟล์จากฐานข้อมูล โดยใช้ชื่อไฟล์ในการค้นหา
    $fileInfo = $db->fetch("
        SELECT se.*, r.Stu_id, r.Re_iden 
        FROM supporting_evidence se
        LEFT JOIN request r ON se.Re_id = r.Re_id
        WHERE se.Sup_filename LIKE ? OR LOWER(se.Sup_filepath) LIKE LOWER(?)
        ORDER BY se.Sup_id DESC
        LIMIT 1
    ", ['%' . $fileName . '%', '%' . $fileName . '%']);

    if (!$fileInfo) {
        // ถ้าไม่พบในฐานข้อมูล แต่ไฟล์มีอยู่ ให้อนุญาตดาวน์โหลด (สำหรับไฟล์เก่า)
        if (file_exists($filePath)) {
            // ตรวจสอบสิทธิ์พื้นฐาน: ต้องล็อกอินแล้ว
            if (!isLoggedIn()) {
                http_response_code(403);
                die('กรุณาเข้าสู่ระบบ');
            }
        } else {
            http_response_code(404);
            die('ไม่พบข้อมูลไฟล์ในระบบ');
        }
    } else {
        // ตรวจสอบสิทธิ์ตามข้อมูลในฐานข้อมูล
        $canAccess = false;

        // ถ้าเป็น teacher สามารถเข้าถึงได้ทั้งหมด
        if ($_SESSION['user_role'] === 'teacher') {
            $canAccess = true;
        }
        // ถ้าเป็น student ตรวจสอบเพิ่มเติม
        else if ($_SESSION['user_role'] === 'student') {
            // สามารถเข้าถึงได้ถ้าเป็นเจ้าของข้อร้องเรียน
            if ($fileInfo['Stu_id'] === $user['Stu_id']) {
                $canAccess = true;
            }
            // หรือถ้าเป็นข้อร้องเรียนแบบระบุตัวตน (Re_iden = 0) ทุกคนดูได้
            else if ($fileInfo['Re_iden'] == 0) {
                $canAccess = true;
            }
        }

        if (!$canAccess) {
            http_response_code(403);
            die('คุณไม่มีสิทธิ์เข้าถึงไฟล์นี้');
        }
    }
} catch (Exception $e) {
    error_log("Download file error: " . $e->getMessage());
    // ในกรณีที่เกิดข้อผิดพลาดในการตรวจสอบฐานข้อมูล ให้อนุญาตดาวน์โหลดสำหรับผู้ที่ล็อกอินแล้ว
    if (!isLoggedIn()) {
        http_response_code(403);
        die('กรุณาเข้าสู่ระบบ');
    }
}

// กำหนด headers สำหรับการดาวน์โหลด
$fileSize = filesize($filePath);
$fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

// กำหนด MIME type
$mimeTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
    'txt' => 'text/plain',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed',
    '7z' => 'application/x-7z-compressed'
];

$mimeType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

// ล้าง output buffer ก่อนส่งไฟล์
if (ob_get_level()) {
    ob_end_clean();
}

// ส่ง headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: attachment; filename="' . basename($displayName) . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Expires: 0');

// ส่งไฟล์
if ($fileSize > 0) {
    $handle = fopen($filePath, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    } else {
        http_response_code(500);
        die('ไม่สามารถอ่านไฟล์ได้');
    }
} else {
    http_response_code(500);
    die('ไฟล์ว่างเปล่า');
}

// บันทึก log การดาวน์โหลด
if (function_exists('logActivity')) {
    logActivity(
        $user['Stu_id'] ?? $user['Aj_id'],
        'download_file',
        'Downloaded file: ' . $displayName,
        $fileInfo['Re_id'] ?? null
    );
}

exit;
