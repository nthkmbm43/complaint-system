<?php
define('SECURE_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

// เริ่ม session หากยังไม่ได้เริ่ม
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------------------------------------
// 1. เก็บข้อมูลบทบาท (Role) ไว้ก่อนทำลาย Session
// -----------------------------------------------------------
$userRole = 'Unknown'; // ค่าเริ่มต้น

if (isset($_SESSION['user_role'])) {
    $userRole = $_SESSION['user_role']; // เก็บค่า role ไว้ใช้ตรวจสอบด้านล่าง (student, teacher, admin)
}

// Log การออกจากระบบ
if (isLoggedIn()) {
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['user_name'] ?? 'Unknown';

    // บันทึก log
    if (function_exists('logActivity')) {
        logActivity('logout', "User {$userName} ({$userRole}) logged out", $userId);
    }

    // อัพเดตเวลาออกจากระบบในฐานข้อมูล
    try {
        $db = getDB();
        // ตรวจสอบว่าเป็นตาราง student หรือ teacher ตาม Role
        // (ส่วนนี้เป็น Optional ถ้าไม่มีตาราง users กลาง ก็ข้ามไปได้ ไม่ Error)
    } catch (Exception $e) {
        // Ignore errors
    }
}

// -----------------------------------------------------------
// 2. ทำลาย Session
// -----------------------------------------------------------
session_unset();
session_destroy();

// ลบ cookies ที่เกี่ยวข้อง
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// เคลียร์ cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกจากระบบ - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        .logout-container {
            max-width: 500px;
            margin: 20px auto;
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }

        .logout-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #28a745;
        }

        .logout-message {
            font-size: 1.2rem;
            color: #333;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .logout-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .countdown {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
            color: #666;
            font-size: 0.9rem;
        }

        .loading-animation {
            display: inline-block;
            margin-left: 10px;
        }

        .loading-animation::after {
            content: '';
            animation: dots 1.5s steps(5, end) infinite;
        }

        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }

        .success-animation {
            animation: bounce 0.6s ease-in-out;
        }

        @keyframes bounce {
            0%, 20%, 60%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            80% { transform: translateY(-5px); }
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: white;
            transition: 0.3s;
            display: inline-block;
        }
        .btn-primary { background-color: #007bff; }
        .btn-success { background-color: #28a745; }
        .btn-warning { background-color: #ffc107; color: #000; }
        .btn:hover { opacity: 0.9; transform: translateY(-2px); }
    </style>
</head>

<body>
    <div class="container">
        <div class="logout-container">
            <div class="logout-icon success-animation">✅</div>
            <h2>ออกจากระบบเรียบร้อย</h2>
            <div class="logout-message">
                <p>คุณได้ออกจากระบบเรียบร้อยแล้ว</p>
                <p>ขอบคุณที่ใช้บริการระบบข้อร้องเรียนนักศึกษา</p>
            </div>

            <div class="countdown">
                <span id="redirectMessage">
                    กำลังเปลี่ยนเส้นทางไปหน้าหลักใน <span id="countdownTimer">5</span> วินาที
                    <span class="loading-animation"></span>
                </span>
            </div>

            <div class="logout-actions">
                <a href="index.php" class="btn btn-primary">
                    🏠 กลับหน้าหลัก
                </a>
                
                <a href="students/login.php" class="btn btn-success">
                    👨‍🎓 เข้าสู่ระบบนักศึกษา
                </a>

                <?php if ($userRole !== 'student') : ?>
                    <a href="staff/login.php" class="btn btn-warning">
                        👨‍🏫 เข้าสู่ระบบเจ้าหน้าที่
                    </a>
                <?php endif; ?>
            </div>

            <div style="margin-top: 30px; padding: 20px; background: #e3f2fd; border-radius: 10px; border-left: 4px solid #2196f3;">
                <h4 style="color: #1976d2; margin-bottom: 10px;">🔒 ข้อควรระวังด้านความปลอดภัย</h4>
                <ul style="text-align: left; color: #1976d2; font-size: 0.9rem; line-height: 1.6;">
                    <li>ปิดหน้าต่างเบราว์เซอร์หากใช้งานในคอมพิวเตอร์สาธารณะ</li>
                    <li>ไม่แชร์รหัสผ่านกับผู้อื่น</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Countdown timer
        let countdown = 5;
        const timer = document.getElementById('countdownTimer');
        const message = document.getElementById('redirectMessage');

        const interval = setInterval(() => {
            countdown--;
            timer.textContent = countdown;

            if (countdown <= 0) {
                clearInterval(interval);
                message.innerHTML = 'กำลังเปลี่ยนเส้นทาง<span class="loading-animation"></span>';

                // Redirect to homepage
                // แก้ไข Script Redirect: เพิ่ม .php
                setTimeout(() => {
                    window.location.href = 'index.php';
                }, 1000);
            }
        }, 1000);

        // Prevent back button logic
        history.pushState(null, null, location.href);
        window.onpopstate = function() {
            history.go(1);
        };

        try {
            localStorage.removeItem('user_session');
            localStorage.removeItem('complaint_draft');
            sessionStorage.clear();
        } catch (e) {}

        // Button animation logic
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function() {
                clearInterval(interval);
                this.style.opacity = '0.7';
            });
        });
    </script>
</body>
</html>