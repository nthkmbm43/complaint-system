<?php
// staff/ajax/users_crud.php - จัดการ CRUD operations สำหรับนักศึกษา (แก้ไข Parameter Issues)
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
$canEditUsers = ($userPermission >= 2);

// รับข้อมูลจาก request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

// Action check_duplicate ไม่ต้องการสิทธิ์แก้ไข (เพื่อให้ตรวจสอบได้ก่อน submit)
if ($action === 'check_duplicate') {
    handleCheckDuplicate($db, $input);
    exit;
}

// สำหรับ action อื่นๆ ต้องมีสิทธิ์แก้ไข
if (!$canEditUsers) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์จัดการข้อมูลนักศึกษา']);
    exit;
}

try {
    switch ($action) {
        case 'add_student':
            handleAddStudent($db, $input);
            break;

        case 'edit_student':
            handleEditStudent($db, $input);
            break;

        case 'delete_student':
            handleDeleteStudent($db, $input);
            break;

        default:
            throw new Exception('Action ไม่ถูกต้อง');
    }
} catch (Exception $e) {
    error_log("Users CRUD error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * ตรวจสอบรหัสนักศึกษาหรืออีเมลซ้ำ (Real-time validation)
 */
function handleCheckDuplicate($db, $input)
{
    $field = trim($input['field'] ?? '');
    $value = trim($input['value'] ?? '');
    $excludeId = trim($input['exclude_id'] ?? ''); // ใช้สำหรับการแก้ไข (ไม่นับตัวเอง)

    if (empty($field) || empty($value)) {
        echo json_encode([
            'success' => true,
            'is_duplicate' => false
        ]);
        return;
    }

    $isDuplicate = false;
    $duplicateMessage = '';

    try {
        if ($field === 'student_id') {
            // ตรวจสอบรหัสนักศึกษาซ้ำ
            if (!empty($excludeId)) {
                $existing = $db->fetch("SELECT Stu_id, Stu_name FROM student WHERE Stu_id = ? AND Stu_id != ?", [$value, $excludeId]);
            } else {
                $existing = $db->fetch("SELECT Stu_id, Stu_name FROM student WHERE Stu_id = ?", [$value]);
            }

            if ($existing) {
                $isDuplicate = true;
                $duplicateMessage = 'รหัสนักศึกษา "' . $value . '" มีอยู่ในระบบแล้ว (' . $existing['Stu_name'] . ')';
            }
        } elseif ($field === 'email') {
            // ตรวจสอบอีเมลซ้ำ
            if (!empty($value)) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    echo json_encode([
                        'success' => true,
                        'is_duplicate' => false,
                        'is_invalid' => true,
                        'message' => 'รูปแบบอีเมลไม่ถูกต้อง'
                    ]);
                    return;
                }

                if (!empty($excludeId)) {
                    $existing = $db->fetch("SELECT Stu_id, Stu_name, Stu_email FROM student WHERE Stu_email = ? AND Stu_id != ?", [$value, $excludeId]);
                } else {
                    $existing = $db->fetch("SELECT Stu_id, Stu_name, Stu_email FROM student WHERE Stu_email = ?", [$value]);
                }

                if ($existing) {
                    $isDuplicate = true;
                    $duplicateMessage = 'อีเมล "' . $value . '" มีอยู่ในระบบแล้ว (' . $existing['Stu_name'] . ' - ' . $existing['Stu_id'] . ')';
                }
            }
        }

        echo json_encode([
            'success' => true,
            'is_duplicate' => $isDuplicate,
            'message' => $duplicateMessage
        ]);
    } catch (Exception $e) {
        error_log("Check duplicate error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'is_duplicate' => false,
            'message' => 'เกิดข้อผิดพลาดในการตรวจสอบ'
        ]);
    }
}

function handleAddStudent($db, $input)
{
    // ตรวจสอบข้อมูลจำเป็น
    $studentId = trim($input['student_id'] ?? '');
    $studentName = trim($input['student_name'] ?? '');
    $studentPassword = trim($input['student_password'] ?? '');
    $unitId = intval($input['unit_id'] ?? 0);
    $studentTel = trim($input['student_tel'] ?? '');
    $studentEmail = trim($input['student_email'] ?? '');

    // Validation
    if (empty($studentId) || empty($studentName) || empty($studentPassword)) {
        throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
    }

    if (strlen($studentPassword) < 6) {
        throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
    }

    if (!preg_match('/^[0-9\-]{10,15}$/', $studentId)) {
        throw new Exception('รหัสนักศึกษาไม่ถูกต้อง');
    }

    // ตรวจสอบรหัสนักศึกษาซ้ำ
    $existingStudent = $db->fetch("SELECT Stu_id FROM student WHERE Stu_id = ?", [$studentId]);
    if ($existingStudent) {
        throw new Exception('รหัสนักศึกษานี้มีอยู่ในระบบแล้ว');
    }

    // ตรวจสอบอีเมลซ้ำ
    if (!empty($studentEmail)) {
        if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }

        $existingEmail = $db->fetch("SELECT Stu_id FROM student WHERE Stu_email = ?", [$studentEmail]);
        if ($existingEmail) {
            throw new Exception('อีเมลนี้มีอยู่ในระบบแล้ว');
        }
    }


    $sql = "INSERT INTO student (Stu_id, Stu_name, Stu_password, Unit_id, Stu_tel, Stu_email, Stu_status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $studentId,
        $studentName,
        $studentPassword,               // 1. ใช้รหัสผ่านแบบเห็นตัวเลขตรงๆ (Plain Text)
        ($unitId > 0 ? $unitId : null), // 2. ถ้าไม่ได้เลือกสาขา (ค่าเป็น 0) ให้ส่งค่า null แทน เพื่อไม่ให้ Error
        $studentTel,
        $studentEmail,
        1,
        date('Y-m-d H:i:s')
    ];

    $result = $db->execute($sql, $params);

    if ($result) {
        // Log activity
        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'add_student', 'เพิ่มนักศึกษา: ' . $studentName . ' (' . $studentId . ')');
        }

        echo json_encode([
            'success' => true,
            'message' => 'เพิ่มนักศึกษาเรียบร้อยแล้ว',
            'student_id' => $studentId
        ]);
    } else {
        throw new Exception('ไม่สามารถเพิ่มนักศึกษาได้');
    }
}

function handleEditStudent($db, $input)
{
    $studentId = trim($input['student_id'] ?? '');
    $studentName = trim($input['student_name'] ?? '');
    $unitId = intval($input['unit_id'] ?? 0);
    $studentTel = trim($input['student_tel'] ?? '');
    $studentEmail = trim($input['student_email'] ?? '');
    $studentPassword = trim($input['student_password'] ?? '');

    // ตรวจสอบข้อมูล
    if (empty($studentId) || empty($studentName)) {
        throw new Exception('กรุณากรอกข้อมูลที่จำเป็น');
    }

    $currentStudent = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$studentId]);
    if (!$currentStudent) {
        throw new Exception('ไม่พบนักศึกษาที่ระบุ');
    }

    // ตรวจสอบอีเมลซ้ำ (ยกเว้นตัวเอง)
    if (!empty($studentEmail)) {
        if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }

        $existingEmail = $db->fetch("SELECT Stu_id FROM student WHERE Stu_email = ? AND Stu_id != ?", [$studentEmail, $studentId]);
        if ($existingEmail) {
            throw new Exception('อีเมลนี้มีอยู่ในระบบแล้ว');
        }
    }

    // เตรียม SQL สำหรับ UPDATE - ใช้ positional parameters เท่านั้น
    $updateFields = [];
    $params = [];

    $updateFields[] = "Stu_name = ?";
    $params[] = $studentName;

    $updateFields[] = "Unit_id = ?";
    $params[] = $unitId;

    $updateFields[] = "Stu_tel = ?";
    $params[] = $studentTel;

    $updateFields[] = "Stu_email = ?";
    $params[] = $studentEmail;

    // แก้ไขรหัสผ่าน (ถ้ามี)
    if (!empty($studentPassword)) {
        if (strlen($studentPassword) < 6) {
            throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
        }
        $updateFields[] = "Stu_password = ?";
        $params[] = $studentPassword;
    }

    $updateFields[] = "updated_at = ?";
    $params[] = date('Y-m-d H:i:s');

    // เพิ่ม WHERE parameter
    $params[] = $studentId;

    $sql = "UPDATE student SET " . implode(', ', $updateFields) . " WHERE Stu_id = ?";

    $result = $db->execute($sql, $params);

    if ($result) {
        // Log activity
        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'edit_student', 'แก้ไขข้อมูลนักศึกษา: ' . $studentName . ' (' . $studentId . ')');
        }

        echo json_encode([
            'success' => true,
            'message' => 'แก้ไขข้อมูลนักศึกษาเรียบร้อยแล้ว'
        ]);
    } else {
        throw new Exception('ไม่สามารถแก้ไขข้อมูลได้');
    }
}

function handleDeleteStudent($db, $input)
{
    $studentId = trim($input['student_id'] ?? '');
    $confirmText = trim($input['confirm_delete'] ?? '');

    if (empty($studentId)) {
        throw new Exception('ไม่พบรหัสนักศึกษา');
    }

    if ($confirmText !== 'DELETE') {
        throw new Exception('กรุณาพิมพ์ "DELETE" เพื่อยืนยันการลบ');
    }

    $currentStudent = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$studentId]);
    if (!$currentStudent) {
        throw new Exception('ไม่พบนักศึกษาที่ระบุ');
    }

    // ตรวจสอบว่าไม่มีข้อร้องเรียน
    $requestCount = $db->count('request', 'Stu_id = ?', [$studentId]);
    if ($requestCount > 0) {
        throw new Exception('ไม่สามารถลบได้ เนื่องจากมีข้อร้องเรียนในระบบ');
    }

    $result = $db->execute("DELETE FROM student WHERE Stu_id = ?", [$studentId]);

    if ($result) {
        // Log activity
        if (function_exists('logActivity')) {
            logActivity($_SESSION['user_id'], 'delete_student', 'ลบนักศึกษา: ' . $currentStudent['Stu_name'] . ' (' . $studentId . ')');
        }

        echo json_encode([
            'success' => true,
            'message' => 'ลบนักศึกษาเรียบร้อยแล้ว'
        ]);
    } else {
        throw new Exception('ไม่สามารถลบนักศึกษาได้');
    }
}
