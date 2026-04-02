<?php
// staff/download.php - ระบบดาวน์โหลดไฟล์หลักฐาน
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// ตรวจสอบการล็อกอินและสิทธิ์
requireLogin();
requireRole(['teacher']);

$db = getDB();
$fileId = intval($_GET['file'] ?? 0);

if ($fileId <= 0) {
    http_response_code(400);
    die('Invalid file ID');
}

try {
    // ดึงข้อมูลไฟล์
    $file = $db->fetch("
        SELECT se.*, r.Stu_id, r.Re_iden
        FROM supporting_evidence se
        JOIN request r ON se.Re_id = r.Re_id
        WHERE se.Sup_id = ?
    ", [$fileId]);

    if (!$file) {
        http_response_code(404);
        die('File not found');
    }

    // ตรวจสอบว่าไฟล์มีอยู่จริงในระบบ
    if (!file_exists($file['Sup_filepath'])) {
        http_response_code(404);
        die('Physical file not found');
    }

    // ตรวจสอบสิทธิ์การเข้าถึง
    // เจ้าหน้าที่สามารถดาวน์โหลดไฟล์ได้ทั้งหมด
    if (!hasRole(['teacher'])) {
        http_response_code(403);
        die('Access denied');
    }

    // อ่านไฟล์และส่งให้ browser
    $filePath = $file['Sup_filepath'];
    $fileName = $file['Sup_filename'];
    $fileSize = $file['Sup_filesize'];
    $fileType = mime_content_type($filePath);

    // ตั้งค่า headers สำหรับการดาวน์โหลด
    header('Content-Type: ' . $fileType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private');
    header('Pragma: no-cache');
    header('Expires: 0');

    // ส่งไฟล์
    readfile($filePath);

    // บันทึก log การดาวน์โหลด
    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'],
            'download_file',
            'Downloaded file: ' . $fileName . ' (ID: ' . $fileId . ')',
            $file['Re_id']
        );
    }

    exit;
} catch (Exception $e) {
    error_log("Download error: " . $e->getMessage());
    http_response_code(500);
    die('Download failed');
}
