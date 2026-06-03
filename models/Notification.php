<?php
// includes/notifications.php - ระบบการแจ้งเตือนครบครันสำหรับระบบข้อร้องเรียน
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * สร้างการแจ้งเตือนใหม่
 */
if (!function_exists('createNotification')) {
function createNotification($title, $message, $requestId = null, $studentId = null, $teacherId = null, $createdBy = null, $type = 'system')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $notificationData = [
            'Noti_title' => $title,
            'Noti_message' => $message,
            'Noti_type' => $type,
            'Noti_status' => 0, // ยังไม่อ่าน
            'Noti_date' => date('Y-m-d H:i:s'),
            'Re_id' => $requestId,
            'Stu_id' => $studentId,
            'Aj_id' => $teacherId,
            'created_by' => $createdBy
        ];

        $result = $db->insert('notification', $notificationData);

        // Log การสร้างการแจ้งเตือน
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Created notification: {$title} for " . ($studentId ? "student {$studentId}" : "teacher {$teacherId}"));
        }

        return $result;
    } catch (Exception $e) {
        error_log("createNotification error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ดึงการแจ้งเตือนของผู้ใช้
 */
if (!function_exists('getUserNotifications')) {
function getUserNotifications($userId, $userType = 'student', $limit = 20, $offset = 0)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $sql = "SELECT n.*, r.Re_infor, r.Re_level, t.Type_infor, t.Type_icon
                FROM notification n
                LEFT JOIN request r ON n.Re_id = r.Re_id
                LEFT JOIN type t ON r.Type_id = t.Type_id
                WHERE n.{$field} = ?
                ORDER BY n.Noti_date DESC, n.Noti_status ASC
                LIMIT ? OFFSET ?";

        $notifications = $db->fetchAll($sql, [$userId, $limit, $offset]);

        // เพิ่มข้อมูลเวลาที่ผ่านมา
        foreach ($notifications as &$notification) {
            $notification['time_ago'] = getTimeAgo($notification['Noti_date']);
            $notification['is_urgent'] = ($notification['Re_level'] ?? '1') >= '3';
        }

        return $notifications;
    } catch (Exception $e) {
        error_log("getUserNotifications error: " . $e->getMessage());
        return [];
    }
}
}

/**
 * นับจำนวนการแจ้งเตือนที่ยังไม่อ่าน
 */
if (!function_exists('getUnreadNotificationCount')) {
function getUnreadNotificationCount($userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return 0;

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';
        return $db->count('notification', $field . ' = ? AND Noti_status = 0', [$userId]);
    } catch (Exception $e) {
        error_log("getUnreadNotificationCount error: " . $e->getMessage());
        return 0;
    }
}
}

/**
 * ทำเครื่องหมายการแจ้งเตือนว่าอ่านแล้ว
 */
if (!function_exists('markNotificationAsRead')) {
function markNotificationAsRead($notificationId, $userId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->execute("
            UPDATE notification 
            SET Noti_status = 1 
            WHERE Noti_id = ? AND (Stu_id = ? OR Aj_id = ?)
        ", [$notificationId, $userId, $userId]) !== false;
    } catch (Exception $e) {
        error_log("markNotificationAsRead error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ทำเครื่องหมายการแจ้งเตือนทั้งหมดว่าอ่านแล้ว
 */
if (!function_exists('markAllNotificationsAsRead')) {
function markAllNotificationsAsRead($userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';
        return $db->execute("
            UPDATE notification 
            SET Noti_status = 1 
            WHERE {$field} = ? AND Noti_status = 0
        ", [$userId]) !== false;
    } catch (Exception $e) {
        error_log("markAllNotificationsAsRead error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ลบการแจ้งเตือน
 */
if (!function_exists('deleteNotification')) {
function deleteNotification($notificationId, $userId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->execute("
            DELETE FROM notification 
            WHERE Noti_id = ? AND (Stu_id = ? OR Aj_id = ?)
        ", [$notificationId, $userId, $userId]) !== false;
    } catch (Exception $e) {
        error_log("deleteNotification error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ส่งการแจ้งเตือนอัตโนมัติตามเหตุการณ์
 */
if (!function_exists('sendAutoNotification')) {
function sendAutoNotification($event, $requestId, $recipientId = null, $recipientType = 'student', $createdBy = null, $additionalData = [])
{
    $templates = [
        'request_received' => [
            'title' => '📨 ได้รับข้อร้องเรียนของคุณแล้ว',
            'message' => 'ระบบได้รับข้อร้องเรียนของคุณแล้ว เจ้าหน้าที่จะดำเนินการตรวจสอบและติดต่อกลับภายใน 72 ชั่วโมง'
        ],
        'request_confirmed' => [
            'title' => '✅ ข้อร้องเรียนได้รับการยืนยันแล้ว',
            'message' => 'เจ้าหน้าที่ได้ยืนยันข้อร้องเรียน #{requestId} แล้ว และกำลังดำเนินการแก้ไข'
        ],
        'request_processing' => [
            'title' => '🔄 ข้อร้องเรียนกำลังดำเนินการ',
            'message' => 'เจ้าหน้าที่กำลังดำเนินการแก้ไขข้อร้องเรียน #{requestId} ของคุณ'
        ],
        'request_completed' => [
            'title' => '✅ ข้อร้องเรียนเสร็จสิ้นแล้ว',
            'message' => 'ข้อร้องเรียน #{requestId} ได้รับการแก้ไขเสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจ'
        ],
        'evaluation_request' => [
            'title' => '⭐ กรุณาประเมินความพึงพอใจ',
            'message' => 'ข้อร้องเรียน #{requestId} เสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจในการให้บริการ'
        ],
        'new_request_staff' => [
            'title' => '🔔 มีข้อร้องเรียนใหม่',
            'message' => 'มีข้อร้องเรียนใหม่ #{requestId} ที่ต้องการการตรวจสอบ'
        ],
        'urgent_request' => [
            'title' => '🚨 ข้อร้องเรียนเร่งด่วน',
            'message' => 'มีข้อร้องเรียนเร่งด่วน #{requestId} ที่ต้องดำเนินการทันที'
        ],
        'evaluation_received' => [
            'title' => '⭐ ได้รับการประเมินจากนักศึกษา',
            'message' => 'ข้อร้องเรียน #{requestId} ได้รับการประเมินความพึงพอใจแล้ว'
        ],
        'overdue_reminder' => [
            'title' => '⏰ เตือนความจำ: ข้อร้องเรียนค้างตอบ',
            'message' => 'ข้อร้องเรียน #{requestId} ยังไม่ได้รับการตอบกลับเกิน 48 ชั่วโมง'
        ],
        'assignment_notification' => [
            'title' => '👤 ได้รับมอบหมายข้อร้องเรียนใหม่',
            'message' => 'คุณได้รับมอบหมายให้ดูแลข้อร้องเรียน #{requestId}'
        ]
    ];

    if (!isset($templates[$event])) {
        error_log("sendAutoNotification: Unknown event type: {$event}");
        return false;
    }

    $template = $templates[$event];
    $title = str_replace('{requestId}', $requestId, $template['title']);
    $message = str_replace('{requestId}', $requestId, $template['message']);

    // แทนที่ข้อมูลเพิ่มเติม
    foreach ($additionalData as $key => $value) {
        $title = str_replace('{' . $key . '}', $value, $title);
        $message = str_replace('{' . $key . '}', $value, $message);
    }

    $studentId = ($recipientType === 'student') ? $recipientId : null;
    $teacherId = ($recipientType === 'teacher') ? $recipientId : null;

    return createNotification($title, $message, $requestId, $studentId, $teacherId, $createdBy);
}
}

/**
 * ส่งการแจ้งเตือนให้เจ้าหน้าที่ทั้งหมด (สำหรับข้อร้องเรียนใหม่)
 */
if (!function_exists('notifyAllStaff')) {
function notifyAllStaff($title, $message, $requestId, $createdBy = null, $excludeStaffId = null)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $sql = "SELECT Aj_id FROM teacher WHERE Aj_status = 1";
        $params = [];

        if ($excludeStaffId) {
            $sql .= " AND Aj_id != ?";
            $params[] = $excludeStaffId;
        }

        $staff = $db->fetchAll($sql, $params);
        $count = 0;

        foreach ($staff as $member) {
            if (createNotification($title, $message, $requestId, null, $member['Aj_id'], $createdBy)) {
                $count++;
            }
        }

        return $count;
    } catch (Exception $e) {
        error_log("notifyAllStaff error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ดึงสถิติการแจ้งเตือน
 */
if (!function_exists('getNotificationStats')) {
function getNotificationStats($userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return ['total' => 0, 'unread' => 0, 'today' => 0, 'week' => 0];

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $total = $db->count('notification', $field . ' = ?', [$userId]);
        $unread = $db->count('notification', $field . ' = ? AND Noti_status = 0', [$userId]);
        $today = $db->count('notification', $field . ' = ? AND DATE(Noti_date) = CURDATE()', [$userId]);
        $week = $db->count('notification', $field . ' = ? AND Noti_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)', [$userId]);

        return [
            'total' => $total,
            'unread' => $unread,
            'today' => $today,
            'week' => $week
        ];
    } catch (Exception $e) {
        error_log("getNotificationStats error: " . $e->getMessage());
        return ['total' => 0, 'unread' => 0, 'today' => 0, 'week' => 0];
    }
}
}

/**
 * คำนวณเวลาที่ผ่านมา
 */
if (!function_exists('getTimeAgo')) {
function getTimeAgo($datetime)
{
    $time = time() - strtotime($datetime);

    if ($time < 60) {
        return 'เมื่อสักครู่';
    } elseif ($time < 3600) {
        $minutes = floor($time / 60);
        return $minutes . ' นาทีที่แล้ว';
    } elseif ($time < 86400) {
        $hours = floor($time / 3600);
        return $hours . ' ชั่วโมงที่แล้ว';
    } elseif ($time < 2592000) {
        $days = floor($time / 86400);
        return $days . ' วันที่แล้ว';
    } elseif ($time < 31536000) {
        $months = floor($time / 2592000);
        return $months . ' เดือนที่แล้ว';
    } else {
        $years = floor($time / 31536000);
        return $years . ' ปีที่แล้ว';
    }
}
}

/**
 * ลบการแจ้งเตือนเก่า (สำหรับ maintenance)
 */
if (!function_exists('cleanupOldNotifications')) {
function cleanupOldNotifications($days = 30)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $db->execute("
            DELETE FROM notification 
            WHERE Noti_date < ? AND Noti_status = 1
        ", [$cutoffDate]);

        $deletedCount = $db->getLastAffectedRows();

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Cleaned up {$deletedCount} old notifications");
        }

        return $deletedCount;
    } catch (Exception $e) {
        error_log("cleanupOldNotifications error: " . $e->getMessage());
        return false;
    }
}
}

/**
 * ตรวจสอบและส่งการแจ้งเตือนค้างส่ง (สำหรับ cron job)
 */
if (!function_exists('processPendingNotifications')) {
function processPendingNotifications()
{
    $db = getDB();
    if (!$db) return 0;

    try {
        // ตรวจสอบข้อร้องเรียนที่ค้างการตอบกลับเกิน 48 ชั่วโมง
        $overdueRequests = $db->fetchAll("
            SELECT r.*, s.Stu_name, s.Stu_email
            FROM request r
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            WHERE r.Re_status = '0' 
              AND r.Re_date < DATE_SUB(NOW(), INTERVAL 48 HOUR)
              AND r.Re_is_spam = 0
              AND NOT EXISTS (
                  SELECT 1 FROM notification n 
                  WHERE n.Re_id = r.Re_id 
                    AND n.Noti_title LIKE '%เตือนความจำ%'
                    AND n.Noti_date > DATE_SUB(NOW(), INTERVAL 24 HOUR)
              )
        ");

        $notificationCount = 0;

        foreach ($overdueRequests as $request) {
            // ส่งการแจ้งเตือนให้เจ้าหน้าที่
            if (notifyAllStaff(
                '⏰ เตือนความจำ: ข้อร้องเรียนค้างตอบ',
                'ข้อร้องเรียน #' . $request['Re_id'] . ' ยังไม่ได้รับการตอบกลับเกิน 48 ชั่วโมง',
                $request['Re_id']
            )) {
                $notificationCount++;
            }

            // ส่งการแจ้งเตือนให้นักศึกษา
            if ($request['Stu_id']) {
                sendAutoNotification(
                    'overdue_reminder',
                    $request['Re_id'],
                    $request['Stu_id'],
                    'student'
                );
            }
        }

        // ตรวจสอบข้อร้องเรียนเร่งด่วนที่ยังไม่ได้รับการแจ้งเตือน
        $urgentRequests = $db->fetchAll("
            SELECT r.*
            FROM request r
            WHERE r.Re_level IN ('4', '5')
              AND r.Re_status IN ('0', '1')
              AND r.Re_is_spam = 0
              AND r.Re_date > DATE_SUB(NOW(), INTERVAL 1 HOUR)
              AND NOT EXISTS (
                  SELECT 1 FROM notification n 
                  WHERE n.Re_id = r.Re_id 
                    AND n.Noti_title LIKE '%เร่งด่วน%'
                    AND n.created_by IS NULL
              )
        ");

        foreach ($urgentRequests as $request) {
            if (notifyAllStaff(
                '🚨 ข้อร้องเรียนเร่งด่วนที่ต้องดำเนินการทันที',
                'ข้อร้องเรียน #' . $request['Re_id'] . ' เป็นเรื่องเร่งด่วนระดับ ' . $request['Re_level'],
                $request['Re_id']
            )) {
                $notificationCount++;
            }
        }

        return $notificationCount;
    } catch (Exception $e) {
        error_log("processPendingNotifications error: " . $e->getMessage());
        return 0;
    }
}
}

/**
 * ส่งอีเมลแจ้งเตือน (สำหรับอนาคต)
 */
if (!function_exists('sendEmailNotification')) {
function sendEmailNotification($to, $subject, $message, $requestId = null)
{
    // TODO: Implement email sending functionality
    // ใช้ PHPMailer หรือ Swift Mailer

    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log("Email notification would be sent to: {$to}, Subject: {$subject}");
    }

    return true; // จำลองการส่งสำเร็จ
}
}



// ======================================
// Helper Functions สำหรับการใช้งานทั่วไป
// ======================================

/**
 * สร้างการแจ้งเตือนเมื่อมีข้อร้องเรียนใหม่
 */
if (!function_exists('onNewComplaint')) {
function onNewComplaint($requestId, $studentId, $requestData = [])
{
    // แจ้งเตือนนักศึกษาว่าได้รับเรื่องแล้ว
    sendAutoNotification('request_received', $requestId, $studentId, 'student');

    // แจ้งเตือนเจ้าหน้าที่
    $isUrgent = ($requestData['Re_level'] ?? '1') >= '3';
    $event = $isUrgent ? 'urgent_request' : 'new_request_staff';

    notifyAllStaff(
        $isUrgent ? '🚨 ข้อร้องเรียนเร่งด่วน' : '🔔 มีข้อร้องเรียนใหม่',
        'มีข้อร้องเรียนใหม่ #' . $requestId . ($isUrgent ? ' (เร่งด่วน)' : '') . ' ที่ต้องการการตรวจสอบ',
        $requestId
    );
}
}

/**
 * สร้างการแจ้งเตือนเมื่อยืนยันข้อร้องเรียน
 */
if (!function_exists('onComplaintConfirmed')) {
function onComplaintConfirmed($requestId, $studentId, $staffId)
{
    sendAutoNotification('request_confirmed', $requestId, $studentId, 'student', $staffId);
}
}

/**
 * สร้างการแจ้งเตือนเมื่อเสร็จสิ้นข้อร้องเรียน
 */
if (!function_exists('onComplaintCompleted')) {
function onComplaintCompleted($requestId, $studentId, $staffId)
{
    sendAutoNotification('request_completed', $requestId, $studentId, 'student', $staffId);

    // ส่งการแจ้งเตือนให้ประเมินหลังจาก 1 ชั่วโมง (สามารถใช้ cron job)
    sendAutoNotification('evaluation_request', $requestId, $studentId, 'student', $staffId);
}
}

/**
 * สร้างการแจ้งเตือนเมื่อได้รับการประเมิน
 */
if (!function_exists('onEvaluationReceived')) {
function onEvaluationReceived($requestId, $studentId, $score, $staffId = null)
{
    if ($staffId) {
        sendAutoNotification('evaluation_received', $requestId, $staffId, 'teacher', null, [
            'score' => $score
        ]);
    } else {
        // แจ้งเตือนเจ้าหน้าที่ทั้งหมด
        notifyAllStaff(
            '⭐ ได้รับการประเมินจากนักศึกษา',
            'ข้อร้องเรียน #' . $requestId . ' ได้รับการประเมินความพึงพอใจ (คะแนน: ' . $score . '/5)',
            $requestId
        );
    }
}
}

/**
 * สร้างการแจ้งเตือนเมื่อมอบหมายงาน
 */
if (!function_exists('onComplaintAssigned')) {
function onComplaintAssigned($requestId, $assignedStaffId, $assignedBy)
{
    sendAutoNotification('assignment_notification', $requestId, $assignedStaffId, 'teacher', $assignedBy);
}
}

// ======================================
// Auto-load สำหรับการใช้งานใน Request Handlers
// ======================================

/**
 * เรียกใช้งานอัตโนมัติตามเหตุการณ์ที่เกิดขึ้น
 */
if (function_exists('handleNotificationEvents')) {
    // สำหรับเรียกใช้ใน request handlers ต่างๆ
    handleNotificationEvents();
}
