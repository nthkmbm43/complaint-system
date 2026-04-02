<?php
// หน้า Login เจ้าหน้าที่ - login.php (Production Version)
define('SECURE_ACCESS', true);

// เริ่ม session ก่อน
session_start();

try {
    require_once '../config/config.php';
} catch (Exception $e) {
    die("Error loading config: " . $e->getMessage());
}

$error = '';
$success = '';
$loginResult = null;

// ตรวจสอบข้อความจาก URL
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'logout_success':
            $success = 'ออกจากระบบเรียบร้อยแล้ว';
            break;
        case 'session_expired':
            $error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';
            break;
        case 'permission_denied':
            $error = 'คุณไม่มีสิทธิ์เข้าถึงระบบนี้';
            break;
    }
}

// สร้างการเชื่อมต่อฐานข้อมูลโดยตรง
function getDirectDBConnection()
{
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
    }
}

// ตรวจสอบว่าเข้าสู่ระบบแล้วหรือไม่
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'teacher') {
    // ตรวจสอบ session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) < SESSION_TIMEOUT) {
        // ยังไม่หมดอายุ - redirect ตามสิทธิ์
        $_SESSION['last_activity'] = time();
        if (isset($_SESSION['permission']) && $_SESSION['permission'] == 2) {
            // Admin (สิทธิ์ 2) → ไปหน้า dashboard
            header('Location: dashboard.php?already_logged_in=1');
        } else {
            // อาจารย์/เจ้าหน้าที่ (สิทธิ์ 1) → ไปหน้า index
            header('Location: index.php?already_logged_in=1');
        }
        exit;
    } else {
        // หมดอายุแล้ว - ล้าง session
        session_destroy();
        session_start();
        $error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';
    }
}

// ประมวลผลการล็อกอิน
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['login']) || isset($_POST['form_submitted']))) {
    $identifier = trim($_POST['identifier']); // รหัสหรือชื่อเจ้าหน้าที่
    $password = $_POST['password'];

    if (empty($identifier) || empty($password)) {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
        $loginResult = 'empty_fields';
    } else {
        try {
            // เชื่อมต่อฐานข้อมูลโดยตรง
            $pdo = getDirectDBConnection();

            // ค้นหาเจ้าหน้าที่ พร้อมดึงข้อมูลหน่วยงานจาก organization_unit
            $sql = "SELECT t.*, 
                           ou.Unit_name, ou.Unit_type, ou.Unit_icon,
                           CASE ou.Unit_type
                               WHEN 'faculty'    THEN 'คณะ'
                               WHEN 'major'      THEN 'สาขา'
                               WHEN 'department' THEN 'แผนก/หน่วยงาน'
                               ELSE ''
                           END as Unit_type_thai
                    FROM teacher t
                    LEFT JOIN organization_unit ou ON t.Unit_id = ou.Unit_id
                    WHERE (t.Aj_id = ? OR t.Aj_name = ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$identifier, $identifier]);
            $teacher = $stmt->fetch();

            if (!$teacher) {
                $error = 'ไม่พบรหัสเจ้าหน้าที่ที่ระบุ';
                $loginResult = 'user_not_found';
            } else {
                // ตรวจสอบรหัสผ่านแบบ plaintext
                $dbPassword = trim($teacher['Aj_password']);
                $inputPassword = trim($password);

                if ($inputPassword !== $dbPassword) {
                    $error = 'รหัสผ่านไม่ถูกต้อง';
                    $loginResult = 'wrong_password';
                } else {
                    // รหัสผ่านถูกต้อง - สร้าง session
                    session_destroy();
                    session_start();
                    session_regenerate_id(true);

                    // สร้าง session ใหม่
                    $_SESSION['user_id'] = $teacher['Aj_id'];
                    $_SESSION['user_role'] = 'teacher';
                    $_SESSION['user_name'] = $teacher['Aj_name'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['teacher_id'] = $teacher['Aj_id'];
                    $_SESSION['position'] = $teacher['Aj_position'];
                    $_SESSION['permission'] = $teacher['Aj_per'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['last_activity'] = time();

                    // *** set ข้อมูลหน่วยงาน (จำเป็นสำหรับการกรองข้อร้องเรียนตาม scope) ***
                    $_SESSION['unit_id']        = $teacher['Unit_id'];       // int หรือ null
                    $_SESSION['unit_type']      = $teacher['Unit_type'] ?? '';   // faculty|major|department
                    $_SESSION['unit_name']      = $teacher['Unit_name'] ?? '';
                    $_SESSION['unit_icon']      = $teacher['Unit_icon'] ?? '🏢';
                    $_SESSION['unit_type_thai'] = $teacher['Unit_type_thai'] ?? '';

                    // กำหนดสิทธิ์พิเศษสำหรับ admin (สิทธิ์ 2)
                    if ($teacher['Aj_per'] == 2) {
                        $_SESSION['is_admin'] = true;
                    }

                    // กำหนดข้อความระดับสิทธิ์
                    $permissionText = '';
                    switch ($teacher['Aj_per']) {
                        case 2:
                            $permissionText = 'ผู้ดูแลระบบ';
                            break;
                        case 1:
                        default:
                            $permissionText = 'อาจารย์/เจ้าหน้าที่';
                            break;
                    }
                    $_SESSION['permission_text'] = $permissionText;

                    // บันทึก session
                    session_write_close();
                    session_start();

                    $loginResult = 'success';
                    $success = 'เข้าสู่ระบบสำเร็จ กำลังเปลี่ยนหน้า...';

                    // Redirect ตามสิทธิ์ผู้ใช้
                    if ($teacher['Aj_per'] == 2) {
                        // Admin (สิทธิ์ 2) → ไปหน้า dashboard
                        header('Location: dashboard.php?welcome=1&permission=' . $teacher['Aj_per']);
                    } else {
                        // อาจารย์/เจ้าหน้าที่ (สิทธิ์ 1) → ไปหน้า index
                        header('Location: index.php?welcome=1&permission=' . $teacher['Aj_per']);
                    }
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
            $loginResult = 'system_error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบเจ้าหน้าที่ - ระบบข้อร้องเรียนนักศึกษา</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
            margin: 20px;
            animation: fadeIn 0.5s ease-in-out;
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

        .university-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .university-header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .university-header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .login-content {
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .login-header .icon {
            font-size: 4rem;
            margin-bottom: 20px;
            display: block;
        }

        .login-header h2 {
            font-size: 2rem;
            margin-bottom: 10px;
            color: #333;
        }

        .login-header p {
            color: #666;
            font-size: 1.1rem;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }

        .required {
            color: red;
            font-weight: bold;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #ff6b6b;
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.1);
        }

        .form-help {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }

        .password-input {
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
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }

        .toggle-password:hover {
            opacity: 1;
        }

        .checkbox-group {
            margin: 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            margin-bottom: 15px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-weight: 600;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }

        .status-indicator {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }

        .input-hint {
            background: #fff3cd;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #ffc107;
        }

        .input-hint h4 {
            color: #856404;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .input-hint p {
            color: #856404;
            font-size: 13px;
            margin: 0;
        }

        .security-note {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #2196f3;
        }

        .security-note .icon {
            font-size: 1.2rem;
        }

        .security-note strong {
            color: #1976d2;
        }

        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .login-container {
                margin: 10px;
            }

            .university-header {
                padding: 20px;
            }

            .university-header h1 {
                font-size: 1.4rem;
            }

            .login-content {
                padding: 30px 20px;
            }

            .login-header .icon {
                font-size: 3rem;
            }

            .login-header h2 {
                font-size: 1.5rem;
            }
        }

        /* ===== Modern Alert Modal ===== */
        .alert-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: overlayFadeIn 0.3s ease-out;
        }

        @keyframes overlayFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        .alert-modal {
            background: white;
            border-radius: 16px;
            padding: 32px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
            animation: modalSlideIn 0.3s ease-out;
            position: relative;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .alert-modal .alert-icon {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
        }

        .alert-modal .alert-title {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 12px;
            color: #333;
        }

        .alert-modal .alert-message {
            color: #666;
            font-size: 1rem;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        .alert-modal .alert-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .alert-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 80px;
        }

        .alert-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .alert-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .alert-error  .alert-icon  { color: #dc3545; }
        .alert-success .alert-icon { color: #28a745; }
        .alert-warning .alert-icon { color: #ffc107; }
        .alert-error  .alert-title  { color: #dc3545; }
        .alert-success .alert-title { color: #28a745; }
        .alert-warning .alert-title { color: #e67e22; }

        @media (max-width: 480px) {
            .alert-modal { padding: 24px; margin: 20px; }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <!-- University Header -->
        <div class="university-header">
            <h1>🎓 มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น</h1>
            <p>ระบบข้อร้องเรียนนักศึกษา - ส่วนเจ้าหน้าที่</p>
        </div>

        <div class="login-content">
            <!-- Login Header -->
            <div class="login-header">
                <span class="icon">👨‍🏫</span>
                <h2>เข้าสู่ระบบเจ้าหน้าที่</h2>
                <p>สำหรับอาจารย์และเจ้าหน้าที่มหาวิทยาลัย</p>
            </div>



            <!-- Alert Messages -->
            <!-- Login Form -->
            <form method="POST" class="login-form" action="">
                <!-- เพิ่ม hidden field เพื่อ debug -->
                <input type="hidden" name="form_submitted" value="1">

                <div class="form-group">
                    <label for="identifier">รหัสเจ้าหน้าที่ หรือ ชื่อผู้ใช้ <span class="required">*</span></label>
                    <input type="text" id="identifier" name="identifier" placeholder="กรอกรหัสเจ้าหน้าที่ หรือ ชื่อผู้ใช้"
                        value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>" required>
                    <small class="form-help">สามารถใช้รหัสเจ้าหน้าที่หรือชื่อผู้ใช้ได้</small>
                </div>

                <div class="form-group">
                    <label for="password">รหัสผ่าน <span class="required">*</span></label>
                    <div class="password-input">
                        <input type="password" id="password" name="password" placeholder="กรอกรหัสผ่าน" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">👁️</button>
                    </div>
                    <small class="form-help">กรอกรหัสผ่านที่ได้รับจากผู้ดูแลระบบ</small>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" name="remember_me" id="remember_me">
                    <label for="remember_me">จดจำการเข้าสู่ระบบ</label>
                </div>

                <button type="submit" name="login" value="1" class="btn btn-primary">
                    🔑 เข้าสู่ระบบ
                </button>
            </form>

            <!-- Security Note -->
            <div class="security-note">
                <span class="icon">🔒</span>
                <div>
                    <strong>ระบบความปลอดภัย:</strong> ใช้การยืนยันตัวตนที่ปลอดภัยและ session management ที่เข้มงวด
                </div>
            </div>

            <!-- Back to Home -->
            <div class="back-home">
                <a href="../index.php" class="btn btn-secondary">
                    ← กลับหน้าหลัก
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript สำหรับ Alert และ Form Enhancement -->
    <script>
        // ===== Modern Alert Function =====
        function showAlert(type, title, message, onClose = null) {
            const existingAlert = document.querySelector('.alert-overlay');
            if (existingAlert) existingAlert.remove();

            const overlay = document.createElement('div');
            overlay.className = 'alert-overlay';

            const modal = document.createElement('div');
            modal.className = `alert-modal alert-${type}`;

            const icons = { 'error': '❌', 'success': '✅', 'warning': '⚠️', 'info': 'ℹ️' };

            modal.innerHTML = `
                <span class="alert-icon">${icons[type] || '❌'}</span>
                <div class="alert-title">${title}</div>
                <div class="alert-message">${message}</div>
                <div class="alert-actions">
                    <button class="alert-btn alert-btn-primary" onclick="closeAlert()">ตกลง</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            window.closeAlert = function() {
                overlay.style.animation = 'overlayFadeIn 0.2s ease-out reverse';
                modal.style.animation = 'modalSlideIn 0.2s ease-out reverse';
                setTimeout(() => { overlay.remove(); if (onClose) onClose(); }, 200);
            };

            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) closeAlert();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeAlert();
            }, { once: true });
        }

        // แสดง Alert จาก PHP เมื่อโหลดหน้า
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($error): ?>
            (function() {
                const errorType = '<?php echo $error_type ?? "general"; ?>';
                const errorTypes = {
                    'validation':     { type: 'warning', title: 'ข้อมูลไม่ถูกต้อง' },
                    'authentication': { type: 'error',   title: 'การเข้าสู่ระบบล้มเหลว' },
                    'general':        { type: 'error',   title: 'เกิดข้อผิดพลาด' }
                };
                const info = errorTypes[errorType] || errorTypes['general'];
                showAlert(info.type, info.title, '<?php echo addslashes($error); ?>');
            })();
            <?php endif; ?>

            <?php if ($success): ?>
            showAlert('success', 'สำเร็จ!', '<?php echo addslashes($success); ?>');
            <?php endif; ?>

            // Auto focus
            document.getElementById('identifier').focus();
        });

        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.toggle-password');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                toggleButton.textContent = '👁️';
            }
        }

        // Enhanced form validation
        document.querySelector('.login-form').addEventListener('submit', function(e) {
            const identifier = document.getElementById('identifier').value.trim();
            const password = document.getElementById('password').value;

            if (!identifier || !password) {
                e.preventDefault();
                showAlert('warning', 'ข้อมูลไม่ครบถ้วน', 'กรุณากรอกชื่อผู้ใช้และรหัสผ่านให้ครบถ้วน');
                return false;
            }

            // Show loading state
            const submitButton = document.querySelector('button[name="login"]');
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '🔄 กำลังเข้าสู่ระบบ...';
            submitButton.disabled = true;

            // Reset button after 15 seconds in case of error
            setTimeout(() => {
                if (submitButton.disabled) {
                    submitButton.innerHTML = originalText;
                    submitButton.disabled = false;
                }
            }, 15000);

            return true;
        });

        // Handle Enter key in form fields
        document.getElementById('identifier').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('password').focus();
            }
        });

        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('button[name="login"]').click();
            }
        });
    </script>
</body>

</html>