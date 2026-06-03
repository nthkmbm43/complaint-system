<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// ถ้าล็อกอินแล้วให้ redirect ไป dashboard
if (isLoggedIn() && hasRole('student')) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$error_type = 'general'; // general, validation, authentication

// ตรวจสอบข้อความจาก URL
if (isset($_GET['message'])) {
    switch ($_GET['message']) {
        case 'logout_success':
            $success = 'ออกจากระบบเรียบร้อยแล้ว';
            break;
        case 'session_expired':
            $error = 'Session หมดอายุ กรุณาเข้าสู่ระบบใหม่';
            $error_type = 'authentication';
            break;
        case 'register_success':
            $success = 'ลงทะเบียนสำเร็จ กรุณาเข้าสู่ระบบด้วยรหัสนักศึกษา';
            break;
    }
}

// ประมวลผลการล็อกอิน
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ตรวจสอบว่าเป็นการ submit ฟอร์มล็อกอินหรือไม่
    $isLoginSubmit = (
        isset($_POST['login']) ||
        (isset($_POST['student_id']) && isset($_POST['password']))
    );

    if ($isLoginSubmit) {
        // เก็บรหัสนักศึกษาตามที่ผู้ใช้กรอก (รวมทั้ง - ด้วย)
        $studentId = trim($_POST['student_id']);
        $password = $_POST['password'];

        if (empty($studentId) || empty($password)) {
            $error = 'กรุณากรอกรหัสนักศึกษาและรหัสผ่านให้ครบถ้วน';
            $error_type = 'validation';
        } elseif (strlen($studentId) !== 13) {
            $error = 'รหัสนักศึkษาต้องมีความยาว 13 ตัวอักษรเท่านั้น ปัจจุบัน: ' . strlen($studentId) . ' ตัวอักษร';
            $error_type = 'validation';
        } elseif (!preg_match('/^[\d\-]{13}$/', $studentId)) {
            $error = 'รหัสนักศึกษาต้องเป็นตัวเลขและ - รวมกัน 13 ตัวอักษร เช่น 66342310092-2 หรือ 6634231009220';
            $error_type = 'validation';
        } else {
            try {
                // ตรวจสอบว่า auth system พร้อมใช้งานหรือไม่
                $auth = getAuth();
                if (!$auth) {
                    throw new Exception("Auth system not available");
                }

                // ลองใช้รหัสนักศึกษาตามที่กรอก (รวม -)
                $result1 = $auth->login($studentId, $password, 'student');

                if (!$result1['success']) {
                    // ถ้าไม่สำเร็จ ลองลบ - ออก
                    $cleanStudentId = str_replace('-', '', $studentId);
                    $result2 = $auth->login($cleanStudentId, $password, 'student');

                    if ($result2['success']) {
                        $result = $result2;
                    } else {
                        $result = $result1; // ใช้ error message จากครั้งแรก
                    }
                } else {
                    $result = $result1;
                }

                if ($result['success']) {
                    header('Location: index.php');
                    exit;
                } else {
                    $error = $result['message'];
                    $error_type = 'authentication';
                }
            } catch (Exception $authException) {
                $error = 'เกิดข้อผิดพลาดในระบบตรวจสอบการเข้าสู่ระบบ';
                $error_type = 'authentication';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบนักศึกษา - <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
            color: #fff;
            position: relative;
            overflow-x: hidden;
        }

        /* Blobs */
        .blob-1, .blob-2 {
            position: absolute;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.4;
            border-radius: 50%;
            animation: float 10s infinite alternate;
        }
        .blob-1 { width: 300px; height: 300px; background: #6C63FF; top: -50px; left: -50px; }
        .blob-2 { width: 400px; height: 400px; background: #48C9B0; bottom: -100px; right: -100px; animation-delay: -5s; }
        @keyframes float {
            0% { transform: translateY(0) scale(1); }
            100% { transform: translateY(50px) scale(1.1); }
        }

        .container {
            width: 100%;
            max-width: 480px;
            margin: 0 auto;
            padding: 20px;
            z-index: 10;
        }

        .screen {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(40px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            padding: 40px 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
            background: linear-gradient(to right, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header p { font-size: 0.9rem; opacity: 0.7; }

        .login-container { padding: 30px 40px 40px; }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header .icon { font-size: 3.5rem; margin-bottom: 15px; display: block; filter: drop-shadow(0 4px 10px rgba(108, 99, 255, 0.4)); }
        .login-header h2 { font-size: 1.5rem; margin-bottom: 5px; font-weight: 500; }
        .login-header p { font-size: 0.95rem; opacity: 0.7; }

        .form-group { margin-bottom: 25px; text-align: left; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 400; opacity: 0.9; font-size: 0.95rem; }
        .required { color: #ff6b6b; font-weight: bold; }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            font-size: 1rem;
            color: #fff;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .form-group input::placeholder { color: rgba(255, 255, 255, 0.3); }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #6C63FF;
            box-shadow: 0 0 0 4px rgba(108, 99, 255, 0.2);
            background: rgba(0, 0, 0, 0.3);
        }

        .form-group.error input {
            border-color: #ff6b6b;
            box-shadow: 0 0 0 4px rgba(255, 107, 107, 0.1);
        }

        .password-input { position: relative; }
        .toggle-password {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer; font-size: 18px; padding: 5px;
            filter: grayscale(1); transition: filter 0.3s;
        }
        .toggle-password:hover { filter: grayscale(0); }

        .btn {
            background: linear-gradient(45deg, #6C63FF, #48C9B0);
            color: white;
            padding: 14px 30px;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.5);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            box-shadow: none;
            text-decoration: none;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            box-shadow: 0 4px 15px rgba(255, 255, 255, 0.1);
        }

        .register-section {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.05);
            margin: 25px 0;
            padding: 20px;
            border-radius: 16px;
            text-align: center;
        }
        .register-section h4 { font-weight: 500; margin-bottom: 5px; color: #48C9B0; }
        .register-section p { font-size: 0.9rem; opacity: 0.7; margin-bottom: 15px; }

        .btn-success { background: rgba(72, 201, 176, 0.2); color: #48C9B0; border: 1px solid rgba(72, 201, 176, 0.3); text-decoration: none; font-size: 1rem; }
        .btn-success:hover { background: rgba(72, 201, 176, 0.3); }

        .forgot-password-link { text-align: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.05); }
        .forgot-password-link a {
            color: #a5b4fc; text-decoration: none; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 5px; transition: all 0.3s ease;
        }
        .forgot-password-link a:hover { color: #fff; transform: translateY(-2px); }

        .back-home { text-align: center; margin-top: 15px; }

        /* Modern Alert Overlay - Dark Mode */
        .alert-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px);
            z-index: 1000; display: flex; align-items: center; justify-content: center;
            animation: overlayFadeIn 0.3s ease-out;
        }
        @keyframes overlayFadeIn { from { opacity: 0; } to { opacity: 1; } }

        .alert-modal {
            background: #1e1e2e; border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px; padding: 32px; max-width: 400px; width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5); text-align: center;
            animation: modalSlideIn 0.3s ease-out; color: #fff;
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .alert-modal .alert-icon { font-size: 3rem; margin-bottom: 16px; display: block; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3)); }
        .alert-modal .alert-title { font-size: 1.4rem; font-weight: 500; margin-bottom: 12px; }
        .alert-modal .alert-message { opacity: 0.8; font-size: 1rem; line-height: 1.5; margin-bottom: 24px; }
        .alert-modal .alert-actions { display: flex; gap: 12px; justify-content: center; }

        .alert-btn {
            padding: 12px 24px; border: none; border-radius: 12px; font-size: 1rem;
            font-weight: 500; cursor: pointer; transition: all 0.2s ease; min-width: 100px;
            background: linear-gradient(45deg, #6C63FF, #48C9B0); color: white;
        }
        .alert-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(108, 99, 255, 0.4); }

        .alert-error .alert-title { color: #ff6b6b; }
        .alert-success .alert-title { color: #48C9B0; }
        .alert-warning .alert-title { color: #f1c40f; }

        @media (max-width: 480px) {
            .container { padding: 15px; }
            .login-container { padding: 30px 20px 40px; }
            .header { padding: 30px 20px 20px; }
            .alert-modal { padding: 24px; }
        }
    
        /* Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    
    </style>
</head>
<body>
    <div class="blob-1"></div>
    <div class="blob-2"></div>

    <div class="container">
        <div class="screen">
            <div class="header">
                <h1>🎓 เข้าสู่ระบบนักศึกษา</h1>
                <p>Student Login - RMUTI ขอนแก่น</p>
            </div>

            <div class="login-container">
                <div class="login-header">
                    <span class="icon">👨‍🎓</span>
                    <h2>ยินดีต้อนรับ</h2>
                    <p>กรุณาเข้าสู่ระบบเพื่อใช้งาน</p>
                </div>

                <form method="POST" class="login-form" id="loginForm">
                    <input type="hidden" name="login" value="1">

                    <div class="form-group">
                        <label for="student_id">รหัสนักศึกษา <span class="required">*</span></label>
                        <input type="text" id="student_id" name="student_id" placeholder="รหัสนักศึกษา 13 หลัก (รวม -)"
                            maxlength="13" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password">รหัสผ่าน <span class="required">*</span></label>
                        <div class="password-input">
                            <input type="password" id="password" name="password" placeholder="รหัสผ่าน" required>
                            <button type="button" class="toggle-password" onclick="togglePassword('password', this)">👁️</button>
                        </div>
                    </div>

                    <button type="submit" name="login" class="btn" id="loginBtn">
                        🔑 เข้าสู่ระบบ
                    </button>

                    <div class="forgot-password-link">
                        <a href="forgot_password.php">🔐 ลืมรหัสผ่าน?</a>
                    </div>
                </form>

                <div class="register-section">
                    <h4>นักศึกษาใหม่?</h4>
                    <p>สร้างบัญชีเพื่อส่งเรื่องร้องเรียน</p>
                    <a href="register.php" class="btn btn-success">✨ ลงทะเบียน</a>
                </div>

                <div class="back-home">
                    <a href="../index.php" class="btn btn-secondary">← กลับหน้าหลัก</a>
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

        function showAlert(type, title, message, onClose = null) {
            const existingAlert = document.querySelector('.alert-overlay');
            if (existingAlert) existingAlert.remove();

            const overlay = document.createElement('div');
            overlay.className = 'alert-overlay';

            const modal = document.createElement('div');
            modal.className = `alert-modal alert-${type}`;

            const icons = { 'error': '❌', 'success': '✅', 'warning': '⚠️', 'info': 'ℹ️' };

            modal.innerHTML = `
                <span class="alert-icon">${icons[type]}</span>
                <div class="alert-title">${title}</div>
                <div class="alert-message">${message}</div>
                <div class="alert-actions">
                    <button class="alert-btn" onclick="closeAlert()">ตกลง</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            window.closeAlert = function() {
                overlay.style.animation = 'overlayFadeIn 0.2s ease-out reverse';
                modal.style.animation = 'modalSlideIn 0.2s ease-out reverse';
                setTimeout(() => { overlay.remove(); if (onClose) onClose(); }, 200);
            };

            overlay.addEventListener('click', function(e) { if (e.target === overlay) closeAlert(); });
            document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAlert(); }, { once: true });
        }

        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const errorTypes = {
                    'validation': { type: 'warning', title: 'ข้อมูลไม่ถูกต้อง' },
                    'authentication': { type: 'error', title: 'การเข้าสู่ระบบล้มเหลว' },
                    'general': { type: 'error', title: 'เกิดข้อผิดพลาด' }
                };
                const errorInfo = errorTypes['<?php echo $error_type; ?>'] || errorTypes['general'];
                showAlert(errorInfo.type, errorInfo.title, '<?php echo addslashes($error); ?>');
            });
        <?php endif; ?>

        <?php if ($success): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showAlert('success', 'สำเร็จ!', '<?php echo addslashes($success); ?>');
            });
        <?php endif; ?>

        document.getElementById('student_id').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9\-]/g, '');
            if (value.length > 13) value = value.substring(0, 13);
            const dashCount = (value.match(/\-/g) || []).length;
            if (dashCount > 1) {
                const firstDashIndex = value.indexOf('-');
                value = value.substring(0, firstDashIndex + 1) + value.substring(firstDashIndex + 1).replace(/\-/g, '');
            }
            e.target.value = value;
            
            const charCount = value.length;
            if (charCount === 13) {
                e.target.style.borderColor = '#48C9B0';
            } else if (charCount > 0) {
                e.target.style.borderColor = '#f1c40f';
            } else {
                e.target.style.borderColor = 'rgba(255, 255, 255, 0.1)';
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const studentId = document.getElementById('student_id').value.trim();
            const password = document.getElementById('password').value;
            
            document.querySelectorAll('.form-group').forEach(group => group.classList.remove('error'));
            
            if (!studentId || !password) {
                if (!studentId) document.getElementById('student_id').closest('.form-group').classList.add('error');
                if (!password) document.getElementById('password').closest('.form-group').classList.add('error');
                showAlert('warning', 'ข้อมูลไม่ครบ', 'กรุณากรอกข้อมูลให้ครบถ้วน');
                e.preventDefault();
                return;
            }
            
            const loginBtn = document.getElementById('loginBtn');
            const originalText = loginBtn.innerHTML;
            loginBtn.innerHTML = '🔄 กำลังเข้าสู่ระบบ...';
            loginBtn.style.opacity = '0.8';
            loginBtn.style.pointerEvents = 'none';
            
            setTimeout(() => {
                if (document.querySelector('.alert-overlay')) {
                    loginBtn.innerHTML = originalText;
                    loginBtn.style.opacity = '1';
                    loginBtn.style.pointerEvents = 'auto';
                }
            }, 3000);
        });
    </script>
</body>
</html>