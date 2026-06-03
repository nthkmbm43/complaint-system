<?php
// staff/ajax/users_search.php - จัดการการค้นหาและดึงข้อมูลนักศึกษา
define('SECURE_ACCESS', true);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ตั้งค่า response เป็น JSON
header('Content-Type: application/json');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาล็อกอินก่อน']);
    exit;
}

if (!hasRole('teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
    exit;
}

$db = getDB();
$userPermission = $_SESSION['permission'] ?? 0;

$action = $_GET['action'] ?? 'search';

try {
    switch ($action) {
        case 'search':
            handleSearch($db);
            break;

        case 'get_student':
            handleGetStudent($db);
            break;

        case 'get_student_detail':
            handleGetStudentDetail($db);
            break;

        case 'get_stats':
            handleGetStats($db);
            break;

        case 'get_faculties':
            handleGetFaculties($db);
            break;

        case 'get_suspension_info':
            handleGetSuspensionInfo($db);
            break;

        default:
            throw new Exception('Action ไม่ถูกต้อง');
    }
} catch (Exception $e) {
    error_log("Users search error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function handleSearch($db)
{
    // พารามิเตอร์การค้นหา
    $search = trim($_GET['search'] ?? '');
    $statusFilter = $_GET['status'] ?? '';
    $facultyFilter = intval($_GET['faculty'] ?? 0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    // สร้างเงื่อนไขการค้นหา
    $params = [];
    $whereConditions = [];

    if (!empty($search)) {
        $whereConditions[] = "(s.Stu_id LIKE ? OR s.Stu_name LIKE ? OR s.Stu_email LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($statusFilter !== '') {
        $whereConditions[] = "s.Stu_status = ?";
        $params[] = intval($statusFilter);
    }

    if ($facultyFilter > 0) {
        $whereConditions[] = "faculty.Unit_id = ?";
        $params[] = $facultyFilter;
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // นับจำนวนรวม
    $countSql = "SELECT COUNT(*) as total 
                 FROM student s
                 LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                 LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                 $whereClause";

    $totalResult = $db->fetch($countSql, $params);
    $total = $totalResult['total'];

    // ดึงข้อมูลนักศึกษา
    $sql = "SELECT s.*, 
                   major.Unit_name as major_name, major.Unit_icon as major_icon,
                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon
            FROM student s
            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            $whereClause
            ORDER BY s.created_at DESC, s.Stu_id ASC
            LIMIT ? OFFSET ?";

    $limitParams = array_merge($params, [$limit, $offset]);
    $students = $db->fetchAll($sql, $limitParams);

    // ปรับรูปแบบข้อมูล
    foreach ($students as &$student) {
        $student['created_at_formatted'] = $student['created_at'] ?
            (new DateTime($student['created_at']))->format('d/m/Y') : '-';
        $student['status_text'] = $student['Stu_status'] == 1 ? 'ใช้งานอยู่' : 'ระงับ';
        $student['status_class'] = $student['Stu_status'] == 1 ? 'active' : 'suspended';
    }

    echo json_encode([
        'success' => true,
        'data' => $students,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => ceil($total / $limit),
            'total_items' => $total,
            'items_per_page' => $limit,
            'start_item' => $offset + 1,
            'end_item' => min($offset + $limit, $total)
        ],
        'filters' => [
            'search' => $search,
            'status' => $statusFilter,
            'faculty' => $facultyFilter
        ]
    ]);
}

function handleGetStudent($db)
{
    $studentId = trim($_GET['student_id'] ?? '');

    if (empty($studentId)) {
        throw new Exception('ไม่พบรหัสนักศึกษา');
    }

    $sql = "SELECT s.*, 
                   major.Unit_name as major_name, major.Unit_icon as major_icon,
                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon
            FROM student s
            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            WHERE s.Stu_id = ?";

    $student = $db->fetch($sql, [$studentId]);

    if (!$student) {
        throw new Exception('ไม่พบนักศึกษาที่ระบุ');
    }

    // ดึงสถิติข้อร้องเรียน
    $complaintStats = $db->fetch("
        SELECT 
            COUNT(*) as total_complaints,
            SUM(CASE WHEN Re_status = 'completed' THEN 1 ELSE 0 END) as completed_complaints,
            SUM(CASE WHEN Re_status = 'pending' THEN 1 ELSE 0 END) as pending_complaints
        FROM request 
        WHERE Stu_id = ?
    ", [$studentId]);

    $student['complaint_stats'] = $complaintStats;
    $student['created_at_formatted'] = $student['created_at'] ?
        (new DateTime($student['created_at']))->format('d/m/Y H:i') : '-';

    echo json_encode([
        'success' => true,
        'student' => $student
    ]);
}

function handleGetStudentDetail($db)
{
    $studentId = trim($_GET['student_id'] ?? '');

    if (empty($studentId)) {
        throw new Exception('ไม่พบรหัสนักศึกษา');
    }

    // ดึงข้อมูลนักศึกษาพร้อมข้อมูลเพิ่มเติม
    $sql = "SELECT s.*, 
                   major.Unit_name as major_name, major.Unit_icon as major_icon,
                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                   suspender.Aj_name as suspended_by_name
            FROM student s
            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            LEFT JOIN teacher suspender ON s.Stu_suspend_by = suspender.Aj_id
            WHERE s.Stu_id = ?";

    $student = $db->fetch($sql, [$studentId]);

    if (!$student) {
        throw new Exception('ไม่พบนักศึกษาที่ระบุ');
    }

    // ดึงสถิติข้อร้องเรียน
    $complaintStats = $db->fetch("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN Re_status = '0' THEN 1 ELSE 0 END) as pending_requests,
            SUM(CASE WHEN Re_status = '1' THEN 1 ELSE 0 END) as confirmed_requests,
            SUM(CASE WHEN Re_status = '2' THEN 1 ELSE 0 END) as processed_requests,
            SUM(CASE WHEN Re_status = '3' THEN 1 ELSE 0 END) as completed_requests
        FROM request 
        WHERE Stu_id = ?
    ", [$studentId]);

    // ดึงข้อร้องเรียนล่าสุด 5 รายการ
    $recentRequests = $db->fetchAll("
        SELECT r.Re_id, r.Re_title, r.Re_infor, r.Re_status, r.Re_level, r.Re_date,
               t.Type_infor, t.Type_icon,
               CASE r.Re_status 
                   WHEN '0' THEN 'ยื่นคำร้อง'
                        WHEN '1' THEN 'กำลังดำเนินการ'
                        WHEN '2' THEN 'รอการประเมินผล'
                        WHEN '3' THEN 'เสร็จสิ้น'
                        WHEN '4' THEN 'ปฏิเสธคำร้อง'
                   ELSE 'ไม่ระบุ'
               END as status_text,
               CASE r.Re_level
                   WHEN '1' THEN 'ไม่เร่งด่วน'
                   WHEN '2' THEN 'ปกติ'
                   WHEN '3' THEN 'เร่งด่วน'
                   WHEN '4' THEN 'เร่งด่วนมาก'
                   WHEN '5' THEN 'วิกฤต/ฉุกเฉิน'
                   ELSE 'ไม่ระบุ'
               END as level_text
        FROM request r
        LEFT JOIN type t ON r.Type_id = t.Type_id
        WHERE r.Stu_id = ?
        ORDER BY r.Re_date DESC
        LIMIT 5
    ", [$studentId]);

    // ดึงประวัติการระงับ (ถ้ามี)
    $suspendHistory = $db->fetchAll("
        SELECT sh.*, 
               suspend_by.Aj_name as suspended_by_name,
               release_by.Aj_name as released_by_name,
               r.Re_title, r.Re_infor
        FROM suspend_history sh
        LEFT JOIN teacher suspend_by ON sh.Sh_suspend_by = suspend_by.Aj_id
        LEFT JOIN teacher release_by ON sh.Sh_release_by = release_by.Aj_id
        LEFT JOIN request r ON sh.Re_id = r.Re_id
        WHERE sh.Sh_user_id = ? AND sh.Sh_user_type = 'S'
        ORDER BY sh.Sh_suspend_date DESC
    ", [$studentId]);

    // ดึงคะแนนประเมินเฉลี่ย
    $evaluationStats = $db->fetch("
        SELECT 
            AVG(e.Eva_score) as avg_score,
            COUNT(e.Eva_id) as total_evaluations
        FROM evaluation e
        INNER JOIN request r ON e.Re_id = r.Re_id
        WHERE r.Stu_id = ?
    ", [$studentId]);

    // จัดรูปแบบข้อมูล
    $student['created_at_formatted'] = $student['created_at'] ?
        (new DateTime($student['created_at']))->format('d/m/Y H:i') : '-';

    $student['status_text'] = $student['Stu_status'] == 1 ? 'ใช้งานอยู่' : 'ระงับ';
    $student['status_class'] = $student['Stu_status'] == 1 ? 'active' : 'suspended';

    // จัดรูปแบบวันที่สำหรับ recent requests
    foreach ($recentRequests as &$request) {
        $request['Re_date_formatted'] = (new DateTime($request['Re_date']))->format('d/m/Y');
    }

    echo json_encode([
        'success' => true,
        'student' => $student,
        'complaint_stats' => $complaintStats,
        'recent_requests' => $recentRequests,
        'suspend_history' => $suspendHistory,
        'evaluation_stats' => $evaluationStats
    ]);
}

function handleGetSuspensionInfo($db)
{
    $studentId = trim($_GET['student_id'] ?? '');

    if (empty($studentId)) {
        throw new Exception('ไม่พบรหัสนักศึกษา');
    }

    // ตรวจสอบสถานะนักศึกษา
    $student = $db->fetch("SELECT Stu_status, Stu_suspend_reason, Stu_suspend_date, Stu_suspend_by FROM student WHERE Stu_id = ?", [$studentId]);

    if (!$student) {
        throw new Exception('ไม่พบข้อมูลนักศึกษา');
    }

    if ($student['Stu_status'] != 0) {
        throw new Exception('นักศึกษาไม่ได้อยู่ในสถานะถูกระงับ');
    }

    // เริ่มต้นข้อมูลการระงับ
    $suspensionInfo = [
        'suspend_date_formatted' => 'ไม่ระบุ',
        'suspended_by_name' => 'ไม่ระบุ',
        'suspend_reason' => $student['Stu_suspend_reason'] ?? 'ไม่ระบุเหตุผล'
    ];

    // แปลงวันที่ระงับ
    if ($student['Stu_suspend_date']) {
        try {
            $suspensionInfo['suspend_date_formatted'] = (new DateTime($student['Stu_suspend_date']))->format('d/m/Y H:i');
        } catch (Exception $e) {
            $suspensionInfo['suspend_date_formatted'] = 'ไม่ระบุ';
        }
    }

    // ดึงชื่อผู้ระงับ
    if ($student['Stu_suspend_by']) {
        try {
            $suspender = $db->fetch("SELECT Aj_name FROM teacher WHERE Aj_id = ?", [$student['Stu_suspend_by']]);
            if ($suspender) {
                $suspensionInfo['suspended_by_name'] = $suspender['Aj_name'];
            }
        } catch (Exception $e) {
            // ใช้ข้อมูลเริ่มต้น
        }
    }

    // ลองดึงจากตาราง suspend_history ถ้ามี
    try {
        $history = $db->fetch("
            SELECT sh.*, t.Aj_name as suspended_by_name
            FROM suspend_history sh
            LEFT JOIN teacher t ON sh.Sh_suspend_by = t.Aj_id
            WHERE sh.Sh_user_id = ? AND sh.Sh_user_type = 'S' AND sh.Sh_release_date IS NULL
            ORDER BY sh.Sh_suspend_date DESC
            LIMIT 1
        ", [$studentId]);

        if ($history) {
            if ($history['Sh_suspend_date']) {
                $suspensionInfo['suspend_date_formatted'] = (new DateTime($history['Sh_suspend_date']))->format('d/m/Y H:i');
            }
            if ($history['suspended_by_name']) {
                $suspensionInfo['suspended_by_name'] = $history['suspended_by_name'];
            }
            if ($history['Sh_reason']) {
                $suspensionInfo['suspend_reason'] = $history['Sh_reason'];
            }
        }
    } catch (Exception $e) {
        // ตาราง suspend_history อาจไม่มี ใช้ข้อมูลจากตาราง student
    }

    echo json_encode([
        'success' => true,
        'suspension_info' => $suspensionInfo
    ]);
}

function handleGetStats($db)
{
    try {
        $stats = [
            'total' => $db->count('student'),
            'active' => $db->count('student', 'Stu_status = 1'),
            'suspended' => $db->count('student', 'Stu_status = 0'),
            'today' => $db->count('student', 'DATE(created_at) = CURDATE()'),
            'this_week' => $db->count('student', 'WEEK(created_at) = WEEK(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())'),
            'this_month' => $db->count('student', 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())'),
            'this_year' => $db->count('student', 'YEAR(created_at) = YEAR(CURDATE())')
        ];

        // สถิติตามคณะ
        $facultyStats = $db->fetchAll("
            SELECT 
                faculty.Unit_id,
                faculty.Unit_name,
                faculty.Unit_icon,
                COUNT(s.Stu_id) as student_count,
                SUM(CASE WHEN s.Stu_status = 1 THEN 1 ELSE 0 END) as active_count
            FROM organization_unit faculty
            LEFT JOIN organization_unit major ON major.Unit_parent_id = faculty.Unit_id
            LEFT JOIN student s ON s.Unit_id = major.Unit_id
            WHERE faculty.Unit_parent_id IS NULL OR faculty.Unit_parent_id = 0
            GROUP BY faculty.Unit_id
            ORDER BY student_count DESC
        ");

        $stats['faculty_stats'] = $facultyStats;

        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงสถิติได้: ' . $e->getMessage());
    }
}

function handleGetFaculties($db)
{
    try {
        // ดึงข้อมูลคณะ
        $faculties = $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_parent_id IS NULL OR Unit_parent_id = 0 
            ORDER BY Unit_name
        ");

        // ดึงข้อมูลสาขา
        $majors = $db->fetchAll("
            SELECT m.Unit_id, m.Unit_name, m.Unit_icon, m.Unit_parent_id,
                   f.Unit_name as faculty_name
            FROM organization_unit m
            LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id
            WHERE m.Unit_parent_id IS NOT NULL AND m.Unit_parent_id > 0 
            ORDER BY f.Unit_name, m.Unit_name
        ");

        // จัดกลุ่มสาขาตามคณะ
        $facultiesWithMajors = [];
        foreach ($faculties as $faculty) {
            $faculty['majors'] = array_filter($majors, function ($major) use ($faculty) {
                return $major['Unit_parent_id'] == $faculty['Unit_id'];
            });
            $facultiesWithMajors[] = $faculty;
        }

        echo json_encode([
            'success' => true,
            'faculties' => $facultiesWithMajors,
            'majors' => $majors
        ]);
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงข้อมูลหน่วยงานได้: ' . $e->getMessage());
    }
}
