<?php

/**
 * ระบบลืมรหัสผ่าน - Re-check Verification
 * ยืนยันตัวตนด้วย: รหัสนักศึกษา + อีเมล + เบอร์โทรศัพท์
 */

define('SECURE_ACCESS', true);
session_start();

// ===== DEBUG MODE - ตั้งเป็น false เมื่อใช้งานจริง =====
$debugMode = false;

// Include required files
$configPath = '../config/config.php';
$dbPath = '../config/database.php';

$configLoaded = file_exists($configPath);
$dbFileLoaded = file_exists($dbPath);

if ($configLoaded) require_once $configPath;
if ($dbFileLoaded) require_once $dbPath;

// Initialize variables
$error = '';
$success = '';
$step = 1;
$verifiedStudent = null;
$debugInfo = [];

// Database connection
$db = null;
$dbConnected = false;

try {
    if (function_exists('getDB')) {
        $db = getDB();
        if ($db) {
            $testQuery = $db->fetch("SELECT 1 as test");
            if ($testQuery) {
                $dbConnected = true;
                $debugInfo[] = "✅ DB เชื่อมต่อสำเร็จ";
            }
        }
    } else {
        $debugInfo[] = "❌ ไม่พบฟังก์ชัน getDB()";
    }
} catch (Exception $e) {
    $debugInfo[] = "❌ DB Error: " . $e->getMessage();
}

$debugInfo[] = "Config: " . ($configLoaded ? '✅' : '❌');
$debugInfo[] = "DB File: " . ($dbFileLoaded ? '✅' : '❌');
$debugInfo[] = "Connected: " . ($dbConnected ? '✅' : '❌');
$debugInfo[] = "Method: " . $_SERVER['REQUEST_METHOD'];

// Rate Limiting
$maxAttempts = 5;
$lockoutTime = 30 * 60;

if (!isset($_SESSION['forgot_attempts'])) {
    $_SESSION['forgot_attempts'] = 0;
    $_SESSION['forgot_first_attempt'] = time();
}

if (time() - $_SESSION['forgot_first_attempt'] > $lockoutTime) {
    $_SESSION['forgot_attempts'] = 0;
    $_SESSION['forgot_first_attempt'] = time();
}

$isLocked = $_SESSION['forgot_attempts'] >= $maxAttempts;
$remainingTime = $lockoutTime - (time() - $_SESSION['forgot_first_attempt']);

// ===== HANDLE POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $debugInfo[] = "📨 POST received!";
    $debugInfo[] = "POST data: " . json_encode($_POST);

    if (!$dbConnected) {
        $error = 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้';
    } elseif ($isLocked) {
        $error = 'ถูกระงับชั่วคราว กรุณารอ ' . ceil($remainingTime / 60) . ' นาที';
    } else {
        // Step 1: Verify Identity - ตรวจจาก field ที่มี
        if (isset($_POST['student_id']) && isset($_POST['email']) && isset($_POST['phone']) && !isset($_POST['new_password'])) {
            $debugInfo[] = "🔍 Step 1: Verify Identity";

            $studentId = trim($_POST['student_id'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            $debugInfo[] = "Input: ID={$studentId}, Email={$email}, Phone={$phone}";

            if (empty($studentId) || empty($email) || empty($phone)) {
                $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
                $_SESSION['forgot_attempts']++;
            } else {
                try {
                    $student = $db->fetch("
                        SELECT Stu_id, Stu_name, Stu_email, Stu_tel, Stu_status
                        FROM student 
                        WHERE Stu_id = ? AND Stu_email = ? AND Stu_tel = ?
                    ", [$studentId, $email, $phone]);

                    if ($student) {
                        $debugInfo[] = "✅ พบข้อมูลนักศึกษา: " . $student['Stu_name'];

                        if ($student['Stu_status'] == 0) {
                            $error = 'บัญชีนี้ถูกระงับการใช้งาน';
                        } else {
                            $step = 2;
                            $_SESSION['verified_student_id'] = $student['Stu_id'];
                            $_SESSION['verified_student_name'] = $student['Stu_name'];
                            $_SESSION['verification_time'] = time();
                            $verifiedStudent = $student;
                            $_SESSION['forgot_attempts'] = 0;
                        }
                    } else {
                        $error = 'ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบอีกครั้ง';
                        $_SESSION['forgot_attempts']++;
                        $debugInfo[] = "❌ ไม่พบข้อมูลที่ตรงกัน";

                        // Debug: ค้นหาเฉพาะ student_id
                        $check = $db->fetch("SELECT Stu_email, Stu_tel FROM student WHERE Stu_id = ?", [$studentId]);
                        if ($check) {
                            $debugInfo[] = "🔎 พบรหัส นศ. - Email ในระบบ: {$check['Stu_email']}";
                            $debugInfo[] = "🔎 พบรหัส นศ. - เบอร์ในระบบ: {$check['Stu_tel']}";
                        } else {
                            $debugInfo[] = "🔎 ไม่พบรหัส นศ. นี้ในระบบ";
                        }
                    }
                } catch (Exception $e) {
                    $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                    $debugInfo[] = "❌ Exception: " . $e->getMessage();
                }
            }
        }

        // Step 2: Reset Password - ตรวจจาก field ที่มี
        if (isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
            $debugInfo[] = "🔑 Step 2: Reset Password";

            if (!isset($_SESSION['verified_student_id'])) {
                $error = 'กรุณายืนยันตัวตนก่อน';
                $step = 1;
            } elseif (time() - $_SESSION['verification_time'] > 600) {
                $error = 'หมดเวลา กรุณาเริ่มใหม่';
                unset($_SESSION['verified_student_id'], $_SESSION['verified_student_name'], $_SESSION['verification_time']);
                $step = 1;
            } else {
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                if (empty($newPassword) || empty($confirmPassword)) {
                    $error = 'กรุณากรอกรหัสผ่านให้ครบ';
                    $step = 2;
                } elseif (strlen($newPassword) < 6) {
                    $error = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
                    $step = 2;
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'รหัสผ่านไม่ตรงกัน';
                    $step = 2;
                } else {
                    try {
                        $result = $db->execute("
                            UPDATE student SET Stu_password = ?, updated_at = NOW() WHERE Stu_id = ?
                        ", [$newPassword, $_SESSION['verified_student_id']]);

                        $success = 'เปลี่ยนรหัสผ่านสำเร็จ!';
                        unset($_SESSION['verified_student_id'], $_SESSION['verified_student_name'], $_SESSION['verification_time']);
                        $step = 1;
                        $debugInfo[] = "✅ อัปเดตรหัสผ่านสำเร็จ";
                    } catch (Exception $e) {
                        $error = 'ไม่สามารถเปลี่ยนรหัสผ่านได้';
                        $step = 2;
                        $debugInfo[] = "❌ Update Error: " . $e->getMessage();
                    }
                }

                if ($step == 2) {
                    $verifiedStudent = [
                        'Stu_id' => $_SESSION['verified_student_id'],
                        'Stu_name' => $_SESSION['verified_student_name']
                    ];
                }
            }
        }
    }
}

// Check existing session
if (isset($_SESSION['verified_student_id']) && isset($_SESSION['verification_time'])) {
    if (time() - $_SESSION['verification_time'] <= 600) {
        $step = 2;
        $verifiedStudent = [
            'Stu_id' => $_SESSION['verified_student_id'],
            'Stu_name' => $_SESSION['verified_student_name']
        ];
    } else {
        unset($_SESSION['verified_student_id'], $_SESSION['verified_student_name'], $_SESSION['verification_time']);
    }
}

// Handle cancel
if (isset($_GET['cancel'])) {
    unset($_SESSION['verified_student_id'], $_SESSION['verified_student_name'], $_SESSION['verification_time']);
    header('Location: forgot_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ลืมรหัสผ่าน - RMUTI</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            max-width: 500px;
            width: 100%;
        }

        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .header p {
            opacity: 0.9;
        }

        .body {
            padding: 30px;
        }

        /* Debug Box */
        .debug-box {
            background: #f8f9fa;
            border: 2px dashed #6c757d;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }

        .debug-box h4 {
            color: #495057;
            margin-bottom: 10px;
        }

        .debug-box ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .debug-box li {
            padding: 3px 0;
            border-bottom: 1px solid #eee;
        }

        /* Progress */
        .progress {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #aaa;
        }

        .step.active {
            color: #667eea;
            font-weight: 600;
        }

        .step.done {
            color: #27ae60;
        }

        .step-num {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #eee;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .step.active .step-num {
            background: #667eea;
            color: white;
        }

        .step.done .step-num {
            background: #27ae60;
            color: white;
        }

        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #ffe6e6;
            border: 1px solid #ffb3b3;
            color: #c0392b;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #a3d9a5;
            color: #155724;
        }

        /* Info Box */
        .info-box {
            background: #e8f4fd;
            border-left: 4px solid #667eea;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .info-box h3 {
            color: #333;
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .info-box p {
            color: #666;
            font-size: 0.9rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .hint {
            font-size: 0.8rem;
            color: #888;
            margin-top: 5px;
        }

        .required {
            color: #e74c3c;
        }

        /* Verified Badge */
        .verified {
            background: #27ae60;
            color: white;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .verified-icon {
            font-size: 2rem;
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-success {
            background: #27ae60;
            color: white;
            margin-top: 10px;
        }

        .btn-secondary {
            background: #dc3545;
            color: white;
            margin-top: 10px;
        }
        
        .btn-secondary:hover {
            background: #c82333;
        }

        /* Links */
        .links {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        .links a {
            color: #667eea;
            text-decoration: none;
            margin: 0 10px;
        }

        .links a:hover {
            text-decoration: underline;
        }

        /* Attempts Warning */
        .attempts {
            color: #e74c3c;
            font-size: 0.85rem;
            text-align: center;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>🔐 ลืมรหัสผ่าน</h1>
                <p>ยืนยันตัวตนเพื่อตั้งรหัสผ่านใหม่</p>
            </div>

            <div class="body">
                <!-- Debug Info -->
                <?php if ($debugMode): ?>
                    <div class="debug-box">
                        <h4>🔧 Debug Info (ปิดได้โดยตั้ง $debugMode = false)</h4>
                        <ul>
                            <?php foreach ($debugInfo as $info): ?>
                                <li><?php echo htmlspecialchars($info); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Progress Steps -->
                <div class="progress">
                    <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>">
                        <span class="step-num"><?php echo $step > 1 ? '✓' : '1'; ?></span>
                        <span>ยืนยันตัวตน</span>
                    </div>
                    <div class="step <?php echo $step == 2 ? 'active' : ''; ?>">
                        <span class="step-num">2</span>
                        <span>ตั้งรหัสใหม่</span>
                    </div>
                </div>

                <!-- Error Alert -->
                <?php if ($error): ?>
                    <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
                    <?php if ($_SESSION['forgot_attempts'] > 0 && $_SESSION['forgot_attempts'] < $maxAttempts): ?>
                        <p class="attempts">⚠️ เหลือโอกาสอีก <?php echo $maxAttempts - $_SESSION['forgot_attempts']; ?> ครั้ง</p>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Success Alert -->
                <?php if ($success): ?>
                    <div class="alert alert-success">✅ <?php echo htmlspecialchars($success); ?></div>
                    <a href="login.php" class="btn btn-success">เข้าสู่ระบบเลย</a>
                <?php endif; ?>

                <!-- Step 1: Verify Identity -->
                <?php if (!$success && $step == 1): ?>
                    <div class="info-box">
                        <h3>🔍 ยืนยันตัวตน</h3>
                        <p>กรอกข้อมูล <strong>ทั้ง 3 รายการ</strong> ให้ตรงกับที่ลงทะเบียนไว้</p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="verify_identity" value="1">
                        <div class="form-group">
                            <label>รหัสนักศึกษา <span class="required">*</span></label>
                            <input type="text" name="student_id" placeholder="เช่น 66342310092-2" maxlength="13"
                                value="<?php echo htmlspecialchars($_POST['student_id'] ?? ''); ?>" required>
                            <div class="hint">รหัส 13 หลัก (รวมขีด)</div>
                        </div>

                        <div class="form-group">
                            <label>อีเมลที่ลงทะเบียน <span class="required">*</span></label>
                            <input type="email" name="email" placeholder="example@rmuti.ac.th"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                            <div class="hint">อีเมลที่ใช้ตอนสมัครสมาชิก</div>
                        </div>

                        <div class="form-group">
                            <label>เบอร์โทรศัพท์ <span class="required">*</span></label>
                            <input type="tel" name="phone" placeholder="0812345678" maxlength="10"
                                value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                            <div class="hint">เบอร์ 10 หลักที่ลงทะเบียนไว้</div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            ตรวจสอบข้อมูล
                        </button>
                    </form>
                <?php endif; ?>

                <!-- Step 2: Reset Password -->
                <?php if (!$success && $step == 2 && $verifiedStudent): ?>
                    <div class="verified">
                        <span class="verified-icon">✅</span>
                        <div>
                            <small>ยืนยันตัวตนสำเร็จ</small>
                            <div><strong><?php echo htmlspecialchars($verifiedStudent['Stu_name']); ?></strong></div>
                        </div>
                    </div>

                    <div class="info-box" style="background: #fff3cd; border-color: #f0ad4e;">
                        <h3>⏱️ หมดเวลาใน 10 นาที</h3>
                        <p>กรุณาตั้งรหัสผ่านใหม่ให้เสร็จภายในเวลาที่กำหนด</p>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="reset_password" value="1">
                        <div class="form-group">
                            <label>รหัสผ่านใหม่ <span class="required">*</span></label>
                            <input type="password" name="new_password" placeholder="อย่างน้อย 6 ตัวอักษร" minlength="6" required>
                        </div>

                        <div class="form-group">
                            <label>ยืนยันรหัสผ่านใหม่ <span class="required">*</span></label>
                            <input type="password" name="confirm_password" placeholder="กรอกรหัสผ่านอีกครั้ง" minlength="6" required>
                        </div>

                        <button type="submit" class="btn btn-success">
                            เปลี่ยนรหัสผ่าน
                        </button>

                        <a href="?cancel=1" class="btn btn-secondary" style="display: block; text-align: center; text-decoration: none;">
                            ยกเลิก
                        </a>
                    </form>
                <?php endif; ?>

                <!-- Links -->
                <div class="links">
                    <a href="login.php">← กลับหน้าเข้าสู่ระบบ</a>
                    <span style="color: #ccc;">|</span>
                    <a href="register.php">สมัครสมาชิก →</a>
                </div>
            </div>
        </div>
    </div>
</body>

</html>