<?php
// ระบบออกจากระบบ - แก้ไขให้สมบูรณ์
ini_set('display_errors', 1);
error_reporting(E_ALL);

// เริ่ม output buffering
ob_start();

session_start();

// ตรวจสอบว่ามี session หรือไม่
$hadSession = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : null;

try {
    // ล้างข้อมูล session
    $_SESSION = array();

    // ลบ session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // ลบ remember me cookie ถ้ามี
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 42000, '/', '', false, true);
    }

    // ทำลาย session
    session_destroy();

    // สร้าง session ใหม่เพื่อแสดงข้อความ
    session_start();

    // กำหนดข้อความสำเร็จ
    $message = 'logout_success';

    // กำหนดหน้าปลายทางตาม role เดิม
    $redirectPage = 'login.php';

    if ($userRole === 'student') {
        $redirectPage = '../index.php';
    } elseif (in_array($userRole, ['staff', 'admin'])) {
        $redirectPage = 'login.php';
    }

    // Redirect พร้อมข้อความ
    ob_end_clean();
    header("Location: {$redirectPage}?message={$message}", true, 302);
    exit();
} catch (Exception $e) {
    // หากเกิดข้อผิดพลาด
    error_log("Logout error: " . $e->getMessage());

    // Redirect กลับไปหน้า login พร้อมข้อความผิดพลาด
    ob_end_clean();
    header('Location: login.php?message=logout_error', true, 302);
    exit();
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>กำลังออกจากระบบ...</title>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .logout-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }

        .logout-icon {
            font-size: 60px;
            margin-bottom: 20px;
            animation: spin 2s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .logout-message {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
        }

        .loading-bar {
            width: 100%;
            height: 4px;
            background: #f0f0f0;
            border-radius: 2px;
            overflow: hidden;
        }

        .loading-progress {
            height: 100%;
            background: linear-gradient(90deg, #ff6b6b, #ee5a24);
            border-radius: 2px;
            animation: progress 2s ease-in-out;
        }

        @keyframes progress {
            0% {
                width: 0%;
            }

            100% {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="logout-container">
        <div class="logout-icon">🔄</div>
        <div class="logout-message">กำลังออกจากระบบ...</div>
        <div class="loading-bar">
            <div class="loading-progress"></div>
        </div>
    </div>

    <script>
        // Fallback redirect หากไม่ redirect อัตโนมัติ
        setTimeout(function() {
            window.location.href = 'login.php?message=logout_success';
        }, 3000);
    </script>
</body>

</html>