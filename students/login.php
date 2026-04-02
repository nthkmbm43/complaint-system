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
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }

        .screen {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
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

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1rem;
            opacity: 0.9;
        }

        .login-container {
            padding: 60px 40px;
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
            margin: 0;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group.error input,
        .form-group.error select,
        .form-group.error textarea {
            border-color: #dc3545;
            background-color: #fff5f5;
        }

        .required {
            color: #dc3545;
            font-weight: bold;
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
            padding: 5px;
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
            min-width: 120px;
            width: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }

        .btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            opacity: 0.6;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .register-section {
            background: linear-gradient(135deg, #28a745, #20c997);
            margin: 20px 0;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.3);
        }

        .register-section h4 {
            color: white;
            margin: 0 0 10px 0;
            font-size: 1.1rem;
        }

        .register-section p {
            color: white;
            opacity: 0.9;
            margin: 0 0 15px 0;
            font-size: 0.95rem;
        }

        .register-section .btn {
            background: white;
            color: #28a745;
            font-weight: bold;
        }

        .forgot-password-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .forgot-password-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .forgot-password-link a:hover {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            color: #764ba2;
            transform: translateY(-2px);
        }

        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        .input-hint {
            background: #e3f2fd;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #2196f3;
        }

        .input-hint h4 {
            color: #1976d2;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .input-hint p {
            color: #1976d2;
            font-size: 13px;
            margin: 0;
        }

        .student-id-examples {
            background: #e7f3ff;
            padding: 10px;
            border-radius: 6px;
            margin-top: 8px;
            font-size: 12px;
            color: #0066cc;
        }

        .student-id-examples strong {
            display: block;
            margin-bottom: 5px;
        }

        .student-id-examples .example {
            font-family: monospace;
            background: white;
            padding: 2px 4px;
            border-radius: 3px;
            margin: 2px 0;
        }

        /* Modern Alert Styles */
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
            animation: overlayFadeIn 0.3s ease-out;
        }

        @keyframes overlayFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
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
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
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

        /* Alert Types */
        .alert-error .alert-icon {
            color: #dc3545;
        }

        .alert-success .alert-icon {
            color: #28a745;
        }

        .alert-warning .alert-icon {
            color: #ffc107;
        }

        .alert-info .alert-icon {
            color: #17a2b8;
        }

        .alert-error .alert-title {
            color: #dc3545;
        }

        .alert-success .alert-title {
            color: #28a745;
        }

        .alert-warning .alert-title {
            color: #e67e22;
        }

        .alert-info .alert-title {
            color: #17a2b8;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .login-container {
                padding: 40px 20px;
            }

            .header h1 {
                font-size: 1.5rem;
            }

            .header p {
                font-size: 0.9rem;
            }

            .alert-modal {
                padding: 24px;
                margin: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="screen">
            <div class="header">
                <h1>🎓 เข้าสู่ระบบนักศึกษา</h1>
                <p>Student Login - มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น</p>
            </div>

            <div class="login-container">
                <div class="login-header">
                    <span class="icon">👨‍🎓</span>
                    <h2>เข้าสู่ระบบนักศึกษา</h2>
                    <p>กรุณาเข้าสู่ระบบด้วยรหัสนักศึกษาและรหัสผ่าน</p>
                </div>

                <form method="POST" class="login-form" id="loginForm">
                    <!-- Hidden field เพื่อให้แน่ใจว่า login key จะถูกส่ง -->
                    <input type="hidden" name="login" value="1">

                    <div class="form-group">
                        <label for="student_id">รหัสนักศึกษา <span class="required">*</span></label>
                        <input
                            type="text"
                            id="student_id"
                            name="student_id"
                            placeholder="กรอกรหัสนักศึกษา รวม 13 ตัวอักษร"
                            maxlength="13"
                            value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>"
                            required>
                    </div>

                    <div class="form-group">
                        <label for="password">รหัสผ่าน <span class="required">*</span></label>
                        <div class="password-input">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="กรอกรหัสผ่าน"
                                required>
                            <button type="button" class="toggle-password" onclick="togglePassword('password', this)">
                                👁️
                            </button>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="login" class="btn btn-primary" id="loginBtn">
                            🔑 เข้าสู่ระบบ
                        </button>
                    </div>

                    <!-- Forgot Password Link -->
                    <div class="forgot-password-link">
                        <a href="forgot_password.php">
                            🔐 ลืมรหัสผ่าน?
                        </a>
                    </div>
                </form>

                <!-- Registration Section -->
                <div class="register-section">
                    <h4>🎓 นักศึกษาใหม่?</h4>
                    <p>สร้างบัญชีผู้ใช้เพื่อใช้งานระบบข้อร้องเรียน</p>
                    <a href="register.php" class="btn btn-success">
                        ✨ ลงทะเบียนนักศึกษาใหม่
                    </a>
                </div>

                <!-- Back to Home -->
                <div class="back-home">
                    <a href="../index.php" class="btn btn-secondary">
                        ← กลับหน้าหลัก
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ฟังก์ชันแสดง/ซ่อนรหัสผ่าน
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const isPassword = input.type === 'password';

            input.type = isPassword ? 'text' : 'password';
            button.textContent = isPassword ? '🙈' : '👁️';
        }

        // Modern Alert Function
        function showAlert(type, title, message, onClose = null) {
            // Remove existing alerts
            const existingAlert = document.querySelector('.alert-overlay');
            if (existingAlert) {
                existingAlert.remove();
            }

            // Create alert overlay
            const overlay = document.createElement('div');
            overlay.className = 'alert-overlay';

            // Create alert modal
            const modal = document.createElement('div');
            modal.className = `alert-modal alert-${type}`;

            // Get icon based on type
            const icons = {
                'error': '❌',
                'success': '✅',
                'warning': '⚠️',
                'info': 'ℹ️'
            };

            modal.innerHTML = `
                <span class="alert-icon">${icons[type]}</span>
                <div class="alert-title">${title}</div>
                <div class="alert-message">${message}</div>
                <div class="alert-actions">
                    <button class="alert-btn alert-btn-primary" onclick="closeAlert()">ตกลง</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            // Close function
            window.closeAlert = function() {
                overlay.style.animation = 'overlayFadeIn 0.2s ease-out reverse';
                modal.style.animation = 'modalSlideIn 0.2s ease-out reverse';

                setTimeout(() => {
                    overlay.remove();
                    if (onClose) onClose();
                }, 200);
            };

            // Close on overlay click
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeAlert();
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeAlert();
                }
            }, {
                once: true
            });
        }

        // Show PHP errors/success as modern alerts
        <?php if ($error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const errorTypes = {
                    'validation': {
                        type: 'warning',
                        title: 'ข้อมูลไม่ถูกต้อง',
                        icon: '⚠️'
                    },
                    'authentication': {
                        type: 'error',
                        title: 'การเข้าสู่ระบบล้มเหลว',
                        icon: '🔒'
                    },
                    'general': {
                        type: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        icon: '❌'
                    }
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

        // Auto-format student ID input - รับตัวเลขและ - รวมกัน 13 ตัวอักษร
        document.getElementById('student_id').addEventListener('input', function(e) {
            let value = e.target.value.replace(/[^0-9\-]/g, ''); // อนุญาตเฉพาะตัวเลขและ -

            // จำกัดจำนวนตัวอักษรไม่เกิน 13 ตัว
            if (value.length > 13) {
                value = value.substring(0, 13);
            }

            // ป้องกันการมี - มากกว่า 1 ตัว
            const dashCount = (value.match(/\-/g) || []).length;
            if (dashCount > 1) {
                // ถ้ามี - มากกว่า 1 ตัว เก็บเฉพาะตัวแรก
                const firstDashIndex = value.indexOf('-');
                value = value.substring(0, firstDashIndex + 1) + value.substring(firstDashIndex + 1).replace(/\-/g, '');
            }

            e.target.value = value;

            // แสดงจำนวนตัวอักษรที่กรอก
            const charCount = value.length;

            // เปลี่ยนสีขอบตามจำนวนตัวอักษร
            if (charCount === 13) {
                e.target.style.borderColor = '#667eea';
                e.target.style.backgroundColor = '#f8f9ff';
            } else if (charCount > 0) {
                e.target.style.borderColor = '#ffc107';
                e.target.style.backgroundColor = '#fffef7';
            } else {
                e.target.style.borderColor = '#e1e5e9';
                e.target.style.backgroundColor = 'white';
            }

            // อัพเดต placeholder ให้แสดงจำนวนตัวอักษร
            if (charCount > 0) {
                e.target.setAttribute('title', `กรอกแล้ว ${charCount}/13 ตัวอักษร`);
            } else {
                e.target.removeAttribute('title');
            }
        });

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const studentId = document.getElementById('student_id').value.trim();
            const password = document.getElementById('password').value;
            const loginBtn = document.getElementById('loginBtn');

            // Reset any previous error styling
            document.querySelectorAll('.form-group').forEach(group => {
                group.classList.remove('error');
            });

            let hasError = false;
            let errorMessage = '';

            // Check required fields
            if (!studentId || !password) {
                errorMessage = 'กรุณากรอกข้อมูลให้ครบถ้วน';

                // Highlight empty fields
                if (!studentId) document.getElementById('student_id').closest('.form-group').classList.add('error');
                if (!password) document.getElementById('password').closest('.form-group').classList.add('error');

                hasError = true;
            }

            // Check student ID format - ตัวเลขและ - รวมกัน 13 ตัวอักษร
            if (!hasError && studentId) {
                // ตรวจสอบความยาวก่อน
                if (studentId.length !== 13) {
                    errorMessage = `รหัสนักศึกษาต้องมีความยาว 13 ตัวอักษรเท่านั้น\nปัจจุบัน: ${studentId.length} ตัวอักษร`;
                    document.getElementById('student_id').closest('.form-group').classList.add('error');
                    hasError = true;
                } else if (!/^[\d\-]{13}$/.test(studentId)) {
                    errorMessage = 'รหัสนักศึกษาต้องเป็นตัวเลขและ - รวมกัน 13 ตัวอักษร\nเช่น 66342310092-2 หรือ 6634231009220';
                    document.getElementById('student_id').closest('.form-group').classList.add('error');
                    hasError = true;
                }
            }

            // Show error if validation failed
            if (hasError) {
                e.preventDefault();
                showAlert('warning', 'ข้อมูลไม่ถูกต้อง', errorMessage);
                return false;
            }

            // If no errors, show loading state and allow form submission
            loginBtn.disabled = true;
            loginBtn.innerHTML = '⏳ กำลังเข้าสู่ระบบ...';

            // Re-enable button after timeout (in case of server error)
            setTimeout(() => {
                if (loginBtn.disabled) {
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = '🔑 เข้าสู่ระบบ';
                }
            }, 10000);

            return true;
        });
    </script>
</body>

</html>