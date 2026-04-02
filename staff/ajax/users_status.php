<?php
// staff/ajax/users_status.php - จัดการสถานะนักศึกษา (แก้ไข Parameter Issues)
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

if (!$canEditUsers) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'ไม่มีสิทธิ์จัดการสถานะนักศึกษา']);
    exit;
}

// รับข้อมูลจาก request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'toggle_status':
            handleToggleStatus($db, $input);
            break;

        case 'bulk_status':
            handleBulkStatus($db, $input);
            break;

        default:
            throw new Exception('Action ไม่ถูกต้อง');
    }
} catch (Exception $e) {
    error_log("Users status error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function handleToggleStatus($db, $input)
{
    $studentId = trim($input['student_id'] ?? '');
    $newStatus = intval($input['new_status'] ?? -1);
    $suspendReason = trim($input['suspend_reason'] ?? '');

    if (empty($studentId)) {
        throw new Exception('ไม่พบรหัสนักศึกษา');
    }

    if ($newStatus < 0 || $newStatus > 1) {
        throw new Exception('สถานะไม่ถูกต้อง');
    }

    $currentStudent = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$studentId]);
    if (!$currentStudent) {
        throw new Exception('ไม่พบนักศึกษาที่ระบุ');
    }

    // ตรวจสอบเหตุผลเมื่อระงับ
    if ($newStatus == 0 && empty($suspendReason)) {
        throw new Exception('กรุณาระบุเหตุผลในการระงับบัญชี');
    }

    $db->beginTransaction();

    try {
        // อัปเดตสถานะนักศึกษา - ใช้ positional parameters เท่านั้น
        $updateFields = [];
        $params = [];

        $updateFields[] = "Stu_status = ?";
        $params[] = $newStatus;

        if ($newStatus == 0) {
            // ระงับบัญชี
            $updateFields[] = "Stu_suspend_reason = ?";
            $params[] = $suspendReason;

            $updateFields[] = "Stu_suspend_date = ?";
            $params[] = date('Y-m-d');

            $updateFields[] = "Stu_suspend_by = ?";
            $params[] = $_SESSION['user_id'];
        } else {
            // เปิดใช้งาน - อัปเดตประวัติการระงับที่ยังไม่ปลด
            $updateSuspendHistorySql = "UPDATE suspend_history SET 
                Sh_status = 0, 
                Sh_release_date = ?, 
                Sh_release_by = ?, 
                updated_at = NOW()
                WHERE Sh_user_type = 'S' 
                AND Sh_user_id = ? 
                AND Sh_status = 1";

            $db->execute($updateSuspendHistorySql, [date('Y-m-d'), $_SESSION['user_id'], $studentId]);

            // ล้างข้อมูลการระงับในตารางนักศึกษา
            $updateFields[] = "Stu_suspend_reason = ?";
            $params[] = null;

            $updateFields[] = "Stu_suspend_date = ?";
            $params[] = null;

            $updateFields[] = "Stu_suspend_by = ?";
            $params[] = null;
        }

        $updateFields[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');

        // เพิ่ม WHERE parameter
        $params[] = $studentId;

        $sql = "UPDATE student SET " . implode(', ', $updateFields) . " WHERE Stu_id = ?";

        $result = $db->execute($sql, $params);

        if (!$result) {
            throw new Exception('ไม่สามารถอัปเดตสถานะได้');
        }

        // บันทึกประวัติการระงับ (เฉพาะตอนระงับ)
        if ($newStatus == 0) {
            $historySql = "INSERT INTO suspend_history (
                Sh_user_type, 
                Sh_user_id, 
                Sh_reason, 
                Sh_suspend_date, 
                Sh_suspend_by, 
                Sh_status,
                created_at,
                updated_at
            ) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())";

            $historyParams = [
                'S',                    // Sh_user_type = Student
                $studentId,             // Sh_user_id
                $suspendReason,         // Sh_reason
                date('Y-m-d'),         // Sh_suspend_date
                $_SESSION['user_id']    // Sh_suspend_by
            ];

            $historyResult = $db->execute($historySql, $historyParams);

            if (!$historyResult) {
                throw new Exception('ไม่สามารถบันทึกประวัติการระงับได้');
            }
        }

        $db->commit();

        // Log activity
        if (function_exists('logActivity')) {
            $action = $newStatus == 1 ? 'activate_student' : 'suspend_student';
            $message = $newStatus == 1 ? 'เปิดใช้งาน' : 'ระงับ';
            logActivity($_SESSION['user_id'], $action, $message . 'บัญชีนักศึกษา: ' . $currentStudent['Stu_name'] . ' (' . $studentId . ')');
        }

        $statusText = $newStatus == 1 ? 'เปิดใช้งาน' : 'ระงับ';

        echo json_encode([
            'success' => true,
            'message' => $statusText . 'บัญชีนักศึกษาเรียบร้อยแล้ว',
            'new_status' => $newStatus
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleBulkStatus($db, $input)
{
    $studentIds = $input['student_ids'] ?? [];
    $newStatus = intval($input['new_status'] ?? -1);
    $suspendReason = trim($input['suspend_reason'] ?? '');

    if (empty($studentIds) || !is_array($studentIds)) {
        throw new Exception('ไม่พบรายการนักศึกษา');
    }

    if ($newStatus < 0 || $newStatus > 1) {
        throw new Exception('สถานะไม่ถูกต้อง');
    }

    if ($newStatus == 0 && empty($suspendReason)) {
        throw new Exception('กรุณาระบุเหตุผลในการระงับบัญชี');
    }

    $db->beginTransaction();
    $updated = 0;
    $errors = [];

    try {
        foreach ($studentIds as $studentId) {
            $studentId = trim($studentId);
            if (empty($studentId)) continue;

            $currentStudent = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$studentId]);
            if (!$currentStudent) {
                $errors[] = "ไม่พบนักศึกษา: $studentId";
                continue;
            }

            // อัปเดตสถานะ - ใช้ positional parameters เท่านั้น
            $updateFields = [];
            $params = [];

            $updateFields[] = "Stu_status = ?";
            $params[] = $newStatus;

            if ($newStatus == 0) {
                // ระงับบัญชี
                $updateFields[] = "Stu_suspend_reason = ?";
                $params[] = $suspendReason;

                $updateFields[] = "Stu_suspend_date = ?";
                $params[] = date('Y-m-d');

                $updateFields[] = "Stu_suspend_by = ?";
                $params[] = $_SESSION['user_id'];
            } else {
                // เปิดใช้งาน - อัปเดตประวัติการระงับ
                $updateSuspendHistorySql = "UPDATE suspend_history SET 
                    Sh_status = 0, 
                    Sh_release_date = ?, 
                    Sh_release_by = ?, 
                    updated_at = NOW()
                    WHERE Sh_user_type = 'S' 
                    AND Sh_user_id = ? 
                    AND Sh_status = 1";

                $db->execute($updateSuspendHistorySql, [date('Y-m-d'), $_SESSION['user_id'], $studentId]);

                $updateFields[] = "Stu_suspend_reason = ?";
                $params[] = null;

                $updateFields[] = "Stu_suspend_date = ?";
                $params[] = null;

                $updateFields[] = "Stu_suspend_by = ?";
                $params[] = null;
            }

            $updateFields[] = "updated_at = ?";
            $params[] = date('Y-m-d H:i:s');

            $params[] = $studentId;

            $sql = "UPDATE student SET " . implode(', ', $updateFields) . " WHERE Stu_id = ?";

            if ($db->execute($sql, $params)) {
                // บันทึกประวัติการระงับ (เฉพาะตอนระงับ)
                if ($newStatus == 0) {
                    $historySql = "INSERT INTO suspend_history (
                        Sh_user_type, 
                        Sh_user_id, 
                        Sh_reason, 
                        Sh_suspend_date, 
                        Sh_suspend_by, 
                        Sh_status,
                        created_at,
                        updated_at
                    ) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())";

                    $historyParams = [
                        'S',
                        $studentId,
                        $suspendReason,
                        date('Y-m-d'),
                        $_SESSION['user_id']
                    ];

                    $db->execute($historySql, $historyParams);
                }

                $updated++;
            } else {
                $errors[] = "ไม่สามารถอัปเดตนักศึกษา: " . $currentStudent['Stu_name'];
            }
        }

        $db->commit();

        $statusText = $newStatus == 1 ? 'เปิดใช้งาน' : 'ระงับ';
        $message = $statusText . 'บัญชีนักศึกษา ' . $updated . ' รายการเรียบร้อยแล้ว';

        if (!empty($errors)) {
            $message .= ' (มีข้อผิดพลาด: ' . implode(', ', $errors) . ')';
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'updated_count' => $updated,
            'errors' => $errors
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}
