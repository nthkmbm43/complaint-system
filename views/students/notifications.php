<?php
// notifications.php - ระบบการแจ้งเตือนแบบ Facebook
include '../includes/config.php';
include '../../core/functions.php';
include '../../models/Auth.php';

// ตรวจสอบการเข้าสู่ระบบ
requireLogin();

header('Content-Type: application/json; charset=utf-8');

// ฟังก์ชันสำหรับสร้างการแจ้งเตือน
function createNotificationSystem($title, $message, $requestId = null, $studentId = null, $teacherId = null, $createdBy = null)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $notificationData = [
            'Noti_title' => $title,
            'Noti_message' => $message,
            'Noti_type' => 'system',
            'Noti_status' => 0, // ยังไม่อ่าน
            'Noti_date' => date('Y-m-d H:i:s'),
            'Re_id' => $requestId,
            'Stu_id' => $studentId,
            'Aj_id' => $teacherId,
            'created_by' => $createdBy
        ];

        return $db->insert('notification', $notificationData);
    } catch (Exception $e) {
        error_log("createNotificationSystem error: " . $e->getMessage());
        return false;
    }
}

// ดึงการแจ้งเตือนของผู้ใช้
function getUserNotifications($userId, $userRole = 'student', $limit = 20)
{
    $db = getDB();
    if (!$db) return [];

    try {
        if ($userRole === 'student') {
            $sql = "SELECT n.*, r.Re_title, r.Re_infor, t.Type_infor, t.Type_icon
                    FROM notification n
                    LEFT JOIN request r ON n.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    WHERE n.Stu_id = ?
                    ORDER BY n.Noti_date DESC
                    LIMIT ?";
            $params = [$userId, $limit];
        } else {
            $sql = "SELECT n.*, r.Re_title, r.Re_infor, t.Type_infor, t.Type_icon, s.Stu_name
                    FROM notification n
                    LEFT JOIN request r ON n.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN student s ON r.Stu_id = s.Stu_id
                    WHERE n.Aj_id = ?
                    ORDER BY n.Noti_date DESC
                    LIMIT ?";
            $params = [$userId, $limit];
        }

        return $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        error_log("getUserNotifications error: " . $e->getMessage());
        return [];
    }
}

// นับจำนวนการแจ้งเตือนที่ยังไม่อ่าน
function getUnreadNotificationCount($userId, $userRole = 'student')
{
    $db = getDB();
    if (!$db) return 0;

    try {
        if ($userRole === 'student') {
            $sql = "SELECT COUNT(*) as count FROM notification WHERE Stu_id = ? AND Noti_status = 0";
        } else {
            $sql = "SELECT COUNT(*) as count FROM notification WHERE Aj_id = ? AND Noti_status = 0";
        }

        $result = $db->fetch($sql, [$userId]);
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("getUnreadNotificationCount error: " . $e->getMessage());
        return 0;
    }
}

// มาร์คการแจ้งเตือนว่าอ่านแล้ว
function markNotificationAsRead($notificationId, $userId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->update(
            'notification',
            ['Noti_status' => 1],
            'Noti_id = ? AND (Stu_id = ? OR Aj_id = ?)',
            [$notificationId, $userId, $userId]
        );
    } catch (Exception $e) {
        error_log("markNotificationAsRead error: " . $e->getMessage());
        return false;
    }
}

// มาร์คการแจ้งเตือนทั้งหมดว่าอ่านแล้ว
function markAllNotificationsAsRead($userId, $userRole = 'student')
{
    $db = getDB();
    if (!$db) return false;

    try {
        if ($userRole === 'student') {
            return $db->update('notification', ['Noti_status' => 1], 'Stu_id = ?', [$userId]);
        } else {
            return $db->update('notification', ['Noti_status' => 1], 'Aj_id = ?', [$userId]);
        }
    } catch (Exception $e) {
        error_log("markAllNotificationsAsRead error: " . $e->getMessage());
        return false;
    }
}

// ลบการแจ้งเตือน
function deleteNotification($notificationId, $userId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->delete(
            'notification',
            'Noti_id = ? AND (Stu_id = ? OR Aj_id = ?)',
            [$notificationId, $userId, $userId]
        );
    } catch (Exception $e) {
        error_log("deleteNotification error: " . $e->getMessage());
        return false;
    }
}

// จัดการ AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_REQUEST['action'] ?? '';
    $userId = $_SESSION['user_id'] ?? '';
    $userRole = $_SESSION['user_role'] ?? 'student';

    switch ($action) {
        case 'get_notifications':
            $limit = intval($_REQUEST['limit'] ?? 20);
            $notifications = getUserNotifications($userId, $userRole, $limit);
            $unreadCount = getUnreadNotificationCount($userId, $userRole);

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => $unreadCount
            ]);
            break;

        case 'get_unread_count':
            $count = getUnreadNotificationCount($userId, $userRole);
            echo json_encode([
                'success' => true,
                'unread_count' => $count
            ]);
            break;

        case 'mark_as_read':
            $notificationId = intval($_REQUEST['notification_id'] ?? 0);
            $result = markNotificationAsRead($notificationId, $userId);
            echo json_encode([
                'success' => $result
            ]);
            break;

        case 'mark_all_as_read':
            $result = markAllNotificationsAsRead($userId, $userRole);
            echo json_encode([
                'success' => $result
            ]);
            break;

        case 'delete_notification':
            $notificationId = intval($_REQUEST['notification_id'] ?? 0);
            $result = deleteNotification($notificationId, $userId);
            echo json_encode([
                'success' => $result
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}

// ฟังก์ชันสำหรับสร้างการแจ้งเตือนอัตโนมัติเมื่อมีการเปลี่ยนแปลงสถานะ
function autoCreateNotifications()
{
    // สำหรับการแจ้งเตือนอัตโนมัติเมื่อมีการเปลี่ยนแปลง

    // 1. เมื่อมีข้อร้องเรียนใหม่
    function notifyNewComplaint($requestId, $studentId)
    {
        // แจ้งเตือนเจ้าหน้าที่
        createNotificationSystem(
            '📝 มีข้อร้องเรียนใหม่',
            'มีข้อร้องเรียนใหม่ที่ต้องการการตรวจสอบ',
            $requestId,
            null, // ไม่ใช่การแจ้งเตือนให้นักศึกษา
            1, // แจ้งเตือนให้เจ้าหน้าที่ที่มี ID = 1 (หรือตามระบบของคุณ)
            null
        );

        // แจ้งเตือนนักศึกษาว่าได้รับเรื่องแล้ว
        createNotificationSystem(
            '✅ ได้รับข้อร้องเรียนของคุณแล้ว',
            'ระบบได้รับข้อร้องเรียนของคุณแล้ว เจ้าหน้าที่จะตรวจสอบและติดต่อกลับภายใน 72 ชั่วโมง',
            $requestId,
            $studentId,
            null,
            null
        );
    }

    // 2. เมื่อยืนยันข้อร้องเรียน
    function notifyComplaintConfirmed($requestId, $studentId, $teacherId)
    {
        createNotificationSystem(
            '🔍 ข้อร้องเรียนของคุณได้รับการยืนยันแล้ว',
            'เจ้าหน้าที่ได้ยืนยันข้อร้องเรียนของคุณแล้ว และกำลังดำเนินการแก้ไข',
            $requestId,
            $studentId,
            null,
            $teacherId
        );
    }

    // 3. เมื่อดำเนินการเสร็จสิ้น
    function notifyComplaintCompleted($requestId, $studentId, $teacherId)
    {
        createNotificationSystem(
            '✅ ข้อร้องเรียนของคุณเสร็จสิ้นแล้ว',
            'ข้อร้องเรียนของคุณได้รับการแก้ไขเสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจ',
            $requestId,
            $studentId,
            null,
            $teacherId
        );
    }

    // 4. เมื่อมีการประเมิน
    function notifyEvaluationReceived($requestId, $studentId, $teacherId)
    {
        createNotificationSystem(
            '⭐ ได้รับการประเมินจากนักศึกษา',
            'นักศึกษาได้ประเมินความพึงพอใจสำหรับข้อร้องเรียนที่ดำเนินการแล้ว',
            $requestId,
            null,
            $teacherId,
            null
        );
    }
}
