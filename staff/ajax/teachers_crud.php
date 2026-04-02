<?php
// ajax/teachers_crud.php
define('SECURE_ACCESS', true);

// เพิ่ม output buffering และ error handling
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ล้าง output buffer ก่อนส่ง JSON response
ob_clean();

// Set JSON response header ก่อนอื่นหมด
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ตรวจสอบการล็อกอินและสิทธิ์
if (!isLoggedIn() || !hasRole('teacher')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$userPermission = $_SESSION['permission'] ?? 0;
$canEditUsers = ($userPermission >= 3); // เฉพาะผู้ดูแลระบบ (permission=3) เท่านั้น

if (!$canEditUsers) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'คุณไม่มีสิทธิ์ในการจัดการข้อมูลเจ้าหน้าที่ (ต้องการสิทธิ์ระดับผู้ดูแลระบบ)']);
    exit;
}

try {
    $db = getDB();

    // รับข้อมูล JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !isset($data['action'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid request data']);
        exit;
    }

    switch ($data['action']) {
        case 'get_next_id':
            $maxId = $db->fetchOne("SELECT COALESCE(MAX(Aj_id), -1) + 1 AS next_id FROM teacher");
            echo json_encode(['success' => true, 'next_id' => intval($maxId['next_id'])]);
            break;

        case 'add_teacher':
            handleAddTeacher($db, $data);
            break;

        case 'edit_teacher':
            handleEditTeacher($db, $data);
            break;

        case 'delete_teacher':
            handleDeleteTeacher($db, $data);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Teachers CRUD error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดภายในระบบ'
    ]);
}

function handleAddTeacher($db, $data)
{
    try {
        // Validation fields required
        $required_fields = ['teacher_name', 'teacher_password', 'permission'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                echo json_encode(['success' => false, 'message' => "กรุณากรอก" . getFieldLabel($field)]);
                return;
            }
        }

        $teacherName     = trim($data['teacher_name']);
        $teacherPassword = trim($data['teacher_password']);
        $teacherPosition = trim($data['teacher_position'] ?? '');
        $unitId          = !empty($data['unit_id']) ? intval($data['unit_id']) : null;
        $teacherTel      = trim($data['teacher_tel'] ?? '');
        $teacherEmail    = trim($data['teacher_email'] ?? '');
        $permission      = intval($data['permission']);

        // ตรวจสอบความยาวรหัสผ่าน
        if (strlen($teacherPassword) < 6) {
            echo json_encode(['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร']);
            return;
        }

        // ตรวจสอบ permission level
        $currentUserPermission = $_SESSION['permission'] ?? 0;
        if ($permission > $currentUserPermission) {
            echo json_encode(['success' => false, 'message' => 'คุณไม่สามารถให้สิทธิ์สูงกว่าระดับของคุณได้']);
            return;
        }

        // ตรวจสอบอีเมล (ถ้ามี)
        if ($teacherEmail && !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง']);
            return;
        }

        // ตรวจสอบอีเมลซ้ำ
        if ($teacherEmail) {
            $existingEmail = $db->fetchOne("SELECT Aj_id FROM teacher WHERE Aj_email = ?", [$teacherEmail]);
            if ($existingEmail) {
                echo json_encode(['success' => false, 'message' => 'อีเมลนี้ถูกใช้แล้ว']);
                return;
            }
        }

        // เริ่ม transaction + ล็อคตารางเพื่อรัน ID อย่างปลอดภัย
        $db->beginTransaction();

        // คำนวณ ID ใหม่ใน transaction (ป้องกัน race condition)
        $maxRow = $db->fetchOne("SELECT COALESCE(MAX(Aj_id), -1) + 1 AS next_id FROM teacher FOR UPDATE");
        $newTeacherId = intval($maxRow['next_id']);

        $sql = "INSERT INTO teacher (Aj_id, Aj_name, Aj_password, Aj_position, Unit_id, Aj_tel, Aj_email, Aj_per, Aj_status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";

        $params = [
            $newTeacherId,
            $teacherName,
            $teacherPassword,
            $teacherPosition ?: null,
            $unitId,
            $teacherTel ?: null,
            $teacherEmail ?: null,
            $permission
        ];

        $result = $db->execute($sql, $params);

        if ($result) {
            $db->commit();
            echo json_encode([
                'success'    => true,
                'message'    => "เพิ่มอาจารย์/เจ้าหน้าที่สำเร็จ (รหัส: {$newTeacherId})",
                'teacher_id' => $newTeacherId
            ]);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถเพิ่มอาจารย์/เจ้าหน้าที่ได้']);
        }
    } catch (Exception $e) {
        if (isset($db)) $db->rollback();
        error_log("Add teacher error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการเพิ่มอาจารย์']);
    }
}

function handleEditTeacher($db, $data)
{
    try {
        // Validation
        if (empty($data['teacher_id']) || empty($data['teacher_name'])) {
            echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
            return;
        }

        $teacherId = intval($data['teacher_id']);
        $teacherName = trim($data['teacher_name']);
        $teacherPosition = trim($data['teacher_position'] ?? '');
        $unitId = !empty($data['unit_id']) ? intval($data['unit_id']) : null;
        $teacherTel = trim($data['teacher_tel'] ?? '');
        $teacherEmail = trim($data['teacher_email'] ?? '');
        $permission = intval($data['permission'] ?? 0);

        // ตรวจสอบว่าอาจารย์มีอยู่จริง
        $existingTeacher = $db->fetchOne("SELECT * FROM teacher WHERE Aj_id = ?", [$teacherId]);
        if (!$existingTeacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์/เจ้าหน้าที่']);
            return;
        }

        // ตรวจสอบ permission level
        $currentUserPermission = $_SESSION['permission'] ?? 0;
        if ($permission > $currentUserPermission) {
            echo json_encode(['success' => false, 'message' => 'คุณไม่สามารถให้สิทธิ์สูงกว่าระดับของคุณได้']);
            return;
        }

        // ตรวจสอบอีเมล (ถ้ามี)
        if ($teacherEmail && !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'รูปแบบอีเมลไม่ถูกต้อง']);
            return;
        }

        // ตรวจสอบอีเมลซ้ำ (ยกเว้นอีเมลของตัวเอง)
        if ($teacherEmail) {
            $existingEmail = $db->fetchOne("SELECT Aj_id FROM teacher WHERE Aj_email = ? AND Aj_id != ?", [$teacherEmail, $teacherId]);
            if ($existingEmail) {
                echo json_encode(['success' => false, 'message' => 'อีเมลนี้ถูกใช้แล้ว']);
                return;
            }
        }

        // เริ่ม transaction
        $db->beginTransaction();

        $sql = "UPDATE teacher SET 
                    Aj_name = ?, 
                    Aj_position = ?, 
                    Unit_id = ?, 
                    Aj_tel = ?, 
                    Aj_email = ?, 
                    Aj_per = ?,
                    updated_at = NOW()
                WHERE Aj_id = ?";

        $params = [
            $teacherName,
            $teacherPosition ?: null,
            $unitId,
            $teacherTel ?: null,
            $teacherEmail ?: null,
            $permission,
            $teacherId
        ];

        // อัปเดตรหัสผ่าน (ถ้ามี)
        if (!empty($data['teacher_password'])) {
            $newPassword = trim($data['teacher_password']);
            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร']);
                return;
            }

$sql = "UPDATE teacher SET 
                        Aj_name = ?, 
                        Aj_password = ?,
                        Aj_position = ?, 
                        Unit_id = ?, 
                        Aj_tel = ?, 
                        Aj_email = ?, 
                        Aj_per = ?,
                        updated_at = NOW()
                    WHERE Aj_id = ?";

            $params = [
                $teacherName,
                $newPassword,
                $teacherPosition ?: null,
                $unitId,
                $teacherTel ?: null,
                $teacherEmail ?: null,
                $permission,
                $teacherId
            ];
        }

        $result = $db->execute($sql, $params);

        if ($result) {
            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'อัปเดตข้อมูลอาจารย์/เจ้าหน้าที่สำเร็จ'
            ]);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถอัปเดตข้อมูลได้']);
        }
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Edit teacher error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการแก้ไขข้อมูล']);
    }
}

function handleDeleteTeacher($db, $data)
{
    try {
        if (empty($data['teacher_id']) || $data['confirm_delete'] !== 'DELETE') {
            echo json_encode(['success' => false, 'message' => 'ข้อมูลการยืนยันไม่ถูกต้อง']);
            return;
        }

        $teacherId = intval($data['teacher_id']);

        // ตรวจสอบว่าอาจารย์มีอยู่จริง
        $existingTeacher = $db->fetchOne("SELECT * FROM teacher WHERE Aj_id = ?", [$teacherId]);
        if (!$existingTeacher) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลอาจารย์/เจ้าหน้าที่']);
            return;
        }

        // ป้องกันการลบตัวเอง
        $currentUserId = $_SESSION['user_id'] ?? 0;
        if ($teacherId == $currentUserId) {
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถลบบัญชีของตัวเองได้']);
            return;
        }

        // เริ่ม transaction
        $db->beginTransaction();

        // ลบอาจารย์
        $result = $db->execute("DELETE FROM teacher WHERE Aj_id = ?", [$teacherId]);

        if ($result) {
            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'ลบอาจารย์/เจ้าหน้าที่สำเร็จ'
            ]);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'ไม่สามารถลบอาจารย์/เจ้าหน้าที่ได้']);
        }
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        error_log("Delete teacher error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการลบอาจารย์']);
    }
}

function getFieldLabel($field)
{
    $labels = [
        'teacher_id'       => 'รหัสอาจารย์/Username',
        'teacher_name'     => 'ชื่อ-นามสกุล',
        'teacher_password' => 'รหัสผ่าน',
        'permission'       => 'สิทธิ์การใช้งาน'
    ];

    return $labels[$field] ?? $field;
}