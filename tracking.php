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

// ประมวลผลการกรองข้อมูล
$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$searchKeyword = $_GET['search'] ?? '';

// Pagination parameters
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 10);
$allowedPerPage = [5, 10, 15, 25, 50, 100];
if (!in_array($perPage, $allowedPerPage)) {
    $perPage = 10; // default fallback
}
$offset = ($currentPage - 1) * $perPage;

// Sorting parameters
$sortBy = $_GET['sort_by'] ?? 'date'; // date, priority, status
$sortOrder = $_GET['sort_order'] ?? 'desc'; // asc, desc
$allowedSortBy = ['date', 'priority', 'status', 'id'];
$allowedSortOrder = ['asc', 'desc'];

if (!in_array($sortBy, $allowedSortBy)) {
    $sortBy = 'date';
}
if (!in_array($sortOrder, $allowedSortOrder)) {
    $sortOrder = 'desc';
}

// ฟังก์ชันปรับปรุงการค้นหาแบบ Fuzzy Search
function prepareFuzzySearchTerms($searchText)
{
    if (empty($searchText)) return [];

    // ทำความสะอาดและแยกคำ
    $searchText = trim($searchText);

    // แยกคำโดยใช้ space, comma, และ special characters
    $terms = preg_split('/[\s,\-_\.]+/', $searchText, -1, PREG_SPLIT_NO_EMPTY);

    // กรองคำที่สั้นเกินไป (น้อยกว่า 2 ตัวอักษร)
    $terms = array_filter($terms, function ($term) {
        return mb_strlen($term, 'UTF-8') >= 2;
    });

    return array_unique($terms);
}

// ฟังก์ชันสร้าง WHERE clause สำหรับการค้นหาแบบ Fuzzy
function buildFuzzySearchCondition($searchTerms, &$params)
{
    if (empty($searchTerms)) return '';

    $conditions = [];
    $searchFields = [
        'r.Re_infor',
        'r.Re_title',
        't.Type_infor',
        's.Stu_name',
        's.Stu_id',
        'major.Unit_name',
        'faculty.Unit_name'
    ];

    foreach ($searchTerms as $term) {
        $termConditions = [];
        $searchPattern = '%' . $term . '%';

        foreach ($searchFields as $field) {
            $termConditions[] = "{$field} LIKE ?";
            $params[] = $searchPattern;
        }

        // แต่ละคำต้องพบในอย่างน้อย 1 ฟิลด์
        $conditions[] = '(' . implode(' OR ', $termConditions) . ')';
    }

    // ทุกคำต้องพบ (AND logic)
    return '(' . implode(' AND ', $conditions) . ')';
}

// ฟังก์ชันดึงข้อร้องเรียนแบบใหม่ - รองรับการดูทั้งหมดและของตัวเอง พร้อม pagination, sorting และ fuzzy search
function getComplaintsWithFilters($status = '', $type = '', $priority = '', $search = '', $limit = 10, $offset = 0, $sortBy = 'date', $sortOrder = 'desc')
{
    $db = getDB();
    if (!$db) return [];

    try {
        $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                       CASE 
                           WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน' 
                           ELSE COALESCE(s.Stu_name, 'ไม่ระบุตัวตน')
                       END as requester_name,
                       s.Stu_id,
                       major.Unit_name as major_name, major.Unit_icon as major_icon,
                       faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                WHERE r.Re_is_spam = 0";

        $params = [];

        // กรองตามสถานะ
        if (!empty($status)) {
            $sql .= " AND r.Re_status = ?";
            $params[] = $status;
        }

        // กรองตามประเภท
        if (!empty($type)) {
            $sql .= " AND r.Type_id = ?";
            $params[] = $type;
        }

        // กรองตามระดับความสำคัญ
        if (!empty($priority)) {
            $sql .= " AND r.Re_level = ?";
            $params[] = $priority;
        }

        // ค้นหาแบบ Fuzzy Search
        if (!empty($search)) {
            $searchTerms = prepareFuzzySearchTerms($search);
            if (!empty($searchTerms)) {
                $fuzzyCondition = buildFuzzySearchCondition($searchTerms, $params);
                if (!empty($fuzzyCondition)) {
                    $sql .= " AND " . $fuzzyCondition;
                }
            }
        }

        // เพิ่มการเรียงลำดับ
        $orderClause = '';
        switch ($sortBy) {
            case 'date':
                $orderClause = "ORDER BY r.Re_date " . strtoupper($sortOrder) . ", r.Re_id " . strtoupper($sortOrder);
                break;
            case 'priority':
                $orderClause = "ORDER BY r.Re_level " . strtoupper($sortOrder) . ", r.Re_date DESC, r.Re_id DESC";
                break;
            case 'status':
                $orderClause = "ORDER BY r.Re_status " . strtoupper($sortOrder) . ", r.Re_date DESC, r.Re_id DESC";
                break;
            case 'id':
                $orderClause = "ORDER BY r.Re_id " . strtoupper($sortOrder);
                break;
            default:
                $orderClause = "ORDER BY r.Re_level DESC, r.Re_date DESC, r.Re_id DESC";
        }

        $sql .= " " . $orderClause;

        // เพิ่ม LIMIT และ OFFSET
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        error_log("getComplaintsWithFilters error: " . $e->getMessage());
        return [];
    }
}

// ฟังก์ชันนับจำนวนข้อร้องเรียนทั้งหมดตามเงื่อนไข - อัพเดตรองรับ fuzzy search
function countComplaintsWithFilters($status = '', $type = '', $priority = '', $search = '')
{
    $db = getDB();
    if (!$db) return 0;

    try {
        $sql = "SELECT COUNT(*) as total
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                WHERE r.Re_is_spam = 0";

        $params = [];

        // กรองตามสถานะ
        if (!empty($status)) {
            $sql .= " AND r.Re_status = ?";
            $params[] = $status;
        }

        // กรองตามประเภท
        if (!empty($type)) {
            $sql .= " AND r.Type_id = ?";
            $params[] = $type;
        }

        // กรองตามระดับความสำคัญ
        if (!empty($priority)) {
            $sql .= " AND r.Re_level = ?";
            $params[] = $priority;
        }

        // ค้นหาแบบ Fuzzy Search
        if (!empty($search)) {
            $searchTerms = prepareFuzzySearchTerms($search);
            if (!empty($searchTerms)) {
                $fuzzyCondition = buildFuzzySearchCondition($searchTerms, $params);
                if (!empty($fuzzyCondition)) {
                    $sql .= " AND " . $fuzzyCondition;
                }
            }
        }

        $result = $db->fetch($sql, $params);
        return $result['total'] ?? 0;
    } catch (Exception $e) {
        error_log("countComplaintsWithFilters error: " . $e->getMessage());
        return 0;
    }
}

// ฟังก์ชันดึงสถิติแบบใหม่ - รองรับการดูทั้งหมดและของตัวเอง พร้อม fuzzy search
function getFilteredStats($status = '', $type = '', $priority = '', $search = '')
{
    $db = getDB();
    if (!$db) return ['total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'evaluated' => 0, 'avg_rating' => 0];

    try {
        $whereConditions = ['r.Re_is_spam = 0'];
        $params = [];

        // กรองตามสถานะ
        if (!empty($status)) {
            $whereConditions[] = 'r.Re_status = ?';
            $params[] = $status;
        }

        // กรองตามประเภท
        if (!empty($type)) {
            $whereConditions[] = 'r.Type_id = ?';
            $params[] = $type;
        }

        // กรองตามระดับความสำคัญ
        if (!empty($priority)) {
            $whereConditions[] = 'r.Re_level = ?';
            $params[] = $priority;
        }

        // ค้นหาแบบ Fuzzy Search
        if (!empty($search)) {
            $searchTerms = prepareFuzzySearchTerms($search);
            if (!empty($searchTerms)) {
                $fuzzyCondition = buildFuzzySearchCondition($searchTerms, $params);
                if (!empty($fuzzyCondition)) {
                    $whereConditions[] = $fuzzyCondition;
                }
            }
        }

        $whereClause = implode(' AND ', $whereConditions);

        // ดึงสถิติทั้งหมด
        $stats = [];

        // จำนวนทั้งหมด
        $sql = "SELECT COUNT(*) as count 
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                WHERE " . $whereClause;
        $result = $db->fetch($sql, $params);
        $stats['total'] = $result['count'] ?? 0;

        // แยกตามสถานะ
        $statusSql = "SELECT r.Re_status, COUNT(*) as count 
                      FROM request r 
                      LEFT JOIN type t ON r.Type_id = t.Type_id
                      LEFT JOIN student s ON r.Stu_id = s.Stu_id
                      LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                      LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                      WHERE " . $whereClause . " 
                      GROUP BY r.Re_status";
        $statusCounts = $db->fetchAll($statusSql, $params);

        $stats['pending'] = 0;
        $stats['confirmed'] = 0;
        $stats['completed'] = 0;
        $stats['evaluated'] = 0;

        foreach ($statusCounts as $statusCount) {
            switch ($statusCount['Re_status']) {
                case '0':
                    $stats['pending'] = $statusCount['count'];
                    break;
                case '1':
                    $stats['confirmed'] = $statusCount['count'];
                    break;
                case '2':
                    $stats['completed'] = $statusCount['count'];
                    break;
                case '3':
                    $stats['evaluated'] = $statusCount['count'];
                    break;
            }
        }

        // คะแนนประเมินเฉลี่ย
        $evalSql = "SELECT AVG(e.Eva_score) as avg_rating 
                    FROM evaluation e 
                    JOIN request r ON e.Re_id = r.Re_id 
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN student s ON r.Stu_id = s.Stu_id
                    LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                    LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                    WHERE " . $whereClause . " AND e.Eva_score > 0";
        $evalResult = $db->fetch($evalSql, $params);
        $stats['avg_rating'] = $evalResult && $evalResult['avg_rating'] ? round($evalResult['avg_rating'], 1) : 0;

        return $stats;
    } catch (Exception $e) {
        error_log("getFilteredStats error: " . $e->getMessage());
        return ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'evaluated' => 0, 'avg_rating' => 0];
    }
}

// ดึงข้อมูลข้อร้องเรียนตามเงื่อนไข พร้อม pagination และ sorting
$totalComplaints = countComplaintsWithFilters($filterStatus, $filterType, $filterPriority, $searchKeyword);
$totalPages = ceil($totalComplaints / $perPage);
$complaints = getComplaintsWithFilters($filterStatus, $filterType, $filterPriority, $searchKeyword, $perPage, $offset, $sortBy, $sortOrder);

// ดึงสถิติตามเงื่อนไขปัจจุบัน
$stats = getFilteredStats($filterStatus, $filterType, $filterPriority, $searchKeyword);

// ฟังก์ชันแสดงข้อมูลสถิติแบบละเอียด
function getDetailedPaginationInfo($totalItems, $perPage, $currentPage, $totalPages)
{
    $startItem = ($currentPage - 1) * $perPage + 1;
    $endItem = min($currentPage * $perPage, $totalItems);

    $info = [
        'total_items' => $totalItems,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'start_item' => $startItem,
        'end_item' => $endItem,
        'showing_count' => $endItem - $startItem + 1
    ];

    return $info;
}

// เตรียม parameters สำหรับ pagination
$paginationParams = [
    'status' => $filterStatus,
    'type' => $filterType,
    'priority' => $filterPriority,
    'search' => $searchKeyword,
    'per_page' => $perPage,
    'sort_by' => $sortBy,
    'sort_order' => $sortOrder
];

// ลบค่าว่างออก
$paginationParams = array_filter($paginationParams, function ($value) {
    return $value !== '';
});

// ข้อมูลสถิติแบบละเอียด
$detailedInfo = getDetailedPaginationInfo($totalComplaints, $perPage, $currentPage, $totalPages);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>แดชบอร์ดข้อร้องเรียนสาธารณะ - <?php echo SITE_NAME; ?></title>
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
            text-align: center;
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

        .btn-info {
            background: #17a2b8;
            color: white;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
            border-left: 5px solid #667eea;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
            color: #667eea;
        }

        .stat-info {
            text-align: left;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .filter-section h3 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
            display: inline-block;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        /* สำหรับแถวที่มีเฉพาะช่อง search */
        .search-full-width {
            grid-column: 1 / -1;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            color: #333;
        }

        .form-group select::placeholder,
        .form-group input::placeholder {
            color: #999;
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* เพิ่มการเน้นให้กับช่อง search */
        .search-full-width input {
            font-size: 16px;
            padding: 15px;
            border: 3px solid #667eea;
        }

        .search-full-width label {
            font-size: 1.1em;
            font-weight: 600;
            color: #667eea;
        }

        /* Section Divider Styles */
        .section-divider {
            display: flex;
            align-items: center;
            margin: 30px 0 25px 0;
            gap: 15px;
        }

        .divider-line {
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, transparent 0%, #667eea 50%, transparent 100%);
            opacity: 0.3;
        }

        .divider-content {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .divider-icon {
            font-size: 16px;
        }

        .divider-text {
            white-space: nowrap;
        }

        /* Google Style Pagination */
        .google-pagination {
            margin: 25px 0;
            padding-top: 20px;
            border-top: 1px solid #e1e5e9;
        }

        .google-pagination-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .google-page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            height: 40px;
            padding: 0 12px;
            text-decoration: none;
            color: #1a73e8;
            font-size: 14px;
            font-weight: 500;
            border-radius: 50%;
            transition: all 0.2s ease;
            position: relative;
        }

        .google-page-link:hover {
            background-color: #f8f9fa;
            color: #1a73e8;
        }

        .google-page-number.google-current {
            background: #1a73e8;
            color: white;
            font-weight: bold;
        }

        .google-page-number.google-current:hover {
            background: #1557b0;
            color: white;
        }

        .google-prev,
        .google-next {
            min-width: auto;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
            border: 1px solid #e1e5e9;
            background: white;
            color: #1a73e8;
        }

        .google-prev:hover,
        .google-next:hover {
            background: #f8f9fa;
            border-color: #dadce0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .google-page-numbers {
            display: flex;
            align-items: center;
            gap: 4px;
            margin: 0 8px;
        }

        /* Complaint Cards */
        .complaints-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .complaint-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-left: 5px solid #667eea;
            transition: all 0.3s ease;
        }

        .complaint-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .complaint-card.status-0 {
            border-left-color: #ffc107;
        }

        .complaint-card.status-1 {
            border-left-color: #17a2b8;
        }

        .complaint-card.status-2 {
            border-left-color: #28a745;
        }

        .complaint-card.status-3 {
            border-left-color: #007bff;
        }

        .complaint-card.priority-3,
        .complaint-card.priority-4,
        .complaint-card.priority-5 {
            border-left-width: 8px;
        }

        .complaint-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .complaint-title {
            flex: 1;
        }

        .complaint-title h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 1.2rem;
        }

        .complaint-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            font-size: 14px;
            color: #666;
            margin-bottom: 15px;
        }

        .complaint-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 5px 12px;
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

        .badge-privacy-hidden {
            background: #f5f5f5;
            color: #757575;
            border: 1px dashed #bbb;
        }

        .badge-privacy-revealed {
            background: #e8f5e8;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .complaint-description {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            line-height: 1.6;
            color: #333;
        }

        .complaint-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .complaint-actions .btn {
            padding: 8px 15px;
            font-size: 12px;
        }

        /* Pagination Controls at Bottom */
        .pagination-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-top: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .pagination-controls {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 20px;
        }

        .total-items-display {
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            font-weight: 600;
        }

        .controls-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .per-page-selector,
        .sort-selector {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .per-page-selector label,
        .sort-selector label {
            font-weight: 500;
            color: #333;
            white-space: nowrap;
        }

        .per-page-selector select,
        .sort-selector select {
            padding: 8px 12px;
            border: 2px solid #e1e5e9;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 120px;
            color: #333;
        }

        .per-page-selector select:focus,
        .sort-selector select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .sort-controls {
            display: flex;
            gap: 10px;
        }

        .sort-toggle-btn {
            padding: 8px 12px;
            border: 2px solid #e1e5e9;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .sort-toggle-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .sort-toggle-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }

        .quick-jump-container {
            display: flex;
            justify-content: center;
            margin-top: 10px;
        }

        .quick-jump {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #333;
        }

        .quick-jump input {
            width: 60px;
            padding: 6px 8px;
            border: 1px solid #e1e5e9;
            border-radius: 4px;
            text-align: center;
            background: white;
            color: #333;
        }

        .quick-jump button {
            padding: 6px 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quick-jump button:hover {
            background: #5a67d8;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
            color: #667eea;
        }

        .empty-title {
            color: #333;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .empty-description {
            color: #666;
            margin-bottom: 30px;
        }

        /* Search Enhancement Styles */
        .search-enhancement {
            background: #f8f9fa;
            border: 1px solid #e1e5e9;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }

        .search-tips {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: center;
        }

        .search-tip {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-example {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 11px;
        }

        .search-results-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: none;
        }

        .search-results-info.show {
            display: block;
        }

        .search-highlight {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 0 8px 8px 0;
        }

        .search-terms {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .search-term {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .smart-search-indicator {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
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
                font-size: 1.2rem;
            }

            .header-title p {
                font-size: 0.8rem;
            }

            .main-content {
                padding: 15px;
            }

            .page-header {
                text-align: center;
            }

            .page-header>div:first-child {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .search-full-width {
                grid-column: 1;
            }

            .complaint-header {
                flex-direction: column;
                gap: 15px;
            }

            .complaint-actions {
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .pagination-controls {
                gap: 15px;
            }

            .total-items-display {
                padding: 10px;
                font-size: 14px;
            }

            .controls-group {
                flex-direction: column;
                gap: 15px;
            }

            .sort-controls {
                justify-content: center;
                flex-wrap: wrap;
            }

            .sort-toggle-btn {
                font-size: 12px;
                padding: 6px 10px;
            }

            .quick-jump-container {
                margin-top: 15px;
            }

            .quick-jump {
                justify-content: center;
            }

            .per-page-selector,
            .sort-selector {
                justify-content: center;
                flex-wrap: wrap;
            }

            .per-page-selector label,
            .sort-selector label {
                text-align: center;
                width: 100%;
                margin-bottom: 5px;
            }

            .per-page-selector select,
            .sort-selector select {
                min-width: 150px;
            }
        }

        @media (max-width: 480px) {
            .pagination-controls {
                gap: 10px;
            }

            .total-items-display {
                padding: 8px;
                font-size: 13px;
            }

            .total-items-display strong {
                display: block;
                margin-top: 2px;
            }

            .sort-controls {
                gap: 5px;
            }

            .sort-toggle-btn {
                font-size: 11px;
                padding: 5px 8px;
            }

            .sort-toggle-btn span {
                font-size: 10px;
            }

            .google-pagination {
                gap: 2px;
            }

            .google-page-link {
                padding: 6px 8px;
                font-size: 12px;
                min-width: 32px;
                height: 32px;
            }

            .divider-content {
                padding: 4px 10px;
                font-size: 11px;
            }

            .section-divider {
                margin: 15px 0 10px 0;
                gap: 8px;
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

        /* Select dropdown styling */
        .form-group select option {
            background: white;
            color: #333;
            padding: 8px;
        }

        /* Icon styling for dropdown options */
        .type-option {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
        }

        .type-icon {
            font-size: 16px;
            width: 20px;
            text-align: center;
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
            <h1>🛠️ แดชบอร์ดข้อร้องเรียนสาธารณะ</h1>
            <p>ตรวจสอบสถานะและความคืบหน้าของข้อร้องเรียนทั้งหมด</p>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <div>
                        <h1>📋 ติดตามสถานะข้อร้องเรียน</h1>
                        <p>ดูความคืบหน้าและประวัติข้อร้องเรียนในระบบ</p>
                    </div>
                    <a href="index.php" class="btn btn-secondary">← กลับสู่หน้าหลัก</a>
                </div>
                <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8);">
                    🌍 <strong>ข้อมูลสาธารณะ:</strong> แสดงข้อร้องเรียนทั้งหมดในระบบที่สามารถเข้าถึงได้
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">📄</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                        <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">⏳</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo ($stats['pending'] ?? 0) + ($stats['confirmed'] ?? 0); ?></div>
                        <div class="stat-label">กำลังดำเนินการ</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">✅</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['completed'] ?? 0; ?></div>
                        <div class="stat-label">เสร็จสิ้นแล้ว</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">⭐</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?></div>
                        <div class="stat-label">คะแนนเฉลี่ย</div>
                    </div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3>🔍 ค้นหาและกรองข้อมูล</h3>
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
                    <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder); ?>">

                    <!-- แถวแรก: ช่องค้นหาเพียงช่องเดียว -->
                    <div class="form-row">
                        <div class="form-group search-full-width">
                            <label>🔎 คำค้นหาอัจฉริยะ (Smart Search)</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchKeyword); ?>"
                                placeholder="ค้นหาด้วยคำบางส่วน เช่น 'อำนวย' จะหา 'สิ่งอำนวยความสะดวก...'"
                                oninput="showSearchPreview(this.value)">

                            <!-- Search Enhancement Info -->
                            <div class="search-enhancement">
                                <div class="search-tips">
                                    <div class="search-tip">
                                        <span>💡</span>
                                        <span>ค้นหาแบบอัจฉริยะ:</span>
                                    </div>
                                    <div class="search-tip">
                                        <span>🔧</span>
                                        <span>พิมพ์คำบางส่วน:</span>
                                        <span class="search-example">อำนวย</span>
                                        <span>→ สิ่งอำนวยความสะดวก</span>
                                    </div>
                                    <div class="search-tip">
                                        <span>👤</span>
                                        <span>ชื่อ:</span>
                                        <span class="search-example">สมชาย</span>
                                        <span>→ นายสมชาย ใจดี</span>
                                    </div>
                                    <div class="search-tip">
                                        <span>🔢</span>
                                        <span>รหัส:</span>
                                        <span class="search-example">6634</span>
                                        <span>→ 66342310001-1</span>
                                    </div>
                                    <div class="search-tip">
                                        <span>🎯</span>
                                        <span>หลายคำ:</span>
                                        <span class="search-example">ห้อง เครื่อง</span>
                                        <span>→ "ห้องเรียน เครื่องปรับอากาศ"</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- แถวที่สอง: ตัวเลือกกรองและปุ่มค้นหา -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>สถานะ</label>
                            <select name="status">
                                <option value="">ทั้งหมด</option>
                                <option value="0" <?php echo $filterStatus === '0' ? 'selected' : ''; ?>>รอยืนยัน</option>
                                <option value="1" <?php echo $filterStatus === '1' ? 'selected' : ''; ?>>ยืนยันแล้ว</option>
                                <option value="2" <?php echo $filterStatus === '2' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                                <option value="3" <?php echo $filterStatus === '3' ? 'selected' : ''; ?>>ประเมินแล้ว</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ประเภท</label>
                            <select name="type">
                                <option value="">ทั้งหมด</option>
                                <?php
                                $complaintTypes = getComplaintTypesList();
                                foreach ($complaintTypes as $type):
                                ?>
                                    <option value="<?php echo $type['Type_id']; ?>" <?php echo $filterType == $type['Type_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($type['Type_icon'] ?? '📋'); ?> <?php echo htmlspecialchars($type['Type_infor']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ความสำคัญ</label>
                            <select name="priority">
                                <option value="">ทั้งหมด</option>
                                <option value="1" <?php echo $filterPriority === '1' ? 'selected' : ''; ?>>ไม่เร่งด่วน</option>
                                <option value="2" <?php echo $filterPriority === '2' ? 'selected' : ''; ?>>ปกติ</option>
                                <option value="3" <?php echo $filterPriority === '3' ? 'selected' : ''; ?>>เร่งด่วน</option>
                                <option value="4" <?php echo $filterPriority === '4' ? 'selected' : ''; ?>>เร่งด่วนมาก</option>
                                <option value="5" <?php echo $filterPriority === '5' ? 'selected' : ''; ?>>วิกฤต/ฉุกเฉิน</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
                                <button type="button" class="btn btn-secondary" onclick="clearAllFilters()">🗑️ ล้างตัวกรอง</button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Search Results Info -->
                <?php if (!empty($searchKeyword)): ?>
                    <div class="search-results-info show">
                        <div class="search-highlight">
                            <div class="smart-search-indicator">
                                <span>🎯</span>
                                <strong>การค้นหาอัจฉริยะทำงานแล้ว!</strong>
                            </div>
                            <p style="margin: 8px 0 0 0; color: #333;">
                                ค้นหาคำ: <strong style="color: #667eea;">"<?php echo htmlspecialchars($searchKeyword); ?>"</strong>
                                <?php
                                $searchTerms = prepareFuzzySearchTerms($searchKeyword);
                                if (!empty($searchTerms) && count($searchTerms) > 1):
                                ?>
                                    <br><small style="color: #666;">แยกเป็น <?php echo count($searchTerms); ?> คำ:</small>
                            <div class="search-terms">
                                <?php foreach ($searchTerms as $term): ?>
                                    <span class="search-term"><?php echo htmlspecialchars($term); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        </p>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- แสดงจำนวนทั้งหมดก่อน -->
                <div class="total-items-display">
                    <span style="font-weight: 600; font-size: 16px;">
                        📊 จำนวนทั้งหมด: <strong><?php echo number_format($totalComplaints); ?></strong> รายการ
                    </span>
                </div>
            </div>

            <!-- Complaints List -->
            <div class="complaints-list">
                <?php if (empty($complaints)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <h3 class="empty-title">ไม่มีข้อร้องเรียน</h3>
                        <p class="empty-description">
                            ไม่มีข้อร้องเรียนที่ตรงกับเงื่อนไขการค้นหา หรือยังไม่มีข้อร้องเรียนในระบบ
                        </p>
                        <button class="btn btn-info" onclick="clearAllFilters()">🗑️ ล้างตัวกรอง</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($complaints as $complaint): ?>
                        <div class="complaint-card status-<?php echo $complaint['Re_status']; ?> priority-<?php echo $complaint['Re_level']; ?>">
                            <div class="complaint-header">
                                <div class="complaint-title">
                                    <h3>
                                        <?php echo htmlspecialchars($complaint['Type_icon'] ?? '📋'); ?>
                                        #<?php echo htmlspecialchars($complaint['Re_id']); ?> -
                                        <?php echo htmlspecialchars($complaint['Type_infor']); ?>
                                    </h3>
                                    <div class="complaint-meta">
                                        <span>📅 ส่งเมื่อ: <?php echo formatThaiDate(strtotime($complaint['Re_date'])); ?></span>

                                        <?php if ($complaint['Re_iden'] == 0): // ระบุตัวตน - แสดงข้อมูลครบถ้วน 
                                        ?>
                                            <span>👤 ผู้ส่ง: <?php echo htmlspecialchars($complaint['requester_name'] ?? 'ไม่ระบุชื่อ'); ?></span>
                                            <?php if ($complaint['Stu_id']): ?>
                                                <span>🆔 รหัส: <?php echo htmlspecialchars($complaint['Stu_id']); ?></span>
                                            <?php endif; ?>
                                            <span>🔓 ระบุตัวตน</span>
                                            <?php if (!empty($complaint['major_name'])): ?>
                                                <span>🎓 สาขา: <?php echo htmlspecialchars($complaint['major_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($complaint['faculty_name'])): ?>
                                                <span>🏫 คณะ: <?php echo htmlspecialchars($complaint['faculty_name']); ?></span>
                                            <?php endif; ?>

                                        <?php else: // ไม่ระบุตัวตน 
                                        ?>
                                            <span>🔒 ไม่ระบุตัวตน</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="complaint-badges">
                                    <span class="badge badge-status-<?php echo $complaint['Re_status']; ?>">
                                        <?php echo getStatusText($complaint['Re_status']); ?>
                                    </span>
                                    <span class="badge badge-priority-<?php echo $complaint['Re_level']; ?>">
                                        <?php echo getPriorityDisplayText($complaint['Re_level'], $complaint['Re_status']); ?>
                                    </span>

                                    <?php if ($complaint['Re_iden'] == 1): ?>
                                        <span class="badge badge-privacy-hidden">
                                            🔒 ไม่ระบุตัวตน
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-privacy-revealed">
                                            🔓 ระบุตัวตน
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="complaint-description">
                                <?php echo nl2br(htmlspecialchars($complaint['Re_infor'])); ?>
                            </div>

                            <div class="complaint-actions">
                                <a href="detail.php?id=<?php echo $complaint['Re_id']; ?>" class="btn btn-info">📄 ดูรายละเอียด</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination Section -->
            <?php if ($totalComplaints > 0): ?>
                <div class="pagination-section">
                    <div class="section-divider">
                        <div class="divider-line"></div>
                        <div class="divider-content">
                            <span class="divider-icon">📊</span>
                            <span class="divider-text">การจัดการและแสดงผลข้อมูล</span>
                        </div>
                        <div class="divider-line"></div>
                    </div>

                    <div class="pagination-controls">
                        <div class="controls-group">
                            <!-- ตัวเลือกจำนวนต่อหน้า -->
                            <div class="per-page-selector">
                                <label>📄 แสดงต่อหน้า:</label>
                                <select onchange="changePerPage(this.value)">
                                    <?php foreach ($allowedPerPage as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $perPage == $option ? 'selected' : ''; ?>>
                                            <?php echo $option; ?> รายการ
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- ตัวเลือกการเรียงลำดับ -->
                            <div class="sort-selector">
                                <label>📋 เรียงตาม:</label>
                                <select onchange="changeSortBy(this.value)">
                                    <option value="date" <?php echo $sortBy === 'date' ? 'selected' : ''; ?>>วันที่ส่ง</option>
                                    <option value="priority" <?php echo $sortBy === 'priority' ? 'selected' : ''; ?>>ความสำคัญ</option>
                                    <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>สถานะ</option>
                                    <option value="id" <?php echo $sortBy === 'id' ? 'selected' : ''; ?>>หมายเลขคำร้อง</option>
                                </select>
                            </div>

                            <!-- ปุ่มสลับการเรียงลำดับ -->
                            <div class="sort-controls">
                                <button type="button"
                                    class="sort-toggle-btn <?php echo $sortOrder === 'desc' ? 'active' : ''; ?>"
                                    onclick="changeSortOrder('desc')">
                                    <span>🔽</span> ใหม่ไปเก่า
                                </button>
                                <button type="button"
                                    class="sort-toggle-btn <?php echo $sortOrder === 'asc' ? 'active' : ''; ?>"
                                    onclick="changeSortOrder('asc')">
                                    <span>🔼</span> เก่าไปใหม่
                                </button>
                            </div>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="quick-jump-container">
                                <div class="quick-jump">
                                    <span>ไปที่หน้า:</span>
                                    <input type="number" id="jumpPage" min="1" max="<?php echo $totalPages; ?>" value="<?php echo $currentPage; ?>">
                                    <button onclick="jumpToPage()">ไป</button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Google Style Pagination -->
                    <div class="google-pagination">
                        <?php
                        $currentPage = max(1, (int)($_GET['page'] ?? 1));
                        $perPage = (int)($_GET['per_page'] ?? 10);
                        $totalComplaints = countComplaintsWithFilters($filterStatus, $filterType, $filterPriority, $searchKeyword);
                        $totalPages = max(1, ceil($totalComplaints / $perPage));

                        function createGooglePagination($currentPage, $totalPages, $baseParams = [])
                        {
                            $currentPage = (int)$currentPage;
                            $totalPages = (int)$totalPages;

                            if ($currentPage < 1) $currentPage = 1;
                            if ($totalPages < 1) $totalPages = 1;
                            if ($currentPage > $totalPages) $currentPage = $totalPages;

                            $html = '<div class="google-pagination-container">';

                            if ($totalPages > 1 && $currentPage > 1) {
                                $prevUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $currentPage - 1]));
                                $html .= '<a href="' . $prevUrl . '" class="google-page-link google-prev">Previous</a>';
                            }

                            $html .= '<div class="google-page-numbers">';

                            if ($totalPages == 1) {
                                $html .= '<span class="google-page-link google-page-number google-current">1</span>';
                            } else {
                                $start = max(1, min($currentPage - 5, $totalPages - 9));
                                $end = min($totalPages, max($currentPage + 4, 10));

                                for ($i = $start; $i <= $end; $i++) {
                                    $pageUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $i]));
                                    $activeClass = ($i == $currentPage) ? ' google-current' : '';
                                    $html .= '<a href="' . $pageUrl . '" class="google-page-link google-page-number' . $activeClass . '">' . $i . '</a>';
                                }
                            }

                            $html .= '</div>';

                            if ($totalPages > 1 && $currentPage < $totalPages) {
                                $nextUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $currentPage + 1]));
                                $html .= '<a href="' . $nextUrl . '" class="google-page-link google-next">Next</a>';
                            }

                            $html .= '</div>';
                            return $html;
                        }

                        echo createGooglePagination($currentPage, $totalPages, $paginationParams);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div style="text-align: center; margin-top: 40px;">
                <button class="btn btn-info" onclick="refreshPage()">🔄 รีเฟรชข้อมูล</button>
                <button class="btn btn-warning" onclick="scrollToSearch()">🔍 ค้นหาข้อร้องเรียน</button>
                <button class="btn btn-secondary" onclick="clearAllFilters()">🗑️ ล้างตัวกรอง</button>
            </div>
        </div>
    </main>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <script>
        // Function สำหรับ scroll ไปยังส่วนค้นหา
        function scrollToSearch() {
            const filterSection = document.querySelector('.filter-section');
            if (filterSection) {
                filterSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

                setTimeout(() => {
                    const searchInput = document.querySelector('input[name="search"]');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }, 500);

                showToast('🔍 เลื่อนไปยังส่วนค้นหาแล้ว', 'info');
            }
        }

        function refreshPage() {
            showToast('กำลังรีเฟรชข้อมูล...', 'info');
            setTimeout(() => {
                location.reload();
            }, 1000);
        }

        function clearAllFilters() {
            window.location.href = '?per_page=10&sort_by=date&sort_order=desc';
        }

        // Pagination Functions
        function changePerPage(newPerPage) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('per_page', newPerPage);
            urlParams.set('page', '1');
            window.location.href = '?' + urlParams.toString();
        }

        // Sorting Functions
        function changeSortBy(newSortBy) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort_by', newSortBy);
            urlParams.set('page', '1');
            window.location.href = '?' + urlParams.toString();
        }

        function changeSortOrder(newSortOrder) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort_order', newSortOrder);
            urlParams.set('page', '1');
            window.location.href = '?' + urlParams.toString();
        }

        function jumpToPage() {
            const pageInput = document.getElementById('jumpPage');
            const page = parseInt(pageInput.value);
            const maxPage = <?php echo max(1, $totalPages); ?>;

            if (isNaN(page) || page < 1 || page > maxPage) {
                showToast('กรุณาใส่หมายเลขหน้าที่ถูกต้อง (1-' + maxPage + ')', 'warning');
                pageInput.focus();
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('page', page);
            window.location.href = '?' + urlParams.toString();
        }

        // Handle Enter key in jump page input
        document.addEventListener('DOMContentLoaded', function() {
            const jumpPageInput = document.getElementById('jumpPage');
            if (jumpPageInput) {
                jumpPageInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        jumpToPage();
                    }
                });
            }

            updateSortDisplay();

            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                showSearchPreview(searchInput.value);
            }
        });

        function updateSortDisplay() {
            const currentSortBy = '<?php echo $sortBy; ?>';
            const currentSortOrder = '<?php echo $sortOrder; ?>';
            console.log('Current sort:', currentSortBy, currentSortOrder);
        }

        // Keyboard shortcuts for pagination
        document.addEventListener('keydown', function(e) {
            if (e.target.tagName.toLowerCase() === 'input' || e.target.tagName.toLowerCase() === 'textarea' || e.target.tagName.toLowerCase() === 'select') {
                return;
            }

            const currentPage = <?php echo max(1, $currentPage); ?>;
            const totalPages = <?php echo max(1, $totalPages); ?>;
            const urlParams = new URLSearchParams(window.location.search);

            switch (e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    if (currentPage > 1) {
                        urlParams.set('page', currentPage - 1);
                        window.location.href = '?' + urlParams.toString();
                    }
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    if (currentPage < totalPages) {
                        urlParams.set('page', currentPage + 1);
                        window.location.href = '?' + urlParams.toString();
                    }
                    break;
                case 'Home':
                    e.preventDefault();
                    if (currentPage > 1) {
                        urlParams.set('page', 1);
                        window.location.href = '?' + urlParams.toString();
                    }
                    break;
                case 'End':
                    e.preventDefault();
                    if (currentPage < totalPages && totalPages > 1) {
                        urlParams.set('page', totalPages);
                        window.location.href = '?' + urlParams.toString();
                    }
                    break;
            }
        });

        // Search Enhancement Functions
        function showSearchPreview(searchValue) {
            if (!searchValue || searchValue.length < 2) {
                hideSearchPreview();
                return;
            }

            const terms = searchValue.trim().split(/[\s,\-_\.]+/).filter(term => term.length >= 2);

            if (terms.length > 0) {
                console.log('Search terms preview:', terms);
            }
        }

        function hideSearchPreview() {
            // Hide any search preview elements
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