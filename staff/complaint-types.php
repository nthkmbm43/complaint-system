<?php
// staff/complaint-types.php - หน้าจัดการประเภทข้อร้องเรียน (แก้ไขการแสดงผลให้ตรงบริบท)
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
if ($userPermission < 3) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดูแลระบบเท่านั้น เนื่องจากการจัดการประเภทข้อร้องเรียนเป็นสิทธิ์เฉพาะผู้ดูแลระบบ (สิทธิ์ระดับ 3)";
    $accessDeniedRedirect = "index.php";
}

$db = getDB();
$user = getCurrentUser();

// ตรวจสอบระดับสิทธิ์ (Admin=2)
$isAdmin = ($_SESSION['permission'] ?? 0) == 3; // เฉพาะผู้ดูแลระบบ (permission=3) เท่านั้น
$canEditSystem = $isAdmin;

// --- ส่วนจัดการ POST Action (คงเดิม ไม่ต้องแก้) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $canEditSystem) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'update_complaint_type') {
            $typeId = intval($_POST['type_id']);
            $typeInfo = trim($_POST['type_info']);
            $typeIcon = trim($_POST['type_icon'] ?? '📋');
            if (empty($typeInfo)) throw new Exception('กรุณาระบุชื่อประเภท');
            $sql = "UPDATE type SET Type_infor = ?, Type_icon = ? WHERE Type_id = ?";
            $stmt = $db->execute($sql, [$typeInfo, $typeIcon, $typeId]);
            echo json_encode(['success' => true, 'message' => "แก้ไขสำเร็จ"]);
        } elseif ($_POST['action'] === 'add_complaint_type') {
            $newTypeInfo = trim($_POST['new_type_info']);
            $newTypeIcon = trim($_POST['new_type_icon'] ?? '📋');
            if (empty($newTypeInfo)) throw new Exception('กรุณาระบุชื่อประเภท');
            $existing = $db->fetch("SELECT Type_id FROM type WHERE Type_infor = ?", [$newTypeInfo]);
            if ($existing) throw new Exception('มีข้อมูลนี้แล้ว');

            // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
            $maxId = $db->fetch("SELECT COALESCE(MAX(Type_id), 0) + 1 as new_id FROM type");
            $newId = $maxId['new_id'];

            $sql = "INSERT INTO type (Type_id, Type_infor, Type_icon) VALUES (?, ?, ?)";
            $stmt = $db->execute($sql, [$newId, $newTypeInfo, $newTypeIcon]);
            echo json_encode(['success' => true, 'message' => "เพิ่มสำเร็จ", 'new_id' => $newId, 'new_name' => $newTypeInfo, 'new_icon' => $newTypeIcon]);
        } elseif ($_POST['action'] === 'delete_complaint_type') {
            $typeId = intval($_POST['type_id']);
            $usageCount = $db->count('request', 'Type_id = ?', [$typeId]);
            if ($usageCount > 0) throw new Exception("ไม่สามารถลบได้ เนื่องจากมีข้อร้องเรียน {$usageCount} รายการที่ใช้ประเภทนี้อยู่");
            $db->execute("DELETE FROM type WHERE Type_id = ?", [$typeId]);
            echo json_encode(['success' => true, 'message' => "ลบประเภทข้อร้องเรียนสำเร็จ"]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- ส่วนดึงข้อมูล (แก้ไขใหม่ให้ตรงบริบท) ---
$complaintTypes = [];
$typeStats = [];

try {
    // 1. ดึงประเภทข้อร้องเรียนทั้งหมด พร้อมจำนวนการใช้งานและสถานะต่างๆ
    $complaintTypes = $db->fetchAll("
        SELECT t.*, 
            COUNT(r.Re_id) as usage_count,
            SUM(CASE WHEN r.Re_status = '0' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN r.Re_status = '1' THEN 1 ELSE 0 END) as processing_count,
            SUM(CASE WHEN r.Re_status IN ('2', '3') THEN 1 ELSE 0 END) as completed_count
        FROM type t
        LEFT JOIN request r ON t.Type_id = r.Type_id
        GROUP BY t.Type_id
        ORDER BY t.Type_id
    ");

    // 2. หาประเภทที่มีการร้องเรียนมากที่สุด (Most Popular)
    $topType = $db->fetch("
        SELECT t.Type_infor, COUNT(r.Re_id) as total
        FROM type t
        LEFT JOIN request r ON t.Type_id = r.Type_id
        GROUP BY t.Type_id
        ORDER BY total DESC
        LIMIT 1
    ");

    // 3. หาประเภทที่มีเรื่องรอรับเรื่องเยอะสุด (Most Pending - Re_status = '0')
    $pendingType = $db->fetch("
        SELECT t.Type_infor, COUNT(r.Re_id) as total
        FROM type t
        LEFT JOIN request r ON t.Type_id = r.Type_id
        WHERE r.Re_status = '0'
        GROUP BY t.Type_id
        ORDER BY total DESC
        LIMIT 1
    ");

    // 4. สรุปข้อมูลสถิติ
    $typeStats = [
        'total_types' => count($complaintTypes), // จำนวนประเภททั้งหมด
        'total_usage' => $db->count('request'),  // จำนวนการใช้งานรวมทุกประเภท
        'active_types' => 0, // ประเภทที่มีการใช้งานจริง (อย่างน้อย 1 ครั้ง)
        'top_type_name' => $topType['Type_infor'] ?? '-',
        'top_type_count' => $topType['total'] ?? 0,
        'most_pending_name' => $pendingType['Type_infor'] ?? '-',
        'most_pending_count' => $pendingType['total'] ?? 0
    ];

    // นับ Active Types (วิธี Manual ง่ายๆ)
    $activeCount = $db->fetch("SELECT COUNT(DISTINCT Type_id) as c FROM request")['c'];
    $typeStats['active_types'] = $activeCount;
} catch (Exception $e) {
    error_log("Error loading types: " . $e->getMessage());
}

$pageTitle = 'จัดการประเภทข้อร้องเรียน';
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        /* ... (CSS เดิมทั้งหมดของคุณ) ... */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 80px 40px 40px 40px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .header-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }

        .header-icon {
            font-size: 3rem;
            color: #667eea;
        }

        .header-text h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .header-text p {
            color: #64748b;
            font-size: 1.1rem;
            line-height: 1.5;
        }

        .header-meta {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            font-size: 0.9rem;
            color: #6b7280;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .management-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .management-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f1f5f9;
        }

        .management-title {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
        }

        .add-btn {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
        }

        .type-item {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            margin-bottom: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }

        .type-item:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .type-icon {
            width: 50px;
            height: 50px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .type-content {
            flex: 1;
        }

        .type-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .type-id {
            font-size: 0.85rem;
            color: #64748b;
        }

        .type-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn-edit {
            background: #f59e0b;
            color: white;
        }

        .btn-edit:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .readonly-notice {
            background: #e0f2fe;
            border: 1px solid #0284c7;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            color: #0c4a6e;
            text-align: center;
        }

        /* Modal CSS omitted for brevity but include all original modal styles here */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: white;
            border-radius: 20px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            transform: translateY(30px) scale(0.9);
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .modal-overlay.active .modal {
            transform: translateY(0) scale(1);
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .modal-title {
            font-size: 1.4rem;
            font-weight: 600;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
            overflow-y: auto;
            flex: 1;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f9fafb;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .icon-selector {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .selected-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .selected-icon:hover {
            border-color: #667eea;
            transform: scale(1.05);
        }

        .icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
        }

        .icon-option {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            border-radius: 8px;
            transition: all 0.2s ease;
            background: white;
            border: 2px solid transparent;
        }

        .icon-option:hover {
            border-color: #667eea;
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .icon-option.selected {
            border-color: #667eea;
            background: #eef2ff;
            transform: scale(1.1);
        }

        .modal-footer {
            padding: 20px 30px;
            background: #f8fafc;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e5e7eb;
            flex-shrink: 0;
        }

        .btn-primary {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.3);
        }

        .btn-secondary {
            background: #dc3545;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #c82333;
            transform: translateY(-2px);
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Delete Modal */
        .delete-modal .modal-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .warning-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fef2f2;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
            font-size: 2.5rem;
        }

        .delete-text {
            text-align: center;
            color: #374151;
            margin-bottom: 20px;
        }

        .type-preview {
            background: #f3f4f6;
            padding: 16px;
            border-radius: 12px;
            margin: 16px 0;
            text-align: center;
        }

        /* Success Modal */
        .success-modal .modal-header {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .success-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #ecfdf5;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #10b981;
            font-size: 2.5rem;
            animation: successPulse 0.6s ease-out;
        }

        @keyframes successPulse {
            0% {
                transform: scale(0);
                opacity: 0;
            }

            50% {
                transform: scale(1.2);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-text {
            text-align: center;
            color: #374151;
            margin-bottom: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        @media (max-width: 768px) {
            body {
                padding: 80px 20px 20px 20px;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .header-meta {
                justify-content: center;
            }

            .type-item {
                flex-direction: column;
                text-align: center;
            }

            .type-actions {
                flex-direction: column;
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to   { transform: scale(1);   opacity: 1; }
        }
    </style>
</head>

<body>
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

    <div class="container">
        <div class="header-card">
            <div class="header-content">
                <div class="header-icon">🏷️</div>
                <div class="header-text">
                    <h1>จัดการประเภทข้อร้องเรียน</h1>
                    <p>กำหนดหัวข้อประเภท เพื่อช่วยในการจัดหมวดหมู่ปัญหา | ผู้ดูแล: <?php echo htmlspecialchars($user['Aj_name']); ?></p>
                </div>
            </div>
            <div class="header-meta">
                <div class="meta-item">
                    <span>📅</span>
                    <span>ข้อมูลล่าสุด: <?php echo date('j M Y'); ?></span>
                </div>
                <div class="meta-item">
                    <span>📁</span>
                    <span>ประเภททั้งหมด: <?php echo $typeStats['total_types']; ?> ประเภท</span>
                </div>
                <div class="meta-item">
                    <span>🔥</span>
                    <span>ประเภทที่ร้องเรียนมากที่สุด: <?php echo htmlspecialchars($typeStats['top_type_name']); ?></span>
                </div>
                <div class="meta-item">
                    <span>📝</span>
                    <span>ใช้งานรวม: <?php echo number_format($typeStats['total_usage']); ?> ครั้ง</span>
                </div>
            </div>
        </div>

        <?php if (!$canEditSystem): ?>
            <div class="readonly-notice">
                <strong>📋 โหมดการดู:</strong> คุณสามารถดูข้อมูลได้เท่านั้น การแก้ไขต้องมีสิทธิ์ผู้ดูแลระบบ
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📚</div>
                <div class="stat-number"><?php echo number_format($typeStats['total_types']); ?></div>
                <div class="stat-label">ประเภททั้งหมดในระบบ</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-number"><?php echo number_format($typeStats['active_types']); ?></div>
                <div class="stat-label">ประเภทที่มีการใช้งานจริง</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">🏆</div>
                <div class="stat-number" style="font-size: 1.2rem; min-height: 40px; display:flex; align-items:center; justify-content:center;">
                    <?php echo htmlspecialchars($typeStats['top_type_name']); ?>
                </div>
                <div class="stat-label">ประเภทที่ร้องเรียนมากที่สุด (<?php echo number_format($typeStats['top_type_count']); ?>)</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⏳</div>
                <div class="stat-number" style="font-size: 1.2rem; min-height: 40px; display:flex; align-items:center; justify-content:center;">
                    <?php echo htmlspecialchars($typeStats['most_pending_name']); ?>
                </div>
                <div class="stat-label">รอรับเรื่องมากสุด (<?php echo number_format($typeStats['most_pending_count']); ?>)</div>
            </div>
        </div>

        <div class="management-card">
            <div class="management-header">
                <div class="management-title">
                    <span>📑</span>
                    <span>รายการประเภทที่มีอยู่</span>
                </div>
                <?php if ($canEditSystem): ?>
                    <button class="add-btn" onclick="openAddModal()">
                        <span>➕</span>
                        <span>เพิ่มประเภทใหม่</span>
                    </button>
                <?php endif; ?>
            </div>

            <div id="typesList">
                <?php if (empty($complaintTypes)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📂</div>
                        <h3>ยังไม่มีประเภทข้อร้องเรียน</h3>
                        <p>เริ่มต้นโดยการเพิ่มประเภทข้อร้องเรียนใหม่ เพื่อให้นักศึกษาสามารถเลือกหัวข้อได้ถูกต้อง</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($complaintTypes as $type): ?>
                        <?php
                        $usageCount = intval($type['usage_count'] ?? 0);
                        $pendingCount = intval($type['pending_count'] ?? 0);
                        $processingCount = intval($type['processing_count'] ?? 0);
                        $completedCount = intval($type['completed_count'] ?? 0);
                        ?>
                        <div class="type-item" data-id="<?php echo $type['Type_id']; ?>">
                            <div class="type-icon"><?php echo htmlspecialchars($type['Type_icon'] ?: '📋'); ?></div>
                            <div class="type-content">
                                <div class="type-name"><?php echo htmlspecialchars($type['Type_infor']); ?></div>
                                <div class="type-id">System ID: <?php echo $type['Type_id']; ?></div>
                                <div class="type-usage" style="font-size: 0.8rem; margin-top: 4px;">
                                    <?php if ($usageCount > 0): ?>
                                        <span style="color: #3b82f6;">📊 ทั้งหมด <?php echo number_format($usageCount); ?></span>
                                        <?php if ($pendingCount > 0): ?>
                                            <span style="color: #f59e0b; margin-left: 8px;">⏳ รอรับเรื่อง <?php echo number_format($pendingCount); ?></span>
                                        <?php endif; ?>
                                        <?php if ($processingCount > 0): ?>
                                            <span style="color: #8b5cf6; margin-left: 8px;">🔄 กำลังดำเนินการ <?php echo number_format($processingCount); ?></span>
                                        <?php endif; ?>
                                        <?php if ($completedCount > 0): ?>
                                            <span style="color: #10b981; margin-left: 8px;">✅ เสร็จสิ้น <?php echo number_format($completedCount); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">📊 ยังไม่มีการใช้งาน</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($canEditSystem): ?>
                                <div class="type-actions">
                                    <button class="btn btn-edit" onclick="openEditModal(<?php echo $type['Type_id']; ?>, '<?php echo addslashes($type['Type_infor']); ?>', '<?php echo addslashes($type['Type_icon'] ?: '📋'); ?>')">
                                        ✏️ แก้ไข
                                    </button>
                                    <button class="btn btn-delete" onclick="openDeleteModal(<?php echo $type['Type_id']; ?>, '<?php echo addslashes($type['Type_infor']); ?>', '<?php echo addslashes($type['Type_icon'] ?: '📋'); ?>', <?php echo $usageCount; ?>, <?php echo $pendingCount; ?>, <?php echo $processingCount; ?>, <?php echo $completedCount; ?>)">
                                        🗑️ ลบ
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="formModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">เพิ่มประเภทข้อร้องเรียนใหม่</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="typeForm">
                    <div class="form-group">
                        <label class="form-label">ไอคอน</label>
                        <div class="icon-selector">
                            <div class="selected-icon" id="selectedIcon" onclick="toggleIconGrid()">📋</div>
                            <span style="color: #6b7280;">คลิกเพื่อเลือกไอคอน</span>
                        </div>
                        <div class="icon-grid" id="iconGrid" style="display: none;"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">ชื่อประเภทข้อร้องเรียน</label>
                        <input type="text" class="form-input" id="typeName" placeholder="ระบุชื่อประเภทข้อร้องเรียน" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeModal()">ยกเลิก</button>
                <button class="btn-primary" id="submitBtn" onclick="submitForm()">
                    <span id="submitText">บันทึก</span>
                    <span class="loading" id="submitLoading" style="display: none;"></span>
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay delete-modal" id="deleteModal">
        <div class="modal" style="max-width: 460px;">
            <div class="modal-header">
                <h3 class="modal-title">ยืนยันการลบ</h3>
                <button class="modal-close" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div style="text-align: center; padding: 10px 0 25px;">
                <div style="font-size: 52px; margin-bottom: 15px;">🗑️</div>
                <p style="font-size: 16px; color: #2d3748; margin-bottom: 8px;">คุณต้องการลบประเภทข้อร้องเรียน</p>
                <p style="font-size: 18px; font-weight: bold; color: #667eea; margin-bottom: 8px;">
                    "<span id="deleteIcon">📋</span> <span id="deleteName">ชื่อประเภท</span>"
                </p>
                <p style="font-size: 13px; color: #e53e3e; font-weight: 600;">⚠️ หากถูกลบแล้วจะไม่สามารถเรียกคืนได้ ⚠️</p>
            </div>
            <div style="display: flex; gap: 12px; justify-content: center; padding-bottom: 10px;">
                <button type="button" class="btn-secondary" onclick="closeDeleteModal()" style="min-width: 120px;">ยกเลิก</button>
                <button type="button" class="btn btn-confirm-delete" id="deleteBtn" onclick="confirmDelete()" style="min-width: 120px;">
                    <span id="deleteText">ยืนยันการลบ</span>
                    <span class="loading" id="deleteLoading" style="display: none;"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Cannot Delete Popup (style เหมือน organization-management) -->
    <div id="cannotDeleteOverlay" style="
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.5); z-index: 10000;
        align-items: center; justify-content: center;
    ">
        <div style="
            background: white; border-radius: 20px; padding: 35px 40px;
            max-width: 440px; width: 90%;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            text-align: center; animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        ">
            <div style="
                width: 70px; height: 70px;
                background: linear-gradient(135deg, #fed7d7, #feb2b2);
                border-radius: 50%; display: flex; align-items: center;
                justify-content: center; font-size: 32px; margin: 0 auto 20px;
            ">⚠️</div>
            <h3 style="color: #c53030; font-size: 20px; margin-bottom: 12px; font-weight: 700;">ไม่สามารถลบได้</h3>
            <div id="cannotDeleteMessage" style="
                background: #fff5f5; border: 1px solid #fed7d7; border-radius: 12px;
                padding: 14px 18px; color: #742a2a; font-size: 14px;
                line-height: 1.7; margin-bottom: 25px; text-align: left;
            "></div>
            <button onclick="closeCannotDeleteAlert()" style="
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white; border: none; border-radius: 12px;
                padding: 12px 35px; font-size: 15px; font-weight: 600;
                cursor: pointer; transition: all 0.2s;
            " onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(102,126,234,0.4)'"
               onmouseout="this.style.transform='';this.style.boxShadow=''">
                รับทราบ
            </button>
        </div>
    </div>

    <div class="modal-overlay success-modal" id="successModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">สำเร็จ</h3>
                <button class="modal-close" onclick="closeSuccessModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="success-icon">✓</div>
                <div class="success-text">
                    <p id="successMessage" style="font-size: 1.1rem; color: #374151;">ดำเนินการสำเร็จ!</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-primary" onclick="closeSuccessModal()">ตกลง</button>
            </div>
        </div>
    </div>

    <script>
        // Available icons
        const availableIcons = [
            '📋', '📝', '📄', '📃', '📑', '📊', '📈', '📉', '📌', '📍',
            '🏢', '🏫', '🏪', '🏬', '🏭', '🏠', '🏡', '🏘️', '🏰', '🏛️',
            '👨‍🎓', '👩‍🎓', '👨‍🏫', '👩‍🏫', '👥', '👤', '👦', '👧', '🧑‍💼', '👩‍💼',
            '💻', '📱', '🖥️', '⌨️', '🖱️', '💾', '💿', '📀', '💡', '🔌',
            '🚗', '🚌', '🚲', '🛴', '🚁', '✈️', '🚂', '🚇', '🚢', '⛵',
            '🍎', '🍊', '🍌', '🍇', '🥕', '🥬', '🍕', '🍔', '🌭', '🥪',
            '⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🏓', '🎱', '🏸', '🥅',
            '🎵', '🎶', '🎸', '🎹', '🥁', '🎺', '🎷', '🎻', '🎤', '🎧',
            '🎨', '🖌️', '🖍️', '✏️', '✒️', '🖊️', '🖋️', '✂️', '📐', '📏',
            '🔧', '🔨', '⚒️', '🛠️', '⚙️', '🔩', '⚡', '🔥', '💧', '❄️'
        ];

        let currentEditingId = null;
        let currentSelectedIcon = '📋';
        let isEditMode = false;

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            populateIconGrid();
        });

        // Populate icon grid
        function populateIconGrid() {
            const iconGrid = document.getElementById('iconGrid');
            iconGrid.innerHTML = '';

            availableIcons.forEach(icon => {
                const iconElement = document.createElement('div');
                iconElement.className = 'icon-option';
                iconElement.textContent = icon;
                iconElement.onclick = () => selectIcon(icon);
                iconGrid.appendChild(iconElement);
            });
        }

        // Toggle icon grid visibility
        function toggleIconGrid() {
            const iconGrid = document.getElementById('iconGrid');
            iconGrid.style.display = iconGrid.style.display === 'none' ? 'grid' : 'none';
        }

        // Select icon
        function selectIcon(icon) {
            currentSelectedIcon = icon;
            document.getElementById('selectedIcon').textContent = icon;

            // Update selection visual
            document.querySelectorAll('.icon-option').forEach(el => {
                el.classList.remove('selected');
            });
            event.target.classList.add('selected');

            // Hide grid after selection
            setTimeout(() => {
                document.getElementById('iconGrid').style.display = 'none';
            }, 300);
        }

        // Open add modal
        function openAddModal() {
            isEditMode = false;
            currentEditingId = null;

            document.getElementById('modalTitle').textContent = 'เพิ่มประเภทข้อร้องเรียนใหม่';
            document.getElementById('typeName').value = '';
            document.getElementById('selectedIcon').textContent = '📋';
            document.getElementById('submitText').textContent = 'เพิ่มประเภทใหม่';

            currentSelectedIcon = '📋';
            document.getElementById('formModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Open edit modal
        function openEditModal(id, name, icon) {
            isEditMode = true;
            currentEditingId = id;

            document.getElementById('modalTitle').textContent = 'แก้ไขประเภทข้อร้องเรียน';
            document.getElementById('typeName').value = name;
            document.getElementById('selectedIcon').textContent = icon;
            document.getElementById('submitText').textContent = 'บันทึกการแก้ไข';

            currentSelectedIcon = icon;
            document.getElementById('formModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        // Close modal
        function closeModal() {
            document.getElementById('formModal').classList.remove('active');
            document.getElementById('iconGrid').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Open delete modal
        function openDeleteModal(id, name, icon, usageCount = 0, pendingCount = 0, processingCount = 0, completedCount = 0) {
            currentEditingId = id;

            const canDelete = (usageCount === 0);

            if (!canDelete) {
                // ลบไม่ได้ → เปิด popup แจ้งเตือน
                let statusDetails = [];
                if (pendingCount > 0)    statusDetails.push(`รอรับเรื่อง ${pendingCount} รายการ`);
                if (processingCount > 0) statusDetails.push(`กำลังดำเนินการ ${processingCount} รายการ`);
                if (completedCount > 0)  statusDetails.push(`เสร็จสิ้นแล้ว ${completedCount} รายการ`);

                const msg = `ประเภทนี้มีข้อร้องเรียนทั้งหมด ${usageCount} รายการ` +
                    (statusDetails.length ? ` (${statusDetails.join(', ')})` : '') +
                    ` กรุณาย้ายหรือลบข้อร้องเรียนเหล่านั้นก่อน`;
                document.getElementById('cannotDeleteMessage').textContent = msg;
                document.getElementById('cannotDeleteOverlay').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            } else {
                // ลบได้ → เปิด modal ยืนยัน
                document.getElementById('deleteIcon').textContent = icon;
                document.getElementById('deleteName').textContent = name;
                document.getElementById('deleteModal').classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCannotDeleteAlert() {
            document.getElementById('cannotDeleteOverlay').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close delete modal
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Submit form
        function submitForm() {
            const typeName = document.getElementById('typeName').value.trim();

            if (!typeName) {
                showSuccessModal('กรุณาระบุชื่อประเภทข้อร้องเรียน', 'error');
                return;
            }

            // Show loading
            document.getElementById('submitText').style.display = 'none';
            document.getElementById('submitLoading').style.display = 'inline-block';
            document.getElementById('submitBtn').disabled = true;

            // Prepare form data
            const formData = new FormData();
            if (isEditMode) {
                formData.append('action', 'update_complaint_type');
                formData.append('type_id', currentEditingId);
                formData.append('type_info', typeName);
                formData.append('type_icon', currentSelectedIcon);
            } else {
                formData.append('action', 'add_complaint_type');
                formData.append('new_type_info', typeName);
                formData.append('new_type_icon', currentSelectedIcon);
            }

            // Send AJAX request
            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading
                    document.getElementById('submitText').style.display = 'inline';
                    document.getElementById('submitLoading').style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;

                    if (data.success) {
                        // Close modal
                        closeModal();

                        // Show success modal
                        showSuccessModal(data.message);

                        // Update UI
                        if (isEditMode) {
                            updateTypeInList(currentEditingId, typeName, currentSelectedIcon);
                        } else {
                            addTypeToList(data.new_id, data.new_name, data.new_icon);
                        }
                    } else {
                        showSuccessModal(data.message, 'error');
                    }
                })
                .catch(error => {
                    // Hide loading
                    document.getElementById('submitText').style.display = 'inline';
                    document.getElementById('submitLoading').style.display = 'none';
                    document.getElementById('submitBtn').disabled = false;

                    showSuccessModal('เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์', 'error');
                    console.error('Error:', error);
                });
        }

        // Confirm delete
        function confirmDelete() {
            // Show loading
            document.getElementById('deleteText').style.display = 'none';
            document.getElementById('deleteLoading').style.display = 'inline-block';
            document.getElementById('deleteBtn').disabled = true;

            // Prepare form data
            const formData = new FormData();
            formData.append('action', 'delete_complaint_type');
            formData.append('type_id', currentEditingId);

            // Send AJAX request
            fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading
                    document.getElementById('deleteText').style.display = 'inline';
                    document.getElementById('deleteLoading').style.display = 'none';
                    document.getElementById('deleteBtn').disabled = false;

                    if (data.success) {
                        // Close modal
                        closeDeleteModal();

                        // Show success modal
                        showSuccessModal(data.message);

                        // Remove from UI
                        removeTypeFromList(currentEditingId);
                    } else {
                        showSuccessModal(data.message, 'error');
                    }
                })
                .catch(error => {
                    // Hide loading
                    document.getElementById('deleteText').style.display = 'inline';
                    document.getElementById('deleteLoading').style.display = 'none';
                    document.getElementById('deleteBtn').disabled = false;

                    showSuccessModal('เกิดข้อผิดพลาดในการเชื่อมต่อกับเซิร์ฟเวอร์', 'error');
                    console.error('Error:', error);
                });
        }

        // Show success modal
        function showSuccessModal(message, type = 'success') {
            document.getElementById('successMessage').textContent = message;

            // Change modal styling based on type
            const modal = document.querySelector('#successModal .modal');
            const header = document.querySelector('#successModal .modal-header');
            const icon = document.querySelector('#successModal .success-icon');

            if (type === 'error') {
                header.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
                icon.style.background = '#fef2f2';
                icon.style.color = '#ef4444';
                icon.textContent = '✗';
            } else {
                header.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                icon.style.background = '#ecfdf5';
                icon.style.color = '#10b981';
                icon.textContent = '✓';
            }

            document.getElementById('successModal').classList.add('active');
        }

        // Close success modal
        function closeSuccessModal() {
            document.getElementById('successModal').classList.remove('active');
            document.body.style.overflow = 'auto';
        }

        // Update type in list
        function updateTypeInList(id, name, icon) {
            const typeItem = document.querySelector(`[data-id="${id}"]`);
            if (typeItem) {
                typeItem.querySelector('.type-icon').textContent = icon;
                typeItem.querySelector('.type-name').textContent = name;

                // ดึงข้อมูลการใช้งานจาก element
                const usageElement = typeItem.querySelector('.type-usage');
                let usageCount = 0,
                    pendingCount = 0,
                    processingCount = 0,
                    completedCount = 0;

                if (usageElement) {
                    const text = usageElement.textContent;
                    const totalMatch = text.match(/ทั้งหมด\s*(\d+)/);
                    const pendingMatch = text.match(/รอรับเรื่อง\s*(\d+)/);
                    const processingMatch = text.match(/กำลังดำเนินการ\s*(\d+)/);
                    const completedMatch = text.match(/เสร็จสิ้น\s*(\d+)/);

                    if (totalMatch) usageCount = parseInt(totalMatch[1]);
                    if (pendingMatch) pendingCount = parseInt(pendingMatch[1]);
                    if (processingMatch) processingCount = parseInt(processingMatch[1]);
                    if (completedMatch) completedCount = parseInt(completedMatch[1]);
                }

                // Update onclick handlers
                const editBtn = typeItem.querySelector('.btn-edit');
                const deleteBtn = typeItem.querySelector('.btn-delete');
                if (editBtn) {
                    editBtn.onclick = () => openEditModal(id, name, icon);
                }
                if (deleteBtn) {
                    deleteBtn.onclick = () => openDeleteModal(id, name, icon, usageCount, pendingCount, processingCount, completedCount);
                }
            }
        }

        // Add new type to list
        function addTypeToList(id, name, icon) {
            const typesList = document.getElementById('typesList');

            // Remove empty state if exists
            const emptyState = typesList.querySelector('.empty-state');
            if (emptyState) {
                emptyState.remove();
            }

            // Create new type item (new items have 0 usage)
            const typeItem = document.createElement('div');
            typeItem.className = 'type-item';
            typeItem.setAttribute('data-id', id);
            typeItem.innerHTML = `
                <div class="type-icon">${icon}</div>
                <div class="type-content">
                    <div class="type-name">${name}</div>
                    <div class="type-id">ID: ${id}</div>
                    <div class="type-usage" style="font-size: 0.8rem; margin-top: 4px;">
                        <span style="color: #9ca3af;">📊 ยังไม่มีการใช้งาน</span>
                    </div>
                </div>
                <div class="type-actions">
                    <button class="btn btn-edit" onclick="openEditModal(${id}, '${name.replace(/'/g, "\\'")}', '${icon}')">
                        ✏️ แก้ไข
                    </button>
                    <button class="btn btn-delete" onclick="openDeleteModal(${id}, '${name.replace(/'/g, "\\'")}', '${icon}', 0, 0, 0, 0)">
                        🗑️ ลบ
                    </button>
                </div>
            `;

            typesList.appendChild(typeItem);
        }

        // Remove type from list
        function removeTypeFromList(id) {
            const typeItem = document.querySelector(`[data-id="${id}"]`);
            if (typeItem) {
                typeItem.style.opacity = '0';
                typeItem.style.transform = 'translateX(-100%)';
                setTimeout(() => {
                    typeItem.remove();

                    // Check if list is empty
                    const typesList = document.getElementById('typesList');
                    if (typesList.children.length === 0) {
                        typesList.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">📂</div>
                                <h3>ไม่มีประเภทข้อร้องเรียน</h3>
                                <p>เริ่มต้นโดยการเพิ่มประเภทข้อร้องเรียนใหม่</p>
                            </div>
                        `;
                    }
                }, 300);
            }
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                if (event.target.id === 'formModal') closeModal();
                if (event.target.id === 'deleteModal') closeDeleteModal();
                if (event.target.id === 'successModal') closeSuccessModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
                closeDeleteModal();
                closeSuccessModal();
            }
        });
    </script>
</body>

</html>