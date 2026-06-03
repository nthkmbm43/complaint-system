<?php
// staff/ajax/assign_complaint.php - แก้ไขการมอบหมายงาน
define('SECURE_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ตรวจสอบว่าเป็น POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// ตรวจสอบการล็อกอิน
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

// ตรวจสอบสิทธิ์ (ต้องเป็นอาจารย์หรือแอดมิน)
$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission < 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ดำเนินการนี้']);
    exit;
}

$db = getDB();
$user = getCurrentUser();
$currentUserId = $user['id'] ?? $_SESSION['user_id'] ?? null;

// รับข้อมูลจาก POST
$action = trim($_POST['action'] ?? '');
$complaintId = intval($_POST['complaint_id'] ?? 0);
$teacherId = isset($_POST['teacher_id']) && $_POST['teacher_id'] !== '' ? intval($_POST['teacher_id']) : null;
$assignNote = trim($_POST['assign_note'] ?? '');

// LOG สำหรับ Debug
error_log("=== ASSIGN COMPLAINT DEBUG ===");
error_log("Action: " . $action);
error_log("Complaint ID: " . $complaintId);
error_log("Teacher ID: " . ($teacherId ?? 'NULL'));
error_log("Note: " . $assignNote);
error_log("Current User ID: " . ($currentUserId ?? 'NULL'));
error_log("POST Data: " . print_r($_POST, true));

// ตรวจสอบ complaint_id
if ($complaintId <= 0) {
    echo json_encode(['success' => false, 'message' => 'รหัสข้อร้องเรียนไม่ถูกต้อง']);
    exit;
}

// ตรวจสอบว่าข้อร้องเรียนมีอยู่จริง
$complaint = $db->fetch("SELECT * FROM request WHERE Re_id = ?", [$complaintId]);
if (!$complaint) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อร้องเรียนที่ระบุ']);
    exit;
}

// ตรวจสอบว่าข้อร้องเรียนถูกปิดไปแล้วหรือไม่
if (intval($complaint['Re_status']) >= 3) {
    echo json_encode(['success' => false, 'message' => 'ข้อร้องเรียนนี้ถูกปิดไปแล้ว ไม่สามารถแก้ไขได้']);
    exit;
}

try {
    $db->beginTransaction();

    switch ($action) {
        case 'assign':
            // มอบหมายงานครั้งแรก
            if (!$teacherId || $teacherId <= 0) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'กรุณาเลือกอาจารย์ที่จะมอบหมาย']);
                exit;
            }

            // ตรวจสอบว่าอาจารย์มีอยู่จริงและใช้งานได้
            $teacher = $db->fetch("SELECT * FROM teacher WHERE Aj_id = ? AND Aj_status = 1", [$teacherId]);
            if (!$teacher) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'ไม่พบอาจารย์ที่เลือก หรืออาจารย์ถูกระงับการใช้งาน']);
                exit;
            }

            // อัปเดตการมอบหมาย + เปลี่ยนสถานะเป็น 1 (กำลังดำเนินการ)
            $updateResult = $db->execute(
                "UPDATE request SET Aj_id = ?, Re_status = 1 WHERE Re_id = ?",
                [$teacherId, $complaintId]
            );

            if (!$updateResult) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถมอบหมายงานได้']);
                exit;
            }

            // บันทึกประวัติการมอบหมาย
            if ($currentUserId) {
                $logMessage = 'มอบหมายงานให้: ' . $teacher['Aj_name'];
                if ($assignNote) {
                    $logMessage .= "\nหมายเหตุ: " . $assignNote;
                }

                $db->execute(
                    "INSERT INTO save_request (Re_id, Aj_id, Sv_infor, Sv_type, Sv_date, created_at) 
                     VALUES (?, ?, ?, 'process', CURDATE(), NOW())",
                    [$complaintId, $currentUserId, $logMessage]
                );
            }

            // สร้างการแจ้งเตือนให้อาจารย์
            $db->execute(
                "INSERT INTO notification (Noti_title, Noti_message, Noti_type, Noti_status, Noti_date, Re_id, Aj_id, created_by) 
                 VALUES (?, ?, 'both', 0, NOW(), ?, ?, ?)",
                [
                    'ได้รับมอบหมายงานใหม่ #' . $complaintId,
                    'คุณได้รับมอบหมายให้ดำแนวข้อร้องเรียน: ' . ($complaint['Re_title'] ?? 'ไม่มีหัวข้อ'),
                    $complaintId,
                    $teacherId,
                    $currentUserId
                ]
            );

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'มอบหมายงานให้ ' . htmlspecialchars($teacher['Aj_name']) . ' เรียบร้อยแล้ว'
            ]);
            break;

        case 'reassign':
            // เปลี่ยนผู้รับผิดชอบ
            if (!$teacherId || $teacherId <= 0) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'กรุณาเลือกอาจารย์ที่จะมอบหมาย']);
                exit;
            }

            $teacher = $db->fetch("SELECT * FROM teacher WHERE Aj_id = ? AND Aj_status = 1", [$teacherId]);
            if (!$teacher) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'ไม่พบอาจารย์ที่เลือก หรืออาจารย์ถูกระงับการใช้งาน']);
                exit;
            }

            // ดึงข้อมูลผู้รับผิดชอบเดิม
            $previousAssigned = $complaint['Aj_id'];
            $previousTeacherName = '';
            if ($previousAssigned) {
                $prevTeacher = $db->fetch("SELECT Aj_name FROM teacher WHERE Aj_id = ?", [$previousAssigned]);
                $previousTeacherName = $prevTeacher['Aj_name'] ?? 'ไม่ทราบ';
            }

            // อัปเดตผู้รับผิดชอบ
            $updateResult = $db->execute(
                "UPDATE request SET Aj_id = ? WHERE Re_id = ?",
                [$teacherId, $complaintId]
            );

            if (!$updateResult) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถเปลี่ยนผู้รับผิดชอบได้']);
                exit;
            }

            // บันทึกประวัติ
            if ($currentUserId) {
                $logMessage = 'เปลี่ยนผู้รับผิดชอบจาก ' . $previousTeacherName . ' เป็น ' . $teacher['Aj_name'];
                if ($assignNote) {
                    $logMessage .= "\nหมายเหตุ: " . $assignNote;
                }

                $db->execute(
                    "INSERT INTO save_request (Re_id, Aj_id, Sv_infor, Sv_type, Sv_date, created_at) 
                     VALUES (?, ?, ?, 'process', CURDATE(), NOW())",
                    [$complaintId, $currentUserId, $logMessage]
                );
            }

            // แจ้งเตือนอาจารย์คนใหม่
            $db->execute(
                "INSERT INTO notification (Noti_title, Noti_message, Noti_type, Noti_status, Noti_date, Re_id, Aj_id, created_by) 
                 VALUES (?, ?, 'both', 0, NOW(), ?, ?, ?)",
                [
                    'ได้รับมอบหมายงานใหม่ #' . $complaintId,
                    'คุณได้รับมอบหมายให้ดำเนินการข้อร้องเรียน: ' . ($complaint['Re_title'] ?? 'ไม่มีหัวข้อ'),
                    $complaintId,
                    $teacherId,
                    $currentUserId
                ]
            );

            // แจ้งเตือนอาจารย์คนเดิม (ถ้ามี)
            if ($previousAssigned) {
                $db->execute(
                    "INSERT INTO notification (Noti_title, Noti_message, Noti_type, Noti_status, Noti_date, Re_id, Aj_id, created_by) 
                     VALUES (?, ?, 'system', 0, NOW(), ?, ?, ?)",
                    [
                        'มีการเปลี่ยนผู้รับผิดชอบ #' . $complaintId,
                        'งานที่คุณรับผิดชอบถูกมอบหมายให้ ' . $teacher['Aj_name'] . ' แทน',
                        $complaintId,
                        $previousAssigned,
                        $currentUserId
                    ]
                );
            }

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'เปลี่ยนผู้รับผิดชอบเป็น ' . htmlspecialchars($teacher['Aj_name']) . ' เรียบร้อยแล้ว'
            ]);
            break;

        case 'unassign':
            // ยกเลิกการมอบหมาย
            $previousAssigned = $complaint['Aj_id'];

            $updateResult = $db->execute(
                "UPDATE request SET Aj_id = NULL WHERE Re_id = ?",
                [$complaintId]
            );

            if (!$updateResult) {
                $db->rollback();
                echo json_encode(['success' => false, 'message' => 'ไม่สามารถยกเลิกการมอบหมายงานได้']);
                exit;
            }

            // บันทึกประวัติ
            if ($currentUserId) {
                $db->execute(
                    "INSERT INTO save_request (Re_id, Aj_id, Sv_infor, Sv_type, Sv_date, created_at) 
                     VALUES (?, ?, ?, 'process', CURDATE(), NOW())",
                    [$complaintId, $currentUserId, 'ยกเลิกการมอบหมายงาน']
                );
            }

            // แจ้งเตือนอาจารย์เดิม
            if ($previousAssigned) {
                $db->execute(
                    "INSERT INTO notification (Noti_title, Noti_message, Noti_type, Noti_status, Noti_date, Re_id, Aj_id, created_by) 
                     VALUES (?, ?, 'system', 0, NOW(), ?, ?, ?)",
                    [
                        'ยกเลิกการมอบหมาย #' . $complaintId,
                        'งานที่คุณรับผิดชอบถูกยกเลิกการมอบหมาย',
                        $complaintId,
                        $previousAssigned,
                        $currentUserId
                    ]
                );
            }

            $db->commit();

            echo json_encode([
                'success' => true,
                'message' => 'ยกเลิกการมอบหมายงานเรียบร้อยแล้ว'
            ]);
            break;

        default:
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Action ไม่ถูกต้อง: ' . $action]);
    }
} catch (Exception $e) {
    try { if (method_exists($db, 'rollBack')) $db->rollBack(); } catch(Exception $t) {}

    // แก้ไขจุดที่เป็น Syntax Error (ลบ message: และ value: ออก)
    error_log("Assign complaint error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}