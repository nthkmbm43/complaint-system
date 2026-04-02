<?php
// staff/index.php - หน้าหลักสำหรับอาจารย์/เจ้าหน้าที่
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอิน
requireLogin();
requireStaffAccess();
requireRole('teacher', '../login.php');

$userPermission = $_SESSION['permission'] ?? 1;

// permission=3 ให้ไป dashboard แทน (ผู้ดูแลระบบมีหน้าของตัวเอง)
if ($userPermission == 3) {
    header('Location: dashboard.php');
    exit;
}

$user = getCurrentUser();
$db = getDB();
$teacherId = $user['Aj_id'];
$isOperator = ($userPermission == 2); // ผู้ดำเนินการ

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    switch ($_GET['action']) {
        case 'get_unread_count':
            echo json_encode(['unread_count' => getUnreadNotificationCount($user['Aj_id'], 'teacher')]);
            exit;
        case 'get_notifications':
            $notifications = getRecentNotifications($user['Aj_id'], 'teacher', 10);
            echo json_encode(['notifications' => $notifications]);
            exit;
        case 'mark_as_read':
            if (isset($_POST['notification_id'])) {
                $success = markSingleNotificationAsRead($_POST['notification_id'], $user['Aj_id'], 'teacher');
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing notification ID']);
            }
            exit;
        case 'mark_all_as_read':
            try {
                $success = markAllNotificationsAsRead($user['Aj_id'], 'teacher');
                echo json_encode(['success' => $success]);
            } catch (Exception $e) {
                error_log("mark_all_as_read AJAX error: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
    }
}

// ดึงจำนวนการแจ้งเตือน
$unreadCount = getUnreadNotificationCount($user['Aj_id'], 'teacher');
$recentNotifications = getRecentNotifications($user['Aj_id'], 'teacher', 5);

// สถิติสำหรับอาจารย์ (เฉพาะที่ได้รับมอบหมาย)
$stats = [
    'processing' => 0,
    'waiting_eval' => 0,
    'completed' => 0,
    'rejected' => 0
];

// สถิติเพิ่มเติมสำหรับผู้ดำเนินการ (permission=2)
$operatorStats = [
    'total_all' => 0,       // ข้อร้องเรียนทั้งหมดในระบบ
    'pending_all' => 0,     // รอรับเรื่อง (ยังไม่มอบหมาย)
    'total_staff' => 0,     // จำนวนเจ้าหน้าที่ทั้งหมด
    'total_students' => 0,  // จำนวนนักศึกษาทั้งหมด
];

$pendingComplaints = [];
$recentComplaints = [];
$dashboardNotifications = [];

try {
    // สถิติของตัวเอง (ทุกสิทธิ์)
    $stats['processing']  = $db->count('request', 'Re_status = ? AND Aj_id = ?', ['1', $teacherId]);
    $stats['waiting_eval']= $db->count('request', 'Re_status = ? AND Aj_id = ?', ['2', $teacherId]);
    $stats['completed']   = $db->count('request', 'Re_status = ? AND Aj_id = ?', ['3', $teacherId]);
    $stats['rejected']    = $db->count('request', 'Re_status = ? AND Aj_id = ?', ['4', $teacherId]);

    // สถิติภาพรวมระบบ — เฉพาะผู้ดำเนินการ (permission=2)
    if ($isOperator) {
        $operatorStats['total_all']     = $db->count('request', '1=1');
        $operatorStats['pending_all']   = $db->count('request', 'Re_status = ?', ['0']);
        $operatorStats['total_staff']   = $db->count('teacher', 'Aj_status = 1');
        $operatorStats['total_students']= $db->count('student', 'Stu_status = 1');
    }

    // ข้อร้องเรียนที่กำลังดำเนินการ (ที่ตัวเองรับผิดชอบ)
    $pendingComplaints = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon,
               COALESCE(r.Re_title, LEFT(r.Re_infor, 50)) as display_title,
               CASE r.Re_iden WHEN 1 THEN 'ไม่ระบุตัวตน' ELSE s.Stu_name END as requester_name,
               CASE r.Re_level
                   WHEN '1' THEN 'ไม่เร่งด่วน' WHEN '2' THEN 'ปกติ'
                   WHEN '3' THEN 'เร่งด่วน' WHEN '4' THEN 'เร่งด่วนมาก'
                   WHEN '5' THEN 'วิกฤต/ฉุกเฉิน' ELSE 'รอพิจารณา'
               END as priority_text,
               CASE r.Re_level
                   WHEN '1' THEN 'priority-low' WHEN '2' THEN 'priority-normal'
                   WHEN '3' THEN 'priority-high' WHEN '4' THEN 'priority-urgent'
                   WHEN '5' THEN 'priority-critical' ELSE 'priority-pending'
               END as priority_class
        FROM request r
        LEFT JOIN type t ON r.Type_id = t.Type_id
        LEFT JOIN student s ON r.Stu_id = s.Stu_id
        WHERE r.Re_status = '1' AND r.Aj_id = ?
        ORDER BY r.Re_level DESC, r.Re_date ASC
        LIMIT 5
    ", [$teacherId]);

    // ข้อร้องเรียนที่รอมอบหมาย — เฉพาะผู้ดำเนินการ
    $unassignedComplaints = [];
    if ($isOperator) {
        $unassignedComplaints = $db->fetchAll("
            SELECT r.*, t.Type_infor, t.Type_icon,
                   COALESCE(r.Re_title, LEFT(r.Re_infor, 50)) as display_title,
                   CASE r.Re_iden WHEN 1 THEN 'ไม่ระบุตัวตน' ELSE s.Stu_name END as requester_name
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            WHERE r.Re_status = '0' AND (r.Aj_id IS NULL OR r.Aj_id = 0)
            ORDER BY r.Re_date ASC
            LIMIT 5
        ");
    }

    // ข้อร้องเรียนล่าสุด
    $recentComplaints = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon,
               COALESCE(r.Re_title, LEFT(r.Re_infor, 50)) as display_title,
               CASE r.Re_status
                   WHEN '0' THEN 'รอรับเรื่อง' WHEN '1' THEN 'กำลังดำเนินการ'
                   WHEN '2' THEN 'รอการประเมินผล' WHEN '3' THEN 'เสร็จสิ้น'
                   WHEN '4' THEN 'ปฏิเสธคำร้อง' ELSE 'ไม่ทราบสถานะ'
               END as status_text,
               CASE r.Re_status
                   WHEN '0' THEN 'secondary' WHEN '1' THEN 'info'
                   WHEN '2' THEN 'warning' WHEN '3' THEN 'success'
                   WHEN '4' THEN 'danger' ELSE 'secondary'
               END as status_class
        FROM request r
        LEFT JOIN type t ON r.Type_id = t.Type_id
        WHERE r.Aj_id = ?
        ORDER BY r.Re_date DESC
        LIMIT 5
    ", [$teacherId]);

    // กิจกรรมล่าสุด
    $dashboardNotifications = $db->fetchAll("
        SELECT CONCAT('ข้อร้องเรียน \"', LEFT(COALESCE(r.Re_title, r.Re_infor), 40), '\" ',
                CASE r.Re_status
                    WHEN '0' THEN 'ใหม่รอรับเรื่อง' WHEN '1' THEN 'กำลังดำเนินการ'
                    WHEN '2' THEN 'รอการประเมินผล' WHEN '3' THEN 'เสร็จสิ้นแล้ว'
                    WHEN '4' THEN 'ถูกปฏิเสธ' ELSE 'มีการอัปเดต'
                END) as Noti_message,
            r.Re_date as created_at,
            CASE r.Re_status
                WHEN '0' THEN '📥' WHEN '1' THEN '⏳' WHEN '2' THEN '⭐'
                WHEN '3' THEN '✅' WHEN '4' THEN '❌' ELSE '🔔'
            END as icon, r.Re_status, r.Re_id
        FROM request r
        WHERE r.Aj_id = ?
        ORDER BY r.Re_date DESC LIMIT 5
    ", [$teacherId]);
} catch (Exception $e) {
    error_log("Staff Dashboard Error: " . $e->getMessage());
}

// คำนวณจำนวนรวม
$totalAssigned = $stats['processing'] + $stats['waiting_eval'] + $stats['completed'] + $stats['rejected'];

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

// กำหนดตำแหน่งแสดงผล
if ($userPermission == 2) {
    $roleDisplay = 'ผู้ดำเนินการ';
    $pageTitle   = 'หน้าหลักผู้ดำเนินการ';
} else {
    $roleDisplay = 'อาจารย์';
    $pageTitle   = 'หน้าหลักอาจารย์';
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo defined('SITE_NAME') ? SITE_NAME : 'ระบบข้อร้องเรียน'; ?></title>
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

        /* Notifications */
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

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
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
        }

        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }

        /* User Menu */
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
            color: #667eea;
            font-weight: 500;
        }

        /* Stats Grid - ตามภาพ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 30px 25px;
            border-radius: 20px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
            border: none;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            flex-shrink: 0;
        }

        .stat-icon img {
            width: 50px;
            height: 50px;
            object-fit: contain;
        }

        .stat-number {
            font-size: 2.8rem;
            font-weight: bold;
            color: #e91e63;
            margin-bottom: 5px;
            line-height: 1;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
            font-weight: 500;
        }

        /* Alert Section - กำลังดำเนินการ */
        .alert-section {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border: 2px solid #2196f3;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .alert-header h3 {
            color: #1565c0;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-count {
            background: #2196f3;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
        }

        .pending-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .pending-item {
            background: white;
            padding: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #2196f3;
        }

        .pending-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .pending-icon {
            font-size: 2rem;
            width: 50px;
            text-align: center;
        }

        .pending-info {
            flex: 1;
        }

        .pending-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .pending-meta {
            display: flex;
            gap: 15px;
            font-size: 0.85rem;
            color: #666;
            flex-wrap: wrap;
        }

        .pending-action {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .btn-action {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        /* Dashboard Grid */
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
        }

        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
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
        }

        /* Badge Colors */
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            display: inline-block;
            white-space: nowrap;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: #333;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        /* Priority Badges */
        .badge-priority-pending {
            background: #6c757d;
            color: white;
        }

        .badge-priority-low {
            background: #28a745;
            color: white;
        }

        .badge-priority-normal {
            background: #3b82f6;
            color: white;
        }

        .badge-priority-high {
            background: #ffc107;
            color: #333;
        }

        .badge-priority-urgent {
            background: #dc3545;
            color: white;
        }

        .badge-priority-critical {
            background: #8b5cf6;
            color: white;
        }

        /* Dashboard Notifications */
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

        .dashboard-notification-icon {
            font-size: 1.5rem;
        }

        .dashboard-notification-content {
            flex: 1;
        }

        .dashboard-notification-message {
            color: #333;
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .dashboard-notification-time {
            color: #999;
            font-size: 0.8rem;
        }

        /* Actions Grid */
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

        .action-card h4 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .action-card p {
            margin: 0;
            color: #666;
            font-size: 0.9rem;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .btn-outline {
            background: transparent;
            color: #2196f3;
            border: 1px solid #2196f3;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .btn-outline:hover {
            background: #2196f3;
            color: white;
        }

        /* Toast */
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

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .stat-card {
                padding: 20px 15px;
            }

            .stat-number {
                font-size: 2rem;
            }

            .stat-icon {
                font-size: 2rem;
                width: 45px;
                height: 45px;
            }

            .dashboard-grid,
            .actions-grid {
                grid-template-columns: 1fr;
            }

            .notification-dropdown {
                width: calc(100vw - 40px);
                right: -150px;
            }

            .pending-item {
                flex-direction: column;
                text-align: center;
            }

            .pending-action {
                align-items: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 15px 10px;
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }

            .stat-number {
                font-size: 1.8rem;
            }

            .stat-label {
                font-size: 0.85rem;
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
                <p><?php echo $pageTitle; ?></p>
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
                <div class="user-avatar"><?php echo $isOperator ? '🧑‍💻' : '👨‍🏫'; ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($user['Aj_name']); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($roleDisplay); ?></span>
                </div>
            </div>
        </div>
    </header>

    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "<?php echo $accessDeniedMessage; ?>",
                    "<?php echo $accessDeniedRedirect; ?>"
                );
            });
        </script>
    <?php endif; ?>

    <main class="main-content">
        <div class="container">

            <?php if ($isOperator): ?>
            <!-- ===== ภาพรวมระบบ: เฉพาะผู้ดำเนินการ ===== -->
            <div style="margin-bottom:20px;">
                <h2 style="color:white;margin-bottom:15px;font-size:1.1rem;opacity:0.9;">
                    📊 ภาพรวมระบบ
                </h2>
                <div class="stats-grid" style="grid-template-columns:repeat(4,1fr);">
                    <div class="stat-card" style="border-left:4px solid #6c757d;">
                        <span class="stat-icon">📋</span>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo number_format($operatorStats['total_all']); ?></div>
                            <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid #dc3545;">
                        <span class="stat-icon">📥</span>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo number_format($operatorStats['pending_all']); ?></div>
                            <div class="stat-label">รอมอบหมาย</div>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid #17a2b8;">
                        <span class="stat-icon">👨‍🏫</span>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo number_format($operatorStats['total_staff']); ?></div>
                            <div class="stat-label">เจ้าหน้าที่ในระบบ</div>
                        </div>
                    </div>
                    <div class="stat-card" style="border-left:4px solid #28a745;">
                        <span class="stat-icon">👨‍🎓</span>
                        <div class="stat-info">
                            <div class="stat-number"><?php echo number_format($operatorStats['total_students']); ?></div>
                            <div class="stat-label">นักศึกษาในระบบ</div>
                        </div>
                    </div>
                </div>
            </div>

            <h2 style="color:white;margin-bottom:15px;font-size:1.1rem;opacity:0.9;">
                📌 งานที่ฉันรับผิดชอบ
            </h2>
            <?php endif; ?>

            <!-- Stats Grid - งานของตัวเอง -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">⏳</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($stats['processing']); ?></div>
                        <div class="stat-label">กำลังดำเนินการ</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">⭐</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($stats['waiting_eval']); ?></div>
                        <div class="stat-label">รอประเมิน</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">✅</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($stats['completed']); ?></div>
                        <div class="stat-label">เสร็จสิ้น</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">📋</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($totalAssigned); ?></div>
                        <div class="stat-label">ทั้งหมดของฉัน</div>
                    </div>
                </div>
            </div>

            <?php if ($isOperator && !empty($unassignedComplaints)): ?>
            <!-- ===== รายการรอมอบหมาย: เฉพาะผู้ดำเนินการ ===== -->
            <div class="alert-section" style="border-left:4px solid #dc3545;">
                <div class="alert-header">
                    <h3>📥 ข้อร้องเรียนที่รอมอบหมาย</h3>
                    <span class="alert-count" style="background:#dc3545;"><?php echo $operatorStats['pending_all']; ?> รายการ</span>
                </div>
                <div class="pending-list">
                    <?php foreach ($unassignedComplaints as $c): ?>
                        <div class="pending-item">
                            <div class="pending-icon"><?php echo $c['Type_icon'] ?? '📋'; ?></div>
                            <div class="pending-info">
                                <div class="pending-title"><?php echo htmlspecialchars(truncateText($c['display_title'] ?? 'ไม่มีหัวข้อ', 60)); ?></div>
                                <div class="pending-meta">
                                    <span>📁 <?php echo htmlspecialchars($c['Type_infor'] ?? 'ไม่ระบุประเภท'); ?></span>
                                    <span>👤 <?php echo htmlspecialchars($c['requester_name'] ?? 'ไม่ระบุ'); ?></span>
                                    <span>📅 <?php echo formatDateThai($c['Re_date']); ?></span>
                                </div>
                            </div>
                            <div class="pending-action">
                                <a href="assign-complaint.php?id=<?php echo $c['Re_id']; ?>" class="btn-action" style="background:#dc3545;">
                                    📤 มอบหมาย
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($operatorStats['pending_all'] > 5): ?>
                    <div style="text-align:center;margin-top:20px;">
                        <a href="assign-complaint.php" class="btn-outline">ดูทั้งหมด (<?php echo $operatorStats['pending_all']; ?> รายการ)</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ข้อร้องเรียนที่กำลังดำเนินการ -->
            <?php if (!empty($pendingComplaints)): ?>
                <div class="alert-section">
                    <div class="alert-header">
                        <h3>⏳ ข้อร้องเรียนที่กำลังดำเนินการ</h3>
                        <span class="alert-count"><?php echo $stats['processing']; ?> รายการ</span>
                    </div>
                    <div class="pending-list">
                        <?php foreach ($pendingComplaints as $complaint): ?>
                            <div class="pending-item">
                                <div class="pending-icon"><?php echo $complaint['Type_icon'] ?? '📋'; ?></div>
                                <div class="pending-info">
                                    <div class="pending-title"><?php echo htmlspecialchars(truncateText($complaint['display_title'] ?? 'ไม่มีหัวข้อ', 60)); ?></div>
                                    <div class="pending-meta">
                                        <span>📁 <?php echo htmlspecialchars($complaint['Type_infor'] ?? 'ไม่ระบุประเภท'); ?></span>
                                        <span>👤 <?php echo htmlspecialchars($complaint['requester_name'] ?? 'ไม่ระบุ'); ?></span>
                                        <span>📅 <?php echo formatDateThai($complaint['Re_date']); ?></span>
                                    </div>
                                </div>
                                <div class="pending-action">
                                    <span class="badge badge-<?php echo $complaint['priority_class']; ?>">
                                        <?php echo $complaint['priority_text']; ?>
                                    </span>
                                    <a href="complaint-detail.php?id=<?php echo $complaint['Re_id']; ?>" class="btn-action">
                                        👀 ดูรายละเอียด
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($stats['processing'] > 5): ?>
                        <div style="text-align: center; margin-top: 20px;">
                            <a href="manage-complaints.php?status=1" class="btn-outline">ดูทั้งหมด (<?php echo $stats['processing']; ?> รายการ)</a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>📋 ข้อร้องเรียนล่าสุด</h3>
                        <a href="manage-complaints.php" class="btn-outline">ดูทั้งหมด</a>
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
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>📭 ยังไม่มีข้อร้องเรียนในระบบ</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>🔔 กิจกรรมล่าสุด</h3>
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
                                <p>🔕 ไม่มีกิจกรรมล่าสุด</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="actions-grid">
                <?php if ($isOperator): ?>
                    <a href="manage-complaints.php" class="action-card">
                        <div class="action-icon">📋</div>
                        <h4>จัดการข้อร้องเรียน</h4>
                        <p>ดูและจัดการข้อร้องเรียนทั้งหมด</p>
                    </a>
                    <a href="assign-complaint.php" class="action-card">
                        <div class="action-icon">📤</div>
                        <h4>มอบหมายงาน</h4>
                        <p><?php echo $operatorStats['pending_all']; ?> รายการรอมอบหมาย</p>
                    </a>
                    <a href="users.php" class="action-card">
                        <div class="action-icon">👨‍🎓</div>
                        <h4>ข้อมูลนักศึกษา</h4>
                        <p>จัดการข้อมูลนักศึกษา</p>
                    </a>
                    <a href="reports.php" class="action-card">
                        <div class="action-icon">📊</div>
                        <h4>รายงาน</h4>
                        <p>ดูรายงานสถิติภาพรวม</p>
                    </a>
                <?php else: ?>
                    <a href="my-assignments.php" class="action-card">
                        <div class="action-icon">📥</div>
                        <h4>งานของฉัน</h4>
                        <p>ดูงานที่ได้รับมอบหมาย</p>
                    </a>
                    <a href="my-assignments.php?status=1" class="action-card">
                        <div class="action-icon">⏳</div>
                        <h4>กำลังดำเนินการ</h4>
                        <p><?php echo $stats['processing']; ?> รายการที่รอดำเนินการ</p>
                    </a>
                    <a href="my-assignments.php?status=2" class="action-card">
                        <div class="action-icon">⭐</div>
                        <h4>รอประเมิน</h4>
                        <p><?php echo $stats['waiting_eval']; ?> รายการรอประเมิน</p>
                    </a>
                <?php endif; ?>
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
                if (!data.notifications || !data.notifications.length) {
                    list.innerHTML = '<div class="no-notifications"><p>ไม่มีการแจ้งเตือน</p></div>';
                    return;
                }
                list.innerHTML = data.notifications.map(n =>
                    `<div class="notification-item ${n.Noti_status==0?'unread':''}" onclick="handleNotiClick(${n.Noti_id}, ${n.Re_id||'null'})">
                        <div class="notification-title">${n.Noti_title || 'แจ้งเตือน'}</div>
                        <div class="notification-message">${n.Noti_message || ''}</div>
                    </div>`
                ).join('');
            }).catch(err => {
                console.error('Load notifications error:', err);
            });
        }

        function handleNotiClick(nid, rid) {
            const fd = new FormData();
            fd.append('notification_id', nid);
            fetch('?action=mark_as_read', {
                method: 'POST',
                body: fd
            }).then(() => {
                if (rid) window.location.href = `complaint-detail.php?id=${rid}`;
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
                    else document.getElementById('notificationBadge').classList.add('zero');
                    document.getElementById('notificationBadge').textContent = d.unread_count;
                });
            }, 15000);
        }
    </script>
</body>

</html>