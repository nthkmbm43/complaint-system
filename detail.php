<?php
define('SECURE_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// ฟังก์ชัน helper สำหรับ footer (ทำงานโดยไม่ต้อง login)
function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function hasRole($roles)
{
    if (!isLoggedIn()) {
        return false;
    }

    $currentRole = $_SESSION['user_role'] ?? '';

    if (is_array($roles)) {
        return in_array($currentRole, $roles);
    }

    return $currentRole === $roles;
}

$db = getDB();
$error = '';

// ดึง ID ข้อร้องเรียนจาก URL
$requestId = $_GET['id'] ?? '';

if (empty($requestId) || !is_numeric($requestId)) {
    header('Location: tracking.php');
    exit;
}

// **แก้ไข: ฟังก์ชันดึงรายละเอียดข้อร้องเรียน - สำหรับการใช้งานสาธารณะ**
function getPublicRequestDetail($requestId)
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

        return $db->fetch($sql, [$requestId]);
    } catch (Exception $e) {
        error_log("getPublicRequestDetail error: " . $e->getMessage());
        return null;
    }
}

// **แก้ไข: ฟังก์ชันดึงไฟล์แนบ - สำหรับการใช้งานสาธารณะ**
function getPublicRequestFiles($requestId)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $sql = "SELECT se.*, s.Stu_name as uploader_name 
                FROM supporting_evidence se
                LEFT JOIN student s ON se.Sup_upload_by = s.Stu_id
                WHERE se.Re_id = ? 
                ORDER BY se.Sup_upload_date DESC";
        return $db->fetchAll($sql, [$requestId]);
    } catch (Exception $e) {
        error_log("getPublicRequestFiles error: " . $e->getMessage());
        return [];
    }
}

// **แก้ไข: ฟังก์ชันดึง timeline - สำหรับการใช้งานสาธารณะ**
function getPublicRequestTimeline($requestId)
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

            $timeline[] = [
                'title' => 'ยืนยันข้อร้องเรียน',
                'description' => 'เจ้าหน้าที่ได้รับและยืนยันข้อร้องเรียนแล้ว' .
                    ($saveRequest['Aj_name'] ? ' โดย ' . $saveRequest['Aj_name'] : ''),
                'date' => $saveRequest['Sv_date'] ?? $request['Re_date'],
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

            $timeline[] = [
                'title' => 'บันทึกผลการดำเนินงาน',
                'description' => 'เจ้าหน้าที่ได้ดำเนินการและบันทึกผลแล้ว' .
                    ($resultRequest['Aj_name'] ? ' โดย ' . $resultRequest['Aj_name'] : ''),
                'date' => $resultRequest['Result_date'] ?? date('Y-m-d'),
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
                'date' => $evaluation['created_at'] ?? date('Y-m-d'),
                'status' => 'completed',
                'icon' => '⭐'
            ];
        } else if ($request['Re_status'] == '2') {
            // รอประเมิน
            $timeline[] = [
                'title' => 'รอประเมินผลความพึงพอใจ',
                'description' => 'รอการประเมินความพึงพอใจในการให้บริการ',
                'date' => '',
                'status' => 'current',
                'icon' => '⏳'
            ];
        }

        return $timeline;
    } catch (Exception $e) {
        error_log("getPublicRequestTimeline error: " . $e->getMessage());
        return [];
    }
}

// ดึงข้อมูลข้อร้องเรียน
$request = getPublicRequestDetail($requestId);
if (!$request) {
    $error = 'ไม่พบข้อร้องเรียนที่ต้องการ';
}

// ดึงไฟล์แนบ
$files = $request ? getPublicRequestFiles($request['Re_id']) : [];

// ดึงประวัติการอัปเดต
$timeline = $request ? getPublicRequestTimeline($request['Re_id']) : [];

// ดึงข้อมูลการประเมิน (ถ้ามี)
$evaluation = null;
if ($request && $request['Re_status'] == '3') {
    try {
        $evaluation = $db->fetch("SELECT * FROM evaluation WHERE Re_id = ?", [$request['Re_id']]);
    } catch (Exception $e) {
        // ไม่ต้องทำอะไร
    }
}

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
        'uploads/requests/' . basename($filePath),
        'uploads/requests/images/' . basename($filePath),
        'uploads/' . basename($filePath),
        'uploads/evidence/' . basename($filePath)
    ];

    // หาไฟล์ที่มีอยู่จริง
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            // แปลง path ให้เป็น URL ที่ถูกต้อง
            if (strpos($path, 'uploads/') === 0) {
                return $path; // ถ้า path เริ่มต้นด้วย uploads/ แล้วให้ใช้เลย
            } else {
                // สร้าง relative path ใหม่
                $fileName = basename($path);
                return 'uploads/requests/' . $fileName;
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
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดข้อร้องเรียนสาธารณะ - <?php echo SITE_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Kanit', sans-serif;
        }

        body {
            background: #f8f9fa !important;
            min-height: 100vh;
            padding-top: 70px;
            color: #333 !important;
        }

        /* Top Header */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 20px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
        }

        .header-title {
            text-align: center;
        }

        .header-title h1 {
            font-size: 1.5rem;
            margin: 0;
            color: white;
            text-shadow: 1px 1px 5px rgba(0, 0, 0, 0.3);
        }

        .header-title p {
            margin: 0;
            font-size: 0.9rem;
            color: rgba(255, 255, 255, 0.9);
        }

        /* Main Content */
        .main-content {
            min-height: calc(100vh - 70px);
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(102, 126, 234, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .page-header h1 {
            color: white;
            font-size: 1.8rem;
            margin-bottom: 5px;
            text-shadow: 1px 1px 5px rgba(0, 0, 0, 0.3);
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Kanit', sans-serif;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-success {
            background: #28a745;
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-info {
            background: #17a2b8;
            color: white;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
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
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            border-left: 5px solid #ffd700;
        }

        .request-header {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e1e5e9;
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
            background: #fff3cd;
            color: #856404;
        }

        .badge-status-1 {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-status-2 {
            background: #d4edda;
            color: #155724;
        }

        .badge-status-3 {
            background: #cce7ff;
            color: #0066cc;
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
            background: #6c5ce7;
            color: white;
        }

        .request-description {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            line-height: 1.6;
            color: #333;
        }

        /* Staff Response */
        .staff-response {
            background: rgba(23, 162, 184, 0.1);
            border-left: 4px solid #17a2b8;
            padding: 20px;
            border-radius: 0 12px 12px 0;
            margin: 20px 0;
        }

        .staff-response h4 {
            color: #17a2b8;
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
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }

        .timeline-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e1e5e9;
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
            background: #ffd700;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #333;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .timeline-icon.completed {
            background: #28a745;
            color: white;
        }

        .timeline-icon.current {
            background: #ffc107;
            color: #333;
            animation: pulse 2s infinite;
        }

        .timeline-icon.pending {
            background: #6c757d;
            color: white;
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
            border-radius: 12px;
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
            background: white;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
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
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }

        .files-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e1e5e9;
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
            background: #f8f9fa;
            border-radius: 15px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .file-main {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .file-icon {
            width: 40px;
            height: 40px;
            background: #ffd700;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
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
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .image-preview img {
            width: 100%;
            max-width: 300px;
            height: auto;
            display: block;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .image-preview img:hover {
            transform: scale(1.02);
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
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
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
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
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
            border-radius: 12px;
            line-height: 1.6;
            color: #333;
            font-style: italic;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        /* Request Info Card */
        .request-info-card {
            background: white;
            padding: 20px;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }

        .request-info-card h3 {
            color: #333;
            margin-bottom: 15px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            display: inline-block;
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
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.5);
            border-radius: 12px;
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
            color: #666;
            line-height: 1.5;
        }

        .public-notice {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(76, 175, 80, 0.5);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .public-notice .icon {
            font-size: 2.5rem;
            flex-shrink: 0;
        }

        .public-notice .content h4 {
            margin: 0 0 8px 0;
            color: #28a745;
        }

        .public-notice .content p {
            margin: 0;
            color: #666;
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
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
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
                font-size: 1.2rem;
            }

            .header-title p {
                font-size: 0.8rem;
            }

            .main-content {
                padding: 15px;
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
        }

        @media (min-width: 1024px) {
            .pagination-controls {
                flex-wrap: nowrap;
            }

            .controls-group {
                flex-wrap: nowrap;
            }
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
    </style>
</head>

<body>
    <!-- Top Header -->
    <header class="top-header">
        <div class="header-title">
            <h1>🛠️ รายละเอียดข้อร้องเรียนสาธารณะ</h1>
            <p>ดูรายละเอียดและติดตามความคืบหน้าของข้อร้องเรียน</p>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>📄 รายละเอียดข้อร้องเรียน</h1>
                    <p>ดูรายละเอียดและติดตามความคืบหน้าของข้อร้องเรียน</p>
                    <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 8px;">
                        🌐 <strong>ข้อมูลสาธารณะ:</strong> แสดงรายละเอียดข้อร้องเรียนในระบบที่สามารถเข้าถึงได้
                    </div>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="tracking.php" class="btn btn-secondary">← กลับรายการ</a>
                    <a href="index.php" class="btn btn-primary">🏠 หน้าหลัก</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span>⛔</span>
                    <?php echo htmlspecialchars($error); ?>
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
                                <div class="request-title"><?php echo htmlspecialchars($request['Type_infor']); ?></div>

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
                                        <span class="meta-icon">📖</span>
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

                        <!-- **แก้ไข: Student Info Card - แสดงข้อมูลทั้งหมดสำหรับการใช้งานสาธารณะ** -->
                        <?php if ($request['Re_iden'] == 1): // โหมดไม่ระบุตัวตน 
                        ?>
                            <div class="request-info-card" style="border-left: 5px solid #ffc107;">
                                <h3>📖 ข้อมูลผู้ส่งข้อร้องเรียน</h3>
                                <div class="anonymous-notice">
                                    <span class="icon">🌐</span>
                                    <div class="content">
                                        <h4>ไม่ระบุตัวตน (แดชบอร์ดสาธารณะ)</h4>
                                        <p>
                                            ข้อร้องเรียนนี้ส่งมาในโหมดไม่ระบุตัวตน<br>
                                            ข้อมูลส่วนบุคคลของผู้ส่งจะไม่ถูกเปิดเผยในแดชบอร์ดสาธารณะนี้<br>
                                            เพื่อปกป้องความเป็นส่วนตัวของผู้ใช้บริการ
                                        </p>
                                    </div>
                                </div>
                            </div>
                        <?php else: // โหมดระบุตัวตน - แสดงข้อมูลได้ 
                        ?>
                            <?php if (isset($request['student_name']) && $request['student_name']): ?>
                                <div class="request-info-card">
                                    <h3>👨‍🎓 ข้อมูลผู้ส่งข้อร้องเรียน</h3>
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">ชื่อ-นามสกุล</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['student_name']); ?></span>
                                        </div>
                                        <div class="info-item">
                                            <span class="info-label">รหัสนักศึกษา</span>
                                            <span class="info-value"><?php echo htmlspecialchars($request['Stu_id']); ?></span>
                                        </div>
                                        <?php if (isset($request['faculty_name']) && $request['faculty_name']): ?>
                                            <div class="info-item">
                                                <span class="info-label">คณะ</span>
                                                <span class="info-value"><?php echo htmlspecialchars($request['faculty_icon'] . ' ' . $request['faculty_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($request['major_name']) && $request['major_name']): ?>
                                            <div class="info-item">
                                                <span class="info-label">สาขา</span>
                                                <span class="info-value"><?php echo htmlspecialchars($request['major_icon'] . ' ' . $request['major_name']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($request['student_tel']) && $request['student_tel']): ?>
                                            <div class="info-item">
                                                <span class="info-label">เบอร์ติดต่อ</span>
                                                <span class="info-value"><?php echo htmlspecialchars($request['student_tel']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (isset($request['student_email']) && $request['student_email']): ?>
                                            <div class="info-item">
                                                <span class="info-label">อีเมล</span>
                                                <span class="info-value"><?php echo htmlspecialchars($request['student_email']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Files Section - แสดงไฟล์แต่ปกปิดข้อมูลส่วนตัวในกรณีไม่ระบุตัวตน -->
                        <?php if (!empty($files)): ?>
                            <div class="files-section">
                                <div class="files-header">
                                    <h3>📎 ไฟล์แนบ (<?php echo count($files); ?> ไฟล์)</h3>
                                </div>

                                <div class="file-list">
                                    <?php foreach ($files as $file): ?>
                                        <div class="file-item">
                                            <div class="file-main">
                                                <div class="file-icon" style="background: <?php echo isImageFile($file['Sup_filetype']) ? '#28a745' : '#ffd700'; ?>">
                                                    <?php echo getFileIcon($file['Sup_filetype']); ?>
                                                </div>
                                                <div class="file-info">
                                                    <?php if ($request['Re_iden'] == 1): // ไม่ระบุตัวตน - ซ่อนชื่อไฟล์ 
                                                    ?>
                                                        <div class="file-name">ไฟล์แนบหลักฐาน</div>
                                                        <div class="file-meta">
                                                            ประเภท: <?php echo strtoupper($file['Sup_filetype']); ?> |
                                                            ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?> |
                                                            อัพโหลดเมื่อ: <?php echo formatThaiDateTime($file['Sup_upload_date']); ?>
                                                        </div>
                                                    <?php else: // ระบุตัวตน - แสดงข้อมูลทั้งหมด 
                                                    ?>
                                                        <div class="file-name"><?php echo htmlspecialchars($file['Sup_filename']); ?></div>
                                                        <div class="file-meta">
                                                            ประเภท: <?php echo strtoupper($file['Sup_filetype']); ?> |
                                                            ขนาด: <?php echo formatFileSize($file['Sup_filesize']); ?> |
                                                            อัพโหลดเมื่อ: <?php echo formatThaiDateTime($file['Sup_upload_date']); ?>
                                                            <?php if ($file['uploader_name']): ?>
                                                                | โดย: <?php echo htmlspecialchars($file['uploader_name']); ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
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

                                            <!-- แสดงรูปภาพ Preview สำหรับไฟล์รูปภาพ (ทั้งกรณีระบุและไม่ระบุตัวตน) -->
                                            <?php if (isImageFile($file['Sup_filetype'])): ?>
                                                <?php
                                                $imageUrl = getImageDisplayUrl($file['Sup_filepath']);
                                                if ($imageUrl):
                                                ?>
                                                    <div class="image-preview">
                                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>"
                                                            alt="<?php echo $request['Re_iden'] == 1 ? 'ไฟล์แนบหลักฐาน' : htmlspecialchars($file['Sup_filename']); ?>"
                                                            onclick="openImageModal(this.src, '<?php echo $request['Re_iden'] == 1 ? 'ไฟล์แนบหลักฐาน' : htmlspecialchars($file['Sup_filename']); ?>')"
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

                        <!-- Action Buttons - แสดงเฉพาะปุ่มที่เกี่ยวข้องกับการดู -->
                        <div class="action-buttons">
                            <h3>🛠️ การดำเนินการ</h3>
                            <div class="actions-grid">
                                <div style="background: rgba(23, 162, 184, 0.1); padding: 15px; border-radius: 12px; margin-bottom: 15px; text-align: center; border: 1px solid rgba(23, 162, 184, 0.3);">
                                    <p style="margin: 0; color: #333; font-size: 14px;">
                                        <strong>ℹ️ แดชบอร์ดสาธารณะ</strong><br>
                                        คุณกำลังดูข้อมูลในโหมดอ่านอย่างเดียว<br>
                                        ไม่สามารถแก้ไขหรือดำเนินการใดๆ ได้
                                    </p>
                                </div>

                                <button class="btn btn-info" onclick="printRequest()">🖨️ พิมพ์รายละเอียด</button>

                                <a href="tracking.php" class="btn btn-secondary">📋 กลับรายการทั้งหมด</a>

                                <a href="index.php" class="btn btn-primary">🏠 หน้าหลัก</a>
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

    <script>
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
                    <title>รายละเอียดข้อร้องเรียนสาธารณะ #CR<?php echo str_pad($request['Re_id'] ?? '', 6, '0', STR_PAD_LEFT); ?></title>
                    <style>
                        body { font-family: 'Kanit', Arial, sans-serif; margin: 20px; line-height: 1.6; color: #333; }
                        .request-detail-card, .files-section, .evaluation-display { 
                            margin-bottom: 20px; 
                            padding: 20px; 
                            border: 1px solid #ddd; 
                            border-radius: 8px; 
                            background: #f9f9f9;
                        }
                        .request-badges .badge { 
                            display: inline-block; 
                            padding: 4px 8px; 
                            margin-right: 5px; 
                            border-radius: 4px; 
                            font-size: 12px;
                            background: #e9ecef;
                            color: #333;
                            border: 1px solid #ced4da;
                        }
                        .file-item { 
                            border: 1px solid #eee; 
                            padding: 10px; 
                            margin: 5px 0; 
                            border-radius: 4px; 
                            background: #fff;
                        }
                        .image-preview img {
                            max-width: 200px; 
                            height: auto; 
                            border: 1px solid #ddd; 
                            border-radius: 4px;
                        }
                        @media print {
                            .file-actions { display: none; }
                            body { color: #000; }
                        }
                    </style>
                </head>
                <body>
                    <h1>รายละเอียดข้อร้องเรียนสาธารณะ</h1>
                    <p>พิมพ์เมื่อ: ${new Date().toLocaleString('th-TH')}</p>
                    <hr>
                    ${printContent}
                </body>
                </html>
            `);

            printWindow.document.close();
            printWindow.print();
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
    </script>

    <?php include 'includes/footer.php'; ?>
</body>

</html>