<?php
// หน้าหลักเจ้าหน้าที่ - index.php (ปรับให้ smooth เหมือน users.php)
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์
requireLogin();
requireStaffAccess();
requireRole(['teacher']);

$userPermission = $_SESSION['permission'] ?? 0;

// permission=1 (อาจารย์) และ permission=2 (ผู้ดำเนินการ) ให้ใช้ index.php แทน
if ($userPermission < 3) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$user = getCurrentUser();
$currentRole = $_SESSION['user_role'];
$isAdmin = true;
$isSuperAdmin = true;

// Initialize variables
$stats = [
    'total_complaints' => 0,
    'pending_complaints' => 0,
    'processing_complaints' => 0,
    'completed_complaints' => 0,
    'evaluated_complaints' => 0,
    'rejected_complaints' => 0,
    'avg_response_time' => 0,
    'avg_rating' => 0,
    'total_students' => 0,
    'total_staff' => 0,
    'success_rate' => 0,
    'spam_complaints' => 0,
    'urgent_complaints' => 0,
    'today_complaints' => 0,
    'week_complaints' => 0,
    'month_complaints' => 0
];

$recentComplaints = [];
$urgentComplaints = [];
$categoryStats = [];
$monthlyStats = [];
$performanceMetrics = [];
$insights = [];
$unitStats = [];

try {
    // ดึงสถิติพื้นฐานจากฐานข้อมูลใหม่
    $stats['total_complaints'] = $db->count('request', '1=1');
    // Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมินผล, 3=เสร็จสิ้น, 4=ปฏิเสธ
    $stats['pending_complaints'] = $db->count('request', 'Re_status = ?', ['0']); // 0=ยื่นคำร้อง (รอยืนยัน)
    $stats['processing_complaints'] = $db->count('request', 'Re_status = ?', ['1']); // 1=กำลังดำเนินการ
    $stats['completed_complaints'] = $db->count('request', 'Re_status = ?', ['2']); // 2=รอประเมินผลความพึงพอใจ
    $stats['evaluated_complaints'] = $db->count('request', 'Re_status = ?', ['3']); // 3=เสร็จสิ้น
    $stats['rejected_complaints'] = $db->count('request', 'Re_status = ?', ['4']); // 4=ปฏิเสธคำร้อง
    // ไม่มีคอลัมน์ Re_is_spam ในฐานข้อมูล ดังนั้นตั้งค่าเป็น 0
    $stats['spam_complaints'] = 0;
    $stats['total_students'] = $db->count('student', 'Stu_status = 1');
    $stats['total_staff'] = $db->count('teacher', 'Aj_status = 1');

    // สถิติวันนี้, สัปดาห์นี้, เดือนนี้
    $stats['today_complaints'] = $db->count('request', 'DATE(Re_date) = CURDATE()');
    $stats['week_complaints'] = $db->count('request', 'YEARWEEK(Re_date, 1) = YEARWEEK(CURDATE(), 1)');
    $stats['month_complaints'] = $db->count('request', 'YEAR(Re_date) = YEAR(CURDATE()) AND MONTH(Re_date) = MONTH(CURDATE())');

    // ข้อร้องเรียนเร่งด่วน (ระดับ 3, 4, 5)
    $stats['urgent_complaints'] = $db->count('request', 'Re_level IN (?, ?, ?) AND Re_status IN (?, ?)', ['3', '4', '5', '0', '1']);

    // คำนวดอัตราความสำเร็จ
    $totalProcessed = $stats['completed_complaints'] + $stats['evaluated_complaints'];
    $stats['success_rate'] = $totalProcessed > 0 ? round(($totalProcessed / $stats['total_complaints']) * 100, 1) : 0;

    // คำนวดเวลาตอบกลับเฉลี่ย (ใช้ข้อมูลจำลอง)
    $stats['avg_response_time'] = 2.5; // วัน

    // ดึงคะแนนประเมินเฉลี่ย
    $avgRating = $db->fetch("SELECT AVG(Eva_score) as avg_rating FROM evaluation WHERE Eva_score > 0");
    $stats['avg_rating'] = round($avgRating['avg_rating'] ?? 0, 1);

    // สถิติตามหน่วยงานของผู้ใช้ (ถ้ามี)
    if ($user['Unit_id']) {
        try {
            // ดึงข้อร้องเรียนที่เกี่ยวข้องกับหน่วยงาน (ถ้ามีการกำหนด assign_to)
            $unitStats['assigned_to_unit'] = $db->count('request', 'Aj_id IN (SELECT Aj_id FROM teacher WHERE Unit_id = ?)', [$user['Unit_id']]);

            // ดึงข้อร้องเรียนที่นักศึกษาในหน่วยงานเดียวกันส่งมา
            $unitStats['from_unit_students'] = $db->count('request r JOIN student s ON r.Stu_id = s.Stu_id', 's.Unit_id = ?', [$user['Unit_id']]);

            // ข้อร้องเรียนที่เสร็จสิ้นโดยเจ้าหน้าที่ในหน่วยงาน
            $unitStats['completed_by_unit'] = $db->count('request', 'Aj_id IN (SELECT Aj_id FROM teacher WHERE Unit_id = ?) AND Re_status IN (?, ?)', [$user['Unit_id'], '2', '3']);
        } catch (Exception $e) {
            error_log("Unit stats error: " . $e->getMessage());
        }
    }

    // ดึงข้อมูลสำหรับกราฟรายเดือน (6 เดือนล่าสุด)
    // Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมินผล, 3=เสร็จสิ้น, 4=ปฏิเสธ
    $monthlyStats = $db->fetchAll("
        SELECT 
            DATE_FORMAT(Re_date, '%Y-%m') as month,
            DATE_FORMAT(Re_date, '%M %Y') as month_name,
            COUNT(*) as total,
            SUM(CASE WHEN Re_status = '0' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN Re_status = '1' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN Re_status = '2' THEN 1 ELSE 0 END) as waiting_eval,
            SUM(CASE WHEN Re_status = '3' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN Re_status = '4' THEN 1 ELSE 0 END) as rejected
        FROM request 
        WHERE Re_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(Re_date, '%Y-%m')
        ORDER BY month DESC
        LIMIT 6
    ");

    // กลับลำดับให้เป็นจากเก่าไปใหม่
    $monthlyStats = array_reverse($monthlyStats);

    // ดึงสถิติตามประเภทข้อร้องเรียน
    $categoryStats = $db->fetchAll("
        SELECT 
            t.Type_infor as category,
            t.Type_icon as icon,
            COUNT(r.Re_id) as total,
            AVG(CASE WHEN e.Eva_score > 0 THEN e.Eva_score ELSE NULL END) as avg_rating
        FROM type t 
        LEFT JOIN request r ON t.Type_id = r.Type_id
        LEFT JOIN evaluation e ON r.Re_id = e.Re_id
        GROUP BY t.Type_id, t.Type_infor, t.Type_icon
        ORDER BY total DESC
    ");

    // ดึงข้อร้องเรียนล่าสุด - แก้ไขให้แสดงหน่วยงาน
    $recentComplaints = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon, s.Stu_name, 
               ou_student.Unit_name as Student_unit_name,
               ou_student.Unit_type as Student_unit_type,
               ou_student.Unit_icon as Student_unit_icon,
               CASE ou_student.Unit_type
                   WHEN 'faculty' THEN 'คณะ'
                   WHEN 'major' THEN 'สาขา'
                   WHEN 'department' THEN 'แผนก'
               END as Student_unit_type_thai,
               aj.Aj_name as Assigned_teacher_name,
               ou_teacher.Unit_name as Teacher_unit_name,
               ou_teacher.Unit_icon as Teacher_unit_icon,
               CASE 
                   WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
                   ELSE s.Stu_name
               END as requester_name
        FROM request r 
        LEFT JOIN type t ON r.Type_id = t.Type_id 
        LEFT JOIN student s ON r.Stu_id = s.Stu_id
        LEFT JOIN organization_unit ou_student ON s.Unit_id = ou_student.Unit_id
        LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
        LEFT JOIN organization_unit ou_teacher ON aj.Unit_id = ou_teacher.Unit_id
        ORDER BY r.Re_date DESC 
        LIMIT 8
    ");

    // ดึงข้อร้องเรียนเร่งด่วน
    $urgentComplaints = $db->fetchAll("
        SELECT r.*, t.Type_infor, t.Type_icon, s.Stu_name,
               ou_student.Unit_name as Student_unit_name,
               ou_student.Unit_icon as Student_unit_icon,
               CASE 
                   WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
                   ELSE s.Stu_name
               END as requester_name
        FROM request r 
        LEFT JOIN type t ON r.Type_id = t.Type_id 
        LEFT JOIN student s ON r.Stu_id = s.Stu_id
        LEFT JOIN organization_unit ou_student ON s.Unit_id = ou_student.Unit_id
        WHERE r.Re_level IN ('3', '4', '5') 
        AND r.Re_status IN ('0', '1')
        ORDER BY r.Re_level DESC, r.Re_date ASC 
        LIMIT 5
    ");

    // สร้าง Insights
    $insights = [
        'success' => [],
        'warnings' => [],
        'urgent' => []
    ];

    if ($stats['success_rate'] >= 90) {
        $insights['success'][] = "อัตราความสำเร็จสูงมาก ({$stats['success_rate']}%)";
    } elseif ($stats['success_rate'] < 70) {
        $insights['warnings'][] = "อัตราความสำเร็จต่ำ ({$stats['success_rate']}%) ควรปรับปรุง";
    }

    if ($stats['urgent_complaints'] > 0) {
        $insights['urgent'][] = "มีข้อร้องเรียนเร่งด่วน {$stats['urgent_complaints']} รายการ ต้องดำเนินการทันที";
    }

    if ($stats['pending_complaints'] > 10) {
        $insights['warnings'][] = "ข้อร้องเรียนรอยืนยันจำนวนมาก ({$stats['pending_complaints']} รายการ)";
    }

    if ($stats['avg_rating'] >= 4.5) {
        $insights['success'][] = "คะแนนประเมินความพึงพอใจสูงมาก ({$stats['avg_rating']}/5.0)";
    } elseif ($stats['avg_rating'] < 3.0 && $stats['avg_rating'] > 0) {
        $insights['warnings'][] = "คะแนนประเมินต่ำ ({$stats['avg_rating']}/5.0) ควรปรับปรุงคุณภาพการให้บริการ";
    }

    // เพิ่ม insights สำหรับหน่วยงาน
    if (!empty($unitStats) && $unitStats['assigned_to_unit'] > 0) {
        $unitSuccessRate = $unitStats['assigned_to_unit'] > 0 ? round(($unitStats['completed_by_unit'] / $unitStats['assigned_to_unit']) * 100, 1) : 0;

        if ($unitSuccessRate >= 85) {
            $insights['success'][] = "หน่วยงานของคุณมีอัตราความสำเร็จสูง ({$unitSuccessRate}%)";
        } elseif ($unitSuccessRate < 60) {
            $insights['warnings'][] = "หน่วยงานของคุณควรปรับปรุงประสิทธิภาพ (อัตราสำเร็จ {$unitSuccessRate}%)";
        }
    }
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $error_message = "เกิดข้อผิดพลาดในการโหลดข้อมูล Dashboard";
}

$perm = $_SESSION['permission'] ?? 1;
if ($perm == 3) $pageTitle = 'หน้าหลักผู้ดูแลระบบ';
elseif ($perm == 2) $pageTitle = 'หน้าหลักผู้ดำเนินการ';
else $pageTitle = 'หน้าหลักเจ้าหน้าที่';
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - ระบบข้อร้องเรียน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Sarabun', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .dashboard-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .main-content {
            margin-left: 0;
            padding-top: 80px;
            min-height: 100vh;
        }

        .dashboard-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Welcome Card */
        .welcome-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .welcome-title {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .welcome-subtitle {
            color: #718096;
            font-size: 16px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .unit-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 500;
        }

        .welcome-stats {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            font-size: 14px;
            color: #4a5568;
        }

        .welcome-stat {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .kpi-card {
            background: linear-gradient(145deg, #ffffff, #f0f4f8);
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
        }

        .kpi-card.blue {
            background: linear-gradient(145deg, #3182ce, #2c5282);
            color: white;
        }

        .kpi-card.green {
            background: linear-gradient(145deg, #38a169, #2f855a);
            color: white;
        }

        .kpi-card.orange {
            background: linear-gradient(145deg, #ed8936, #dd6b20);
            color: white;
        }

        .kpi-card.red {
            background: linear-gradient(145deg, #e53e3e, #c53030);
            color: white;
        }

        .kpi-card.purple {
            background: linear-gradient(145deg, #805ad5, #6b46c1);
            color: white;
        }

        .kpi-card.teal {
            background: linear-gradient(145deg, #38b2ac, #319795);
            color: white;
        }

        .kpi-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .kpi-label {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .kpi-trend {
            font-size: 12px;
            opacity: 0.8;
        }

        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .chart-title {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 20px;
            text-align: center;
        }

        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }

        .chart-container.small {
            height: 300px;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #2d3748;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            transition: all 0.3s ease;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            color: #667eea;
        }

        .action-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .action-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .action-subtitle {
            font-size: 12px;
            opacity: 0.7;
        }

        /* Recent Activities */
        .activity-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .activity-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .activity-title {
            font-size: 18px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: rgba(102, 126, 234, 0.05);
            margin: 0 -10px;
            padding: 12px 10px;
            border-radius: 8px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-info {
            flex-grow: 1;
        }

        .activity-name {
            font-weight: 500;
            color: #2d3748;
            margin-bottom: 2px;
        }

        .activity-meta {
            font-size: 12px;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .unit-info {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 8px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 12px;
            font-size: 11px;
            color: #667eea;
            font-weight: 500;
        }

        .activity-status {
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-pending {
            background: #fed7d7;
            color: #c53030;
        }

        .status-processing {
            background: #bee3f8;
            color: #3182ce;
        }

        .status-waiting-eval {
            background: #fef3c7;
            color: #d97706;
        }

        .status-completed {
            background: #c6f6d5;
            color: #38a169;
        }

        .status-rejected {
            background: #fecaca;
            color: #dc2626;
        }

        .status-urgent {
            background: #febb2b;
            color: #744210;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }
        }

        /* Insights */
        .insights-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .insights-title {
            font-size: 20px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .insight-item {
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 10px;
            border-left: 4px solid #48bb78;
            background: rgba(72, 187, 120, 0.05);
        }

        .insight-item.warning {
            border-left-color: #ed8936;
            background: rgba(237, 137, 54, 0.05);
        }

        .insight-item.danger {
            border-left-color: #e53e3e;
            background: rgba(229, 62, 62, 0.05);
        }

        .insight-item h4 {
            margin-bottom: 8px;
            font-size: 16px;
        }

        .insight-item p {
            margin-bottom: 5px;
            font-size: 14px;
            color: #4a5568;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .activity-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-content {
                padding: 15px;
            }

            .welcome-card {
                padding: 20px;
            }

            .welcome-title {
                font-size: 24px;
            }

            .welcome-stats {
                flex-direction: column;
                gap: 15px;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .welcome-subtitle {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 90px;
            right: 20px;
            background: white;
            padding: 15px 20px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 9999;
            max-width: 350px;
            border-left: 4px solid #48bb78;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .notification.success {
            border-left-color: #48bb78;
        }

        .notification.error {
            border-left-color: #f56565;
        }

        .notification.warning {
            border-left-color: #ed8936;
        }

        .notification:hover {
            transform: translateX(-5px);
        }
    </style>
</head>

<body class="dashboard-container">
    <!-- Include Header และ Sidebar -->
    <?php include '../includes/header.php'; ?>
    <?php include '../includes/sidebar.php'; ?>
    <?php if (isset($accessDeniedMessage)): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function() {
                showAccessDenied(
                    "<?php echo $accessDeniedMessage; ?>",
                    "<?php echo $accessDeniedRedirect; ?>"
                );
            });
        </script>
    <?php endif; ?>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="dashboard-content">
            <!-- Welcome Message -->
            <?php if (isset($_GET['welcome'])): ?>
                <div class="notification success" style="position: relative; top: auto; right: auto; margin-bottom: 20px;">
                    <div style="font-weight: bold; margin-bottom: 5px;">✅ ยินดีต้อนรับ!</div>
                    <div>เข้าสู่ระบบ<?php 
                        $perm = $_SESSION['permission'] ?? 1;
                        if ($perm == 3) echo 'ผู้ดูแลระบบ';
                        elseif ($perm == 2) echo 'ผู้ดำเนินการ';
                        else echo 'เจ้าหน้าที่';
                    ?>สำเร็จ</div>
                </div>
            <?php endif; ?>

            <!-- Welcome Card -->
            <div class="welcome-card">
                <div class="welcome-title">
                    👋 สวัสดี, <?php echo htmlspecialchars($user['Aj_name'] ?? 'เจ้าหน้าที่'); ?>
                </div>
                <div class="welcome-subtitle">
                    <span><?php echo $pageTitle; ?> | ตำแหน่ง: <?php echo htmlspecialchars($user['Aj_position'] ?? 'เจ้าหน้าที่'); ?></span>
                    <?php if ($user['Unit_name']): ?>
                        <span class="unit-badge">
                            <?php if ($user['Unit_icon']): ?>
                                <?php echo $user['Unit_icon']; ?>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($user['Unit_type_thai'] ?? 'หน่วยงาน'); ?>: <?php echo htmlspecialchars($user['Unit_name']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="welcome-stats">
                    <div class="welcome-stat">
                        <span>🕐</span>
                        <span>เข้าสู่ระบบล่าสุด: <?php echo date('j M Y, H:i น.'); ?></span>
                    </div>
                    <div class="welcome-stat">
                        <span>📈</span>
                        <span>อัตราความสำเร็จ: <?php echo $stats['success_rate']; ?>%</span>
                    </div>
                    <div class="welcome-stat">
                        <span>⏱️</span>
                        <span>เวลาตอบกลับเฉลี่ย: <?php echo $stats['avg_response_time']; ?> วัน</span>
                    </div>
                    <div class="welcome-stat">
                        <span>⭐</span>
                        <span>คะแนนเฉลี่ย: <?php echo $stats['avg_rating']; ?>/5.0</span>
                    </div>
                    <?php if ($stats['spam_complaints'] > 0): ?>
                        <div class="welcome-stat">
                            <span>🚫</span>
                            <span>สแปม: <?php echo $stats['spam_complaints']; ?> เรื่อง</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- KPI Cards สำหรับหน่วยงาน (ถ้ามีข้อมูล) -->
            <?php if (!empty($unitStats) && $unitStats['assigned_to_unit'] > 0): ?>
                <div class="kpi-grid" style="margin-bottom: 20px;">
                    <div class="kpi-card purple">
                        <div class="kpi-number"><?php echo $unitStats['assigned_to_unit']; ?></div>
                        <div class="kpi-label">งานที่มอบหมายให้หน่วยงาน</div>
                        <div class="kpi-trend">
                            <?php echo htmlspecialchars($user['Unit_type_thai'] ?? ''); ?><?php echo htmlspecialchars($user['Unit_name'] ?? ''); ?>
                        </div>
                    </div>
                    <div class="kpi-card teal">
                        <div class="kpi-number"><?php echo $unitStats['from_unit_students']; ?></div>
                        <div class="kpi-label">จากนักศึกษาในหน่วยงาน</div>
                        <div class="kpi-trend">
                            ข้อร้องเรียนจากนักศึกษาในหน่วยงานเดียวกัน
                        </div>
                    </div>
                    <div class="kpi-card green">
                        <div class="kpi-number"><?php echo $unitStats['completed_by_unit']; ?></div>
                        <div class="kpi-label">เสร็จสิ้นโดยหน่วยงาน</div>
                        <div class="kpi-trend">
                            อัตราสำเร็จ: <?php echo $unitStats['assigned_to_unit'] > 0 ? round(($unitStats['completed_by_unit'] / $unitStats['assigned_to_unit']) * 100, 1) : 0; ?>%
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- KPI Cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-number"><?php echo number_format($stats['total_complaints']); ?></div>
                    <div class="kpi-label">ข้อร้องเรียนทั้งหมด</div>
                    <div class="kpi-trend">
                        วันนี้: <?php echo $stats['today_complaints']; ?> |
                        สัปดาห์นี้: <?php echo $stats['week_complaints']; ?> |
                        เดือนนี้: <?php echo $stats['month_complaints']; ?>
                    </div>
                </div>
                <div class="kpi-card blue">
                    <div class="kpi-number"><?php echo $stats['avg_response_time']; ?></div>
                    <div class="kpi-label">เวลาตอบกลับเฉลี่ย (วัน)</div>
                    <div class="kpi-trend">เป้าหมาย: ≤ 3 วัน</div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-number"><?php echo $stats['success_rate']; ?>%</div>
                    <div class="kpi-label">อัตราความสำเร็จ</div>
                    <div class="kpi-trend">
                        รอประเมินผล: <?php echo $stats['completed_complaints']; ?> |
                        เสร็จสิ้น: <?php echo $stats['evaluated_complaints']; ?>
                    </div>
                </div>
                <div class="kpi-card <?php echo $stats['urgent_complaints'] > 0 ? 'red' : 'orange'; ?>">
                    <div class="kpi-number"><?php echo $stats['urgent_complaints']; ?></div>
                    <div class="kpi-label">ข้อร้องเรียนเร่งด่วน</div>
                    <div class="kpi-trend">ต้องดำเนินการทันที</div>
                </div>
            </div>

            <!-- Interactive Charts -->
            <div class="charts-grid">
                <!-- Monthly Trend Chart -->
                <div class="chart-card">
                    <div class="chart-title">📈 แนวโน้มข้อร้องเรียนรายเดือน</div>
                    <div class="chart-container">
                        <canvas id="monthlyTrendChart"></canvas>
                    </div>
                </div>

                <!-- Category Distribution Chart -->
                <div class="chart-card">
                    <div class="chart-title">📊 การแจกแจงตามประเภท</div>
                    <div class="chart-container small">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Status Overview Chart -->
            <div class="chart-card" style="margin-bottom: 30px;">
                <div class="chart-title">📋 ภาพรวมสถานะข้อร้องเรียน</div>
                <div class="chart-container small">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="manage-complaints.php" class="action-card">
                    <div class="action-icon">📋</div>
                    <div class="action-title">จัดการข้อร้องเรียน</div>
                    <div class="action-subtitle"><?php echo $stats['total_complaints']; ?> รายการ</div>
                </a>
                <?php if ($isAdmin): ?>
                    <a href="reports.php" class="action-card">
                        <div class="action-icon">📊</div>
                        <div class="action-title">รายงาน</div>
                        <div class="action-subtitle">สถิติและการวิเคราะห์</div>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Recent Activities -->
            <div class="activity-grid">
                <!-- Recent Complaints -->
                <div class="activity-card">
                    <div class="activity-title">
                        📝 ข้อร้องเรียนล่าสุด
                    </div>
                    <?php if (!empty($recentComplaints)): ?>
                        <?php foreach (array_slice($recentComplaints, 0, 6) as $complaint): ?>
                            <div class="activity-item">
                                <div class="activity-info">
                                    <div class="activity-name">
                                        <?php echo htmlspecialchars($complaint['Type_icon'] ?? '📋'); ?>
                                        #<?php echo $complaint['Re_id']; ?> -
                                        <?php echo htmlspecialchars(mb_substr($complaint['Re_infor'], 0, 30) . '...'); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span>โดย: <?php echo htmlspecialchars($complaint['requester_name']); ?></span>
                                        <?php if ($complaint['Student_unit_name']): ?>
                                            <span class="unit-info">
                                                <?php echo htmlspecialchars($complaint['Student_unit_icon'] ?? '🏫'); ?>
                                                <?php echo htmlspecialchars($complaint['Student_unit_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span><?php echo date('j M Y, H:i', strtotime($complaint['Re_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="activity-status status-<?php
                                                                    // Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมินผล, 3=เสร็จสิ้น, 4=ปฏิเสธ
                                                                    $statusClass = 'pending';
                                                                    switch ($complaint['Re_status']) {
                                                                        case '0':
                                                                            $statusClass = 'pending';
                                                                            break;
                                                                        case '1':
                                                                            $statusClass = 'processing';
                                                                            break;
                                                                        case '2':
                                                                            $statusClass = 'waiting-eval';
                                                                            break;
                                                                        case '3':
                                                                            $statusClass = 'completed';
                                                                            break;
                                                                        case '4':
                                                                            $statusClass = 'rejected';
                                                                            break;
                                                                    }
                                                                    echo $statusClass;
                                                                    ?>">
                                    <?php
                                    // Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมินผล, 3=เสร็จสิ้น, 4=ปฏิเสธ
                                    $statusText = 'รอยืนยัน';
                                    switch ($complaint['Re_status']) {
                                        case '0':
                                            $statusText = 'รอยืนยัน';
                                            break;
                                        case '1':
                                            $statusText = 'กำลังดำเนินการ';
                                            break;
                                        case '2':
                                            $statusText = 'รอประเมินผล';
                                            break;
                                        case '3':
                                            $statusText = 'เสร็จสิ้น';
                                            break;
                                        case '4':
                                            $statusText = 'ปฏิเสธ';
                                            break;
                                    }
                                    echo $statusText;
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-item">
                            <div class="activity-info">
                                <div class="activity-name">ไม่มีข้อร้องเรียนใหม่</div>
                                <div class="activity-meta">ทุกอย่างดูดี! 🎉</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Urgent Complaints -->
                <div class="activity-card">
                    <div class="activity-title">
                        🚨 ข้อร้องเรียนเร่งด่วน
                    </div>
                    <?php if (!empty($urgentComplaints)): ?>
                        <?php foreach ($urgentComplaints as $urgent): ?>
                            <div class="activity-item">
                                <div class="activity-info">
                                    <div class="activity-name">
                                        <?php echo htmlspecialchars($urgent['Type_icon'] ?? '📋'); ?>
                                        #<?php echo $urgent['Re_id']; ?> -
                                        <?php echo htmlspecialchars(mb_substr($urgent['Re_infor'], 0, 25) . '...'); ?>
                                    </div>
                                    <div class="activity-meta">
                                        <span>ระดับ: <?php echo $urgent['Re_level']; ?></span>
                                        <?php if ($urgent['Student_unit_name']): ?>
                                            <span class="unit-info">
                                                <?php echo htmlspecialchars($urgent['Student_unit_icon'] ?? '🏫'); ?>
                                                <?php echo htmlspecialchars($urgent['Student_unit_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <span><?php echo date('j M Y', strtotime($urgent['Re_date'])); ?></span>
                                    </div>
                                </div>
                                <div class="activity-status status-urgent">
                                    เร่งด่วน
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="activity-item">
                            <div class="activity-info">
                                <div class="activity-name">ไม่มีข้อร้องเรียนเร่งด่วน</div>
                                <div class="activity-meta">สถานการณ์ปกติ ✅</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AI Insights -->
            <?php if (!empty($insights['success']) || !empty($insights['warnings']) || !empty($insights['urgent'])): ?>
                <div class="insights-card">
                    <div class="insights-title">
                        🤖 ข้อเสนะแนะจากระบบ AI
                    </div>

                    <?php if (!empty($insights['success'])): ?>
                        <div class="insight-item">
                            <h4 style="color: #38a169;">✅ จุดแข็ง</h4>
                            <?php foreach ($insights['success'] as $success): ?>
                                <p>• <?php echo htmlspecialchars($success); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($insights['warnings'])): ?>
                        <div class="insight-item warning">
                            <h4 style="color: #d69e2e;">⚠️ จุดที่ต้องปรับปรุง</h4>
                            <?php foreach ($insights['warnings'] as $warning): ?>
                                <p>• <?php echo htmlspecialchars($warning); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($insights['urgent'])): ?>
                        <div class="insight-item danger">
                            <h4 style="color: #e53e3e;">🚨 ข้อเสนอแนะเร่งด่วน</h4>
                            <?php foreach ($insights['urgent'] as $urgent): ?>
                                <p>• <?php echo htmlspecialchars($urgent); ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Performance Summary -->
                    <div class="insight-item" style="border-left-color: #17a2b8; background: rgba(23, 162, 184, 0.05);">
                        <h4 style="color: #17a2b8;">📈 สรุปประสิทธิภาพ (เดือนนี้)</h4>
                        <p>• จัดการข้อร้องเรียนทั้งหมด: <?php echo number_format($stats['month_complaints']); ?> เรื่อง</p>
                        <p>• อัตราความสำเร็จ: <?php echo $stats['success_rate']; ?>%</p>
                        <p>• คะแนนความพึงพอใจ: <?php echo $stats['avg_rating']; ?>/5.0</p>
                        <p>• เวลาตอบกลับเฉลี่ย: <?php echo $stats['avg_response_time']; ?> วัน</p>
                    </div>

                    <!-- System Status -->
                    <div class="insight-item" style="border-left-color: #6f42c1; background: rgba(111, 66, 193, 0.05);">
                        <h4 style="color: #6f42c1;">🔧 สถานะระบบ</h4>
                        <p>• ข้อร้องเรียนสแปม: <?php echo $stats['spam_complaints']; ?> เรื่อง (<?php echo $stats['total_complaints'] > 0 ? round(($stats['spam_complaints'] / $stats['total_complaints']) * 100, 1) : 0; ?>%)</p>
                        <p>• นักศึกษาใช้งานระบบ: <?php echo number_format($stats['total_students']); ?> คน</p>
                        <p>• เจ้าหน้าที่ในระบบ: <?php echo number_format($stats['total_staff']); ?> คน</p>
                        <?php if ($user['Unit_name']): ?>
                            <p>• หน่วยงานของคุณ: <?php echo htmlspecialchars($user['Unit_type_thai'] ?? ''); ?><?php echo htmlspecialchars($user['Unit_name']); ?></p>
                        <?php endif; ?>
                        <?php if ($isAdmin): ?>
                            <p>• อัปเดตล่าสุด: <?php echo date('j M Y, H:i น.'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript for Interactive Charts -->
    <script>
        // Chart.js Configuration - Simple and Fast
        Chart.defaults.font.family = "'Sarabun', sans-serif";
        Chart.defaults.font.size = 12;

        document.addEventListener('DOMContentLoaded', function() {
            // Initialize charts immediately without delays
            initializeMonthlyChart();
            initializeCategoryChart();
            initializeStatusChart();
        });

        // Monthly Trend Chart
        function initializeMonthlyChart() {
            const monthlyCtx = document.getElementById('monthlyTrendChart');
            if (!monthlyCtx) return;

            new Chart(monthlyCtx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: [
                        <?php foreach ($monthlyStats as $month): ?> '<?php echo date('M Y', strtotime($month['month'] . '-01')); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'ข้อร้องเรียนทั้งหมด',
                        data: [
                            <?php foreach ($monthlyStats as $month): ?>
                                <?php echo $month['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: 'rgb(102, 126, 234)',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'เสร็จสิ้นแล้ว',
                        data: [
                            <?php foreach ($monthlyStats as $month): ?>
                                <?php echo ($month['waiting_eval'] ?? 0) + ($month['completed'] ?? 0); ?>,
                            <?php endforeach; ?>
                        ],
                        borderColor: 'rgb(56, 161, 105)',
                        backgroundColor: 'rgba(56, 161, 105, 0.1)',
                        tension: 0.4,
                        fill: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'แนวโน้มข้อร้องเรียน 6 เดือนล่าสุด'
                        },
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        },
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'เดือน'
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'จำนวน (เรื่อง)'
                            },
                            beginAtZero: true
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }

        // Category Distribution Chart
        function initializeCategoryChart() {
            const categoryCtx = document.getElementById('categoryChart');
            if (!categoryCtx) return;

            new Chart(categoryCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: [
                        <?php foreach ($categoryStats as $category): ?> '<?php echo addslashes($category['category']); ?>',
                        <?php endforeach; ?>
                    ],
                    datasets: [{
                        label: 'จำนวนข้อร้องเรียน',
                        data: [
                            <?php foreach ($categoryStats as $category): ?>
                                <?php echo $category['total']; ?>,
                            <?php endforeach; ?>
                        ],
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 205, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)',
                            'rgba(255, 159, 64, 0.8)',
                            'rgba(199, 199, 199, 0.8)',
                            'rgba(83, 102, 255, 0.8)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 205, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(199, 199, 199, 1)',
                            'rgba(83, 102, 255, 1)'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} เรื่อง (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }

        // Status Overview Chart
        function initializeStatusChart() {
            const statusCtx = document.getElementById('statusChart');
            if (!statusCtx) return;

            new Chart(statusCtx.getContext('2d'), {
                type: 'bar',
                data: {
                    // Re_status: 0=ยื่นคำร้อง, 1=กำลังดำเนินการ, 2=รอประเมินผล, 3=เสร็จสิ้น, 4=ปฏิเสธ
                    labels: ['รอยืนยัน', 'กำลังดำเนินการ', 'รอประเมินผล', 'เสร็จสิ้น', 'ปฏิเสธ'],
                    datasets: [{
                        label: 'จำนวนข้อร้องเรียน',
                        data: [
                            <?php echo $stats['pending_complaints']; ?>,
                            <?php echo $stats['processing_complaints']; ?>,
                            <?php echo $stats['completed_complaints']; ?>,
                            <?php echo $stats['evaluated_complaints']; ?>,
                            <?php echo $stats['rejected_complaints'] ?? 0; ?>
                        ],
                        backgroundColor: [
                            'rgba(255, 193, 7, 0.8)', // รอยืนยัน - Yellow
                            'rgba(0, 123, 255, 0.8)', // กำลังดำเนินการ - Blue
                            'rgba(255, 159, 64, 0.8)', // รอประเมินผล - Orange
                            'rgba(40, 167, 69, 0.8)', // เสร็จสิ้น - Green
                            'rgba(220, 53, 69, 0.8)' // ปฏิเสธ - Red
                        ],
                        borderColor: [
                            'rgba(255, 193, 7, 1)',
                            'rgba(0, 123, 255, 1)',
                            'rgba(255, 159, 64, 1)',
                            'rgba(40, 167, 69, 1)',
                            'rgba(220, 53, 69, 1)'
                        ],
                        borderWidth: 2,
                        borderRadius: 5,
                        borderSkipped: false,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    const total = <?php echo $stats['total_complaints']; ?>;
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value} เรื่อง (${percentage}%)`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'จำนวน (เรื่อง)'
                            }
                        }
                    }
                }
            });
        }

        // Show welcome notification if redirected from login
        <?php if (isset($_GET['welcome'])): ?>
            setTimeout(() => {
                showNotification('ยินดีต้อนรับเข้าสู่ระบบ <?php 
                    $perm = $_SESSION['permission'] ?? 1;
                    if ($perm == 3) echo 'ผู้ดูแลระบบ';
                    elseif ($perm == 2) echo 'ผู้ดำเนินการ';
                    else echo 'เจ้าหน้าที่';
                ?>!', 'success');
            }, 1000);
        <?php endif; ?>

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <div style="font-weight: bold; margin-bottom: 5px;">
                    ${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'} 
                    ${type === 'success' ? 'สำเร็จ' : type === 'error' ? 'ข้อผิดพลาด' : type === 'warning' ? 'คำเตือน' : 'แจ้งเตือน'}
                </div>
                <div>${message}</div>
            `;

            document.body.appendChild(notification);

            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);

            // Click to dismiss
            notification.onclick = () => notification.remove();
        }
    </script>
</body>

</html>