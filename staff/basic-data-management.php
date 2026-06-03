<?php
// staff/basic-data-management.php - หน้าจัดการข้อมูลพื้นฐาน
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์ (เฉพาะ Admin)
requireLogin();
requireRole(['teacher']);
requirePermission(3); // เฉพาะ Admin

$db = getDB();
$user = getCurrentUser();

// ตัวแปรสำหรับข้อความแจ้งเตือน
$message = '';
$messageType = 'success';
$activeTab = $_GET['tab'] ?? 'overview';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        switch ($action) {
            case 'add_complaint_type':
                $typeName = trim($_POST['type_name']);
                $typeIcon = trim($_POST['type_icon']);
                $typeDescription = trim($_POST['type_description'] ?? '');

                if (empty($typeName)) {
                    throw new Exception('กรุณากรอกชื่อประเภทข้อร้องเรียน');
                }

                // ตรวจสอบชื่อซ้ำ
                $existing = $db->fetch("SELECT Type_id FROM type WHERE Type_infor = ?", [$typeName]);
                if ($existing) {
                    throw new Exception('ประเภทข้อร้องเรียนนี้มีอยู่แล้ว');
                }

                $result = $db->insert('type', [
                    'Type_infor' => $typeName,
                    'Type_icon' => $typeIcon ?: '📋',
                    'Type_description' => $typeDescription,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                if ($result) {
                    $message = 'เพิ่มประเภทข้อร้องเรียนเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_complaint_type', 'เพิ่มประเภท: ' . $typeName);
                }
                break;

            case 'edit_complaint_type':
                $typeId = intval($_POST['type_id']);
                $typeName = trim($_POST['type_name']);
                $typeIcon = trim($_POST['type_icon']);
                $typeDescription = trim($_POST['type_description'] ?? '');

                if (empty($typeName)) {
                    throw new Exception('กรุณากรอกชื่อประเภทข้อร้องเรียน');
                }

                $result = $db->update('type', [
                    'Type_infor' => $typeName,
                    'Type_icon' => $typeIcon ?: '📋',
                    'Type_description' => $typeDescription,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'Type_id = ?', [$typeId]);

                if ($result) {
                    $message = 'แก้ไขประเภทข้อร้องเรียนเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'edit_complaint_type', 'แก้ไขประเภท ID: ' . $typeId);
                }
                break;

            case 'delete_complaint_type':
                $typeId = intval($_POST['type_id']);

                // ตรวจสอบว่ามีข้อร้องเรียนใช้ประเภทนี้หรือไม่
                $usage = $db->count('request', 'Type_id = ?', [$typeId]);
                if ($usage > 0) {
                    throw new Exception('ไม่สามารถลบได้ เนื่องจากมีข้อร้องเรียนใช้ประเภทนี้อยู่ ' . $usage . ' รายการ');
                }

                $result = $db->delete('type', 'Type_id = ?', [$typeId]);
                if ($result) {
                    $message = 'ลบประเภทข้อร้องเรียนเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'delete_complaint_type', 'ลบประเภท ID: ' . $typeId);
                }
                break;

            case 'add_organization_unit':
                $unitName = trim($_POST['unit_name']);
                $unitType = $_POST['unit_type'];
                $unitIcon = trim($_POST['unit_icon']);
                $unitParentId = !empty($_POST['unit_parent_id']) ? intval($_POST['unit_parent_id']) : null;
                $unitPhone = trim($_POST['unit_phone'] ?? '');
                $unitEmail = trim($_POST['unit_email'] ?? '');

                if (empty($unitName) || empty($unitType)) {
                    throw new Exception('กรุณากรอกข้อมูลให้ครบถ้วน');
                }

                $result = $db->insert('organization_unit', [
                    'Unit_name' => $unitName,
                    'Unit_type' => $unitType,
                    'Unit_icon' => $unitIcon ?: '🏢',
                    'Unit_parent_id' => $unitParentId,
                    'Unit_phone' => $unitPhone,
                    'Unit_email' => $unitEmail,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                if ($result) {
                    $message = 'เพิ่มหน่วยงานเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_organization_unit', 'เพิ่มหน่วยงาน: ' . $unitName);
                }
                break;

            case 'edit_organization_unit':
                $unitId = intval($_POST['unit_id']);
                $unitName = trim($_POST['unit_name']);
                $unitType = $_POST['unit_type'];
                $unitIcon = trim($_POST['unit_icon']);
                $unitParentId = !empty($_POST['unit_parent_id']) ? intval($_POST['unit_parent_id']) : null;
                $unitPhone = trim($_POST['unit_phone'] ?? '');
                $unitEmail = trim($_POST['unit_email'] ?? '');

                $result = $db->update('organization_unit', [
                    'Unit_name' => $unitName,
                    'Unit_type' => $unitType,
                    'Unit_icon' => $unitIcon ?: '🏢',
                    'Unit_parent_id' => $unitParentId,
                    'Unit_phone' => $unitPhone,
                    'Unit_email' => $unitEmail,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'Unit_id = ?', [$unitId]);

                if ($result) {
                    $message = 'แก้ไขหน่วยงานเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'edit_organization_unit', 'แก้ไขหน่วยงาน ID: ' . $unitId);
                }
                break;

            case 'delete_organization_unit':
                $unitId = intval($_POST['unit_id']);

                // ตรวจสอบการใช้งาน
                $studentUsage = $db->count('student', 'Unit_id = ?', [$unitId]);
                $childUnits = $db->count('organization_unit', 'Unit_parent_id = ?', [$unitId]);

                if ($studentUsage > 0 || $childUnits > 0) {
                    throw new Exception('ไม่สามารถลบได้ เนื่องจากมีข้อมูลที่เกี่ยวข้อง');
                }

                $result = $db->delete('organization_unit', 'Unit_id = ?', [$unitId]);
                if ($result) {
                    $message = 'ลบหน่วยงานเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'delete_organization_unit', 'ลบหน่วยงาน ID: ' . $unitId);
                }
                break;

            case 'add_staff':
                $staffId = trim($_POST['staff_id']);
                $staffName = trim($_POST['staff_name']);
                $staffPosition = trim($_POST['staff_position']);
                $staffPermission = intval($_POST['staff_permission']);
                $staffPassword = trim($_POST['staff_password']);
                $staffEmail = trim($_POST['staff_email'] ?? '');
                $staffPhone = trim($_POST['staff_phone'] ?? '');

                if (empty($staffId) || empty($staffName) || empty($staffPassword)) {
                    throw new Exception('กรุณากรอกข้อมูลให้ครบถ้วน');
                }

                // ตรวจสอบรหัสซ้ำ
                $existing = $db->fetch("SELECT Aj_id FROM teacher WHERE Aj_id = ?", [$staffId]);
                if ($existing) {
                    throw new Exception('รหัสเจ้าหน้าที่นี้มีอยู่แล้ว');
                }

                $result = $db->insert('teacher', [
                    'Aj_id' => $staffId,
                    'Aj_name' => $staffName,
                    'Aj_position' => $staffPosition,
                    'Aj_per' => $staffPermission,
                    'Aj_password' => $staffPassword, // ในการใช้งานจริงควร hash password
                    'Aj_email' => $staffEmail,
                    'Aj_phone' => $staffPhone,
                    'Aj_status' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                if ($result) {
                    $message = 'เพิ่มเจ้าหน้าที่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_staff', 'เพิ่มเจ้าหน้าที่: ' . $staffName);
                }
                break;

            case 'toggle_staff_status':
                $staffId = intval($_POST['staff_id']);
                $newStatus = intval($_POST['new_status']);

                $result = $db->update('teacher', [
                    'Aj_status' => $newStatus,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'Aj_id = ?', [$staffId]);

                if ($result) {
                    $statusText = $newStatus ? 'เปิดใช้งาน' : 'ระงับการใช้งาน';
                    $message = $statusText . 'บัญชีเจ้าหน้าที่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'toggle_staff_status', $statusText . ' เจ้าหน้าที่ ID: ' . $staffId);
                }
                break;

            case 'system_backup':
                // สร้างไฟล์ backup
                $backupFile = createSystemBackup();
                if ($backupFile) {
                    $message = 'สร้างไฟล์สำรองข้อมูลเรียบร้อยแล้ว: ' . $backupFile;
                    logActivity($_SESSION['user_id'], 'system_backup', 'สร้างไฟล์สำรองข้อมูล');
                } else {
                    throw new Exception('ไม่สามารถสร้างไฟล์สำรองข้อมูลได้');
                }
                break;

            case 'clear_logs':
                $daysOld = intval($_POST['days_old'] ?? 30);
                $cutoffDate = date('Y-m-d', strtotime("-{$daysOld} days"));

                // ลบ logs เก่า (ถ้ามีตาราง logs)
                $deletedCount = clearOldLogs($cutoffDate);
                $message = "ลบ logs เก่ากว่า {$daysOld} วันเรียบร้อยแล้ว (จำนวน: {$deletedCount} รายการ)";
                logActivity($_SESSION['user_id'], 'clear_logs', "ลบ logs เก่ากว่า {$daysOld} วัน");
                break;

            default:
                throw new Exception('การดำเนินการไม่ถูกต้อง');
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $messageType = 'error';
        error_log("Basic data management error: " . $e->getMessage());
    }
}

// ดึงข้อมูลสำหรับแสดงผล
try {
    // สถิติระบบ
    $systemStats = [
        'total_students' => $db->count('student'),
        'active_students' => $db->count('student', 'Stu_status = 1'),
        'total_staff' => $db->count('teacher'),
        'active_staff' => $db->count('teacher', 'Aj_status = 1'),
        'total_requests' => $db->count('request'),
        'pending_requests' => $db->count('request', 'Re_status = "0"'),
        'complaint_types' => $db->count('type'),
        'organization_units' => $db->count('organization_unit')
    ];

    // ข้อมูลประเภทข้อร้องเรียน
    $complaintTypes = $db->fetchAll("
        SELECT t.*, COUNT(r.Re_id) as usage_count 
        FROM type t 
        LEFT JOIN request r ON t.Type_id = r.Type_id 
        GROUP BY t.Type_id 
        ORDER BY t.Type_infor ASC
    ");

    // ข้อมูลหน่วยงาน
    $organizationUnits = $db->fetchAll("
        SELECT u1.*, u2.Unit_name as parent_name,
               COUNT(DISTINCT s.Stu_id) as student_count,
               COUNT(DISTINCT u3.Unit_id) as child_count
        FROM organization_unit u1
        LEFT JOIN organization_unit u2 ON u1.Unit_parent_id = u2.Unit_id
        LEFT JOIN student s ON u1.Unit_id = s.Unit_id
        LEFT JOIN organization_unit u3 ON u1.Unit_id = u3.Unit_parent_id
        GROUP BY u1.Unit_id
        ORDER BY u1.Unit_type ASC, u1.Unit_name ASC
    ");

    // ข้อมูลเจ้าหน้าที่
    $staffList = $db->fetchAll("
        SELECT t.*, 
               COUNT(DISTINCT r1.Re_id) as assigned_requests,
               COUNT(DISTINCT r2.Re_id) as completed_requests
        FROM teacher t
        LEFT JOIN request r1 ON t.Aj_id = r1.Aj_id
        LEFT JOIN request r2 ON t.Aj_id = r2.Aj_id AND r2.Re_status IN ('2', '3')
        GROUP BY t.Aj_id
        ORDER BY t.Aj_per DESC, t.Aj_name ASC
    ");

    // ข้อมูลนักศึกษาล่าสุด
    $recentStudents = $db->fetchAll("
        SELECT s.*, u1.Unit_name as major_name, u2.Unit_name as faculty_name
        FROM student s
        LEFT JOIN organization_unit u1 ON s.Unit_id = u1.Unit_id
        LEFT JOIN organization_unit u2 ON u1.Unit_parent_id = u2.Unit_id
        ORDER BY s.created_at DESC
        LIMIT 10
    ");

    // สถิติการใช้งานระบบ
    $usageStats = [
        'today_requests' => $db->count('request', 'DATE(Re_date) = CURDATE()'),
        'week_requests' => $db->count('request', 'YEARWEEK(Re_date, 1) = YEARWEEK(CURDATE(), 1)'),
        'month_requests' => $db->count('request', 'YEAR(Re_date) = YEAR(CURDATE()) AND MONTH(Re_date) = MONTH(CURDATE())'),
        'today_registrations' => $db->count('student', 'DATE(created_at) = CURDATE()'),
        'avg_response_time' => $db->fetch("SELECT AVG(TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date)) as avg_hours FROM request r JOIN save_request sr ON r.Re_id = sr.Re_id WHERE r.Re_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")['avg_hours'] ?? 0
    ];
} catch (Exception $e) {
    error_log("Data loading error: " . $e->getMessage());
    $systemStats = [];
    $complaintTypes = [];
    $organizationUnits = [];
    $staffList = [];
    $recentStudents = [];
    $usageStats = [];
}

// ฟังก์ชันช่วยเหลือ
function createSystemBackup()
{
    $backupDir = '../backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . $filename;

    // คำสั่ง mysqldump (ต้องปรับตามระบบ)
    $command = "mysqldump -h " . DB_HOST . " -u " . DB_USER . " -p" . DB_PASS . " " . DB_NAME . " > " . $filepath;

    // ในการใช้งานจริง ควรใช้ PHP เพื่อสร้าง backup แทน
    // exec($command, $output, $return_var);

    // สำหรับ demo จะสร้างไฟล์ว่าง
    file_put_contents($filepath, "-- System Backup Created at " . date('Y-m-d H:i:s') . "\n");

    return file_exists($filepath) ? $filename : false;
}

function clearOldLogs($cutoffDate)
{
    global $db;

    try {
        // ลบ logs เก่า (ถ้ามีตาราง system_logs)
        $result = $db->execute("DELETE FROM system_logs WHERE created_at < ?", [$cutoffDate]);
        return $result ? $db->affectedRows() : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function logActivity($userId, $action, $details, $relatedId = null)
{
    global $db;

    try {
        // บันทึก activity log (ถ้ามีตาราง activity_logs)
        $db->insert('activity_logs', [
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'related_id' => $relatedId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        error_log("Log activity error: " . $e->getMessage());
    }
}

include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการข้อมูลพื้นฐาน - <?php echo SITE_SHORT_NAME; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .page-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 5px solid #667eea;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 1.1rem;
            color: #666;
            font-weight: 500;
        }

        .tabs-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .tabs-header {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            overflow-x: auto;
        }

        .tab-button {
            padding: 18px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
            white-space: nowrap;
            position: relative;
        }

        .tab-button:hover {
            background: #e9ecef;
            color: #333;
        }

        .tab-button.active {
            background: white;
            color: #667eea;
            font-weight: 600;
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: #667eea;
        }

        .tab-content {
            padding: 30px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .data-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-success {
            background: #48bb78;
            color: white;
        }

        .btn-warning {
            background: #ed8936;
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: #dc3545;
            color: white;
        }

        .btn-secondary:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.8rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .form-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }

        .form-section h3 {
            margin-bottom: 20px;
            color: #333;
            font-size: 1.3rem;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .usage-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            background: #e9ecef;
            border-radius: 15px;
            font-size: 0.8rem;
            color: #666;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #999;
        }

        .close:hover {
            color: #333;
        }

        .permission-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .permission-1 {
            background: #bee3f8;
            color: #2b6cb0;
        }

        .permission-2 {
            background: #d69e2e;
            color: white;
        }

        .permission-3 {
            background: #f56565;
            color: white;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 10px;
            }

            .page-title {
                font-size: 2rem;
            }

            .stats-overview {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
            }

            .tabs-header {
                flex-wrap: wrap;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <style>
        /* Global Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>

<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-container">
        <div class="page-header">
            <h1 class="page-title">
                <span>⚙️</span>
                จัดการข้อมูลพื้นฐาน
            </h1>
            <p class="page-subtitle">
                จัดการประเภทข้อร้องเรียน หน่วยงาน เจ้าหน้าที่ และการตั้งค่าระบบ
            </p>
        </div>

        <?php if ($message): ?>
            <div class="alert <?php echo $messageType === 'error' ? 'alert-error' : 'alert-success'; ?>">
                <span><?php echo $messageType === 'error' ? '❌' : '✅'; ?></span>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- System Overview Stats -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['total_students'] ?? 0); ?></div>
                <div class="stat-label">👨‍🎓 นักศึกษาทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['active_staff'] ?? 0); ?></div>
                <div class="stat-label">👨‍🏫 เจ้าหน้าที่ที่ใช้งาน</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['total_requests'] ?? 0); ?></div>
                <div class="stat-label">📝 ข้อร้องเรียนทั้งหมด</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['pending_requests'] ?? 0); ?></div>
                <div class="stat-label">⏳ รอดำเนินการ</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['complaint_types'] ?? 0); ?></div>
                <div class="stat-label">📋 ประเภทข้อร้องเรียน</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($systemStats['organization_units'] ?? 0); ?></div>
                <div class="stat-label">🏢 หน่วยงาน</div>
            </div>
        </div>

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" onclick="showTab('overview')">
                    📊 ภาพรวมระบบ
                </button>
                <button class="tab-button <?php echo $activeTab === 'complaint_types' ? 'active' : ''; ?>" onclick="showTab('complaint_types')">
                    📋 ประเภทข้อร้องเรียน
                </button>
                <button class="tab-button <?php echo $activeTab === 'organizations' ? 'active' : ''; ?>" onclick="showTab('organizations')">
                    🏢 หน่วยงาน
                </button>
                <button class="tab-button <?php echo $activeTab === 'staff' ? 'active' : ''; ?>" onclick="showTab('staff')">
                    👨‍🏫 เจ้าหน้าที่
                </button>
                <button class="tab-button <?php echo $activeTab === 'students' ? 'active' : ''; ?>" onclick="showTab('students')">
                    👨‍🎓 นักศึกษา
                </button>
                <button class="tab-button <?php echo $activeTab === 'system' ? 'active' : ''; ?>" onclick="showTab('system')">
                    🔧 ระบบ
                </button>
            </div>

            <!-- Overview Tab -->
            <div id="overview" class="tab-content <?php echo $activeTab === 'overview' ? 'active' : ''; ?>">
                <h2 class="section-title">📊 ภาพรวมการใช้งานระบบ</h2>

                <div class="form-grid">
                    <div class="form-section">
                        <h3>📈 สถิติวันนี้</h3>
                        <p><strong>ข้อร้องเรียนใหม่:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo $usageStats['today_requests'] ?? 0; ?></span> รายการ</p>
                        <p><strong>การลงทะเบียนใหม่:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo $usageStats['today_registrations'] ?? 0; ?></span> คน</p>
                    </div>

                    <div class="form-section">
                        <h3>📊 สถิติสัปดาห์นี้</h3>
                        <p><strong>ข้อร้องเรียน:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo $usageStats['week_requests'] ?? 0; ?></span> รายการ</p>
                        <p><strong>เวลาตอบสนองเฉลี่ย:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo round($usageStats['avg_response_time'] ?? 0, 1); ?></span> ชั่วโมง</p>
                    </div>

                    <div class="form-section">
                        <h3>📆 สถิติเดือนนี้</h3>
                        <p><strong>ข้อร้องเรียนทั้งหมด:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo $usageStats['month_requests'] ?? 0; ?></span> รายการ</p>
                        <p><strong>อัตราความสำเร็จ:</strong> <span class="stat-number" style="font-size: 1.5rem;">
                                <?php
                                $totalMonth = $usageStats['month_requests'] ?? 0;
                                $completedMonth = $db->count('request', 'YEAR(Re_date) = YEAR(CURDATE()) AND MONTH(Re_date) = MONTH(CURDATE()) AND Re_status IN ("2", "3")');
                                echo $totalMonth > 0 ? round(($completedMonth / $totalMonth) * 100, 1) : 0;
                                ?>%
                            </span></p>
                    </div>
                </div>

                <?php if (!empty($recentStudents)): ?>
                    <h3 class="section-title">👨‍🎓 นักศึกษาที่ลงทะเบียนล่าสุด</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>รหัสนักศึกษา</th>
                                <th>ชื่อ-นามสกุล</th>
                                <th>คณะ</th>
                                <th>สาขา</th>
                                <th>วันที่ลงทะเบียน</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentStudents as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['Stu_id']); ?></td>
                                    <td><?php echo htmlspecialchars($student['Stu_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['faculty_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($student['major_name'] ?? ''); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($student['created_at'] ?? 'now')); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $student['Stu_status'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $student['Stu_status'] ? 'ใช้งาน' : 'ระงับ'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Complaint Types Tab -->
            <div id="complaint_types" class="tab-content <?php echo $activeTab === 'complaint_types' ? 'active' : ''; ?>">
                <div class="form-grid">
                    <div class="form-section">
                        <h3>➕ เพิ่มประเภทข้อร้องเรียนใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_complaint_type">
                            <div class="form-group">
                                <label>ชื่อประเภทข้อร้องเรียน</label>
                                <input type="text" name="type_name" class="form-control" placeholder="เช่น ปัญหาสิ่งแวดล้อม" required>
                            </div>
                            <div class="form-group">
                                <label>ไอคอน</label>
                                <input type="text" name="type_icon" class="form-control" placeholder="🌿" maxlength="10">
                            </div>
                            <div class="form-group">
                                <label>คำอธิบาย</label>
                                <textarea name="type_description" class="form-control" rows="3" placeholder="คำอธิบายเพิ่มเติม (ไม่บังคับ)"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span>➕</span> เพิ่มประเภทใหม่
                            </button>
                        </form>
                    </div>
                </div>

                <h2 class="section-title">📋 ประเภทข้อร้องเรียนทั้งหมด</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ไอคอน</th>
                            <th>ชื่อประเภท</th>
                            <th>คำอธิบาย</th>
                            <th>การใช้งาน</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($complaintTypes as $type): ?>
                            <tr>
                                <td style="font-size: 1.5rem;"><?php echo $type['Type_icon'] ?: '📋'; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($type['Type_infor']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($type['Type_description'] ?? ''); ?></td>
                                <td>
                                    <span class="usage-indicator">
                                        📊 <?php echo number_format($type['usage_count']); ?> รายการ
                                    </span>
                                </td>
                                <td>
                                    <button onclick="editComplaintType(<?php echo $type['Type_id']; ?>, '<?php echo addslashes($type['Type_infor']); ?>', '<?php echo addslashes($type['Type_icon']); ?>', '<?php echo addslashes($type['Type_description'] ?? ''); ?>')" class="btn btn-warning btn-sm">
                                        ✏️ แก้ไข
                                    </button>
                                    <?php if ($type['usage_count'] == 0): ?>
                                        <button onclick="deleteComplaintType(<?php echo $type['Type_id']; ?>)" class="btn btn-danger btn-sm">
                                            🗑️ ลบ
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Organizations Tab -->
            <div id="organizations" class="tab-content <?php echo $activeTab === 'organizations' ? 'active' : ''; ?>">
                <div class="form-grid">
                    <div class="form-section">
                        <h3>🏢 เพิ่มหน่วยงานใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_organization_unit">
                            <div class="form-group">
                                <label>ชื่อหน่วยงาน</label>
                                <input type="text" name="unit_name" class="form-control" placeholder="เช่น คณะวิศวกรรมศาสตร์" required>
                            </div>
                            <div class="form-group">
                                <label>ประเภท</label>
                                <select name="unit_type" class="form-control" required>
                                    <option value="">เลือกประเภท</option>
                                    <option value="faculty">คณะ</option>
                                    <option value="major">สาขาวิชา</option>
                                    <option value="department">แผนก/หน่วยงาน</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>หน่วยงานแม่</label>
                                <select name="unit_parent_id" class="form-control">
                                    <option value="">ไม่มี (หน่วยงานหลัก)</option>
                                    <?php foreach ($organizationUnits as $unit): ?>
                                        <?php if ($unit['Unit_type'] !== 'major'): ?>
                                            <option value="<?php echo $unit['Unit_id']; ?>">
                                                <?php echo htmlspecialchars($unit['Unit_name']); ?> (<?php echo $unit['Unit_type']; ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>ไอคอน</label>
                                <input type="text" name="unit_icon" class="form-control" placeholder="🏢" maxlength="10">
                            </div>
                            <div class="form-group">
                                <label>เบอร์โทร</label>
                                <input type="text" name="unit_phone" class="form-control" placeholder="043-123-456">
                            </div>
                            <div class="form-group">
                                <label>อีเมล</label>
                                <input type="email" name="unit_email" class="form-control" placeholder="contact@rmuti.ac.th">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span>🏢</span> เพิ่มหน่วยงาน
                            </button>
                        </form>
                    </div>
                </div>

                <h2 class="section-title">🏢 หน่วยงานทั้งหมด</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ไอคอน</th>
                            <th>ชื่อหน่วยงาน</th>
                            <th>ประเภท</th>
                            <th>หน่วยงานแม่</th>
                            <th>จำนวนนักศึกษา</th>
                            <th>หน่วยงานลูก</th>
                            <th>ติดต่อ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($organizationUnits as $unit): ?>
                            <tr>
                                <td style="font-size: 1.5rem;"><?php echo $unit['Unit_icon'] ?: '🏢'; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($unit['Unit_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="status-badge status-active">
                                        <?php
                                        $typeNames = ['faculty' => 'คณะ', 'major' => 'สาขา', 'department' => 'แผนก'];
                                        echo $typeNames[$unit['Unit_type']] ?? $unit['Unit_type'];
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($unit['parent_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($unit['student_count'] > 0): ?>
                                        <span class="usage-indicator">
                                            👨‍🎓 <?php echo number_format($unit['student_count']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($unit['child_count'] > 0): ?>
                                        <span class="usage-indicator">
                                            🏢 <?php echo number_format($unit['child_count']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($unit['Unit_phone'] || $unit['Unit_email']): ?>
                                        <div style="font-size: 0.9rem;">
                                            <?php if ($unit['Unit_phone']): ?>
                                                <div>📞 <?php echo htmlspecialchars($unit['Unit_phone']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($unit['Unit_email']): ?>
                                                <div>📧 <?php echo htmlspecialchars($unit['Unit_email']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button onclick="editOrganizationUnit(<?php echo $unit['Unit_id']; ?>)" class="btn btn-warning btn-sm">
                                        ✏️ แก้ไข
                                    </button>
                                    <?php if ($unit['student_count'] == 0 && $unit['child_count'] == 0): ?>
                                        <button onclick="deleteOrganizationUnit(<?php echo $unit['Unit_id']; ?>)" class="btn btn-danger btn-sm">
                                            🗑️ ลบ
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Staff Tab -->
            <div id="staff" class="tab-content <?php echo $activeTab === 'staff' ? 'active' : ''; ?>">
                <div class="form-grid">
                    <div class="form-section">
                        <h3>👨‍🏫 เพิ่มเจ้าหน้าที่ใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_staff">
                            <div class="form-group">
                                <label>รหัสเจ้าหน้าที่</label>
                                <input type="text" name="staff_id" class="form-control" placeholder="เช่น 001" required>
                            </div>
                            <div class="form-group">
                                <label>ชื่อ-นามสกุล</label>
                                <input type="text" name="staff_name" class="form-control" placeholder="เช่น นาย สมชาย ใจดี" required>
                            </div>
                            <div class="form-group">
                                <label>ตำแหน่ง</label>
                                <input type="text" name="staff_position" class="form-control" placeholder="เช่น เจ้าหน้าที่บริการการศึกษา">
                            </div>
                            <div class="form-group">
                                <label>ระดับสิทธิ์</label>
                                <select name="staff_permission" class="form-control" required>
                                    <option value="">เลือกระดับสิทธิ์</option>
                                    <option value="1">เจ้าหน้าที่ (ระดับ 1)</option>
                                    <option value="2">หัวหน้างาน (ระดับ 2)</option>
                                    <option value="3">ผู้ดูแลระบบ (ระดับ 3)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>รหัสผ่าน</label>
                                <input type="password" name="staff_password" class="form-control" placeholder="รหัสผ่านเริ่มต้น" required>
                            </div>
                            <div class="form-group">
                                <label>อีเมล</label>
                                <input type="email" name="staff_email" class="form-control" placeholder="staff@rmuti.ac.th">
                            </div>
                            <div class="form-group">
                                <label>เบอร์โทร</label>
                                <input type="text" name="staff_phone" class="form-control" placeholder="081-234-5678">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span>👨‍🏫</span> เพิ่มเจ้าหน้าที่
                            </button>
                        </form>
                    </div>
                </div>

                <h2 class="section-title">👨‍🏫 เจ้าหน้าที่ทั้งหมด</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>รหัส</th>
                            <th>ชื่อ-นามสกุล</th>
                            <th>ตำแหน่ง</th>
                            <th>ระดับสิทธิ์</th>
                            <th>ข้อร้องเรียนที่รับผิดชอบ</th>
                            <th>ประสิทธิภาพ</th>
                            <th>สถานะ</th>
                            <th>การจัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staffList as $staff): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($staff['Aj_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($staff['Aj_name']); ?></td>
                                <td><?php echo htmlspecialchars($staff['Aj_position'] ?? ''); ?></td>
                                <td>
                                    <span class="permission-badge permission-<?php echo $staff['Aj_per']; ?>">
                                        <?php
                                        $permissions = [1 => 'เจ้าหน้าที่', 2 => 'หัวหน้างาน', 3 => 'ผู้ดูแลระบบ'];
                                        echo $permissions[$staff['Aj_per']] ?? 'ไม่ระบุ';
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="usage-indicator">
                                        📋 <?php echo number_format($staff['assigned_requests'] ?? 0); ?> รายการ
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $total = $staff['assigned_requests'] ?? 0;
                                    $completed = $staff['completed_requests'] ?? 0;
                                    $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
                                    ?>
                                    <span style="color: <?php echo $rate >= 80 ? '#28a745' : ($rate >= 60 ? '#ffc107' : '#dc3545'); ?>">
                                        <?php echo $rate; ?>%
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $staff['Aj_status'] ? 'status-active' : 'status-inactive'; ?>">
                                        <?php echo $staff['Aj_status'] ? 'ใช้งาน' : 'ระงับ'; ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_staff_status">
                                        <input type="hidden" name="staff_id" value="<?php echo $staff['Aj_id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $staff['Aj_status'] ? 0 : 1; ?>">
                                        <button type="submit" class="btn <?php echo $staff['Aj_status'] ? 'btn-warning' : 'btn-success'; ?> btn-sm">
                                            <?php echo $staff['Aj_status'] ? '⏸️ ระงับ' : '▶️ เปิดใช้'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Students Tab -->
            <div id="students" class="tab-content <?php echo $activeTab === 'students' ? 'active' : ''; ?>">
                <h2 class="section-title">👨‍🎓 สถิตินักศึกษา</h2>

                <div class="form-grid">
                    <div class="form-section">
                        <h3>📊 สถิติการลงทะเบียน</h3>
                        <p><strong>นักศึกษาทั้งหมด:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo number_format($systemStats['total_students'] ?? 0); ?></span> คน</p>
                        <p><strong>ใช้งานระบบ:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo number_format($systemStats['active_students'] ?? 0); ?></span> คน</p>
                        <p><strong>ระงับการใช้งาน:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo number_format(($systemStats['total_students'] ?? 0) - ($systemStats['active_students'] ?? 0)); ?></span> คน</p>
                    </div>

                    <div class="form-section">
                        <h3>📈 สถิติการใช้งาน</h3>
                        <?php
                        $studentWithRequests = $db->count('student s', 'EXISTS (SELECT 1 FROM request r WHERE r.Stu_id = s.Stu_id)');
                        $avgRequests = $db->fetch("SELECT AVG(request_count) as avg_count FROM (SELECT COUNT(r.Re_id) as request_count FROM student s LEFT JOIN request r ON s.Stu_id = r.Stu_id GROUP BY s.Stu_id) as subquery")['avg_count'] ?? 0;
                        ?>
                        <p><strong>ส่งข้อร้องเรียนแล้ว:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo number_format($studentWithRequests); ?></span> คน</p>
                        <p><strong>เฉลี่ยข้อร้องเรียน/คน:</strong> <span class="stat-number" style="font-size: 1.5rem;"><?php echo round($avgRequests, 1); ?></span> รายการ</p>
                    </div>

                    <div class="form-section">
                        <h3>🏢 แยกตามคณะ</h3>
                        <?php
                        $facultyStats = $db->fetchAll("
                            SELECT u.Unit_name, u.Unit_icon, COUNT(s.Stu_id) as student_count
                            FROM organization_unit u
                            LEFT JOIN organization_unit m ON u.Unit_id = m.Unit_parent_id
                            LEFT JOIN student s ON m.Unit_id = s.Unit_id
                            WHERE u.Unit_type = 'faculty'
                            GROUP BY u.Unit_id
                            ORDER BY student_count DESC
                        ");
                        ?>
                        <?php foreach ($facultyStats as $faculty): ?>
                            <p><strong><?php echo $faculty['Unit_icon']; ?> <?php echo htmlspecialchars($faculty['Unit_name']); ?>:</strong>
                                <span class="stat-number" style="font-size: 1.2rem;"><?php echo number_format($faculty['student_count']); ?></span> คน
                            </p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- System Tab -->
            <div id="system" class="tab-content <?php echo $activeTab === 'system' ? 'active' : ''; ?>">
                <h2 class="section-title">🔧 การจัดการระบบ</h2>

                <div class="form-grid">
                    <div class="form-section">
                        <h3>💾 สำรองข้อมูล</h3>
                        <p>สร้างไฟล์สำรองข้อมูลระบบเพื่อป้องกันการสูญหาย</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="system_backup">
                            <button type="submit" class="btn btn-primary">
                                <span>💾</span> สร้างไฟล์สำรองข้อมูล
                            </button>
                        </form>
                    </div>

                    <div class="form-section">
                        <h3>🧹 ล้าง Logs</h3>
                        <p>ลบข้อมูล logs เก่าเพื่อประหยัดพื้นที่เก็บข้อมูล</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="clear_logs">
                            <div class="form-group">
                                <label>ลบ logs เก่ากว่า (วัน)</label>
                                <select name="days_old" class="form-control">
                                    <option value="30">30 วัน</option>
                                    <option value="60">60 วัน</option>
                                    <option value="90">90 วัน</option>
                                    <option value="180">180 วัน</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-warning">
                                <span>🧹</span> ล้าง Logs เก่า
                            </button>
                        </form>
                    </div>

                    <div class="form-section">
                        <h3>📧 การตั้งค่าอีเมล</h3>
                        <p>ตั้งค่าระบบส่งอีเมลแจ้งเตือนอัตโนมัติ</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_email_settings">
                            <div class="form-group">
                                <label>SMTP Server</label>
                                <input type="text" name="smtp_host" class="form-control" value="<?php echo SMTP_HOST; ?>" placeholder="smtp.gmail.com">
                            </div>
                            <div class="form-group">
                                <label>SMTP Port</label>
                                <input type="number" name="smtp_port" class="form-control" value="<?php echo SMTP_PORT; ?>" placeholder="587">
                            </div>
                            <div class="form-group">
                                <label>อีเมลผู้ส่ง</label>
                                <input type="email" name="smtp_from" class="form-control" value="<?php echo SMTP_FROM_EMAIL; ?>" placeholder="noreply@rmuti.ac.th">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <span>📧</span> อัปเดตการตั้งค่า
                            </button>
                        </form>
                    </div>
                </div>

                <h3 class="section-title">📊 ข้อมูลระบบ</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th>ค่า</th>
                            <th>หมายเหตุ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>เวอร์ชัน PHP</td>
                            <td><?php echo PHP_VERSION; ?></td>
                            <td>-</td>
                        </tr>
                        <tr>
                            <td>ฐานข้อมูล</td>
                            <td><?php echo DB_NAME; ?></td>
                            <td>MySQL/MariaDB</td>
                        </tr>
                        <tr>
                            <td>เขตเวลา</td>
                            <td><?php echo date_default_timezone_get(); ?></td>
                            <td>Asia/Bangkok</td>
                        </tr>
                        <tr>
                            <td>ขนาดไฟล์สูงสุด</td>
                            <td><?php echo ini_get('upload_max_filesize'); ?></td>
                            <td>การอัปโหลดไฟล์</td>
                        </tr>
                        <tr>
                            <td>หน่วยความจำ</td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                            <td>PHP Memory Limit</td>
                        </tr>
                        <tr>
                            <td>เวลา Session</td>
                            <td><?php echo SESSION_TIMEOUT; ?> วินาที</td>
                            <td><?php echo SESSION_TIMEOUT / 60; ?> นาที</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal สำหรับแก้ไขประเภทข้อร้องเรียน -->
    <div id="editComplaintTypeModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editComplaintTypeModal')">&times;</span>
            <h2>✏️ แก้ไขประเภทข้อร้องเรียน</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_complaint_type">
                <input type="hidden" name="type_id" id="editTypeId">
                <div class="form-group">
                    <label>ชื่อประเภทข้อร้องเรียน</label>
                    <input type="text" name="type_name" id="editTypeName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>ไอคอน</label>
                    <input type="text" name="type_icon" id="editTypeIcon" class="form-control" placeholder="📋" maxlength="10">
                </div>
                <div class="form-group">
                    <label>คำอธิบาย</label>
                    <textarea name="type_description" id="editTypeDescription" class="form-control" rows="3"></textarea>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('editComplaintTypeModal')" class="btn btn-secondary">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal สำหรับยืนยันการลบ -->
    <div id="confirmDeleteModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('confirmDeleteModal')">&times;</span>
            <h2>⚠️ ยืนยันการลบ</h2>
            <p id="deleteConfirmText">คุณแน่ใจหรือไม่ที่จะลบรายการนี้?</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" id="deleteAction">
                <input type="hidden" name="type_id" id="deleteId">
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('confirmDeleteModal')" class="btn btn-secondary">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger">ยืนยันการลบ</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab Management
        function showTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));

            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => button.classList.remove('active'));

            // Show selected tab and mark button as active
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');

            // Update URL parameter
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Modal Management
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Complaint Type Functions
        function editComplaintType(id, name, icon, description) {
            document.getElementById('editTypeId').value = id;
            document.getElementById('editTypeName').value = name;
            document.getElementById('editTypeIcon').value = icon;
            document.getElementById('editTypeDescription').value = description;
            openModal('editComplaintTypeModal');
        }

        function deleteComplaintType(id) {
            document.getElementById('deleteAction').value = 'delete_complaint_type';
            document.getElementById('deleteId').name = 'type_id';
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteConfirmText').textContent = 'คุณแน่ใจหรือไม่ที่จะลบประเภทข้อร้องเรียนนี้?';
            openModal('confirmDeleteModal');
        }

        // Organization Unit Functions
        function editOrganizationUnit(id) {
            // Implementation for editing organization unit
            alert('ฟีเจอร์แก้ไขหน่วยงาน - จะพัฒนาในส่วนต่อไป');
        }

        function deleteOrganizationUnit(id) {
            document.getElementById('deleteAction').value = 'delete_organization_unit';
            document.getElementById('deleteId').name = 'unit_id';
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteConfirmText').textContent = 'คุณแน่ใจหรือไม่ที่จะลบหน่วยงานนี้?';
            openModal('confirmDeleteModal');
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Auto-refresh system stats every 30 seconds
        setInterval(function() {
            // Implementation for auto-refresh stats
        }, 30000);

        // Initialize tooltips and other UI enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth scrolling
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.querySelector(this.getAttribute('href')).scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });

            // Add loading states to forms
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '⏳ กำลังประมวลผล...';
                    }
                });
            });
        });
    </script>
</body>

</html>