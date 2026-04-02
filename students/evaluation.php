<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบสิทธิ์
requireRole('student', '../login.php');

$user = getCurrentUser();
$db = getDB();

$error = '';
$success = '';

// Handle AJAX requests สำหรับการอัพเดตแบบ real-time
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

// Handle evaluation submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $requestId = intval($_POST['request_id']);
    $score = intval($_POST['overall_rating'] ?? 0);
    $suggestion = sanitizeInput($_POST['comment']);

    if ($score < 1 || $score > 5) {
        $error = 'กรุณาให้คะแนนประเมินระหว่าง 1-5';
    } else {
        try {
            // 1. ตรวจสอบว่าเคยมีข้อมูลการประเมินในตาราง evaluation แล้วหรือยัง
            $existingEvaluation = $db->fetch(
                "SELECT Eva_id FROM evaluation WHERE Re_id = ?",
                [$requestId]
            );

            if ($existingEvaluation) {
                // --- Self-Healing: ซ่อมแซมสถานะ ---
                $currentRequest = $db->fetch(
                    "SELECT Re_status FROM request WHERE Re_id = ?",
                    [$requestId]
                );

                if ($currentRequest && $currentRequest['Re_status'] == '2') {
                    $db->update('request', ['Re_status' => '3'], 'Re_id = ?', [$requestId]);
                    $success = 'ระบบตรวจสอบพบข้อมูลประเมินเดิม และได้อัปเดตสถานะเป็น "ประเมินแล้ว" เรียบร้อยครับ';
                } else {
                    $error = 'คุณได้ประเมินข้อร้องเรียนนี้ไปแล้ว ไม่สามารถแก้ไขได้';
                }
            } else {
                // 2. ถ้ายังไม่เคยประเมิน
                $request = $db->fetch(
                    "SELECT Re_id FROM request WHERE Re_id = ? AND Stu_id = ? AND Re_status = '2'",
                    [$requestId, $user['Stu_id']]
                );

                if (!$request) {
                    $error = 'ไม่พบข้อร้องเรียนที่ระบุ หรือข้อร้องเรียนยังไม่เสร็จสิ้น';
                } else {
                    $evaluationData = [
                        'Eva_score' => $score,
                        'Eva_sug' => $suggestion,
                        'Re_id' => $requestId
                    ];

                    $result = $db->insert('evaluation', $evaluationData);

                    if ($result) {
                        $db->update('request', ['Re_status' => '3'], 'Re_id = ?', [$requestId]);
                        $success = 'ส่งการประเมินเรียบร้อย ขอบคุณสำหรับความคิดเห็น';
                    } else {
                        $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูลการประเมิน';
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Evaluation submission error: " . $e->getMessage());
            $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        }
    }
}

// ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// Get completed requests for evaluation
$completedRequests = getCompletedRequestsForEvaluation($user['Stu_id']);

// Get evaluation history
$evaluationHistory = getEvaluationHistory($user['Stu_id']);
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ประเมินความพึงพอใจ - <?php echo SITE_NAME; ?></title>
    <style>
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
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Notification Dropdown */
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

        .mark-all-read:hover {
            background: #667eea;
            color: white;
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

        .notification-item.unread::before {
            content: '';
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            width: 8px;
            height: 8px;
            background: #667eea;
            border-radius: 50%;
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

        .notification-time {
            color: #999;
            font-size: 0.75rem;
        }

        .no-notifications {
            padding: 40px 20px;
            text-align: center;
            color: #999;
        }

        .no-notifications .icon {
            font-size: 3rem;
            margin-bottom: 10px;
            opacity: 0.5;
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

        /* Main Content */
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
            max-width: 1000px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            color: #333;
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .page-header p {
            color: #666;
            margin: 0;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 114, 128, 0.3);
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #333;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Evaluation Cards */
        .evaluation-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .evaluation-section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 1.3rem;
        }

        .request-selector {
            margin-bottom: 30px;
        }

        .request-option {
            background: #f8f9fa;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .request-option:hover {
            border-color: #667eea;
            transform: translateY(-2px);
        }

        .request-option.selected {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: #667eea;
        }

        .request-option.evaluated {
            background: linear-gradient(135deg, #e8f5e8 0%, #c8e6c9 100%);
            border-color: #4caf50;
            cursor: default;
        }

        .request-title {
            font-weight: bold;
            color: #333;
            margin-bottom: 8px;
        }

        .request-meta {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .request-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-evaluated {
            background: #4caf50;
            color: white;
        }

        .status-pending {
            background: #2196f3;
            color: white;
        }

        /* Rating System */
        .rating-group {
            margin-bottom: 30px;
        }

        .rating-label {
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .rating-container {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 15px 0;
        }

        .rating-box {
            flex: 1;
            text-align: center;
            padding: 15px 10px;
            border: 3px solid #ddd;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fff;
            position: relative;
        }

        .rating-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .rating-box.selected {
            background: linear-gradient(145deg, #e8f5e8, #c8e6c9);
            border-color: #4caf50;
            box-shadow: 0 4px 12px rgba(76, 175, 80, 0.3);
            transform: scale(1.05);
        }

        .rating-emoji {
            font-size: 28px;
            margin-bottom: 8px;
            display: block;
        }

        .rating-number {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }

        .rating-label-text {
            font-size: 12px;
            color: #666;
            font-weight: 600;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }

        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.6;
            min-height: 120px;
            resize: vertical;
            transition: all 0.3s ease;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        /* Evaluation Form */
        .evaluation-form {
            display: none;
            background: #f8f9fa;
            padding: 30px;
            border-radius: 15px;
            margin-top: 30px;
        }

        .evaluation-form.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-actions {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e1e5e9;
        }

        /* Evaluation History */
        .history-card {
            background: #f8f9fa;
            border-left: 5px solid #667eea;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 0 10px 10px 0;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .history-title {
            font-weight: bold;
            color: #333;
        }

        .history-date {
            font-size: 14px;
            color: #666;
        }

        .history-rating {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 10px;
            margin-bottom: 15px;
        }

        .history-rating-value {
            font-size: 28px;
            font-weight: bold;
            color: #4caf50;
            margin-bottom: 5px;
        }

        .history-rating-label {
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }

        .history-comment {
            background: white;
            padding: 15px;
            border-radius: 8px;
            font-style: italic;
            color: #666;
        }

        /* Evaluation Display */
        .evaluation-display {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            margin-top: 20px;
        }

        .evaluation-display h4 {
            color: #4caf50;
            margin-bottom: 20px;
            text-align: center;
        }

        .evaluation-rating {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .evaluation-rating-value {
            font-size: 28px;
            font-weight: bold;
            color: #4caf50;
            margin-bottom: 5px;
        }

        .evaluation-rating-label {
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }

        .evaluation-comment-display {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #4caf50;
        }

        .evaluation-comment-display h5 {
            color: #333;
            margin-bottom: 10px;
        }

        /* Alerts */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }

        .alert-success {
            background: linear-gradient(135deg, #51cf66 0%, #40c057 100%);
            color: white;
        }

        .alert-warning {
            background: linear-gradient(135deg, #ffd43b 0%, #fab005 100%);
            color: #333;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-title {
            color: #333;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .empty-description {
            color: #666;
            margin-bottom: 30px;
        }

        /* Toast Notification */
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

        .toast.error {
            background: #dc3545;
        }

        .toast.info {
            background: #17a2b8;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-title h1 {
                font-size: 1rem;
            }

            .header-title p {
                display: none;
            }

            .user-menu {
                min-width: auto;
                width: 45px;
                height: 45px;
                padding: 0;
                border-radius: 50%;
                justify-content: center;
            }

            .user-info {
                display: none;
            }

            .main-content {
                padding: 15px;
            }

            .main-content.shifted {
                margin-left: 0;
            }

            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .rating-container {
                flex-direction: column;
                gap: 15px;
            }

            .rating-box {
                display: flex;
                align-items: center;
                text-align: left;
                padding: 15px;
            }

            .rating-emoji {
                margin-right: 15px;
                margin-bottom: 0;
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
                <div class="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </button>
            <div class="header-title">
                <h1>ประเมินความพึงพอใจ</h1>
                <p>ให้คะแนนและความคิดเห็นเกี่ยวกับการบริการ</p>
            </div>
        </div>

        <div class="header-right">
            <div class="header-notification" id="notificationButton" onclick="toggleNotificationDropdown()">
                <span style="font-size: 18px;">🔔</span>
                <span class="notification-badge<?php echo $unreadCount > 0 ? '' : ' zero'; ?>" id="notificationBadge">
                    <?php echo $unreadCount; ?>
                </span>

                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h3>การแจ้งเตือน</h3>
                        <button class="mark-all-read" onclick="markAllAsRead()">อ่านทั้งหมด</button>
                    </div>
                    <div class="notification-list" id="notificationList">
                    </div>
                </div>
            </div>

            <div class="user-menu">
                <div class="user-avatar">👨‍🎓</div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($user['Stu_name']); ?></span>
                    <span class="user-role">นักศึกษา</span>
                </div>
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
            <div class="page-header">
                <div>
                    <h1>⭐ ประเมินความพึงพอใจ</h1>
                    <p>ให้คะแนนและความคิดเห็นเกี่ยวกับการบริการที่ได้รับ</p>
                </div>
                <a href="index.php" class="btn btn-secondary">← กลับหน้าหลัก</a>
            </div>

            <?php if ($error): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showResultModal('error', <?php echo json_encode($error); ?>);
                });
                </script>
            <?php endif; ?>

            <?php if ($success): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showResultModal('success', <?php echo json_encode($success); ?>);
                });
                </script>
            <?php endif; ?>

            <div class="evaluation-section">
                <h3>📝 ประเมินความพึงพอใจ</h3>

                <?php if (!empty($completedRequests)): ?>
                    <div class="request-selector">
                        <p style="color: #666; margin-bottom: 20px;">เลือกข้อร้องเรียนที่ต้องการประเมิน:</p>

                        <?php
                        // กรองรายการที่จะแสดงใน Selector ไม่ให้ซ้ำกัน
                        $shownRequestIdsSelector = [];
                        foreach ($completedRequests as $request):
                            if (in_array($request['Re_id'], $shownRequestIdsSelector)) continue;
                            $shownRequestIdsSelector[] = $request['Re_id'];

                            $statusClass = $request['evaluation_status'] === 'evaluated' ? 'evaluated' : '';
                            $clickable = $request['evaluation_status'] !== 'evaluated';
                        ?>

                            <div class="request-option <?php echo $statusClass; ?>"
                                <?php if ($clickable): ?>
                                onclick="selectRequest('<?php echo $request['Re_id']; ?>', '<?php echo htmlspecialchars('#' . $request['Re_id'] . ' - ' . $request['Type_infor']); ?>', this)"
                                <?php endif; ?>>

                                <div class="request-status status-<?php echo $request['evaluation_status']; ?>">
                                    <?php echo $request['evaluation_status'] === 'evaluated' ? '✅ ประเมินแล้ว' : '⭐ รอประเมิน'; ?>
                                </div>

                                <div class="request-title">
                                    <?php echo htmlspecialchars($request['Type_icon'] ?? '📋'); ?>
                                    #<?php echo htmlspecialchars($request['Re_id']); ?> -
                                    <?php echo htmlspecialchars($request['Type_infor']); ?>
                                </div>

                                <div class="request-meta">
                                    📅 เสร็จสิ้น: <?php echo formatThaiDate(strtotime($request['Re_date'])); ?>
                                </div>

                                <div class="request-meta">
                                    📝 <?php echo $request['Re_iden'] ? 'ไม่ระบุตัวตน' : 'ระบุตัวตน'; ?>
                                </div>

                                <?php if ($request['evaluation_status'] === 'evaluated'): ?>
                                    <div class="evaluation-display">
                                        <h4>ผลการประเมินของคุณ</h4>
                                        <div class="evaluation-rating">
                                            <div class="evaluation-rating-value"><?php echo $request['Eva_score']; ?>/5</div>
                                            <div class="evaluation-rating-label">คะแนนความพึงพอใจ</div>
                                        </div>
                                        <?php if ($request['evaluation_comment']): ?>
                                            <div class="evaluation-comment-display">
                                                <h5>ความคิดเห็น:</h5>
                                                <p>"<?php echo htmlspecialchars($request['evaluation_comment']); ?>"</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form method="POST" class="evaluation-form" id="evaluationForm">
                        <input type="hidden" name="request_id" id="selectedRequestId">

                        <h4 style="color: #667eea; margin-bottom: 25px; text-align: center;">
                            ประเมินข้อร้องเรียน: <span id="selectedRequestTitle">-</span>
                        </h4>

                        <div class="rating-group">
                            <div class="rating-label">ความพึงพอใจโดยรวม</div>
                            <div class="rating-container">
                                <div class="rating-box" onclick="selectRating('overall', 1, this)">
                                    <span class="rating-emoji">😡</span>
                                    <div class="rating-number">1</div>
                                    <div class="rating-label-text">แย่มาก</div>
                                </div>
                                <div class="rating-box" onclick="selectRating('overall', 2, this)">
                                    <span class="rating-emoji">😞</span>
                                    <div class="rating-number">2</div>
                                    <div class="rating-label-text">ไม่ดี</div>
                                </div>
                                <div class="rating-box" onclick="selectRating('overall', 3, this)">
                                    <span class="rating-emoji">😐</span>
                                    <div class="rating-number">3</div>
                                    <div class="rating-label-text">ปกติ</div>
                                </div>
                                <div class="rating-box" onclick="selectRating('overall', 4, this)">
                                    <span class="rating-emoji">😊</span>
                                    <div class="rating-number">4</div>
                                    <div class="rating-label-text">ดี</div>
                                </div>
                                <div class="rating-box" onclick="selectRating('overall', 5, this)">
                                    <span class="rating-emoji">😍</span>
                                    <div class="rating-number">5</div>
                                    <div class="rating-label-text">ดีมาก</div>
                                </div>
                            </div>
                            <input type="hidden" name="overall_rating" id="overallRating">
                        </div>

                        <div class="form-group">
                            <label>ความคิดเห็นและข้อเสนอแนะ</label>
                            <textarea name="comment" placeholder="กรุณาแชร์ความคิดเห็นของคุณเกี่ยวกับการบริการ..."></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="submit_evaluation" class="btn btn-success">⭐ ส่งการประเมิน</button>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">⭐</div>
                        <h3 class="empty-title">ไม่มีข้อร้องเรียนให้ประเมิน</h3>
                        <p class="empty-description">คุณไม่มีข้อร้องเรียนที่เสร็จสิ้นแล้วให้ประเมิน</p>
                        <a href="complaint.php" class="btn btn-primary">📝 ส่งข้อร้องเรียนใหม่</a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($evaluationHistory)): ?>
                <div class="evaluation-section">
                    <h3>📊 ประวัติการประเมิน</h3>

                    <?php
                    // *** แก้ไขการแสดงผลซ้ำ: ใช้ Array เก็บ ID ที่แสดงไปแล้ว ***
                    $displayedHistoryIds = [];
                    foreach ($evaluationHistory as $evaluation):
                        // ถ้า Re_id นี้ถูกแสดงไปแล้ว ให้ข้ามลูปนี้ไป
                        if (in_array($evaluation['Re_id'], $displayedHistoryIds)) continue;
                        $displayedHistoryIds[] = $evaluation['Re_id'];
                    ?>
                        <div class="history-card">
                            <div class="history-header">
                                <div class="history-title">
                                    <?php echo htmlspecialchars($evaluation['Type_icon'] ?? '📋'); ?>
                                    #<?php echo htmlspecialchars($evaluation['Re_id'] ?? 'N/A'); ?> -
                                    <?php echo htmlspecialchars($evaluation['Type_infor'] ?? 'ไม่ระบุ'); ?>
                                </div>
                                <div class="history-date">
                                    ประเมินเมื่อ: <?php echo formatThaiDate(strtotime($evaluation['created_at'] ?? 'now')); ?>
                                </div>
                            </div>
                            <div class="history-rating">
                                <div class="history-rating-value"><?php echo $evaluation['Eva_score'] ?? 'N/A'; ?>/5</div>
                                <div class="history-rating-label">คะแนนความพึงพอใจ</div>
                            </div>
                            <?php if (!empty($evaluation['Eva_sug'])): ?>
                                <div class="history-comment">
                                    "<?php echo htmlspecialchars($evaluation['Eva_sug']); ?>"
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="toast" id="toast"></div>

    <?php
    $currentRole = $_SESSION['user_role'] ?? '';
    if ($currentRole === 'teacher'): ?>
        <script src="../js/staff.js"></script>
    <?php endif; ?>

    <script>
        // Global variables
        let currentUnreadCount = <?php echo $unreadCount; ?>;
        let notificationDropdownOpen = false;
        let notificationCheckInterval;
        let selectedRequestId = null;

        document.addEventListener('DOMContentLoaded', function() {
            loadNotifications();
            startNotificationPolling();

            document.addEventListener('click', function(e) {
                const notificationButton = document.getElementById('notificationButton');
                if (!notificationButton.contains(e.target)) {
                    closeNotificationDropdown();
                }
            });

            if (window.innerWidth >= 1024) {
                setTimeout(() => {
                    openSidebar();
                }, 500);
            }
        });

        function updateNotificationBadge(count) {
            const badge = document.getElementById('notificationBadge');
            if (badge) {
                badge.textContent = count;
                if (count > 0) badge.classList.remove('zero');
                else badge.classList.add('zero');
            }
            currentUnreadCount = count;
        }

        function toggleNotificationDropdown() {
            if (notificationDropdownOpen) closeNotificationDropdown();
            else openNotificationDropdown();
        }

        function openNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');
            dropdown.classList.add('show');
            button.classList.add('active');
            notificationDropdownOpen = true;
            loadNotifications();
        }

        function closeNotificationDropdown() {
            const dropdown = document.getElementById('notificationDropdown');
            const button = document.getElementById('notificationButton');
            dropdown.classList.remove('show');
            button.classList.remove('active');
            notificationDropdownOpen = false;
        }

        function loadNotifications() {
            fetch('?action=get_notifications')
                .then(response => response.json())
                .then(data => {
                    displayNotifications(data.notifications);
                })
                .catch(error => {
                    displayNotifications([]);
                });
        }

        function displayNotifications(notifications) {
            const listContainer = document.getElementById('notificationList');
            if (!notifications || notifications.length === 0) {
                listContainer.innerHTML = `<div class="no-notifications"><div class="icon">🔔</div><p>ไม่มีการแจ้งเตือน</p></div>`;
                return;
            }
            let html = '';
            notifications.forEach(notification => {
                const isUnread = notification.Noti_status == 0;
                const time = formatRelativeTime(notification.Noti_date);
                html += `<div class="notification-item ${isUnread ? 'unread' : ''}" onclick="handleNotificationClick(${notification.Noti_id}, ${notification.Re_id || 'null'})"><div class="notification-title">${escapeHtml(notification.Noti_title)}</div><div class="notification-message">${escapeHtml(notification.Noti_message)}</div><div class="notification-time">${time}</div></div>`;
            });
            listContainer.innerHTML = html;
        }

        function handleNotificationClick(notificationId, requestId) {
            markNotificationAsRead(notificationId);
            if (requestId) window.location.href = `tracking.php?id=${requestId}`;
        }

        function markNotificationAsRead(notificationId) {
            const formData = new FormData();
            formData.append('notification_id', notificationId);
            fetch('?action=mark_as_read', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateUnreadCount();
                        loadNotifications();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        function markAllAsRead() {
            fetch('?action=mark_all_as_read', {
                    method: 'POST'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateNotificationBadge(0);
                        loadNotifications();
                        showToast('อ่านทั้งหมดแล้ว');
                    }
                });
        }

        function updateUnreadCount() {
            fetch('?action=get_unread_count')
                .then(response => response.json())
                .then(data => {
                    if (data.unread_count !== currentUnreadCount) {
                        updateNotificationBadge(data.unread_count);
                        if (data.unread_count > currentUnreadCount) showToast('คุณมีการแจ้งเตือนใหม่', 'info');
                    }
                });
        }

        function startNotificationPolling() {
            notificationCheckInterval = setInterval(updateUnreadCount, 15000);
        }

        function stopNotificationPolling() {
            if (notificationCheckInterval) clearInterval(notificationCheckInterval);
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        function formatRelativeTime(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);
            if (diffInSeconds < 60) return 'เมื่อสักครู่';
            if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} นาทีที่แล้ว`;
            if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} ชั่วโมงที่แล้ว`;
            return `${Math.floor(diffInSeconds / 86400)} วันที่แล้ว`;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            if (sidebar.classList.contains('show')) closeSidebar();
            else openSidebar();
        }

        function openSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.add('show');
            if (window.innerWidth >= 1024) {
                mainContent.classList.add('shifted', 'desktop-shifted');
                sidebar.classList.add('desktop-open');
            } else {
                document.getElementById('sidebarOverlay').classList.add('show');
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('.main-content');
            sidebar.classList.remove('show', 'desktop-open');
            mainContent.classList.remove('shifted', 'desktop-shifted');
            document.getElementById('sidebarOverlay').classList.remove('show');
        }

        function selectRequest(requestId, title, element) {
            document.querySelectorAll('.request-option').forEach(option => option.classList.remove('selected'));
            element.classList.add('selected');
            selectedRequestId = requestId;
            document.getElementById('selectedRequestId').value = requestId;
            document.getElementById('selectedRequestTitle').textContent = title;
            document.getElementById('evaluationForm').classList.add('active');
            document.getElementById('evaluationForm').scrollIntoView({
                behavior: 'smooth'
            });
        }

        function selectRating(type, rating, element) {
            const container = element.closest('.rating-container');
            container.querySelectorAll('.rating-box').forEach(box => box.classList.remove('selected'));
            element.classList.add('selected');
            document.getElementById(type + 'Rating').value = rating;
        }
    </script>

    <?php if ($success || $error): ?>
        <script>
            // handled by showResultModal above
        </script>
    <?php endif; ?>

    <!-- ===== MODAL OVERLAY ===== -->
    <div id="swal-overlay" style="
        display:none; position:fixed; inset:0; z-index:20000;
        background:rgba(30,30,60,0.48); backdrop-filter:blur(4px);
        align-items:center; justify-content:center;
    ">
        <div id="swal-box" style="
            background:#fff; border-radius:24px; padding:42px 38px 34px;
            width:100%; max-width:400px; text-align:center;
            box-shadow:0 28px 70px rgba(0,0,0,0.2);
            transform:scale(0.85); opacity:0;
            transition:transform 0.32s cubic-bezier(.22,1,.36,1), opacity 0.25s ease;
            position:relative; margin:16px;
        ">
            <div id="swal-icon-wrap" style="margin-bottom:22px;"></div>
            <div id="swal-title"    style="font-size:1.35rem; font-weight:800; color:#1a1a2e; margin-bottom:9px;"></div>
            <div id="swal-sub"      style="font-size:0.92rem; color:#666; line-height:1.6; margin-bottom:6px;"></div>
            <div id="swal-reason-box" style="
                display:none; border-radius:10px; padding:13px 16px; margin:14px 0 4px;
                text-align:left; font-size:0.87rem; line-height:1.55;
            "></div>
            <div id="swal-buttons" style="display:flex; gap:12px; justify-content:center; margin-top:26px; flex-wrap:wrap;"></div>
        </div>
    </div>

    <style>
        @keyframes swal-pop    { 0%{transform:scale(0) rotate(-12deg);opacity:0} 70%{transform:scale(1.13) rotate(2deg)} 100%{transform:scale(1) rotate(0);opacity:1} }
        @keyframes swal-check  { 0%{stroke-dashoffset:80} 100%{stroke-dashoffset:0} }
        @keyframes swal-shake  { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-7px)} 40%{transform:translateX(7px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)} }
        @keyframes swal-ring   { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.2);opacity:.55} }
        @keyframes swal-bob    { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-6px)} }
        @keyframes swal-star   { 0%,100%{transform:rotate(0) scale(1)} 25%{transform:rotate(-15deg) scale(1.2)} 75%{transform:rotate(15deg) scale(1.2)} }

        .swal-btn {
            padding:12px 30px; border-radius:50px; font-size:.95rem;
            font-weight:700; border:none; cursor:pointer;
            transition:transform .15s, box-shadow .15s;
        }
        .swal-btn:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.15); }
        .swal-btn:active { transform:translateY(0); }
        .swal-btn-confirm { background:linear-gradient(135deg,#43e97b,#38f9d7); color:#1a6640; }
        .swal-btn-cancel  { background:linear-gradient(135deg,#ff6b6b,#ee0979); color:#fff; }
        .swal-btn-ok-success { background:linear-gradient(135deg,#43e97b,#38f9d7); color:#1a6640; }
        .swal-btn-ok-error   { background:linear-gradient(135deg,#ff6b6b,#ee0979); color:#fff; }
    </style>

    <script>
    /* ============================================================
       MODAL ENGINE
    ============================================================ */
    const _swalOverlay = document.getElementById('swal-overlay');
    const _swalBox     = document.getElementById('swal-box');

    function _openSwal() {
        _swalOverlay.style.display = 'flex';
        requestAnimationFrame(() => {
            _swalBox.style.transform = 'scale(1)';
            _swalBox.style.opacity   = '1';
        });
    }
    function _closeSwal() {
        _swalBox.style.transform = 'scale(0.85)';
        _swalBox.style.opacity   = '0';
        setTimeout(() => { _swalOverlay.style.display = 'none'; }, 280);
    }

    /* ---- icon builders ---- */
    function _iconSuccess() {
        return `<div style="animation:swal-pop .5s ease forwards;display:inline-flex;align-items:center;justify-content:center;
                    width:82px;height:82px;border-radius:50%;background:linear-gradient(135deg,#43e97b,#38f9d7);
                    box-shadow:0 8px 26px rgba(67,233,123,.4);">
                  <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path d="M10 21 L17 28 L30 14" stroke="white" stroke-width="3.5"
                          stroke-linecap="round" stroke-linejoin="round"
                          stroke-dasharray="80" stroke-dashoffset="80"
                          style="animation:swal-check .45s .3s ease forwards"/>
                  </svg>
                </div>`;
    }
    function _iconError() {
        return `<div style="animation:swal-pop .4s ease forwards,swal-shake .5s .4s ease;display:inline-flex;align-items:center;justify-content:center;
                    width:82px;height:82px;border-radius:50%;background:linear-gradient(135deg,#ff6b6b,#ee0979);
                    box-shadow:0 8px 26px rgba(238,9,121,.35);">
                  <svg width="36" height="36" viewBox="0 0 36 36" fill="none">
                    <line x1="10" y1="10" x2="26" y2="26" stroke="white" stroke-width="3.5" stroke-linecap="round"/>
                    <line x1="26" y1="10" x2="10" y2="26" stroke="white" stroke-width="3.5" stroke-linecap="round"/>
                  </svg>
                </div>`;
    }
    function _iconConfirm() {
        return `<div style="position:relative;display:inline-block;">
                  <div style="animation:swal-ring 1.6s ease infinite;position:absolute;inset:-9px;border-radius:50%;
                              background:rgba(102,126,234,.14);"></div>
                  <div style="animation:swal-pop .4s ease forwards;display:inline-flex;align-items:center;justify-content:center;
                              width:82px;height:82px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);
                              box-shadow:0 8px 26px rgba(102,126,234,.4);position:relative;">
                    <span style="font-size:2rem;animation:swal-bob 1.4s ease infinite;">⭐</span>
                  </div>
                </div>`;
    }

    /* ---- public API ---- */
    function showConfirmModal(title, sub, targetForm) {
        document.getElementById('swal-icon-wrap').innerHTML   = _iconConfirm();
        document.getElementById('swal-title').textContent     = title;
        document.getElementById('swal-sub').textContent       = sub;
        const rb = document.getElementById('swal-reason-box');
        rb.style.display = 'none';
        document.getElementById('swal-buttons').innerHTML = `
            <button class="swal-btn swal-btn-cancel"  onclick="_closeSwal()">ยกเลิก</button>
            <button class="swal-btn swal-btn-confirm" id="_swalOkBtn">ยืนยันส่งการประเมิน</button>`;
        document.getElementById('_swalOkBtn').onclick = () => {
            _closeSwal();
            setTimeout(() => {
                let flag = targetForm.querySelector('input[name="submit_evaluation"]');
                if (!flag) {
                    flag = document.createElement('input');
                    flag.type  = 'hidden';
                    flag.name  = 'submit_evaluation';
                    flag.value = '1';
                    targetForm.appendChild(flag);
                }
                targetForm.submit();
            }, 220);
        };
        _openSwal();
    }

    function showResultModal(type, message) {
        const isOk = (type === 'success');
        document.getElementById('swal-icon-wrap').innerHTML = isOk ? _iconSuccess() : _iconError();
        document.getElementById('swal-title').textContent   = isOk ? 'ส่งการประเมินสำเร็จ!' : 'เกิดข้อผิดพลาด';
        document.getElementById('swal-sub').textContent     = '';

        const rb = document.getElementById('swal-reason-box');
        rb.style.display = 'block';
        rb.innerHTML     = `<strong>${isOk ? '✅' : '❌'} ผลลัพธ์:</strong> ${message}`;
        if (isOk) {
            rb.style.background  = '#f0fff4';
            rb.style.borderLeft  = '4px solid #38a169';
            rb.style.color       = '#276749';
        } else {
            rb.style.background  = '#fff5f5';
            rb.style.borderLeft  = '4px solid #e53e3e';
            rb.style.color       = '#c0392b';
        }

        const btnClass = isOk ? 'swal-btn-ok-success' : 'swal-btn-ok-error';
        const onClose  = isOk ? `() => { _closeSwal(); setTimeout(() => location.reload(), 250); }` : `_closeSwal`;
        document.getElementById('swal-buttons').innerHTML =
            `<button class="swal-btn ${btnClass}" onclick="(${onClose})()">รับทราบ</button>`;
        _openSwal();
    }

    /* ============================================================
       INTERCEPT FORM SUBMIT — ต้องรอผู้ใช้กดเองเท่านั้น
    ============================================================ */
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('evaluationForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault(); // หยุดก่อนเสมอ

            // ตรวจสอบ request ที่เลือก
            const reqId = document.getElementById('selectedRequestId').value;
            if (!reqId) {
                showResultModal('error', 'กรุณาเลือกข้อร้องเรียนที่ต้องการประเมิน');
                return;
            }

            // ตรวจสอบคะแนน
            const rating = document.getElementById('overallRating').value;
            if (!rating) {
                showResultModal('error', 'กรุณาเลือกคะแนนความพึงพอใจก่อนส่ง');
                return;
            }

            const ratingLabels = {'1':'แย่มาก 😡','2':'ไม่ดี 😞','3':'ปกติ 😐','4':'ดี 😊','5':'ดีมาก 😍'};
            const title = document.getElementById('selectedRequestTitle').textContent;

            showConfirmModal(
                'ยืนยันการส่งประเมิน',
                `คำร้อง: ${title}\nคะแนน: ${rating}/5 — ${ratingLabels[rating] || ''}`,
                form
            );
        });
    });
    </script>
</body>

</html>