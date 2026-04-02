<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// เปิด debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ตรวจสอบการล็อกอิน
requireRole('student', 'login.php');

$user = getCurrentUser();
$db = getDB();
$studentId = $user['Stu_id'];

$message = '';
$messageType = '';

// Handle AJAX request สำหรับดึงสาขา
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_majors') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        $facultyId = (int)($_GET['faculty_id'] ?? 0);

        if ($facultyId <= 0) {
            echo json_encode([]);
            exit;
        }

        $majors = $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_parent_id = ? AND Unit_type = 'major'
            ORDER BY Unit_name
        ", [$facultyId]);

        echo json_encode($majors ?: [], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("AJAX get_majors error: " . $e->getMessage());
        echo json_encode([]);
        exit;
    }
}

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

// ฟังก์ชันดึงข้อมูลคณะปัจจุบันของผู้ใช้
function getCurrentFacultyId($db, $user)
{
    if (empty($user['Unit_id'])) {
        return null;
    }

    try {
        $major = $db->fetch("
            SELECT Unit_parent_id 
            FROM organization_unit 
            WHERE Unit_id = ? AND Unit_type = 'major'
        ", [$user['Unit_id']]);

        return $major ? $major['Unit_parent_id'] : null;
    } catch (Exception $e) {
        error_log("Error getting current faculty: " . $e->getMessage());
        return null;
    }
}

// ฟังก์ชันดึงสถิติการใช้งาน
function getStudentStats($db, $studentId)
{
    try {
        $stats = [];

        $total = $db->fetch("SELECT COUNT(*) as count FROM request WHERE Stu_id = ?", [$studentId]);
        $stats['total'] = $total ? (int)$total['count'] : 0;

        $pending = $db->fetch("SELECT COUNT(*) as count FROM request WHERE Stu_id = ? AND Re_status IN ('0', '1')", [$studentId]);
        $stats['pending'] = $pending ? (int)$pending['count'] : 0;

        $completed = $db->fetch("SELECT COUNT(*) as count FROM request WHERE Stu_id = ? AND Re_status = '3'", [$studentId]);
        $stats['completed'] = $completed ? (int)$completed['count'] : 0;

        $processing = $db->fetch("SELECT COUNT(*) as count FROM request WHERE Stu_id = ? AND Re_status = '2'", [$studentId]);
        $stats['processing'] = $processing ? (int)$processing['count'] : 0;

        $rating = $db->fetch("
            SELECT AVG(e.Eva_score) as avg_rating 
            FROM evaluation e 
            JOIN request r ON e.Re_id = r.Re_id 
            WHERE r.Stu_id = ? AND e.Eva_score > 0
        ", [$studentId]);
        $stats['avg_rating'] = ($rating && $rating['avg_rating']) ? round((float)$rating['avg_rating'], 1) : 0;

        return $stats;
    } catch (Exception $e) {
        error_log("Error getting student stats: " . $e->getMessage());
        return ['total' => 0, 'pending' => 0, 'completed' => 0, 'processing' => 0, 'avg_rating' => 0];
    }
}

// === การประมวลผลการอัพเดตข้อมูล ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received");
    error_log("POST data: " . print_r($_POST, true));

    // การอัพเดตข้อมูลโปรไฟล์
    if (isset($_POST['update_profile'])) {
        error_log("Profile update requested");

        try {
            // รับข้อมูลจากฟอร์ม
            $fullName = trim($_POST['full_name'] ?? '');
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            $majorId = (int)($_POST['major_id'] ?? 0);
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            error_log("Form data - Name: $fullName, Faculty: $facultyId, Major: $majorId, Phone: $phone, Email: $email");

            // Validation ข้อมูลพื้นฐาน
            if (empty($fullName)) {
                throw new Exception('กรุณากรอกชื่อ-นามสกุล');
            }
            if (empty($facultyId) || $facultyId <= 0) {
                throw new Exception('กรุณาเลือกคณะ');
            }
            if (empty($majorId) || $majorId <= 0) {
                throw new Exception('กรุณาเลือกสาขาวิชา');
            }
            if (mb_strlen($fullName, 'UTF-8') < 2 || mb_strlen($fullName, 'UTF-8') > 100) {
                throw new Exception('ชื่อ-นามสกุลต้องมีความยาว 2-100 ตัวอักษร');
            }
            if (!empty($phone) && !preg_match('/^[0-9]{10}$/', $phone)) {
                throw new Exception('เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก');
            }
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
            }

            error_log("Basic validation passed");

            // ตรวจสอบความสัมพันธ์คณะและสาขา
            $majorCheck = $db->fetch("
                SELECT m.Unit_id, m.Unit_name, m.Unit_parent_id,
                       f.Unit_name as faculty_name
                FROM organization_unit m
                LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id
                WHERE m.Unit_id = ? AND m.Unit_type = 'major' AND m.Unit_parent_id = ?
            ", [$majorId, $facultyId]);

            error_log("Major check result: " . print_r($majorCheck, true));

            if (!$majorCheck) {
                throw new Exception('สาขาวิชาที่เลือกไม่อยู่ในคณะที่เลือก กรุณาเลือกใหม่');
            }

            // ตรวจสอบข้อมูลซ้ำ
            if (!empty($email)) {
                $emailCheck = $db->fetch("
                    SELECT COUNT(*) as count 
                    FROM student 
                    WHERE Stu_email = ? AND Stu_id != ?
                ", [$email, $user['Stu_id']]);
                if ($emailCheck && $emailCheck['count'] > 0) {
                    throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
                }
            }

            if (!empty($phone)) {
                $phoneCheck = $db->fetch("
                    SELECT COUNT(*) as count 
                    FROM student 
                    WHERE Stu_tel = ? AND Stu_id != ?
                ", [$phone, $user['Stu_id']]);
                if ($phoneCheck && $phoneCheck['count'] > 0) {
                    throw new Exception('เบอร์โทรศัพท์นี้ถูกใช้งานแล้ว');
                }
            }

            error_log("Duplicate check passed");

            // อัพเดตข้อมูลด้วย SQL แบบธรรมดา
            $db->beginTransaction();

            $updateSql = "UPDATE student SET 
                         Stu_name = ?, 
                         Unit_id = ?, 
                         Stu_tel = ?, 
                         Stu_email = ?
                         WHERE Stu_id = ?";

            $updateParams = [
                $fullName,
                $majorId,
                !empty($phone) ? $phone : null,
                !empty($email) ? $email : null,
                $user['Stu_id']
            ];

            $stmt = $db->execute($updateSql, $updateParams);
            $rowsAffected = $stmt->rowCount();

            error_log("SQL executed. Rows affected: $rowsAffected");

            if ($rowsAffected >= 0) {
                $db->commit();

                // อัพเดต session
                $_SESSION['user_name'] = $fullName;
                $_SESSION['unit_id'] = $majorId;

                error_log("Transaction committed successfully");

                $message = 'อัพเดตข้อมูลเรียบร้อยแล้ว';
                $messageType = 'success';

                // Force reload user data
                $user = getCurrentUser();
                error_log("User data reloaded");
            } else {
                $db->rollback();
                throw new Exception('ไม่สามารถอัพเดตข้อมูลได้');
            }
        } catch (Exception $e) {
            try {
                $db->rollback();
            } catch (Exception $rollbackEx) {
                error_log("Rollback failed: " . $rollbackEx->getMessage());
            }
            error_log("Exception caught: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }

    // การเปลี่ยนรหัสผ่าน
    if (isset($_POST['change_password'])) {
        error_log("Password change requested");

        try {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                throw new Exception('กรุณากรอกข้อมูลรหัสผ่านให้ครบถ้วน');
            }
            if ($newPassword !== $confirmPassword) {
                throw new Exception('รหัสผ่านใหม่ไม่ตรงกัน');
            }
            if (strlen($newPassword) < 6 || strlen($newPassword) > 10) {
                throw new Exception('รหัสผ่านต้องมีความยาว 6-10 ตัวอักษร');
            }

            // ตรวจสอบรหัสผ่านเก่า
            $currentUser = $db->fetch("SELECT Stu_password FROM student WHERE Stu_id = ?", [$user['Stu_id']]);
            if (!$currentUser || $currentUser['Stu_password'] !== $currentPassword) {
                throw new Exception('รหัสผ่านปัจจุบันไม่ถูกต้อง');
            }

            // อัพเดตรหัสผ่าน
            $result = $db->execute("UPDATE student SET Stu_password = ?, updated_at = NOW() WHERE Stu_id = ?", [$newPassword, $user['Stu_id']]);

            if ($result && $result->rowCount() >= 0) {
                $message = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                $messageType = 'success';
                error_log("Password changed successfully");
            } else {
                throw new Exception('ไม่สามารถเปลี่ยนรหัสผ่านได้');
            }
        } catch (Exception $e) {
            error_log("Password change exception: " . $e->getMessage());
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ดึงข้อมูลหลัก
try {
    // ดึงรายการคณะทั้งหมด
    $faculties = $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_icon 
        FROM organization_unit 
        WHERE Unit_type = 'faculty' 
        ORDER BY Unit_name
    ");
    error_log("Faculties loaded: " . count($faculties));
} catch (Exception $e) {
    error_log("Error loading faculties: " . $e->getMessage());
    $faculties = [];
}

// ดึงคณะปัจจุบันของผู้ใช้
$currentFacultyId = getCurrentFacultyId($db, $user);
error_log("Current faculty ID: " . $currentFacultyId);

// ดึงสาขาในคณะปัจจุบัน
try {
    $currentMajors = $currentFacultyId ? $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_icon 
        FROM organization_unit 
        WHERE Unit_parent_id = ? AND Unit_type = 'major'
        ORDER BY Unit_name
    ", [$currentFacultyId]) : [];
    error_log("Current majors loaded: " . count($currentMajors));
} catch (Exception $e) {
    error_log("Error loading majors: " . $e->getMessage());
    $currentMajors = [];
}

$stats = getStudentStats($db, $studentId);

// ดึงข้อมูล notification
$unreadCount = getUnreadNotificationCount($user['Stu_id'], 'student');
$recentNotifications = getRecentNotifications($user['Stu_id'], 'student', 5);

// ดึงข้อมูลคณะและสาขาปัจจุบัน
$currentUserData = $db->fetch("
    SELECT s.*, 
           m.Unit_name as major_name, m.Unit_icon as major_icon,
           f.Unit_name as faculty_name, f.Unit_icon as faculty_icon
    FROM student s
    LEFT JOIN organization_unit m ON s.Unit_id = m.Unit_id AND m.Unit_type = 'major'
    LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id AND f.Unit_type = 'faculty'
    WHERE s.Stu_id = ?
", [$user['Stu_id']]);

// ถ้าดึงข้อมูลได้ ให้ใช้ข้อมูลนั้น
if ($currentUserData) {
    $user = array_merge($user, $currentUserData);
    error_log("User data merged with current data");
}

error_log("Final user data: " . print_r($user, true));
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ข้อมูลส่วนตัว - <?php echo SITE_NAME; ?></title>
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Profile Card Styles */
        .profile-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
        }

        .profile-avatar-large {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            flex-shrink: 0;
            box-shadow: 0 8px 25px rgba(255, 255, 255, 0.3);
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .profile-id {
            font-size: 1.1rem;
            margin-bottom: 15px;
            opacity: 0.9;
        }

        .profile-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .profile-body {
            padding: 40px;
        }

        /* แสดงข้อมูลแบบ Grid 2x3 */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 40px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid #667eea;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .info-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            color: #667eea;
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .info-value {
            font-size: 1.1rem;
            color: #333;
            min-height: 26px;
        }

        /* Form fields in edit mode */
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
            display: none;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-control.show {
            display: block;
        }

        .info-value.edit-mode {
            display: none;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 20px;
            padding-right: 45px;
            cursor: pointer;
        }

        /* Button Styles */
        .button-actions {
            text-align: center;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .edit-actions {
            display: none;
            justify-content: center;
            gap: 15px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 1px solid #e9ecef;
        }

        .edit-actions.show {
            display: flex;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-edit {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
        }

        .btn-edit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .btn-success:hover {
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6b7280, #4b5563);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 114, 128, 0.3);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: #333;
        }

        .btn-warning:hover {
            box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }

        .stat-info {
            text-align: left;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 1rem;
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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1002;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px 20px 0 0;
            text-align: center;
            position: relative;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 25px;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }

        .modal-body {
            padding: 30px;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #667eea;
            font-size: 0.9rem;
            margin-bottom: 8px;
        }

        .form-group label .required {
            color: red;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 5px;
            display: none;
        }

        .field-error {
            border-color: #dc3545 !important;
        }

        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

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

            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .profile-name {
                font-size: 2rem;
            }

            .button-actions {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .edit-actions {
                flex-direction: column;
                align-items: center;
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
    <!-- Top Header -->
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
                <h1>ระบบข้อร้องเรียนนักศึกษา</h1>
                <p>ระบบข้อร้องเรียนสำหรับนักศึกษา</p>
            </div>
        </div>

        <div class="header-right">
            <div class="header-notification" id="notificationButton" onclick="toggleNotificationDropdown()">
                <span style="font-size: 18px;">🔔</span>
                <span class="notification-badge<?php echo $unreadCount > 0 ? '' : ' zero'; ?>" id="notificationBadge">
                    <?php echo $unreadCount; ?>
                </span>
            </div>

            <div class="user-menu">
                <div class="user-avatar">👨‍🎓</div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($user['Stu_name'] ?? 'ผู้ใช้'); ?></span>
                    <span class="user-role">นักศึกษา</span>
                </div>
            </div>
        </div>
    </header>

    <!-- Include Sidebar -->
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

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <!-- Profile Card -->
            <div class="profile-container">
                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-avatar-large">👨‍🎓</div>
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($user['Stu_name'] ?? 'ไม่ระบุชื่อ'); ?></div>
                        <div class="profile-id">รหัสนักศึกษา: <?php echo htmlspecialchars($user['Stu_id'] ?? 'N/A'); ?></div>
                        <div class="profile-status">
                            <span style="width: 8px; height: 8px; border-radius: 50%; background: #28a745;"></span>
                            กำลังศึกษา
                        </div>
                        <div class="profile-admission">
                            <?php echo htmlspecialchars(($user['faculty_name'] ?? 'ไม่ระบุคณะ') . ' - ' . ($user['major_name'] ?? 'ไม่ระบุสาขา')); ?>
                        </div>
                    </div>
                </div>

                <!-- Profile Body -->
                <div class="profile-body">
                    <form method="POST" action="" id="profileForm">
                        <!-- แสดงข้อมูลแบบ Grid 2x3 -->
                        <div class="info-grid">
                            <!-- รหัสนักศึกษา -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>🆔</span>
                                    <span>รหัสนักศึกษา</span>
                                </div>
                                <div class="info-value" id="student_id-display">
                                    <?php echo htmlspecialchars($user['Stu_id'] ?? 'N/A'); ?>
                                </div>
                            </div>

                            <!-- ชื่อ-นามสกุล -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>👤</span>
                                    <span>ชื่อ-นามสกุล <span class="required">*</span></span>
                                </div>
                                <div class="info-value" id="full_name-display">
                                    <?php echo htmlspecialchars($user['Stu_name'] ?? 'ไม่ระบุ'); ?>
                                </div>
                                <input type="text" class="form-control" name="full_name" id="full_name-input"
                                    value="<?php echo htmlspecialchars($user['Stu_name'] ?? ''); ?>"
                                    placeholder="กรอกชื่อ-นามสกุลของคุณ" required maxlength="100">
                            </div>

                            <!-- คณะ -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>🏛️</span>
                                    <span>คณะ <span class="required">*</span></span>
                                </div>
                                <div class="info-value" id="faculty-display">
                                    <?php echo htmlspecialchars($user['faculty_name'] ?? 'ไม่ระบุคณะ'); ?>
                                </div>
                                <select class="form-control" name="faculty_id" id="faculty-input" required onchange="loadMajors()">
                                    <option value="">เลือกคณะ</option>
                                    <?php foreach ($faculties as $faculty): ?>
                                        <option value="<?php echo $faculty['Unit_id']; ?>"
                                            <?php echo ($currentFacultyId == $faculty['Unit_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($faculty['Unit_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- สาขาวิชา -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>📚</span>
                                    <span>สาขาวิชา <span class="required">*</span></span>
                                </div>
                                <div class="info-value" id="major-display">
                                    <?php echo htmlspecialchars($user['major_name'] ?? 'ไม่ระบุสาขา'); ?>
                                </div>
                                <select class="form-control" name="major_id" id="major-input" required>
                                    <option value="">เลือกสาขาวิชา</option>
                                    <?php foreach ($currentMajors as $major): ?>
                                        <option value="<?php echo $major['Unit_id']; ?>"
                                            <?php echo ($user['Unit_id'] == $major['Unit_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($major['Unit_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- เบอร์โทรศัพท์ -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>📞</span>
                                    <span>เบอร์โทรศัพท์</span>
                                </div>
                                <div class="info-value" id="phone-display">
                                    <?php echo !empty($user['Stu_tel']) ? htmlspecialchars($user['Stu_tel']) : 'ไม่ระบุ'; ?>
                                </div>
                                <input type="tel" class="form-control" name="phone" id="phone-input"
                                    value="<?php echo htmlspecialchars($user['Stu_tel'] ?? ''); ?>"
                                    placeholder="เช่น 0812345678" maxlength="10">
                            </div>

                            <!-- อีเมล -->
                            <div class="info-item">
                                <div class="info-label">
                                    <span>📧</span>
                                    <span>อีเมล</span>
                                </div>
                                <div class="info-value" id="email-display">
                                    <?php echo !empty($user['Stu_email']) ? htmlspecialchars($user['Stu_email']) : 'ไม่ระบุ'; ?>
                                </div>
                                <input type="email" class="form-control" name="email" id="email-input"
                                    value="<?php echo htmlspecialchars($user['Stu_email'] ?? ''); ?>"
                                    placeholder="เช่น student@rmuti.ac.th">
                            </div>
                        </div>

                        <!-- ปุ่มในโหมดปกติ -->
                        <div class="button-actions" id="normalActions">
                            <button type="button" class="btn btn-edit" id="editToggleBtn" onclick="toggleEditMode()">
                                <span>✏️</span>
                                <span>แก้ไขข้อมูล</span>
                            </button>
                            <button type="button" class="btn btn-warning" onclick="openChangePasswordModal()">
                                <span>🔒</span>
                                <span>เปลี่ยนรหัสผ่าน</span>
                            </button>
                        </div>

                        <!-- ปุ่มในโหมดแก้ไข -->
                        <div class="edit-actions" id="editActions">
                            <button type="submit" name="update_profile" class="btn btn-success">
                                <span>💾</span>
                                <span>บันทึกการแก้ไข</span>
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="cancelEdit()">
                                <span>❌</span>
                                <span>ยกเลิก</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-icon">📋</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">ข้อร้องเรียนที่ส่ง</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">⏳</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">กำลังดำเนินการ</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">✅</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['completed']; ?></div>
                        <div class="stat-label">เสร็จสิ้นแล้ว</div>
                    </div>
                </div>
                <div class="stat-card">
                    <span class="stat-icon">⭐</span>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo $stats['avg_rating']; ?></div>
                        <div class="stat-label">คะแนนเฉลี่ย</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>🔒 เปลี่ยนรหัสผ่าน</h2>
                <button class="modal-close" onclick="closeChangePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="changePasswordForm">
                    <div class="form-group">
                        <label>รหัสผ่านปัจจุบัน <span class="required">*</span></label>
                        <div class="password-container">
                            <input type="password" class="form-control" name="current_password" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('current_password')">
                                👁️
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>รหัสผ่านใหม่ <span class="required">*</span></label>
                        <div class="password-container">
                            <input type="password" class="form-control" name="new_password" required minlength="6" maxlength="10">
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('new_password')">
                                👁️
                            </button>
                        </div>
                        <small style="color: #666; font-size: 0.85rem;">รหัสผ่านต้องมีความยาว 6-10 ตัวอักษร</small>
                    </div>

                    <div class="form-group">
                        <label>ยืนยันรหัสผ่านใหม่ <span class="required">*</span></label>
                        <div class="password-container">
                            <input type="password" class="form-control" name="confirm_password" required>
                            <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                                👁️
                            </button>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 30px;">
                        <button type="submit" name="change_password" class="btn" style="width: 100%; margin-bottom: 15px;">
                            🔒 เปลี่ยนรหัสผ่าน
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeChangePasswordModal()" style="width: 100%;">
                            ยกเลิก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toast"></div>

    <script>
        // Global variables
        let isEditMode = false;
        const editableFields = ['full_name', 'faculty', 'major', 'phone', 'email'];
        let currentUnreadCount = <?php echo $unreadCount; ?>;
        let notificationDropdownOpen = false;
        let notificationCheckInterval;

        // Initialize everything when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🚀 Profile page loaded and initialized');

            // Show server message if exists
            <?php if (!empty($message)): ?>
                console.log('📢 Server message:', <?php echo json_encode($message); ?>);
                showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($messageType); ?>);
            <?php endif; ?>

            // Setup form handlers
            setupFormHandlers();

            // Initialize sidebar for desktop
            initializeSidebar();
        });

        // =================== EDIT MODE FUNCTIONS ===================

        function toggleEditMode() {
            console.log('🔓 Toggle edit mode called');

            if (isEditMode) {
                console.log('Already in edit mode');
                return;
            }

            enterEditMode();
        }

        function enterEditMode() {
            console.log('🔧 Entering edit mode');
            isEditMode = true;

            // Hide normal actions
            const normalActions = document.getElementById('normalActions');
            if (normalActions) {
                normalActions.style.display = 'none';
            }

            // Show edit actions
            const editActions = document.getElementById('editActions');
            if (editActions) {
                editActions.classList.add('show');
            }

            // Switch fields to edit mode
            const fields = ['full_name', 'faculty', 'major', 'phone', 'email'];
            fields.forEach(fieldName => {
                const display = document.getElementById(fieldName + '-display');
                const input = document.getElementById(fieldName + '-input');

                if (display && input) {
                    display.classList.add('edit-mode');
                    input.classList.add('show');
                }
            });

            console.log('✅ Edit mode activated');
        }

        function cancelEdit() {
            console.log('❌ Canceling edit mode');
            isEditMode = false;

            // Show normal actions
            const normalActions = document.getElementById('normalActions');
            if (normalActions) {
                normalActions.style.display = 'flex';
            }

            // Hide edit actions
            const editActions = document.getElementById('editActions');
            if (editActions) {
                editActions.classList.remove('show');
            }

            // Switch fields back to display mode
            const fields = ['full_name', 'faculty', 'major', 'phone', 'email'];
            fields.forEach(fieldName => {
                const display = document.getElementById(fieldName + '-display');
                const input = document.getElementById(fieldName + '-input');

                if (display && input) {
                    display.classList.remove('edit-mode');
                    input.classList.remove('show');

                    // Reset form values to original
                    const form = document.getElementById('profileForm');
                    if (form) {
                        form.reset();
                        loadMajors(); // Reload majors if needed
                    }
                }
            });

            console.log('✅ Edit mode cancelled');
        }

        // =================== FORM HANDLING ===================

        function setupFormHandlers() {
            const form = document.getElementById('profileForm');
            if (!form) {
                console.error('❌ Profile form not found!');
                return;
            }

            // Auto-format phone input
            const phoneInput = form.querySelector('input[name="phone"]');
            if (phoneInput) {
                phoneInput.addEventListener('input', function() {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length > 10) value = value.substring(0, 10);
                    this.value = value;
                });
            }

            console.log('✅ Form handlers set up successfully');
        }

        // =================== MAJOR LOADING FUNCTION ===================

        function loadMajors() {
            const facultySelect = document.querySelector('select[name="faculty_id"]');
            const majorSelect = document.querySelector('select[name="major_id"]');

            if (!facultySelect || !majorSelect) {
                return;
            }

            const selectedFacultyId = facultySelect.value;
            console.log('🔄 Loading majors for faculty:', selectedFacultyId);

            // Clear current options
            majorSelect.innerHTML = '<option value="">เลือกสาขาวิชา</option>';

            if (!selectedFacultyId) {
                return;
            }

            // Show loading
            majorSelect.innerHTML = '<option value="">กำลังโหลด...</option>';
            majorSelect.disabled = true;

            // Fetch majors
            fetch(`?ajax=get_majors&faculty_id=${selectedFacultyId}`)
                .then(response => response.json())
                .then(majors => {
                    majorSelect.innerHTML = '<option value="">เลือกสาขาวิชา</option>';
                    majorSelect.disabled = false;

                    if (majors && majors.length > 0) {
                        majors.forEach(major => {
                            const option = document.createElement('option');
                            option.value = major.Unit_id;
                            option.textContent = major.Unit_name;

                            // Select current major if it matches
                            if (major.Unit_id == '<?php echo $user['Unit_id'] ?? ''; ?>') {
                                option.selected = true;
                            }

                            majorSelect.appendChild(option);
                        });
                        console.log(`✅ ${majors.length} majors loaded`);
                    } else {
                        majorSelect.innerHTML = '<option value="">ไม่พบสาขาในคณะนี้</option>';
                    }
                })
                .catch(error => {
                    console.error('Error loading majors:', error);
                    majorSelect.innerHTML = '<option value="">เกิดข้อผิดพลาด</option>';
                    majorSelect.disabled = false;
                });
        }

        // =================== UTILITY FUNCTIONS ===================

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type}`;
            toast.classList.add('show');

            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        // =================== PASSWORD MODAL FUNCTIONS ===================

        function openChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.remove('show');
            document.body.style.overflow = '';
            clearPasswordForm();
        }

        function clearPasswordForm() {
            const form = document.getElementById('changePasswordForm');
            if (form) {
                form.reset();
            }
        }

        function togglePasswordVisibility(fieldName) {
            const field = document.querySelector(`input[name="${fieldName}"]`);
            const toggle = field.nextElementSibling;

            if (field.type === 'password') {
                field.type = 'text';
                toggle.textContent = '🙈';
            } else {
                field.type = 'password';
                toggle.textContent = '👁️';
            }
        }

        // =================== SIDEBAR FUNCTIONS ===================

        function initializeSidebar() {
            if (window.innerWidth >= 1024) {
                setTimeout(() => {
                    openSidebar();
                }, 500);
            }
        }

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const isOpen = sidebar && sidebar.classList.contains('show');

            if (isOpen) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }

        function openSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggle = document.querySelector('.mobile-menu-toggle');

            if (sidebar) {
                sidebar.classList.add('show');
            }
            if (toggle) {
                toggle.classList.add('active');
            }

            if (window.innerWidth >= 1024) {
                // Desktop
                if (mainContent) {
                    mainContent.classList.add('shifted');
                }
            } else {
                // Mobile
                if (overlay) {
                    overlay.classList.add('show');
                }
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');
            const toggle = document.querySelector('.mobile-menu-toggle');

            if (sidebar) {
                sidebar.classList.remove('show');
            }
            if (overlay) {
                overlay.classList.remove('show');
            }
            if (toggle) {
                toggle.classList.remove('active');
            }
            if (mainContent) {
                mainContent.classList.remove('shifted');
            }
        }

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const mainContent = document.querySelector('.main-content');

            if (window.innerWidth >= 1024) {
                if (overlay) {
                    overlay.classList.remove('show');
                }
                if (sidebar && sidebar.classList.contains('show')) {
                    if (mainContent) {
                        mainContent.classList.add('shifted');
                    }
                }
            } else {
                if (mainContent) {
                    mainContent.classList.remove('shifted');
                }
                if (sidebar && sidebar.classList.contains('show')) {
                    if (overlay) {
                        overlay.classList.add('show');
                    }
                }
            }
        });

        // =================== DUMMY NOTIFICATION FUNCTIONS ===================
        function toggleNotificationDropdown() {
            // Placeholder function for notification dropdown
            console.log('Notification dropdown clicked');
        }

        // Modal handlers
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('changePasswordModal');
            if (e.target === modal) {
                closeChangePasswordModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeChangePasswordModal();
            }
        });
    </script>
</body>

</html>