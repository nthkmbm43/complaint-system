<?php
// หน้ารายงาน - reports.php
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์
requireLogin();
requireStaffAccess();
requireRole(['teacher']);

$db = getDB();
$user = getCurrentUser();
$currentRole = $_SESSION['user_role'];

// ระดับสิทธิ์ของผู้ใช้งาน
$userPermission = (int)($_SESSION['permission'] ?? 1);
$userUnitId     = (int)($_SESSION['unit_id'] ?? 0);
$userUnitType   = $_SESSION['unit_type'] ?? ''; // 'faculty' | 'major' | 'department'

// สิทธิ์ 2-3 เห็นข้อมูลทั้งหมด / สิทธิ์ 1 เห็นเฉพาะหน่วยงานตัวเอง
$isAdminOrSupervisor = ($userPermission >= 2);

// ดึงข้อมูลสำหรับ dropdown (เฉพาะสิทธิ์ 2-3)
$types       = $db->fetchAll("SELECT Type_id, Type_infor, Type_icon FROM type ORDER BY Type_id");
$faculties   = $db->fetchAll("SELECT Unit_id, Unit_name, Unit_icon FROM organization_unit WHERE Unit_type = 'faculty' ORDER BY Unit_name");
$departments = $db->fetchAll("SELECT Unit_id, Unit_name, Unit_icon FROM organization_unit WHERE Unit_type = 'department' ORDER BY Unit_name");

// ===== รับค่า Filter จาก GET =====
$filter_type       = isset($_GET['type_id'])       ? $_GET['type_id']             : '';
$filter_faculty    = isset($_GET['faculty_id'])    ? intval($_GET['faculty_id'])  : 0;
$filter_major      = isset($_GET['major_id'])      ? intval($_GET['major_id'])    : 0;
$filter_department = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$filter_date_from  = isset($_GET['date_from'])     ? $_GET['date_from']           : '';
$filter_date_to    = isset($_GET['date_to'])       ? $_GET['date_to']             : '';
$filter_status     = isset($_GET['status'])        ? $_GET['status']              : '';
$filter_identity   = isset($_GET['identity'])      ? $_GET['identity']            : '';
$filter_evaluation = isset($_GET['evaluation'])    ? $_GET['evaluation']          : '';

// ===== สร้าง WHERE Conditions =====
$whereConditions = [];
$params = [];

// --- สิทธิ์ 1: ล็อกข้อมูลตามหน่วยงานของตัวเอง ---
if (!$isAdminOrSupervisor && $userUnitId > 0) {
    if ($userUnitType === 'major') {
        // สาขา → กรองนักศึกษาในสาขานั้น
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $userUnitId;
    } elseif ($userUnitType === 'faculty') {
        // คณะ → กรองนักศึกษาทุกสาขาในคณะ
        $whereConditions[] = "(s.Unit_id = ? OR s.Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?))";
        $params[] = $userUnitId;
        $params[] = $userUnitId;
    } elseif ($userUnitType === 'department') {
        // แผนก/หน่วยงาน → กรองนักศึกษาในแผนกนั้น
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $userUnitId;
    }
}

// --- สิทธิ์ 2-3: ใช้ Filter จาก UI ---
if ($isAdminOrSupervisor) {
    if ($filter_department > 0) {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $filter_department;
    } elseif ($filter_major > 0) {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $filter_major;
    } elseif ($filter_faculty > 0) {
        $whereConditions[] = "(s.Unit_id = ? OR s.Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?))";
        $params[] = $filter_faculty;
        $params[] = $filter_faculty;
    }
}

// --- Filter ร่วมทุกสิทธิ์ ---
if ($filter_type !== '') {
    $whereConditions[] = "r.Type_id = ?";
    $params[] = $filter_type;
}
if ($filter_status !== '') {
    $whereConditions[] = "r.Re_status = ?";
    $params[] = $filter_status;
}
if ($filter_identity !== '') {
    $whereConditions[] = "r.Re_iden = ?";
    $params[] = $filter_identity;
}
if (!empty($filter_date_from)) {
    $whereConditions[] = "r.Re_date >= ?";
    $params[] = $filter_date_from;
}
if (!empty($filter_date_to)) {
    $whereConditions[] = "r.Re_date <= ?";
    $params[] = $filter_date_to;
}
if ($filter_evaluation !== '') {
    if ($filter_evaluation === 'has_rating') {
        $whereConditions[] = "e.Eva_score IS NOT NULL AND e.Eva_score > 0";
    } elseif ($filter_evaluation === 'no_rating') {
        $whereConditions[] = "(e.Eva_score IS NULL OR e.Eva_score = 0)";
    } elseif (is_numeric($filter_evaluation)) {
        $whereConditions[] = "e.Eva_score = ?";
        $params[] = intval($filter_evaluation);
    }
}

$whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

// ===== Query หลัก =====
$reportData = $db->fetchAll("
    SELECT
        r.Re_id,
        r.Re_title,
        r.Re_infor,
        r.Re_status,
        r.Re_level,
        r.Re_date,
        r.Re_iden,
        t.Type_infor,
        t.Type_icon,
        s.Stu_id,
        s.Stu_name,
        ou.Unit_name  as Student_unit_name,
        ou.Unit_type  as Student_unit_type,
        parent_ou.Unit_name as Parent_unit_name,
        e.Eva_score,
        CASE
            WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
            ELSE s.Stu_name
        END as requester_name,
        CASE r.Re_status
            WHEN '0' THEN 'รอยืนยัน'
            WHEN '1' THEN 'กำลังดำเนินการ'
            WHEN '2' THEN 'รอประเมินผล'
            WHEN '3' THEN 'เสร็จสิ้น'
            WHEN '4' THEN 'ปฏิเสธ'
            ELSE 'ไม่ทราบสถานะ'
        END as status_text,
        CASE r.Re_level
            WHEN '0' THEN 'รอพิจารณา'
            WHEN '1' THEN 'ไม่เร่งด่วน'
            WHEN '2' THEN 'ปกติ'
            WHEN '3' THEN 'เร่งด่วน'
            WHEN '4' THEN 'เร่งด่วนมาก'
            WHEN '5' THEN 'วิกฤต/ฉุกเฉิน'
            ELSE 'ไม่ทราบ'
        END as level_text
    FROM request r
    LEFT JOIN type t ON r.Type_id = t.Type_id
    LEFT JOIN student s ON r.Stu_id = s.Stu_id
    LEFT JOIN organization_unit ou ON s.Unit_id = ou.Unit_id
    LEFT JOIN organization_unit parent_ou ON ou.Unit_parent_id = parent_ou.Unit_id
    LEFT JOIN evaluation e ON r.Re_id = e.Re_id
    $whereClause
    ORDER BY r.Re_date DESC, r.Re_id DESC
", $params);

// ===== คำนวณสถิติ =====
$totalCount   = count($reportData);
$statusCounts = ['0' => 0, '1' => 0, '2' => 0, '3' => 0, '4' => 0];
$typeCounts   = [];
$levelCounts  = ['0' => 0, '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0];

foreach ($reportData as $row) {
    if (isset($statusCounts[$row['Re_status']])) $statusCounts[$row['Re_status']]++;
    if (isset($levelCounts[$row['Re_level']]))   $levelCounts[$row['Re_level']]++;
    $typeKey = $row['Type_infor'] ?? 'ไม่ระบุ';
    $typeCounts[$typeKey] = ($typeCounts[$typeKey] ?? 0) + 1;
}

// ===== ข้อความแสดงขอบเขตของสิทธิ์ 1 =====
$scopeLabel = '';
if (!$isAdminOrSupervisor && $userUnitId > 0) {
    $unitInfo = $db->fetch("SELECT Unit_name, Unit_type FROM organization_unit WHERE Unit_id = ?", [$userUnitId]);
    if ($unitInfo) {
        $typeMap = ['faculty' => 'คณะ', 'major' => 'สาขา', 'department' => 'แผนก/หน่วยงาน'];
        $scopeLabel = ($typeMap[$unitInfo['Unit_type']] ?? '') . ': ' . $unitInfo['Unit_name'];
    }
}

$pageTitle = 'รายงานข้อร้องเรียน';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - ระบบข้อร้องเรียน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Sarabun', sans-serif; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; overflow-x: hidden; }
        .dashboard-container { min-height: 100vh; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .main-content { margin-left: 0; padding-top: 80px; min-height: 100vh; }
        .report-content { max-width: 1600px; margin: 0 auto; padding: 20px; }

        /* Page Title */
        .page-title {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px 30px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;
        }
        .page-title h1 { font-size: 28px; font-weight: bold; color: #2d3748; display: flex; align-items: center; gap: 10px; }

        /* Scope Badge */
        .scope-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white; padding: 8px 18px; border-radius: 50px;
            font-size: 14px; font-weight: 600;
        }

        /* Export Button */
        .export-btn {
            background: linear-gradient(135deg, #e53e3e, #c53030); color: white; border: none;
            padding: 12px 25px; border-radius: 10px; font-size: 16px; font-weight: 600;
            cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.3s ease;
        }
        .export-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(229,62,62,0.4); }

        /* Filter Card */
        .filter-card {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        .filter-title { font-size: 18px; font-weight: bold; color: #2d3748; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .filter-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 20px; }
        @media (max-width: 1200px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 900px)  { .filter-grid { grid-template-columns: repeat(2, 1fr); } }
        .filter-group { display: flex; flex-direction: column; gap: 8px; }
        .filter-group label { font-weight: 600; color: #4a5568; font-size: 14px; }
        .filter-group select, .filter-group input {
            padding: 12px 15px; border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; transition: all 0.3s ease; background: white;
        }
        .filter-group select:focus, .filter-group input:focus {
            outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }

        /* Star Dropdown */
        .star-dropdown { position: relative; width: 100%; }
        .star-dropdown-btn {
            width: 100%; padding: 12px 15px; border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 14px; background: white; cursor: pointer; text-align: left;
            display: flex; align-items: center; justify-content: space-between;
            transition: all 0.3s ease; font-family: 'Sarabun', sans-serif;
        }
        .star-dropdown-btn:hover, .star-dropdown-btn.open { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.2); }
        .star-dropdown-btn .arrow { font-size: 11px; color: #888; transition: transform 0.2s; flex-shrink: 0; }
        .star-dropdown-btn.open .arrow { transform: rotate(180deg); }
        .star-dropdown-list {
            display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: white; border: 2px solid #667eea; border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 999; overflow: hidden;
        }
        .star-dropdown-list.open { display: block; }
        .star-dropdown-item {
            padding: 10px 15px; cursor: pointer; display: flex; align-items: center;
            gap: 0; font-size: 14px; transition: background 0.15s; white-space: nowrap;
        }
        .star-dropdown-item:hover { background: #667eea; color: white; }
        .star-dropdown-item:hover .star-item-num, .star-dropdown-item:hover .star-item-label { color: white; }
        .star-dropdown-item:hover .star-empty { color: rgba(255,255,255,0.5); }
        .star-dropdown-item.selected { background: #eef1ff; font-weight: 600; color: #667eea; }
        .star-item-num   { width: 20px; font-weight: 700; color: #444; flex-shrink: 0; }
        .star-item-label { width: 110px; flex-shrink: 0; color: #555; }
        .star-item-stars { display: flex; gap: 2px; align-items: center; }
        .star-item-stars span { font-size: 16px; line-height: 1; }
        .star-full  { color: #f5a623; }
        .star-empty { color: #ddd; }
        input[name="evaluation"] { display: none; }
        .star-dropdown-divider { border: none; border-top: 1px solid #eee; margin: 4px 0; }

        .filter-actions { display: flex; gap: 15px; flex-wrap: wrap; }
        .btn-filter {
            background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none;
            padding: 12px 25px; border-radius: 10px; font-size: 14px; font-weight: 600;
            cursor: pointer; transition: all 0.3s ease;
        }
        .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 5px 20px rgba(102,126,234,0.4); }
        .btn-reset {
            background: #718096; color: white; border: none; padding: 12px 25px;
            border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease;
        }
        .btn-reset:hover { background: #4a5568; }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: rgba(255,255,255,0.95); border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .stat-card.total      { border-left: 4px solid #667eea; }
        .stat-card.pending    { border-left: 4px solid #ecc94b; }
        .stat-card.processing { border-left: 4px solid #4299e1; }
        .stat-card.waiting    { border-left: 4px solid #ed8936; }
        .stat-card.completed  { border-left: 4px solid #48bb78; }
        .stat-card.rejected   { border-left: 4px solid #fc8181; }
        .stat-number { font-size: 32px; font-weight: bold; color: #2d3748; }
        .stat-label  { font-size: 14px; color: #718096; margin-top: 5px; }

        /* Charts */
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; margin-bottom: 25px; }
        .chart-card {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px; box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        .chart-title { font-size: 16px; font-weight: bold; color: #2d3748; margin-bottom: 20px; }
        .chart-container { height: 300px; }

        /* Table */
        .table-card {
            background: rgba(255,255,255,0.95); backdrop-filter: blur(10px);
            border-radius: 20px; padding: 25px; box-shadow: 0 8px 32px rgba(0,0,0,0.1); overflow: hidden;
        }
        .table-title { font-size: 18px; font-weight: bold; color: #2d3748; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .table-wrapper { overflow-x: auto; }
        .report-table { width: 100%; border-collapse: collapse; min-width: 1000px; }
        .report-table th {
            background: linear-gradient(135deg, #667eea, #764ba2); color: white;
            padding: 15px 12px; text-align: left; font-weight: 600; font-size: 14px; white-space: nowrap;
        }
        .report-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #4a5568; }
        .report-table tr:hover { background: rgba(102,126,234,0.05); }

        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .status-0 { background: #fef3c7; color: #d97706; }
        .status-1 { background: #dbeafe; color: #2563eb; }
        .status-2 { background: #fed7aa; color: #ea580c; }
        .status-3 { background: #d1fae5; color: #059669; }
        .status-4 { background: #fecaca; color: #dc2626; }

        .level-badge { padding: 4px 10px; border-radius: 15px; font-size: 11px; font-weight: 600; }
        .level-0 { background: #e2e8f0; color: #64748b; }
        .level-1 { background: #d1fae5; color: #059669; }
        .level-2 { background: #dbeafe; color: #2563eb; }
        .level-3 { background: #fef3c7; color: #d97706; }
        .level-4 { background: #fed7aa; color: #ea580c; }
        .level-5 { background: #fecaca; color: #dc2626; }

        .no-data { text-align: center; padding: 50px; color: #718096; font-size: 16px; }

        /* Loading overlay */
        .loading-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center;
        }
        .loading-spinner { background: white; padding: 30px 50px; border-radius: 15px; text-align: center; }
        .spinner {
            width: 50px; height: 50px; border: 5px solid #e2e8f0; border-top-color: #667eea;
            border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 768px) {
            .report-content { padding: 15px; }
            .page-title { flex-direction: column; text-align: center; }
            .filter-grid { grid-template-columns: 1fr; }
            .charts-grid { grid-template-columns: 1fr; }
            .filter-actions { justify-content: center; }
        }
    </style>
</head>
<body class="dashboard-container">

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner"></div>
            <p>กำลังสร้าง PDF...</p>
        </div>
    </div>

    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied("<?php echo $accessDeniedMessage; ?>", "<?php echo $accessDeniedRedirect; ?>");
            });
        </script>
    <?php endif; ?>

    <div class="main-content" id="mainContent">
        <div class="report-content">

            <!-- Page Title -->
            <div class="page-title">
                <div>
                    <h1>📊 <?php echo $pageTitle; ?></h1>
                    <?php if (!$isAdminOrSupervisor && $scopeLabel): ?>
                        <div style="margin-top: 8px;">
                            <span class="scope-badge">📌 ขอบเขต: <?php echo htmlspecialchars($scopeLabel); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <button class="export-btn" onclick="exportPDF()">
                    📄 ส่งออก PDF
                </button>
            </div>

            <!-- Filter Section (เฉพาะสิทธิ์ 2-3 เห็น filter คณะ/สาขา/แผนก) -->
            <div class="filter-card">
                <div class="filter-title">🔍 ตัวกรองข้อมูล</div>
                <form method="GET" action="" id="filterForm">
                    <div class="filter-grid">

                        <!-- ประเภทข้อร้องเรียน -->
                        <div class="filter-group">
                            <label>ประเภทข้อร้องเรียน</label>
                            <select name="type_id" id="type_id">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?php echo $type['Type_id']; ?>" <?php echo $filter_type === (string)$type['Type_id'] ? 'selected' : ''; ?>>
                                        <?php echo $type['Type_icon']; ?> <?php echo htmlspecialchars($type['Type_infor']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- สถานะ -->
                        <div class="filter-group">
                            <label>สถานะ</label>
                            <select name="status" id="status">
                                <option value="">-- ทั้งหมด --</option>
                                <option value="0" <?php echo $filter_status === '0' ? 'selected' : ''; ?>>📋 รอยืนยัน</option>
                                <option value="1" <?php echo $filter_status === '1' ? 'selected' : ''; ?>>⏳ กำลังดำเนินการ</option>
                                <option value="2" <?php echo $filter_status === '2' ? 'selected' : ''; ?>>🔔 รอประเมินผล</option>
                                <option value="3" <?php echo $filter_status === '3' ? 'selected' : ''; ?>>✅ เสร็จสิ้น</option>
                                <option value="4" <?php echo $filter_status === '4' ? 'selected' : ''; ?>>❌ ปฏิเสธ</option>
                            </select>
                        </div>

                        <!-- การระบุตัวตน -->
                        <div class="filter-group">
                            <label>การระบุตัวตน</label>
                            <select name="identity" id="identity">
                                <option value="">-- ทั้งหมด --</option>
                                <option value="0" <?php echo $filter_identity === '0' ? 'selected' : ''; ?>>👤 ระบุตัวตน</option>
                                <option value="1" <?php echo $filter_identity === '1' ? 'selected' : ''; ?>>🎭 ไม่ระบุตัวตน</option>
                            </select>
                        </div>

                        <!-- ประเมินความพึงพอใจ -->
                        <div class="filter-group">
                            <label>ประเมินความพึงพอใจ</label>
                            <input type="hidden" name="evaluation" id="evaluation_val" value="<?php echo htmlspecialchars($filter_evaluation); ?>">
                            <div class="star-dropdown" id="starDropdown">
                                <button type="button" class="star-dropdown-btn" id="starDropdownBtn" onclick="toggleStarDropdown()">
                                    <span id="starDropdownLabel">-- ทั้งหมด --</span>
                                    <span class="arrow">▼</span>
                                </button>
                                <div class="star-dropdown-list" id="starDropdownList">
                                    <div class="star-dropdown-item" onclick="selectEval('','-- ทั้งหมด --', this)">
                                        <span class="star-item-num"></span>
                                        <span class="star-item-label" style="color:#444;">-- ทั้งหมด --</span>
                                        <span class="star-item-stars"></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('has_rating','✔ มีการประเมินแล้ว', this)">
                                        <span class="star-item-num" style="color:#38a169;">✔</span>
                                        <span class="star-item-label">มีการประเมินแล้ว</span>
                                        <span class="star-item-stars"></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('no_rating','⏳ ยังไม่ได้ประเมิน', this)">
                                        <span class="star-item-num">⏳</span>
                                        <span class="star-item-label">ยังไม่ได้ประเมิน</span>
                                        <span class="star-item-stars"></span>
                                    </div>
                                    <hr class="star-dropdown-divider">
                                    <div class="star-dropdown-item" onclick="selectEval('5','5  ดีมาก', this)">
                                        <span class="star-item-num">5</span><span class="star-item-label">ดีมาก</span>
                                        <span class="star-item-stars"><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('4','4  ดี', this)">
                                        <span class="star-item-num">4</span><span class="star-item-label">ดี</span>
                                        <span class="star-item-stars"><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span><span class="star-empty">★</span></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('3','3  ปานกลาง', this)">
                                        <span class="star-item-num">3</span><span class="star-item-label">ปานกลาง</span>
                                        <span class="star-item-stars"><span class="star-full">★</span><span class="star-full">★</span><span class="star-full">★</span><span class="star-empty">★</span><span class="star-empty">★</span></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('2','2  พอใช้', this)">
                                        <span class="star-item-num">2</span><span class="star-item-label">พอใช้</span>
                                        <span class="star-item-stars"><span class="star-full">★</span><span class="star-full">★</span><span class="star-empty">★</span><span class="star-empty">★</span><span class="star-empty">★</span></span>
                                    </div>
                                    <div class="star-dropdown-item" onclick="selectEval('1','1  ต้องปรับปรุง', this)">
                                        <span class="star-item-num">1</span><span class="star-item-label">ต้องปรับปรุง</span>
                                        <span class="star-item-stars"><span class="star-full">★</span><span class="star-empty">★</span><span class="star-empty">★</span><span class="star-empty">★</span><span class="star-empty">★</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($isAdminOrSupervisor): ?>
                        <!-- คณะ (เฉพาะสิทธิ์ 2-3) -->
                        <div class="filter-group">
                            <label>คณะ</label>
                            <select name="faculty_id" id="faculty_id" onchange="loadMajors()">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?php echo $faculty['Unit_id']; ?>" <?php echo $filter_faculty == $faculty['Unit_id'] ? 'selected' : ''; ?>>
                                        <?php echo $faculty['Unit_icon']; ?> <?php echo htmlspecialchars($faculty['Unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- สาขา -->
                        <div class="filter-group">
                            <label>สาขา</label>
                            <select name="major_id" id="major_id">
                                <option value="">-- ทั้งหมด --</option>
                            </select>
                        </div>
                        <!-- แผนก -->
                        <div class="filter-group">
                            <label>แผนก/หน่วยงาน</label>
                            <select name="department_id" id="department_id">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['Unit_id']; ?>" <?php echo $filter_department == $dept['Unit_id'] ? 'selected' : ''; ?>>
                                        <?php echo $dept['Unit_icon']; ?> <?php echo htmlspecialchars($dept['Unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <!-- วันที่เริ่มต้น -->
                        <div class="filter-group">
                            <label>ตั้งแต่วันที่</label>
                            <input type="date" name="date_from" id="date_from" value="<?php echo $filter_date_from; ?>">
                        </div>
                        <!-- วันที่สิ้นสุด -->
                        <div class="filter-group">
                            <label>ถึงวันที่</label>
                            <input type="date" name="date_to" id="date_to" value="<?php echo $filter_date_to; ?>">
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn-filter">🔍 กรองข้อมูล</button>
                        <button type="button" class="btn-reset" onclick="resetFilters()">🔄 ล้างตัวกรอง</button>
                    </div>
                </form>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number"><?php echo number_format($totalCount); ?></div>
                    <div class="stat-label">ทั้งหมด</div>
                </div>
                <div class="stat-card pending">
                    <div class="stat-number"><?php echo number_format($statusCounts['0']); ?></div>
                    <div class="stat-label">รอยืนยัน</div>
                </div>
                <div class="stat-card processing">
                    <div class="stat-number"><?php echo number_format($statusCounts['1']); ?></div>
                    <div class="stat-label">กำลังดำเนินการ</div>
                </div>
                <div class="stat-card waiting">
                    <div class="stat-number"><?php echo number_format($statusCounts['2']); ?></div>
                    <div class="stat-label">รอประเมินผล</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number"><?php echo number_format($statusCounts['3']); ?></div>
                    <div class="stat-label">เสร็จสิ้น</div>
                </div>
                <div class="stat-card rejected">
                    <div class="stat-number"><?php echo number_format($statusCounts['4']); ?></div>
                    <div class="stat-label">ปฏิเสธ</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="charts-grid">
                <div class="chart-card">
                    <div class="chart-title">📊 สถิติตามสถานะ</div>
                    <div class="chart-container"><canvas id="statusChart"></canvas></div>
                </div>
                <div class="chart-card">
                    <div class="chart-title">📈 สถิติตามประเภท</div>
                    <div class="chart-container"><canvas id="typeChart"></canvas></div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="table-card">
                <div class="table-title">📋 รายการข้อร้องเรียน (<?php echo number_format($totalCount); ?> รายการ)</div>
                <div class="table-wrapper">
                    <?php if (count($reportData) > 0): ?>
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>รหัส</th>
                                    <th>หัวข้อ</th>
                                    <th>ประเภท</th>
                                    <th>ผู้ร้องเรียน</th>
                                    <th>หน่วยงาน</th>
                                    <th>วันที่</th>
                                    <th>ระดับ</th>
                                    <th>สถานะ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($reportData as $row): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo $row['Re_id']; ?></td>
                                    <td><?php echo htmlspecialchars(mb_substr($row['Re_title'] ?? $row['Re_infor'], 0, 40)); ?>...</td>
                                    <td><?php echo $row['Type_icon']; ?> <?php echo htmlspecialchars($row['Type_infor']); ?></td>
                                    <td><?php echo htmlspecialchars($row['requester_name'] ?? 'ไม่ระบุ'); ?></td>
                                    <td>
                                        <?php
                                        if ($row['Parent_unit_name']) echo htmlspecialchars($row['Parent_unit_name']) . ' / ';
                                        echo htmlspecialchars($row['Student_unit_name'] ?? 'ไม่ระบุ');
                                        ?>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($row['Re_date'])); ?></td>
                                    <td><span class="level-badge level-<?php echo $row['Re_level']; ?>"><?php echo $row['level_text']; ?></span></td>
                                    <td><span class="status-badge status-<?php echo $row['Re_status']; ?>"><?php echo $row['status_text']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data"><p>📭 ไม่พบข้อมูลตามเงื่อนไขที่กำหนด</p></div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
    // ===== Load Majors =====
    function loadMajors() {
        const facultyId = document.getElementById('faculty_id')?.value;
        const majorSelect = document.getElementById('major_id');
        if (!majorSelect) return;
        majorSelect.innerHTML = '<option value="">-- ทั้งหมด --</option>';
        if (facultyId) {
            fetch('ajax/get_units.php?faculty_id=' + facultyId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.majors) {
                        data.majors.forEach(major => {
                            const opt = document.createElement('option');
                            opt.value = major.Unit_id;
                            opt.textContent = (major.Unit_icon || '') + ' ' + major.Unit_name;
                            <?php if ($filter_major > 0): ?>
                            if (major.Unit_id == <?php echo $filter_major; ?>) opt.selected = true;
                            <?php endif; ?>
                            majorSelect.appendChild(opt);
                        });
                    }
                })
                .catch(e => console.error('Error:', e));
        }
    }

    // ===== Star Dropdown =====
    function toggleStarDropdown() {
        document.getElementById('starDropdownBtn').classList.toggle('open');
        document.getElementById('starDropdownList').classList.toggle('open');
    }
    function selectEval(val, label, el) {
        document.getElementById('evaluation_val').value = val;
        document.getElementById('starDropdownLabel').textContent = label || '-- ทั้งหมด --';
        document.querySelectorAll('.star-dropdown-item').forEach(i => i.classList.remove('selected'));
        if (el) el.classList.add('selected');
        document.getElementById('starDropdownBtn').classList.remove('open');
        document.getElementById('starDropdownList').classList.remove('open');
    }
    document.addEventListener('click', function(e) {
        const dd = document.getElementById('starDropdown');
        if (dd && !dd.contains(e.target)) {
            document.getElementById('starDropdownBtn').classList.remove('open');
            document.getElementById('starDropdownList').classList.remove('open');
        }
    });
    (function() {
        const val = document.getElementById('evaluation_val')?.value;
        const labelMap = { 'has_rating': '✔ มีการประเมินแล้ว', 'no_rating': '⏳ ยังไม่ได้ประเมิน', '5': '5  ดีมาก', '4': '4  ดี', '3': '3  ปานกลาง', '2': '2  พอใช้', '1': '1  ต้องปรับปรุง' };
        if (val && labelMap[val]) {
            document.getElementById('starDropdownLabel').textContent = labelMap[val];
            document.querySelectorAll('.star-dropdown-item').forEach(item => {
                if ((item.getAttribute('onclick') || '').includes("'" + val + "'")) item.classList.add('selected');
            });
        }
    })();

    // ===== Reset =====
    function resetFilters() { window.location.href = 'reports.php'; }

    // ===== Export PDF (ส่ง filter ทั้งหมดไปด้วย) =====
    function exportPDF() {
        document.getElementById('loadingOverlay').style.display = 'flex';
        const params = new URLSearchParams(window.location.search);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'ajax/export_pdf.php';
        form.target = '_blank';

        // ส่ง filter ที่เลือกไว้ทั้งหมด
        const fields = ['type_id', 'status', 'identity', 'faculty_id', 'major_id', 'department_id', 'date_from', 'date_to'];
        fields.forEach(key => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = key;
            inp.value = params.get(key) || document.getElementById(key)?.value || '';
            form.appendChild(inp);
        });
        // evaluation ใช้จาก hidden input
        const evalInp = document.createElement('input');
        evalInp.type = 'hidden'; evalInp.name = 'evaluation';
        evalInp.value = document.getElementById('evaluation_val')?.value || '';
        form.appendChild(evalInp);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        setTimeout(() => { document.getElementById('loadingOverlay').style.display = 'none'; }, 3000);
    }

    // ===== Init Charts =====
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($filter_faculty > 0): ?> loadMajors(); <?php endif; ?>

        // ลงทะเบียน plugin datalabels กับทุก chart
        Chart.register(ChartDataLabels);

        // ─── Status Doughnut ───
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusData = [<?php echo implode(',', $statusCounts); ?>];
            const total = statusData.reduce((a, b) => a + b, 0);

            new Chart(statusCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['รอยืนยัน', 'กำลังดำเนินการ', 'รอประเมินผล', 'เสร็จสิ้น', 'ปฏิเสธ'],
                    datasets: [{
                        data: statusData,
                        backgroundColor: [
                            'rgba(234,179,8,0.85)',
                            'rgba(59,130,246,0.85)',
                            'rgba(249,115,22,0.85)',
                            'rgba(34,197,94,0.85)',
                            'rgba(239,68,68,0.85)'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        datalabels: {
                            display: function(context) {
                                // ซ่อน label ถ้าค่าเป็น 0
                                return context.dataset.data[context.dataIndex] > 0;
                            },
                            color: '#000',
                            font: { weight: 'bold', size: 13 },
                            formatter: function(value, context) {
                                const pct = total > 0 ? Math.round((value / total) * 100) : 0;
                                return value + '\n(' + pct + '%)';
                            },
                            textAlign: 'center'
                        }
                    }
                }
            });
        }

        // ─── Type Bar ───
        const typeCtx = document.getElementById('typeChart');
        if (typeCtx) {
            const barData = [<?php echo implode(',', array_values($typeCounts)); ?>];

            new Chart(typeCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: [<?php echo "'" . implode("','", array_map('addslashes', array_keys($typeCounts))) . "'"; ?>],
                    datasets: [{
                        label: 'จำนวน',
                        data: barData,
                        backgroundColor: 'rgba(102,126,234,0.8)',
                        borderColor: 'rgba(102,126,234,1)',
                        borderWidth: 1,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: {
                        // เพิ่มพื้นที่ด้านบนให้ตัวเลขไม่ถูกตัด
                        padding: { top: 20 }
                    },
                    plugins: {
                        legend: { display: false },
                        datalabels: {
                            display: function(context) {
                                return context.dataset.data[context.dataIndex] > 0;
                            },
                            anchor: 'end',   // วางที่ปลายแท่ง
                            align: 'end',    // แสดงเหนือแท่ง
                            offset: 2,
                            color: '#1a1a2e',
                            font: { weight: 'bold', size: 12 },
                            formatter: function(value) { return value; }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            // เพิ่ม max เล็กน้อยให้ตัวเลขบนแท่งสูงสุดไม่ถูกตัด
                            grace: '10%'
                        }
                    }
                }
            });
        }
    });
    </script>
</body>
</html>