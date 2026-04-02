<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอิน
requireRole('student', 'login.php');

$user = getCurrentUser();

// Handle AJAX requests สำหรับการอัพเดตแบบ real-time (เพิ่มจาก index.php)
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

// ประมวลผลการกรองข้อมูล
$viewMode = 'mine';
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
function getComplaintsWithFilters($studentId, $viewMode = 'all', $status = '', $type = '', $priority = '', $search = '', $limit = 10, $offset = 0, $sortBy = 'date', $sortOrder = 'desc')
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
                       faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                       CASE 
                           WHEN r.Stu_id = ? THEN 'mine' 
                           ELSE 'other' 
                       END as ownership
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                WHERE 1=1";

        $params = [$studentId];

        // กรองตาม view mode
        if ($viewMode === 'mine') {
            $sql .= " AND r.Stu_id = ?";
            $params[] = $studentId;
        }

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
function countComplaintsWithFilters($studentId, $viewMode = 'all', $status = '', $type = '', $priority = '', $search = '')
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
                WHERE 1=1";

        $params = [];

        // กรองตาม view mode
        if ($viewMode === 'mine') {
            $sql .= " AND r.Stu_id = ?";
            $params[] = $studentId;
        }

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
function getFilteredStats($studentId, $viewMode = 'all', $status = '', $type = '', $priority = '', $search = '')
{
    $db = getDB();
    if (!$db) return ['total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'evaluated' => 0, 'avg_rating' => 0];

    try {
        $whereConditions = ['1=1'];
        $params = [];

        // กรองตาม view mode
        if ($viewMode === 'mine') {
            $whereConditions[] = 'r.Stu_id = ?';
            $params[] = $studentId;
        }

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
                    $stats['waiting_eval'] = $statusCount['count']; // รอประเมินผลความพึงพอใจ
                    break;
                case '3':
                    $stats['completed'] = $statusCount['count']; // เสร็จสิ้น (แก้ไขจาก case '2')
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
$totalComplaints = countComplaintsWithFilters($user['Stu_id'], $viewMode, $filterStatus, $filterType, $filterPriority, $searchKeyword);
$totalPages = ceil($totalComplaints / $perPage);
$complaints = getComplaintsWithFilters($user['Stu_id'], $viewMode, $filterStatus, $filterType, $filterPriority, $searchKeyword, $perPage, $offset, $sortBy, $sortOrder);

// ดึงสถิติตามเงื่อนไขปัจจุบัน
$stats = getFilteredStats($user['Stu_id'], $viewMode, $filterStatus, $filterType, $filterPriority, $searchKeyword);

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
    'view' => $viewMode,
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

// ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน (เพิ่มจาก index.php)
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตามสถานะข้อร้องเรียน - <?php echo SITE_NAME; ?></title>
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

        /* Notification Dropdown (เพิ่มจาก index.php) */
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

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: -300px;
            top: 70px;
            width: 300px;
            height: calc(100vh - 70px);
            background: linear-gradient(180deg, #1e3c72 0%, #2a5298 100%);
            z-index: 990;
            transition: all 0.3s ease;
            overflow-y: auto;
            box-shadow: 4px 0 15px rgba(0, 0, 0, 0.1);
        }

        .sidebar.show {
            left: 0;
        }

        .sidebar-overlay {
            position: fixed;
            top: 70px;
            left: 0;
            width: 100%;
            height: calc(100vh - 70px);
            background: rgba(0, 0, 0, 0.5);
            z-index: 989;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .sidebar-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .sidebar-header {
            padding: 25px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-logo {
            width: 60px;
            height: 60px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .university-name {
            color: white;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 5px;
            line-height: 1.3;
        }

        .university-campus {
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
            line-height: 1.2;
        }

        .sidebar-user {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .sidebar-user-avatar {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .sidebar-user-info {
            flex: 1;
            min-width: 0;
        }

        .sidebar-user-name {
            color: white;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            margin-bottom: 2px;
        }

        .sidebar-user-id {
            color: rgba(255, 255, 255, 0.6);
            font-size: 11px;
        }

        .sidebar-nav {
            padding: 10px 0;
        }

        .sidebar-menu {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .sidebar-menu-item {
            margin: 2px 10px;
        }

        .sidebar-menu-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .sidebar-menu-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 0;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            z-index: 0;
        }

        .sidebar-menu-link:hover::before {
            width: 100%;
        }

        .sidebar-menu-link:hover,
        .sidebar-menu-item.active .sidebar-menu-link {
            color: white;
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }

        .sidebar-menu-item.active .sidebar-menu-link {
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .menu-icon {
            display: inline-block;
            width: 25px;
            margin-right: 12px;
            text-align: center;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }

        .menu-text {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
            position: relative;
            z-index: 1;
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
            max-width: 1200px;
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

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .filter-section h3 {
            color: #333;
            margin-bottom: 20px;
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
            /* ให้ช่อง search ขยายเต็มความกว้างของ grid */
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
        }

        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Style สำหรับ Priority Select - แสดงสีตามระดับความสำคัญ (ตาม $PRIORITY_COLORS ใน config.php) */
        .priority-select option[value="1"] {
            color: #28a745;
            font-weight: 500;
        }

        /* success - ไม่เร่งด่วน */
        .priority-select option[value="2"] {
            color: #6c757d;
            font-weight: 500;
        }

        /* secondary - ปกติ */
        .priority-select option[value="3"] {
            color: #ffc107;
            font-weight: 500;
        }

        /* warning - เร่งด่วน */
        .priority-select option[value="4"] {
            color: #dc3545;
            font-weight: 600;
        }

        /* danger - เร่งด่วนมาก */
        .priority-select option[value="5"] {
            color: #343a40;
            font-weight: 600;
            background: #f8d7da;
        }

        /* dark - วิกฤต/ฉุกเฉิน */

        /* เพิ่มการเน้นให้กับช่อง search */
        .search-full-width input {
            font-size: 16px;
            /* ป้องกันการ zoom บนมือถือ */
            padding: 15px;
            /* เพิ่ม padding ให้ดูใหญ่ขึ้น */
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

        /* ปรับ pagination wrapper ให้ไม่แสดงเมื่อรวมแล้ว */
        .pagination-wrapper {
            display: none;
        }

        /* Complaint Cards */
        .complaints-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .complaint-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #667eea;
            transition: all 0.3s ease;
        }

        .complaint-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .complaint-card.status-0 {
            border-left-color: #6c757d;
        }

        .complaint-card.status-1 {
            border-left-color: #17a2b8;
        }

        .complaint-card.status-2 {
            border-left-color: #ffc107;
        }

        .complaint-card.status-3 {
            border-left-color: #28a745;
        }

        .complaint-card.status-4 {
            border-left-color: #dc3545;
        }

        .complaint-card.priority-3,
        .complaint-card.priority-4,
        .complaint-card.priority-5 {
            border-left-width: 8px;
        }

        .complaint-card.ownership-other {
            border-left-style: dashed;
            opacity: 0.85;
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
            background: #e2e3e5;
            color: #383d41;
            border: 1px solid #d6d8db;
        }

        .badge-status-1 {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .badge-status-2 {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .badge-status-3 {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .badge-status-4 {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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

        .badge-ownership-mine {
            background: #e7f3ff;
            color: #0066cc;
        }

        .badge-ownership-other {
            background: #f0f0f0;
            color: #666;
        }

        .badge-privacy-hidden {
            background: #f5f5f5;
            color: #757575;
            border: 1px dashed #bbb;
        }

        .badge-privacy-owner {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #bbdefb;
        }

        .badge-privacy-revealed {
            background: #e8f5e8;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .complaint-description {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
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

        /* Pagination Controls at Bottom - ส่วนใหม่ที่ย้ายมาไว้ท้าย */
        .pagination-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            margin-top: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
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
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #667eea;
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
        }

        .quick-jump input {
            width: 60px;
            padding: 6px 8px;
            border: 1px solid #e1e5e9;
            border-radius: 4px;
            text-align: center;
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
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
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

        /* View Mode Toggle */
        .view-mode-toggle {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .toggle-label {
            font-weight: 600;
            color: #333;
        }

        .toggle-buttons {
            display: flex;
            gap: 10px;
        }

        .toggle-btn {
            padding: 8px 16px;
            border: 2px solid #e1e5e9;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #666;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }

        .toggle-btn:hover {
            border-color: #667eea;
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
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #d1ecf1;
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

        /* Toast Notification (เพิ่มจาก index.php) */
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

            .sidebar {
                width: 100%;
                left: -100%;
            }

            .sidebar.show {
                left: 0;
            }

            .main-content.shifted {
                margin-left: 0;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .search-full-width {
                grid-column: 1;
                /* รีเซ็ตการขยายบนมือถือ */
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

            .view-mode-toggle {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
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

            .notification-dropdown {
                width: calc(100vw - 40px);
                right: -150px;
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

            /* Extra small screen adjustments */
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
            .sidebar.desktop-open {
                left: 0;
            }

            .main-content.desktop-shifted {
                margin-left: 300px;
            }

            .pagination-controls {
                flex-wrap: nowrap;
            }

            .controls-group {
                flex-wrap: nowrap;
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
                <h1>ติดตามสถานะข้อร้องเรียน</h1>
                <p>ดูความคืบหน้าและประวัติข้อร้องเรียนของคุณ</p>
            </div>
        </div>

        <div class="header-right">
            <!-- Updated Notification Button with Dropdown (คัดลอกจาก index.php) -->
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
                    <h1>📋 ติดตามสถานะข้อร้องเรียน</h1>
                    <p>ดูความคืบหน้าและประวัติข้อร้องเรียนของคุณ</p>
                    <div style="font-size: 12px; color: #888; margin-top: 8px;">
                        🔒 <strong>ข้อมูลไม่ระบุตัวตน:</strong> คุณสามารถเห็นข้อร้องเรียนที่ไม่ระบุตัวตนของตัวเองได้
                        แต่จะไม่เห็นข้อมูลส่วนตัวของผู้อื่นที่ร้องเรียนแบบไม่ระบุตัวตน
                    </div>
                </div>
                <a href="index.php" class="btn btn-secondary">← กลับหน้าหลัก</a>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">📝</span>
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

            <!-- Filter Section - ปรับปรุงใหม่ รวม Pagination -->
            <div class="filter-section">
                <h3>🔍 ค้นหาและกรองข้อมูล</h3>
                <form method="GET" action="" id="filterForm">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
                    <input type="hidden" name="per_page" value="<?php echo $perPage; ?>">
                    <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sortBy); ?>">
                    <input type="hidden" name="sort_order" value="<?php echo htmlspecialchars($sortOrder); ?>">

                    <!-- แถวแรก: ช่องค้นหาเพียงช่องเดียว -->
                    <div class="form-row">
                        <div class="form-group search-full-width">
                            <label>🔎 คำค้นหาอัจฉริยะ (Smart Search)</label>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchKeyword); ?>"
                                placeholder="ค้นหาด้วยคำบางส่วน เช่น 'อำนวย' จะหา 'สิ่งอำนวยความสะดวก..."
                                oninput="showSearchPreview(this.value)">

                            <!-- Search Enhancement Info -->
                            <div class="search-enhancement">
                                <div class="search-tips">
                                    <div class="search-tip">
                                        <span>💡</span>
                                        <span>ค้นหาแบบอัจฉริยะ:</span>
                                    </div>
                                    <div class="search-tip">
                                        <span>🔍</span>
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
                                <option value="0" <?php echo $filterStatus === '0' ? 'selected' : ''; ?>>ยื่นคำร้อง</option>
                                <option value="1" <?php echo $filterStatus === '1' ? 'selected' : ''; ?>>กำลังดำเนินการ</option>
                                <option value="2" <?php echo $filterStatus === '2' ? 'selected' : ''; ?>>รอการประเมินผล</option>
                                <option value="3" <?php echo $filterStatus === '3' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                                <option value="4" <?php echo $filterStatus === '4' ? 'selected' : ''; ?>>ปฏิเสธคำร้อง</option>
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
                                        <?php echo $type['Type_icon'] ?? '📋'; ?> <?php echo htmlspecialchars($type['Type_infor']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>ความสำคัญ</label>
                            <select name="priority" class="priority-select">
                                <option value="">ทั้งหมด</option>
                                <?php
                                global $PRIORITY_COLORS;
                                foreach ($PRIORITY_COLORS as $key => $priority):
                                ?>
                                    <option value="<?php echo $key; ?>" <?php echo $filterPriority === (string)$key ? 'selected' : ''; ?>>
                                        <?php echo $priority['icon']; ?> <?php echo htmlspecialchars($priority['text']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary">🔍 ค้นหา</button>
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
                    <span style="font-weight: 600; color: #333; font-size: 16px;">
                        📊 จำนวนทั้งหมด: <strong style="color: #667eea;"><?php echo number_format($totalComplaints); ?></strong> รายการ
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
                            <?php if ($viewMode === 'mine'): ?>
                                คุณยังไม่ได้ส่งข้อร้องเรียนใดๆ หรือไม่มีข้อร้องเรียนที่ตรงกับเงื่อนไขการค้นหา
                            <?php else: ?>
                                ไม่มีข้อร้องเรียนที่ตรงกับเงื่อนไขการค้นหา หรือยังไม่มีข้อร้องเรียนในระบบ
                            <?php endif; ?>
                        </p>
                        <a href="complaint.php" class="btn btn-primary">📝 ส่งข้อร้องเรียนใหม่</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($complaints as $complaint): ?>
                        <div class="complaint-card status-<?php echo $complaint['Re_status']; ?> priority-<?php echo $complaint['Re_level']; ?> ownership-<?php echo $complaint['ownership']; ?>">
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
                                            <?php
                                            // ตรวจสอบว่าเป็นเจ้าของข้อร้องเรียนหรือไม่
                                            $isOwner = ($complaint['Stu_id'] === $user['Stu_id']);
                                            ?>

                                            <?php if ($isOwner): ?>
                                                <!-- เจ้าของ - แสดงเฉพาะว่าเป็นของตัวเอง -->
                                                <span>🔓 ไม่ระบุตัวตน (คุณเป็นเจ้าของ)</span>
                                            <?php else: ?>
                                                <!-- คนอื่น - แสดงเฉพาะไม่ระบุตัวตน -->
                                                <span>🔓 ไม่ระบุตัวตน</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="complaint-badges">
                                    <span class="badge badge-status-<?php echo $complaint['Re_status']; ?>">
                                        <?php echo getStatusText($complaint['Re_status']); ?>
                                    </span>                                                             
                                    <?php if ($complaint['Re_iden'] == 1): ?>
                                        <?php if ($isOwner): ?>
                                            <span class="badge" style="background: #e3f2fd; color: #1976d2;">
                                                🔔 ไม่ระบุตัวตน (เจ้าของ)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: #f5f5f5; color: #757575;">
                                                🔒 ไม่ระบุตัวตน
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge" style="background: #e8f5e8; color: #2e7d32;">
                                            🔔 ระบุตัวตน
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="complaint-description">
                                <?php echo nl2br(htmlspecialchars($complaint['Re_infor'])); ?>
                            </div>

                            <div class="complaint-actions">
                                <a href="detail.php?id=<?php echo $complaint['Re_id']; ?>" class="btn btn-info">📄 ดูรายละเอียด</a>

                                <?php if ($complaint['Re_status'] == '2'): ?>
                                    <a href="evaluation.php?id=<?php echo $complaint['Re_id']; ?>" class="btn btn-success">⭐ ประเมินบริการ</a>
                                <?php endif; ?>

                                <?php if ($complaint['Re_status'] == '0'): ?>
                                    <a href="complaint.php?edit=<?php echo $complaint['Re_id']; ?>" class="btn btn-warning">✏️ แก้ไข</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagination Section - ย้ายมาอยู่ท้ายสุดหลังข้อร้องเรียนเรื่องสุดท้าย -->
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
                        // แก้ไขในส่วนการประมวลผล pagination parameters (บรรทัดประมาณ 40-50)
                        $currentPage = max(1, (int)($_GET['page'] ?? 1));
                        $perPage = (int)($_GET['per_page'] ?? 10);
                        $totalComplaints = countComplaintsWithFilters($user['Stu_id'], $viewMode, $filterStatus, $filterType, $filterPriority, $searchKeyword);
                        $totalPages = max(1, ceil($totalComplaints / $perPage)); // เพิ่มการป้องกัน division by zero

                        // แก้ไขฟังก์ชัน createGooglePagination (บรรทัดประมาณ 2450-2500)
                        function createGooglePagination($currentPage, $totalPages, $baseParams = [])
                        {
                            // แปลงค่าให้เป็น int เพื่อป้องกัน type error
                            $currentPage = (int)$currentPage;
                            $totalPages = (int)$totalPages;

                            // ตรวจสอบค่าที่ถูกต้อง
                            if ($currentPage < 1) $currentPage = 1;
                            if ($totalPages < 1) $totalPages = 1;
                            if ($currentPage > $totalPages) $currentPage = $totalPages;

                            $html = '<div class="google-pagination-container">';

                            // Previous button (only show if more than 1 page and current page > 1)
                            if ($totalPages > 1 && $currentPage > 1) {
                                $prevUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $currentPage - 1]));
                                $html .= '<a href="' . $prevUrl . '" class="google-page-link google-prev">Previous</a>';
                            }

                            // Page numbers
                            $html .= '<div class="google-page-numbers">';

                            if ($totalPages == 1) {
                                // Always show page 1 even if it's the only page
                                $html .= '<span class="google-page-link google-page-number google-current">1</span>';
                            } else {
                                // Calculate page range for multiple pages
                                $start = max(1, min($currentPage - 5, $totalPages - 9));
                                $end = min($totalPages, max($currentPage + 4, 10));

                                for ($i = $start; $i <= $end; $i++) {
                                    $pageUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $i]));
                                    $activeClass = ($i == $currentPage) ? ' google-current' : '';
                                    $html .= '<a href="' . $pageUrl . '" class="google-page-link google-page-number' . $activeClass . '">' . $i . '</a>';
                                }
                            }

                            $html .= '</div>';

                            // Next button (only show if more than 1 page and current page < total pages)
                            if ($totalPages > 1 && $currentPage < $totalPages) {
                                $nextUrl = '?' . http_build_query(array_merge($baseParams, ['page' => $currentPage + 1]));
                                $html .= '<a href="' . $nextUrl . '" class="google-page-link google-next">Next</a>';
                            }

                            $html .= '</div>';
                            return $html;
                        }

                        // แก้ไขการเรียกใช้ฟังก์ชัน (บรรทัดประมาณ 2496)
                        echo createGooglePagination($currentPage, $totalPages, $paginationParams);
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div style="text-align: center; margin-top: 40px;">
                <a href="complaint.php" class="btn btn-primary">📝 ส่งข้อร้องเรียนใหม่</a>
                <button class="btn btn-info" onclick="refreshPage()">🔄 รีเฟรชข้อมูล</button>

                <button class="btn btn-warning" onclick="scrollToSearch()">🔍 ค้นหาข้อร้องเรียน</button>
            </div>
        </div>
    </main>

    <!-- Toast Notification (เพิ่มจาก index.php) -->
    <div class="toast" id="toast"></div>

    <?php
    // โหลด JavaScript ตามสิทธิ์ผู้ใช้ (เพิ่มจาก index.php)
    $currentRole = $_SESSION['user_role'] ?? '';
    if ($currentRole === 'teacher'): ?>
        <script src="../js/staff.js"></script>
    <?php endif; ?>

    <script>
        // Global variables to track notification state (เพิ่มจาก index.php)
        let currentUnreadCount = <?php echo $unreadCount; ?>;
        let notificationDropdownOpen = false;
        let notificationCheckInterval;

        // Initialize notifications on page load (เพิ่มจาก index.php)
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
        });

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

        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth >= 1024) {
                setTimeout(() => {
                    openSidebar();
                }, 500);
            }
        });

        // Update notification badge (เพิ่มจาก index.php)
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

        // Toggle notification dropdown (เพิ่มจาก index.php)
        function toggleNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            if (notificationDropdownOpen) {
                closeNotificationDropdown();
            } else {
                openNotificationDropdown();
            }
        }

        // Open notification dropdown (เพิ่มจาก index.php)
        function openNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            dropdown.classList.add('show');
            button.classList.add('active');
            notificationDropdownOpen = true;

            // Load fresh notifications
            loadNotifications();
        }

        // Close notification dropdown (เพิ่มจาก index.php)
        function closeNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');

            dropdown.classList.remove('show');
            button.classList.remove('active');
            notificationDropdownOpen = false;
        }

        // Load notifications (เพิ่มจาก index.php)
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

        // Display notifications in dropdown (เพิ่มจาก index.php)
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

        // Handle notification click (เพิ่มจาก index.php)
        function handleNotificationClick(notificationId, requestId) {
            // Mark as read
            markNotificationAsRead(notificationId);

            // Navigate to related request if available
            if (requestId) {
                window.location.href = `tracking.php?id=${requestId}`;
            }
        }

        // Mark single notification as read (เพิ่มจาก index.php)
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

        // Mark all notifications as read (เพิ่มจาก index.php)
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

        // Update unread count (เพิ่มจาก index.php)
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

        // Start periodic notification checking (เพิ่มจาก index.php)
        function startNotificationPolling() {
            // Check every 15 seconds
            notificationCheckInterval = setInterval(updateUnreadCount, 15000);
        }

        // Stop notification polling (เพิ่มจาก index.php)
        function stopNotificationPolling() {
            if (notificationCheckInterval) {
                clearInterval(notificationCheckInterval);
            }
        }

        // Show toast message (เพิ่มจาก index.php)
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // Play notification sound (เพิ่มจาก index.php)
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

        // Format relative time (เพิ่มจาก index.php)
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

        // Escape HTML (เพิ่มจาก index.php)
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Cleanup on page unload (เพิ่มจาก index.php)
        window.addEventListener('beforeunload', function() {
            stopNotificationPolling();
        });

        // Handle visibility change (pause polling when tab not active) (เพิ่มจาก index.php)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopNotificationPolling();
            } else {
                startNotificationPolling();
                updateUnreadCount(); // Check immediately when tab becomes active
            }
        });

        // Function สำหรับ scroll ไปยังส่วนค้นหา
        function scrollToSearch() {
            const filterSection = document.querySelector('.filter-section');
            if (filterSection) {
                filterSection.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

                // Focus ที่ช่องค้นหาหลังจาก scroll เสร็จ
                setTimeout(() => {
                    const searchInput = document.querySelector('input[name="search"]');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select(); // เลือกข้อความทั้งหมดในช่อง
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

        // Pagination Functions
        function changePerPage(newPerPage) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('per_page', newPerPage);
            urlParams.set('page', '1'); // Reset to first page
            window.location.href = '?' + urlParams.toString();
        }

        // Sorting Functions
        function changeSortBy(newSortBy) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort_by', newSortBy);
            urlParams.set('page', '1'); // Reset to first page when changing sort
            window.location.href = '?' + urlParams.toString();
        }

        function changeSortOrder(newSortOrder) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('sort_order', newSortOrder);
            urlParams.set('page', '1'); // Reset to first page when changing sort order
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

            // Show current sort status
            updateSortDisplay();

            // Initialize search preview
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput && searchInput.value) {
                showSearchPreview(searchInput.value);
            }
        });

        // Update sort display status
        function updateSortDisplay() {
            const currentSortBy = '<?php echo $sortBy; ?>';
            const currentSortOrder = '<?php echo $sortOrder; ?>';

            // You can add visual indicators here if needed
            console.log('Current sort:', currentSortBy, currentSortOrder);
        }

        // Keyboard shortcuts for pagination
        document.addEventListener('keydown', function(e) {
            // Only work when not in input fields
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
            // This is a client-side preview, actual fuzzy search happens on server
            if (!searchValue || searchValue.length < 2) {
                hideSearchPreview();
                return;
            }

            // Simple preview of what terms will be searched
            const terms = searchValue.trim().split(/[\s,\-_\.]+/).filter(term => term.length >= 2);

            if (terms.length > 0) {
                console.log('Search terms preview:', terms);
                // You can add visual preview here if needed
            }
        }

        function hideSearchPreview() {
            // Hide any search preview elements
        }
    </script>
</body>

</html>