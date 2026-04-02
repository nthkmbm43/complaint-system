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
    if (!$isLoggedIn && !in_array($path, ['tracking.php', 'students/register.php', 'students/login.php'])) {
        return 'students/login.php?redirect=' . urlencode($path);
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Kanit', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }

        /* Hero Section */
        .hero-section {
            position: relative;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.95) 0%, rgba(118, 75, 162, 0.95) 100%);
            padding: 20px;
        }

        .hero-content {
            text-align: center;
            max-width: 900px;
            width: 100%;
            z-index: 10;
            padding-top: 40px;
        }

        .hero-title {
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 700;
            color: white;
            margin-bottom: 1rem;
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .hero-subtitle {
            font-size: clamp(1rem, 2vw, 1.2rem);
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        /* ==========================================
           แก้ไข: เพิ่ม z-index ให้ปุ่มลอยเหนือพื้นหลัง
           ==========================================
        */
        .auth-buttons {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
            /* สำคัญ: ทำให้ปุ่มอยู่บนสุด */
        }

        .auth-btn {
            padding: 8px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            /* เพิ่มให้มั่นใจว่าเป็นเมาส์รูปมือ */
        }

        .auth-btn.register {
            background: rgba(255, 255, 255, 0.25);
            /* เพิ่มความเข้มพื้นหลังเล็กน้อย */
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(5px);
            /* เพิ่มลูกเล่นเบลอ */
        }

        .auth-btn.login {
            background: white;
            color: #667eea;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .auth-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .auth-btn.register:hover {
            background: rgba(255, 255, 255, 0.4);
        }

        /* User Bar (Logged In) */
        .user-bar {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.95);
            padding: 8px 20px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            /* สำคัญ: ทำให้บาร์Userอยู่บนสุดเช่นกัน */
        }

        .user-name {
            font-weight: 500;
            color: #333;
        }

        .btn-logout {
            color: #dc3545;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin: 40px 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 25px 15px;
            border-radius: 20px;
            color: white;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.25);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #ffd700;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        /* Action Card */
        .action-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-top: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .btn-main {
            display: inline-block;
            background: linear-gradient(45deg, #ff6b6b, #ee5a24);
            color: white;
            padding: 15px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 600;
            box-shadow: 0 5px 15px rgba(238, 90, 36, 0.4);
            transition: all 0.3s ease;
        }

        .btn-main:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(238, 90, 36, 0.5);
        }

        /* News Section */
        .news-section {
            margin-top: 40px;
            text-align: left;
        }

        .news-title {
            color: white;
            margin-bottom: 15px;
            font-size: 1.2rem;
        }

        .news-item {
            background: rgba(255, 255, 255, 0.1);
            border-left: 4px solid #00b894;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            color: white;
        }

        .news-date {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .hero-title {
                font-size: 2rem;
            }
        }
    </style>
</head>

<body>

    <?php if ($isLoggedIn): ?>
        <div class="user-bar">
            <span class="user-name">👤 <?php echo htmlspecialchars($user['name']); ?></span>
            <a href="logout.php" class="btn-logout">ออกจากระบบ</a>
        </div>
    <?php else: ?>
        <div class="auth-buttons">
            <a href="students/register.php" class="auth-btn register">ลงทะเบียนนักศึกษา</a>
            <a href="students/login.php" class="auth-btn login">เข้าสู่ระบบ</a>
        </div>
    <?php endif; ?>

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
                <div style="margin-bottom: 20px; font-size: 1.1rem; color: #666;">
                    📝 พบปัญหาหรือต้องการเสนอแนะ? แจ้งเราได้ทันที
                </div>
                <a href="<?php echo createSecureUrl('students/complaint.php'); ?>" class="btn-main">
                    ส่งข้อร้องเรียน
                </a>
            </div>

            <div class="news-section">
                <h3 class="news-title">📢 ประชาสัมพันธ์</h3>
                <?php foreach ($announcements as $news): ?>
                    <div class="news-item">
                        <div style="font-weight: 500; margin-bottom: 5px;"><?php echo $news['title']; ?></div>
                        <div style="font-size: 0.9rem; opacity: 0.9;"><?php echo $news['content']; ?></div>
                        <div class="news-date">📅 <?php echo date('d/m/Y', strtotime($news['date'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>
    </div>

</body>

</html>