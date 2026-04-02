<?php
// complaint-detail.php - หน้าแสดงรายละเอียดข้อร้องเรียน
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireRole(['teacher']);

$db = getDB();
$user = getCurrentUser();
$complaintId = intval($_GET['id'] ?? 0);

if ($complaintId <= 0) {
    header('Location: manage-complaints.php?message=invalid_id');
    exit;
}

// ========================
// SECTION: Data Fetching
// ========================

function getComplaintData($db, $complaintId)
{
    $sql = "SELECT r.*, t.Type_infor, t.Type_icon, 
                   CASE r.Re_iden 
                       WHEN 1 THEN 'ไม่ระบุตัวตน' 
                       ELSE s.Stu_name 
                   END as requester_name,
                   s.Stu_id, s.Stu_name, s.Stu_tel, s.Stu_email,
                   major.Unit_name as major_name, major.Unit_icon as major_icon,
                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                   asn.Aj_name as assigned_name, asn.Aj_position as assigned_position,
                   e.Eva_score, e.Eva_sug as evaluation_comment, e.created_at as evaluation_date
            FROM request r 
            LEFT JOIN type t ON r.Type_id = t.Type_id 
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            LEFT JOIN teacher asn ON r.Aj_id = asn.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            WHERE r.Re_id = ?";

    return $db->fetch($sql, [$complaintId]);
}

/**
 * ดึงข้อมูลสถานะการดำเนินการแบบ Summary (เฉพาะครั้งล่าสุดของแต่ละสถานะ)
 */
function getStatusTimeline($db, $complaintId, $complaint)
{
    $timeline = [];

    // ========================================
    // สถานะ 0: ยื่นคำร้อง
    // ========================================
    $timeline['step0'] = [
        'step' => 0,
        'title' => 'ยื่นคำร้อง',
        'icon' => '📝',
        'color' => 'submitted',
        'date' => $complaint['Re_date'],
        'is_anonymous' => ($complaint['Re_iden'] == 1),
        'requester_name' => $complaint['Stu_name'] ?? null,
        'requester_id' => $complaint['Stu_id'] ?? null,
        'completed' => true
    ];

    // ========================================
    // สถานะ 1: รับเรื่อง (ดึงครั้งล่าสุด)
    // ========================================
    $receiveData = $db->fetch("
        SELECT sr.*, aj.Aj_name, aj.Aj_position
        FROM save_request sr
        JOIN teacher aj ON sr.Aj_id = aj.Aj_id
        WHERE sr.Re_id = ? AND sr.Sv_type = 'receive'
        ORDER BY sr.created_at DESC
        LIMIT 1
    ", [$complaintId]);

    $timeline['step1'] = [
        'step' => 1,
        'title' => $receiveData ? 'รับเรื่องแล้ว' : 'รอรับเรื่อง',
        'icon' => $receiveData ? '✅' : '⏳',
        'color' => $receiveData ? 'received' : 'pending',
        'date' => $receiveData['created_at'] ?? null,
        'staff_name' => $receiveData['Aj_name'] ?? null,
        'staff_position' => $receiveData['Aj_position'] ?? null,
        'note' => $receiveData['Sv_note'] ?? null,
        'completed' => ($complaint['Re_status'] >= 1 && $complaint['Re_status'] != 4)
    ];

    // ========================================
    // ตรวจสอบการปฏิเสธ (ดึงครั้งล่าสุด)
    // ========================================
    if ($complaint['Re_status'] == '4') {
        $rejectData = $db->fetch("
            SELECT sr.*, aj.Aj_name, aj.Aj_position
            FROM save_request sr
            JOIN teacher aj ON sr.Aj_id = aj.Aj_id
            WHERE sr.Re_id = ? AND sr.Sv_type = 'reject'
            ORDER BY sr.created_at DESC
            LIMIT 1
        ", [$complaintId]);

        if ($rejectData) {
            $timeline['rejected'] = [
                'step' => 'rejected',
                'title' => 'ปฏิเสธคำร้อง',
                'icon' => '❌',
                'color' => 'rejected',
                'date' => $rejectData['created_at'],
                'staff_name' => $rejectData['Aj_name'],
                'staff_position' => $rejectData['Aj_position'],
                'reason' => $rejectData['Sv_infor'],
                'note' => $rejectData['Sv_note'],
                'completed' => true
            ];
        }
    }

    // ========================================
    // สถานะ 2: ดำเนินการ/รอประเมิน (ดึงครั้งล่าสุด)
    // ========================================
    if ($complaint['Re_status'] != '4') {
        $processData = $db->fetch("
            SELECT sr.*, aj.Aj_name, aj.Aj_position
            FROM save_request sr
            JOIN teacher aj ON sr.Aj_id = aj.Aj_id
            WHERE sr.Re_id = ? AND sr.Sv_type = 'process'
            ORDER BY sr.created_at DESC
            LIMIT 1
        ", [$complaintId]);

        $timeline['step2'] = [
            'step' => 2,
            'title' => $processData ? 'ดำเนินการเสร็จสิ้น - รอประเมิน' : 'รอดำเนินการ',
            'icon' => $processData ? '⭐' : '⏳',
            'color' => $processData ? 'processed' : 'pending',
            'date' => $processData['created_at'] ?? null,
            'staff_name' => $processData['Aj_name'] ?? null,
            'staff_position' => $processData['Aj_position'] ?? null,
            'detail' => $processData['Sv_note'] ?? null,
            'completed' => ($complaint['Re_status'] >= 2)
        ];
    }

    // ========================================
    // สถานะ 3: เสร็จสิ้น/ประเมินแล้ว
    // ========================================
    if ($complaint['Re_status'] == '3') {
        $evalData = $db->fetch("
            SELECT e.*, s.Stu_name
            FROM evaluation e
            LEFT JOIN request r ON e.Re_id = r.Re_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            WHERE e.Re_id = ?
            ORDER BY e.created_at DESC
            LIMIT 1
        ", [$complaintId]);

        $timeline['step3'] = [
            'step' => 3,
            'title' => $evalData ? 'เสร็จสิ้น (ประเมินแล้ว)' : 'เสร็จสิ้น',
            'icon' => '🏆',
            'color' => 'completed',
            'date' => $evalData['created_at'] ?? null,
            'score' => $evalData['Eva_score'] ?? null,
            'comment' => $evalData['Eva_sug'] ?? null,
            'evaluator_name' => $evalData['Stu_name'] ?? null,
            'is_anonymous' => ($complaint['Re_iden'] == 1),
            'completed' => true
        ];
    } elseif ($complaint['Re_status'] != '4') {
        // ยังไม่เสร็จสิ้น
        $timeline['step3'] = [
            'step' => 3,
            'title' => 'รอเสร็จสิ้น',
            'icon' => '🏁',
            'color' => 'pending',
            'completed' => false
        ];
    }

    return $timeline;
}

function getEvidenceFiles($db, $complaintId)
{
    return $db->fetchAll("
        SELECT se.*, 
               s.Stu_name as uploader_name, 
               aj.Aj_name as teacher_uploader_name,
               aj.Aj_position as teacher_position,
               CASE 
                   WHEN se.Aj_id IS NOT NULL THEN 'teacher'
                   ELSE 'student'
               END as uploader_type
        FROM supporting_evidence se
        LEFT JOIN student s ON se.Sup_upload_by = s.Stu_id
        LEFT JOIN teacher aj ON se.Aj_id = aj.Aj_id
        WHERE se.Re_id = ? 
        ORDER BY se.Sup_upload_date DESC
    ", [$complaintId]);
}

/**
 * แยกไฟล์ตามผู้อัปโหลด (นักศึกษา vs อาจารย์)
 */
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

// ========================
// SECTION: Helper Functions
// ========================

function getFileIcon($extension)
{
    $icons = [
        'pdf' => '📄',
        'doc' => '📝',
        'docx' => '📝',
        'xls' => '📊',
        'xlsx' => '📊',
        'jpg' => '🖼️',
        'jpeg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🖼️'
    ];
    return $icons[strtolower($extension)] ?? '📎';
}

function isImageFile($extension)
{
    return in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
}

function getCorrectFilePath($filepath)
{
    return (strpos($filepath, '../') === false) ? '../' . $filepath : $filepath;
}

function getStatusBadgeHtml($status)
{
    $badges = [
        '0' => ['class' => 'secondary', 'text' => 'ยื่นคำร้อง', 'icon' => '📝'],
        '1' => ['class' => 'info', 'text' => 'กำลังดำเนินการ', 'icon' => '⏳'],
        '2' => ['class' => 'warning', 'text' => 'รอการประเมินผล', 'icon' => '⭐'],
        '3' => ['class' => 'success', 'text' => 'เสร็จสิ้น', 'icon' => '✅'],
        '4' => ['class' => 'danger', 'text' => 'ปฏิเสธคำร้อง', 'icon' => '❌']
    ];
    $b = $badges[$status] ?? ['class' => 'secondary', 'text' => 'ไม่ทราบสถานะ', 'icon' => '❓'];
    return "<span class=\"badge {$b['class']}\">{$b['icon']} {$b['text']}</span>";
}

function getPriorityBadgeHtmlLocal($level)
{
    $levels = [
        '0' => ['class' => 'secondary', 'text' => 'รอพิจารณา'],
        '1' => ['class' => 'success', 'text' => 'ไม่เร่งด่วน'],
        '2' => ['class' => 'info', 'text' => 'ปกติ'],
        '3' => ['class' => 'warning', 'text' => 'เร่งด่วน'],
        '4' => ['class' => 'danger', 'text' => 'เร่งด่วนมาก'],
        '5' => ['class' => 'danger', 'text' => 'วิกฤต/ฉุกเฉิน']
    ];
    $l = $levels[$level] ?? ['class' => 'secondary', 'text' => 'ไม่ระบุ'];
    return "<span class=\"badge {$l['class']}\">{$l['text']}</span>";
}

// ========================
// SECTION: Load Data
// ========================

$complaint = null;
$statusTimeline = [];
$evidenceFiles = [];

try {
    $complaint = getComplaintData($db, $complaintId);
    if (!$complaint) {
        header('Location: manage-complaints.php?message=not_found');
        exit;
    }
    $statusTimeline = getStatusTimeline($db, $complaintId, $complaint);
    $evidenceFiles = getEvidenceFiles($db, $complaintId);

    // แยกไฟล์ตามผู้อัปโหลด
    $separatedFiles = separateFilesByUploader($evidenceFiles);
    $studentFiles = $separatedFiles['student_files'];
    $teacherFiles = $separatedFiles['teacher_files'];
} catch (Exception $e) {
    error_log("Complaint detail error: " . $e->getMessage());
    header('Location: manage-complaints.php?message=error');
    exit;
}

$isAdmin = ($_SESSION['permission'] ?? 0) == 3;
$isPending = ($complaint['Re_status'] == '0');
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายละเอียดข้อร้องเรียน #<?php echo $complaintId; ?></title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <style>
        /* ========================
           Floating Back Button
           ======================== */
        .floating-back-btn {
            position: fixed;
            top: 85px;
            right: 50px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .floating-back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
            color: white;
        }

        /* ========================
           Action Buttons Section
           ======================== */
        .action-buttons-section {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.2);
        }

        .action-buttons-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .action-buttons-header h3 {
            margin: 0;
            color: #856404;
            font-size: 1.2rem;
        }

        .action-buttons-desc {
            color: #856404;
            margin-bottom: 20px;
        }

        .action-buttons-container {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-action-accept,
        .btn-action-reject {
            border: none;
            padding: 15px 30px;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-action-accept {
            background: linear-gradient(135deg, #28a745, #20c997);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-action-reject {
            background: linear-gradient(135deg, #dc3545, #c82333);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-action-accept:hover,
        .btn-action-reject:hover {
            transform: translateY(-3px);
        }

        /* ========================
           Status Steps Timeline (NEW DESIGN)
           ======================== */
        .status-steps {
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
        }

        .status-step {
            display: flex;
            gap: 20px;
            position: relative;
            padding-bottom: 30px;
        }

        .status-step:last-child {
            padding-bottom: 0;
        }

        /* Connector Line */
        .status-step:not(:last-child)::before {
            content: '';
            position: absolute;
            left: 24px;
            top: 50px;
            bottom: 0;
            width: 4px;
            background: #e9ecef;
            border-radius: 2px;
        }

        .status-step.completed:not(:last-child)::before {
            background: linear-gradient(180deg, #28a745, #20c997);
        }

        .status-step.rejected:not(:last-child)::before {
            background: linear-gradient(180deg, #dc3545, #f8d7da);
        }

        /* Step Number/Icon Circle */
        .step-indicator {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
            z-index: 1;
            border: 4px solid #e9ecef;
            background: white;
            transition: all 0.3s ease;
        }

        .status-step.completed .step-indicator {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-color: #28a745;
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .status-step.rejected .step-indicator {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-color: #dc3545;
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .status-step.pending .step-indicator {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #adb5bd;
        }

        /* Step Content */
        .step-content {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .status-step.completed .step-content {
            border-left: 4px solid #28a745;
            background: linear-gradient(135deg, #f8fff9, #fff);
        }

        .status-step.rejected .step-content {
            border-left: 4px solid #dc3545;
            background: linear-gradient(135deg, #fff8f8, #fff);
        }

        .status-step.pending .step-content {
            opacity: 0.6;
            border-left: 4px solid #dee2e6;
        }

        .step-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .step-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .step-title .step-number {
            background: #667eea;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }

        .step-date {
            font-size: 0.85rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .step-body {
            color: #555;
        }

        .step-info-box {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }

        .step-info-row {
            display: flex;
            margin-bottom: 10px;
            align-items: flex-start;
        }

        .step-info-row:last-child {
            margin-bottom: 0;
        }

        .step-info-label {
            color: #6c757d;
            font-size: 0.9rem;
            min-width: 100px;
            flex-shrink: 0;
        }

        .step-info-value {
            color: #333;
            font-weight: 500;
            flex: 1;
        }

        .step-note {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 12px 15px;
            margin-top: 12px;
            color: #856404;
            font-size: 0.9rem;
        }

        .step-note.reject-reason {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }

        /* Rating Stars */
        .rating-display {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rating-stars {
            display: flex;
            gap: 2px;
        }

        .rating-stars .star {
            font-size: 1.2rem;
        }

        .rating-stars .star.empty {
            opacity: 0.3;
        }

        .rating-score {
            font-size: 1.1rem;
            font-weight: 700;
            color: #ffc107;
        }

        /* Pending Message */
        .pending-message {
            color: #6c757d;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ========================
           Modal Styles
           ======================== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .modal-container {
            background: white;
            border-radius: 15px;
            width: 95%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header.reject-header {
            background: linear-gradient(135deg, #ff6b6b, #ee5a5a);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .modal-header.accept-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8f9fa;
            border-radius: 0 0 15px 15px;
        }

        .modal-form-group {
            margin-bottom: 20px;
        }

        .modal-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .modal-form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
            font-family: inherit;
            transition: border-color 0.3s;
        }

        .modal-form-control:focus {
            outline: none;
            border-color: #667eea;
        }

        .required {
            color: #dc3545;
        }

        .modal-btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .modal-btn-secondary {
            background: #dc3545;
            color: white;
        }

        .modal-btn-secondary:hover {
            background: #c82333;
        }

        .modal-btn-danger {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .modal-btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .modal-btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .modal-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            background: #e8f5e9;
            border-radius: 8px;
            border: 1px solid #c8e6c9;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-group label {
            margin: 0 !important;
            cursor: pointer;
            font-weight: 500 !important;
        }

        .complaint-preview {
            background: linear-gradient(135deg, #f8f9fa, #fff);
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .complaint-preview-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #dee2e6;
        }

        .complaint-preview-id {
            font-size: 1.2rem;
            font-weight: bold;
            color: #667eea;
        }

        .complaint-preview-date {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .complaint-preview-content {
            color: #495057;
            line-height: 1.6;
        }

        .email-notice {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 10px 15px;
            margin-top: 15px;
            font-size: 0.9rem;
            color: #856404;
        }

        .email-notice.no-email {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }

        .info-box-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 15px;
            color: #155724;
        }

        .info-box-success ul {
            margin: 10px 0 0 20px;
            padding: 0;
        }

        /* Notification Toast */
        .notification {
            position: fixed;
            top: 100px;
            right: 20px;
            background: white;
            padding: 20px 25px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            animation: slideInRight 0.4s ease;
            max-width: 400px;
        }

        .notification.success {
            border-left: 5px solid #28a745;
        }

        .notification.error {
            border-left: 5px solid #dc3545;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* ========================
           Layout & Card Styles
           ======================== */
        .main-content {
            padding-top: 70px;
        }

        .page-content {
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 25px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-header-icon {
            font-size: 1.5rem;
        }

        .card-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.2rem;
        }

        /* Complaint Main Card */
        .complaint-main-card {
            overflow: hidden;
        }

        .complaint-title-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: -25px -25px 0 -25px;
            padding: 25px;
            color: white;
        }

        .complaint-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
        }

        .complaint-title-left {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            flex: 1;
        }

        .complaint-type-icon {
            font-size: 2.5rem;
            background: rgba(255, 255, 255, 0.2);
            padding: 15px;
            border-radius: 12px;
            line-height: 1;
        }

        .complaint-title-info {
            flex: 1;
        }

        .complaint-main-title {
            margin: 0 0 12px 0;
            font-size: 1.5rem;
            font-weight: 600;
            line-height: 1.3;
        }

        .complaint-meta-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .meta-tag {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.85rem;
        }

        .complaint-id-badge {
            background: rgba(255, 255, 255, 0.25);
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .complaint-details-section {
            padding-top: 25px;
        }

        .complaint-two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .quick-info-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f0f4ff;
            border-radius: 10px;
        }

        .quick-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .quick-label {
            color: #666;
        }

        .quick-value {
            font-weight: 500;
            color: #333;
        }

        .content-label {
            margin: 0 0 12px 0;
            color: #333;
            font-size: 1rem;
            font-weight: 600;
        }

        .content-text {
            background: #fafafa;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
            line-height: 1.8;
            color: #444;
            min-height: 150px;
        }

        .images-label {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 1rem;
            font-weight: 600;
        }

        .complaint-images-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .complaint-image-item {
            position: relative;
            border-radius: 10px;
            overflow: hidden;
            cursor: pointer;
            aspect-ratio: 4/3;
            background: #f0f0f0;
        }

        .complaint-image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .complaint-image-item:hover img {
            transform: scale(1.05);
        }

        .complaint-image-item .image-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.7));
            color: white;
            padding: 30px 10px 10px;
            text-align: center;
            font-size: 0.85rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .complaint-image-item:hover .image-overlay {
            opacity: 1;
        }

        .no-images {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 10px;
            padding: 40px 20px;
            color: #999;
            min-height: 200px;
        }

        .no-images-icon {
            font-size: 3rem;
            opacity: 0.5;
            margin-bottom: 10px;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .info-icon {
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .info-label {
            font-size: 0.85rem;
            color: #666;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1rem;
            color: #333;
            font-weight: 500;
        }

        /* Anonymous Notice */
        .anonymous-notice {
            display: flex;
            align-items: center;
            gap: 20px;
            background: linear-gradient(135deg, #f8f9fa, #fff);
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 30px;
        }

        .anonymous-icon {
            font-size: 3rem;
            opacity: 0.7;
        }

        .anonymous-text h4 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .anonymous-text p {
            margin: 0;
            color: #666;
        }

        /* Evidence Grid */
        .evidence-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }

        .evidence-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border: 1px solid #e9ecef;
        }

        .evidence-preview {
            display: flex;
            justify-content: center;
            margin-bottom: 12px;
        }

        .evidence-file-icon {
            font-size: 3rem;
        }

        .evidence-info {
            text-align: center;
        }

        .evidence-filename {
            font-weight: 500;
            margin-bottom: 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .evidence-meta {
            font-size: 0.85rem;
            color: #666;
            display: flex;
            justify-content: space-between;
        }

        .evidence-download {
            color: #667eea;
            text-decoration: none;
        }

        .evidence-download:hover {
            text-decoration: underline;
        }

        /* No Data */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .no-data-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.95);
        }

        .modal-close-btn {
            position: absolute;
            top: 20px;
            right: 35px;
            color: #f1f1f1;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 10001;
        }

        .modal-content-img {
            margin: auto;
            display: block;
            max-width: 90%;
            max-height: 85vh;
            margin-top: 50px;
            cursor: zoom-in;
        }

        .modal-content-img.zoomed {
            max-width: none;
            max-height: none;
            cursor: zoom-out;
        }

        #imgCaption {
            text-align: center;
            color: #ccc;
            padding: 10px 20px;
        }

        /* ========================
           Staff Files Section Styles
           ======================== */
        .images-section-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #495057;
            margin: 15px 0 10px 0;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .images-section-label.staff-section-label {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .staff-completed-badge-small {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: auto;
        }

        .image-uploader-badge {
            position: absolute;
            bottom: 5px;
            left: 5px;
            right: 5px;
            background: rgba(40, 167, 69, 0.9);
            color: white;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: 500;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .complaint-image-item {
            position: relative;
        }

        .staff-files-card-header {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        .staff-completed-badge-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: auto;
            animation: completedPulse 2s ease-in-out infinite;
        }

        @keyframes completedPulse {

            0%,
            100% {
                transform: scale(1);
                box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
            }

            50% {
                transform: scale(1.02);
                box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
            }
        }

        .evidence-uploader {
            margin-top: 8px;
            padding: 5px 10px;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 500;
            display: inline-block;
        }

        /* Responsive */
        @media (max-width: 900px) {
            .complaint-two-column {
                grid-template-columns: 1fr;
            }

            .complaint-title-row {
                flex-direction: column;
            }
        }

        @media (max-width: 600px) {
            .floating-back-btn {
                right: 20px;
                top: 75px;
                padding: 10px 15px;
                font-size: 0.85rem;
            }

            .action-buttons-container {
                flex-direction: column;
            }

            .btn-action-accept,
            .btn-action-reject {
                width: 100%;
                justify-content: center;
            }

            .complaint-title-left {
                flex-direction: column;
            }
        }
    </style>
</head>

<body class="staff-layout">
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>

    <a href="<?php echo $isAdmin ? 'manage-complaints.php' : 'my-assignments.php'; ?>" class="floating-back-btn">
    	<span>←</span> กลับไปยังรายการ
	</a>

    <div class="main-content" id="mainContent">
        <div class="page-content">

            <?php if ($isPending): ?>
                <!-- ส่วนปุ่มดำเนินการ -->
                <div class="action-buttons-section">
                    <div class="action-buttons-header">
                        <span class="icon">⚡</span>
                        <h3>ดำเนินการกับข้อร้องเรียนนี้</h3>
                    </div>
                    <p class="action-buttons-desc">
                        ข้อร้องเรียนนี้ยัง<strong>รอการยืนยัน</strong> กรุณาตรวจสอบข้อมูลและเลือกดำเนินการ
                    </p>
                    <div class="action-buttons-container">
                        <button type="button" class="btn-action-accept" onclick="openAcceptModal()">
                            <span>✅</span> รับเรื่องข้อร้องเรียน
                        </button>
                        <button type="button" class="btn-action-reject" onclick="openRejectModal()">
                            <span>❌</span> ปฏิเสธข้อร้องเรียน
                        </button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Card หลัก -->
            <div class="card complaint-main-card">
                <div class="complaint-title-section">
                    <div class="complaint-title-row">
                        <div class="complaint-title-left">
                            <span class="complaint-type-icon">
                                <?php echo $complaint['Type_icon'] ?: '📋'; ?>
                            </span>
                            <div class="complaint-title-info">
                                <h2 class="complaint-main-title">
                                    <?php echo !empty($complaint['Re_title']) ? htmlspecialchars($complaint['Re_title']) : 'ข้อร้องเรียน #' . $complaintId; ?>
                                </h2>
                                <div class="complaint-meta-badges">
                                    <?php echo getStatusBadgeHtml($complaint['Re_status']); ?>
                                    <?php
                                    if (function_exists('getPriorityBadgeClass') && function_exists('getPriorityText')) {
                                        echo '<span class="badge ' . getPriorityBadgeClass($complaint['Re_level']) . '">' . getPriorityText($complaint['Re_level']) . '</span>';
                                    } else {
                                        echo getPriorityBadgeHtmlLocal($complaint['Re_level']);
                                    }
                                    ?>
                                    <span class="meta-tag">
                                        <?php echo $complaint['Re_iden'] == 1 ? '🕶️ ไม่ระบุตัวตน' : '👤 ระบุตัวตน'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="complaint-title-right">
                            <div class="complaint-id-badge">#<?php echo $complaintId; ?></div>
                        </div>
                    </div>
                </div>

                <div class="complaint-details-section">
                    <div class="complaint-two-column">
                        <div class="complaint-left-col">
                            <div class="quick-info-bar">
                                <div class="quick-info-item">
                                    <span class="quick-icon">📅</span>
                                    <span class="quick-label">วันที่:</span>
                                    <span class="quick-value"><?php echo formatThaiDateTime($complaint['Re_date']); ?></span>
                                </div>
                                <div class="quick-info-item">
                                    <span class="quick-icon">📁</span>
                                    <span class="quick-label">ประเภท:</span>
                                    <span class="quick-value"><?php echo htmlspecialchars($complaint['Type_infor']); ?></span>
                                </div>
                            </div>

                            <div class="complaint-content-box">
                                <h4 class="content-label">📄 รายละเอียดข้อร้องเรียน</h4>
                                <div class="content-text">
                                    <?php echo nl2br(htmlspecialchars($complaint['Re_infor'])); ?>
                                </div>
                            </div>
                        </div>

                        <div class="complaint-right-col">
                            <h4 class="images-label">🖼️ ภาพประกอบ</h4>
                            <?php
                            // แยกรูปภาพตามผู้อัปโหลด
                            $studentImageFiles = array_filter($studentFiles, function ($file) {
                                return isImageFile($file['Sup_filetype']);
                            });
                            $teacherImageFiles = array_filter($teacherFiles, function ($file) {
                                return isImageFile($file['Sup_filetype']);
                            });
                            ?>

                            <?php if (!empty($studentImageFiles) || !empty($teacherImageFiles)): ?>
                                <!-- รูปภาพจากผู้ร้องเรียน -->
                                <?php if (!empty($studentImageFiles)): ?>
                                    <div class="images-section-label">📎 จากผู้ร้องเรียน (<?php echo count($studentImageFiles); ?> รูป)</div>
                                    <div class="complaint-images-grid">
                                        <?php foreach ($studentImageFiles as $file): ?>
                                            <?php
                                            $imagePath = '../uploads/requests/images/' . basename($file['Sup_filepath']);
                                            if (!file_exists($imagePath)) {
                                                $imagePath = getCorrectFilePath($file['Sup_filepath']);
                                            }
                                            ?>
                                            <?php if (file_exists($imagePath)): ?>
                                                <div class="complaint-image-item" onclick="openImageModal('<?php echo htmlspecialchars($imagePath); ?>', '<?php echo htmlspecialchars($file['Sup_filename']); ?>')">
                                                    <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($file['Sup_filename']); ?>">
                                                    <div class="image-overlay"><span>🔍 ดูภาพขยาย</span></div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- รูปภาพจากเจ้าหน้าที่ -->
                                <?php if (!empty($teacherImageFiles)): ?>
                                    <div class="images-section-label staff-section-label">
                                        ✅ จากเจ้าหน้าที่ (<?php echo count($teacherImageFiles); ?> รูป)
                                        <span class="staff-completed-badge-small">🎉 ดำเนินการเสร็จสิ้น</span>
                                    </div>
                                    <div class="complaint-images-grid">
                                        <?php foreach ($teacherImageFiles as $file): ?>
                                            <?php
                                            $imagePath = '../uploads/requests/images/' . basename($file['Sup_filepath']);
                                            if (!file_exists($imagePath)) {
                                                $imagePath = getCorrectFilePath($file['Sup_filepath']);
                                            }
                                            ?>
                                            <?php if (file_exists($imagePath)): ?>
                                                <div class="complaint-image-item" onclick="openImageModal('<?php echo htmlspecialchars($imagePath); ?>', '<?php echo htmlspecialchars($file['Sup_filename']); ?>')">
                                                    <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="<?php echo htmlspecialchars($file['Sup_filename']); ?>">
                                                    <div class="image-overlay"><span>🔍 ดูภาพขยาย</span></div>
                                                    <div class="image-uploader-badge">
                                                        👨‍🏫 <?php echo htmlspecialchars($file['teacher_uploader_name'] ?? 'เจ้าหน้าที่'); ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="no-images">
                                    <span class="no-images-icon">🖼️</span>
                                    <p>ไม่มีภาพประกอบ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ========================================
                 สถานะการดำเนินการ (Timeline แบบใหม่)
                 ======================================== -->
            <div class="card">
                <div class="card-header">
                    <span class="card-header-icon">⏱️</span>
                    <h3>สถานะการดำเนินการ</h3>
                </div>

                <div class="status-steps">
                    <!-- ขั้นตอนที่ 0: ยื่นคำร้อง -->
                    <?php if (isset($statusTimeline['step0'])): $item = $statusTimeline['step0']; ?>
                        <div class="status-step <?php echo $item['completed'] ? 'completed' : 'pending'; ?>">
                            <div class="step-indicator"><?php echo $item['icon']; ?></div>
                            <div class="step-content">
                                <div class="step-header">
                                    <h4 class="step-title">
                                        <span class="step-number">0</span>
                                        <?php echo $item['title']; ?>
                                    </h4>
                                    <span class="step-date">📅 <?php echo formatThaiDateTime($item['date']); ?></span>
                                </div>
                                <div class="step-body">
                                    <div class="step-info-box">
                                        <div class="step-info-row">
                                            <span class="step-info-label">ผู้ร้องเรียน:</span>
                                            <span class="step-info-value">
                                                <?php if ($item['is_anonymous']): ?>
                                                    🕶️ ไม่ระบุตัวตน
                                                <?php else: ?>
                                                    👤 <?php echo htmlspecialchars($item['requester_name'] ?? 'ไม่ระบุ'); ?>
                                                    <?php if (!empty($item['requester_id'])): ?>
                                                        <small style="color: #6c757d;">(<?php echo htmlspecialchars($item['requester_id']); ?>)</small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ขั้นตอนที่ 1: รับเรื่อง -->
                    <?php if (isset($statusTimeline['step1'])): $item = $statusTimeline['step1']; ?>
                        <div class="status-step <?php echo $item['completed'] ? 'completed' : 'pending'; ?>">
                            <div class="step-indicator"><?php echo $item['icon']; ?></div>
                            <div class="step-content">
                                <div class="step-header">
                                    <h4 class="step-title">
                                        <span class="step-number">1</span>
                                        <?php echo $item['title']; ?>
                                    </h4>
                                    <?php if (!empty($item['date'])): ?>
                                        <span class="step-date">📅 <?php echo formatThaiDateTime($item['date']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="step-body">
                                    <?php if ($item['completed'] && !empty($item['staff_name'])): ?>
                                        <div class="step-info-box">
                                            <div class="step-info-row">
                                                <span class="step-info-label">ผู้รับเรื่อง:</span>
                                                <span class="step-info-value">
                                                    👨‍💼 <?php echo htmlspecialchars($item['staff_name']); ?>
                                                    <small style="color: #6c757d;">(<?php echo htmlspecialchars($item['staff_position']); ?>)</small>
                                                </span>
                                            </div>
                                        </div>
                                        <?php if (!empty($item['note'])): ?>
                                            <div class="step-note">💬 <?php echo htmlspecialchars($item['note']); ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="pending-message">⏳ รอเจ้าหน้าที่ยืนยันรับเรื่อง</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ปฏิเสธ (ถ้ามี) -->
                    <?php if (isset($statusTimeline['rejected'])): $item = $statusTimeline['rejected']; ?>
                        <div class="status-step rejected">
                            <div class="step-indicator"><?php echo $item['icon']; ?></div>
                            <div class="step-content">
                                <div class="step-header">
                                    <h4 class="step-title" style="color: #dc3545;">
                                        <?php echo $item['title']; ?>
                                    </h4>
                                    <span class="step-date">📅 <?php echo formatThaiDateTime($item['date']); ?></span>
                                </div>
                                <div class="step-body">
                                    <div class="step-info-box">
                                        <div class="step-info-row">
                                            <span class="step-info-label">ผู้ดำเนินการ:</span>
                                            <span class="step-info-value">
                                                👨‍💼 <?php echo htmlspecialchars($item['staff_name']); ?>
                                                <small style="color: #6c757d;">(<?php echo htmlspecialchars($item['staff_position']); ?>)</small>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="step-note reject-reason">
                                        <strong>❌ เหตุผล:</strong> <?php echo htmlspecialchars($item['reason']); ?>
                                        <?php if (!empty($item['note'])): ?>
                                            <br><strong>💡 คำชี้แนะ:</strong> <?php echo htmlspecialchars($item['note']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ขั้นตอนที่ 2: ดำเนินการ/รอประเมิน (ไม่แสดงถ้าถูกปฏิเสธ) -->
                    <?php if (isset($statusTimeline['step2']) && !isset($statusTimeline['rejected'])): $item = $statusTimeline['step2']; ?>
                        <div class="status-step <?php echo $item['completed'] ? 'completed' : 'pending'; ?>">
                            <div class="step-indicator"><?php echo $item['icon']; ?></div>
                            <div class="step-content">
                                <div class="step-header">
                                    <h4 class="step-title">
                                        <span class="step-number">2</span>
                                        <?php echo $item['title']; ?>
                                    </h4>
                                    <?php if (!empty($item['date'])): ?>
                                        <span class="step-date">📅 <?php echo formatThaiDateTime($item['date']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="step-body">
                                    <?php if ($item['completed'] && !empty($item['staff_name'])): ?>
                                        <div class="step-info-box">
                                            <div class="step-info-row">
                                                <span class="step-info-label">ผู้ดำเนินการ:</span>
                                                <span class="step-info-value">
                                                    👨‍💼 <?php echo htmlspecialchars($item['staff_name']); ?>
                                                    <small style="color: #6c757d;">(<?php echo htmlspecialchars($item['staff_position']); ?>)</small>
                                                </span>
                                            </div>
                                            <?php if (!empty($item['detail'])): ?>
                                                <div class="step-info-row">
                                                    <span class="step-info-label">รายละเอียด:</span>
                                                    <span class="step-info-value"><?php echo htmlspecialchars($item['detail']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="pending-message">⏳ รอเจ้าหน้าที่ดำเนินการ</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ขั้นตอนที่ 3: เสร็จสิ้น (ไม่แสดงถ้าถูกปฏิเสธ) -->
                    <?php if (isset($statusTimeline['step3']) && !isset($statusTimeline['rejected'])): $item = $statusTimeline['step3']; ?>
                        <div class="status-step <?php echo $item['completed'] ? 'completed' : 'pending'; ?>">
                            <div class="step-indicator"><?php echo $item['icon']; ?></div>
                            <div class="step-content">
                                <div class="step-header">
                                    <h4 class="step-title">
                                        <span class="step-number">3</span>
                                        <?php echo $item['title']; ?>
                                    </h4>
                                    <?php if (!empty($item['date'])): ?>
                                        <span class="step-date">📅 <?php echo formatThaiDateTime($item['date']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="step-body">
                                    <?php if ($item['completed'] && !empty($item['score'])): ?>
                                        <div class="step-info-box">
                                            <div class="step-info-row">
                                                <span class="step-info-label">คะแนน:</span>
                                                <span class="step-info-value">
                                                    <div class="rating-display">
                                                        <div class="rating-stars">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <span class="star <?php echo $i <= $item['score'] ? '' : 'empty'; ?>">⭐</span>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <span class="rating-score"><?php echo $item['score']; ?>/5</span>
                                                    </div>
                                                </span>
                                            </div>
                                            <div class="step-info-row">
                                                <span class="step-info-label">ผู้ประเมิน:</span>
                                                <span class="step-info-value">
                                                    <?php if ($item['is_anonymous']): ?>
                                                        🕶️ ไม่ระบุตัวตน
                                                    <?php else: ?>
                                                        👤 <?php echo htmlspecialchars($item['evaluator_name'] ?? 'ไม่ระบุ'); ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($item['comment'])): ?>
                                                <div class="step-info-row">
                                                    <span class="step-info-label">ความคิดเห็น:</span>
                                                    <span class="step-info-value"><?php echo htmlspecialchars($item['comment']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif (!$item['completed']): ?>
                                        <div class="pending-message">⏳ รอผู้ร้องเรียนประเมินความพึงพอใจ</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Card ข้อมูลผู้ร้องเรียน -->
            <div class="card">
                <div class="card-header">
                    <span class="card-header-icon">👤</span>
                    <h3>ข้อมูลผู้ร้องเรียน</h3>
                </div>

                <?php if ($complaint['Re_iden'] == 1): ?>
                    <div class="anonymous-notice">
                        <span class="anonymous-icon">🕶️</span>
                        <div class="anonymous-text">
                            <h4>ไม่ระบุตัวตน</h4>
                            <p>ผู้ร้องเรียนเลือกที่จะไม่เปิดเผยข้อมูลส่วนตัว</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-icon">👨‍🎓</span>
                            <div class="info-content">
                                <div class="info-label">ชื่อ-นามสกุล</div>
                                <div class="info-value"><?php echo htmlspecialchars($complaint['Stu_name'] ?? '-'); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <span class="info-icon">🆔</span>
                            <div class="info-content">
                                <div class="info-label">รหัสนักศึกษา</div>
                                <div class="info-value"><?php echo htmlspecialchars($complaint['Stu_id'] ?? '-'); ?></div>
                            </div>
                        </div>
                        <?php if (!empty($complaint['faculty_name'])): ?>
                            <div class="info-item">
                                <span class="info-icon">🏫</span>
                                <div class="info-content">
                                    <div class="info-label">คณะ</div>
                                    <div class="info-value"><?php echo htmlspecialchars($complaint['faculty_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($complaint['major_name'])): ?>
                            <div class="info-item">
                                <span class="info-icon">🎓</span>
                                <div class="info-content">
                                    <div class="info-label">สาขา</div>
                                    <div class="info-value"><?php echo htmlspecialchars($complaint['major_name']); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-icon">📞</span>
                            <div class="info-content">
                                <div class="info-label">เบอร์โทรศัพท์</div>
                                <div class="info-value"><?php echo !empty($complaint['Stu_tel']) ? htmlspecialchars($complaint['Stu_tel']) : '-'; ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <span class="info-icon">📧</span>
                            <div class="info-content">
                                <div class="info-label">อีเมล</div>
                                <div class="info-value"><?php echo !empty($complaint['Stu_email']) ? htmlspecialchars($complaint['Stu_email']) : '-'; ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card ผู้รับผิดชอบ -->
            <div class="card">
                <div class="card-header">
                    <span class="card-header-icon">👥</span>
                    <h3>ผู้รับผิดชอบ</h3>
                </div>

                <?php if (!empty($complaint['assigned_name'])): ?>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-icon">👨‍💼</span>
                            <div class="info-content">
                                <div class="info-label">ผู้ได้รับมอบหมาย</div>
                                <div class="info-value"><?php echo htmlspecialchars($complaint['assigned_name']); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <span class="info-icon">💼</span>
                            <div class="info-content">
                                <div class="info-label">ตำแหน่ง</div>
                                <div class="info-value"><?php echo htmlspecialchars($complaint['assigned_position']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <div class="no-data-icon">👤</div>
                        <p>ยังไม่มีการมอบหมายผู้รับผิดชอบ</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Card ไฟล์แนบ -->
            <?php
            // แยกไฟล์เอกสารตามผู้อัปโหลด
            $studentNonImageFiles = array_filter($studentFiles, function ($file) {
                return !isImageFile($file['Sup_filetype']);
            });
            $teacherNonImageFiles = array_filter($teacherFiles, function ($file) {
                return !isImageFile($file['Sup_filetype']);
            });
            ?>
            <?php if (!empty($studentNonImageFiles) || !empty($teacherNonImageFiles)): ?>

                <!-- ไฟล์เอกสารจากผู้ร้องเรียน -->
                <?php if (!empty($studentNonImageFiles)): ?>
                    <div class="card">
                        <div class="card-header">
                            <span class="card-header-icon">📎</span>
                            <h3>ไฟล์เอกสารจากผู้ร้องเรียน (<?php echo count($studentNonImageFiles); ?> ไฟล์)</h3>
                        </div>
                        <div class="evidence-grid">
                            <?php foreach ($studentNonImageFiles as $file): ?>
                                <?php $filePath = getCorrectFilePath($file['Sup_filepath']); ?>
                                <div class="evidence-item">
                                    <div class="evidence-preview">
                                        <span class="evidence-file-icon"><?php echo getFileIcon($file['Sup_filetype']); ?></span>
                                    </div>
                                    <div class="evidence-info">
                                        <div class="evidence-filename" title="<?php echo htmlspecialchars($file['Sup_filename']); ?>">
                                            <?php echo htmlspecialchars($file['Sup_filename']); ?>
                                        </div>
                                        <div class="evidence-meta">
                                            <span><?php echo formatFileSize($file['Sup_filesize']); ?></span>
                                            <a href="download.php?file=<?php echo $file['Sup_id']; ?>" class="evidence-download">💾 ดาวน์โหลด</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ไฟล์เอกสารจากเจ้าหน้าที่ -->
                <?php if (!empty($teacherNonImageFiles)): ?>
                    <div class="card">
                        <div class="card-header staff-files-card-header">
                            <span class="card-header-icon">✅</span>
                            <h3>ไฟล์เอกสารจากเจ้าหน้าที่ (<?php echo count($teacherNonImageFiles); ?> ไฟล์)</h3>
                            <span class="staff-completed-badge-header">🎉 ดำเนินการเสร็จสิ้นแล้ว</span>
                        </div>
                        <div class="evidence-grid">
                            <?php foreach ($teacherNonImageFiles as $file): ?>
                                <?php $filePath = getCorrectFilePath($file['Sup_filepath']); ?>
                                <div class="evidence-item">
                                    <div class="evidence-preview">
                                        <span class="evidence-file-icon"><?php echo getFileIcon($file['Sup_filetype']); ?></span>
                                    </div>
                                    <div class="evidence-info">
                                        <div class="evidence-filename" title="<?php echo htmlspecialchars($file['Sup_filename']); ?>">
                                            <?php echo htmlspecialchars($file['Sup_filename']); ?>
                                        </div>
                                        <div class="evidence-meta">
                                            <span><?php echo formatFileSize($file['Sup_filesize']); ?></span>
                                            <a href="download.php?file=<?php echo $file['Sup_id']; ?>" class="evidence-download">💾 ดาวน์โหลด</a>
                                        </div>
                                        <div class="evidence-uploader">
                                            👨‍🏫 แนบโดย: <?php echo htmlspecialchars($file['teacher_uploader_name'] ?? 'เจ้าหน้าที่'); ?>
                                            <?php if (!empty($file['teacher_position'])): ?>
                                                (<?php echo htmlspecialchars($file['teacher_position']); ?>)
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </div>
    </div>

    <!-- Modal ดูภาพ -->
    <div id="imageViewerModal" class="image-modal">
        <span class="modal-close-btn" onclick="closeImageModal()">&times;</span>
        <img class="modal-content-img" id="img01">
        <div id="imgCaption"></div>
    </div>

    <?php if ($isPending): ?>
        <!-- Modal รับเรื่อง -->
        <div id="acceptModal" class="modal-overlay" style="display: none;">
            <div class="modal-container">
                <div class="modal-header accept-header">
                    <h3>✅ รับเรื่องข้อร้องเรียน</h3>
                    <button type="button" class="modal-close" onclick="closeAcceptModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="complaint-preview">
                        <div class="complaint-preview-header">
                            <span class="complaint-preview-id">#<?php echo $complaintId; ?></span>
                            <span class="complaint-preview-date"><?php echo $complaint['Re_date']; ?></span>
                        </div>
                        <div class="complaint-preview-content">
                            <?php if (!empty($complaint['Re_title'])): ?>
                                <strong><?php echo htmlspecialchars($complaint['Re_title']); ?></strong><br>
                            <?php endif; ?>
                            <?php echo htmlspecialchars(substr($complaint['Re_infor'], 0, 200)); ?>
                            <?php echo strlen($complaint['Re_infor']) > 200 ? '...' : ''; ?>
                        </div>
                    </div>

                    <form id="acceptForm">
                        <input type="hidden" id="accept_complaint_id" value="<?php echo $complaintId; ?>">
                        <div class="modal-form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="accept_send_email" <?php echo !empty($complaint['Stu_email']) ? 'checked' : 'disabled'; ?>>
                                <label for="accept_send_email">📧 ส่งอีเมลแจ้งเตือนนักศึกษา</label>
                            </div>
                            <div class="email-notice <?php echo empty($complaint['Stu_email']) ? 'no-email' : ''; ?>">
                                <?php if (empty($complaint['Stu_email'])): ?>
                                    ⚠️ ไม่พบอีเมลของนักศึกษา
                                <?php elseif ($complaint['Re_iden'] == 1): ?>
                                    🕶️📧 ผู้ร้องเรียนไม่ระบุตัวตน แต่ระบบจะส่งอีเมลแจ้งเตือนให้อัตโนมัติ
                                <?php else: ?>
                                    📧 จะส่งไปที่: <?php echo htmlspecialchars($complaint['Stu_email']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <div class="info-box-success">
                        <strong>📋 สิ่งที่จะเกิดขึ้น:</strong>
                        <ul>
                            <li>สถานะจะเปลี่ยนเป็น "กำลังดำเนินการ"</li>
                            <li>บันทึกประวัติการรับเรื่อง</li>
                            <li>ส่งการแจ้งเตือนในระบบ</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeAcceptModal()">ยกเลิก</button>
                    <button type="button" class="modal-btn modal-btn-success" id="submitAcceptBtn" onclick="submitAccept()">✅ ยืนยันรับเรื่อง</button>
                </div>
            </div>
        </div>

        <!-- Modal ปฏิเสธ -->
        <div id="rejectModal" class="modal-overlay" style="display: none;">
            <div class="modal-container">
                <div class="modal-header reject-header">
                    <h3>❌ ปฏิเสธข้อร้องเรียน</h3>
                    <button type="button" class="modal-close" onclick="closeRejectModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="complaint-preview">
                        <div class="complaint-preview-header">
                            <span class="complaint-preview-id">#<?php echo $complaintId; ?></span>
                            <span class="complaint-preview-date"><?php echo $complaint['Re_date']; ?></span>
                        </div>
                        <div class="complaint-preview-content">
                            <?php if (!empty($complaint['Re_title'])): ?>
                                <strong><?php echo htmlspecialchars($complaint['Re_title']); ?></strong><br>
                            <?php endif; ?>
                            <?php echo htmlspecialchars(substr($complaint['Re_infor'], 0, 200)); ?>
                            <?php echo strlen($complaint['Re_infor']) > 200 ? '...' : ''; ?>
                        </div>
                    </div>

                    <form id="rejectForm">
                        <input type="hidden" id="reject_complaint_id" value="<?php echo $complaintId; ?>">
                        <div class="modal-form-group">
                            <label><span class="required">*</span> เหตุผลที่ปฏิเสธ</label>
                            <select id="reject_reason_type" class="modal-form-control" onchange="toggleCustomReason()">
                                <option value="">-- เลือกเหตุผล --</option>
                                <option value="ข้อมูลไม่เพียงพอ กรุณาแนบหลักฐานเพิ่มเติม">📎 ข้อมูลไม่เพียงพอ</option>
                                <option value="ไม่อยู่ในขอบเขตที่หน่วยงานรับผิดชอบ">🏢 ไม่อยู่ในขอบเขต</option>
                                <option value="ซ้ำกับข้อร้องเรียนที่มีอยู่แล้ว">🔄 ซ้ำกับที่มีอยู่แล้ว</option>
                                <option value="ข้อมูลไม่ถูกต้อง">❓ ข้อมูลไม่ถูกต้อง</option>
                                <option value="เนื้อหาไม่เหมาะสม">⚠️ เนื้อหาไม่เหมาะสม</option>
                                <option value="custom">✏️ อื่นๆ (ระบุเอง)</option>
                            </select>
                        </div>
                        <div class="modal-form-group" id="custom_reason_group" style="display: none;">
                            <label><span class="required">*</span> ระบุเหตุผล</label>
                            <input type="text" id="custom_reason" class="modal-form-control" placeholder="กรุณาระบุเหตุผล...">
                        </div>
                        <div class="modal-form-group">
                            <label>💡 คำชี้แนะเพิ่มเติม</label>
                            <textarea id="reject_note" class="modal-form-control" rows="3" placeholder="เช่น กรุณาแนบรูปภาพประกอบ..."></textarea>
                        </div>
                        <div class="modal-form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="reject_send_email" <?php echo !empty($complaint['Stu_email']) ? 'checked' : 'disabled'; ?>>
                                <label for="reject_send_email">📧 ส่งอีเมลแจ้งเตือนนักศึกษา</label>
                            </div>
                            <div class="email-notice <?php echo empty($complaint['Stu_email']) ? 'no-email' : ''; ?>">
                                <?php if (empty($complaint['Stu_email'])): ?>
                                    ⚠️ ไม่พบอีเมลของนักศึกษา
                                <?php elseif ($complaint['Re_iden'] == 1): ?>
                                    🕶️📧 ผู้ร้องเรียนไม่ระบุตัวตน แต่ระบบจะส่งอีเมลแจ้งเตือนให้อัตโนมัติ
                                <?php else: ?>
                                    📧 จะส่งไปที่: <?php echo htmlspecialchars($complaint['Stu_email']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeRejectModal()">ยกเลิก</button>
                    <button type="button" class="modal-btn modal-btn-danger" id="submitRejectBtn" onclick="submitReject()">❌ ยืนยันปฏิเสธ</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Image Modal
        const imageModal = document.getElementById("imageViewerModal");
        const modalImg = document.getElementById("img01");
        const captionText = document.getElementById("imgCaption");

        function openImageModal(src, altText) {
            imageModal.style.display = "block";
            modalImg.src = src;
            captionText.innerHTML = altText;
            modalImg.classList.remove('zoomed');
            document.body.style.overflow = 'hidden';
        }

        function closeImageModal() {
            imageModal.style.display = "none";
            document.body.style.overflow = 'auto';
        }

        modalImg.onclick = function() {
            this.classList.toggle('zoomed');
        }
        imageModal.onclick = function(e) {
            if (e.target === imageModal) closeImageModal();
        }

        <?php if ($isPending): ?>
            // Accept/Reject Modal
            function openAcceptModal() {
                document.getElementById('acceptModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }

            function closeAcceptModal() {
                document.getElementById('acceptModal').style.display = 'none';
                document.body.style.overflow = '';
            }

            function openRejectModal() {
                document.getElementById('rejectModal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }

            function closeRejectModal() {
                document.getElementById('rejectModal').style.display = 'none';
                document.body.style.overflow = '';
            }

            function toggleCustomReason() {
                const select = document.getElementById('reject_reason_type');
                const customGroup = document.getElementById('custom_reason_group');
                customGroup.style.display = (select.value === 'custom') ? 'block' : 'none';
            }

            function submitAccept() {
                const complaintId = document.getElementById('accept_complaint_id').value;
                const sendEmail = document.getElementById('accept_send_email').checked;

                const formData = new FormData();
                formData.append('action', 'accept_complaint');
                formData.append('complaint_id', complaintId);
                formData.append('send_email', sendEmail ? '1' : '0');

                const btn = document.getElementById('submitAcceptBtn');
                btn.innerHTML = '⏳ กำลังดำเนินการ...';
                btn.disabled = true;

                fetch('ajax/update_complaint.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message || 'รับเรื่องสำเร็จ', 'success');
                            closeAcceptModal();
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification(data.message || 'เกิดข้อผิดพลาด', 'error');
                        }
                    })
                    .catch(() => showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error'))
                    .finally(() => {
                        btn.innerHTML = '✅ ยืนยันรับเรื่อง';
                        btn.disabled = false;
                    });
            }

            function submitReject() {
                const complaintId = document.getElementById('reject_complaint_id').value;
                const reasonType = document.getElementById('reject_reason_type').value;
                const customReason = document.getElementById('custom_reason').value.trim();
                const note = document.getElementById('reject_note').value.trim();
                const sendEmail = document.getElementById('reject_send_email').checked;

                if (!reasonType) {
                    showNotification('กรุณาเลือกเหตุผล', 'error');
                    return;
                }
                if (reasonType === 'custom' && !customReason) {
                    showNotification('กรุณาระบุเหตุผล', 'error');
                    return;
                }

                const reason = (reasonType === 'custom') ? customReason : reasonType;

                const formData = new FormData();
                formData.append('action', 'reject_complaint');
                formData.append('complaint_id', complaintId);
                formData.append('reason', reason);
                formData.append('note', note);
                formData.append('send_email', sendEmail ? '1' : '0');

                const btn = document.getElementById('submitRejectBtn');
                btn.innerHTML = '⏳ กำลังดำเนินการ...';
                btn.disabled = true;

                fetch('ajax/update_complaint.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message || 'ปฏิเสธสำเร็จ', 'success');
                            closeRejectModal();
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification(data.message || 'เกิดข้อผิดพลาด', 'error');
                        }
                    })
                    .catch(() => showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error'))
                    .finally(() => {
                        btn.innerHTML = '❌ ยืนยันปฏิเสธ';
                        btn.disabled = false;
                    });
            }

            function showNotification(message, type = 'info') {
                document.querySelectorAll('.notification').forEach(n => n.remove());
                const notification = document.createElement('div');
                notification.className = `notification ${type}`;
                const icons = {
                    success: '✅',
                    error: '❌',
                    info: 'ℹ️',
                    warning: '⚠️'
                };
                const titles = {
                    success: 'สำเร็จ',
                    error: 'ข้อผิดพลาด',
                    info: 'แจ้งเตือน',
                    warning: 'คำเตือน'
                };
                notification.innerHTML = `<div style="display: flex; align-items: flex-start; gap: 12px;">
                <span style="font-size: 1.5rem;">${icons[type]}</span>
                <div><div style="font-weight: bold; margin-bottom: 3px;">${titles[type]}</div><div>${message}</div></div>
            </div>`;
                document.body.appendChild(notification);
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 5000);
            }

            document.getElementById('acceptModal')?.addEventListener('click', e => {
                if (e.target.id === 'acceptModal') closeAcceptModal();
            });
            document.getElementById('rejectModal')?.addEventListener('click', e => {
                if (e.target.id === 'rejectModal') closeRejectModal();
            });
        <?php endif; ?>

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                if (imageModal.style.display === "block") closeImageModal();
                <?php if ($isPending): ?>
                    closeAcceptModal();
                    closeRejectModal();
                <?php endif; ?>
            }
        });
    </script>
</body>

</html>