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
                // ตรวจสอบรหัสผ่านแบบ hash (และ fallback เป็น plaintext)
                $dbPassword = trim($teacher['Aj_password']);
                $inputPassword = trim($password);
                
                $passwordValid = false;
                if (password_verify($inputPassword, $dbPassword)) {
                    $passwordValid = true;
                } elseif ($inputPassword === $dbPassword) {
                    $passwordValid = true;
                }

                if (!$passwordValid) {
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
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Kanit', sans-serif;
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px 0;
        color: #fff;
        position: relative;
        overflow-x: hidden;
    }

    /* Hide scrollbar */
    ::-webkit-scrollbar { display: none; }
    html { -ms-overflow-style: none; scrollbar-width: none; }

    .blob-1, .blob-2 {
        position: absolute; filter: blur(80px); z-index: -1; opacity: 0.3; border-radius: 50%;
        animation: float 10s infinite alternate;
    }
    .blob-1 { width: 350px; height: 350px; background: #1e88e5; top: -50px; left: -50px; }
    .blob-2 { width: 450px; height: 450px; background: #0f3460; bottom: -100px; right: -100px; animation-delay: -5s; }
    @keyframes float { 0% { transform: translateY(0) scale(1); } 100% { transform: translateY(50px) scale(1.1); } }

    .login-container { width: 100%; max-width: 500px; margin: 0 auto; padding: 15px; z-index: 10; }

    .university-header {
        text-align: center;
        margin-bottom: 25px;
    }
    .university-header h1 { font-size: 1.4rem; color: #bbdefb; font-weight: 600; margin-bottom: 5px; }
    @media (max-width: 480px) { .university-header h1 { font-size: 1.2rem; } }
    .university-header p { font-size: 0.95rem; opacity: 0.8; color: #fff;}

    .login-content {
        background: rgba(255, 255, 255, 0.05);
        backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 28px;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.3);
        animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        padding: 40px 35px;
    }
    @media (max-width: 480px) { .login-content { padding: 30px 20px; } }
    
    @keyframes slideUp { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }

    .login-header { text-align: center; margin-bottom: 30px; }
    .login-header .icon { font-size: 3.5rem; margin-bottom: 15px; display: block; filter: drop-shadow(0 4px 10px rgba(30, 136, 229, 0.4)); }
    .login-header h2 { font-size: 1.5rem; margin-bottom: 5px; font-weight: 500; color: #fff;}
    .login-header p { font-size: 0.95rem; opacity: 0.7; color: #fff;}

    .form-group { margin-bottom: 20px; text-align: left; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 400; opacity: 0.9; font-size: 0.95rem; color: #fff;}
    .required { color: #ef5350; font-weight: bold; }
    .form-help { font-size: 0.8rem; color: rgba(255,255,255,0.5); display: block; margin-top: 5px; }

    .form-group input, .form-group select {
        width: 100%; padding: 14px 16px; background: rgba(0, 0, 0, 0.2);
        border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px;
        font-size: 1rem; color: #fff; transition: all 0.3s ease; font-family: inherit;
    }
    .form-group input::placeholder { color: rgba(255, 255, 255, 0.3); }
    .form-group input:focus, .form-group select:focus {
        outline: none; border-color: #1e88e5; box-shadow: 0 0 0 4px rgba(30, 136, 229, 0.2); background: rgba(0, 0, 0, 0.3);
    }
    .form-group.error input { border-color: #ef5350; box-shadow: 0 0 0 4px rgba(239, 83, 80, 0.1); }

    .password-input { position: relative; }
    .toggle-password {
        position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
        background: none; border: none; cursor: pointer; font-size: 18px; padding: 5px;
        filter: grayscale(1); transition: filter 0.3s;
    }
    .toggle-password:hover { filter: grayscale(0); }

    .checkbox-group { display: flex; align-items: center; gap: 8px; margin-bottom: 25px; font-size: 0.95rem; }
    
    .btn {
        background: linear-gradient(45deg, #1565c0, #1e88e5); color: white;
        padding: 14px 30px; border: none; border-radius: 12px; font-size: 1.1rem;
        font-weight: 500; cursor: pointer; transition: all 0.3s ease;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        width: 100%; box-shadow: 0 4px 15px rgba(30, 136, 229, 0.3); text-decoration: none;
    }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(30, 136, 229, 0.5); }
    
    .btn-secondary { background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255,255,255,0.2); box-shadow: none; }
    .btn-secondary:hover { background: rgba(255, 255, 255, 0.2); box-shadow: none; }
    
    .security-note { margin-top: 25px; display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; color: rgba(255,255,255,0.6); line-height: 1.4; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); }
    .security-note .icon { font-size: 1.2rem; }

    .back-home { text-align: center; margin-top: 25px; }

    .alert-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px);
        z-index: 1000; display: flex; align-items: center; justify-content: center;
    }
    .alert-modal {
        background: #1e1e2e; border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 20px; padding: 32px; max-width: 400px; width: 90%;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); text-align: center; color: #fff;
    }
    .alert-icon { font-size: 3rem; margin-bottom: 16px; display: block; }
    .alert-title { font-size: 1.4rem; font-weight: 500; margin-bottom: 12px; }
    .alert-message { opacity: 0.8; font-size: 1rem; line-height: 1.5; margin-bottom: 24px; }
    .alert-btn {
        background: linear-gradient(45deg, #1565c0, #1e88e5); color: white;
        padding: 12px 24px; border: none; border-radius: 12px; font-size: 1rem;
        cursor: pointer; font-weight: 500;
    }
    .alert-error .alert-title { color: #ef5350; }
    .alert-success .alert-title { color: #1e88e5; }
    .alert-warning .alert-title { color: #ffb300; }
</style>
</head>

<body>
    <div class="blob-1"></div>
    <div class="blob-2"></div>
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