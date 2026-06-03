<?php
session_start();

// กำหนด SECURE_ACCESS
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// ----------------------------------------------------------------------
// 1. ระบบโหลด Config ที่ถูกต้อง
// ----------------------------------------------------------------------
$configPath = __DIR__ . '/config/config.php';
$dbPath = __DIR__ . '/config/database.php';

if (file_exists($configPath)) {
    require_once $configPath;
}
if (file_exists($dbPath)) {
    require_once $dbPath;
}

// Fallback ค่าเริ่มต้น
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'complaint_system');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_CHARSET', 'utf8mb4');
}

if (!defined('SITE_NAME')) define('SITE_NAME', 'ระบบจัดการข้อร้องเรียน RMUTI');

// ----------------------------------------------------------------------
// 2. เชื่อมต่อฐานข้อมูล
// ----------------------------------------------------------------------
$db = null;
$db_connected = false;

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $db_connected = true;
} catch (PDOException $e) {
    $db_connected = false;
}

// ตรวจสอบสถานะล็อกอิน
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user = $isLoggedIn ? [
    'name' => $_SESSION['user_name'] ?? 'ผู้ใช้งาน',
    'role' => $_SESSION['user_role'] ?? ''
] : null;

// ฟังก์ชัน Helper สร้าง URL
function createSecureUrl($path)
{
    global $isLoggedIn;
    if (!$isLoggedIn && !in_array($path, ['tracking.php', 'views/students/register.php', 'views/students/login.php'])) {
        return 'views/students/login.php?redirect=' . urlencode($path);
    }
    return $path;
}

// ----------------------------------------------------------------------
// 3. ดึงสถิติจากฐานข้อมูลจริง
// ----------------------------------------------------------------------
$stats = [
    'total_complaints' => 0,
    'resolved_complaints' => 0,
    'response_rate' => 0,
    'new_today' => 0
];

if ($db_connected) {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM request");
        $stats['total_complaints'] = $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM request WHERE Re_status = '3'");
        $stats['resolved_complaints'] = $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM request WHERE DATE(Re_date) = CURDATE()");
        $stats['new_today'] = $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM request WHERE Re_status != '0'");
        $responded = $stmt->fetchColumn();

        if ($stats['total_complaints'] > 0) {
            $stats['response_rate'] = round(($responded / $stats['total_complaints']) * 100, 1);
        } else {
            $stats['response_rate'] = 0;
        }
    } catch (Exception $e) {
        // Silent error
    }
}

$announcements = [
    [
        'title' => '📢 ยินดีต้อนรับสู่ระบบข้อร้องเรียนออนไลน์',
        'content' => 'นักศึกษาสามารถส่งเรื่องร้องเรียนและติดตามสถานะได้ตลอด 24 ชั่วโมง',
        'date' => date('Y-m-d'),
        'type' => 'info'
    ],
    [
        'title' => '🔒 ระบบรักษาความปลอดภัยข้อมูล',
        'content' => 'ท่านสามารถเลือกส่งข้อร้องเรียนแบบ "ไม่ระบุตัวตน" ได้ เพื่อความเป็นส่วนตัว',
        'date' => date('Y-m-d', strtotime('-1 day')),
        'type' => 'warning'
    ]
];
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบจัดการข้อร้องเรียน - <?php echo SITE_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
            min-height: 100vh;
            color: #fff;
            position: relative;
            overflow-x: hidden;
        }
        /* Floating Blobs */
        .blob-1, .blob-2 {
            position: absolute;
            filter: blur(80px);
            z-index: -1;
            opacity: 0.5;
            border-radius: 50%;
            animation: float 10s infinite alternate;
        }
        .blob-1 {
            width: 400px; height: 400px;
            background: #6C63FF;
            top: -100px; left: -100px;
        }
        .blob-2 {
            width: 500px; height: 500px;
            background: #48C9B0;
            bottom: -150px; right: -150px;
            animation-delay: -5s;
        }
        @keyframes float {
            0% { transform: translateY(0) scale(1); }
            100% { transform: translateY(50px) scale(1.1); }
        }
        
        /* Navbar */
        .navbar {
            position: fixed;
            top: 0; left: 0; width: 100%;
            padding: 20px 40px;
            display: flex;
            justify-content: flex-end;
            z-index: 1000;
        }
        .auth-buttons, .user-bar {
            display: flex; gap: 15px; align-items: center;
        }
        .user-bar {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            padding: 10px 25px;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .user-name { font-weight: 500; color: #fff; }
        .btn-logout { color: #ff6b6b; text-decoration: none; font-weight: 500; }
        
        .auth-btn {
            padding: 10px 25px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .auth-btn.register {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }
        .auth-btn.register:hover { background: rgba(255,255,255,0.2); }
        .auth-btn.login {
            background: linear-gradient(45deg, #6C63FF, #48C9B0);
            color: #fff;
            box-shadow: 0 4px 15px rgba(108, 99, 255, 0.4);
            border: none;
        }
        .auth-btn.login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 99, 255, 0.6);
        }

        /* Hero Section */
        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 100px 20px 40px;
        }
        .hero-content { text-align: center; max-width: 1000px; width: 100%; z-index: 10; }
        .hero-title {
            font-size: clamp(2.5rem, 6vw, 4rem);
            font-weight: 700;
            background: linear-gradient(to right, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
        }
        .hero-subtitle {
            font-size: clamp(1.1rem, 2vw, 1.3rem);
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 3rem;
            line-height: 1.6;
        }

        /* Stats Grid - Glassmorphism */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            margin: 40px 0;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 30px 20px;
            border-radius: 24px;
            color: white;
            transition: all 0.3s ease;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .stat-card:hover {
            transform: translateY(-10px);
            background: rgba(255, 255, 255, 0.15);
            border-color: rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
        }
        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #48C9B0;
            text-shadow: 0 2px 10px rgba(72, 201, 176, 0.2);
        }
        .stat-label { font-size: 1rem; opacity: 0.9; font-weight: 300; }

        /* Action Card */
        .action-card {
            background: rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 28px;
            padding: 40px;
            margin-top: 50px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .btn-main {
            display: inline-block;
            background: linear-gradient(45deg, #6C63FF, #48C9B0);
            color: white;
            padding: 16px 45px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 1.2rem;
            font-weight: 600;
            box-shadow: 0 8px 25px rgba(108, 99, 255, 0.4);
            transition: all 0.3s ease;
            border: none;
        }
        .btn-main:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 12px 30px rgba(108, 99, 255, 0.6);
        }

        /* News Section */
        .news-section {
            margin-top: 60px;
            text-align: left;
            background: rgba(255, 255, 255, 0.05);
            padding: 40px;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .news-title { color: #fff; margin-bottom: 20px; font-size: 1.4rem; font-weight: 600; }
        .news-item {
            background: rgba(0, 0, 0, 0.2);
            border-left: 4px solid #48C9B0;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            color: white;
            transition: all 0.3s ease;
        }
        .news-item:hover { background: rgba(0, 0, 0, 0.3); transform: translateX(5px); }
        .news-date { font-size: 0.85rem; color: #48C9B0; margin-top: 8px; font-weight: 300; }

        @media (max-width: 768px) {
            .navbar { padding: 15px 20px; justify-content: center; }
            .hero-section { padding-top: 120px; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .hero-title { font-size: 2.2rem; }
            .action-card { padding: 30px 20px; }
            .news-section { padding: 20px; }
        }
    
        /* Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    
    </style>
</head>
<body>
    <div class="blob-1"></div>
    <div class="blob-2"></div>

    <nav class="navbar">
        <?php if ($isLoggedIn): ?>
            <div class="user-bar">
                <span class="user-name">👤 <?php echo htmlspecialchars($user['name']); ?></span>
                <a href="logout.php" class="btn-logout">ออกจากระบบ</a>
            </div>
        <?php else: ?>
            <div class="auth-buttons">
                <a href="views/students/register.php" class="auth-btn register">ลงทะเบียนนักศึกษา</a>
                <a href="views/students/login.php" class="auth-btn login">เข้าสู่ระบบ</a>
            </div>
        <?php endif; ?>
    </nav>

    <div class="hero-section">
        <div class="hero-content">
            <h1 class="hero-title">ระบบจัดการข้อร้องเรียน</h1>
            <p class="hero-subtitle">
                มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน (RMUTI)<br>
                ช่องทางแจ้งปัญหาและข้อเสนอแนะ เพื่อการพัฒนาอย่างต่อเนื่อง
            </p>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['total_complaints']); ?></div>
                    <div class="stat-label">ข้อร้องเรียนทั้งหมด</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['resolved_complaints']); ?></div>
                    <div class="stat-label">ข้อร้องเรียนเสร็จสิ้นแล้ว</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $stats['response_rate']; ?>%</div>
                    <div class="stat-label">อัตราการตอบสนอง</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($stats['new_today']); ?></div>
                    <div class="stat-label">เรื่องใหม่วันนี้</div>
                </div>
            </div>

            <div class="action-card">
                <div style="margin-bottom: 25px; font-size: 1.2rem; color: rgba(255,255,255,0.9); font-weight: 300;">
                    📝 พบปัญหาหรือต้องการเสนอแนะ? แจ้งเราได้ทันที
                </div>
                <a href="<?php echo createSecureUrl('views/students/complaint.php'); ?>" class="btn-main">
                    ส่งข้อร้องเรียน
                </a>
            </div>

            <div class="news-section">
                <h3 class="news-title">📢 ประชาสัมพันธ์</h3>
                <?php foreach ($announcements as $news): ?>
                    <div class="news-item">
                        <div style="font-weight: 500; margin-bottom: 5px; font-size: 1.1rem;"><?php echo $news['title']; ?></div>
                        <div style="font-size: 0.95rem; opacity: 0.9; font-weight: 300;"><?php echo $news['content']; ?></div>
                        <div class="news-date">📅 <?php echo date('d/m/Y', strtotime($news['date'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>