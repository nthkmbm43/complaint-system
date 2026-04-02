<?php
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireStaffAccess();

$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission < 2) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดูแลระบบเท่านั้น เนื่องจากการมอบหมายงานเป็นสิทธิ์เฉพาะผู้ดูแลระบบ";
    $accessDeniedRedirect = "my-assignments.php";
}

$db = getDB();
$user = getCurrentUser();

// ============================================================
// AJAX: ดึงรายละเอียดข้อร้องเรียนสำหรับ Detail Modal
// ============================================================
if (isset($_GET['ajax_detail']) && is_numeric($_GET['ajax_detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rid = intval($_GET['ajax_detail']);
    try {
        // ข้อมูลหลัก
        $c = $db->fetch("
            SELECT r.*, t.Type_infor, t.Type_icon,
                   s.Stu_name, s.Stu_id, s.Stu_tel, s.Stu_email,
                   major.Unit_name  as major_name,  major.Unit_icon  as major_icon,
                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                   asn.Aj_name as assigned_name, asn.Aj_position as assigned_position,
                   e.Eva_score, e.Eva_sug as evaluation_comment, e.created_at as evaluation_date
            FROM request r
            LEFT JOIN type t        ON r.Type_id = t.Type_id
            LEFT JOIN student s     ON r.Stu_id  = s.Stu_id
            LEFT JOIN organization_unit major   ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            LEFT JOIN teacher asn   ON r.Aj_id   = asn.Aj_id
            LEFT JOIN evaluation e  ON r.Re_id   = e.Re_id
            WHERE r.Re_id = ?
        ", [$rid]);

        if (!$c) { echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูล']); exit; }

        // Timeline
        $timeline = [];

        // Step 0: ยื่นคำร้อง
        $timeline[] = ['step' => 0, 'icon' => '📝', 'title' => 'ยื่นคำร้อง',
            'date' => $c['Re_date'], 'completed' => true,
            'detail' => ($c['Re_iden'] == 1) ? 'ไม่ระบุตัวตน' : ($c['Stu_name'] ?? '-')];

        // Step 1: รับเรื่อง
        $recv = $db->fetch("SELECT sr.*, aj.Aj_name, aj.Aj_position FROM save_request sr
            JOIN teacher aj ON sr.Aj_id = aj.Aj_id
            WHERE sr.Re_id = ? AND sr.Sv_type = 'receive' ORDER BY sr.created_at DESC LIMIT 1", [$rid]);
        $timeline[] = ['step' => 1, 'icon' => $recv ? '✅' : '⏳',
            'title' => $recv ? 'รับเรื่องแล้ว' : 'รอรับเรื่อง',
            'date' => $recv['created_at'] ?? null,
            'staff' => $recv ? ($recv['Aj_name'] . ' (' . $recv['Aj_position'] . ')') : null,
            'completed' => ($c['Re_status'] >= 1 && $c['Re_status'] != 4)];

        // Step ปฏิเสธ
        if ($c['Re_status'] == '4') {
            $rej = $db->fetch("SELECT sr.*, aj.Aj_name FROM save_request sr
                JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                WHERE sr.Re_id = ? AND sr.Sv_type = 'reject' ORDER BY sr.created_at DESC LIMIT 1", [$rid]);
            if ($rej) {
                $timeline[] = ['step' => 'rejected', 'icon' => '❌', 'title' => 'ปฏิเสธคำร้อง',
                    'date' => $rej['created_at'], 'staff' => $rej['Aj_name'],
                    'detail' => $rej['Sv_infor'] ?? null, 'completed' => true];
            }
        }

        // Step 2: ดำเนินการ
        if ($c['Re_status'] != '4') {
            $proc = $db->fetch("SELECT sr.*, aj.Aj_name, aj.Aj_position FROM save_request sr
                JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                WHERE sr.Re_id = ? AND sr.Sv_type = 'process' ORDER BY sr.created_at DESC LIMIT 1", [$rid]);
            $timeline[] = ['step' => 2, 'icon' => $proc ? '⭐' : '⏳',
                'title' => $proc ? 'ดำเนินการเสร็จสิ้น - รอประเมิน' : 'รอดำเนินการ',
                'date' => $proc['created_at'] ?? null,
                'staff' => $proc ? ($proc['Aj_name'] . ' (' . $proc['Aj_position'] . ')') : null,
                'detail' => $proc['Sv_note'] ?? null,
                'completed' => ($c['Re_status'] >= 2)];
        }

        // Step 3: เสร็จสิ้น
        if ($c['Re_status'] == '3') {
            $ev = $db->fetch("SELECT e.*, s.Stu_name FROM evaluation e
                LEFT JOIN request r ON e.Re_id = r.Re_id
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                WHERE e.Re_id = ? ORDER BY e.created_at DESC LIMIT 1", [$rid]);
            $timeline[] = ['step' => 3, 'icon' => '🏆', 'title' => 'เสร็จสิ้น',
                'date' => $ev['created_at'] ?? null,
                'score' => $ev['Eva_score'] ?? null,
                'detail' => $ev['Eva_sug'] ?? null,
                'completed' => true];
        } elseif ($c['Re_status'] != '4') {
            $timeline[] = ['step' => 3, 'icon' => '🏁', 'title' => 'รอเสร็จสิ้น', 'completed' => false];
        }

        // ไฟล์แนบ
        $files = $db->fetchAll("
            SELECT se.Sup_filename, se.Sup_filepath, se.Sup_filetype,
                   s.Stu_name as uploader_name, aj.Aj_name as teacher_name,
                   CASE WHEN se.Aj_id IS NOT NULL THEN 'teacher' ELSE 'student' END as uploader_type
            FROM supporting_evidence se
            LEFT JOIN student s  ON se.Sup_upload_by = s.Stu_id
            LEFT JOIN teacher aj ON se.Aj_id = aj.Aj_id
            WHERE se.Re_id = ? ORDER BY se.Sup_upload_date DESC
        ", [$rid]);

        // แยกรูปภาพ
        $imgExts = ['jpg','jpeg','png','gif','webp'];
        $studentImages = []; $teacherImages = []; $otherFiles = [];
        foreach ($files as $f) {
            $ext = strtolower($f['Sup_filetype'] ?? pathinfo($f['Sup_filename'], PATHINFO_EXTENSION));
            $path = '../uploads/requests/images/' . basename($f['Sup_filepath']);
            if (in_array($ext, $imgExts)) {
                $item = ['name' => $f['Sup_filename'], 'path' => $path,
                         'uploader' => $f['uploader_type'] === 'teacher' ? ($f['teacher_name'] ?? 'เจ้าหน้าที่') : ($f['uploader_name'] ?? 'ผู้ร้องเรียน')];
                if ($f['uploader_type'] === 'teacher') $teacherImages[] = $item;
                else $studentImages[] = $item;
            } else {
                $otherFiles[] = ['name' => $f['Sup_filename'], 'path' => $path, 'ext' => $ext];
            }
        }

        // แปลงสถานะ/ระดับ
        $statusMap = ['0'=>'รอยืนยัน','1'=>'กำลังดำเนินการ','2'=>'รอประเมิน','3'=>'เสร็จสิ้น','4'=>'ปฏิเสธ'];
        $levelMap  = ['0'=>'รอพิจารณา','1'=>'ไม่เร่งด่วน','2'=>'ปกติ','3'=>'เร่งด่วน','4'=>'เร่งด่วนมาก','5'=>'วิกฤต/ฉุกเฉิน'];

        echo json_encode([
            'success'       => true,
            'id'            => $c['Re_id'],
            'title'         => $c['Re_title'] ?: 'ข้อร้องเรียน #' . $rid,
            'detail'        => $c['Re_infor'],
            'date'          => $c['Re_date'],
            'status'        => $c['Re_status'],
            'statusText'    => $statusMap[$c['Re_status']] ?? '-',
            'level'         => $c['Re_level'],
            'levelText'     => $levelMap[$c['Re_level']] ?? '-',
            'typeIcon'      => $c['Type_icon'] ?? '📋',
            'typeInfor'     => $c['Type_infor'] ?? '-',
            'isAnonymous'   => ($c['Re_iden'] == 1),
            'stuName'       => $c['Stu_name'] ?? null,
            'stuId'         => $c['Stu_id'] ?? null,
            'stuTel'        => $c['Stu_tel'] ?? null,
            'stuEmail'      => $c['Stu_email'] ?? null,
            'majorName'     => ($c['major_icon'] ?? '') . ' ' . ($c['major_name'] ?? '-'),
            'facultyName'   => ($c['faculty_icon'] ?? '') . ' ' . ($c['faculty_name'] ?? '-'),
            'assignedName'  => $c['assigned_name'] ?? null,
            'assignedPos'   => $c['assigned_position'] ?? null,
            'evaScore'      => $c['Eva_score'] ?? null,
            'evaComment'    => $c['evaluation_comment'] ?? null,
            'timeline'      => $timeline,
            'studentImages' => $studentImages,
            'teacherImages' => $teacherImages,
            'otherFiles'    => $otherFiles,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ------------------------------------------------------------------
// Scope สำหรับ permission 2: กรองตาม unit_type ที่ผู้ใช้สังกัด
//   - faculty    → แสดง นศ. ทุกสาขาในคณะ
//   - major      → แสดง นศ. เฉพาะสาขานั้นโดยตรง
//   - department → แสดง นศ. เฉพาะแผนก/หน่วยงานนั้นโดยตรง
//   - permission 3 (admin) → เห็นทั้งหมด ไม่จำกัด scope
// ------------------------------------------------------------------
$isAdmin     = ($userPermission >= 3);
$unitId      = $_SESSION['unit_id']   ?? null;
$unitType    = $_SESSION['unit_type'] ?? '';

// --- กำหนดค่าเริ่มต้นตัวแปร ---
$complaints = [];
$totalComplaints = 0;
$teacherList = [];

// สถิติ
$statsAll         = 0;
$statsUnassigned  = 0;
$statsAssigned    = 0;
$statsWaitingEval = 0;
$statsCompleted   = 0;

// Pagination
$itemsPerPage = 20;
$pageInput = $_GET['page'] ?? 1;
$currentPage = is_numeric($pageInput) ? max(1, intval($pageInput)) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// Tabs & Filters
$currentTab = $_GET['tab'] ?? 'unassigned';
$filters = [
    'level' => $_GET['level'] ?? '',
    'search' => trim($_GET['search'] ?? '')
];

// Helper: Deadline
function getDeadlineInfo($startDate, $level, $status)
{
    if (!$startDate) return ['text' => '-', 'class' => '', 'days' => 0];
    $start = new DateTime($startDate);
    $level = intval($level);

    if ($level == 5) $daysToAdd = 1;
    elseif ($level >= 3 && $level <= 4) $daysToAdd = 3;
    else $daysToAdd = 7;

    $deadline = clone $start;
    $deadline->modify("+$daysToAdd days");
    $today = new DateTime();
    $deadline->setTime(23, 59, 59);
    $today->setTime(0, 0, 0);

    $interval = $today->diff($deadline);
    $isPast = $interval->invert;
    $daysDiff = intval($interval->days);

    if (intval($status) >= 3) return ['text' => '✓ เสร็จสิ้น', 'class' => 'deadline-completed', 'days' => 0];
    if ($isPast) return ['text' => "⚠️ เกิน $daysDiff วัน", 'class' => 'deadline-over', 'days' => -$daysDiff];
    elseif ($daysDiff == 0) return ['text' => "🔥 วันนี้!", 'class' => 'deadline-urgent', 'days' => 0];
    elseif ($daysDiff <= 1) return ['text' => "🔥 เหลือ $daysDiff วัน", 'class' => 'deadline-urgent', 'days' => $daysDiff];
    else return ['text' => "⏳ เหลือ $daysDiff วัน", 'class' => 'deadline-normal', 'days' => $daysDiff];
}

function getDeadlineDays($level)
{
    $level = intval($level);
    if ($level == 5) return 'ภายใน 1 วัน';
    elseif ($level >= 3 && $level <= 4) return 'ภายใน 3 วัน';
    else return 'ภายใน 7 วัน';
}

try {
    // ดึงรายชื่ออาจารย์ พร้อมข้อมูลหน่วยงาน
    $teacherList = $db->fetchAll("
        SELECT t.Aj_id, t.Aj_name, t.Aj_position, t.Aj_per, t.Unit_id,
               u.Unit_name, u.Unit_type
        FROM teacher t
        LEFT JOIN organization_unit u ON t.Unit_id = u.Unit_id
        WHERE t.Aj_status = 1 
        ORDER BY t.Aj_per DESC, t.Aj_name ASC
    ");

    // ดึงรายชื่อหน่วยงานทั้งหมด (สำหรับ filter)
    $unitList = $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_type, Unit_icon
        FROM organization_unit 
        ORDER BY Unit_type, Unit_name
    ");

    $whereConditions = ['1=1'];
    $whereConditions[] = 'r.Re_status >= 1';
    $params = [];

    // --- Scope: กรองตาม unit_type ของผู้ใช้ (permission 2 เท่านั้น) ---
    if (!$isAdmin && $unitId) {
        if ($unitType === 'faculty') {
            // สังกัดคณะ → แสดง นศ. ทุกสาขาในคณะ
            $whereConditions[] = "(r.Stu_id IS NULL OR r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2
                INNER JOIN organization_unit ou2 ON s2.Unit_id = ou2.Unit_id
                WHERE ou2.Unit_id = ? OR ou2.Unit_parent_id = ?
            ))";
            $params[] = $unitId;
            $params[] = $unitId;
        } else {
            // สังกัดสาขา (major) หรือแผนก (department) → scope ตรงๆ
            $whereConditions[] = "(r.Stu_id IS NULL OR r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2 WHERE s2.Unit_id = ?
            ))";
            $params[] = $unitId;
        }
    }

    // **เงื่อนไขหลัก: แสดงเฉพาะสถานะ 1 ขึ้นไป (ยกเว้นสถานะ 0 = ยื่นคำร้อง)**
    switch ($currentTab) {
        case 'all':
            // แสดงทั้งหมด แต่ต้องเป็นสถานะ 1 ขึ้นไป
            $whereConditions[] = 'r.Re_status >= 1';
            break;
        case 'unassigned':
            // รอมอบหมาย: สถานะ 1, ยังไม่มีผู้รับผิดชอบ
            $whereConditions[] = 'r.Re_status = 1';
            $whereConditions[] = 'r.Aj_id IS NULL';
            break;
        case 'assigned':
            // กำลังดำเนินการ: สถานะ 1, มีผู้รับผิดชอบแล้ว
            $whereConditions[] = 'r.Re_status = 1';
            $whereConditions[] = 'r.Aj_id IS NOT NULL';
            break;
        case 'waiting_eval':
            $whereConditions[] = 'r.Re_status = 2';
            break;	
        case 'completed':
            // เสร็จสิ้น: สถานะ 3 ขึ้นไป
            $whereConditions[] = 'r.Re_status >= 3';
            break;
    }

    // Filter: ระดับความสำคัญ
    if (!empty($filters['level']) && is_numeric($filters['level'])) {
        $whereConditions[] = 'r.Re_level = ?';
        $params[] = intval($filters['level']);
    }

    // Filter: ค้นหา - **แก้ไขให้ทำงานได้**
    if (!empty($filters['search'])) {
        $searchTerm = '%' . $filters['search'] . '%';
        $whereConditions[] = '(
            r.Re_id LIKE ? OR 
            r.Re_title LIKE ? OR 
            r.Re_infor LIKE ? OR 
            s.Stu_name LIKE ? OR
            s.Stu_id LIKE ?
        )';
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    }

    $whereClause = implode(' AND ', $whereConditions);

    // Count Total
    $countSql = "SELECT COUNT(*) as total 
                 FROM request r 
                 LEFT JOIN student s ON r.Stu_id = s.Stu_id 
                 WHERE {$whereClause}";
    $countResult = $db->fetch($countSql, $params);
    $totalComplaints = intval($countResult['total'] ?? 0);

    // Fetch Data
    $sql = "SELECT r.*, 
                   s.Stu_name as requester_name, 
                   s.Stu_id as requester_id,
                   t.Type_infor, 
                   t.Type_icon,
                   t_assign.Aj_id as assigned_id, 
                   t_assign.Aj_name as assigned_name
            FROM request r
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN teacher t_assign ON r.Aj_id = t_assign.Aj_id
            WHERE {$whereClause}
            ORDER BY 
                CASE WHEN r.Re_status < 3 THEN 0 ELSE 1 END ASC,
                r.Re_level DESC, 
                r.Re_date DESC
            LIMIT ? OFFSET ?";

    $params[] = $itemsPerPage;
    $params[] = $offset;
    $complaints = $db->fetchAll($sql, $params);

    // Dashboard Stats (กรองตาม scope ด้วย)
    if (!$isAdmin && $unitId) {
        if ($unitType === 'faculty') {
            $statScope = "AND (Stu_id IS NULL OR Stu_id IN (
                SELECT s2.Stu_id FROM student s2
                INNER JOIN organization_unit ou2 ON s2.Unit_id = ou2.Unit_id
                WHERE ou2.Unit_id = ? OR ou2.Unit_parent_id = ?
            ))";
            $statParams = [$unitId, $unitId];
        } else {
            $statScope = "AND (Stu_id IS NULL OR Stu_id IN (
                SELECT s2.Stu_id FROM student s2 WHERE s2.Unit_id = ?
            ))";
            $statParams = [$unitId];
        }
        $statsAll         = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status >= 1 $statScope", $statParams)['count'] ?? 0);
        $statsUnassigned  = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 1 AND Aj_id IS NULL $statScope", $statParams)['count'] ?? 0);
        $statsAssigned    = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 1 AND Aj_id IS NOT NULL $statScope", $statParams)['count'] ?? 0);
        $statsWaitingEval = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 2 $statScope", $statParams)['count'] ?? 0);
        $statsCompleted   = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status >= 3 $statScope", $statParams)['count'] ?? 0);
    } else {
        $statsAll         = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status >= 1")['count'] ?? 0);
        $statsUnassigned  = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 1 AND Aj_id IS NULL")['count'] ?? 0);
        $statsAssigned    = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 1 AND Aj_id IS NOT NULL")['count'] ?? 0);
        $statsWaitingEval = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status = 2")['count'] ?? 0);
        $statsCompleted   = intval($db->fetch("SELECT COUNT(*) as count FROM request WHERE Re_status >= 3")['count'] ?? 0);
    }
} catch (Exception $e) {
    error_log("Error in assign-complaint.php: " . $e->getMessage());
}

$totalPages = $totalComplaints > 0 ? intval(ceil($totalComplaints / $itemsPerPage)) : 0;
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกจัดการข้อร้องเรียน - ระบบข้อร้องเรียน</title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ... (CSS เดิมทั้งหมด - ไม่ต้องแก้) ... */
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --bg: #f3f4f6;
            --bg-dark: #e5e7eb;
            --text: #1f2937;
            --text-light: #6b7280;
            --text-muted: #9ca3af;
            --border: #e5e7eb;
            --white: #ffffff;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius: 12px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Sarabun', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            color: var(--text);
            min-height: 100vh;
        }

        .main-content {
            padding-top: 90px;
            padding-bottom: 40px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            padding: 2rem 2.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 2rem;
            color: var(--white);
            box-shadow: 0 10px 40px rgba(99, 102, 241, 0.3);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .page-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card.all::before {
            background: var(--info);
        }

        .stat-card.unassigned::before {
            background: var(--warning);
        }

        .stat-card.processing::before {
            background: #10b981;
        }

        .stat-card.processing .stat-number {
            color: #10b981;
        }

        .stat-card.completed::before {
            background: var(--success);
        }

        .stat-card .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-card.all .stat-number {
            color: var(--info);
        }

        .stat-card.unassigned .stat-number {
            color: var(--warning);
        }

        .stat-card.assigned .stat-number {
            color: var(--primary);
        }

        .stat-card.completed .stat-number {
            color: var(--success);
        }

        .stat-card .stat-label {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .tab-container {
            background: var(--white);
            padding: 1.25rem 1.5rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .tab-nav {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--border);
            padding-bottom: 1rem;
        }

        .tab-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: 2px solid transparent;
        }

        .tab-btn.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
        }

        .tab-btn:not(.active) {
            background: var(--bg);
            color: var(--text-light);
            border-color: var(--border);
        }

        .tab-btn:not(.active):hover {
            background: var(--bg-dark);
            color: var(--text);
            border-color: var(--primary-light);
        }

        .tab-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .tab-btn:not(.active) .tab-count {
            background: var(--white);
            color: var(--text);
        }

        .filter-form {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            font-size: 1rem;
            background: var(--bg);
        }

        .search-box::before {
            content: '🔍';
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
        }

        .filter-select {
            padding: 0.875rem 2.5rem 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            font-size: 1rem;
            background: var(--bg);
            appearance: none;
        }

        .btn-search {
            padding: 0.875rem 1.75rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: var(--white);
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .table-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table thead {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .modern-table th {
            padding: 1rem 1.25rem;
            text-align: left;
            border-bottom: 2px solid var(--border);
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
        }

        .modern-table td {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .modern-table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #fefefe 100%);
        }

        .complaint-id {
            font-weight: 700;
            color: var(--primary);
        }

        .prio-badge {
            padding: 0.4rem 0.85rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--white);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .prio-5 {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            animation: pulse-red 1.5s infinite;
        }

        .prio-4 {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            animation: pulse-orange 2s infinite;
        }

        .prio-3 {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .prio-1,
        .prio-2 {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .deadline-badge {
            padding: 0.4rem 0.85rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .deadline-over {
            background: #fee2e2;
            color: #dc2626;
            animation: pulse-deadline 1.5s infinite;
        }

        .deadline-urgent {
            background: #fff7ed;
            color: #ea580c;
        }

        .deadline-normal {
            background: #d1fae5;
            color: #059669;
        }

        .deadline-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-badge {
            padding: 0.4rem 0.85rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #b45309;
        }

        .status-confirmed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-completed {
            background: #d1fae5;
            color: #059669;
        }

        .btn-action {
            padding: 0.6rem 1.25rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            color: white;
            transition: 0.3s;
        }

        .btn-assign {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
        }

        .btn-disabled {
            background: var(--bg-dark);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        @keyframes pulse-red {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5);
            }

            50% {
                box-shadow: 0 0 0 10px rgba(239, 68, 68, 0);
            }
        }

        @keyframes pulse-orange {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(249, 115, 22, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(249, 115, 22, 0);
            }
        }

        @keyframes pulse-deadline {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(5px);
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .modal.show {
            display: flex;
        }

        .modal-content-lg {
            background: var(--white);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            background: #f8fafc;
        }

        .modal-body-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-height: calc(90vh - 150px);
            overflow: hidden;
        }

        .modal-left-panel {
            padding: 1.5rem;
            border-right: 1px solid var(--border);
            overflow-y: auto;
            background: var(--bg);
        }

        .modal-right-panel {
            padding: 1.5rem;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            background: white;
        }

        .section-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-light);
            text-transform: uppercase;
            margin-bottom: 1rem;
        }

        .info-card {
            background: white;
            padding: 1.25rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .requester-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .evidence-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
            gap: 0.5rem;
        }

        .evidence-item {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #ddd;
            transition: 0.2s;
        }

        .evidence-item:hover {
            transform: scale(1.05);
        }

        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .btn-submit {
            padding: 0.75rem 2rem;
            background: var(--success);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel {
            padding: 0.75rem 1.5rem;
            border: none;
            background: #dc3545;
            color: white;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel:hover {
            background: #c82333;
        }

        .toast-container {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 1rem 1.5rem;
            border-radius: var(--radius);
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-lg);
            animation: slideIn 0.3s;
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .loading-overlay.show {
            display: flex;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
            }

            to {
                transform: translateX(0);
            }
        }

        .lightbox-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 100000;
            justify-content: center;
            align-items: center;
        }

        .lightbox-modal.show {
            display: flex;
        }

        .lightbox-image {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            transition: 0.2s;
        }

        .pagination a.active {
            background: var(--primary);
            color: white;
        }

        .pagination a:not(.active) {
            background: white;
            color: var(--text);
        }

        .pagination a:hover:not(.active) {
            background: var(--bg-dark);
        }

        /* ===== Teacher Searchable Select Styles ===== */
        .teacher-select-container {
            background: #f8fafc;
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 15px;
        }

        .teacher-filter-row {
            margin-bottom: 10px;
        }

        .form-select-sm {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            background: white;
            cursor: pointer;
        }

        .form-select-sm:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .teacher-search-box {
            position: relative;
            margin-bottom: 10px;
        }

        .form-input-search {
            width: 100%;
            padding: 10px 35px 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-input-search:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .search-clear {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            background: #ddd;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            color: #666;
            transition: all 0.2s;
        }

        .search-clear:hover {
            background: #ccc;
            color: #333;
        }

        .teacher-list-container {
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
        }

        .teacher-list {
            padding: 5px;
        }

        .teacher-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }

        .teacher-option:hover {
            background: #f1f5f9;
        }

        .teacher-option.selected {
            background: #eef2ff;
            border-color: var(--primary);
        }

        .teacher-option.hidden {
            display: none;
        }

        .teacher-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .teacher-info {
            flex: 1;
            min-width: 0;
        }

        .teacher-name {
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }

        .admin-badge {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .teacher-meta {
            display: flex;
            gap: 10px;
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 2px;
        }

        .teacher-id {
            color: var(--primary);
            font-weight: 500;
        }

        .teacher-unit {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .teacher-check {
            width: 24px;
            height: 24px;
            background: var(--success);
            color: white;
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            flex-shrink: 0;
        }

        .teacher-option.selected .teacher-check {
            display: flex;
        }

        .teacher-count {
            padding: 8px 12px;
            font-size: 0.8rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            background: #f8fafc;
            border-radius: 0 0 8px 8px;
            margin-top: -1px;
        }

        .no-results {
            padding: 30px;
            text-align: center;
            color: var(--text-muted);
        }

        .no-results span {
            font-size: 2rem;
            display: block;
            margin-bottom: 10px;
        }

        .selected-teacher-display {
            margin-top: 10px;
        }

        .selected-teacher-card {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid var(--success);
            border-radius: 8px;
        }

        .selected-label {
            color: var(--success);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .selected-name {
            flex: 1;
            font-weight: 600;
            color: var(--text);
        }

        .btn-clear-selection {
            background: rgba(239, 68, 68, 0.1);
            border: none;
            color: var(--danger);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .btn-clear-selection:hover {
            background: var(--danger);
            color: white;
        }

        /* Scrollbar สำหรับรายการอาจารย์ */
        .teacher-list-container::-webkit-scrollbar {
            width: 6px;
        }

        .teacher-list-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .teacher-list-container::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }

        .teacher-list-container::-webkit-scrollbar-thumb:hover {
            background: #aaa;
        }
    </style>
</head>

<body>
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "<?php echo $accessDeniedMessage; ?>",
                    "<?php echo $accessDeniedRedirect; ?>"
                );
            });
        </script>
    <?php endif; ?>

    <div class="toast-container" id="toastContainer"></div>
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <p style="margin-top:10px;">กำลังบันทึก...</p>
    </div>

    <div id="imageLightbox" class="lightbox-modal" onclick="closeLightbox()">
        <img id="lightboxImage" class="lightbox-image" src="">
    </div>

    <div id="assignModal" class="modal">
        <div class="modal-content-lg">
            <div class="modal-header">
                <h3 id="modalTitleText">📤 มอบหมายงาน</h3>
                <button onclick="closeModal()" class="btn-cancel" style="border:none; padding:0; font-size:1.5rem;">&times;</button>
            </div>
            <form onsubmit="submitAction(event)" id="assignForm" style="display:flex; flex-direction:column; flex:1; overflow:hidden;">
                <input type="hidden" name="complaint_id" id="modalComplaintId">
                <input type="hidden" name="action" id="formAction" value="assign">

                <div class="modal-body-split">
                    <div class="modal-left-panel">
                        <div class="section-label">👤 ข้อมูลผู้ร้องเรียน</div>
                        <div class="requester-card">
                            <div style="width:40px; height:40px; background:var(--primary); color:white; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:bold;" id="modalRequesterAvatar">A</div>
                            <div>
                                <h4 style="margin:0;" id="modalRequesterName"></h4>
                                <span style="font-size:0.85rem; color:#666;">รหัส: <span id="modalRequesterId"></span></span>
                            </div>
                        </div>

                        <div class="section-label">📋 รายละเอียด</div>
                        <div class="info-card">
                            <div style="margin-bottom:10px; display:flex; gap:15px; font-size:0.9rem; color:#666;">
                                <span>#<strong id="modalComplaintIdDisplay"></strong></span>
                                <span>📅 <span id="modalComplaintDate"></span></span>
                            </div>
                            <h4 id="modalComplaintTitle" style="margin:0 0 10px 0;"></h4>
                            <p id="modalComplaintDetails" style="color:#555; line-height:1.6; font-size:0.95rem;"></p>
                            <div style="margin-top:15px;">
                                ความสำคัญ: <span id="modalComplaintLevel" class="prio-badge"></span>
                                กำหนดส่ง: <span id="modalDeadlineDays" style="color:var(--danger); font-weight:bold; margin-left:10px;"></span>
                            </div>
                        </div>

                        <div class="section-label">🖼️ หลักฐาน</div>
                        <div class="evidence-gallery" id="modalImages"></div>
                    </div>

                    <div class="modal-right-panel">
                        <div class="section-label">⚙️ ส่วนการมอบหมาย</div>
                        <label style="display:block; margin-bottom:5px; font-weight:600;">เลือกอาจารย์ผู้รับผิดชอบ <span style="color:red">*</span></label>

                        <!-- Searchable Select Container -->
                        <div class="teacher-select-container">
                            <!-- Filter ตามหน่วยงาน -->
                            <div class="teacher-filter-row">
                                <select id="unitFilter" class="form-select-sm" onchange="filterTeachers()">
                                    <option value="">🏢 ทุกหน่วยงาน</option>
                                    <?php
                                    $groupedUnits = [];
                                    foreach ($unitList as $unit) {
                                        $groupedUnits[$unit['Unit_type']][] = $unit;
                                    }

                                    $typeLabels = [
                                        'faculty' => '📚 คณะ',
                                        'major' => '🎓 สาขา',
                                        'department' => '🏛️ หน่วยงาน'
                                    ];

                                    foreach ($groupedUnits as $type => $units):
                                        $label = $typeLabels[$type] ?? $type;
                                    ?>
                                        <optgroup label="<?php echo $label; ?>">
                                            <?php foreach ($units as $unit): ?>
                                                <option value="<?php echo $unit['Unit_id']; ?>">
                                                    <?php echo htmlspecialchars($unit['Unit_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- ช่องค้นหา -->
                            <div class="teacher-search-box">
                                <input type="text"
                                    id="teacherSearch"
                                    class="form-input-search"
                                    placeholder="🔍 พิมพ์ค้นหาชื่อหรือรหัสอาจารย์..."
                                    autocomplete="off"
                                    onkeyup="filterTeachers()">
                                <span class="search-clear" onclick="clearTeacherSearch()" title="ล้างการค้นหา">&times;</span>
                            </div>

                            <!-- รายการอาจารย์ -->
                            <div class="teacher-list-container" id="teacherListContainer">
                                <div class="teacher-list" id="teacherList">
                                    <?php foreach ($teacherList as $t): ?>
                                        <div class="teacher-option"
                                            data-id="<?php echo $t['Aj_id']; ?>"
                                            data-name="<?php echo htmlspecialchars($t['Aj_name']); ?>"
                                            data-position="<?php echo htmlspecialchars($t['Aj_position'] ?? ''); ?>"
                                            data-unit="<?php echo $t['Unit_id'] ?? ''; ?>"
                                            data-unit-name="<?php echo htmlspecialchars($t['Unit_name'] ?? ''); ?>"
                                            onclick="selectTeacher(this)">
                                            <div class="teacher-avatar">
                                                <?php echo mb_substr($t['Aj_name'], 0, 1); ?>
                                            </div>
                                            <div class="teacher-info">
                                                <div class="teacher-name">
                                                    <?php echo htmlspecialchars($t['Aj_name']); ?>
                                                    <?php if ($t['Aj_per'] == 2): ?>
                                                        <span class="admin-badge">Admin</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="teacher-meta">
                                                    <span class="teacher-id">ID: <?php echo $t['Aj_id']; ?></span>
                                                    <?php if (!empty($t['Aj_position'])): ?>
                                                        <span class="teacher-position"><?php echo htmlspecialchars($t['Aj_position']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($t['Unit_name'])): ?>
                                                    <div class="teacher-unit">🏢 <?php echo htmlspecialchars($t['Unit_name']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="teacher-check">✓</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="no-results" id="noTeacherResults" style="display:none;">
                                    <span>😕</span>
                                    <p>ไม่พบอาจารย์ที่ค้นหา</p>
                                </div>
                            </div>

                            <!-- จำนวนที่แสดง -->
                            <div class="teacher-count">
                                แสดง <span id="visibleCount"><?php echo count($teacherList); ?></span> จาก <?php echo count($teacherList); ?> คน
                            </div>
                        </div>

                        <!-- Hidden input สำหรับส่งค่า -->
                        <input type="hidden" name="teacher_id" id="teacher_id" required>

                        <!-- แสดงอาจารย์ที่เลือก -->
                        <div class="selected-teacher-display" id="selectedTeacherDisplay" style="display:none;">
                            <div class="selected-teacher-card">
                                <span class="selected-label">✅ เลือกแล้ว:</span>
                                <span class="selected-name" id="selectedTeacherName"></span>
                                <button type="button" class="btn-clear-selection" onclick="clearSelection()">✕</button>
                            </div>
                        </div>

                        <label style="display:block; margin-bottom:5px; font-weight:600; margin-top:15px;">หมายเหตุ / คำสั่ง</label>
                        <textarea name="assign_note" id="assign_note" class="form-textarea" rows="5" placeholder="ระบุรายละเอียดเพิ่มเติม..."></textarea>

                        <div style="background:var(--info-light); padding:1rem; border-radius:8px; margin-top:1rem; font-size:0.9rem; color:#1e40af;">
                            💡 อาจารย์ที่ถูกมอบหมายจะได้รับการแจ้งเตือนทันที
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn-cancel">ยกเลิก</button>
                    <button type="submit" id="modalSubmitBtn" class="btn-submit">ยืนยันมอบหมาย</button>
                </div>
            </form>
        </div>
    </div>

    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>📥 บันทึกจัดการข้อร้องเรียน</h1>
                <p>บันทึกการจัดการและมอบหมายงานข้อร้องเรียนให้กับอาจารย์ผู้รับผิดชอบ</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card all">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?php echo number_format($statsAll); ?></div>
                    <div class="stat-label">ทั้งหมด</div>
                </div>
                <div class="stat-card unassigned">
                    <div class="stat-icon">📋</div>
                    <div class="stat-number"><?php echo number_format($statsUnassigned); ?></div>
                    <div class="stat-label">รอมอบหมาย</div>
                </div>
                <div class="stat-card processing">
                    <div class="stat-icon">🔧</div>
                    <div class="stat-number"><?php echo number_format($statsAssigned); ?></div>
                    <div class="stat-label">กำลังดำเนินการ</div>
                </div>
                <div class="stat-card assigned">
                    <div class="stat-icon">⏰</div>
                    <div class="stat-number"><?php echo number_format($statsWaitingEval); ?></div>
                    <div class="stat-label">รอประเมิน</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-icon">✅</div>
                    <div class="stat-number"><?php echo number_format($statsCompleted); ?></div>
                    <div class="stat-label">เสร็จสิ้น</div>
                </div>
            </div>

            <div class="tab-container">
                <div class="tab-nav">
                    <a href="?tab=all" class="tab-btn <?php echo $currentTab == 'all' ? 'active' : ''; ?>">
                        📋 ทั้งหมด <span class="tab-count"><?php echo number_format($statsAll); ?></span>
                    </a>
                    <a href="?tab=unassigned" class="tab-btn <?php echo $currentTab == 'unassigned' ? 'active' : ''; ?>">
                        ⏳ รอมอบหมาย <span class="tab-count"><?php echo number_format($statsUnassigned); ?></span>
                    </a>
                    <a href="?tab=assigned" class="tab-btn <?php echo $currentTab == 'assigned' ? 'active' : ''; ?>">
                        🔧 กำลังดำเนินการ <span class="tab-count"><?php echo number_format($statsAssigned); ?></span>
                    </a>
                    <a href="?tab=waiting_eval" class="tab-btn <?php echo $currentTab == 'waiting_eval' ? 'active' : ''; ?>">
                        ⏰ รอประเมิน <span class="tab-count"><?php echo number_format($statsWaitingEval); ?></span>
                    </a>
                    <a href="?tab=completed" class="tab-btn <?php echo $currentTab == 'completed' ? 'active' : ''; ?>">
                        ✅ เสร็จสิ้น <span class="tab-count"><?php echo number_format($statsCompleted); ?></span>
                    </a>
                </div>

                <form method="GET" class="filter-form">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($currentTab); ?>">
                    <div class="search-box">
                        <input type="text" name="search" placeholder="ค้นหา..." value="<?php echo htmlspecialchars($filters['search']); ?>">
                    </div>
                    <select name="level" class="filter-select">
                        <option value="">ทุกระดับ</option>
                        <option value="5" <?php echo $filters['level'] == '5' ? 'selected' : ''; ?>>🚨 วิกฤต</option>
                        <option value="4" <?php echo $filters['level'] == '4' ? 'selected' : ''; ?>>🔴 เร่งด่วนมาก</option>
                        <option value="3" <?php echo $filters['level'] == '3' ? 'selected' : ''; ?>>🟠 เร่งด่วน</option>
                        <option value="2" <?php echo $filters['level'] == '2' ? 'selected' : ''; ?>>🟢 ปกติ</option>
                    </select>
                    <button type="submit" class="btn-search">ค้นหา</button>
                </form>
            </div>

            <div class="table-container">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th width="80">รหัส</th>
                            <th>เรื่องร้องเรียน</th>
                            <th>ความเร่งด่วน</th>
                            <th>สถานะ</th>
                            <th>ผู้รับผิดชอบ</th>
                            <th width="120" style="text-align:center;">จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($complaints)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:3rem;">ไม่พบข้อมูล</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($complaints as $row):
                                $images = [];
                                try {
                                    $images = $db->fetchAll("SELECT Sup_filepath FROM supporting_evidence WHERE Re_id = ?", [$row['Re_id']]);
                                } catch (Exception $e) {
                                }
                                $imgList = array_map(function ($img) {
                                    return $img['Sup_filepath'];
                                }, $images);

                                $lv = intval($row['Re_level']);
                                $lvText = match ($lv) {
                                    5 => '🚨 วิกฤต',
                                    4 => '🔴 เร่งด่วนมาก',
                                    3 => '🟠 เร่งด่วน',
                                    2 => '🟢 ปกติ',
                                    default => '⚪ ไม่เร่งด่วน'
                                };
                                $lvClass = match ($lv) {
                                    5 => 'prio-5',
                                    4 => 'prio-4',
                                    3 => 'prio-3',
                                    default => 'prio-1'
                                };

                                $status = intval($row['Re_status']);
                                $statusText = match ($status) {
                                    0 => 'ยื่นคำร้อง',
                                    1 => 'กำลังดำเนินการ',
                                    2 => 'รอการประเมินผล',
                                    3 => 'เสร็จสิ้น',
                                    4 => 'ปฏิเสธ/ยกเลิก',
                                    default => 'ไม่ทราบ'
                                };
                                $statusClass = match ($status) {
                                    0 => 'status-pending',
                                    1 => 'status-confirmed',
                                    2 => 'status-confirmed',
                                    3 => 'status-completed',
                                    4 => 'status-completed',
                                    default => 'status-pending'
                                };

                                $deadline = getDeadlineInfo($row['Re_date'], $lv, $status);
                                $deadlineDaysText = getDeadlineDays($lv);

                                $jsData = json_encode([
                                    'id' => $row['Re_id'],
                                    'title' => htmlspecialchars($row['Re_title'] ?: '-'),
                                    'details' => htmlspecialchars($row['Re_infor'] ?? ''),
                                    'date' => date('d/m/Y', strtotime($row['Re_date'])),
                                    'levelText' => $lvText,
                                    'levelClass' => $lvClass,
                                    'deadlineDays' => $deadlineDaysText,
                                    'requesterName' => htmlspecialchars($row['Re_iden'] == 1 ? 'ไม่ระบุตัวตน' : ($row['requester_name'] ?? '-')),
                                    'requesterId' => htmlspecialchars($row['Re_iden'] == 1 ? '-' : ($row['requester_id'] ?? '-')),
                                    'images' => $imgList,
                                    'currentTeacherId' => $row['assigned_id'] ?? null,
                                    'currentTeacherName' => $row['assigned_name'] ?? null
                                ], JSON_HEX_APOS | JSON_HEX_QUOT);
                            ?>
                                <tr>
                                    <td><span class="complaint-id">#<?php echo $row['Re_id']; ?></span></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($row['Re_title']); ?></div>
                                        <small style="color:#666;"><?php echo date('d/m/Y', strtotime($row['Re_date'])); ?></small>
                                    </td>
                                    <td><span class="prio-badge <?php echo $lvClass; ?>"><?php echo $lvText; ?></span></td>
                                    <td><span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
                                    <td><?php echo $row['assigned_name'] ? htmlspecialchars($row['assigned_name']) : '<span style="color:#999">- ยังไม่ระบุ -</span>'; ?></td>
                                    <td style="text-align:center;">
                                        <!-- ปุ่มดูรายละเอียด -->
                                        <button type="button"
                                            class="btn-action btn-view-detail"
                                            onclick="openDetailModal(<?php echo $row['Re_id']; ?>)"
                                            title="ดูรายละเอียด">
                                            👁️ รายละเอียด
                                        </button>
                                        <?php
                                        $canAssign = ($status == 1); // กดได้เฉพาะสถานะ 1 (ยืนยันแล้ว/รอมอบหมาย)
                                        ?>
                                        <?php if ($canAssign): ?>
                                            <button type="button"
                                                class="btn-action <?php echo !empty($row['assigned_id']) ? 'btn-edit' : 'btn-assign'; ?>"
                                                data-complaint='<?php echo htmlspecialchars($jsData, ENT_QUOTES, 'UTF-8'); ?>'
                                                onclick="handleAssignClick(this)">
                                                <?php echo !empty($row['assigned_id']) ? '✏️ แก้ไข' : '📤 มอบหมาย'; ?>
                                            </button>
                                        <?php else: ?>
                                            <button class="btn-action btn-disabled" disabled
                                                title="ไม่สามารถแก้ไขได้เนื่องจากสถานะดำเนินการไปแล้ว">
                                                <?php if ($status == 2): ?>
                                                    ⏰ รอประเมิน
                                                <?php else: ?>
                                                    🔒 เสร็จสิ้น
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = http_build_query(array_filter([
                        'tab' => $currentTab,
                        'level' => $filters['level'],
                        'search' => $filters['search']
                    ]));

                    for ($i = 1; $i <= $totalPages; $i++):
                        $pageUrl = '?' . ($queryParams ? $queryParams . '&' : '') . 'page=' . $i;
                    ?>
                        <a href="<?php echo $pageUrl; ?>" class="<?php echo $currentPage == $i ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        window.currentImages = [];

        window.handleAssignClick = function(button) {
            console.log('🖱️ Button Clicked!');
            try {
                var dataStr = button.getAttribute('data-complaint');
                if (!dataStr) {
                    alert('ไม่พบข้อมูล');
                    return;
                }

                var data = JSON.parse(dataStr);
                console.log('✅ Data:', data);
                openAssignModal(data);
            } catch (e) {
                console.error(e);
                alert('Data Error: ' + e.message);
            }
        };

        window.openAssignModal = function(data) {
            // ฟังก์ชันนี้จะถูก override ด้านล่าง
        };

        window.closeModal = function() {
            var m = document.getElementById('assignModal');
            if (m) m.classList.remove('show');
        };

        window.openLightbox = function(src) {
            var lb = document.getElementById('imageLightbox');
            var img = document.getElementById('lightboxImage');
            img.src = src;
            lb.classList.add('show');
        };

        window.closeLightbox = function() {
            document.getElementById('imageLightbox').classList.remove('show');
        };

        window.submitAction = function(e) {
            e.preventDefault();

            var teacherId = document.getElementById('teacher_id').value;
            if (!teacherId) {
                showToast('error', 'กรุณาเลือกอาจารย์ผู้รับผิดชอบ');
                return;
            }

            var btn = document.getElementById('modalSubmitBtn');
            var originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = 'กำลังบันทึก...';

            document.getElementById('loadingOverlay').classList.add('show');

            var formData = new FormData(e.target);

            console.log('📤 Sending data:');
            for (var pair of formData.entries()) {
                console.log(pair[0] + ': ' + pair[1]);
            }

            fetch('ajax/assign_complaint.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(res => {
                    document.getElementById('loadingOverlay').classList.remove('show');
                    btn.disabled = false;
                    btn.innerText = originalText;

                    console.log('📥 Response:', res);

                    if (res.success) {
                        showToast('success', res.message);
                        closeModal();
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        showToast('error', res.message);
                    }
                })
                .catch(err => {
                    console.error('❌ Error:', err);
                    document.getElementById('loadingOverlay').classList.remove('show');
                    btn.disabled = false;
                    btn.innerText = originalText;
                    showToast('error', 'ไม่สามารถเชื่อมต่อ Server ได้');
                });
        };

        function showToast(type, msg) {
            var container = document.getElementById('toastContainer');
            var div = document.createElement('div');
            div.className = 'toast ' + type;
            div.innerHTML = (type === 'success' ? '✅' : '❌') + ' <span>' + msg + '</span>';
            container.appendChild(div);
            setTimeout(() => div.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            var assignModal = document.getElementById('assignModal');
            if (assignModal) {
                assignModal.addEventListener('click', function(e) {
                    if (e.target === this) closeModal();
                });
            }
        });

        console.log('✅ Script Loaded');

        // ===== Teacher Search & Filter Functions =====
        function filterTeachers() {
            const searchText = document.getElementById('teacherSearch').value.toLowerCase().trim();
            const unitFilter = document.getElementById('unitFilter').value;
            const teacherOptions = document.querySelectorAll('.teacher-option');
            let visibleCount = 0;

            teacherOptions.forEach(option => {
                const name = option.dataset.name.toLowerCase();
                const id = option.dataset.id;
                const position = (option.dataset.position || '').toLowerCase();
                const unitId = option.dataset.unit;
                const unitName = (option.dataset.unitName || '').toLowerCase();

                // ค้นหาจากชื่อ, รหัส, ตำแหน่ง, หน่วยงาน
                const matchSearch = searchText === '' ||
                    name.includes(searchText) ||
                    id.includes(searchText) ||
                    position.includes(searchText) ||
                    unitName.includes(searchText);

                // Filter ตามหน่วยงาน
                const matchUnit = unitFilter === '' || unitId === unitFilter;

                if (matchSearch && matchUnit) {
                    option.classList.remove('hidden');
                    visibleCount++;
                } else {
                    option.classList.add('hidden');
                }
            });

            // แสดง/ซ่อน no results message
            const noResults = document.getElementById('noTeacherResults');
            const teacherList = document.getElementById('teacherList');
            if (visibleCount === 0) {
                noResults.style.display = 'block';
                teacherList.style.display = 'none';
            } else {
                noResults.style.display = 'none';
                teacherList.style.display = 'block';
            }

            // อัพเดทจำนวน
            document.getElementById('visibleCount').textContent = visibleCount;
        }

        function selectTeacher(element) {
            const id = element.dataset.id;
            const name = element.dataset.name;
            const position = element.dataset.position;

            // ลบ selected จากทุก option
            document.querySelectorAll('.teacher-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            // เพิ่ม selected ให้ option ที่เลือก
            element.classList.add('selected');

            // อัพเดท hidden input
            document.getElementById('teacher_id').value = id;

            // แสดงอาจารย์ที่เลือก
            const displayName = name + (position ? ` (${position})` : '');
            document.getElementById('selectedTeacherName').textContent = displayName;
            document.getElementById('selectedTeacherDisplay').style.display = 'block';

            // Scroll to selected item
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function clearTeacherSearch() {
            document.getElementById('teacherSearch').value = '';
            document.getElementById('unitFilter').value = '';
            filterTeachers();
        }

        function clearSelection() {
            // ลบ selected จากทุก option
            document.querySelectorAll('.teacher-option').forEach(opt => {
                opt.classList.remove('selected');
            });

            // ล้าง hidden input
            document.getElementById('teacher_id').value = '';

            // ซ่อนส่วนแสดงอาจารย์ที่เลือก
            document.getElementById('selectedTeacherDisplay').style.display = 'none';
        }

        // ===== Override openAssignModal เพื่อรองรับระบบใหม่ =====
        const originalOpenAssignModal = window.openAssignModal;
        window.openAssignModal = function(data) {
            var modal = document.getElementById('assignModal');
            if (!modal) {
                alert('Modal not found');
                return;
            }

            document.getElementById('assignForm').reset();
            document.getElementById('modalImages').innerHTML = '';

            // รีเซ็ตการค้นหาและเลือกอาจารย์
            clearTeacherSearch();
            clearSelection();

            function setText(id, txt) {
                var el = document.getElementById(id);
                if (el) el.innerText = txt;
            }

            document.getElementById('modalComplaintId').value = data.id;
            setText('modalComplaintIdDisplay', data.id);
            setText('modalComplaintDate', data.date);
            setText('modalRequesterName', data.requesterName);
            setText('modalRequesterId', data.requesterId);
            setText('modalComplaintTitle', data.title);
            setText('modalComplaintDetails', data.details);
            setText('modalDeadlineDays', data.deadlineDays);

            var avatar = document.getElementById('modalRequesterAvatar');
            if (avatar) avatar.innerText = (data.requesterName || '?').charAt(0);

            var badge = document.getElementById('modalComplaintLevel');
            if (badge) {
                badge.className = 'prio-badge ' + data.levelClass;
                badge.innerText = data.levelText;
            }

            window.currentImages = data.images || [];
            var gallery = document.getElementById('modalImages');
            if (data.images && data.images.length > 0) {
                data.images.forEach(function(src, idx) {
                    var img = document.createElement('img');
                    img.src = src;
                    img.className = 'evidence-item';
                    img.onclick = function() {
                        openLightbox(src);
                    };
                    gallery.appendChild(img);
                });
            } else {
                gallery.innerHTML = '<small style="color:#999; display:block; padding:10px;">- ไม่มีหลักฐานประกอบ -</small>';
            }

            var isEdit = !!data.currentTeacherId;
            document.getElementById('formAction').value = isEdit ? 'reassign' : 'assign';
            document.getElementById('modalTitleText').innerText = isEdit ? '📄 แก้ไขการมอบหมายงาน' : '📤 มอบหมายงาน';
            document.getElementById('modalSubmitBtn').innerText = isEdit ? 'บันทึกการแก้ไข' : 'ยืนยันมอบหมาย';
            document.getElementById('assign_note').placeholder = isEdit ? 'ระบุเหตุผลที่เปลี่ยนผู้รับผิดชอบ...' : 'ระบุหมายเหตุเพิ่มเติม...';

            // ถ้ามีอาจารย์ที่เลือกไว้แล้ว (กรณีแก้ไข)
            if (data.currentTeacherId) {
                const teacherOption = document.querySelector(`.teacher-option[data-id="${data.currentTeacherId}"]`);
                if (teacherOption) {
                    selectTeacher(teacherOption);
                }
            }

            modal.classList.add('show');
        };
    </script>

<!-- ============================================================
     DETAIL MODAL
     ============================================================ -->
<div id="detailModal" class="dm-overlay" onclick="if(event.target===this)closeDetailModal()">
    <div class="dm-box">
        <div class="dm-header">
            <div class="dm-header-left">
                <span id="dm-type-icon" class="dm-type-icon">📋</span>
                <div>
                    <div id="dm-title" class="dm-title">-</div>
                    <div class="dm-badges" id="dm-badges"></div>
                </div>
            </div>
            <div class="dm-header-right">
                <span id="dm-id" class="dm-id-badge">#-</span>
                <button class="dm-close" onclick="closeDetailModal()">✕</button>
            </div>
        </div>

        <div class="dm-body" id="dm-body">
            <div class="dm-loading">⏳ กำลังโหลดข้อมูล...</div>
        </div>
    </div>
</div>

<style>
/* ===== DETAIL MODAL ===== */
.dm-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.55); z-index: 9999;
    align-items: center; justify-content: center; padding: 16px;
}
.dm-overlay.open { display: flex; }
.dm-box {
    background: #fff; border-radius: 16px;
    width: 100%; max-width: 860px; max-height: 90vh;
    display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
    animation: dmSlideIn .25s ease;
}
@keyframes dmSlideIn { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.dm-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 20px 24px 16px; border-bottom: 2px solid #f0f0f0;
    background: linear-gradient(135deg,#6366f1,#4f46e5); border-radius: 16px 16px 0 0;
    color: #fff;
}
.dm-header-left { display: flex; gap: 14px; align-items: flex-start; flex: 1; }
.dm-type-icon { font-size: 2rem; line-height: 1; }
.dm-title { font-size: 1.15rem; font-weight: 700; margin-bottom: 6px; }
.dm-badges { display: flex; flex-wrap: wrap; gap: 6px; }
.dm-badge {
    padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: 600;
    background: rgba(255,255,255,.25); color: #fff;
}
.dm-badge.status-0  { background: #fbbf24; color: #7c3a00; }
.dm-badge.status-1  { background: #3b82f6; color: #fff; }
.dm-badge.status-2  { background: #8b5cf6; color: #fff; }
.dm-badge.status-3  { background: #10b981; color: #fff; }
.dm-badge.status-4  { background: #ef4444; color: #fff; }
.dm-badge.level-0   { background: #e5e7eb; color: #374151; }
.dm-badge.level-1   { background: #d1fae5; color: #065f46; }
.dm-badge.level-2   { background: #dbeafe; color: #1e40af; }
.dm-badge.level-3   { background: #fef3c7; color: #92400e; }
.dm-badge.level-4   { background: #fee2e2; color: #991b1b; }
.dm-badge.level-5   { background: #7f1d1d; color: #fff; }
.dm-header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.dm-id-badge { background: rgba(255,255,255,.25); border-radius: 8px; padding: 4px 12px; font-weight: 700; font-size: .9rem; }
.dm-close {
    background: rgba(255,255,255,.2); border: none; color: #fff;
    width: 32px; height: 32px; border-radius: 50%; cursor: pointer;
    font-size: 1rem; display: flex; align-items: center; justify-content: center;
    transition: background .2s;
}
.dm-close:hover { background: rgba(255,255,255,.4); }
.dm-body { overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.dm-loading { text-align: center; padding: 40px; color: #666; font-size: 1.1rem; }
.dm-error   { text-align: center; padding: 40px; color: #ef4444; }

/* --- Sections --- */
.dm-section { background: #f8fafc; border-radius: 12px; padding: 18px; }
.dm-section-title {
    font-size: .8rem; font-weight: 700; color: #6366f1;
    text-transform: uppercase; letter-spacing: .06em; margin-bottom: 12px;
    display: flex; align-items: center; gap: 6px;
}
.dm-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
@media (max-width: 600px) { .dm-two-col { grid-template-columns: 1fr; } }
.dm-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px,1fr)); gap: 10px; }
.dm-info-item label { display: block; font-size: .74rem; color: #888; margin-bottom: 2px; }
.dm-info-item span  { font-size: .9rem; font-weight: 600; color: #1e293b; }
.dm-content-box {
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 14px; line-height: 1.7; color: #374151; font-size: .9rem;
    white-space: pre-wrap; word-break: break-word;
}

/* --- Timeline --- */
.dm-timeline { display: flex; flex-direction: column; gap: 0; }
.dm-tl-item { display: flex; gap: 14px; position: relative; }
.dm-tl-item:not(:last-child) .dm-tl-line { flex: 1; }
.dm-tl-indicator {
    display: flex; flex-direction: column; align-items: center; flex-shrink: 0;
}
.dm-tl-dot {
    width: 38px; height: 38px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
    background: #e0e7ff; border: 2px solid #c7d2fe;
}
.dm-tl-item.done   .dm-tl-dot { background: #d1fae5; border-color: #6ee7b7; }
.dm-tl-item.reject .dm-tl-dot { background: #fee2e2; border-color: #fca5a5; }
.dm-tl-item.pending .dm-tl-dot { background: #f1f5f9; border-color: #cbd5e1; }
.dm-tl-line { width: 2px; background: #e2e8f0; margin: 4px auto; min-height: 16px; }
.dm-tl-item.done .dm-tl-line { background: #6ee7b7; }
.dm-tl-content { padding-bottom: 18px; flex: 1; }
.dm-tl-title { font-weight: 700; font-size: .9rem; color: #1e293b; }
.dm-tl-item.pending .dm-tl-title { color: #94a3b8; }
.dm-tl-date  { font-size: .78rem; color: #94a3b8; margin-top: 2px; }
.dm-tl-sub   { font-size: .82rem; color: #64748b; margin-top: 4px; }
.dm-tl-detail { font-size: .82rem; color: #475569; margin-top: 4px;
    background: #fff; border-left: 3px solid #6366f1; padding: 6px 10px; border-radius: 0 6px 6px 0; }
.dm-tl-score { display: flex; gap: 4px; margin-top: 4px; }
.dm-star { font-size: 1rem; }
.dm-star.filled { color: #f59e0b; }
.dm-star.empty  { color: #d1d5db; }

/* --- Images --- */
.dm-img-label { font-size: .8rem; color: #6366f1; font-weight: 600; margin-bottom: 8px; }
.dm-img-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.dm-img-item {
    width: 90px; height: 90px; border-radius: 8px; overflow: hidden;
    cursor: pointer; border: 2px solid #e2e8f0; position: relative;
    transition: transform .2s;
}
.dm-img-item:hover { transform: scale(1.05); border-color: #6366f1; }
.dm-img-item img { width: 100%; height: 100%; object-fit: cover; }
.dm-no-img { color: #94a3b8; font-size: .85rem; font-style: italic; }

/* --- btn-view-detail --- */
.btn-view-detail {
    background: #6366f1; color: #fff; border: none; border-radius: 8px;
    padding: 6px 12px; font-size: .82rem; cursor: pointer;
    margin-bottom: 4px; transition: background .2s; white-space: nowrap;
}
.btn-view-detail:hover { background: #4f46e5; }
</style>

<script>
// ===== DETAIL MODAL =====
function openDetailModal(id) {
    const overlay = document.getElementById('detailModal');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    // reset
    document.getElementById('dm-body').innerHTML = '<div class="dm-loading">⏳ กำลังโหลดข้อมูล...</div>';
    document.getElementById('dm-title').textContent = '-';
    document.getElementById('dm-badges').innerHTML = '';
    document.getElementById('dm-id').textContent = '#' + id;
    document.getElementById('dm-type-icon').textContent = '📋';

    fetch('assign-complaint.php?ajax_detail=' + id)
        .then(r => r.json())
        .then(d => {
            if (!d.success) {
                document.getElementById('dm-body').innerHTML = '<div class="dm-error">❌ ' + d.message + '</div>';
                return;
            }
            renderDetailModal(d);
        })
        .catch(() => {
            document.getElementById('dm-body').innerHTML = '<div class="dm-error">❌ เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
        });
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('open');
    document.body.style.overflow = '';
}

function renderDetailModal(d) {
    // Header
    document.getElementById('dm-type-icon').textContent = d.typeIcon;
    document.getElementById('dm-title').textContent = d.title;
    document.getElementById('dm-id').textContent = '#' + d.id;

    const statusColors = {0:'status-0',1:'status-1',2:'status-2',3:'status-3',4:'status-4'};
    const levelColors  = {0:'level-0',1:'level-1',2:'level-2',3:'level-3',4:'level-4',5:'level-5'};
    const levelIcons   = {0:'⚪',1:'🟢',2:'🔵',3:'⚠️',4:'🚨',5:'🔥'};
    document.getElementById('dm-badges').innerHTML =
        `<span class="dm-badge ${statusColors[d.status]||''}">${d.statusText}</span>` +
        `<span class="dm-badge ${levelColors[d.level]||''}">${levelIcons[d.level]||''} ${d.levelText}</span>` +
        (d.isAnonymous ? '<span class="dm-badge">🕶️ ไม่ระบุตัวตน</span>' : '<span class="dm-badge">👤 ระบุตัวตน</span>');

    // Format date
    function fmtDate(s) {
        if (!s) return '-';
        const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        const dt = new Date(s); if (isNaN(dt)) return s;
        return dt.getDate() + ' ' + months[dt.getMonth()] + ' ' + (dt.getFullYear()+543);
    }

    // Build body
    let html = '';

    // --- ข้อมูลพื้นฐาน ---
    html += `<div class="dm-section">
        <div class="dm-section-title">📋 รายละเอียดข้อร้องเรียน</div>
        <div class="dm-info-grid" style="margin-bottom:12px;">
            <div class="dm-info-item"><label>วันที่แจ้ง</label><span>📅 ${fmtDate(d.date)}</span></div>
            <div class="dm-info-item"><label>ประเภท</label><span>${d.typeIcon} ${d.typeInfor}</span></div>
        </div>
        <div class="dm-content-box">${escHtml(d.detail)}</div>
    </div>`;

    // --- ผู้ร้องเรียน ---
    if (!d.isAnonymous && d.stuName) {
        html += `<div class="dm-section">
            <div class="dm-section-title">👤 ข้อมูลผู้ร้องเรียน</div>
            <div class="dm-info-grid">
                <div class="dm-info-item"><label>ชื่อ-นามสกุล</label><span>${escHtml(d.stuName)}</span></div>
                <div class="dm-info-item"><label>รหัสนักศึกษา</label><span>${escHtml(d.stuId||'-')}</span></div>
                ${d.stuTel ? `<div class="dm-info-item"><label>เบอร์โทร</label><span>${escHtml(d.stuTel)}</span></div>` : ''}
                ${d.stuEmail ? `<div class="dm-info-item"><label>อีเมล</label><span>${escHtml(d.stuEmail)}</span></div>` : ''}
                <div class="dm-info-item"><label>สาขา</label><span>${escHtml(d.majorName)}</span></div>
                <div class="dm-info-item"><label>คณะ</label><span>${escHtml(d.facultyName)}</span></div>
            </div>
        </div>`;
    } else if (d.isAnonymous) {
        html += `<div class="dm-section">
            <div class="dm-section-title">👤 ข้อมูลผู้ร้องเรียน</div>
            <div style="color:#94a3b8;font-style:italic;">🕶️ ผู้ร้องเรียนไม่ระบุตัวตน</div>
        </div>`;
    }

    // --- ผู้รับผิดชอบ ---
    html += `<div class="dm-section">
        <div class="dm-section-title">👥 ผู้รับผิดชอบ</div>
        ${d.assignedName
            ? `<div class="dm-info-grid">
                <div class="dm-info-item"><label>ชื่อ</label><span>${escHtml(d.assignedName)}</span></div>
                <div class="dm-info-item"><label>ตำแหน่ง</label><span>${escHtml(d.assignedPos||'-')}</span></div>
               </div>`
            : '<div style="color:#94a3b8;font-style:italic;">- ยังไม่ได้มอบหมาย -</div>'
        }
    </div>`;

    // --- ภาพประกอบ ---
    const hasImages = d.studentImages.length > 0 || d.teacherImages.length > 0;
    html += `<div class="dm-section">
        <div class="dm-section-title">🖼️ ภาพประกอบ</div>`;
    if (hasImages) {
        if (d.studentImages.length > 0) {
            html += `<div class="dm-img-label">📎 จากผู้ร้องเรียน (${d.studentImages.length} รูป)</div>
                <div class="dm-img-grid">`;
            d.studentImages.forEach(img => {
                html += `<div class="dm-img-item" onclick="window.open('${escHtml(img.path)}','_blank')" title="${escHtml(img.name)}">
                    <img src="${escHtml(img.path)}" alt="${escHtml(img.name)}" onerror="this.parentElement.style.display='none'">
                </div>`;
            });
            html += '</div>';
        }
        if (d.teacherImages.length > 0) {
            html += `<div class="dm-img-label" style="margin-top:10px;">✅ จากเจ้าหน้าที่ (${d.teacherImages.length} รูป)</div>
                <div class="dm-img-grid">`;
            d.teacherImages.forEach(img => {
                html += `<div class="dm-img-item" onclick="window.open('${escHtml(img.path)}','_blank')" title="${escHtml(img.name)}">
                    <img src="${escHtml(img.path)}" alt="${escHtml(img.name)}" onerror="this.parentElement.style.display='none'">
                </div>`;
            });
            html += '</div>';
        }
    } else {
        html += '<div class="dm-no-img">ไม่มีภาพประกอบ</div>';
    }
    html += '</div>';

    // --- Timeline ---
    html += `<div class="dm-section">
        <div class="dm-section-title">⏱️ สถานะการดำเนินการ</div>
        <div class="dm-timeline">`;

    d.timeline.forEach((item, idx) => {
        const isLast = idx === d.timeline.length - 1;
        const cls = item.step === 'rejected' ? 'reject' : (item.completed ? 'done' : 'pending');
        html += `<div class="dm-tl-item ${cls}">
            <div class="dm-tl-indicator">
                <div class="dm-tl-dot">${item.icon}</div>
                ${!isLast ? '<div class="dm-tl-line"></div>' : ''}
            </div>
            <div class="dm-tl-content">
                <div class="dm-tl-title">${item.title}</div>
                ${item.date ? `<div class="dm-tl-date">${fmtDate(item.date)}</div>` : ''}
                ${item.staff ? `<div class="dm-tl-sub">👤 ${escHtml(item.staff)}</div>` : ''}
                ${item.detail ? `<div class="dm-tl-detail">${escHtml(item.detail)}</div>` : ''}
                ${item.score != null ? (() => {
                    let stars = '';
                    for (let i = 1; i <= 5; i++) {
                        stars += `<span class="dm-star ${i <= item.score ? 'filled' : 'empty'}">★</span>`;
                    }
                    return `<div class="dm-tl-sub">คะแนนความพึงพอใจ: <span class="dm-tl-score">${stars}</span></div>`;
                })() : ''}
            </div>
        </div>`;
    });

    html += '</div></div>';

    document.getElementById('dm-body').innerHTML = html;
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetailModal(); });
</script>
</body>

</html>