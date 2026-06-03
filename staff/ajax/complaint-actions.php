<?php
// AJAX handler สำหรับการจัดการข้อร้องเรียน
header('Content-Type: application/json; charset=utf-8');

session_start();
define('SECURE_ACCESS', true);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

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

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'send_reply':
            handleSendReply();
            break;

        case 'update_status':
            handleUpdateStatus();
            break;

        case 'assign_complaint':
            handleAssignComplaint();
            break;

        case 'update_priority':
            handleUpdatePriority();
            break;

        case 'get_complaint_stats':
            handleGetComplaintStats();
            break;

        case 'bulk_assign':
            handleBulkAssign();
            break;

        case 'bulk_update_priority':
            handleBulkUpdatePriority();
            break;

        case 'bulk_update_status':
            handleBulkUpdateStatus();
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

function handleSendReply()
{
    global $db, $user;

    $complaintId = $_POST['complaint_id'] ?? 0;
    $message = trim($_POST['message'] ?? '');

    if (!$complaintId || !$message) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    // ตรวจสอบสิทธิ์ในการตอบกลับ
    $complaint = $db->fetch("SELECT id, status, assigned_to FROM complaints WHERE id = ?", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ตรวจสอบสิทธิ์ - admin หรือผู้ได้รับมอบหมายเท่านั้น
    if (!hasRole('admin') && $complaint['assigned_to'] != $user['id']) {
        throw new Exception('คุณไม่มีสิทธิ์ตอบกลับข้อร้องเรียนนี้');
    }

    if (in_array($complaint['status'], ['completed', 'rejected', 'cancelled'])) {
        throw new Exception('ไม่สามารถตอบกลับข้อร้องเรียนที่เสร็จสิ้นแล้วได้');
    }

    $db->beginTransaction();

    try {
        // เพิ่มข้อความตอบกลับ
        $messageData = [
            'complaint_id' => $complaintId,
            'sender_id' => $user['id'],
            'message' => $message,
            'is_staff_reply' => 1,
            'sender_type' => $user['role']
        ];

        $messageId = $db->insert('complaint_messages', $messageData);

        // อัปเดตสถานะเป็น processing หากยังเป็น pending
        if ($complaint['status'] === 'pending') {
            $db->update(
                'complaints',
                ['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$complaintId]
            );
        }

        // ส่งการแจ้งเตือนให้ผู้ร้องเรียน
        $complaintInfo = $db->fetch("SELECT user_id, complaint_id, title FROM complaints WHERE id = ?", [$complaintId]);

        if ($complaintInfo) {
            createNotification(
                $complaintInfo['user_id'],
                'มีข้อความตอบกลับใหม่',
                "เจ้าหน้าที่ได้ตอบกลับข้อร้องเรียน #{$complaintInfo['complaint_id']} เรื่อง \"{$complaintInfo['title']}\"",
                'info'
            );
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'ส่งข้อความเรียบร้อย',
            'message_id' => $messageId
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleUpdateStatus()
{
    global $db, $user;

    $complaintId = $_POST['complaint_id'] ?? 0;
    $newStatus = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (!$complaintId || !$newStatus) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    $validStatuses = ['pending', 'processing', 'waiting_approval', 'completed', 'rejected', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        throw new Exception('สถานะไม่ถูกต้อง');
    }

    // ตรวจสอบสิทธิ์
    $complaint = $db->fetch("SELECT * FROM complaints WHERE id = ?", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ตรวจสอบสิทธิ์ในการอัปเดตสถานะ
    if (!hasRole('admin') && $complaint['assigned_to'] != $user['id']) {
        throw new Exception('คุณไม่มีสิทธิ์อัปเดตสถานะข้อร้องเรียนนี้');
    }

    // ตรวจสอบการเปลี่ยนสถานะพิเศษ
    if ($newStatus === 'waiting_approval' && !hasRole(['admin', 'staff'])) {
        throw new Exception('ไม่มีสิทธิ์ส่งเรื่องรออนุมัติ');
    }

    if ($complaint['status'] === 'waiting_approval' && $newStatus === 'completed' && !hasRole('admin')) {
        throw new Exception('เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถอนุมัติเรื่องรออนุมัติได้');
    }

    $db->beginTransaction();

    try {
        // อัปเดตสถานะ
        $updateData = [
            'status' => $newStatus,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $db->update('complaints', $updateData, 'id = ?', [$complaintId]);

        $statusTexts = [
            'pending' => 'รอดำเนินการ',
            'processing' => 'กำลังดำเนินการ',
            'waiting_approval' => 'ส่งรออนุมัติ',
            'completed' => 'ดำเนินการเสร็จสิ้น',
            'rejected' => 'ปฏิเสธ',
            'cancelled' => 'ยกเลิก'
        ];

        // เพิ่มข้อความแจ้งการเปลี่ยนสถานะ
        if ($note || $newStatus === 'rejected') {
            $systemMessage = "สถานะข้อร้องเรียนเปลี่ยนเป็น: {$statusTexts[$newStatus]}";
            if ($note) {
                $systemMessage .= "\n\nหมายเหตุ: {$note}";
            }

            $messageData = [
                'complaint_id' => $complaintId,
                'sender_id' => $user['id'],
                'message' => $systemMessage,
                'is_staff_reply' => 1,
                'sender_type' => $user['role']
            ];

            $db->insert('complaint_messages', $messageData);
        }

        // ส่งการแจ้งเตือน
        $notificationType = 'info';
        $notificationTitle = 'อัปเดตสถานะข้อร้องเรียน';

        if ($newStatus === 'completed') {
            $notificationType = 'success';
            $notificationTitle = 'ข้อร้องเรียนเสร็จสิ้น';
        } elseif ($newStatus === 'rejected') {
            $notificationType = 'warning';
            $notificationTitle = 'ข้อร้องเรียนถูกปฏิเสธ';
        }

        $notificationMessage = "สถานะข้อร้องเรียน #{$complaint['complaint_id']} เปลี่ยนเป็น: {$statusTexts[$newStatus]}";
        if ($note) {
            $notificationMessage .= "\n\nหมายเหตุ: {$note}";
        }

        createNotification(
            $complaint['user_id'],
            $notificationTitle,
            $notificationMessage,
            $notificationType
        );

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตสถานะเรียบร้อย'
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleAssignComplaint()
{
    global $db, $user;

    $complaintId = $_POST['complaint_id'] ?? 0;
    $staffId = $_POST['staff_id'] ?? 0;

    if (!$complaintId || !$staffId) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    // ตรวจสอบว่าเป็นเจ้าหน้าที่จริง
    $staff = $db->fetch("SELECT id, first_name, last_name FROM users WHERE id = ? AND role IN ('staff', 'admin') AND status = 'active'", [$staffId]);

    if (!$staff) {
        throw new Exception('ไม่พบเจ้าหน้าที่ที่ระบุ');
    }

    // ตรวจสอบข้อร้องเรียน
    $complaint = $db->fetch("SELECT id, complaint_id, assigned_to FROM complaints WHERE id = ?", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ตรวจสอบสิทธิ์ในการมอบหมาย
    if (!hasRole('admin') && $complaint['assigned_to'] && $complaint['assigned_to'] != $user['id']) {
        throw new Exception('ข้อร้องเรียนนี้ถูกมอบหมายให้เจ้าหน้าที่คนอื่นแล้ว');
    }

    $db->beginTransaction();

    try {
        // อัปเดตการมอบหมาย
        $db->update(
            'complaints',
            ['assigned_to' => $staffId, 'updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [$complaintId]
        );

        // เพิ่มข้อความแจ้งการมอบหมาย
        $messageData = [
            'complaint_id' => $complaintId,
            'sender_id' => $user['id'],
            'message' => "มอบหมายข้อร้องเรียนให้ {$staff['first_name']} {$staff['last_name']}",
            'is_staff_reply' => 1,
            'sender_type' => $user['role']
        ];

        $db->insert('complaint_messages', $messageData);

        // ส่งการแจ้งเตือนให้เจ้าหน้าที่ที่ได้รับมอบหมาย
        if ($staffId != $user['id']) {
            createNotification(
                $staffId,
                'ได้รับมอบหมายข้อร้องเรียนใหม่',
                "คุณได้รับมอบหมายข้อร้องเรียน #{$complaint['complaint_id']}",
                'info'
            );
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'มอบหมายงานเรียบร้อย'
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleUpdatePriority()
{
    global $db, $user;

    $complaintId = $_POST['complaint_id'] ?? 0;
    $priority = $_POST['priority'] ?? '';

    if (!$complaintId || !$priority) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    $validPriorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $validPriorities)) {
        throw new Exception('ระดับความสำคัญไม่ถูกต้อง');
    }

    // ตรวจสอบข้อร้องเรียน
    $complaint = $db->fetch("SELECT id, complaint_id, assigned_to, priority FROM complaints WHERE id = ?", [$complaintId]);

    if (!$complaint) {
        throw new Exception('ไม่พบข้อร้องเรียนที่ระบุ');
    }

    // ตรวจสอบสิทธิ์
    if (!hasRole('admin') && $complaint['assigned_to'] && $complaint['assigned_to'] != $user['id']) {
        throw new Exception('คุณไม่มีสิทธิ์แก้ไขความสำคัญของข้อร้องเรียนนี้');
    }

    // อัปเดตความสำคัญ
    $db->update(
        'complaints',
        ['priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')],
        'id = ?',
        [$complaintId]
    );

    // แจ้งเตือนหากเป็น urgent หรือ high
    if (in_array($priority, ['urgent', 'high'])) {
        $priorityTexts = [
            'urgent' => 'เร่งด่วน',
            'high' => 'ความสำคัญสูง'
        ];

        createNotification(
            $complaint['assigned_to'] ?: $user['id'],
            'ข้อร้องเรียนความสำคัญสูง',
            "ข้อร้องเรียน #{$complaint['complaint_id']} ได้รับการจัดให้เป็น{$priorityTexts[$priority]}",
            'warning'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'อัปเดตความสำคัญเรียบร้อย'
    ]);
}

function handleGetComplaintStats()
{
    global $db;

    $stats = [];

    // สถิติพื้นฐาน
    $stats['total'] = $db->count('complaints');
    $stats['pending'] = $db->count('complaints', 'status = ?', ['pending']);
    $stats['processing'] = $db->count('complaints', 'status = ?', ['processing']);
    $stats['completed'] = $db->count('complaints', 'status = ?', ['completed']);
    $stats['today'] = $db->count('complaints', 'DATE(created_at) = CURDATE()');

    // สถิติความสำคัญ
    $stats['urgent'] = $db->count('complaints', 'priority = ? AND status NOT IN (?, ?)', ['urgent', 'completed', 'rejected']);
    $stats['high'] = $db->count('complaints', 'priority = ? AND status NOT IN (?, ?)', ['high', 'completed', 'rejected']);

    // สถิติการประเมิน
    $avgRating = $db->fetch("SELECT AVG(overall_rating) as avg FROM evaluations");
    $stats['avg_rating'] = round($avgRating['avg'] ?? 0, 1);

    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
}

function handleBulkAssign()
{
    global $db, $user;

    $complaintIds = $_POST['complaint_ids'] ?? [];
    $staffId = $_POST['staff_id'] ?? 0;

    if (empty($complaintIds) || !$staffId) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    if (!hasRole('admin')) {
        throw new Exception('เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถมอบหมายงานเป็นกลุ่มได้');
    }

    // ตรวจสอบเจ้าหน้าที่
    $staff = $db->fetch("SELECT id, first_name, last_name FROM users WHERE id = ? AND role IN ('staff', 'admin')", [$staffId]);

    if (!$staff) {
        throw new Exception('ไม่พบเจ้าหน้าที่ที่ระบุ');
    }

    $db->beginTransaction();

    try {
        $updated = 0;
        foreach ($complaintIds as $complaintId) {
            $result = $db->update(
                'complaints',
                ['assigned_to' => $staffId, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$complaintId]
            );

            if ($result) {
                $updated++;
            }
        }

        // แจ้งเตือนเจ้าหน้าที่
        if ($staffId != $user['id'] && $updated > 0) {
            createNotification(
                $staffId,
                'ได้รับมอบหมายข้อร้องเรียนใหม่',
                "คุณได้รับมอบหมายข้อร้องเรียน {$updated} เรื่อง",
                'info'
            );
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "มอบหมายงาน {$updated} เรื่องเรียบร้อย"
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleBulkUpdatePriority()
{
    global $db, $user;

    $complaintIds = $_POST['complaint_ids'] ?? [];
    $priority = $_POST['priority'] ?? '';

    if (empty($complaintIds) || !$priority) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    if (!hasRole('admin')) {
        throw new Exception('เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถอัปเดตความสำคัญเป็นกลุ่มได้');
    }

    $validPriorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $validPriorities)) {
        throw new Exception('ระดับความสำคัญไม่ถูกต้อง');
    }

    $db->beginTransaction();

    try {
        $updated = 0;
        foreach ($complaintIds as $complaintId) {
            $result = $db->update(
                'complaints',
                ['priority' => $priority, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$complaintId]
            );

            if ($result) {
                $updated++;
            }
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "อัปเดตความสำคัญ {$updated} เรื่องเรียบร้อย"
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleBulkUpdateStatus()
{
    global $db, $user;

    $complaintIds = $_POST['complaint_ids'] ?? [];
    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    if (empty($complaintIds) || !$status) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }

    if (!hasRole('admin')) {
        throw new Exception('เฉพาะผู้ดูแลระบบเท่านั้นที่สามารถอัปเดตสถานะเป็นกลุ่มได้');
    }

    $validStatuses = ['pending', 'processing', 'waiting_approval', 'completed', 'rejected', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('สถานะไม่ถูกต้อง');
    }

    $db->beginTransaction();

    try {
        $updated = 0;
        $statusTexts = [
            'pending' => 'รอดำเนินการ',
            'processing' => 'กำลังดำเนินการ',
            'waiting_approval' => 'รออนุมัติ',
            'completed' => 'เสร็จสิ้น',
            'rejected' => 'ปฏิเสธ',
            'cancelled' => 'ยกเลิก'
        ];

        foreach ($complaintIds as $complaintId) {
            // อัปเดตสถานะ
            $result = $db->update(
                'complaints',
                ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                'id = ?',
                [$complaintId]
            );

            if ($result) {
                $updated++;

                // ดึงข้อมูลข้อร้องเรียนเพื่อส่งแจ้งเตือน
                $complaint = $db->fetch("SELECT user_id, complaint_id FROM complaints WHERE id = ?", [$complaintId]);

                if ($complaint) {
                    // เพิ่มข้อความระบบ
                    if ($note) {
                        $systemMessage = "สถานะข้อร้องเรียนเปลี่ยนเป็น: {$statusTexts[$status]}\n\nหมายเหตุ: {$note}";

                        $messageData = [
                            'complaint_id' => $complaintId,
                            'sender_id' => $user['id'],
                            'message' => $systemMessage,
                            'is_staff_reply' => 1,
                            'sender_type' => $user['role']
                        ];

                        $db->insert('complaint_messages', $messageData);
                    }

                    // ส่งการแจ้งเตือน
                    $notificationType = 'info';
                    if ($status === 'completed') {
                        $notificationType = 'success';
                    } elseif ($status === 'rejected') {
                        $notificationType = 'warning';
                    }

                    $notificationMessage = "สถานะข้อร้องเรียน #{$complaint['complaint_id']} เปลี่ยนเป็น: {$statusTexts[$status]}";
                    if ($note) {
                        $notificationMessage .= "\n\nหมายเหตุ: {$note}";
                    }

                    createNotification(
                        $complaint['user_id'],
                        'อัปเดตสถานะข้อร้องเรียน',
                        $notificationMessage,
                        $notificationType
                    );
                }
            }
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => "อัปเดตสถานะ {$updated} เรื่องเรียบร้อย"
        ]);
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

// Helper function สำหรับสร้างการแจ้งเตือน
function createNotification($userId, $title, $message, $type = 'info')
{
    global $db;

    try {
        $data = [
            'user_id' => $userId,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s')
        ];

        return $db->insert('notifications', $data);
    } catch (Exception $e) {
        // Log error but don't fail the main operation
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}
