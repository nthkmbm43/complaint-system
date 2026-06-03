<?php
// staff/admin-management.php - หน้าจัดการระบบ (Process 3)
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์ Admin เท่านั้น
requireLogin();
requirePermission(3); // เฉพาะ Admin

$db = getDB();
$user = getCurrentUser();

$message = '';
$messageType = 'success';
$activeTab = $_GET['tab'] ?? 'users';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_teacher':
                // เพิ่มอาจารย์/เจ้าหน้าที่
                $teacherName = trim($_POST['teacher_name']);
                $teacherPassword = trim($_POST['teacher_password']);
                $teacherPosition = trim($_POST['teacher_position']);
                $teacherPermission = intval($_POST['teacher_permission']);
                $teacherTel = trim($_POST['teacher_tel'] ?? '');
                $teacherEmail = trim($_POST['teacher_email'] ?? '');
                $teacherDepartment = trim($_POST['teacher_department'] ?? '');

                if (empty($teacherName) || empty($teacherPassword) || empty($teacherPosition)) {
                    throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
                }

                if (!in_array($teacherPermission, [1, 2, 3])) {
                    throw new Exception('ระดับสิทธิ์ไม่ถูกต้อง');
                }

                // ตรวจสอบชื่อซ้ำ
                $existingTeacher = $db->fetch("SELECT Aj_id FROM teacher WHERE Aj_name = ?", [$teacherName]);
                if ($existingTeacher) {
                    throw new Exception('ชื่อเจ้าหน้าที่นี้มีอยู่ในระบบแล้ว');
                }

                $result = $db->insert('teacher', [
                    'Aj_name' => $teacherName,
                    'Aj_password' => $teacherPassword,
                    'Aj_position' => $teacherPosition,
                    'Aj_per' => $teacherPermission,
                    'Aj_tel' => $teacherTel ?: null,
                    'Aj_email' => $teacherEmail ?: null,
                    'Aj_department' => $teacherDepartment ?: null,
                    'Aj_status' => 1
                ]);

                if ($result) {
                    $message = 'เพิ่มเจ้าหน้าที่ใหม่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_teacher', 'เพิ่มเจ้าหน้าที่: ' . $teacherName);
                }
                break;

            case 'add_complaint_type':
                // เพิ่มประเภทข้อร้องเรียน
                $typeName = trim($_POST['type_name']);
                $typeIcon = trim($_POST['type_icon']);

                if (empty($typeName)) {
                    throw new Exception('กรุณาระบุชื่อประเภทข้อร้องเรียน');
                }

                $result = $db->insert('type', [
                    'Type_infor' => $typeName,
                    'Type_icon' => $typeIcon ?: '📋'
                ]);

                if ($result) {
                    $message = 'เพิ่มประเภทข้อร้องเรียนใหม่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_type', 'เพิ่มประเภท: ' . $typeName);
                }
                break;

            case 'add_organization_unit':
                // เพิ่มหน่วยงาน
                $unitName = trim($_POST['unit_name']);
                $unitType = trim($_POST['unit_type']);
                $unitIcon = trim($_POST['unit_icon']);
                $unitParentId = intval($_POST['unit_parent_id'] ?? 0);
                $unitTel = trim($_POST['unit_tel'] ?? '');
                $unitEmail = trim($_POST['unit_email'] ?? '');

                if (empty($unitName) || empty($unitType)) {
                    throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
                }

                if (!in_array($unitType, ['faculty', 'major', 'department'])) {
                    throw new Exception('ประเภทหน่วยงานไม่ถูกต้อง');
                }

                $insertData = [
                    'Unit_name' => $unitName,
                    'Unit_type' => $unitType,
                    'Unit_icon' => $unitIcon ?: '🏢',
                    'Unit_parent_id' => $unitParentId > 0 ? $unitParentId : null,
                    'Unit_tel' => $unitTel ?: null,
                    'Unit_email' => $unitEmail ?: null
                ];

                $result = $db->insert('organization_unit', $insertData);

                if ($result) {
                    $message = 'เพิ่มหน่วยงานใหม่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'add_unit', 'เพิ่มหน่วยงาน: ' . $unitName);
                }
                break;

            case 'update_settings':
                // อัปเดตการตั้งค่าระบบ
                $siteName = trim($_POST['site_name']);
                $maxFileSize = intval($_POST['max_file_size']);
                $responseTime = intval($_POST['response_time']);

                // บันทึกการตั้งค่าในไฟล์หรือฐานข้อมูล
                $settings = [
                    'site_name' => $siteName,
                    'max_file_size' => $maxFileSize,
                    'response_time' => $responseTime,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'updated_by' => $_SESSION['user_id']
                ];

                // สร้างไฟล์ settings.json
                file_put_contents('../config/settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                $message = 'อัปเดตการตั้งค่าระบบเรียบร้อยแล้ว';
                logActivity($_SESSION['user_id'], 'update_settings', 'อัปเดตการตั้งค่าระบบ');
                break;

            case 'toggle_teacher_status':
                // เปิด/ปิดใช้งานบัญชีเจ้าหน้าที่
                $teacherId = intval($_POST['teacher_id']);
                $newStatus = intval($_POST['new_status']);

                $result = $db->update('teacher', ['Aj_status' => $newStatus], 'Aj_id = ?', [$teacherId]);

                if ($result) {
                    $statusText = $newStatus == 1 ? 'เปิดใช้งาน' : 'ปิดใช้งาน';
                    $message = $statusText . 'บัญชีเจ้าหน้าที่เรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'toggle_teacher_status', 'เปลี่ยนสถานะเจ้าหน้าที่ ID: ' . $teacherId);
                }
                break;

            case 'delete_complaint_type':
                // ลบประเภทข้อร้องเรียน
                $typeId = intval($_POST['type_id']);

                // ตรวจสอบว่ามีการใช้งานหรือไม่
                $usage = $db->count('request', 'Type_id = ?', [$typeId]);
                if ($usage > 0) {
                    throw new Exception('ไม่สามารถลบได้ เนื่องจากมีข้อร้องเรียนใช้ประเภทนี้อยู่ ' . $usage . ' รายการ');
                }

                $result = $db->delete('type', 'Type_id = ?', [$typeId]);

                if ($result) {
                    $message = 'ลบประเภทข้อร้องเรียนเรียบร้อยแล้ว';
                    logActivity($_SESSION['user_id'], 'delete_type', 'ลบประเภท ID: ' . $typeId);
                }
                break;
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $messageType = 'error';
        error_log("Admin management error: " . $e->getMessage());
    }
}

// ดึงข้อมูลสำหรับแสดงผล
try {
    // ข้อมูลเจ้าหน้าที่
    $teachers = $db->fetchAll("SELECT * FROM teacher ORDER BY Aj_per DESC, Aj_name ASC");

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
        SELECT u1.*, u2.Unit_name as parent_name
        FROM organization_unit u1
        LEFT JOIN organization_unit u2 ON u1.Unit_parent_id = u2.Unit_id
        ORDER BY u1.Unit_type ASC, u1.Unit_name ASC
    ");

    // ข้อมูลนักศึกษา (สำหรับแสดงสถิติ)
    $studentStats = [
        'total' => $db->count('student'),
        'active' => $db->count('student', 'Stu_status = 1'),
        'suspended' => $db->count('student', 'Stu_status = 0'),
        'today' => $db->count('student', 'DATE(created_at) = CURDATE()'),
        'this_month' => $db->count('student', 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())')
    ];

    // สถิติระบบ
    $systemStats = [
        'total_requests' => $db->count('request'),
        'pending_requests' => $db->count('request', 'Re_status = "0"'),
        'completed_requests' => $db->count('request', 'Re_status = "3"'),
        'spam_requests' => $db->count('request', 'Re_is_spam = 1'),
        'teachers' => $db->count('teacher', 'Aj_status = 1'),
        'faculties' => $db->count('organization_unit', 'Unit_type = "faculty"'),
        'majors' => $db->count('organization_unit', 'Unit_type = "major"'),
        'departments' => $db->count('organization_unit', 'Unit_type = "department"')
    ];

    // ข้อมูลการตั้งค่า
    $settings = [];
    if (file_exists('../config/settings.json')) {
        $settings = json_decode(file_get_contents('../config/settings.json'), true);
    }

    // ค่าเริ่มต้นหากไม่มีการตั้งค่า
    $settings = array_merge([
        'site_name' => SITE_NAME,
        'max_file_size' => MAX_FILE_SIZE,
        'response_time' => DEFAULT_RESPONSE_TIME
    ], $settings);
} catch (Exception $e) {
    error_log("Admin data error: " . $e->getMessage());
    $message = "เกิดข้อผิดพลาดในการโหลดข้อมูล: " . $e->getMessage();
    $messageType = 'error';
}

?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการระบบ - ระบบข้อร้องเรียนนักศึกษา</title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <style>
        .main-content {
            padding-top: 70px;
        }

        .admin-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .admin-title {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .admin-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .tabs-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .tabs-header {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            overflow-x: auto;
        }

        .tab-button {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #666;
            transition: all 0.3s ease;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-button.active {
            background: white;
            color: #667eea;
            border-bottom: 3px solid #667eea;
        }

        .tab-button:hover {
            background: #e9ecef;
        }

        .tab-content {
            padding: 30px;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
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
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
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
            font-size: 12px;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 25px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table th {
            background: #f8f9fa;
            padding: 15px 10px;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            text-align: left;
            font-size: 13px;
        }

        .table td {
            padding: 15px 10px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
            font-size: 13px;
        }

        .table tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }

        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-secondary {
            background: #e2e3e5;
            color: #383d41;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-control {
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .required {
            color: #dc3545;
        }

        .actions-group {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .permission-info {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .permission-level {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }

        .permission-level.level-1 {
            background: #e2e3e5;
            color: #383d41;
        }

        .permission-level.level-2 {
            background: #bee5eb;
            color: #0c5460;
        }

        .permission-level.level-3 {
            background: #f8d7da;
            color: #721c24;
        }

        .unit-hierarchy {
            padding-left: 20px;
            color: #666;
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .tabs-header {
                flex-direction: column;
            }

            .tab-content {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                align-items: stretch;
            }

            .actions-group {
                flex-direction: column;
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

    <div class="main-content">
        <?php include '../includes/header.php'; ?>

        <div class="page-content">
            <!-- Header -->
            <div class="admin-header">
                <div class="admin-title">
                    <span>⚙️</span>
                    จัดการระบบ
                </div>
                <div class="admin-subtitle">
                    จัดการข้อมูลพื้นฐานและการตั้งค่าระบบ
                </div>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- System Overview -->
            <div class="stats-overview">
                <div class="stat-card">
                    <div class="stat-icon">👨‍🎓</div>
                    <div class="stat-number"><?php echo number_format($studentStats['total']); ?></div>
                    <div class="stat-label">นักศึกษาทั้งหมด</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">👨‍🏫</div>
                    <div class="stat-number"><?php echo number_format($systemStats['teachers']); ?></div>
                    <div class="stat-label">เจ้าหน้าที่</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📝</div>
                    <div class="stat-number"><?php echo number_format($systemStats['total_requests']); ?></div>
                    <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">⏳</div>
                    <div class="stat-number"><?php echo number_format($systemStats['pending_requests']); ?></div>
                    <div class="stat-label">รอดำเนินการ</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🏢</div>
                    <div class="stat-number"><?php echo number_format($systemStats['faculties']); ?></div>
                    <div class="stat-label">คณะ</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?php echo number_format($systemStats['majors']); ?></div>
                    <div class="stat-label">สาขา</div>
                </div>
            </div>

            <!-- Management Tabs -->
            <div class="tabs-container">
                <div class="tabs-header">
                    <button class="tab-button <?php echo $activeTab === 'users' ? 'active' : ''; ?>" onclick="showTab('users')">
                        👥 จัดการผู้ใช้
                    </button>
                    <button class="tab-button <?php echo $activeTab === 'teachers' ? 'active' : ''; ?>" onclick="showTab('teachers')">
                        👨‍🏫 จัดการเจ้าหน้าที่
                    </button>
                    <button class="tab-button <?php echo $activeTab === 'types' ? 'active' : ''; ?>" onclick="showTab('types')">
                        📋 ประเภทข้อร้องเรียน
                    </button>
                    <button class="tab-button <?php echo $activeTab === 'units' ? 'active' : ''; ?>" onclick="showTab('units')">
                        🏢 จัดการหน่วยงาน
                    </button>
                    <button class="tab-button <?php echo $activeTab === 'settings' ? 'active' : ''; ?>" onclick="showTab('settings')">
                        ⚙️ ตั้งค่าระบบ
                    </button>
                </div>

                <!-- Tab: Users -->
                <div id="users-tab" class="tab-content <?php echo $activeTab === 'users' ? 'active' : ''; ?>">
                    <div class="section-header">
                        <div class="section-title">
                            👥 จัดการนักศึกษา
                        </div>
                        <a href="users.php" class="btn btn-primary">
                            📊 จัดการรายละเอียด
                        </a>
                    </div>

                    <div class="stats-overview">
                        <div class="stat-card">
                            <div class="stat-icon">✅</div>
                            <div class="stat-number"><?php echo number_format($studentStats['active']); ?></div>
                            <div class="stat-label">ใช้งานอยู่</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">🚫</div>
                            <div class="stat-number"><?php echo number_format($studentStats['suspended']); ?></div>
                            <div class="stat-label">ถูกระงับ</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">📅</div>
                            <div class="stat-number"><?php echo number_format($studentStats['today']); ?></div>
                            <div class="stat-label">สมัครวันนี้</div>
                        </div>

                        <div class="stat-card">
                            <div class="stat-icon">📊</div>
                            <div class="stat-number"><?php echo number_format($studentStats['this_month']); ?></div>
                            <div class="stat-label">สมัครเดือนนี้</div>
                        </div>
                    </div>

                    <p>สำหรับการจัดการนักศึกษารายละเอียด กรุณาคลิกปุ่ม "จัดการรายละเอียด" ด้านบน</p>
                </div>

                <!-- Tab: Teachers -->
                <div id="teachers-tab" class="tab-content <?php echo $activeTab === 'teachers' ? 'active' : ''; ?>">
                    <div class="section-header">
                        <div class="section-title">
                            👨‍🏫 จัดการเจ้าหน้าที่
                        </div>
                        <button type="button" class="btn btn-success" onclick="showAddTeacherForm()">
                            ➕ เพิ่มเจ้าหน้าที่
                        </button>
                    </div>

                    <!-- Add Teacher Form -->
                    <div id="addTeacherForm" class="card" style="display: none;">
                        <h3>➕ เพิ่มเจ้าหน้าที่ใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_teacher">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>ชื่อ-สกุล <span class="required">*</span></label>
                                    <input type="text" name="teacher_name" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label>รหัสผ่าน <span class="required">*</span></label>
                                    <input type="password" name="teacher_password" class="form-control" required minlength="6">
                                </div>

                                <div class="form-group">
                                    <label>ตำแหน่ง <span class="required">*</span></label>
                                    <input type="text" name="teacher_position" class="form-control" required placeholder="เช่น อาจารย์, ผู้ช่วยศาสตราจารย์">
                                </div>

                                <div class="form-group">
                                    <label>ระดับสิทธิ์ <span class="required">*</span></label>
                                    <select name="teacher_permission" class="form-control" required>
                                        <option value="">เลือกระดับสิทธิ์</option>
                                        <option value="1">1 - อาจารย์</option>
                                        <option value="2">2 - ผู้ดำเนินการ</option>
                                        <option value="3">3 - ผู้ดูแลระบบ</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>เบอร์โทร</label>
                                    <input type="tel" name="teacher_tel" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>อีเมล</label>
                                    <input type="email" name="teacher_email" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>แผนก/หน่วยงาน</label>
                                    <input type="text" name="teacher_department" class="form-control">
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <button type="button" class="btn btn-secondary" onclick="hideAddTeacherForm()">ยกเลิก</button>
                                <button type="submit" class="btn btn-success">➕ เพิ่มเจ้าหน้าที่</button>
                            </div>
                        </form>
                    </div>

                    <!-- Teachers List -->
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>ชื่อ-สกุล</th>
                                    <th>ตำแหน่ง</th>
                                    <th>ระดับสิทธิ์</th>
                                    <th>ติดต่อ</th>
                                    <th>สถานะ</th>
                                    <th>จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teachers as $teacher): ?>
                                    <tr>
                                        <td><?php echo $teacher['Aj_id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($teacher['Aj_name']); ?></strong>
                                            <?php if ($teacher['Aj_department']): ?>
                                                <br><small><?php echo htmlspecialchars($teacher['Aj_department']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($teacher['Aj_position']); ?></td>
                                        <td>
                                            <div class="permission-info">
                                                <span class="permission-level level-<?php echo $teacher['Aj_per']; ?>">
                                                    <?php echo $teacher['Aj_per']; ?>
                                                </span>
                                                <?php
                                                $permissions = ['', 'อาจารย์', 'ผู้ดำเนินการ', 'ผู้ดูแลระบบ'];
                                                echo $permissions[$teacher['Aj_per']] ?? 'ไม่ทราบ';
                                                ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($teacher['Aj_tel'] || $teacher['Aj_email']): ?>
                                                <?php if ($teacher['Aj_tel']): ?>
                                                    <div>📞 <?php echo htmlspecialchars($teacher['Aj_tel']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($teacher['Aj_email']): ?>
                                                    <div>📧 <?php echo htmlspecialchars($teacher['Aj_email']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #666;">ไม่มีข้อมูล</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($teacher['Aj_status'] == 1): ?>
                                                <span class="badge badge-success">ใช้งานอยู่</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger">ปิดใช้งาน</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="actions-group">
                                                <?php if ($teacher['Aj_id'] != $_SESSION['user_id']): ?>
                                                    <?php if ($teacher['Aj_status'] == 1): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="toggle_teacher_status">
                                                            <input type="hidden" name="teacher_id" value="<?php echo $teacher['Aj_id']; ?>">
                                                            <input type="hidden" name="new_status" value="0">
                                                            <button type="submit" class="btn btn-warning btn-sm"
                                                                onclick="return confirm('คุณต้องการปิดใช้งานบัญชีนี้หรือไม่?')">
                                                                🚫 ปิดใช้งาน
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="toggle_teacher_status">
                                                            <input type="hidden" name="teacher_id" value="<?php echo $teacher['Aj_id']; ?>">
                                                            <input type="hidden" name="new_status" value="1">
                                                            <button type="submit" class="btn btn-success btn-sm">
                                                                ✅ เปิดใช้งาน
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-info">บัญชีของคุณ</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Complaint Types -->
                <div id="types-tab" class="tab-content <?php echo $activeTab === 'types' ? 'active' : ''; ?>">
                    <div class="section-header">
                        <div class="section-title">
                            📋 ประเภทข้อร้องเรียน
                        </div>
                        <button type="button" class="btn btn-success" onclick="showAddTypeForm()">
                            ➕ เพิ่มประเภท
                        </button>
                    </div>

                    <!-- Add Type Form -->
                    <div id="addTypeForm" class="card" style="display: none;">
                        <h3>➕ เพิ่มประเภทข้อร้องเรียนใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_complaint_type">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>ชื่อประเภท <span class="required">*</span></label>
                                    <input type="text" name="type_name" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label>ไอคอน</label>
                                    <input type="text" name="type_icon" class="form-control" placeholder="📋">
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <button type="button" class="btn btn-secondary" onclick="hideAddTypeForm()">ยกเลิก</button>
                                <button type="submit" class="btn btn-success">➕ เพิ่มประเภท</button>
                            </div>
                        </form>
                    </div>

                    <!-- Types List -->
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>ประเภท</th>
                                    <th>การใช้งาน</th>
                                    <th>จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($complaintTypes as $type): ?>
                                    <tr>
                                        <td><?php echo $type['Type_id']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="font-size: 1.2rem;"><?php echo $type['Type_icon']; ?></span>
                                                <strong><?php echo htmlspecialchars($type['Type_infor']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-info">
                                                <?php echo number_format($type['usage_count']); ?> ครั้ง
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-group">
                                                <?php if ($type['usage_count'] == 0): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_complaint_type">
                                                        <input type="hidden" name="type_id" value="<?php echo $type['Type_id']; ?>">
                                                        <button type="submit" class="btn btn-danger btn-sm"
                                                            onclick="return confirm('คุณต้องการลบประเภทนี้หรือไม่?')">
                                                            🗑️ ลบ
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">ใช้งานอยู่</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: Organization Units -->
                <div id="units-tab" class="tab-content <?php echo $activeTab === 'units' ? 'active' : ''; ?>">
                    <div class="section-header">
                        <div class="section-title">
                            🏢 หน่วยงาน
                        </div>
                        <button type="button" class="btn btn-success" onclick="showAddUnitForm()">
                            ➕ เพิ่มหน่วยงาน
                        </button>
                    </div>

                    <!-- Add Unit Form -->
                    <div id="addUnitForm" class="card" style="display: none;">
                        <h3>➕ เพิ่มหน่วยงานใหม่</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="add_organization_unit">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>ชื่อหน่วยงาน <span class="required">*</span></label>
                                    <input type="text" name="unit_name" class="form-control" required>
                                </div>

                                <div class="form-group">
                                    <label>ประเภท <span class="required">*</span></label>
                                    <select name="unit_type" class="form-control" required>
                                        <option value="">เลือกประเภท</option>
                                        <option value="faculty">คณะ</option>
                                        <option value="major">สาขา</option>
                                        <option value="department">แผนก/หน่วยงาน</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>ไอคอน</label>
                                    <input type="text" name="unit_icon" class="form-control" placeholder="🏢">
                                </div>

                                <div class="form-group">
                                    <label>หน่วยงานต้นสังกัด</label>
                                    <select name="unit_parent_id" class="form-control">
                                        <option value="">ไม่มี (หน่วยงานหลัก)</option>
                                        <?php foreach ($organizationUnits as $unit): ?>
                                            <option value="<?php echo $unit['Unit_id']; ?>">
                                                <?php echo $unit['Unit_icon'] . ' ' . htmlspecialchars($unit['Unit_name']) . ' (' . $unit['Unit_type'] . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>เบอร์โทร</label>
                                    <input type="tel" name="unit_tel" class="form-control">
                                </div>

                                <div class="form-group">
                                    <label>อีเมล</label>
                                    <input type="email" name="unit_email" class="form-control">
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <button type="button" class="btn btn-secondary" onclick="hideAddUnitForm()">ยกเลิก</button>
                                <button type="submit" class="btn btn-success">➕ เพิ่มหน่วยงาน</button>
                            </div>
                        </form>
                    </div>

                    <!-- Units List -->
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>หน่วยงาน</th>
                                    <th>ประเภท</th>
                                    <th>หน่วยงานต้นสังกัด</th>
                                    <th>ติดต่อ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $typeLabels = [
                                    'faculty' => 'คณะ',
                                    'major' => 'สาขา',
                                    'department' => 'แผนก/หน่วยงาน'
                                ];

                                foreach ($organizationUnits as $unit):
                                ?>
                                    <tr>
                                        <td><?php echo $unit['Unit_id']; ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <span style="font-size: 1.2rem;"><?php echo $unit['Unit_icon']; ?></span>
                                                <strong><?php echo htmlspecialchars($unit['Unit_name']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $unit['Unit_type'] === 'faculty' ? 'info' : ($unit['Unit_type'] === 'major' ? 'success' : 'secondary'); ?>">
                                                <?php echo $typeLabels[$unit['Unit_type']] ?? $unit['Unit_type']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($unit['parent_name']): ?>
                                                <div class="unit-hierarchy">
                                                    <?php echo htmlspecialchars($unit['parent_name']); ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #666;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($unit['Unit_tel'] || $unit['Unit_email']): ?>
                                                <?php if ($unit['Unit_tel']): ?>
                                                    <div>📞 <?php echo htmlspecialchars($unit['Unit_tel']); ?></div>
                                                <?php endif; ?>
                                                <?php if ($unit['Unit_email']): ?>
                                                    <div>📧 <?php echo htmlspecialchars($unit['Unit_email']); ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #666;">ไม่มีข้อมูล</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab: System Settings -->
                <div id="settings-tab" class="tab-content <?php echo $activeTab === 'settings' ? 'active' : ''; ?>">
                    <div class="section-header">
                        <div class="section-title">
                            ⚙️ ตั้งค่าระบบ
                        </div>
                    </div>

                    <div class="card">
                        <h3>การตั้งค่าทั่วไป</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_settings">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>ชื่อระบบ</label>
                                    <input type="text" name="site_name" class="form-control"
                                        value="<?php echo htmlspecialchars($settings['site_name']); ?>">
                                </div>

                                <div class="form-group">
                                    <label>ขนาดไฟล์สูงสุด (bytes)</label>
                                    <input type="number" name="max_file_size" class="form-control"
                                        value="<?php echo $settings['max_file_size']; ?>">
                                    <small>ปัจจุบัน: <?php echo formatFileSize($settings['max_file_size']); ?></small>
                                </div>

                                <div class="form-group">
                                    <label>เวลาตอบกลับมาตรฐาน (ชั่วโมง)</label>
                                    <input type="number" name="response_time" class="form-control"
                                        value="<?php echo $settings['response_time']; ?>">
                                </div>
                            </div>

                            <div style="text-align: right;">
                                <button type="submit" class="btn btn-primary">💾 บันทึกการตั้งค่า</button>
                            </div>
                        </form>
                    </div>

                    <div class="card">
                        <h3>ข้อมูลระบบ</h3>
                        <div class="form-grid">
                            <div>
                                <strong>เวอร์ชัน PHP:</strong> <?php echo phpversion(); ?>
                            </div>
                            <div>
                                <strong>MySQL:</strong> <?php echo $db->fetch("SELECT VERSION() as version")['version'] ?? 'ไม่ทราบ'; ?>
                            </div>
                            <div>
                                <strong>เซิร์ฟเวอร์:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'ไม่ทราบ'; ?>
                            </div>
                            <div>
                                <strong>การใช้หน่วยความจำ:</strong> <?php echo formatFileSize(memory_get_usage(true)); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // ซ่อน tab ทั้งหมด
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // ซ่อน button ทั้งหมด
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });

            // แสดง tab ที่เลือก
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');

            // อัปเดต URL
            const url = new URLSearchParams(window.location.search);
            url.set('tab', tabName);
            window.history.replaceState({}, '', `${window.location.pathname}?${url}`);
        }

        // Teacher form functions
        function showAddTeacherForm() {
            document.getElementById('addTeacherForm').style.display = 'block';
        }

        function hideAddTeacherForm() {
            document.getElementById('addTeacherForm').style.display = 'none';
        }

        // Type form functions
        function showAddTypeForm() {
            document.getElementById('addTypeForm').style.display = 'block';
        }

        function hideAddTypeForm() {
            document.getElementById('addTypeForm').style.display = 'none';
        }

        // Unit form functions
        function showAddUnitForm() {
            document.getElementById('addUnitForm').style.display = 'block';
        }

        function hideAddUnitForm() {
            document.getElementById('addUnitForm').style.display = 'none';
        }
    </script>
</body>

</html>