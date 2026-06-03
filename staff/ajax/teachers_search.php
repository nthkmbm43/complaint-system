<?php
// ajax/teachers_search.php - จัดการการค้นหาและดึงข้อมูลอาจารย์/เจ้าหน้าที่
define('SECURE_ACCESS', true);

// เพิ่ม output buffering และ error handling
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ป้องกัน error output ก่อน JSON response
try {
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../includes/auth.php';
    require_once '../../includes/functions.php';
} catch (Exception $e) {
    // ล้าง output buffer และส่ง error response
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'System error: Cannot load required files']);
    exit;
}

// ล้าง output buffer ก่อนส่ง JSON response
ob_clean();

// Set JSON response header ก่อนอื่นหมด
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ตรวจสอบการล็อกอินและสิทธิ์
try {
    if (
        !function_exists('isLoggedIn') || !isLoggedIn() ||
        !function_exists('hasRole') || !hasRole('teacher')
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Authentication error']);
    exit;
}

// ตรวจสอบ AJAX request (ทำให้ flexible มากขึ้น)
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
$hasAction = isset($_GET['action']) && !empty($_GET['action']);

if (!$isAjax && !$hasAction) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    // ตรวจสอบ database connection
    $db = getDB();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $action = $_GET['action'] ?? '';

    switch ($action) {
        case 'search':
            handleSearch($db);
            break;

        case 'get_stats':
            handleGetStats($db);
            break;

        case 'get_teacher':
            handleGetTeacher($db);
            break;

        case 'get_teacher_detail':
            handleGetTeacherDetail($db);
            break;

        case 'get_suspension_info':
            handleGetSuspensionInfo($db);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
    }
} catch (Exception $e) {
    error_log("Teachers search error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการประมวลผล'
    ]);
}

function handleSearch($db)
{
    try {
        // รับค่าพารามิเตอร์
        $search = trim($_GET['search'] ?? '');
        $status = $_GET['status'] ?? '';
        $department = $_GET['department'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // สร้าง WHERE clause
        $conditions = [];
        $params = [];

        if ($search) {
            $conditions[] = "(t.Aj_name LIKE ? OR t.Aj_email LIKE ? OR t.Aj_tel LIKE ? OR t.Aj_position LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
        }

        if ($status !== '') {
            $conditions[] = "t.Aj_status = ?";
            $params[] = intval($status);
        }

        if ($department) {
            $conditions[] = "t.Unit_id = ?";
            $params[] = intval($department);
        }

        $whereClause = !empty($conditions) ? "WHERE " . implode(' AND ', $conditions) : "";

        // Count total records
        $countSql = "SELECT COUNT(*) as total FROM teacher t {$whereClause}";
        $totalResult = $db->fetchOne($countSql, $params);
        $total = $totalResult['total'] ?? 0;

        // Main query
        $sql = "SELECT 
                    t.*,
                    u.Unit_name as unit_name,
                    u.Unit_icon as unit_icon,
                    u.Unit_type as unit_type
                FROM teacher t
                LEFT JOIN organization_unit u ON t.Unit_id = u.Unit_id
                {$whereClause}
                ORDER BY t.Aj_id DESC
                LIMIT ? OFFSET ?";

        // เพิ่ม limit และ offset เข้าไปใน params
        $params[] = $limit;
        $params[] = $offset;

        $teachers = $db->fetchAll($sql, $params) ?? [];

        // ===== ส่วนสำคัญ: ปรับรูปแบบข้อมูลก่อนส่งกลับ =====
        foreach ($teachers as &$teacher) {
            // Format วันที่สร้าง
            $teacher['created_at_formatted'] = '-';
            if (!empty($teacher['created_at'])) {
                try {
                    $teacher['created_at_formatted'] = (new DateTime($teacher['created_at']))->format('d/m/Y');
                } catch (Exception $e) {
                    $teacher['created_at_formatted'] = '-';
                }
            }

            // Format วันที่อัปเดต
            $teacher['updated_at_formatted'] = '-';
            if (!empty($teacher['updated_at'])) {
                try {
                    $teacher['updated_at_formatted'] = (new DateTime($teacher['updated_at']))->format('d/m/Y H:i');
                } catch (Exception $e) {
                    $teacher['updated_at_formatted'] = '-';
                }
            }

            // กำหนด status text และ class (สำคัญมาก!)
            $teacher['status_text'] = $teacher['Aj_status'] == 1 ? 'ใช้งานอยู่' : 'ระงับ';
            $teacher['status_class'] = $teacher['Aj_status'] == 1 ? 'active' : 'suspended';

            // กำหนด permission text
            $permissionTexts = [
                0 => 'ปกติ',
                1 => 'อาจารย์',
                2 => 'ผู้ช่วย',
                3 => 'ผู้ดูแล'
            ];
            $teacher['permission_text'] = $permissionTexts[$teacher['Aj_per'] ?? 0] ?? 'ไม่ระบุ';
        }
        unset($teacher); // ยกเลิก reference

        // Calculate pagination
        $totalPages = $total > 0 ? ceil($total / $limit) : 1;
        $startItem = $total > 0 ? $offset + 1 : 0;
        $endItem = min($offset + $limit, $total);

        echo json_encode([
            'success' => true,
            'data' => $teachers,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $total,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'items_per_page' => $limit,
                'start_item' => $startItem,
                'end_item' => $endItem,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'department' => $department,
                'page' => $page
            ]
        ]);
    } catch (Exception $e) {
        error_log("handleSearch error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการค้นหาข้อมูล'
        ]);
    }
}

function handleGetStats($db)
{
    try {
        // ใช้ method ที่ปลอดภัยและมี fallback
        $stats = [
            'total' => 0,
            'active' => 0,
            'suspended' => 0,
            'today' => 0
        ];

        // ตรวจสอบว่า table teacher มีอยู่จริง
        $checkTable = $db->fetchOne("SHOW TABLES LIKE 'teacher'");
        if (!$checkTable) {
            echo json_encode([
                'success' => false,
                'message' => 'ตาราง teacher ไม่พบในฐานข้อมูล'
            ]);
            return;
        }

        // นับจำนวนรวม
        $totalResult = $db->fetchOne("SELECT COUNT(*) as count FROM teacher");
        $stats['total'] = $totalResult['count'] ?? 0;

        // นับจำนวนที่ active
        $activeResult = $db->fetchOne("SELECT COUNT(*) as count FROM teacher WHERE Aj_status = 1");
        $stats['active'] = $activeResult['count'] ?? 0;

        // นับจำนวนที่ suspended
        $suspendedResult = $db->fetchOne("SELECT COUNT(*) as count FROM teacher WHERE Aj_status = 0");
        $stats['suspended'] = $suspendedResult['count'] ?? 0;

        // นับจำนวนวันนี้ (ถ้ามี column created_at)
        try {
            $todayResult = $db->fetchOne("SELECT COUNT(*) as count FROM teacher WHERE DATE(created_at) = CURDATE()");
            $stats['today'] = $todayResult['count'] ?? 0;
        } catch (Exception $e) {
            // ถ้าไม่มี column created_at ให้ใช้ค่า 0
            $stats['today'] = 0;
        }

        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
    } catch (Exception $e) {
        error_log("handleGetStats error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการดึงสถิติ',
            'stats' => [
                'total' => 0,
                'active' => 0,
                'suspended' => 0,
                'today' => 0
            ]
        ]);
    }
}

function handleGetTeacher($db)
{
    try {
        $teacherId = intval($_GET['teacher_id'] ?? 0);

        if (!$teacherId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสอาจารย์']);
            return;
        }

        $sql = "SELECT 
                    t.*,
                    u.Unit_name as unit_name,
                    u.Unit_icon as unit_icon,
                    u.Unit_type as unit_type
                FROM teacher t
                LEFT JOIN organization_unit u ON t.Unit_id = u.Unit_id
                WHERE t.Aj_id = ?";

        $teacher = $db->fetchOne($sql, [$teacherId]);

        if (!$teacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์']);
            return;
        }

        // Format ข้อมูล
        $teacher['created_at_formatted'] = '-';
        if (!empty($teacher['created_at'])) {
            try {
                $teacher['created_at_formatted'] = (new DateTime($teacher['created_at']))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $teacher['created_at_formatted'] = '-';
            }
        }

        $teacher['updated_at_formatted'] = '-';
        if (!empty($teacher['updated_at'])) {
            try {
                $teacher['updated_at_formatted'] = (new DateTime($teacher['updated_at']))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $teacher['updated_at_formatted'] = '-';
            }
        }

        $teacher['status_text'] = $teacher['Aj_status'] == 1 ? 'ใช้งานอยู่' : 'ระงับ';
        $teacher['status_class'] = $teacher['Aj_status'] == 1 ? 'active' : 'suspended';

        echo json_encode([
            'success' => true,
            'teacher' => $teacher
        ]);
    } catch (Exception $e) {
        error_log("handleGetTeacher error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลอาจารย์'
        ]);
    }
}

function handleGetTeacherDetail($db)
{
    try {
        $teacherId = intval($_GET['teacher_id'] ?? 0);

        if (!$teacherId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสอาจารย์']);
            return;
        }

        // ดึงข้อมูลอาจารย์พร้อมหน่วยงาน
        $sql = "SELECT 
                    t.*,
                    u.Unit_name as unit_name,
                    u.Unit_icon as unit_icon,
                    u.Unit_type as unit_type
                FROM teacher t
                LEFT JOIN organization_unit u ON t.Unit_id = u.Unit_id
                WHERE t.Aj_id = ?";

        $teacher = $db->fetchOne($sql, [$teacherId]);

        if (!$teacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์']);
            return;
        }

        // ===== Format ข้อมูลวันที่และสถานะ =====
        $teacher['created_at_formatted'] = '-';
        if (!empty($teacher['created_at'])) {
            try {
                $teacher['created_at_formatted'] = (new DateTime($teacher['created_at']))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $teacher['created_at_formatted'] = '-';
            }
        }

        $teacher['updated_at_formatted'] = '-';
        if (!empty($teacher['updated_at'])) {
            try {
                $teacher['updated_at_formatted'] = (new DateTime($teacher['updated_at']))->format('d/m/Y H:i');
            } catch (Exception $e) {
                $teacher['updated_at_formatted'] = '-';
            }
        }

        $teacher['status_text'] = $teacher['Aj_status'] == 1 ? 'ใช้งานอยู่' : 'ระงับ';
        $teacher['status_class'] = $teacher['Aj_status'] == 1 ? 'active' : 'suspended';

        // กำหนด permission text
        $permissionTexts = [
            0 => 'ปกติ',
            1 => 'อาจารย์',
            2 => 'ผู้ช่วย',
            3 => 'ผู้ดูแล'
        ];
        $teacher['permission_text'] = $permissionTexts[$teacher['Aj_per'] ?? 0] ?? 'ไม่ระบุ';

        // ดึงสถิติการจัดการข้อร้องเรียน (ถ้ามี)
        $requestStats = [
            'total_handled' => 0,
            'completed_handled' => 0,
            'in_progress' => 0
        ];

        try {
            $statsResult = $db->fetchOne("
                SELECT 
                    COUNT(DISTINCT sr.Re_id) as total_handled,
                    COUNT(DISTINCT CASE WHEN r.Re_status = '2' THEN sr.Re_id END) as completed_handled,
                    COUNT(DISTINCT CASE WHEN r.Re_status = '1' THEN sr.Re_id END) as in_progress
                FROM save_request sr
                LEFT JOIN request r ON sr.Re_id = r.Re_id
                WHERE sr.Aj_id = ?
            ", [$teacherId]);

            if ($statsResult) {
                $requestStats = $statsResult;
            }
        } catch (Exception $e) {
            // ใช้ค่าเริ่มต้น
        }

        $teacher['request_stats'] = $requestStats;

        // ดึงข้อร้องเรียนที่ได้รับมอบหมายล่าสุด
        $recentAssigned = [];

        try {
            $recentAssigned = $db->fetchAll("
                SELECT r.Re_id, r.Re_title, r.Re_infor, r.Re_status, r.Re_date,
                       t.Type_infor, t.Type_icon,
                       s.Stu_name,
                       CASE r.Re_status 
                           WHEN '0' THEN 'ยื่นคำร้อง'
                           WHEN '1' THEN 'กำลังดำเนินการ'
                           WHEN '2' THEN 'รอการประเมินผล'
                           WHEN '3' THEN 'เสร็จสิ้น'
                           WHEN '4' THEN 'ปฏิเสธคำร้อง'
                           ELSE 'ไม่ระบุ'
                       END as status_text
                FROM request r
                LEFT JOIN type t ON r.Type_id = t.Type_id
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                WHERE r.Aj_id = ?
                ORDER BY r.created_at DESC
                LIMIT 5
            ", [$teacherId]) ?? [];

            // Format วันที่
            foreach ($recentAssigned as &$request) {
                $request['Re_date_formatted'] = '-';
                if (!empty($request['Re_date'])) {
                    try {
                        $request['Re_date_formatted'] = (new DateTime($request['Re_date']))->format('d/m/Y');
                    } catch (Exception $e) {
                        $request['Re_date_formatted'] = '-';
                    }
                }
            }
            unset($request);
        } catch (Exception $e) {
            // ใช้ค่าเริ่มต้น
        }

        $teacher['recent_assigned'] = $recentAssigned;

        echo json_encode([
            'success' => true,
            'teacher' => $teacher
        ]);
    } catch (Exception $e) {
        error_log("handleGetTeacherDetail error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการดึงรายละเอียดอาจารย์'
        ]);
    }
}

function handleGetSuspensionInfo($db)
{
    try {
        $teacherId = intval($_GET['teacher_id'] ?? 0);

        if (!$teacherId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสอาจารย์']);
            return;
        }

        // ตรวจสอบว่ามี teacher อยู่จริง
        $teacher = $db->fetchOne("SELECT Aj_id, Aj_status, Aj_name FROM teacher WHERE Aj_id = ?", [$teacherId]);

        if (!$teacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์']);
            return;
        }

        // เริ่มต้นข้อมูลการระงับ
        $suspensionInfo = [
            'teacher_id' => $teacher['Aj_id'],
            'current_status' => $teacher['Aj_status'],
            'suspend_date_formatted' => 'ไม่ระบุ',
            'suspended_by_name' => 'ไม่ระบุ',
            'suspend_reason' => 'ไม่ระบุเหตุผล',
            'can_release' => true
        ];

        // ลองดึงจากตาราง suspend_history ถ้ามี
        try {
            $history = $db->fetchOne("
                SELECT sh.*, t.Aj_name as suspended_by_name
                FROM suspend_history sh
                LEFT JOIN teacher t ON sh.Sh_suspend_by = t.Aj_id
                WHERE sh.Sh_user_id = ? AND sh.Sh_user_type = 'T' AND sh.Sh_release_date IS NULL
                ORDER BY sh.Sh_suspend_date DESC
                LIMIT 1
            ", [$teacherId]);

            if ($history) {
                if (!empty($history['Sh_suspend_date'])) {
                    try {
                        $suspensionInfo['suspend_date_formatted'] = (new DateTime($history['Sh_suspend_date']))->format('d/m/Y H:i');
                    } catch (Exception $e) {
                        // ใช้ค่าเริ่มต้น
                    }
                }
                if (!empty($history['suspended_by_name'])) {
                    $suspensionInfo['suspended_by_name'] = $history['suspended_by_name'];
                }
                if (!empty($history['Sh_reason'])) {
                    $suspensionInfo['suspend_reason'] = $history['Sh_reason'];
                }
            }
        } catch (Exception $e) {
            // ตาราง suspend_history อาจไม่มี หรือไม่มีข้อมูล ใช้ค่าเริ่มต้น
            error_log("Suspend history query error: " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'suspension_info' => $suspensionInfo
        ]);
    } catch (Exception $e) {
        error_log("handleGetSuspensionInfo error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลการระงับ'
        ]);
    }
}
