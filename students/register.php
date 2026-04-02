<?php
define('SECURE_ACCESS', true);

// Enable detailed error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Function to safely include files
function safeInclude($file, $description)
{
    if (!file_exists($file)) {
        die("❌ ไม่พบไฟล์ {$description}: {$file}<br>กรุณาตรวจสอบโครงสร้างโฟลเดอร์");
    }
    require_once $file;
}

// Include required files with error checking
safeInclude('../config/config.php', 'config');
safeInclude('../config/database.php', 'database');
safeInclude('../includes/auth.php', 'auth');

// Initialize variables
$error = '';
$success = '';
$debugMode = true; // Force debug mode for testing
$authAvailable = false;

// Check if auth functions are available and working
try {
    if (function_exists('isLoggedIn') && function_exists('hasRole')) {
        $authAvailable = true;

        // ถ้าล็อกอินแล้วให้ redirect ไป dashboard
        if (isLoggedIn() && hasRole('student')) {
            header('Location: index.php');
            exit;
        }
    }
} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
}

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
$db = null;
$dbConnected = false;

try {
    $db = getDB();
    if ($db) {
        // ทดสอบการเชื่อมต่อด้วย query ง่ายๆ
        $testQuery = $db->fetch("SELECT 1 as test_connection");
        if ($testQuery && isset($testQuery['test_connection'])) {
            $dbConnected = true;
            if ($debugMode) {
                error_log("Database connection successful");
            }
        }
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    $error = "ข้อผิดพลาดฐานข้อมูล: " . $e->getMessage();
}

// ดึงข้อมูลคณะ (Unit_type = 'faculty')
$faculties = [];
if ($dbConnected) {
    try {
        $faculties = $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_type = 'faculty' 
            ORDER BY Unit_name
        ");
        if ($debugMode) {
            error_log("Loaded " . count($faculties) . " faculties");
        }
    } catch (Exception $e) {
        error_log("Error loading faculties: " . $e->getMessage());
        $error = 'ข้อผิดพลาดในการโหลดข้อมูลคณะ: ' . $e->getMessage();
    }
}

// จัดการ AJAX request สำหรับโหลดสาขา
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_majors'])) {
    if (!$dbConnected) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $facultyId = (int)$_POST['faculty_id'];
    try {
        $majors = $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_type = 'major' AND Unit_parent_id = ? 
            ORDER BY Unit_name
        ", [$facultyId]);

        if ($debugMode) {
            error_log("Loaded " . count($majors) . " majors for faculty $facultyId");
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($majors, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("Error loading majors: " . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'ไม่สามารถโหลดข้อมูลสาขาได้: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Enhanced database wrapper with better error reporting และการแก้ไขปัญหา
class DebugDatabaseWrapper
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function insert($table, $data)
    {
        try {
            error_log("Attempting insert into $table with data: " . print_r($data, true));

            // ตรวจสอบว่าตารางมีอยู่จริง
            $tableExists = $this->db->fetch("
                SELECT COUNT(*) as count 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ?
            ", [$table]);

            if (!$tableExists || $tableExists['count'] == 0) {
                throw new Exception("Table '$table' does not exist");
            }

            // ตรวจสอบ columns ที่ถูกต้อง
            $columns = $this->db->fetchAll("
                SELECT COLUMN_NAME as Field 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ?
            ", [$table]);

            $validColumns = array_column($columns, 'Field');
            error_log("Valid columns for $table: " . implode(', ', $validColumns));

            // ตรวจสอบว่า columns ที่ส่งมานั้นถูกต้องหรือไม่
            foreach (array_keys($data) as $column) {
                if (!in_array($column, $validColumns)) {
                    throw new Exception("Invalid column '$column' for table '$table'");
                }
            }

            // **แก้ไขหลัก: ใช้ Transaction และปรับปรุงการตรวจสอบ**
            $this->db->beginTransaction();
            
            try {
                $result = $this->db->insert($table, $data);
                
                if ($result !== false) {
                    // รอสักครู่เพื่อให้ฐานข้อมูลประมวลผลเสร็จ
                    usleep(200000); // 0.2 วินาที
                    
                    // ตรวจสอบการบันทึกจริงด้วย primary key
                    $primaryKey = $this->getPrimaryKey($table);
                    if ($primaryKey && isset($data[$primaryKey])) {
                        $checkSql = "SELECT COUNT(*) as count FROM `$table` WHERE `$primaryKey` = ?";
                        $checkResult = $this->db->fetch($checkSql, [$data[$primaryKey]]);
                        
                        if ($checkResult && $checkResult['count'] > 0) {
                            $this->db->commit();
                            error_log("Insert verification SUCCESS - Record exists in database");
                            return $result;
                        } else {
                            $this->db->rollback();
                            error_log("Insert verification FAILED - Record not found after insert");
                            return false;
                        }
                    } else {
                        // ถ้าไม่มี primary key ให้ตรวจสอบด้วยข้อมูลทั้งหมด
                        $whereConditions = [];
                        $whereParams = [];
                        foreach ($data as $key => $value) {
                            if ($value !== null) {
                                $whereConditions[] = "`$key` = ?";
                                $whereParams[] = $value;
                            }
                        }
                        
                        if (!empty($whereConditions)) {
                            $checkSql = "SELECT COUNT(*) as count FROM `$table` WHERE " . implode(' AND ', $whereConditions);
                            $checkResult = $this->db->fetch($checkSql, $whereParams);
                            
                            if ($checkResult && $checkResult['count'] > 0) {
                                $this->db->commit();
                                error_log("Insert verification SUCCESS (no primary key)");
                                return $result;
                            }
                        }
                        
                        $this->db->commit();
                        error_log("Insert completed, cannot fully verify (no primary key info)");
                        return $result;
                    }
                } else {
                    $this->db->rollback();
                    error_log("Insert FAILED - Database insert returned false");
                    return false;
                }
            } catch (Exception $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (Exception $e) {
            error_log("Insert error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * หาค่า Primary Key ของตาราง
     */
    private function getPrimaryKey($table)
    {
        try {
            $result = $this->db->fetch("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_KEY = 'PRI'
                LIMIT 1
            ", [$table]);
            
            return $result ? $result['COLUMN_NAME'] : null;
        } catch (Exception $e) {
            error_log("Cannot get primary key for table $table: " . $e->getMessage());
            return null;
        }
    }

    public function __call($method, $args)
    {
        return call_user_func_array([$this->db, $method], $args);
    }
}

// Wrap database with debug wrapper
if ($dbConnected) {
    $db = new DebugDatabaseWrapper($db);
}

// จัดการการลงทะเบียน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register']) && $dbConnected) {
    try {
        error_log("=== REGISTRATION ATTEMPT STARTED ===");

        // รับข้อมูลจากฟอร์ม
        $studentId = trim($_POST['student_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $facultyId = (int)($_POST['faculty_id'] ?? 0);
        $majorId = (int)($_POST['major_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        error_log("Form data received - Student ID: $studentId, Name: $fullName, Faculty: $facultyId, Major: $majorId");

        // Validation
        if (empty($studentId)) {
            throw new Exception('กรุณากรอกรหัสนักศึกษา');
        }

        if (strlen($studentId) !== 13) {
            throw new Exception('รหัสนักศึกษาต้องมีความยาว 13 ตัวอักษร (ปัจจุบัน: ' . strlen($studentId) . ' ตัวอักษร)');
        }

        if (!preg_match('/^[\d\-]{13}$/', $studentId)) {
            throw new Exception('รหัสนักศึกษาต้องเป็นตัวเลขและ - เท่านั้น');
        }

        if (empty($fullName)) {
            throw new Exception('กรุณากรอกชื่อ-นามสกุล');
        }

        if (mb_strlen($fullName, 'UTF-8') < 2) {
            throw new Exception('ชื่อ-นามสกุลต้องมีอย่างน้อย 2 ตัวอักษร');
        }

        if (mb_strlen($fullName, 'UTF-8') > 50) {
            throw new Exception('ชื่อ-นามสกุลต้องไม่เกิน 50 ตัวอักษร (ปัจจุบัน: ' . mb_strlen($fullName, 'UTF-8') . ' ตัวอักษร)');
        }

        if (empty($password)) {
            throw new Exception('กรุณากรอกรหัสผ่าน');
        }

        if (strlen($password) < 6) {
            throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
        }

        if (strlen($password) > 10) {
            throw new Exception('รหัสผ่านต้องไม่เกิน 10 ตัวอักษร (ปัจจุบัน: ' . strlen($password) . ' ตัวอักษร)');
        }

        if ($password !== $confirmPassword) {
            throw new Exception('รหัสผ่านไม่ตรงกัน');
        }

        if (empty($facultyId)) {
            throw new Exception('กรุณาเลือกคณะ');
        }

        if (empty($majorId)) {
            throw new Exception('กรุณาเลือกสาขาวิชา');
        }

        // ตรวจสอบเบอร์โทร (ถ้ามี)
        if (!empty($phone) && !preg_match('/^[0-9]{10}$/', $phone)) {
            throw new Exception('เบอร์โทรศัพท์ต้องเป็นตัวเลข 10 หลัก');
        }

        // ตรวจสอบอีเมล (ถ้ามี)
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
        }

        error_log("Basic validation passed");

        // ตรวจสอบความซ้ำ
        $existingStudent = $db->fetch("SELECT COUNT(*) as count FROM student WHERE Stu_id = ?", [$studentId]);
        if ($existingStudent && $existingStudent['count'] > 0) {
            throw new Exception('รหัสนักศึกษานี้ถูกใช้งานแล้ว');
        }

        error_log("Student ID uniqueness check passed");

        // ตรวจสอบอีเมลซ้ำ (ถ้ามี)
        if (!empty($email)) {
            $existingEmail = $db->fetch("SELECT COUNT(*) as count FROM student WHERE Stu_email = ?", [$email]);
            if ($existingEmail && $existingEmail['count'] > 0) {
                throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
            }
        }

        // ตรวจสอบเบอร์โทรซ้ำ (ถ้ามี)
        if (!empty($phone)) {
            $existingPhone = $db->fetch("SELECT COUNT(*) as count FROM student WHERE Stu_tel = ?", [$phone]);
            if ($existingPhone && $existingPhone['count'] > 0) {
                throw new Exception('เบอร์โทรศัพท์นี้ถูกใช้งานแล้ว');
            }
        }

        error_log("Uniqueness checks passed");

        // ตรวจสอบคณะและสาขา - ใช้ organization_unit
        $majorCheck = $db->fetch("
            SELECT COUNT(*) as count 
            FROM organization_unit 
            WHERE Unit_id = ? AND Unit_parent_id = ? AND Unit_type = 'major'
        ", [$majorId, $facultyId]);

        if (!$majorCheck || $majorCheck['count'] == 0) {
            error_log("Major check failed - Major ID: $majorId, Faculty ID: $facultyId");
            throw new Exception('คณะและสาขาที่เลือกไม่ตรงกัน');
        }

        error_log("Faculty/Major relationship check passed");

        // บันทึกข้อมูล (ใช้ plaintext password และ Unit_id)
        $insertData = [
            'Stu_id' => $studentId,
            'Stu_name' => $fullName,
            'Stu_password' => $password, // plaintext password
            'Stu_tel' => !empty($phone) ? $phone : null,
            'Stu_email' => !empty($email) ? $email : null,
            'Unit_id' => $majorId, // ใช้ Unit_id ของสาขา
            'Stu_status' => 1
        ];

        error_log("Attempting to insert data: " . print_r($insertData, true));

        $result = $db->insert('student', $insertData);

        if ($result !== false) {
            $success = "ลงทะเบียนสำเร็จ! รหัสนักศึกษา: {$studentId}";
            error_log("Registration successful for student: $studentId");

            // Log การลงทะเบียน (ถ้าฟังก์ชันมี)
            if (function_exists('logActivity')) {
                try {
                    logActivity($studentId, 'register', 'Student registered successfully');
                } catch (Exception $e) {
                    error_log("Log activity failed: " . $e->getMessage());
                }
            }

            // ล้างข้อมูลฟอร์ม
            $_POST = [];
        } else {
            throw new Exception('ไม่สามารถบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง');
        }

        error_log("=== REGISTRATION ATTEMPT COMPLETED SUCCESSFULLY ===");
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("=== REGISTRATION ATTEMPT FAILED ===");
        error_log("Registration error: " . $error);
        error_log("Stack trace: " . $e->getTraceAsString());
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลงทะเบียนนักศึกษา - ระบบข้อร้องเรียน RMUTI</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }

        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.2rem;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .register-body {
            padding: 40px 30px;
        }

        .status-info {
            background: #e7f3ff;
            border: 1px solid #b3d7ff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }

        .status-info.error {
            background: #ffe6e6;
            border-color: #ffb3b3;
            color: #cc0000;
        }

        .status-info.warning {
            background: #fff3cd;
            border-color: #ffeaa7;
            color: #856404;
        }

        .status-info.debug {
            background: #f8f9fa;
            border-color: #dee2e6;
            color: #495057;
            text-align: left;
            font-family: monospace;
            font-size: 0.9rem;
        }

        .form-title {
            text-align: center;
            margin-bottom: 30px;
        }

        .form-title h2 {
            color: #333;
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .form-title p {
            color: #666;
            font-size: 1rem;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .required {
            color: #e74c3c;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            color: #666;
            padding: 5px;
        }

        .toggle-password:hover {
            color: #333;
        }

        .input-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }

        /* Select with Icon Styles */
        .select-with-icon {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .select-with-icon select {
            flex: 1;
        }

        .selected-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            font-size: 1.5rem;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: all 0.3s ease;
            flex-shrink: 0;
            animation: iconPop 0.3s ease;
        }

        @keyframes iconPop {
            0% { transform: scale(0.5); opacity: 0; }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }

        .selected-icon.empty {
            background: #e9ecef;
            box-shadow: none;
            animation: none;
        }

        .selected-icon.empty::after {
            content: '❓';
            font-size: 1.2rem;
            opacity: 0.5;
        }

        .form-group select option {
            padding: 10px;
            font-size: 14px;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-bottom: 15px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .alert-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }

        .alert-modal {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: scaleIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .alert-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
        }

        .alert-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #333;
        }

        .alert-message {
            color: #666;
            font-size: 1rem;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        .alert-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .alert-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .alert-error .alert-icon {
            color: #e74c3c;
        }

        .alert-error .alert-title {
            color: #e74c3c;
        }

        .alert-success .alert-icon {
            color: #27ae60;
        }

        .alert-success .alert-title {
            color: #27ae60;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .register-body {
                padding: 30px 20px;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .header h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="register-card">
            <div class="header">
                <h1>🎓 ลงทะเบียนนักศึกษา</h1>
                <p>ระบบข้อร้องเรียนออนไลน์ - มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</p>
            </div>

            <div class="register-body">
                <?php if ($debugMode): ?>
                    <!-- <div class="status-info debug">
                        <strong>🔧 Debug Information:</strong><br>
                        Database Connected: <?php echo $dbConnected ? 'Yes' : 'No'; ?><br>
                        Faculties Loaded: <?php echo count($faculties); ?><br>
                        Auth Available: <?php echo $authAvailable ? 'Yes' : 'No'; ?><br>
                        PHP Version: <?php echo PHP_VERSION; ?><br>
                        Error Log: Check server logs for detailed information
                    </div> -->
                <?php endif; ?>

                <?php if (!$dbConnected): ?>
                    <div class="status-info error">
                        <h3>⚠️ ไม่สามารถเชื่อมต่อฐานข้อมูลได้</h3>
                        <p>กรุณาตรวจสอบการตั้งค่าฐานข้อมูลและลองใหม่อีกครั้ง</p>
                        <?php if ($error): ?>
                            <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                                Error: <?php echo htmlspecialchars($error); ?>
                            </p>
                        <?php endif; ?>
                        <div style="margin-top: 20px;">
                            <a href="javascript:window.location.reload();" class="btn btn-secondary">
                                🔄 ลองใหม่
                            </a>
                        </div>
                    </div>
                <?php elseif (empty($faculties)): ?>
                    <div class="status-info error">
                        <h3>⚠️ ไม่พบข้อมูลคณะ</h3>
                        <p>กรุณาตรวจสอบว่าได้นำเข้าไฟล์ฐานข้อมูลแล้วหรือไม่</p>
                        <div style="margin-top: 20px;">
                            <a href="javascript:window.location.reload();" class="btn btn-secondary">
                                🔄 ลองใหม่
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if (!$authAvailable): ?>
                        <div class="status-info warning">
                            <h3>⚠️ ระบบยืนยันตัวตนไม่พร้อมใช้งาน</h3>
                            <p>คุณยังสามารถลงทะเบียนได้ แต่อาจไม่สามารถตรวจสอบสถานะการล็อกอินได้</p>
                        </div>
                    <?php endif; ?>

                    <div class="form-title">
                        <h2>✨ สร้างบัญชีใหม่</h2>
                        <p>กรุณากรอกข้อมูลให้ครบถ้วนเพื่อใช้งานระบบ</p>
                    </div>

                    <form method="POST" id="registerForm">
                        <input type="hidden" name="register" value="1">

                        <!-- ข้อมูลพื้นฐาน -->
                        <div class="form-group">
                            <label for="student_id">รหัสนักศึกษา <span class="required">*</span></label>
                            <input type="text" id="student_id" name="student_id"
                                placeholder="เช่น 66342310092-2 หรือ 6634231009220"
                                maxlength="13"
                                value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>"
                                required>
                            <div class="input-hint">รหัสนักศึกษา 13 ตัวอักษร (ตัวเลขและ - เท่านั้น)</div>
                        </div>

                        <div class="form-group">
                            <label for="full_name">ชื่อ-นามสกุล <span class="required">*</span></label>
                            <input type="text" id="full_name" name="full_name"
                                placeholder="ชื่อเต็ม เช่น นายสมศักดิ์ ใจดี"
                                maxlength="50"
                                value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                                required>
                            <div class="input-hint">ชื่อ-นามสกุลต้องไม่เกิน 50 ตัวอักษร</div>
                        </div>

                        <!-- ข้อมูลการศึกษา -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="faculty_id">คณะ <span class="required">*</span></label>
                                <div class="select-with-icon">
                                    <div class="selected-icon empty" id="faculty_icon"></div>
                                    <select id="faculty_id" name="faculty_id" required onchange="loadMajors(this.value); updateFacultyIcon(this);">
                                        <option value="" data-icon="">-- เลือกคณะ --</option>
                                        <?php foreach ($faculties as $faculty): ?>
                                            <option value="<?php echo $faculty['Unit_id']; ?>"
                                                data-icon="<?php echo htmlspecialchars($faculty['Unit_icon'] ?? '🎓'); ?>"
                                                <?php echo (isset($_POST['faculty_id']) && $_POST['faculty_id'] == $faculty['Unit_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(($faculty['Unit_icon'] ?? '🎓') . ' ' . $faculty['Unit_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="major_id">สาขาวิชา <span class="required">*</span></label>
                                <div class="select-with-icon">
                                    <div class="selected-icon empty" id="major_icon"></div>
                                    <select id="major_id" name="major_id" required onchange="updateMajorIcon(this);">
                                        <option value="" data-icon="">-- เลือกสาขาวิชา --</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- ข้อมูลติดต่อ -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">เบอร์โทรศัพท์</label>
                                <input type="tel" id="phone" name="phone"
                                    placeholder="เช่น 0812345678"
                                    maxlength="10"
                                    value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                <div class="input-hint">ตัวเลข 10 หลัก (ไม่บังคับ)</div>
                            </div>

                            <div class="form-group">
                                <label for="email">อีเมล</label>
                                <input type="email" id="email" name="email"
                                    placeholder="เช่น student@rmuti.ac.th"
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                <div class="input-hint">สำหรับรับการแจ้งเตือน (ไม่บังคับ)</div>
                            </div>
                        </div>

                        <!-- รหัสผ่าน -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="password">รหัสผ่าน <span class="required">*</span></label>
                                <div class="password-wrapper">
                                    <input type="password" id="password" name="password"
                                        placeholder="6-10 ตัวอักษร"
                                        minlength="6" maxlength="10" required>
                                    <button type="button" class="toggle-password" onclick="togglePassword('password', this)">
                                        👁️
                                    </button>
                                </div>
                                <div class="input-hint">ความยาว 6-10 ตัวอักษร</div>
                            </div>

                            <div class="form-group">
                                <label for="confirm_password">ยืนยันรหัสผ่าน <span class="required">*</span></label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirm_password" name="confirm_password"
                                        placeholder="กรอกรหัสผ่านอีกครั้ง"
                                        minlength="6" maxlength="10" required>
                                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password', this)">
                                        👁️
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn" id="submitBtn">
                            🚀 ลงทะเบียน
                        </button>
                    </form>
                <?php endif; ?>

                <div class="back-link">
                    <a href="login.php">← กลับไปหน้าเข้าสู่ระบบ</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            button.textContent = isPassword ? '🙈' : '👁️';
        }

        function showAlert(type, title, message, callback = null) {
            const overlay = document.createElement('div');
            overlay.className = 'alert-overlay';

            const modal = document.createElement('div');
            modal.className = `alert-modal alert-${type}`;

            const icons = {
                'error': '❌',
                'success': '✅',
                'warning': '⚠️'
            };

            modal.innerHTML = `
                <span class="alert-icon">${icons[type]}</span>
                <div class="alert-title">${title}</div>
                <div class="alert-message">${message}</div>
                <button class="alert-btn" onclick="closeAlert()">ตกลง</button>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            window.closeAlert = function() {
                document.body.removeChild(overlay);
                if (callback) callback();
            };

            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeAlert();
            });
        }

        function loadMajors(facultyId) {
            const majorSelect = document.getElementById('major_id');
            const majorIcon = document.getElementById('major_icon');
            
            majorSelect.innerHTML = '<option value="" data-icon="">-- กำลังโหลด... --</option>';
            majorSelect.disabled = true;
            
            // Reset major icon
            majorIcon.className = 'selected-icon empty';
            majorIcon.textContent = '';

            if (!facultyId) {
                majorSelect.innerHTML = '<option value="" data-icon="">-- เลือกสาขาวิชา --</option>';
                majorSelect.disabled = false;
                return;
            }

            const formData = new FormData();
            formData.append('get_majors', '1');
            formData.append('faculty_id', facultyId);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    majorSelect.innerHTML = '<option value="" data-icon="">-- เลือกสาขาวิชา --</option>';
                    majorSelect.disabled = false;

                    if (data.error) {
                        console.error('Error:', data.error);
                        majorSelect.innerHTML = '<option value="" data-icon="">-- เกิดข้อผิดพลาด --</option>';
                        showAlert('error', 'เกิดข้อผิดพลาด', data.error);
                    } else if (data && data.length > 0) {
                        data.forEach(major => {
                            const option = document.createElement('option');
                            option.value = major.Unit_id;
                            const icon = major.Unit_icon || '📚';
                            option.setAttribute('data-icon', icon);
                            option.textContent = icon + ' ' + major.Unit_name;
                            majorSelect.appendChild(option);
                        });
                    } else {
                        majorSelect.innerHTML = '<option value="" data-icon="">-- ไม่พบสาขาวิชา --</option>';
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    majorSelect.innerHTML = '<option value="" data-icon="">-- เกิดข้อผิดพลาด --</option>';
                    majorSelect.disabled = false;
                    showAlert('error', 'เกิดข้อผิดพลาด', 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้');
                });
        }

        // Update faculty icon when selection changes
        function updateFacultyIcon(selectElement) {
            const iconElement = document.getElementById('faculty_icon');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const icon = selectedOption.getAttribute('data-icon');
            
            if (icon && icon.trim() !== '') {
                iconElement.textContent = icon;
                iconElement.className = 'selected-icon';
            } else {
                iconElement.textContent = '';
                iconElement.className = 'selected-icon empty';
            }
        }

        // Update major icon when selection changes
        function updateMajorIcon(selectElement) {
            const iconElement = document.getElementById('major_icon');
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const icon = selectedOption.getAttribute('data-icon');
            
            if (icon && icon.trim() !== '') {
                iconElement.textContent = icon;
                iconElement.className = 'selected-icon';
            } else {
                iconElement.textContent = '';
                iconElement.className = 'selected-icon empty';
            }
        }

        // Auto-format student ID
        document.getElementById('student_id')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9\-]/g, '');
            if (value.length > 13) value = value.substring(0, 13);
            e.target.value = value;
        });

        // Auto-format phone number
        document.getElementById('phone')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9]/g, '');
            if (value.length > 10) value = value.substring(0, 10);
            e.target.value = value;
        });

        // Password validation
        document.getElementById('confirm_password')?.addEventListener('input', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = e.target.value;

            if (confirmPassword && password !== confirmPassword) {
                e.target.setCustomValidity('รหัสผ่านไม่ตรงกัน');
            } else {
                e.target.setCustomValidity('');
            }
        });

        // Form submission handling
        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (password !== confirmPassword) {
                e.preventDefault();
                showAlert('error', 'รหัสผ่านไม่ตรงกัน', 'กรุณาตรวจสอบรหัสผ่านและยืนยันรหัสผ่านให้ตรงกัน');
                return;
            }

            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ กำลังประมวลผล...';

            // Re-enable button after 10 seconds to prevent permanent disable
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '🚀 ลงทะเบียน';
                }
            }, 10000);
        });

        // Load majors if faculty is pre-selected
        document.addEventListener('DOMContentLoaded', function() {
            const facultySelect = document.getElementById('faculty_id');
            
            // Initialize faculty icon if pre-selected
            if (facultySelect) {
                updateFacultyIcon(facultySelect);
                
                if (facultySelect.value) {
                    loadMajors(facultySelect.value);

                    // Restore selected major after loading
                    setTimeout(() => {
                        const savedMajor = '<?php echo $_POST['major_id'] ?? ''; ?>';
                        if (savedMajor) {
                            const majorSelect = document.getElementById('major_id');
                            if (majorSelect) {
                                majorSelect.value = savedMajor;
                                updateMajorIcon(majorSelect);
                            }
                        }
                    }, 1000);
                }
            }
        });

        // Show alerts
        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showAlert('error', 'เกิดข้อผิดพลาด', '<?php echo addslashes($error); ?>');
            });
        <?php endif; ?>

        <?php if ($success): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showAlert('success', 'ลงทะเบียนสำเร็จ!', '<?php echo addslashes($success); ?>', function() {
                    window.location.href = 'login.php?message=register_success';
                });
            });
        <?php endif; ?>
    </script>
</body>

</html>