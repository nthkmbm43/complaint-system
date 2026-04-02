<?php
// staff/manage-complaints.php - ระบบจัดการข้อร้องเรียน พร้อม Modal ปฏิเสธและแจ้งเตือนอีเมล
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบสิทธิ์
requireLogin();
requireStaffAccess();
requireRole(['teacher']);

$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission < 2) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดูแลระบบเท่านั้น หากต้องการดูข้อร้องเรียนของคุณ กรุณาใช้หน้างานที่ได้รับมอบหมายแทน";
    $accessDeniedRedirect = "my-assignments.php";
}

$db = getDB();
$user = getCurrentUser();

$isAdmin       = ($userPermission >= 3); // permission 3 = เห็นทั้งหมด
$currentUnitId = $_SESSION['unit_id'] ?? null;
$unitType      = $_SESSION['unit_type'] ?? ''; // faculty | major | department

// --------------------------------------------------------------------------
// ส่วนการจัดการ Logic (Pagination & Sorting)
// --------------------------------------------------------------------------

// 1. จัดการ Pagination (บังคับเป็น int ป้องกัน Error: string * int)
$itemsPerPage = 20;
$pageParam = $_GET['page'] ?? 1;
$currentPage = (is_numeric($pageParam) && $pageParam > 0) ? (int)$pageParam : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

// 2. จัดการ Sorting
$sort = isset($_GET['sort']) && in_array(strtolower($_GET['sort']), ['asc', 'desc']) ? strtolower($_GET['sort']) : 'desc';
$nextSort = ($sort === 'desc') ? 'asc' : 'desc';
$sortIcon = ($sort === 'desc') ? '▼' : '▲';

// 3. รับค่าค้นหา
$searchTerm = trim($_GET['search'] ?? '');
$filterType = $_GET['type'] ?? '';

// --------------------------------------------------------------------------
// กำหนด scope SQL ตามสิทธิ์ผู้ใช้
//
// permission 3 (Admin/ผอ.)  → เห็นทุกคำร้อง ไม่จำกัด scope
// permission 2 (ผู้ดำเนินการ):
//   - unit_type = 'faculty'    → เห็น นศ. ทุกสาขาในคณะ (Unit_id = คณะ หรือ Unit_parent_id = คณะ)
//   - unit_type = 'major'      → เห็น นศ. เฉพาะสาขานั้นโดยตรง (s.Unit_id = unit_id)
//   - unit_type = 'department' → เห็น นศ. เฉพาะแผนก/หน่วยงานนั้นโดยตรง (s.Unit_id = unit_id)
//
// หมายเหตุ: กรณีที่ นศ. ร้องเรียนแบบไม่ระบุตัวตน (Re_iden=1, Stu_id=NULL)
//   permission 3 → เห็นทั้งหมดรวมไม่ระบุตัวตน
//   permission 2 → ไม่แสดงรายการไม่ระบุตัวตน เพราะ INNER JOIN กรอง NULL ออกอัตโนมัติ
// --------------------------------------------------------------------------
$scopeJoin   = "LEFT JOIN student s ON r.Stu_id = s.Stu_id";
$scopeWhere  = "";
$scopeParams = [];

if (!$isAdmin && $userPermission == 2) {
    if (!$currentUnitId) {
        // ไม่มี unit_id → safety fallback ไม่แสดงข้อมูลใดเลย
        $scopeJoin   = "INNER JOIN student s ON r.Stu_id = s.Stu_id";
        $scopeWhere  = " AND 1=0";
        $scopeParams = [];
    } elseif ($unitType === 'faculty') {
        // สังกัดคณะ → แสดง นศ. ทุกคนในคณะ (สาขาที่ parent = คณะ รวมถึง Unit_id = คณะ)
        $scopeJoin   = "INNER JOIN student s ON r.Stu_id = s.Stu_id";
        $scopeWhere  = " AND s.Unit_id IN (
                            SELECT ou_scope.Unit_id
                            FROM organization_unit ou_scope
                            WHERE ou_scope.Unit_id = ? OR ou_scope.Unit_parent_id = ?
                        )";
        $scopeParams = [$currentUnitId, $currentUnitId];
    } else {
        // สังกัดสาขา (major) หรือแผนก (department) → scope ตรงๆ เลย
        $scopeJoin   = "INNER JOIN student s ON r.Stu_id = s.Stu_id";
        $scopeWhere  = " AND s.Unit_id = ?";
        $scopeParams = [$currentUnitId];
    }
}
// permission 3: ใช้ค่า default (LEFT JOIN, ไม่มี scopeWhere)

// --------------------------------------------------------------------------
// [DEBUG] แสดงข้อมูล session และ scope ที่ใช้งาน (ปิดใน production)
// ลบหรือ comment บล็อกนี้ออกเมื่อทดสอบเสร็จแล้ว
// --------------------------------------------------------------------------
$debugMode = isset($_GET['debug']) && $_GET['debug'] === '1'; // เปิดด้วย ?debug=1

// --------------------------------------------------------------------------
// ส่วนการดึงข้อมูล (SQL Query)
// --------------------------------------------------------------------------
$complaints = [];
$totalComplaints = 0;
$complaintTypes = [];

try {
    // ดึงประเภทข้อร้องเรียน
    $complaintTypes = $db->fetchAll("SELECT * FROM type ORDER BY Type_id ASC");

    // --- สร้างเงื่อนไข SQL ---
    $whereSQL = "WHERE r.Re_status = '0'" . $scopeWhere;
    $params   = $scopeParams;

    // เพิ่มเงื่อนไขค้นหา
    if (!empty($searchTerm)) {
        $whereSQL .= " AND (r.Re_title LIKE ? OR r.Re_infor LIKE ? OR s.Stu_id LIKE ? OR s.Stu_name LIKE ?)";
        $likeTerm = "%" . $searchTerm . "%";
        array_push($params, $likeTerm, $likeTerm, $likeTerm, $likeTerm);
    }

    // เพิ่มเงื่อนไขประเภท
    if (!empty($filterType)) {
        $whereSQL .= " AND r.Type_id = ?";
        $params[] = $filterType;
    }

    // --- Query 1: นับจำนวนทั้งหมด (scoped) ---
    $countSql = "SELECT COUNT(*) as total 
                 FROM request r 
                 $scopeJoin 
                 $whereSQL";
    $countResult = $db->fetch($countSql, $params);
    $totalComplaints = $countResult ? (int)$countResult['total'] : 0;

    // --- Query 2: ดึงข้อมูลรายการ ---
    // หมายเหตุ: $scopeJoin (JOIN student) ต้องอยู่ก่อน LEFT JOIN type และ LEFT JOIN teacher
    // เพราะ $scopeWhere อ้างถึง s.Unit_id ซึ่งมาจาก student
    $dataSql = "SELECT r.*, t.Type_infor, t.Type_icon,
            s.Stu_name as requester_name, s.Stu_id, s.Stu_email,
            th.Aj_name as assigned_name
            FROM request r
            $scopeJoin
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN teacher th ON r.Aj_id = th.Aj_id 
            $whereSQL
            ORDER BY r.Re_id $sort 
            LIMIT $itemsPerPage OFFSET $offset";

    $complaints = $db->fetchAll($dataSql, $params);

    // --- Stats scoped (สำหรับ cards) ---
    $scopedStats = [];
    foreach (['0' => 'pending', '1' => 'confirmed', '2' => 'completed'] as $status => $key) {
        $whereStats = "WHERE r.Re_status = ?" . $scopeWhere;
        $statsParams = array_merge([$status], $scopeParams);
        $row = $db->fetch("SELECT COUNT(*) as c FROM request r $scopeJoin $whereStats", $statsParams);
        $scopedStats[$key] = (int)($row['c'] ?? 0);
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $complaints = [];
    $scopedStats = ['pending' => 0, 'confirmed' => 0, 'completed' => 0];
}

// คำนวณจำนวนหน้า (บังคับ int)
$totalPages = max(1, (int)ceil($totalComplaints / $itemsPerPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อร้องเรียน - ระบบข้อร้องเรียนนักศึกษา</title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <style>
        .main-content {
            padding-top: 70px;
        }

        .page-content {
            padding: 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 5px solid;
        }

        .stat-card.pending {
            border-left-color: #ffc107;
        }

        .stat-card.confirmed {
            border-left-color: #17a2b8;
        }

        .stat-card.completed {
            border-left-color: #28a745;
        }

        .stat-card.evaluated {
            border-left-color: #6f42c1;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
        }

        .filters-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-section {
            display: flex;
            gap: 10px;
            align-items: end;
        }

        .search-input {
            flex: 1;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
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

        .btn:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }

        .complaints-section {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .section-header {
            background: #f8f9fa;
            padding: 20px 25px;
            border-bottom: 1px solid #e1e5e9;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: bold;
            color: #333;
            margin: 0;
        }

        .complaints-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .complaints-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #e1e5e9;
        }

        .complaints-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: top;
        }

        .complaints-table tr:hover {
            background: #f8f9fa;
        }

        .sort-link {
            color: #555;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            background: #e9ecef;
            border-radius: 5px;
            transition: all 0.2s;
        }

        .sort-link:hover {
            background: #007bff;
            color: white;
        }

        .complaint-id {
            font-weight: bold;
            color: #007bff;
            text-decoration: none;
        }

        .complaint-content {
            max-width: 300px;
            line-height: 1.5;
        }

        .complaint-meta {
            font-size: 0.9rem;
            color: #666;
            margin-top: 8px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .badge.warning {
            background: #fff3cd;
            color: #856404;
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .pagination-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .page-link {
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            text-decoration: none;
            color: #007bff;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: #007bff;
            color: white;
            transform: translateY(-2px);
        }

        .page-link.active {
            background: #007bff;
            color: white;
            font-weight: bold;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        /* ============================================ */
        /* Modal Styles */
        /* ============================================ */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
            backdrop-filter: blur(4px);
        }

        .modal-container {
            background: white;
            border-radius: 15px;
            width: 90%;
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
            border-bottom: 1px solid #eee;
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
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
            font-family: inherit;
        }

        .modal-form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .modal-form-control[readonly] {
            background: #f8f9fa;
            color: #666;
            cursor: not-allowed;
        }

        .modal-form-control::placeholder {
            color: #aaa;
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

        .modal-btn-success:hover {
            background: linear-gradient(135deg, #218838, #1db988);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.4);
        }

        .modal-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
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

        .info-display {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            color: #495057;
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
            align-items: center;
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
    </style>
</head>

<body class="staff-layout">
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

    <div class="main-content" id="mainContent">
        <div class="page-content">
            <div class="container">
                <div class="page-header">
                    <div class="page-title">📋 จัดการข้อร้องเรียน</div>
                    <div class="page-subtitle">
                        รายการข้อร้องเรียนที่ "ยังไม่ได้รับเรื่อง" (รอยืนยัน)
                        <?php if ($isAdmin): ?>
                            &nbsp;<span style="background:linear-gradient(135deg,#667eea,#764ba2); color:white; font-size:0.8rem; font-weight:600; padding:3px 12px; border-radius:20px; vertical-align:middle;">
                                🌐 แสดงทุกหน่วยงาน (สิทธิ์ระดับ 3)
                            </span>
                        <?php elseif ($userPermission == 2 && $currentUnitId): ?>
                            &nbsp;<span style="background:linear-gradient(135deg,#22c55e,#16a34a); color:white; font-size:0.8rem; font-weight:600; padding:3px 12px; border-radius:20px; vertical-align:middle;">
                                <?php
                                    $scopeBadgeIcon  = htmlspecialchars($_SESSION['unit_icon'] ?? '🏢');
                                    $scopeBadgeName  = htmlspecialchars($_SESSION['unit_name'] ?? '');
                                    $scopeBadgeLabel = ($unitType === 'faculty') ? 'คณะ' : (($unitType === 'major') ? 'สาขา' : 'แผนก/หน่วยงาน');
                                    echo "$scopeBadgeIcon กรองเฉพาะ{$scopeBadgeLabel}: $scopeBadgeName";
                                ?>
                            </span>
                            &nbsp;<span style="background:#e0f2fe; color:#0369a1; font-size:0.75rem; font-weight:500; padding:3px 10px; border-radius:20px; vertical-align:middle; border:1px solid #bae6fd;">
                                สิทธิ์ระดับ 2 · เฉพาะ<?php echo $scopeBadgeLabel; ?>ตัวเอง
                            </span>
                        <?php elseif ($userPermission == 2): ?>
                            &nbsp;<span style="background:#fee2e2; color:#dc2626; font-size:0.8rem; font-weight:600; padding:3px 12px; border-radius:20px; vertical-align:middle;">
                                ⚠️ ไม่พบข้อมูลหน่วยงานของคุณ กรุณาติดต่อผู้ดูแลระบบ
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($debugMode): ?>
                <!-- ========== DEBUG PANEL (เปิดด้วย ?debug=1) ========== -->
                <div style="background:#1e1e2e; color:#cdd6f4; border-radius:10px; padding:20px; margin-bottom:20px; font-family:monospace; font-size:13px; line-height:1.8;">
                    <div style="color:#89b4fa; font-weight:bold; margin-bottom:10px;">🔍 DEBUG INFO (ลบ ?debug=1 ออกจาก URL เมื่อทดสอบเสร็จ)</div>
                    <table style="width:100%; border-collapse:collapse;">
                        <tr><td style="color:#a6e3a1; width:220px;">$_SESSION[permission]</td><td>= <?php echo htmlspecialchars((string)($userPermission)); ?></td></tr>
                        <tr><td style="color:#a6e3a1;">$isAdmin</td><td>= <?php echo $isAdmin ? 'true' : 'false'; ?></td></tr>
                        <tr><td style="color:#a6e3a1;">$_SESSION[unit_id]</td><td>= <?php echo htmlspecialchars((string)($currentUnitId ?? 'NULL')); ?></td></tr>
                        <tr><td style="color:#a6e3a1;">$_SESSION[unit_type]</td><td>= <?php echo htmlspecialchars($unitType ?: '(empty)'); ?></td></tr>
                        <tr><td style="color:#a6e3a1;">$_SESSION[unit_name]</td><td>= <?php echo htmlspecialchars($_SESSION['unit_name'] ?? '(not set)'); ?></td></tr>
                        <tr><td style="color:#fab387;">$scopeJoin</td><td>= <?php echo nl2br(htmlspecialchars(trim($scopeJoin))); ?></td></tr>
                        <tr><td style="color:#fab387;">$scopeWhere</td><td>= <?php echo htmlspecialchars(trim($scopeWhere) ?: '(none)'); ?></td></tr>
                        <tr><td style="color:#fab387;">$scopeParams</td><td>= [<?php echo htmlspecialchars(implode(', ', $scopeParams)); ?>]</td></tr>
                        <tr><td style="color:#f38ba8;">$totalComplaints</td><td>= <?php echo $totalComplaints; ?></td></tr>
                        <tr><td style="color:#cba6f7;">ALL SESSION KEYS</td><td>= <?php 
                            $safeSession = array_filter($_SESSION, fn($k) => !in_array($k, ['password','token']), ARRAY_FILTER_USE_KEY);
                            foreach ($safeSession as $k => $v) {
                                echo htmlspecialchars("[$k] => " . (is_array($v) ? json_encode($v) : (string)$v)) . "<br>";
                            }
                        ?></td></tr>
                    </table>
                </div>
                <?php endif; ?>

                <div class="stats-cards">
                    <div class="stat-card pending" style="border: 2px solid #ffc107; transform: scale(1.05);">
                        <div class="stat-number"><?php echo number_format($scopedStats['pending'] ?? 0); ?></div>
                        <div class="stat-label">รอยืนยัน (แสดงผล)</div>
                    </div>
                    <div class="stat-card confirmed" style="opacity: 0.7;">
                        <div class="stat-number"><?php echo number_format($scopedStats['confirmed'] ?? 0); ?></div>
                        <div class="stat-label">ยืนยันแล้ว</div>
                    </div>
                    <div class="stat-card completed" style="opacity: 0.7;">
                        <div class="stat-number"><?php echo number_format($scopedStats['completed'] ?? 0); ?></div>
                        <div class="stat-label">เสร็จสิ้น</div>
                    </div>
                </div>

                <div class="filters-section">
                    <form method="GET" action="">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label for="type">กรองตามประเภท</label>
                                <select name="type" id="type" class="form-control" onchange="this.form.submit()">
                                    <option value="">ทั้งหมด</option>
                                    <?php foreach ($complaintTypes as $type): ?>
                                        <option value="<?php echo $type['Type_id']; ?>"
                                            <?php echo $filterType == $type['Type_id'] ? 'selected' : ''; ?>>
                                            <?php echo $type['Type_icon'] . ' ' . htmlspecialchars($type['Type_infor']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="search-section">
                            <div class="form-group search-input">
                                <label for="search">ค้นหา</label>
                                <input type="text" name="search" id="search" class="form-control"
                                    placeholder="ค้นหาชื่อเรื่อง, รายละเอียด, หรือรหัสนักศึกษา..."
                                    value="<?php echo htmlspecialchars($searchTerm); ?>">
                            </div>
                            <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                            <a href="manage-complaints.php" class="btn btn-secondary">🔄 รีเซ็ต</a>
                        </div>
                    </form>
                </div>

                <div class="complaints-section">
                    <div class="section-header">
                        <h3 class="section-title">
                            รายการที่ยังไม่ได้รับเรื่อง (<?php echo number_format($totalComplaints); ?> รายการ)
                        </h3>
                    </div>

                    <?php if (empty($complaints)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">📭</div>
                            <h3>ไม่พบรายการใหม่</h3>
                            <p>ไม่มีข้อร้องเรียนที่สถานะ "รอยืนยัน" ในขณะนี้</p>
                        </div>
                    <?php else: ?>
                        <table class="complaints-table">
                            <thead>
                                <tr>
                                    <th width="120">
                                        <?php
                                        $urlParams = $_GET;
                                        $urlParams['sort'] = $nextSort;
                                        $sortUrl = '?' . http_build_query($urlParams);
                                        ?>
                                        <a href="<?php echo $sortUrl; ?>" class="sort-link" title="คลิกเพื่อเรียงลำดับ">
                                            รหัส <?php echo $sortIcon; ?>
                                        </a>
                                    </th>
                                    <th width="120">วันที่แจ้ง</th>
                                    <th>รายละเอียด</th>
                                    <th width="150">ผู้ร้องเรียน</th>
                                    <th width="100">ประเภท</th>
                                    <th width="100">สถานะ</th>
                                    <th width="120">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaints as $complaint): ?>
                                    <tr>
                                        <td>
                                            <a href="complaint-detail.php?id=<?php echo $complaint['Re_id']; ?>"
                                                class="complaint-id">
                                                #<?php echo $complaint['Re_id']; ?>
                                            </a>
                                        </td>
                                        <td><?php echo formatThaiDateOnly($complaint['Re_date']); ?></td>
                                        <td>
                                            <div class="complaint-content">
                                                <?php if (!empty($complaint['Re_title'])): ?>
                                                    <strong><?php echo htmlspecialchars($complaint['Re_title']); ?></strong><br>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars(truncateText($complaint['Re_infor'], 100)); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($complaint['Re_iden'] == 1): ?>
                                                <span style="color: #666;">🕶️ ไม่ระบุตัวตน</span>
                                            <?php else: ?>
                                                <strong><?php echo htmlspecialchars($complaint['requester_name']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($complaint['Stu_id']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span title="<?php echo htmlspecialchars($complaint['Type_infor']); ?>">
                                                <?php echo $complaint['Type_icon']; ?>
                                            </span>
                                        </td>
                                        <td><span class="badge warning">รอยืนยัน</span></td>
                                        <td>
                                            <div class="actions">
                                                <a href="complaint-detail.php?id=<?php echo $complaint['Re_id']; ?>"
                                                    class="btn btn-sm btn-info" title="ดูรายละเอียด">
                                                    👁️
                                                </a>
                                                <button onclick="acceptComplaint(<?php echo $complaint['Re_id']; ?>)"
                                                    class="btn btn-sm btn-success" title="รับเรื่อง">
                                                    ✅
                                                </button>
                                                <button onclick="openRejectModal(<?php echo $complaint['Re_id']; ?>)"
                                                    class="btn btn-sm btn-danger" title="ปฏิเสธ">
                                                    ❌
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php if ($totalComplaints > 0): ?>
                    <div class="pagination-container">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="pagination-info">
                                📊 แสดง <strong><?php echo number_format(min(((int)$offset + 1), (int)$totalComplaints)); ?></strong> -
                                <strong><?php echo number_format(min(((int)$currentPage * (int)$itemsPerPage), (int)$totalComplaints)); ?></strong>
                                จาก <strong><?php echo number_format((int)$totalComplaints); ?></strong> รายการ
                            </div>

                            <?php if ($totalPages > 1): ?>
                                <div class="pagination">
                                    <?php
                                    $pageUrlParams = $_GET;

                                    if ($currentPage > 1) {
                                        $pageUrlParams['page'] = 1;
                                        echo '<a href="?' . http_build_query($pageUrlParams) . '" class="page-link">หน้าแรก</a>';
                                        $pageUrlParams['page'] = $currentPage - 1;
                                        echo '<a href="?' . http_build_query($pageUrlParams) . '" class="page-link">ก่อนหน้า</a>';
                                    }

                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPages, $currentPage + 2);
                                    for ($i = $startPage; $i <= $endPage; $i++) {
                                        $pageUrlParams['page'] = $i;
                                        $activeClass = ($i === $currentPage) ? 'active' : '';
                                        echo '<a href="?' . http_build_query($pageUrlParams) . '" class="page-link ' . $activeClass . '">' . $i . '</a>';
                                    }

                                    if ($currentPage < $totalPages) {
                                        $pageUrlParams['page'] = $currentPage + 1;
                                        echo '<a href="?' . http_build_query($pageUrlParams) . '" class="page-link">ถัดไป</a>';
                                        $pageUrlParams['page'] = $totalPages;
                                        echo '<a href="?' . http_build_query($pageUrlParams) . '" class="page-link">หน้าสุดท้าย</a>';
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- Modal ปฏิเสธข้อร้องเรียน -->
    <!-- ============================================ -->
    <div id="rejectModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header reject-header">
                <h3>❌ ปฏิเสธข้อร้องเรียน</h3>
                <button type="button" class="modal-close" onclick="closeRejectModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- แสดงข้อมูลข้อร้องเรียน -->
                <div class="complaint-preview">
                    <div class="complaint-preview-header">
                        <span class="complaint-preview-id" id="preview_complaint_id">#0</span>
                        <span class="complaint-preview-date" id="preview_complaint_date"></span>
                    </div>
                    <div class="complaint-preview-content" id="preview_complaint_content"></div>
                </div>

                <form id="rejectForm">
                    <input type="hidden" id="reject_complaint_id" name="complaint_id">

                    <div class="modal-form-group">
                        <label><span class="required">*</span> เหตุผลที่ปฏิเสธ</label>
                        <select id="reject_reason_type" class="modal-form-control" onchange="toggleCustomReason()">
                            <option value="">-- เลือกเหตุผล --</option>
                            <option value="ข้อมูลไม่เพียงพอ กรุณาแนบหลักฐานเพิ่มเติม">📎 ข้อมูลไม่เพียงพอ กรุณาแนบหลักฐานเพิ่มเติม</option>
                            <option value="ไม่อยู่ในขอบเขตที่หน่วยงานรับผิดชอบ">🏢 ไม่อยู่ในขอบเขตที่หน่วยงานรับผิดชอบ</option>
                            <option value="ซ้ำกับข้อร้องเรียนที่มีอยู่แล้ว">🔄 ซ้ำกับข้อร้องเรียนที่มีอยู่แล้ว</option>
                            <option value="ข้อมูลไม่ถูกต้องหรือไม่ตรงกับความเป็นจริง">❓ ข้อมูลไม่ถูกต้องหรือไม่ตรงกับความเป็นจริง</option>
                            <option value="เนื้อหาไม่เหมาะสม">⚠️ เนื้อหาไม่เหมาะสม</option>
                            <option value="custom">✏️ อื่นๆ (ระบุเอง)</option>
                        </select>
                    </div>

                    <div class="modal-form-group" id="custom_reason_group" style="display: none;">
                        <label><span class="required">*</span> ระบุเหตุผล</label>
                        <input type="text" id="custom_reason" class="modal-form-control"
                            placeholder="กรุณาระบุเหตุผลที่ปฏิเสธ...">
                    </div>

                    <div class="modal-form-group">
                        <label>💡 คำชี้แนะเพิ่มเติม (ถ้ามี)</label>
                        <textarea id="reject_note" name="note" class="modal-form-control" rows="3"
                            placeholder="เช่น กรุณาแนบรูปภาพประกอบ, ติดต่อหน่วยงาน xxx แทน, ส่งข้อร้องเรียนใหม่พร้อมข้อมูลเพิ่มเติม..."></textarea>
                    </div>

                    <div class="modal-form-group">
                        <div class="checkbox-group" id="email_checkbox_group">
                            <input type="checkbox" id="send_email" name="send_email" checked>
                            <label for="send_email">📧 ส่งอีเมลแจ้งเตือนนักศึกษา</label>
                        </div>
                        <div class="email-notice" id="email_notice" style="display: none;"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeRejectModal()">
                    ยกเลิก
                </button>
                <button type="button" class="modal-btn modal-btn-danger" id="submitRejectBtn" onclick="submitReject()">
                    ❌ ยืนยันปฏิเสธ
                </button>
            </div>
        </div>
    </div>

    <!-- ============================================ -->
    <!-- Modal รับเรื่อง (Accept) -->
    <!-- ============================================ -->
    <div id="acceptModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header accept-header">
                <h3>✅ รับเรื่องข้อร้องเรียน</h3>
                <button type="button" class="modal-close" onclick="closeAcceptModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- แสดงข้อมูลข้อร้องเรียน -->
                <div class="complaint-preview">
                    <div class="complaint-preview-header">
                        <span class="complaint-preview-id" id="accept_preview_id">#0</span>
                        <span class="complaint-preview-date" id="accept_preview_date"></span>
                    </div>
                    <div class="complaint-preview-content" id="accept_preview_content"></div>
                </div>

                <form id="acceptForm">
                    <input type="hidden" id="accept_complaint_id" name="complaint_id">

                    <div class="modal-form-group">
                        <div class="checkbox-group" id="accept_email_checkbox_group">
                            <input type="checkbox" id="accept_send_email" name="send_email" checked>
                            <label for="accept_send_email">📧 ส่งอีเมลแจ้งเตือนนักศึกษา</label>
                        </div>
                        <div class="email-notice" id="accept_email_notice" style="display: none;"></div>
                    </div>
                </form>

                <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; color: #155724;">
                    <strong>📋 สิ่งที่จะเกิดขึ้น:</strong>
                    <ul style="margin: 10px 0 0 20px; padding: 0;">
                        <li>สถานะข้อร้องเรียนจะเปลี่ยนเป็น "ยืนยันแล้ว"</li>
                        <li>บันทึกประวัติการรับเรื่อง</li>
                        <li>ส่งการแจ้งเตือนในระบบ</li>
                        <li>ส่งอีเมลแจ้งนักศึกษา (ถ้าเลือก)</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-secondary" onclick="closeAcceptModal()">
                    ยกเลิก
                </button>
                <button type="button" class="modal-btn modal-btn-success" id="submitAcceptBtn" onclick="submitAccept()">
                    ✅ ยืนยันรับเรื่อง
                </button>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // ข้อมูลข้อร้องเรียนสำหรับ Modal
        // ============================================
        const complaintsData = <?php echo json_encode($complaints, JSON_UNESCAPED_UNICODE); ?>;

        // ============================================
        // Notification Function
        // ============================================
        function showNotification(message, type = 'info') {
            // ลบ notification เก่า
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

            notification.innerHTML = `
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <span style="font-size: 1.5rem;">${icons[type] || icons.info}</span>
                    <div>
                        <div style="font-weight: bold; margin-bottom: 3px;">${titles[type] || titles.info}</div>
                        <div>${message}</div>
                    </div>
                </div>
            `;

            // เพิ่ม styles
            if (!document.querySelector('style[data-notification]')) {
                const style = document.createElement('style');
                style.setAttribute('data-notification', 'true');
                style.textContent = `
                    .notification {
                        position: fixed;
                        top: 90px;
                        right: 20px;
                        background: white;
                        border-radius: 12px;
                        padding: 18px 22px;
                        box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
                        z-index: 10001;
                        min-width: 320px;
                        max-width: 450px;
                        border-left: 5px solid #007bff;
                        opacity: 0;
                        transform: translateX(100%);
                        animation: notifSlideIn 0.4s ease forwards;
                        cursor: pointer;
                    }
                    .notification.success { border-left-color: #28a745; }
                    .notification.error { border-left-color: #dc3545; }
                    .notification.warning { border-left-color: #ffc107; }
                    @keyframes notifSlideIn {
                        to { opacity: 1; transform: translateX(0); }
                    }
                    @keyframes notifSlideOut {
                        to { opacity: 0; transform: translateX(100%); }
                    }
                `;
                document.head.appendChild(style);
            }

            document.body.appendChild(notification);

            // Auto remove
            setTimeout(() => {
                notification.style.animation = 'notifSlideOut 0.3s ease forwards';
                setTimeout(() => notification.remove(), 300);
            }, 4000);

            // Click to remove
            notification.onclick = () => {
                notification.style.animation = 'notifSlideOut 0.3s ease forwards';
                setTimeout(() => notification.remove(), 300);
            };
        }

        // ============================================
        // Modal Functions - ปฏิเสธ (Reject)
        // ============================================
        function openRejectModal(id) {
            const complaint = complaintsData.find(c => c.Re_id == id);

            if (complaint) {
                // Set hidden values
                document.getElementById('reject_complaint_id').value = id;

                // Set preview
                document.getElementById('preview_complaint_id').textContent = '#' + id;
                document.getElementById('preview_complaint_date').textContent = complaint.Re_date || '';

                let contentHtml = '';
                if (complaint.Re_title) {
                    contentHtml += '<strong>' + escapeHtml(complaint.Re_title) + '</strong><br>';
                }
                contentHtml += escapeHtml(complaint.Re_infor);
                document.getElementById('preview_complaint_content').innerHTML = contentHtml;

                // Check email availability - ส่งได้ทุกกรณี ถ้ามีอีเมล
                const hasEmail = complaint.Stu_email && complaint.Stu_email.trim() !== '';
                const isAnonymous = complaint.Re_iden == 1;
                const emailCheckbox = document.getElementById('send_email');
                const emailNotice = document.getElementById('email_notice');

                if (!hasEmail) {
                    // ไม่มีอีเมลในระบบ
                    emailCheckbox.checked = false;
                    emailCheckbox.disabled = true;
                    emailNotice.style.display = 'block';
                    emailNotice.className = 'email-notice no-email';
                    emailNotice.innerHTML = '⚠️ ไม่พบอีเมลของนักศึกษา ไม่สามารถส่งอีเมลได้';
                } else {
                    // มีอีเมล - ส่งได้ทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
                    emailCheckbox.checked = true;
                    emailCheckbox.disabled = false;
                    emailNotice.style.display = 'block';

                    if (isAnonymous) {
                        // ไม่ระบุตัวตน แต่ยังส่งอีเมลได้ (ระบบรู้ว่าใครส่งมา)
                        emailNotice.className = 'email-notice';
                        emailNotice.innerHTML = '🕶️📧 ผู้ร้องเรียนไม่ระบุตัวตน แต่ระบบจะส่งอีเมลแจ้งเตือนให้อัตโนมัติ';
                    } else {
                        emailNotice.className = 'email-notice';
                        emailNotice.innerHTML = '📧 จะส่งไปที่: ' + escapeHtml(complaint.Stu_email);
                    }
                }
            }

            // Reset form
            document.getElementById('reject_reason_type').value = '';
            document.getElementById('custom_reason').value = '';
            document.getElementById('reject_note').value = '';
            document.getElementById('custom_reason_group').style.display = 'none';

            // Show modal
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

            if (select.value === 'custom') {
                document.getElementById('custom_reason').focus();
            }
        }

        function submitReject() {
            const complaintId = document.getElementById('reject_complaint_id').value;
            const reasonType = document.getElementById('reject_reason_type').value;
            const customReason = document.getElementById('custom_reason').value.trim();
            const note = document.getElementById('reject_note').value.trim();
            const sendEmail = document.getElementById('send_email').checked;

            // Validation
            if (!reasonType) {
                showNotification('กรุณาเลือกเหตุผลที่ปฏิเสธ', 'error');
                document.getElementById('reject_reason_type').focus();
                return;
            }

            if (reasonType === 'custom' && !customReason) {
                showNotification('กรุณาระบุเหตุผล', 'error');
                document.getElementById('custom_reason').focus();
                return;
            }

            const reason = (reasonType === 'custom') ? customReason : reasonType;

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'reject_complaint');
            formData.append('complaint_id', complaintId);
            formData.append('reason', reason);
            formData.append('note', note);
            formData.append('send_email', sendEmail ? '1' : '0');

            // Show loading
            const submitBtn = document.getElementById('submitRejectBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ กำลังดำเนินการ...';
            submitBtn.disabled = true;

            // Send request
            fetch('ajax/update_complaint.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'ปฏิเสธข้อร้องเรียนสำเร็จ', 'success');
                        closeRejectModal();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.message || 'เกิดข้อผิดพลาด', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                })
                .finally(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // ============================================
        // Modal Functions - รับเรื่อง (Accept)
        // ============================================
        function acceptComplaint(id) {
            const complaint = complaintsData.find(c => c.Re_id == id);

            if (complaint) {
                // Set hidden values
                document.getElementById('accept_complaint_id').value = id;

                // Set preview
                document.getElementById('accept_preview_id').textContent = '#' + id;
                document.getElementById('accept_preview_date').textContent = complaint.Re_date || '';

                let contentHtml = '';
                if (complaint.Re_title) {
                    contentHtml += '<strong>' + escapeHtml(complaint.Re_title) + '</strong><br>';
                }
                contentHtml += escapeHtml(complaint.Re_infor);
                document.getElementById('accept_preview_content').innerHTML = contentHtml;

                // Check email availability - ส่งได้ทุกกรณี ถ้ามีอีเมล
                const hasEmail = complaint.Stu_email && complaint.Stu_email.trim() !== '';
                const isAnonymous = complaint.Re_iden == 1;
                const emailCheckbox = document.getElementById('accept_send_email');
                const emailNotice = document.getElementById('accept_email_notice');

                if (!hasEmail) {
                    // ไม่มีอีเมลในระบบ
                    emailCheckbox.checked = false;
                    emailCheckbox.disabled = true;
                    emailNotice.style.display = 'block';
                    emailNotice.className = 'email-notice no-email';
                    emailNotice.innerHTML = '⚠️ ไม่พบอีเมลของนักศึกษา ไม่สามารถส่งอีเมลได้';
                } else {
                    // มีอีเมล - ส่งได้ทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
                    emailCheckbox.checked = true;
                    emailCheckbox.disabled = false;
                    emailNotice.style.display = 'block';

                    if (isAnonymous) {
                        // ไม่ระบุตัวตน แต่ยังส่งอีเมลได้ (ระบบรู้ว่าใครส่งมา)
                        emailNotice.className = 'email-notice';
                        emailNotice.innerHTML = '🕶️📧 ผู้ร้องเรียนไม่ระบุตัวตน แต่ระบบจะส่งอีเมลแจ้งเตือนให้อัตโนมัติ';
                    } else {
                        emailNotice.className = 'email-notice';
                        emailNotice.innerHTML = '📧 จะส่งไปที่: ' + escapeHtml(complaint.Stu_email);
                    }
                }
            }

            // Show modal
            document.getElementById('acceptModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeAcceptModal() {
            document.getElementById('acceptModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitAccept() {
            const complaintId = document.getElementById('accept_complaint_id').value;
            const sendEmail = document.getElementById('accept_send_email').checked;

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'accept_complaint');
            formData.append('complaint_id', complaintId);
            formData.append('send_email', sendEmail ? '1' : '0');

            // Show loading
            const submitBtn = document.getElementById('submitAcceptBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ กำลังดำเนินการ...';
            submitBtn.disabled = true;

            // Send request
            fetch('ajax/update_complaint.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'รับเรื่องข้อร้องเรียนสำเร็จ', 'success');
                        closeAcceptModal();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification(data.message || 'เกิดข้อผิดพลาด', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('เกิดข้อผิดพลาดในการเชื่อมต่อ', 'error');
                })
                .finally(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                });
        }

        // ============================================
        // Utility Functions
        // ============================================
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Close modal on overlay click
        document.getElementById('rejectModal').addEventListener('click', function(e) {
            if (e.target === this) closeRejectModal();
        });

        document.getElementById('acceptModal').addEventListener('click', function(e) {
            if (e.target === this) closeAcceptModal();
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRejectModal();
                closeAcceptModal();
            }
        });
    </script>
</body>

</html>