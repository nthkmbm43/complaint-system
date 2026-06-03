<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Notification.php';
if (session_status() === PHP_SESSION_NONE) session_start();


// ตรวจสอบว่าเป็นการเรียกผ่าน AJAX
if (isset($_POST['ajax']) || isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $userId = $_SESSION['user_id'];
    $userType = $_SESSION['user_role'] ?? 'student';

    switch ($action) {
        case 'get_notifications':
            $limit = intval($_POST['limit'] ?? $_GET['limit'] ?? 20);
            $offset = intval($_POST['offset'] ?? $_GET['offset'] ?? 0);

            $notifications = getUserNotifications($userId, $userType, $limit, $offset);
            $stats = getNotificationStats($userId, $userType);

            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'stats' => $stats
            ]);
            break;

        case 'get_unread_count':
            $count = getUnreadNotificationCount($userId, $userType);
            echo json_encode([
                'success' => true,
                'unread_count' => $count
            ]);
            break;

        case 'mark_as_read':
            $notificationId = intval($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
            $result = markNotificationAsRead($notificationId, $userId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ทำเครื่องหมายว่าอ่านแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'mark_all_as_read':
            $result = markAllNotificationsAsRead($userId, $userType);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ทำเครื่องหมายทั้งหมดว่าอ่านแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'delete_notification':
            $notificationId = intval($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
            $result = deleteNotification($notificationId, $userId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'ลบการแจ้งเตือนแล้ว' : 'เกิดข้อผิดพลาด'
            ]);
            break;

        case 'get_stats':
            $stats = getNotificationStats($userId, $userType);
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action'
            ]);
            break;
    }
    exit;
}