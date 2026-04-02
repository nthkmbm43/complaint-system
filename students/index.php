<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอิน
requireRole('student', 'login.php');

$user = getCurrentUser();
$db = getDB();
$studentId = $user['Stu_id'];

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {
        case 'get_unread_count':
            echo json_encode(['unread_count' => getUnreadNotificationCount($user['Stu_id'], 'student')]);
            exit;
        case 'get_notifications':
            $notifications = getRecentNotifications($user['Stu_id'], 'student', 10);
            echo json_encode(['notifications' => $notifications]);
            exit;
        case 'mark_as_read':
            if (isset($_POST['notification_id'])) {
                $success = markSingleNotificationAsRead($_POST['notification_id'], $user['Stu_id'], 'student');
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
            }
            exit;
        case 'mark_all_as_read':
            try {
                $success = markAllNotificationsAsRead($user['Stu_id'], 'student');
                echo json_encode(['success' => $success]);
            } catch (Exception $e) {
                error_log("mark_all_as_read AJAX error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// ดึงจำนวนการแจ้งเตือน
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// สถิติ Dashboard
$dashboardStats = [
    'total_complaints' => 0,
    'pending_complaints' => 0,
    'processing_complaints' => 0,
    'waiting_eval' => 0,
    'completed_complaints' => 0,
    'avg_rating_given' => 0,
    'this_month_complaints' => 0
];

$recentComplaints = [];
$pendingEvaluations = [];
$dashboardNotifications = [];

try {
    // นับจำนวนตามสถานะ (ลบ Re_is_spam ออกแล้ว)
    $dashboardStats['total_complaints'] = $db->count('request', 'Stu_id = ?', [$studentId]);
    $dashboardStats['pending_complaints'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '0']);
    $dashboardStats['processing_complaints'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '1']);
    $dashboardStats['waiting_eval'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '2']);
    $dashboardStats['completed_complaints'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '3']);
    $dashboardStats['this_month_complaints'] = $db->count('request', 'Stu_id = ? AND YEAR(Re_date) = YEAR(CURDATE()) AND MONTH(Re_date) = MONTH(CURDATE())', [$studentId]);

    // คะแนนเฉลี่ย
    $avgRatingResult = $db->fetch("SELECT AVG(e.Eva_score) as avg_rating FROM evaluation e JOIN request r ON e.Re_id = r.Re_id WHERE r.Stu_id = ? AND e.Eva_score > 0", [$studentId]);
    $dashboardStats['avg_rating_given'] = round($avgRatingResult['avg_rating'] ?? 0, 1);

    // ดึงข้อร้องเรียนล่าสุด 5 รายการ (ลบ Re_is_spam ออกแล้ว)
    $recentComplaints = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon,
               COALESCE(r.Re_title, LEFT(r.Re_infor, 50)) as display_title,
               CASE r.Re_status 
                   WHEN '0' THEN 'ยื่นคำร้อง'
                   WHEN '1' THEN 'กำลังดำเนินการ'
                   WHEN '2' THEN 'รอการประเมินผล'
                   WHEN '3' THEN 'เสร็จสิ้น'
                   WHEN '4' THEN 'ปฏิเสธคำร้อง'
                   ELSE 'ไม่ทราบสถานะ'
               END as status_text,
               CASE r.Re_status
                   WHEN '0' THEN 'secondary' 
                   WHEN '1' THEN 'info'      
                   WHEN '2' THEN 'warning'   
                   WHEN '3' THEN 'success'   
                   WHEN '4' THEN 'danger'    
                   ELSE 'secondary'
               END as status_class,
               CASE r.Re_level
                   WHEN '1' THEN 'ไม่เร่งด่วน'
                   WHEN '2' THEN 'ปกติ'
                   WHEN '3' THEN 'เร่งด่วน'
                   WHEN '4' THEN 'เร่งด่วนมาก'
                   WHEN '5' THEN 'วิกฤต/ฉุกเฉิน'
                   ELSE 'ปกติ'
               END as priority_text,
               CASE r.Re_level
                   WHEN '1' THEN 'priority-low'      
                   WHEN '2' THEN 'priority-normal'   
                   WHEN '3' THEN 'priority-high'     
                   WHEN '4' THEN 'priority-urgent'   
                   WHEN '5' THEN 'priority-critical' 
                   ELSE 'priority-normal'
               END as priority_class
        FROM request r
        LEFT JOIN type t ON r.Type_id = t.Type_id
        WHERE r.Stu_id = ? 
        ORDER BY r.Re_date DESC
        LIMIT 5
    ", [$studentId]);

    // ดึงข้อร้องเรียนที่รอประเมิน (ลบ Re_is_spam ออกแล้ว)
    $pendingEvaluations = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon, COALESCE(r.Re_title, LEFT(r.Re_infor, 50)) as display_title
        FROM request r
        LEFT JOIN type t ON r.Type_id = t.Type_id
        LEFT JOIN evaluation e ON r.Re_id = e.Re_id
        WHERE r.Stu_id = ? AND r.Re_status = '2' AND e.Re_id IS NULL
        ORDER BY r.Re_date ASC LIMIT 3
    ", [$studentId]);

    // ดึงการแจ้งเตือน (ลบ Re_is_spam ออกแล้ว)
    $dashboardNotifications = $db->fetchAll("
        SELECT CONCAT('ข้อร้องเรียน \"', LEFT(COALESCE(r.Re_title, r.Re_infor), 50), '\" ', 
                CASE r.Re_status 
                    WHEN '1' THEN 'กำลังดำเนินการ'
                    WHEN '2' THEN 'รอการประเมินผล'
                    WHEN '3' THEN 'เสร็จสิ้นแล้ว'
                    WHEN '4' THEN 'ถูกปฏิเสธ'
                    ELSE 'มีการอัปเดต'
                END
            ) as Noti_message,
            r.Re_date as created_at,
            CASE r.Re_status
                WHEN '1' THEN '⏳' WHEN '2' THEN '⭐' WHEN '3' THEN '✅' WHEN '4' THEN '❌' ELSE '🔔'
            END as icon, r.Re_status, r.Re_id
        FROM request r
        WHERE r.Stu_id = ? AND r.Re_status != '0'
        ORDER BY r.Re_date DESC LIMIT 5
    ", [$studentId]);
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
}

// คำนวณอัตราความสำเร็จ
$successCount = $dashboardStats['waiting_eval'] + $dashboardStats['completed_complaints'];
$successRate = $dashboardStats['total_complaints'] > 0 ? round(($successCount / $dashboardStats['total_complaints']) * 100, 1) : 0;

// Helper Functions
function truncateText($text, $length = 50)
{
    return mb_strlen($text ?? '', 'UTF-8') > $length ? mb_substr($text, 0, $length, 'UTF-8') . '...' : ($text ?? '');
}
function formatDateThai($date)
{
    return empty($date) ? '-' : date('d/m/Y H:i', strtotime($date));
}
function timeAgo($datetime)
{
    if (empty($datetime)) return '-';
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'เมื่อสักครู่';
    if ($time < 3600) return floor($time / 60) . ' นาทีที่แล้ว';
    if ($time < 86400) return floor($time / 3600) . ' ชั่วโมงที่แล้ว';
    return floor($time / 86400) . ' วันที่แล้ว';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>หน้าหลักนักศึกษา - <?php echo defined('SITE_NAME') ? SITE_NAME : 'ระบบข้อร้องเรียน'; ?></title>
    <style>
        /* General Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding-top: 70px;
        }

        .main-content {
            margin-left: 0;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 70px);
            padding: 20px;
        }

        .main-content.shifted {
            margin-left: 300px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Top Header */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #e1e5e9;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .mobile-menu-toggle {
            display: block;
            background: none;
            border: none;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .mobile-menu-toggle:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .hamburger {
            width: 24px;
            height: 18px;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .hamburger span {
            width: 100%;
            height: 2px;
            background: #333;
            border-radius: 1px;
            transition: all 0.3s ease;
        }

        .header-title h1 {
            font-size: 1.2rem;
            margin: 0;
            color: #333;
        }

        .header-title p {
            margin: 0;
            font-size: 0.85rem;
            color: #666;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        /* Notifications & User Menu */
        .header-notification {
            position: relative;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .header-notification:hover {
            background: #e9ecef;
            transform: scale(1.05);
        }

        .header-notification.active {
            background: #667eea;
            color: white;
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            min-width: 20px;
            animation: pulse 2s infinite;
        }

        .notification-badge.zero {
            display: none;
        }

        .notification-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 350px;
            max-height: 400px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
            overflow: hidden;
        }

        .notification-dropdown.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .notification-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
        }

        .notification-header h3 {
            margin: 0;
            font-size: 1rem;
            color: #333;
        }

        .mark-all-read {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 0.85rem;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .notification-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f1f3f4;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .notification-item:hover {
            background: #f8f9fa;
        }

        .notification-item.unread {
            background: rgba(102, 126, 234, 0.05);
            border-left: 3px solid #667eea;
        }

        .notification-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }

        .notification-message {
            color: #666;
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 5px;
        }

        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
        }

        .user-menu:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .user-name {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.75rem;
            color: #666;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .welcome-info h2 {
            font-size: 1.8rem;
            margin-bottom: 15px;
        }

        .welcome-avatar .avatar {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
            border-left: 4px solid #ddd;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }

        /* สีของ Stat Cards */
        .stat-card.primary {
            border-left-color: #667eea;
        }

        /* ทั้งหมด */
        .stat-card.info {
            border-left-color: #17a2b8;
        }

        /* กำลังดำเนินการ (ฟ้า) */
        .stat-card.success {
            border-left-color: #28a745;
        }

        /* เสร็จสิ้น (เขียว) */
        .stat-card.secondary {
            border-left-color: #6c757d;
        }

        /* อัตราความสำเร็จ (เทา) */
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
            line-height: 1;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
        }

        /* Dashboard Grid & Cards */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .dashboard-card.full-width {
            grid-column: 1 / -1;
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }

        .card-header.alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid #ffc107;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: #333;
        }

        .card-content {
            padding: 25px;
        }

        /* Complaint Items */
        .complaint-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .complaint-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 3px solid #ddd;
            transition: all 0.3s ease;
        }

        .complaint-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .complaint-icon {
            font-size: 1.5rem;
            width: 40px;
            text-align: center;
        }

        .complaint-info {
            flex: 1;
        }

        .complaint-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .complaint-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #666;
        }

        .complaint-status {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        /* Badge Colors (Update ตาม Config) */
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            display: inline-block;
            white-space: nowrap;
        }

        /* Status Colors - สีสถานะ */
        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        /* 0: ยื่นคำร้อง (เทา) */
        .badge-info {
            background: #17a2b8;
            color: white;
        }

        /* 1: กำลังดำเนินการ (ฟ้า) */
        .badge-warning {
            background: #ffc107;
            color: #333;
        }

        /* 2: รอประเมิน (เหลือง) */
        .badge-success {
            background: #28a745;
            color: white;
        }

        /* 3: เสร็จสิ้น (เขียว) */
        .badge-danger {
            background: #dc3545;
            color: white;
        }

        /* 4: ปฏิเสธ (แดง) */

        /* Priority Colors - สีระดับความสำคัญ (แก้ไขตามที่ขอ) */
        .badge-priority-low {
            background: #28a745;
            color: white;
        }

        /* 1: ไม่เร่งด่วน -> เขียว 🟢 */
        .badge-priority-normal {
            background: #3b82f6;
            color: white;
        }

        /* 2: ปกติ -> ฟ้า 🔵 (เปลี่ยนจากเทา) */
        .badge-priority-high {
            background: #ffc107;
            color: #333;
        }

        /* 3: เร่งด่วน -> เหลือง 🟡 */
        .badge-priority-urgent {
            background: #dc3545;
            color: white;
        }

        /* 4: เร่งด่วนมาก -> แดง 🔴 */
        .badge-priority-critical {
            background: #8b5cf6;
            color: white;
        }

        /* 5: วิกฤต -> ม่วง 🟣 (เปลี่ยนจากดำ) */

        /* Additional & Actions */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-decoration: none;
            color: #333;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            text-align: center;
            border: 3px solid transparent;
        }

        .action-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            border-color: #667eea;
        }

        .action-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .evaluation-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }

        .evaluation-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .btn-success {
            background: #28a745;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 1px solid #667eea;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .additional-stats {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .stats-row {
            display: flex;
            justify-content: space-around;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        /* Notifications & Toast */
        .dashboard-notification-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .dashboard-notification-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            background: #f1f3f4;
            border-radius: 8px;
        }

        .toast {
            position: fixed;
            top: 90px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            z-index: 1002;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }

        @media (max-width: 768px) {

            .header-title p,
            .user-info {
                display: none;
            }

            .user-menu {
                min-width: auto;
                padding: 0;
                width: 45px;
                height: 45px;
                border-radius: 50%;
                justify-content: center;
            }

            .dashboard-grid,
            .stats-grid,
            .actions-grid {
                grid-template-columns: 1fr;
            }

            .notification-dropdown {
                width: calc(100vw - 40px);
                right: -150px;
            }
        }

        @media (min-width: 1024px) {
            .main-content.desktop-shifted {
                margin-left: 300px;
            }
        }
    </style>
</head>

<body>
    <header class="top-header">
        <div class="header-left">
            <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                <div class="hamburger"><span></span><span></span><span></span></div>
            </button>
            <div class="header-title">
                <h1><?php echo defined('SITE_NAME') ? SITE_NAME : 'ระบบข้อร้องเรียน'; ?></h1>
                <p>หน้าหลัก Dashboard</p>
            </div>
        </div>
        <div class="header-right">
            <div class="header-notification" id="notificationButton" onclick="toggleNotificationDropdown()">
                <span style="font-size: 18px;">🔔</span>
                <span class="notification-badge<?php echo $unreadCount > 0 ? '' : ' zero'; ?>" id="notificationBadge"><?php echo $unreadCount; ?></span>
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h3>การแจ้งเตือน</h3>
                        <button class="mark-all-read" onclick="markAllAsRead()">อ่านทั้งหมด</button>
                    </div>
                    <div class="notification-list" id="notificationList"></div>
                </div>
            </div>
            <div class="user-menu">
                <div class="user-avatar">👨‍🎓</div>
                <div class="user-info"><span class="user-name"><?php echo htmlspecialchars($user['Stu_name']); ?></span><span class="user-role">นักศึกษา</span></div>
            </div>
        </div>
    </header>

    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($_GET['message']) && $_GET['message'] === 'permission_denied'): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "คุณไม่มีสิทธิ์เข้าถึงหน้านั้น เนื่องจากหน้าดังกล่าวสำหรับเจ้าหน้าที่และผู้ดูแลระบบเท่านั้น",
                    null
                );
            });
        </script>
    <?php endif; ?>

    <main class="main-content">
        <div class="container">
            <div class="welcome-section">
                <div class="welcome-info">
                    <h2>สวัสดี, <?php echo htmlspecialchars($user['Stu_name']); ?> 👋</h2>
                    <p>รหัสนักศึกษา: <?php echo htmlspecialchars($user['Stu_id']); ?></p>
                    <p>เข้าสู่ระบบล่าสุด: <?php echo date('d/m/Y H:i'); ?></p>
                </div>
                <div class="welcome-avatar">
                    <div class="avatar">👨‍🎓</div>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card primary"><span class="stat-icon">📊</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($dashboardStats['total_complaints']); ?></div>
                        <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
                    </div>
                </div>
                <div class="stat-card info"><span class="stat-icon">⏳</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($dashboardStats['pending_complaints'] + $dashboardStats['processing_complaints']); ?></div>
                        <div class="stat-label">กำลังดำเนินการ</div>
                    </div>
                </div>
                <div class="stat-card success"><span class="stat-icon">✅</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($successCount); ?></div>
                        <div class="stat-label">เสร็จสิ้นแล้ว</div>
                    </div>
                </div>
                <div class="stat-card secondary"><span class="stat-icon">📈</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $successRate; ?>%</div>
                        <div class="stat-label">อัตราความสำเร็จ</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>📋 ข้อร้องเรียนล่าสุด</h3><a href="tracking.php" class="btn btn-sm btn-outline">ดูทั้งหมด</a>
                    </div>
                    <div class="card-content">
                        <?php if (!empty($recentComplaints)): ?>
                            <div class="complaint-list">
                                <?php foreach ($recentComplaints as $complaint): ?>
                                    <div class="complaint-item" style="border-left-color: var(--<?php echo $complaint['status_class'] == 'secondary' ? 'gray' : ($complaint['status_class'] == 'info' ? 'cyan' : $complaint['status_class']); ?>);">
                                        <div class="complaint-icon"><?php echo $complaint['Type_icon'] ?? '📋'; ?></div>
                                        <div class="complaint-info">
                                            <div class="complaint-title"><?php echo htmlspecialchars(truncateText($complaint['display_title'] ?? 'ไม่มีหัวข้อ', 50)); ?></div>
                                            <div class="complaint-meta">
                                                <span class="type"><?php echo htmlspecialchars($complaint['Type_infor'] ?? 'ไม่ระบุประเภท'); ?></span>
                                                <span class="date"><?php echo formatDateThai($complaint['Re_date'] ?? ''); ?></span>
                                            </div>
                                        </div>
                                        <div class="complaint-status">
                                            <span class="badge badge-<?php echo $complaint['status_class']; ?>"><?php echo $complaint['status_text']; ?></span>
                                            <span class="badge badge-<?php echo $complaint['priority_class']; ?>" style="font-size: 0.7rem; opacity: 0.9;">ระดับ: <?php echo $complaint['priority_text']; ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>🎯 ยังไม่มีข้อร้องเรียน</p><a href="complaint.php" class="btn btn-primary">ส่งข้อร้องเรียนแรก</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>🔔 การแจ้งเตือน</h3><?php if (count($dashboardNotifications) > 0): ?><span class="notification-count" style="background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 0.8rem;"><?php echo count($dashboardNotifications); ?></span><?php endif; ?>
                    </div>
                    <div class="card-content">
                        <?php if (!empty($dashboardNotifications)): ?>
                            <div class="dashboard-notification-list">
                                <?php foreach ($dashboardNotifications as $notification): ?>
                                    <div class="dashboard-notification-item">
                                        <div class="dashboard-notification-icon"><?php echo $notification['icon']; ?></div>
                                        <div class="dashboard-notification-content">
                                            <div class="dashboard-notification-message"><?php echo htmlspecialchars($notification['Noti_message'] ?? ''); ?></div>
                                            <div class="dashboard-notification-time"><?php echo timeAgo($notification['created_at'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>🔕 ไม่มีการแจ้งเตือนใหม่</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($pendingEvaluations)): ?>
                <div class="dashboard-card full-width">
                    <div class="card-header alert-warning">
                        <h3>⭐ รอการประเมินความพึงพอใจ</h3>
                    </div>
                    <div class="card-content">
                        <div class="evaluation-grid">
                            <?php foreach ($pendingEvaluations as $evaluation): ?>
                                <div class="evaluation-card">
                                    <div class="eval-icon"><?php echo $evaluation['Type_icon'] ?? '📋'; ?></div>
                                    <div class="eval-info">
                                        <h4><?php echo htmlspecialchars(truncateText($evaluation['display_title'] ?? 'ไม่มีหัวข้อ', 60)); ?></h4>
                                        <p><?php echo htmlspecialchars($evaluation['Type_infor'] ?? 'ไม่ระบุประเภท'); ?></p>
                                    </div>
                                    <div class="eval-action"><a href="evaluation.php?id=<?php echo $evaluation['Re_id']; ?>" class="btn-success">⭐ ประเมินตอนนี้</a></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="actions-grid">
                <a href="complaint.php" class="action-card">
                    <div class="action-icon">📝</div>
                    <h4>ส่งข้อร้องเรียน</h4>
                    <p>ยื่นข้อร้องเรียนใหม่</p>
                </a>
                <a href="tracking.php" class="action-card">
                    <div class="action-icon">📋</div>
                    <h4>ติดตามสถานะ</h4>
                    <p>ดูความคืบหน้า</p>
                </a>
                <a href="evaluation.php" class="action-card">
                    <div class="action-icon">⭐</div>
                    <h4>ประเมินบริการ</h4>
                    <p>ให้คะแนน</p>
                </a>
                <a href="profile.php" class="action-card">
                    <div class="action-icon">👤</div>
                    <h4>ข้อมูลส่วนตัว</h4>
                    <p>จัดการข้อมูล</p>
                </a>
            </div>
        </div>
    </main>

    <div class="toast" id="toast"></div>

    <script>
        let currentUnreadCount = <?php echo $unreadCount; ?>;
        let notificationDropdownOpen = false;

        document.addEventListener('DOMContentLoaded', function() {
            loadNotifications();
            startNotificationPolling();
            if (window.innerWidth >= 1024) setTimeout(() => {
                openSidebar();
            }, 500);

            document.addEventListener('click', function(e) {
                const notiBtn = document.getElementById('notificationButton');
                if (!notiBtn.contains(e.target)) closeNotificationDropdown();

                const sidebar = document.getElementById('sidebar');
                const toggle = document.querySelector('.mobile-menu-toggle');
                if (window.innerWidth < 1024 && sidebar && sidebar.classList.contains('show') && !sidebar.contains(e.target) && !toggle.contains(e.target)) closeSidebar();
            });

            window.addEventListener('resize', function() {
                const sidebar = document.getElementById('sidebar');
                if (window.innerWidth >= 1024) {
                    document.getElementById('sidebarOverlay')?.classList.remove('show');
                    if (sidebar?.classList.contains('show')) {
                        document.querySelector('.main-content')?.classList.add('shifted', 'desktop-shifted');
                        sidebar.classList.add('desktop-open');
                    }
                } else {
                    document.querySelector('.main-content')?.classList.remove('shifted', 'desktop-shifted');
                    sidebar?.classList.remove('desktop-open');
                }
            });
        });

        // Sidebar Functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && sidebar.classList.contains('show')) closeSidebar();
            else openSidebar();
        }

        function openSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.add('show');
            if (window.innerWidth >= 1024) {
                document.querySelector('.main-content')?.classList.add('shifted', 'desktop-shifted');
                sidebar?.classList.add('desktop-open');
            } else {
                document.getElementById('sidebarOverlay')?.classList.add('show');
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.classList.remove('show');
                sidebar.classList.remove('desktop-open');
            }
            document.getElementById('sidebarOverlay')?.classList.remove('show');
            document.querySelector('.main-content')?.classList.remove('shifted', 'desktop-shifted');
        }

        // Notification Functions
        function toggleNotificationDropdown() {
            if (notificationDropdownOpen) closeNotificationDropdown();
            else openNotificationDropdown();
        }

        function openNotificationDropdown() {
            document.getElementById('notificationDropdown').classList.add('show');
            document.getElementById('notificationButton').classList.add('active');
            notificationDropdownOpen = true;
            loadNotifications();
        }

        function closeNotificationDropdown() {
            document.getElementById('notificationDropdown').classList.remove('show');
            document.getElementById('notificationButton').classList.remove('active');
            notificationDropdownOpen = false;
        }

        function loadNotifications() {
            fetch('?action=get_notifications').then(r => r.json()).then(data => {
                const list = document.getElementById('notificationList');
                if (!data.notifications.length) {
                    list.innerHTML = '<div class="no-notifications"><p>ไม่มีการแจ้งเตือน</p></div>';
                    return;
                }
                list.innerHTML = data.notifications.map(n =>
                    `<div class="notification-item ${n.Noti_status==0?'unread':''}" onclick="handleNotiClick(${n.Noti_id}, ${n.Re_id||'null'})">
                        <div class="notification-title">${n.Noti_title}</div><div class="notification-message">${n.Noti_message}</div>
                    </div>`
                ).join('');
            });
        }

        function handleNotiClick(nid, rid) {
            const fd = new FormData();
            fd.append('notification_id', nid);
            fetch('?action=mark_as_read', {
                method: 'POST',
                body: fd
            }).then(() => {
                if (rid) window.location.href = `tracking.php?id=${rid}`;
            });
        }

        function markAllAsRead() {
            fetch('?action=mark_all_as_read', {
                method: 'POST'
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    document.getElementById('notificationBadge').classList.add('zero');
                    loadNotifications();
                }
            });
        }

        function startNotificationPolling() {
            setInterval(() => {
                fetch('?action=get_unread_count').then(r => r.json()).then(d => {
                    if (d.unread_count > 0) document.getElementById('notificationBadge').classList.remove('zero');
                    document.getElementById('notificationBadge').textContent = d.unread_count;
                });
            }, 15000);
        }
    </script>
</body>

</html>