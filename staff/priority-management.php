<?php
// staff/priority-management.php
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
if ($userPermission < 2) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดำเนินการและผู้ดูแลระบบเท่านั้น (สิทธิ์ระดับ 2 ขึ้นไป)";
    $accessDeniedRedirect = "index.php";
}

$db = getDB();
$user = getCurrentUser();

// ------------------------------------------------------------------
// กำหนด scope ตาม unit_type ของผู้ใช้ (permission 2)
//   - faculty    → แสดง นศ. ทุกสาขาในคณะ
//   - major      → แสดง นศ. เฉพาะสาขานั้นโดยตรง
//   - department → แสดง นศ. เฉพาะแผนก/หน่วยงานนั้นโดยตรง
// ------------------------------------------------------------------
$isAdmin         = ($userPermission >= 3);
$sessionUnitId   = $_SESSION['unit_id']   ?? null;
$sessionUnitType = $_SESSION['unit_type'] ?? '';

// พารามิเตอร์สำหรับการกรองและค้นหา
$currentPage = max(1, intval($_GET['page'] ?? 1));
$itemsPerPage = 20;
$offset = ($currentPage - 1) * $itemsPerPage;

// กำหนดค่าเริ่มต้นตัวกรอง
$currentPriorityFilter = isset($_GET['current_priority']) ? $_GET['current_priority'] : '0';

$filters = [
    'type' => $_GET['type'] ?? '',
    'current_priority' => $currentPriorityFilter,
    'search' => trim($_GET['search'] ?? ''),
];

$message = '';
$messageType = 'success';

function getPriorityTextLocal($level)
{
    $levels = [
        '0' => 'รอจัดระดับ',
        '1' => 'ไม่เร่งด่วน',
        '2' => 'ปกติ',
        '3' => 'เร่งด่วน',
        '4' => 'เร่งด่วนมาก',
        '5' => 'วิกฤต/ฉุกเฉิน'
    ];
    return $levels[$level] ?? 'ไม่ระบุ';
}

// --- ส่วนจัดการบันทึกข้อมูล (PHP Action) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        // กรณี: เปลี่ยนความสำคัญรายรายการ
        if ($_POST['action'] === 'update_priority') {
            $requestId = intval($_POST['request_id']);
            $newPriority = $_POST['priority'];

            if (!in_array($newPriority, ['1', '2', '3', '4', '5'])) {
                throw new Exception('ระดับความสำคัญไม่ถูกต้อง');
            }

            // [แก้ไขจุดที่ 1] ลบ updated_at ออก
            $sql = "UPDATE request SET Re_level = ? WHERE Re_id = ?";
            $params = [$newPriority, $requestId];

            $updated = $db->execute($sql, $params);

            if ($updated) {
                $db->insert('save_request', [
                    'Re_id' => $requestId,
                    'Aj_id' => $_SESSION['teacher_id'],
                    'Sv_infor' => "เปลี่ยนระดับความสำคัญเป็น: " . getPriorityTextLocal($newPriority),
                    'Sv_type' => 'process',
                    'Sv_date' => date('Y-m-d'),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $message = 'บันทึกระดับความสำคัญเรียบร้อยแล้ว';
            }
        }

        // กรณี: เปลี่ยนความสำคัญแบบกลุ่ม (Bulk Update)
        if ($_POST['action'] === 'bulk_update') {
            $requestIds = explode(',', $_POST['request_ids'] ?? '');
            $bulkPriority = $_POST['bulk_priority'];

            if (empty($requestIds) || !in_array($bulkPriority, ['1', '2', '3', '4', '5'])) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }

            $updateCount = 0;
            // [แก้ไขจุดที่ 2] ลบ updated_at ออก
            $sql = "UPDATE request SET Re_level = ? WHERE Re_id = ?";

            foreach ($requestIds as $requestId) {
                $requestId = intval($requestId);
                if ($requestId > 0) {
                    $params = [$bulkPriority, $requestId];
                    if ($db->execute($sql, $params)) {
                        $updateCount++;
                        $db->insert('save_request', [
                            'Re_id' => $requestId,
                            'Aj_id' => $_SESSION['teacher_id'],
                            'Sv_infor' => "เปลี่ยนระดับความสำคัญเป็น: " . getPriorityTextLocal($bulkPriority) . " (Bulk)",
                            'Sv_type' => 'process',
                            'Sv_date' => date('Y-m-d'),
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                }
            }

            if ($updateCount > 0) {
                $message = "อัปเดตระดับความสำคัญสำเร็จ $updateCount รายการ";
            }
        }
    } catch (Exception $e) {
        $message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// --- ส่วนดึงข้อมูล (Query) ---
try {
    // สร้าง scope condition สำหรับใช้ร่วมกันทั้งสถิติและ main query
    $statScope  = "";
    $statParams = [];
    if (!$isAdmin && $sessionUnitId) {
        if ($sessionUnitType === 'faculty') {
            // สังกัดคณะ → แสดง นศ. ทุกสาขาในคณะ
            $statScope  = "AND r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2
                INNER JOIN organization_unit ou2 ON s2.Unit_id = ou2.Unit_id
                WHERE ou2.Unit_id = ? OR ou2.Unit_parent_id = ?
            )";
            $statParams = [$sessionUnitId, $sessionUnitId];
        } else {
            // สังกัดสาขา (major) หรือแผนก (department) → scope ตรงๆ
            $statScope  = "AND r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2 WHERE s2.Unit_id = ?
            )";
            $statParams = [$sessionUnitId];
        }
    }

    // สถิติ
    if (!$isAdmin && $sessionUnitId) {
        $stats = [
            'inbox'     => $db->fetch("SELECT COUNT(*) as c FROM request r WHERE Re_level = '0' AND Re_status = '1' $statScope", $statParams)['c'] ?? 0,
            'processed' => $db->fetch("SELECT COUNT(*) as c FROM request r WHERE (Re_level != '0' OR Re_status = '2') $statScope", $statParams)['c'] ?? 0,
            'urgent'    => $db->fetch("SELECT COUNT(*) as c FROM request r WHERE Re_level IN ('4','5') $statScope", $statParams)['c'] ?? 0,
        ];
    } else {
        $stats = [
            'inbox'     => $db->count('request', "Re_level = '0' AND Re_status = '1'"),
            'processed' => $db->count('request', "(Re_level != '0' OR Re_status = '2')"),
            'urgent'    => $db->count('request', "Re_level IN ('4', '5')")
        ];
    }

    // ส่วนดึงข้อมูล (Query)
    $whereConditions = ["r.Re_status != '0'"];
    $params = [];

    // กรองตามสถานะการจัดระดับ
    if ($filters['current_priority'] === '0') {
        $whereConditions[] = "r.Re_level = '0'";
        $whereConditions[] = "r.Re_status = '1'";
    } elseif ($filters['current_priority'] === 'done') {
        $whereConditions[] = "(r.Re_level != '0' OR r.Re_status = '2')";
    } elseif ($filters['current_priority'] !== 'all') {
        $whereConditions[] = "r.Re_level = ?";
        $params[] = $filters['current_priority'];
    }

    // --- Scope: กรองตาม unit_type ของผู้ใช้ (ยกเว้น admin) ---
    $scopeJoin = '';
    if (!$isAdmin && $sessionUnitId) {
        if ($sessionUnitType === 'faculty') {
            // สังกัดคณะ → แสดง นศ. ทุกสาขาในคณะ
            $whereConditions[] = "r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2
                INNER JOIN organization_unit ou2 ON s2.Unit_id = ou2.Unit_id
                WHERE ou2.Unit_id = ? OR ou2.Unit_parent_id = ?
            )";
            $params[] = $sessionUnitId;
            $params[] = $sessionUnitId;
        } else {
            // สังกัดสาขา (major) หรือแผนก (department) → scope ตรงๆ
            $whereConditions[] = "r.Stu_id IN (
                SELECT s2.Stu_id FROM student s2 WHERE s2.Unit_id = ?
            )";
            $params[] = $sessionUnitId;
        }
    }

    // กรองอื่นๆ
    if (!empty($filters['type'])) {
        $whereConditions[] = "r.Type_id = ?";
        $params[] = $filters['type'];
    }
    if (!empty($filters['search'])) {
        $whereConditions[] = "(r.Re_title LIKE ? OR r.Re_infor LIKE ? OR s.Stu_name LIKE ?)";
        $searchTerm = '%' . $filters['search'] . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    // นับจำนวนทั้งหมด
    $countSql = "SELECT COUNT(*) as total FROM request r LEFT JOIN student s ON r.Stu_id = s.Stu_id $whereClause";
    $totalResult = $db->fetch($countSql, $params);
    $totalComplaints = $totalResult['total'];

    // ดึงข้อมูล
    $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                   CASE r.Re_iden WHEN 1 THEN 'ไม่ระบุตัวตน' ELSE s.Stu_name END as requester_name
            FROM request r 
            LEFT JOIN type t ON r.Type_id = t.Type_id 
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            $whereClause
            ORDER BY r.Re_status ASC, r.Re_date ASC 
            LIMIT ? OFFSET ?";

    $params[] = $itemsPerPage;
    $params[] = $offset;
    $complaints = $db->fetchAll($sql, $params);
    $complaintTypes = $db->fetchAll("SELECT * FROM type ORDER BY Type_infor");
} catch (Exception $e) {
    $message = "Error: " . $e->getMessage();
    $messageType = 'error';
    $complaints = [];
}

// --- ฟังก์ชันแสดงป้ายสถานะ (ปรับปรุงใหม่ตาม requirement) ---
function getStatusBadge($status)
{
    // กำหนดค่าสี ข้อความ และไอคอน ตามที่คุณต้องการ
    $badges = [
        '0' => ['class' => 'secondary', 'text' => 'ยื่นคำร้อง',       'icon' => '📝'], // สีเทา
        '1' => ['class' => 'info',      'text' => 'กำลังดำเนินการ',   'icon' => '⏳'], // สีฟ้า
        '2' => ['class' => 'warning',   'text' => 'รอการประเมินผล', 'icon' => '⭐'], // สีเหลือง
        '3' => ['class' => 'success',   'text' => 'เสร็จสิ้น',        'icon' => '✅'], // สีเขียว
        '4' => ['class' => 'danger',    'text' => 'ปฏิเสธคำร้อง',     'icon' => '❌']  // สีแดง
    ];

    // ตรวจสอบว่ามี status นี้หรือไม่ ถ้าไม่มีให้ใช้ค่า Default (secondary)
    $b = $badges[$status] ?? ['class' => 'secondary', 'text' => 'ไม่ทราบสถานะ', 'icon' => '❓'];

    return "<span class=\"badge {$b['class']}\">{$b['icon']} {$b['text']}</span>";
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>คัดกรองความสำคัญ</title>
    <link rel="stylesheet" href="../assets/css/staff.css">
    <style>
        .priority-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        .stat-card.inbox {
            border-bottom: 4px solid #ffc107;
        }

        .complaints-table {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
        }

        .table td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        /* --- Badge Styles (ปรับปรุงตามสีที่ต้องการ) --- */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* 0: Secondary (เทา) - ยื่นคำร้อง */
        .badge.secondary {
            background: #e2e3e5;
            color: #383d41;
        }

        /* 1: Info (ฟ้า) - กำลังดำเนินการ */
        .badge.info {
            background: #cff4fc;
            color: #055160;
        }

        /* 2: Warning (เหลือง) - รอการประเมินผล */
        .badge.warning {
            background: #fff3cd;
            color: #856404;
        }

        /* 3: Success (เขียว) - เสร็จสิ้น */
        .badge.success {
            background: #d4edda;
            color: #155724;
        }

        /* 4: Danger (แดง) - ปฏิเสธคำร้อง */
        .badge.danger {
            background: #f8d7da;
            color: #721c24;
        }

        .btn-update {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
        }

        .priority-select {
            width: 100%;
            padding: 8px;
            border-radius: 6px;
            border: 1px solid #ced4da;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .priority-select:hover {
            border-color: #667eea;
        }

        .level-0 {
            border-left: 5px solid #6c757d;
        }

        .level-1 {
            border-left: 5px solid #28a745;
            color: #155724;
            background-color: #f0fff4;
        }

        .level-2 {
            border-left: 5px solid #ffc107;
            color: #856404;
            background-color: #fffbf0;
        }

        .level-3 {
            border-left: 5px solid #fd7e14;
            color: #854004;
            background-color: #fff4e6;
        }

        .level-4 {
            border-left: 5px solid #dc3545;
            color: #721c24;
            background-color: #f8d7da;
        }

        .level-5 {
            border-left: 5px solid #6f42c1;
            color: #440f7d;
            background-color: #f3e5f5;
        }

        .pagination-container {
            text-align: center;
            margin-top: 20px;
            padding: 20px;
            background: white;
            border-radius: 15px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            margin: 0 2px;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
        }

        .page-link.active {
            background: #007bff;
            color: white;
        }
    </style>
</head>

<body>
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
    <div class="main-content">
        <?php include '../includes/header.php'; ?>
        <div class="page-content">

            <div class="priority-header">
                <div style="font-size: 2rem; font-weight: bold;">🎯 คัดกรองความสำคัญ</div>
                <div>จัดการระดับความสำคัญ (Priority) ของข้อร้องเรียน</div>
                <div style="margin-top:10px;">
                <?php if (!empty($_SESSION['is_admin'])): ?>
                    <span style="background:rgba(255,255,255,0.25); border-radius:20px; padding:5px 14px; font-size:0.85rem;">
                        👑 ผู้ดูแลระบบ — แสดงทุกหน่วยงาน
                    </span>
                <?php elseif (!empty($_SESSION['unit_name'])): ?>
                    <span style="background:rgba(255,255,255,0.25); border-radius:20px; padding:5px 14px; font-size:0.85rem;">
                        <?php echo htmlspecialchars($_SESSION['unit_icon'] ?? '🏢'); ?>
                        <?php echo htmlspecialchars($_SESSION['unit_type_thai'] ?? ''); ?>:
                        <strong><?php echo htmlspecialchars($_SESSION['unit_name']); ?></strong>
                        และหน่วยงานย่อย
                    </span>
                <?php endif; ?>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card inbox">
                    <div style="font-size: 2.5rem; font-weight: bold;"><?php echo number_format($stats['inbox']); ?></div>
                    <div>รอจัดระดับ</div>
                </div>
                <div class="stat-card">
                    <div style="font-size: 2.5rem; font-weight: bold; color: #28a745;"><?php echo number_format($stats['processed']); ?></div>
                    <div>จัดระดับแล้ว</div>
                </div>
                <div class="stat-card">
                    <div style="font-size: 2.5rem; font-weight: bold; color: #dc3545;"><?php echo number_format($stats['urgent']); ?></div>
                    <div>เร่งด่วน/วิกฤต</div>
                </div>
            </div>

            <?php if ($message): ?>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        showToast(
                            <?php echo json_encode($message); ?>,
                            <?php echo json_encode($messageType); ?>
                        );
                    });
                </script>
            <?php endif; ?>

            <div style="background: white; padding: 20px; border-radius: 15px; margin-bottom: 25px;">
                <form method="GET">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div>
                            <label>มุมมอง:</label>
                            <select name="current_priority" class="priority-select" onchange="this.form.submit()">
                                <option value="0" <?php echo $filters['current_priority'] === '0' ? 'selected' : ''; ?>>⏳ เฉพาะงานค้าง (รอจัดระดับ)</option>
                                <option value="done" <?php echo $filters['current_priority'] === 'done' ? 'selected' : ''; ?>>✅ จัดระดับแล้ว</option>
                                <option value="all" <?php echo $filters['current_priority'] === 'all' ? 'selected' : ''; ?>>📂 ดูทั้งหมด</option>
                            </select>
                        </div>
                        <div>
                            <label>ค้นหา:</label>
                            <input type="text" name="search" class="priority-select" placeholder="พิมพ์ค้นหา..." value="<?php echo htmlspecialchars($filters['search']); ?>">
                        </div>
                        <div style="display: flex; align-items: end;">
                            <button type="submit" class="btn-update" style="width: 100%;">ค้นหา</button>
                        </div>
                    </div>
                </form>
            </div>

            <div id="bulkActions" style="display:none; background:#e3f2fd; padding:15px; margin-bottom:20px; border-radius:8px;">
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="request_ids" id="selectedIds">
                    <strong>⚡ เลือกหลายรายการ: </strong>
                    <select name="bulk_priority" required style="padding:5px; border-radius:4px;">
                        <option value="">-- เปลี่ยนทั้งหมดเป็น --</option>
                        <option value="1">🟢 1 - ไม่เร่งด่วน</option>
                        <option value="2">🟡 2 - ปกติ</option>
                        <option value="3">🟠 3 - เร่งด่วน</option>
                        <option value="4">🔴 4 - เร่งด่วนมาก</option>
                        <option value="5">🟣 5 - วิกฤต/ฉุกเฉิน</option>
                    </select>
                    <button type="submit" class="btn-update" onclick="return confirm('ยืนยันการอัพเดตทั้งหมด?')">บันทึก</button>
                </form>
            </div>

            <div class="complaints-table">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                <th>รหัส</th>
                                <th>วันที่</th>
                                <th>ประเภท</th>
                                <th>เรื่องร้องเรียน</th>
                                <th>สถานะ</th>
                                <th width="220">ระดับความสำคัญ (Re_level)</th>
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($complaints)): ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding: 40px;">ไม่พบข้อมูล</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($complaints as $c): ?>
                                    <tr>
                                        <td>
                                            <?php if ($c['Re_status'] != '2'): ?>
                                                <input type="checkbox" class="complaint-checkbox" value="<?php echo $c['Re_id']; ?>" onchange="updateSelection()">
                                            <?php endif; ?>
                                        </td>
                                        <td>#<?php echo $c['Re_id']; ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($c['Re_date'])); ?></td>
                                        <td>
                                            <span style="font-size:1.2rem;"><?php echo $c['Type_icon']; ?></span>
                                            <span style="font-size:0.82rem; color:#555; display:block; margin-top:2px;"><?php echo htmlspecialchars($c['Type_infor']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars(mb_strimwidth($c['Re_title'], 0, 40, '...')); ?></strong><br>
                                            <small style="color:#666;"><?php echo htmlspecialchars(mb_strimwidth($c['Re_infor'], 0, 60, '...')); ?></small>
                                        </td>
                                        <td><?php echo getStatusBadge($c['Re_status']); ?></td>
                                        <td>
                                            <?php if ($c['Re_status'] != '2'): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_priority">
                                                    <input type="hidden" name="request_id" value="<?php echo $c['Re_id']; ?>">
                                                    <select name="priority" class="priority-select level-<?php echo $c['Re_level']; ?>">
                                                        <option value="0" disabled <?php echo ($c['Re_level'] == '0') ? 'selected' : ''; ?>>⏳ รอจัดระดับ</option>
                                                        <option value="1" <?php echo ($c['Re_level'] == '1') ? 'selected' : ''; ?>>🟢 1 - ไม่เร่งด่วน</option>
                                                        <option value="2" <?php echo ($c['Re_level'] == '2') ? 'selected' : ''; ?>>🟡 2 - ปกติ</option>
                                                        <option value="3" <?php echo ($c['Re_level'] == '3') ? 'selected' : ''; ?>>🟠 3 - เร่งด่วน</option>
                                                        <option value="4" <?php echo ($c['Re_level'] == '4') ? 'selected' : ''; ?>>🔴 4 - เร่งด่วนมาก</option>
                                                        <option value="5" <?php echo ($c['Re_level'] == '5') ? 'selected' : ''; ?>>🟣 5 - วิกฤต/ฉุกเฉิน</option>
                                                    </select>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge secondary">ปิดงานแล้ว (Level <?php echo $c['Re_level']; ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="complaint-detail.php?id=<?php echo $c['Re_id']; ?>" target="_blank" class="btn-update" style="background:#17a2b8; text-decoration:none;">ดูรายละเอียด</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($totalComplaints > 0): ?>
                <div class="pagination-container">
                    <?php
                    $queryParams = $_GET;
                    unset($queryParams['page']);
                    $baseUrl = '?' . http_build_query($queryParams);
                    $totalPages = ceil($totalComplaints / $itemsPerPage);
                    for ($i = 1; $i <= $totalPages; $i++) {
                        $active = ($i == $currentPage) ? 'active' : '';
                        echo "<a href='{$baseUrl}&page={$i}' class='page-link {$active}'>{$i}</a>";
                    }
                    ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- ===== MODAL OVERLAY ===== -->
    <div id="swal-overlay" style="
        display:none; position:fixed; inset:0; z-index:10000;
        background:rgba(30,30,60,0.45); backdrop-filter:blur(3px);
        align-items:center; justify-content:center;
    ">
        <div id="swal-box" style="
            background:#fff; border-radius:22px; padding:40px 36px 32px;
            width:100%; max-width:400px; text-align:center;
            box-shadow:0 24px 64px rgba(0,0,0,0.18);
            transform:scale(0.85); opacity:0;
            transition: transform 0.32s cubic-bezier(.22,1,.36,1), opacity 0.25s ease;
            position:relative;
        ">
            <div id="swal-icon-wrap" style="margin-bottom:20px;"></div>
            <div id="swal-title"   style="font-size:1.3rem; font-weight:800; color:#1a1a2e; margin-bottom:8px;"></div>
            <div id="swal-sub"     style="font-size:0.9rem; color:#666; line-height:1.55; margin-bottom:10px;"></div>
            <div id="swal-reason-box" style="
                display:none; background:#fff5f5; border-left:4px solid #e53e3e;
                border-radius:8px; padding:12px 14px; margin:14px 0 4px;
                text-align:left; font-size:0.85rem; color:#c0392b; line-height:1.5;
            "></div>
            <div id="swal-buttons" style="display:flex; gap:12px; justify-content:center; margin-top:24px;"></div>
        </div>
    </div>

    <style>
        /* ---- icon animations ---- */
        @keyframes popIn       { 0%{transform:scale(0) rotate(-15deg);opacity:0} 70%{transform:scale(1.15) rotate(3deg)} 100%{transform:scale(1) rotate(0);opacity:1} }
        @keyframes checkDraw   { 0%{stroke-dashoffset:80} 100%{stroke-dashoffset:0} }
        @keyframes ringPulse   { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.18);opacity:.6} }
        @keyframes shakeBounce { 0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)} 40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)} }
        @keyframes questionBob { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }

        .swal-btn {
            padding:11px 28px; border-radius:50px; font-size:.95rem;
            font-weight:700; border:none; cursor:pointer;
            transition:transform .15s, box-shadow .15s;
        }
        .swal-btn:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.15); }
        .swal-btn:active{ transform:translateY(0); }
        .swal-btn-confirm { background:linear-gradient(135deg,#43e97b,#38f9d7); color:#1a6640; }
        .swal-btn-cancel  { background:linear-gradient(135deg,#ff6b6b,#ee0979); color:#fff; }
        .swal-btn-ok-success { background:linear-gradient(135deg,#43e97b,#38f9d7); color:#1a6640; }
        .swal-btn-ok-error   { background:linear-gradient(135deg,#ff6b6b,#ee0979); color:#fff; }
    </style>

    <script>
    /* ============================================================
       MODAL ENGINE
    ============================================================ */
    const SwalOverlay = document.getElementById('swal-overlay');
    const SwalBox     = document.getElementById('swal-box');

    function _openModal() {
        SwalOverlay.style.display = 'flex';
        requestAnimationFrame(() => {
            SwalBox.style.transform = 'scale(1)';
            SwalBox.style.opacity   = '1';
        });
    }
    function _closeModal() {
        SwalBox.style.transform = 'scale(0.85)';
        SwalBox.style.opacity   = '0';
        setTimeout(() => { SwalOverlay.style.display = 'none'; }, 280);
    }

    /* ---- icon builders ---- */
    function _iconSuccess() {
        return `<div style="animation:popIn .5s ease forwards; display:inline-flex; align-items:center; justify-content:center;
                    width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#43e97b,#38f9d7);
                    box-shadow:0 8px 24px rgba(67,233,123,.4);">
                  <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path d="M10 21 L17 28 L30 14" stroke="white" stroke-width="3.5"
                          stroke-linecap="round" stroke-linejoin="round"
                          stroke-dasharray="80" stroke-dashoffset="80"
                          style="animation:checkDraw .45s .3s ease forwards"/>
                  </svg>
                </div>`;
    }
    function _iconError() {
        return `<div style="animation:popIn .4s ease forwards,shakeBounce .5s .4s ease; display:inline-flex; align-items:center; justify-content:center;
                    width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#ff6b6b,#ee0979);
                    box-shadow:0 8px 24px rgba(238,9,121,.35);">
                  <svg width="36" height="36" viewBox="0 0 36 36" fill="none">
                    <line x1="10" y1="10" x2="26" y2="26" stroke="white" stroke-width="3.5" stroke-linecap="round"/>
                    <line x1="26" y1="10" x2="10" y2="26" stroke="white" stroke-width="3.5" stroke-linecap="round"/>
                  </svg>
                </div>`;
    }
    function _iconConfirm() {
        return `<div style="position:relative; display:inline-block;">
                  <div style="animation:ringPulse 1.6s ease infinite; position:absolute; inset:-8px; border-radius:50%;
                              background:rgba(102,126,234,.15);"></div>
                  <div style="animation:popIn .4s ease forwards; display:inline-flex; align-items:center; justify-content:center;
                              width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);
                              box-shadow:0 8px 24px rgba(102,126,234,.4); position:relative;">
                    <span style="font-size:2rem; animation:questionBob 1.4s ease infinite;">❓</span>
                  </div>
                </div>`;
    }

    /* ---- public API ---- */
    function showConfirm(title, sub, onConfirm) {
        document.getElementById('swal-icon-wrap').innerHTML = _iconConfirm();
        document.getElementById('swal-title').textContent   = title;
        document.getElementById('swal-sub').textContent     = sub;
        document.getElementById('swal-reason-box').style.display = 'none';
        document.getElementById('swal-buttons').innerHTML = `
            <button class="swal-btn swal-btn-cancel"  onclick="_closeModal()">ยกเลิก</button>
            <button class="swal-btn swal-btn-confirm" id="confirmOkBtn">ยืนยัน</button>`;
        document.getElementById('confirmOkBtn').onclick = () => { _closeModal(); setTimeout(onConfirm, 200); };
        _openModal();
    }

    function showResult(message, type) {
        const isOk = (type === 'success');
        document.getElementById('swal-icon-wrap').innerHTML = isOk ? _iconSuccess() : _iconError();
        document.getElementById('swal-title').textContent   = isOk ? 'บันทึกสำเร็จ!' : 'เกิดข้อผิดพลาด';
        document.getElementById('swal-sub').textContent     = '';

        const reasonBox = document.getElementById('swal-reason-box');
        reasonBox.style.display = 'block';
        reasonBox.innerHTML = `<strong>♦ ผลลัพธ์:</strong> ${message}`;
        if (isOk) {
            reasonBox.style.background   = '#f0fff4';
            reasonBox.style.borderColor  = '#38a169';
            reasonBox.style.color        = '#276749';
        } else {
            reasonBox.style.background   = '#fff5f5';
            reasonBox.style.borderColor  = '#e53e3e';
            reasonBox.style.color        = '#c0392b';
        }

        const btnClass = isOk ? 'swal-btn-ok-success' : 'swal-btn-ok-error';
        document.getElementById('swal-buttons').innerHTML =
            `<button class="swal-btn ${btnClass}" onclick="_closeModal()">รับทราบ</button>`;
        _openModal();
    }

    /* ============================================================
       INTERCEPT PRIORITY SELECT  (single update)
    ============================================================ */
    document.addEventListener('change', function(e) {
        const sel = e.target;
        if (!sel.classList.contains('priority-select') || !sel.closest('form[method="POST"]')) return;
        const form = sel.closest('form');
        if (!form.querySelector('input[name="action"][value="update_priority"]')) return;

        const levelMap = {'1':'ไม่เร่งด่วน','2':'ปกติ','3':'เร่งด่วน','4':'เร่งด่วนมาก','5':'วิกฤต/ฉุกเฉิน'};
        const chosen = levelMap[sel.value] || sel.value;
        const id = form.querySelector('input[name="request_id"]')?.value ?? '';

        showConfirm(
            'ยืนยันการเปลี่ยนระดับ',
            `เปลี่ยนคำร้อง #${id} เป็น "${chosen}" ใช่หรือไม่?`,
            () => form.submit()
        );
    });

    /* ============================================================
       INTERCEPT BULK UPDATE
    ============================================================ */
    document.addEventListener('submit', function(e) {
        const form = e.target;
        const actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || actionInput.value !== 'bulk_update') return;

        e.preventDefault();
        const sel = form.querySelector('select[name="bulk_priority"]');
        if (!sel || !sel.value) { alert('กรุณาเลือกระดับความสำคัญ'); return; }
        const levelMap = {'1':'ไม่เร่งด่วน','2':'ปกติ','3':'เร่งด่วน','4':'เร่งด่วนมาก','5':'วิกฤต/ฉุกเฉิน'};
        const ids = document.getElementById('selectedIds').value.split(',').filter(Boolean);
        const chosen = levelMap[sel.value] || sel.value;

        showConfirm(
            'ยืนยันการอัปเดตกลุ่ม',
            `เปลี่ยน ${ids.length} รายการ เป็น "${chosen}" ใช่หรือไม่?`,
            () => form.submit()
        );
    });

    /* ============================================================
       SHOW RESULT AFTER PHP POST (triggered by PHP variable)
    ============================================================ */
    function showToast(message, type) { showResult(message, type); }

    /* ============================================================
       SELECT ALL / SELECTION HELPERS
    ============================================================ */
    function toggleSelectAll() {
        const checked = document.getElementById('selectAll').checked;
        document.querySelectorAll('.complaint-checkbox').forEach(cb => cb.checked = checked);
        updateSelection();
    }
    function updateSelection() {
        const selected = Array.from(document.querySelectorAll('.complaint-checkbox:checked')).map(cb => cb.value);
        document.getElementById('selectedIds').value = selected.join(',');
        document.getElementById('bulkActions').style.display = selected.length > 0 ? 'block' : 'none';
    }
    </script>
</body>

</html>