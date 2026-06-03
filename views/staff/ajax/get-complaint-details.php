<?php
// AJAX handler สำหรับดึงรายละเอียดข้อร้องเรียน
header('Content-Type: application/json; charset=utf-8');

session_start();
define('SECURE_ACCESS', true);

require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../models/Auth.php';
require_once '../../../core/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isLoggedIn() || !hasRole(['staff', 'admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่มีสิทธิ์เข้าถึง'
    ]);
    exit;
}

$user = getCurrentUser();
$db = getDB();

if (!$db) {
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'
    ]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_complaint':
            handleGetComplaint();
            break;

        case 'get_messages':
            handleGetMessages();
            break;

        case 'get_files':
            handleGetFiles();
            break;

        case 'get_complaint_list':
            handleGetComplaintList();
            break;

        case 'search_complaints':
            handleSearchComplaints();
            break;

        case 'get_stats':
            handleGetStats();
            break;

        case 'get_priority_queue':
            handleGetPriorityQueue();
            break;

        case 'get_staff_workload':
            handleGetStaffWorkload();
            break;

        default:
            throw new Exception('การดำเนินการไม่ถูกต้อง');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function handleGetComplaint()
{
    global $db;

    $complaintId = $_GET['id'] ?? 0;

    if (!$complaintId) {
        throw new Exception('ไม่พบรหัสข้อร้องเรียน');
    }

    // ดึงข้อมูลข้อร้องเรียนแบบละเอียด
    $complaint = $db->fetch("
        SELECT c.*, 
               u.student_id, u.first_name, u.last_name, u.email, u.phone,
               u.faculty, u.major, u.year_level,
               a.first_name as assigned_first_name, a.last_name as assigned_last_name,
               a.email as assigned_email,
               COUNT(m.id) as message_count,
               COUNT(f.id) as file_count
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN users a ON c.assigned_to = a.id
        LEFT JOIN complaint_messages m ON c.id = m.complaint_id
        LEFT JOIN complaint_files f ON c.id = f.complaint_id
        WHERE c.id = ?
        GROUP BY c.id
    ", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ดึงข้อมูลการประเมิน (ถ้ามี)
    $evaluation = $db->fetch("
        SELECT * FROM evaluations 
        WHERE complaint_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ", [$complaintId]);

    // แปลงข้อมูลให้เป็นรูปแบบที่เหมาะสม
    $complaint['created_at_formatted'] = formatThaiDateTime($complaint['created_at']);
    $complaint['updated_at_formatted'] = formatThaiDateTime($complaint['updated_at']);
    $complaint['incident_date_formatted'] = $complaint['incident_date'] ? formatThaiDate($complaint['incident_date']) : null;

    // เพิ่มข้อมูลการประเมิน
    $complaint['evaluation'] = $evaluation;

    echo json_encode([
        'success' => true,
        'complaint' => $complaint
    ]);
}

function handleGetMessages()
{
    global $db;

    $complaintId = $_GET['complaint_id'] ?? 0;

    if (!$complaintId) {
        throw new Exception('ไม่พบรหัสข้อร้องเรียน');
    }

    // ตรวจสอบว่าข้อร้องเรียนมีอยู่จริง
    $complaint = $db->fetch("SELECT id FROM complaints WHERE id = ?", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ดึงข้อความสนทนา
    $messages = $db->fetchAll("
        SELECT m.*, u.first_name, u.last_name, u.role, u.email
        FROM complaint_messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.complaint_id = ?
        ORDER BY m.created_at ASC
    ", [$complaintId]);

    // แปลงรูปแบบวันที่
    foreach ($messages as &$message) {
        $message['created_at_formatted'] = formatThaiDateTime($message['created_at']);
        $message['is_staff'] = in_array($message['role'], ['staff', 'admin']);
    }

    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
}

function handleGetFiles()
{
    global $db;

    $complaintId = $_GET['complaint_id'] ?? 0;

    if (!$complaintId) {
        throw new Exception('ไม่พบรหัสข้อร้องเรียน');
    }

    // ดึงไฟล์แนบ
    $files = $db->fetchAll("
        SELECT * FROM complaint_files 
        WHERE complaint_id = ? 
        ORDER BY uploaded_at ASC
    ", [$complaintId]);

    // เพิ่มข้อมูลเพิ่มเติม
    foreach ($files as &$file) {
        $file['uploaded_at_formatted'] = formatThaiDateTime($file['uploaded_at']);
        $file['file_size_formatted'] = formatFileSize($file['file_size']);

        // กำหนดไอคอนตามประเภทไฟล์
        $icons = [
            'pdf' => '📄',
            'doc' => '📝',
            'docx' => '📝',
            'xls' => '📊',
            'xlsx' => '📊',
            'jpg' => '🖼️',
            'jpeg' => '🖼️',
            'png' => '🖼️',
            'gif' => '🖼️',
            'txt' => '📄',
            'zip' => '📦',
            'rar' => '📦'
        ];

        $file['icon'] = $icons[$file['file_type']] ?? '📁';
        $file['is_image'] = in_array($file['file_type'], ['jpg', 'jpeg', 'png', 'gif']);
    }

    echo json_encode([
        'success' => true,
        'files' => $files
    ]);
}

function handleGetComplaintList()
{
    global $db, $user;

    $page = max(1, $_GET['page'] ?? 1);
    $limit = min(50, max(10, $_GET['limit'] ?? 20));
    $offset = ($page - 1) * $limit;

    // ตัวกรอง
    $filters = [];
    $params = [];
    $whereConditions = [];

    // สถานะ
    if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
        $whereConditions[] = 'c.status = ?';
        $params[] = $_GET['status'];
    }

    // ความสำคัญ
    if (!empty($_GET['priority']) && $_GET['priority'] !== 'all') {
        $whereConditions[] = 'c.priority = ?';
        $params[] = $_GET['priority'];
    }

    // หมวดหมู่
    if (!empty($_GET['category']) && $_GET['category'] !== 'all') {
        $whereConditions[] = 'c.category = ?';
        $params[] = $_GET['category'];
    }

    // การมอบหมาย
    if (!empty($_GET['assigned'])) {
        if ($_GET['assigned'] === 'unassigned') {
            $whereConditions[] = 'c.assigned_to IS NULL';
        } elseif ($_GET['assigned'] === 'me' && !hasRole('admin')) {
            $whereConditions[] = 'c.assigned_to = ?';
            $params[] = $user['id'];
        } elseif (is_numeric($_GET['assigned'])) {
            $whereConditions[] = 'c.assigned_to = ?';
            $params[] = $_GET['assigned'];
        }
    }

    // ช่วงวันที่
    if (!empty($_GET['date_from'])) {
        $whereConditions[] = 'DATE(c.created_at) >= ?';
        $params[] = $_GET['date_from'];
    }

    if (!empty($_GET['date_to'])) {
        $whereConditions[] = 'DATE(c.created_at) <= ?';
        $params[] = $_GET['date_to'];
    }

    // คำค้นหา
    if (!empty($_GET['search'])) {
        $search = '%' . $_GET['search'] . '%';
        $whereConditions[] = '(c.title LIKE ? OR c.description LIKE ? OR c.complaint_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?)';
        $params = array_merge($params, [$search, $search, $search, $search, $search, $search]);
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // การเรียงลำดับ
    $orderBy = 'ORDER BY ';
    $sortBy = $_GET['sort'] ?? 'created_at';
    $sortOrder = $_GET['order'] ?? 'desc';

    switch ($sortBy) {
        case 'priority':
            $orderBy .= "CASE WHEN c.priority = 'urgent' THEN 1 WHEN c.priority = 'high' THEN 2 WHEN c.priority = 'medium' THEN 3 ELSE 4 END";
            break;
        case 'status':
            $orderBy .= 'c.status';
            break;
        case 'updated_at':
            $orderBy .= 'c.updated_at';
            break;
        default:
            $orderBy .= 'c.created_at';
    }

    $orderBy .= ' ' . (strtolower($sortOrder) === 'asc' ? 'ASC' : 'DESC');

    // นับจำนวนทั้งหมด
    $countSql = "
        SELECT COUNT(*) as total
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        {$whereClause}
    ";

    $countResult = $db->fetch($countSql, $params);
    $totalRecords = $countResult['total'];
    $totalPages = ceil($totalRecords / $limit);

    // ดึงข้อมูล
    $sql = "
        SELECT c.*, 
               u.student_id, u.first_name, u.last_name, u.email,
               u.faculty, u.major,
               a.first_name as assigned_first_name, a.last_name as assigned_last_name,
               COUNT(DISTINCT m.id) as message_count,
               COUNT(DISTINCT f.id) as file_count,
               CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END as has_evaluation
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN users a ON c.assigned_to = a.id
        LEFT JOIN complaint_messages m ON c.id = m.complaint_id
        LEFT JOIN complaint_files f ON c.id = f.complaint_id
        LEFT JOIN evaluations e ON c.id = e.complaint_id
        {$whereClause}
        GROUP BY c.id
        {$orderBy}
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;

    $complaints = $db->fetchAll($sql, $params);

    // แปลงรูปแบบข้อมูล
    foreach ($complaints as &$complaint) {
        $complaint['created_at_formatted'] = formatThaiDateTime($complaint['created_at']);
        $complaint['updated_at_formatted'] = formatThaiDateTime($complaint['updated_at']);
        $complaint['incident_date_formatted'] = $complaint['incident_date'] ? formatThaiDate($complaint['incident_date']) : null;

        // แปลงข้อความสถานะ
        $complaint['status_text'] = getStatusText($complaint['status']);
        $complaint['priority_text'] = getPriorityText($complaint['priority']);
        $complaint['category_text'] = getCategoryText($complaint['category']);
    }

    echo json_encode([
        'success' => true,
        'complaints' => $complaints,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
            'limit' => $limit,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ]);
}

function handleSearchComplaints()
{
    global $db;

    $query = trim($_GET['q'] ?? '');
    $limit = min(20, max(5, $_GET['limit'] ?? 10));

    if (strlen($query) < 2) {
        echo json_encode([
            'success' => true,
            'complaints' => []
        ]);
        return;
    }

    $searchTerm = '%' . $query . '%';

    $sql = "
        SELECT c.id, c.complaint_id, c.title, c.status, c.priority,
               c.created_at, u.first_name, u.last_name, u.student_id
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.title LIKE ? 
           OR c.description LIKE ? 
           OR c.complaint_id LIKE ? 
           OR u.first_name LIKE ? 
           OR u.last_name LIKE ? 
           OR u.student_id LIKE ?
        ORDER BY c.created_at DESC
        LIMIT ?
    ";

    $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit];
    $results = $db->fetchAll($sql, $params);

    foreach ($results as &$result) {
        $result['created_at_formatted'] = formatThaiDateTime($result['created_at']);
        $result['status_text'] = getStatusText($result['status']);
        $result['priority_text'] = getPriorityText($result['priority']);
    }

    echo json_encode([
        'success' => true,
        'complaints' => $results
    ]);
}

function handleGetStats()
{
    global $db, $user;

    $timeframe = $_GET['timeframe'] ?? 'week';
    $staffId = $_GET['staff_id'] ?? null;

    $stats = [];

    // กำหนดช่วงเวลา
    switch ($timeframe) {
        case 'today':
            $dateCondition = 'DATE(created_at) = CURDATE()';
            break;
        case 'week':
            $dateCondition = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
            break;
        case 'month':
            $dateCondition = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
        case 'year':
            $dateCondition = 'created_at >= DATE_SUB(NOW(), INTERVAL 365 DAY)';
            break;
        default:
            $dateCondition = '1=1';
    }

    // เงื่อนไขเจ้าหน้าที่
    $staffCondition = '';
    $params = [];

    if ($staffId && !hasRole('admin')) {
        $staffCondition = ' AND assigned_to = ?';
        $params[] = $staffId;
    } elseif (!hasRole('admin')) {
        $staffCondition = ' AND (assigned_to = ? OR assigned_to IS NULL)';
        $params[] = $user['id'];
    }

    // สถิติพื้นฐาน
    $basicStats = $db->fetch("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high
        FROM complaints 
        WHERE {$dateCondition} {$staffCondition}
    ", $params);

    $stats['basic'] = $basicStats;

    // สถิติตามหมวดหมู่
    $categoryStats = $db->fetchAll("
        SELECT category, COUNT(*) as count
        FROM complaints 
        WHERE {$dateCondition} {$staffCondition}
        GROUP BY category
        ORDER BY count DESC
    ", $params);

    $stats['categories'] = $categoryStats;

    // สถิติตามวัน (7 วันล่าสุด)
    $dailyStats = $db->fetchAll("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM complaints 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) {$staffCondition}
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ", $params);

    $stats['daily'] = $dailyStats;

    // เวลาตอบสนองเฉลี่ย
    $responseTime = $db->fetch("
        SELECT AVG(TIMESTAMPDIFF(HOUR, c.created_at, m.created_at)) as avg_hours
        FROM complaints c
        JOIN (
            SELECT complaint_id, MIN(created_at) as created_at
            FROM complaint_messages
            WHERE is_staff_reply = 1
            GROUP BY complaint_id
        ) m ON c.id = m.complaint_id
        WHERE {$dateCondition} {$staffCondition}
    ", $params);

    $stats['avg_response_time'] = round($responseTime['avg_hours'] ?? 0, 1);

    // คะแนนประเมินเฉลี่ย
    $evaluation = $db->fetch("
        SELECT AVG(overall_rating) as avg_rating, COUNT(*) as total_evaluations
        FROM evaluations e
        JOIN complaints c ON e.complaint_id = c.id
        WHERE {$dateCondition} {$staffCondition}
    ", $params);

    $stats['evaluation'] = [
        'avg_rating' => round($evaluation['avg_rating'] ?? 0, 1),
        'total_evaluations' => $evaluation['total_evaluations'] ?? 0
    ];

    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}

function handleGetPriorityQueue()
{
    global $db;

    $sql = "
        SELECT c.*, 
               u.student_id, u.first_name, u.last_name,
               a.first_name as assigned_first_name, a.last_name as assigned_last_name,
               TIMESTAMPDIFF(HOUR, c.created_at, NOW()) as hours_pending
        FROM complaints c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN users a ON c.assigned_to = a.id
        WHERE c.status IN ('pending', 'processing')
        ORDER BY 
            CASE 
                WHEN c.priority = 'urgent' THEN 1 
                WHEN c.priority = 'high' THEN 2 
                WHEN c.priority = 'medium' THEN 3 
                ELSE 4 
            END,
            c.created_at ASC
        LIMIT 20
    ";

    $queue = $db->fetchAll($sql);

    foreach ($queue as &$item) {
        $item['created_at_formatted'] = formatThaiDateTime($item['created_at']);
        $item['status_text'] = getStatusText($item['status']);
        $item['priority_text'] = getPriorityText($item['priority']);
        $item['urgency_score'] = calculateUrgencyScore($item);
    }

    echo json_encode([
        'success' => true,
        'queue' => $queue
    ]);
}

function handleGetStaffWorkload()
{
    global $db;

    $sql = "
        SELECT u.id, u.first_name, u.last_name, u.faculty,
               COUNT(c.id) as total_assigned,
               SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN c.status = 'processing' THEN 1 ELSE 0 END) as processing,
               SUM(CASE WHEN c.status = 'completed' THEN 1 ELSE 0 END) as completed_this_month,
               AVG(CASE WHEN e.overall_rating > 0 THEN e.overall_rating ELSE NULL END) as avg_rating
        FROM users u
        LEFT JOIN complaints c ON u.id = c.assigned_to 
        LEFT JOIN evaluations e ON c.id = e.complaint_id
        WHERE u.role IN ('staff', 'admin') AND u.status = 'active'
        GROUP BY u.id
        ORDER BY total_assigned DESC
    ";

    $workload = $db->fetchAll($sql);

    foreach ($workload as &$staff) {
        $staff['avg_rating'] = $staff['avg_rating'] ? round($staff['avg_rating'], 1) : null;
        $staff['workload_score'] = ($staff['pending'] * 2) + $staff['processing'];
    }

    echo json_encode([
        'success' => true,
        'workload' => $workload
    ]);
}

// Helper functions
function formatThaiDateTime($datetime)
{
    return date('d/m/Y H:i', strtotime($datetime));
}

function formatThaiDate($date)
{
    return date('d/m/Y', strtotime($date));
}

function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getStatusText($status)
{
    $statuses = [
        'pending' => 'รอดำเนินการ',
        'processing' => 'กำลังดำเนินการ',
        'waiting_approval' => 'รออนุมัติ',
        'completed' => 'เสร็จสิ้น',
        'rejected' => 'ปฏิเสธ',
        'cancelled' => 'ยกเลิก'
    ];
    return $statuses[$status] ?? $status;
}

function getPriorityText($priority)
{
    $priorities = [
        'low' => 'ต่ำ',
        'medium' => 'ปกติ',
        'high' => 'สูง',
        'urgent' => 'เร่งด่วน'
    ];
    return $priorities[$priority] ?? 'ไม่ระบุ';
}

function getCategoryText($category)
{
    $categories = [
        'academic' => 'การเรียนการสอน',
        'facility' => 'สิ่งอำนวยความสะดวก',
        'finance' => 'การเงิน',
        'staff' => 'บุคลากร',
        'technology' => 'เทคโนโลยี',
        'transport' => 'การคมนาคม',
        'health' => 'สุขภาพ',
        'other' => 'อื่นๆ'
    ];
    return $categories[$category] ?? $category;
}

function calculateUrgencyScore($complaint)
{
    $score = 0;

    // คะแนนตามความสำคัญ
    switch ($complaint['priority']) {
        case 'urgent':
            $score += 100;
            break;
        case 'high':
            $score += 75;
            break;
        case 'medium':
            $score += 50;
            break;
        case 'low':
            $score += 25;
            break;
    }

    // คะแนนตามเวลาที่รอ
    $hours = $complaint['hours_pending'];
    if ($hours > 72) $score += 30;
    elseif ($hours > 48) $score += 20;
    elseif ($hours > 24) $score += 10;

    // คะแนนตามสถานะ
    if ($complaint['status'] === 'pending') $score += 20;

    return $score;
}
