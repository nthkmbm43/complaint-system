<?php
// staff/organization-management.php - หน้าจัดการข้อมูลคณะ/สาขา/แผนก
define('SECURE_ACCESS', true);

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// ตรวจสอบการล็อกอินและสิทธิ์ Admin เท่านั้น
requireLogin();
requireStaffAccess();
requireRole(['teacher']);

$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission < 3) {
    $accessDeniedMessage = "หน้านี้สำหรับผู้ดูแลระบบเท่านั้น เนื่องจากการจัดการข้อมูลองค์กรเป็นสิทธิ์เฉพาะผู้ดูแลระบบ (สิทธิ์ระดับ 3)";
    $accessDeniedRedirect = "index.php";
}

$db = getDB();
$user = getCurrentUser();

$message = '';
$messageType = 'success';

// การเรียงลำดับ
$sortField = $_GET['sort'] ?? 'Unit_id';
$sortOrder = $_GET['order'] ?? 'ASC';
$allowedSortFields = ['Unit_id', 'Unit_name', 'Unit_type', 'parent_name', 'student_count', 'teacher_count', 'child_count'];
$allowedSortOrders = ['ASC', 'DESC'];

// ตรวจสอบความถูกต้องของ sort parameters
if (!in_array($sortField, $allowedSortFields)) {
    $sortField = 'Unit_id';
}
if (!in_array($sortOrder, $allowedSortOrders)) {
    $sortOrder = 'ASC';
}

// ฟังก์ชันสำหรับ validation
function validateUnitData($data, $isEdit = false)
{
    $errors = [];

    // ตรวจสอบชื่อหน่วยงาน
    if (empty(trim($data['unit_name']))) {
        $errors[] = 'กรุณากรอกชื่อหน่วยงาน';
    }

    // ตรวจสอบประเภทหน่วยงาน
    if (!$isEdit && empty($data['unit_type'])) {
        $errors[] = 'กรุณาเลือกประเภทหน่วยงาน';
    }

    // ตรวจสอบ email format
    if (!empty($data['unit_email']) && !filter_var($data['unit_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    // ตรวจสอบเบอร์โทรศัพท์
    if (!empty($data['unit_tel']) && !preg_match('/^[0-9\-\+\(\)\s]+$/', $data['unit_tel'])) {
        $errors[] = 'รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง';
    }

    return $errors;
}

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_unit':
                // เพิ่มหน่วยงานใหม่
                $unitName = trim($_POST['unit_name']);
                $unitType = trim($_POST['unit_type']);
                $unitParentId = !empty($_POST['unit_parent_id']) ? intval($_POST['unit_parent_id']) : null;
                $unitIcon = trim($_POST['unit_icon'] ?? '🏢');
                $unitEmail = trim($_POST['unit_email'] ?? '');
                $unitTel = trim($_POST['unit_tel'] ?? '');

                // Validation
                $validationErrors = validateUnitData($_POST);
                if (!empty($validationErrors)) {
                    throw new Exception(implode(', ', $validationErrors));
                }

                // ตรวจสอบการซ้ำ
                $existing = $db->fetch("SELECT Unit_id FROM organization_unit WHERE Unit_name = ? AND Unit_type = ?", [$unitName, $unitType]);
                if ($existing) {
                    throw new Exception('หน่วยงานนี้มีอยู่ในระบบแล้ว');
                }

                // กฎการเลือก parent ที่แก้ไขใหม่
                if ($unitType === 'faculty') {
                    // คณะไม่ต้องมี parent
                    $unitParentId = null;
                } elseif ($unitType === 'major' || $unitType === 'department') {
                    // สาขาวิชาและแผนกต้องเลือกคณะเป็น parent เสมอ
                    if (!$unitParentId) {
                        $typeLabel = $unitType === 'major' ? 'สาขาวิชา' : 'แผนก';
                        throw new Exception($typeLabel . 'ต้องระบุคณะที่สังกัด');
                    }

                    $parent = $db->fetch("SELECT Unit_type FROM organization_unit WHERE Unit_id = ?", [$unitParentId]);
                    if (!$parent || $parent['Unit_type'] !== 'faculty') {
                        $typeLabel = $unitType === 'major' ? 'สาขาวิชา' : 'แผนก';
                        throw new Exception($typeLabel . 'ต้องสังกัดคณะเท่านั้น');
                    }
                }

                // คำนวณ Unit_id ใหม่ = MAX(Unit_id) + 1
                $maxId = $db->fetch("SELECT MAX(Unit_id) as max_id FROM organization_unit");
                $newUnitId = ($maxId && $maxId['max_id'] !== null) ? (int)$maxId['max_id'] + 1 : 1;

                // เพิ่มข้อมูล
                $db->insert('organization_unit', [
                    'Unit_id'        => $newUnitId,
                    'Unit_name'      => $unitName,
                    'Unit_type'      => $unitType,
                    'Unit_parent_id' => $unitParentId,
                    'Unit_icon'      => $unitIcon,
                    'Unit_email'     => $unitEmail ?: null,
                    'Unit_tel'       => $unitTel ?: null
                ]);

                // ตรวจสอบว่าข้อมูลเข้าจริงโดย SELECT กลับมาเช็ค แทนการเชื่อ return value
                // (lastInsertId() คืน 0 เพราะไม่มี AUTO_INCREMENT ทำให้ PHP แปลเป็น false)
                $inserted = $db->fetch("SELECT Unit_id FROM organization_unit WHERE Unit_id = ?", [$newUnitId]);
                if ($inserted) {
                    $message = "เพิ่มหน่วยงาน '{$unitName}' สำเร็จ";
                    $messageType = 'success';
                } else {
                    throw new Exception('ไม่สามารถเพิ่มหน่วยงานได้');
                }
                break;

            case 'edit_unit':
                // แก้ไขหน่วยงาน
                $unitId = intval($_POST['unit_id']);
                $unitName = trim($_POST['unit_name']);
                $unitType = trim($_POST['unit_type'] ?? '');
                $unitParentId = !empty($_POST['unit_parent_id']) ? intval($_POST['unit_parent_id']) : null;
                $unitIcon = trim($_POST['unit_icon'] ?? '🏢');
                $unitEmail = trim($_POST['unit_email'] ?? '');
                $unitTel = trim($_POST['unit_tel'] ?? '');

                // Validation ชื่อ
                if (empty($unitName)) {
                    throw new Exception('กรุณากรอกชื่อหน่วยงาน');
                }
                if (empty($unitType)) {
                    throw new Exception('กรุณาเลือกประเภทหน่วยงาน');
                }
                if (!empty($unitEmail) && !filter_var($unitEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
                }
                if (!empty($unitTel) && !preg_match('/^[0-9\-\+\(\)\s]+$/', $unitTel)) {
                    throw new Exception('รูปแบบเบอร์โทรศัพท์ไม่ถูกต้อง');
                }

                // ตรวจสอบว่าหน่วยงานมีอยู่จริง
                $existingUnit = $db->fetch("SELECT Unit_name, Unit_type FROM organization_unit WHERE Unit_id = ?", [$unitId]);
                if (!$existingUnit) {
                    throw new Exception('ไม่พบหน่วยงานที่ต้องการแก้ไข');
                }

                // กฎ unit_type และ parent
                if ($unitType === 'faculty') {
                    $unitParentId = null;
                } elseif ($unitType === 'major' || $unitType === 'department') {
                    if (!$unitParentId) {
                        $typeLabel = $unitType === 'major' ? 'สาขาวิชา' : 'แผนก';
                        throw new Exception($typeLabel . 'ต้องระบุคณะที่สังกัด');
                    }
                    $parent = $db->fetch("SELECT Unit_type FROM organization_unit WHERE Unit_id = ?", [$unitParentId]);
                    if (!$parent || $parent['Unit_type'] !== 'faculty') {
                        $typeLabel = $unitType === 'major' ? 'สาขาวิชา' : 'แผนก';
                        throw new Exception($typeLabel . 'ต้องสังกัดคณะเท่านั้น');
                    }
                }

                // ตรวจสอบชื่อซ้ำ (ยกเว้นตัวเอง ในประเภทเดียวกัน)
                $duplicateCheck = $db->fetch(
                    "SELECT Unit_id FROM organization_unit WHERE Unit_name = ? AND Unit_type = ? AND Unit_id != ?",
                    [$unitName, $unitType, $unitId]
                );
                if ($duplicateCheck) {
                    throw new Exception('ชื่อหน่วยงานนี้ซ้ำกับหน่วยงานอื่นในประเภทเดียวกัน');
                }

                // อัปเดตข้อมูล รวม unit_type และ parent
                try {
                    $sql = "UPDATE organization_unit SET 
                        Unit_name = ?, 
                        Unit_type = ?,
                        Unit_parent_id = ?,
                        Unit_icon = ?, 
                        Unit_email = ?, 
                        Unit_tel = ? 
                        WHERE Unit_id = ?";

                    $params = [
                        $unitName,
                        $unitType,
                        $unitParentId,
                        $unitIcon,
                        $unitEmail ?: null,
                        $unitTel ?: null,
                        $unitId
                    ];

                    $stmt = $db->execute($sql, $params);

                    if ($stmt && $stmt->rowCount() > 0) {
                        $message = "แก้ไขหน่วยงาน '{$unitName}' สำเร็จ";
                        $messageType = 'success';
                    } else {
                        throw new Exception('ไม่สามารถแก้ไขหน่วยงานได้ - ไม่มีการเปลี่ยนแปลงข้อมูล');
                    }
                } catch (PDOException $e) {
                    error_log("PDO Error: " . $e->getMessage());
                    throw new Exception('เกิดข้อผิดพลาดในการแก้ไขข้อมูล: ' . $e->getMessage());
                } catch (Exception $e) {
                    error_log("General Error: " . $e->getMessage());
                    throw $e;
                }
                break;

            case 'delete_unit':
                // ลบหน่วยงาน
                $unitId = intval($_POST['unit_id']);

                // ดึงข้อมูลหน่วยงานที่ต้องการลบ
                $unitInfo = $db->fetch("SELECT Unit_name, Unit_type FROM organization_unit WHERE Unit_id = ?", [$unitId]);
                if (!$unitInfo) {
                    throw new Exception('ไม่พบหน่วยงานที่ต้องการลบ');
                }

                // ตรวจสอบหน่วยงานลูก
                $childCount = $db->count('organization_unit', 'Unit_parent_id = ?', [$unitId]);

                // รวบรวม Unit IDs ทั้งหมด (รวมหน่วยงานลูก) สำหรับตรวจสอบบุคลากร
                $allUnitIds = [$unitId];
                if ($childCount > 0) {
                    $childUnits = $db->fetchAll("SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?", [$unitId]);
                    foreach ($childUnits as $child) {
                        $allUnitIds[] = $child['Unit_id'];
                    }
                }
                $unitIdsPlaceholder = implode(',', array_fill(0, count($allUnitIds), '?'));

                // นับนักศึกษาในหน่วยงานนี้โดยตรง
                $directStudentCount = $db->count('student', 'Unit_id = ?', [$unitId]);

                // นับนักศึกษาในหน่วยงานลูก (ถ้ามี)
                $childStudentCount = 0;
                if ($childCount > 0) {
                    $childStudentCount = $db->fetch(
                        "SELECT COUNT(*) as cnt FROM student WHERE Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?)",
                        [$unitId]
                    )['cnt'] ?? 0;
                }
                $totalStudentCount = $directStudentCount + $childStudentCount;

                // นับอาจารย์ในหน่วยงานนี้โดยตรง (Aj_per = 0 หรือ 1)
                $directTeacherCount = $db->fetch(
                    "SELECT COUNT(*) as cnt FROM teacher WHERE Unit_id = ? AND Aj_per IN (0, 1)",
                    [$unitId]
                )['cnt'] ?? 0;

                // นับอาจารย์ในหน่วยงานลูก
                $childTeacherCount = 0;
                if ($childCount > 0) {
                    $childTeacherCount = $db->fetch(
                        "SELECT COUNT(*) as cnt FROM teacher WHERE Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?) AND Aj_per IN (0, 1)",
                        [$unitId]
                    )['cnt'] ?? 0;
                }
                $totalTeacherCount = $directTeacherCount + $childTeacherCount;

                // นับเจ้าหน้าที่/แอดมิน ในหน่วยงานนี้โดยตรง (Aj_per = 2 หรือ 3)
                $directAdminCount = $db->fetch(
                    "SELECT COUNT(*) as cnt FROM teacher WHERE Unit_id = ? AND Aj_per IN (2, 3)",
                    [$unitId]
                )['cnt'] ?? 0;

                // นับเจ้าหน้าที่/แอดมิน ในหน่วยงานลูก
                $childAdminCount = 0;
                if ($childCount > 0) {
                    $childAdminCount = $db->fetch(
                        "SELECT COUNT(*) as cnt FROM teacher WHERE Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?) AND Aj_per IN (2, 3)",
                        [$unitId]
                    )['cnt'] ?? 0;
                }
                $totalAdminCount = $directAdminCount + $childAdminCount;

                // รวมบุคลากรทั้งหมด
                $totalPersonnel = $totalStudentCount + $totalTeacherCount + $totalAdminCount;

                // ตรวจสอบว่ามีหน่วยงานลูก
                if ($childCount > 0) {
                    throw new Exception("ไม่สามารถลบ '{$unitInfo['Unit_name']}' ได้ เนื่องจากมีหน่วยงานลูก {$childCount} หน่วย กรุณาลบหน่วยงานลูกก่อน");
                }

                // ตรวจสอบนักศึกษา
                if ($totalStudentCount > 0) {
                    throw new Exception("ไม่สามารถลบ '{$unitInfo['Unit_name']}' ได้ เนื่องจากมีนักศึกษาสังกัด {$totalStudentCount} คน กรุณาย้ายนักศึกษาไปหน่วยงานอื่นก่อน");
                }

                // ตรวจสอบอาจารย์
                if ($totalTeacherCount > 0) {
                    throw new Exception("ไม่สามารถลบ '{$unitInfo['Unit_name']}' ได้ เนื่องจากมีอาจารย์สังกัด {$totalTeacherCount} คน กรุณาย้ายอาจารย์ไปหน่วยงานอื่นก่อน");
                }

                // ตรวจสอบเจ้าหน้าที่/แอดมิน
                if ($totalAdminCount > 0) {
                    throw new Exception("ไม่สามารถลบ '{$unitInfo['Unit_name']}' ได้ เนื่องจากมีเจ้าหน้าที่/แอดมินสังกัด {$totalAdminCount} คน กรุณาย้ายบุคลากรไปหน่วยงานอื่นก่อน");
                }

                // ลบข้อมูล
                $result = $db->delete('organization_unit', 'Unit_id = ?', [$unitId]);

                if ($result) {
                    $message = "ลบหน่วยงาน '{$unitInfo['Unit_name']}' สำเร็จ";
                    $messageType = 'success';
                } else {
                    throw new Exception('ไม่สามารถลบหน่วยงานได้');
                }
                break;

            case 'add_sample_data':
                // เพิ่มข้อมูลตัวอย่าง
                $sampleData = [
                    ['คณะวิศวกรรมศาสตร์', 'faculty', null, '⚙️'],
                    ['คณะบริหารธุรกิจ', 'faculty', null, '💼'],
                    ['คณะครุศาสตร์อุตสาหกรรม', 'faculty', null, '🎓'],
                    ['วิศวกรรมคอมพิวเตอร์', 'major', 1, '💻'],
                    ['วิศวกรรมไฟฟ้า', 'major', 1, '⚡'],
                    ['การบัญชี', 'major', 2, '📊'],
                    ['การตลาด', 'major', 2, '📈'],
                    ['งานทะเบียนและประมวลผล', 'department', null, '📋'],
                    ['งานกิจการนักศึกษา', 'department', null, '👥']
                ];

                $addedCount = 0;
                foreach ($sampleData as $data) {
                    try {
                        $result = $db->insert('organization_unit', [
                            'Unit_name' => $data[0],
                            'Unit_type' => $data[1],
                            'Unit_parent_id' => $data[2],
                            'Unit_icon' => $data[3]
                        ]);
                        if ($result) $addedCount++;
                    } catch (Exception $e) {
                        // ข้ามถ้าข้อมูลซ้ำ
                        continue;
                    }
                }

                $message = "เพิ่มข้อมูลตัวอย่าง {$addedCount} รายการสำเร็จ";
                $messageType = 'success';
                break;

            default:
                throw new Exception('การดำเนินการไม่ถูกต้อง');
                break;
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

// ดึงข้อมูลสำหรับแสดงผล
try {
    // ตรวจสอบการเชื่อมต่อฐานข้อมูล
    $testQuery = $db->fetchAll("SHOW TABLES LIKE 'organization_unit'");
    if (empty($testQuery)) {
        throw new Exception("ตาราง organization_unit ไม่พบในฐานข้อมูล");
    }

    // สร้าง ORDER BY clause ตาม sortField และ sortOrder
    $orderByClause = "ORDER BY ";

    // สำหรับการเรียงตามชื่อ ให้ใช้ COLLATE สำหรับภาษาไทย
    if ($sortField === 'Unit_name' || $sortField === 'parent_name') {
        $orderByClause .= "{$sortField} COLLATE utf8mb4_unicode_ci {$sortOrder}";
    } else {
        $orderByClause .= "{$sortField} {$sortOrder}";
    }

    // เพิ่ม secondary sort เพื่อความสม่ำเสมอ
    if ($sortField !== 'Unit_id') {
        $orderByClause .= ", Unit_id ASC";
    }

    // ข้อมูลหน่วยงานทั้งหมด พร้อมนับจำนวนนักศึกษา อาจารย์ และแอดมิน
    $sql = "
        SELECT u1.Unit_id, u1.Unit_name, u1.Unit_type, u1.Unit_icon, u1.Unit_parent_id,
            COALESCE(u1.Unit_email, '') as Unit_email,
            COALESCE(u1.Unit_tel, '') as Unit_tel,
            COALESCE(u2.Unit_name, '') as parent_name,
            CASE 
                WHEN u1.Unit_type = 'faculty' THEN
                    COALESCE((
                        SELECT COUNT(DISTINCT s_all.Stu_id) 
                        FROM student s_all 
                        WHERE s_all.Unit_id = u1.Unit_id 
                            OR s_all.Unit_id IN (
                                SELECT child.Unit_id 
                                FROM organization_unit child 
                                WHERE child.Unit_parent_id = u1.Unit_id
                            )
                    ), 0)
                ELSE COUNT(DISTINCT s.Stu_id)
            END as student_count,
            CASE 
                WHEN u1.Unit_type = 'faculty' THEN
                    COALESCE((
                        SELECT COUNT(DISTINCT t_all.Aj_id) 
                        FROM teacher t_all 
                        WHERE (t_all.Unit_id = u1.Unit_id 
                            OR t_all.Unit_id IN (
                                SELECT child.Unit_id 
                                FROM organization_unit child 
                                WHERE child.Unit_parent_id = u1.Unit_id
                            ))
                            AND t_all.Aj_per IN (0, 1)
                    ), 0)
                ELSE (SELECT COUNT(*) FROM teacher t2 WHERE t2.Unit_id = u1.Unit_id AND t2.Aj_per IN (0, 1))
            END as teacher_count,
            CASE 
                WHEN u1.Unit_type = 'faculty' THEN
                    COALESCE((
                        SELECT COUNT(DISTINCT a_all.Aj_id) 
                        FROM teacher a_all 
                        WHERE (a_all.Unit_id = u1.Unit_id 
                            OR a_all.Unit_id IN (
                                SELECT child.Unit_id 
                                FROM organization_unit child 
                                WHERE child.Unit_parent_id = u1.Unit_id
                            ))
                            AND a_all.Aj_per IN (2, 3)
                    ), 0)
                ELSE (SELECT COUNT(*) FROM teacher a2 WHERE a2.Unit_id = u1.Unit_id AND a2.Aj_per IN (2, 3))
            END as admin_count,
            COUNT(DISTINCT u3.Unit_id) as child_count
        FROM organization_unit u1
        LEFT JOIN organization_unit u2 ON u1.Unit_parent_id = u2.Unit_id
        LEFT JOIN student s ON u1.Unit_id = s.Unit_id
        LEFT JOIN organization_unit u3 ON u1.Unit_id = u3.Unit_parent_id
        GROUP BY u1.Unit_id, u1.Unit_name, u1.Unit_type, u1.Unit_icon, u1.Unit_parent_id, u1.Unit_email, u1.Unit_tel, u2.Unit_name
        {$orderByClause}
    ";

    $organizationUnits = $db->fetchAll($sql);

    // รายการคณะสำหรับ dropdown
    $facultiesList = $db->fetchAll("
        SELECT Unit_id, Unit_name, Unit_icon 
        FROM organization_unit 
        WHERE Unit_type = 'faculty' 
        ORDER BY Unit_name COLLATE utf8mb4_unicode_ci
    ");

    // สถิติพื้นฐาน - ปรับปรุงให้แสดงข้อมูลประชากรทั้งหมด
    $totalFaculties = $db->count('organization_unit', 'Unit_type = ?', ['faculty']);
    $totalMajors = $db->count('organization_unit', 'Unit_type = ?', ['major']);
    $totalDepartments = $db->count('organization_unit', 'Unit_type = ?', ['department']);
    $totalUnits = $db->count('organization_unit');
    $totalStudents = $db->count('student');
    $totalTeachers = $db->count('teacher');
    $totalPopulation = $totalStudents + $totalTeachers;

    $stats = [
        'total_faculties' => $totalFaculties,
        'total_majors' => $totalMajors,
        'total_departments' => $totalDepartments,
        'total_units' => $totalUnits,
        'total_students' => $totalStudents,
        'total_teachers' => $totalTeachers,
        'total_population' => $totalPopulation
    ];

    $debugInfo = [
        'total_records' => count($organizationUnits),
        'database_connected' => true,
        'current_sort' => "{$sortField} {$sortOrder}"
    ];
} catch (Exception $e) {
    error_log("Organization data error: " . $e->getMessage());
    $organizationUnits = [];
    $facultiesList = [];
    $stats = [
        'total_faculties' => 0,
        'total_majors' => 0,
        'total_departments' => 0,
        'total_units' => 0,
        'total_students' => 0,
        'total_teachers' => 0,
        'total_population' => 0
    ];
    $debugInfo = [
        'total_records' => 0,
        'database_connected' => false,
        'error_message' => $e->getMessage()
    ];
}

$pageTitle = 'จัดการข้อมูลคณะ/สาขา/แผนก';

// ฟังก์ชันสำหรับสร้าง sort URL
function getSortUrl($field, $currentSortField, $currentSortOrder)
{
    $newOrder = 'ASC';
    if ($field === $currentSortField && $currentSortOrder === 'ASC') {
        $newOrder = 'DESC';
    }
    return "?sort={$field}&order={$newOrder}";
}

// ฟังก์ชันสำหรับแสดงไอคอน sort
function getSortIcon($field, $currentSortField, $currentSortOrder)
{
    if ($field === $currentSortField) {
        return $currentSortOrder === 'ASC' ? '▲' : '▼';
    }
    return '⇅';
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - ระบบข้อร้องเรียน</title>

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
            padding-top: 70px;
        }

        .dashboard-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .dashboard-content {
            max-width: 1600px;
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
            margin-bottom: 20px;
        }

        .welcome-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 5px solid #667eea;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #4a5568;
            font-size: 14px;
            font-weight: 500;
        }

        .population-highlight {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-left: 5px solid #fbbf24;
        }

        .population-highlight .stat-number {
            color: white;
        }

        .population-highlight .stat-label {
            color: #e2e8f0;
        }

        /* Debug Info */
        .debug-info {
            background: rgba(255, 255, 255, 0.9);
            border: 2px solid #fbbf24;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        /* Add Unit Button */
        .add-unit-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 20px 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn-add-unit {
            background: linear-gradient(145deg, #4ade80, #22c55e);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }

        .btn-add-unit:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(34, 197, 94, 0.4);
        }

        .btn-sample-data {
            background: linear-gradient(145deg, #f59e0b, #d97706);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 15px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }

        .btn-sample-data:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }

        .modal-title {
            font-size: 24px;
            font-weight: bold;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            background: #ef4444;
            color: white;
            border: none;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .close:hover {
            background: #dc2626;
            transform: scale(1.1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(145deg, #38a169, #276749);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(56, 161, 105, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(145deg, #dc3545, #c82333);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(145deg, #c82333, #bd2130);
        }

        .btn-warning {
            background: linear-gradient(145deg, #ed8936, #dd6b20);
            color: white;
        }

        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(237, 137, 54, 0.3);
        }

        .btn-danger {
            background: linear-gradient(145deg, #38a169, #276749);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(56, 161, 105, 0.3);
        }

        .btn-delete-row {
            background: linear-gradient(145deg, #e53e3e, #c53030);
            color: white;
        }

        .btn-delete-row:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(229, 62, 62, 0.3);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 14px;
        }

        /* Data Card */
        .data-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .data-table th,
        .data-table td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        .data-table th {
            background: linear-gradient(145deg, #f8fafc, #e2e8f0);
            font-weight: bold;
            color: #4a5568;
            font-size: 14px;
            white-space: nowrap;
        }

        .data-table tr:hover {
            background: #f8fafc;
            transform: translateY(-1px);
            transition: all 0.2s ease;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-faculty {
            background: linear-gradient(145deg, #3b82f6, #2563eb);
            color: white;
        }

        .status-major {
            background: linear-gradient(145deg, #10b981, #059669);
            color: white;
        }

        .status-department {
            background: linear-gradient(145deg, #8b5cf6, #7c3aed);
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        /* Unit name styling with child count */
        .unit-display {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .unit-name {
            font-weight: bold;
            color: #2d3748;
            font-size: 15px;
        }

        .unit-child-count {
            font-size: 12px;
            color: #718096;
            font-style: italic;
        }

        .unit-contact {
            font-size: 12px;
            color: #4a5568;
            margin-top: 3px;
        }

        /* Population display styling */
        .population-display {
            display: flex;
            flex-direction: column;
            gap: 3px;
            text-align: center;
        }

        .population-count {
            font-weight: bold;
            font-size: 16px;
            color: #2d3748;
        }

        .population-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .student-count {
            color: #3b82f6;
        }

        .teacher-count {
            color: #10b981;
        }

        /* Icon Selector */
        .icon-selector {
            margin-bottom: 20px;
        }

        .icon-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(50px, 1fr));
            gap: 10px;
            max-height: 200px;
            overflow-y: auto;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
        }

        .icon-option {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 10px;
            transition: all 0.2s ease;
            background: white;
        }

        .icon-option:hover {
            border-color: #667eea;
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .icon-option.selected {
            border-color: #667eea;
            background: linear-gradient(145deg, #667eea, #764ba2);
            color: white;
            transform: scale(1.1);
        }

        .selected-icon {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: #e0e7ff;
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .selected-icon-display {
            font-size: 24px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border-radius: 8px;
        }

        /* Alert System */
        .alert {
            position: fixed;
            top: 90px;
            right: 20px;
            background: white;
            padding: 20px 25px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            z-index: 9999;
            max-width: 400px;
            border-left: 5px solid #48bb78;
            cursor: pointer;
            transition: all 0.3s ease;
            transform: translateX(100%);
            opacity: 0;
        }

        .alert.show {
            transform: translateX(0);
            opacity: 1;
        }

        .alert.success {
            border-left-color: #48bb78;
        }

        .alert.error {
            border-left-color: #f56565;
        }

        .alert:hover {
            transform: translateX(-5px);
        }

        .alert-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-icon {
            font-size: 24px;
            flex-shrink: 0;
        }

        .alert-text {
            flex: 1;
        }

        .alert-title {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .alert-message {
            color: #666;
            font-size: 14px;
        }

        /* Parent selection rules */
        .parent-rule-info {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            font-size: 14px;
        }

        .parent-rule-info.warning {
            background: #fefce8;
            border-left-color: #eab308;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        /* Sort header styles */
        .sort-header {
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .sort-header:hover {
            background: rgba(102, 126, 234, 0.1);
            border-radius: 5px;
        }

        .sort-icon {
            font-size: 12px;
            color: #718096;
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .data-table {
                font-size: 13px;
            }

            .data-table th,
            .data-table td {
                padding: 12px 8px;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
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
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                gap: 15px;
            }

            .data-table {
                font-size: 12px;
                overflow-x: auto;
            }

            .data-table th,
            .data-table td {
                padding: 8px 6px;
                min-width: 100px;
            }

            .modal-content {
                padding: 20px;
                margin: 10px;
            }

            .icon-grid {
                grid-template-columns: repeat(auto-fill, minmax(40px, 1fr));
            }

            .icon-option {
                width: 40px;
                height: 40px;
                font-size: 20px;
            }

            .add-unit-section {
                flex-direction: column;
            }

            .btn-add-unit,
            .btn-sample-data {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {

            .data-table th,
            .data-table td {
                padding: 6px 4px;
                font-size: 11px;
            }

            .population-count {
                font-size: 14px;
            }

            .population-label {
                font-size: 10px;
            }
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
            <!-- Debug Information -->
            <?php if (empty($organizationUnits) || !$debugInfo['database_connected']): ?>
                <div class="debug-info">
                    <h4>ข้อมูลการดีบัก</h4>
                    <p><strong>การเชื่อมต่อฐานข้อมูล:</strong> <?php echo $debugInfo['database_connected'] ? 'เชื่อมต่อแล้ว' : 'ไม่สามารถเชื่อมต่อได้'; ?></p>
                    <p><strong>จำนวนข้อมูลในตาราง:</strong> <?php echo $debugInfo['total_records']; ?> รายการ</p>
                    <?php if (isset($debugInfo['current_sort'])): ?>
                        <p><strong>การเรียงลำดับปัจจุบัน:</strong> <?php echo htmlspecialchars($debugInfo['current_sort']); ?></p>
                    <?php endif; ?>
                    <?php if (isset($debugInfo['error_message'])): ?>
                        <p><strong>ข้อผิดพลาด:</strong> <?php echo htmlspecialchars($debugInfo['error_message']); ?></p>
                    <?php endif; ?>
                    <?php if (empty($organizationUnits)): ?>
                        <p><strong>คำแนะนำ:</strong> หากไม่มีข้อมูล กรุณาคลิกปุ่ม "เพิ่มข้อมูลตัวอย่าง" เพื่อสร้างข้อมูลเริ่มต้น</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Welcome Card -->
            <div class="welcome-card">
                <div class="welcome-title">
                    จัดการข้อมูลคณะ/สาขา/แผนก
                </div>
                <div class="welcome-subtitle">
                    จัดการโครงสร้างหน่วยงานของสถาบัน | ผู้ดูแล: <?php echo htmlspecialchars($user['Aj_name']); ?>
                </div>
                <div class="welcome-stats">
                    <div class="stat-card population-highlight">
                        <div class="stat-number"><?php echo number_format($stats['total_units'] ?? 0); ?></div>
                        <div class="stat-label">🏛️ หน่วยงานทั้งหมด</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($stats['total_faculties'] ?? 0); ?></div>
                        <div class="stat-label">🎓 คณะทั้งหมด</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($stats['total_majors'] ?? 0); ?></div>
                        <div class="stat-label">📚 สาขาทั้งหมด</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($stats['total_departments'] ?? 0); ?></div>
                        <div class="stat-label">🗂️ แผนกทั้งหมด</div>
                    </div>
                </div>
            </div>

            <!-- Add Unit Button Section -->
            <div class="add-unit-section">
                <button class="btn-add-unit" onclick="openAddModal()">
                    <span>เพิ่มหน่วยงานใหม่</span>
                </button>
                <?php if (empty($organizationUnits)): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="action" value="add_sample_data">
                        <button type="submit" class="btn-sample-data">
                            <span>เพิ่มข้อมูลตัวอย่าง</span>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Organization Units Table -->
            <div class="data-card">
                <div class="modal-title">
                    รายการหน่วยงานทั้งหมด (<?php echo count($organizationUnits); ?> รายการ)
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>
                                    <a href="<?php echo getSortUrl('Unit_id', $sortField, $sortOrder); ?>" class="sort-header">
                                        ID <span class="sort-icon"><?php echo getSortIcon('Unit_id', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>ไอคอน</th>
                                <th>
                                    <a href="<?php echo getSortUrl('Unit_name', $sortField, $sortOrder); ?>" class="sort-header">
                                        ชื่อหน่วยงาน <span class="sort-icon"><?php echo getSortIcon('Unit_name', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('Unit_type', $sortField, $sortOrder); ?>" class="sort-header">
                                        ประเภท <span class="sort-icon"><?php echo getSortIcon('Unit_type', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('parent_name', $sortField, $sortOrder); ?>" class="sort-header">
                                        หน่วยงานแม่ <span class="sort-icon"><?php echo getSortIcon('parent_name', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('student_count', $sortField, $sortOrder); ?>" class="sort-header">
                                        นักศึกษา <span class="sort-icon"><?php echo getSortIcon('student_count', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>
                                    <a href="<?php echo getSortUrl('teacher_count', $sortField, $sortOrder); ?>" class="sort-header">
                                        อาจารย์ <span class="sort-icon"><?php echo getSortIcon('teacher_count', $sortField, $sortOrder); ?></span>
                                    </a>
                                </th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($organizationUnits)): ?>
                                <tr>
                                    <td colspan="8" class="empty-state">
                                        <div class="empty-state-icon">📂</div>
                                        <h3>ไม่พบข้อมูลหน่วยงาน</h3>
                                        <p>กรุณาเพิ่มข้อมูลหน่วยงานหรือเพิ่มข้อมูลตัวอย่าง</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($organizationUnits as $unit): ?>
                                    <tr>
                                        <td><?php echo $unit['Unit_id']; ?></td>
                                        <td style="font-size: 1.5rem;"><?php echo htmlspecialchars($unit['Unit_icon'] ?: '🏢'); ?></td>
                                        <td>
                                            <div class="unit-display">
                                                <div class="unit-name">
                                                    <?php echo htmlspecialchars($unit['Unit_name']); ?>
                                                    <?php if ($unit['child_count'] > 0): ?>
                                                        <span class="unit-child-count">(<?php echo $unit['child_count']; ?> หน่วย)</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($unit['Unit_email']) || !empty($unit['Unit_tel'])): ?>
                                                    <div class="unit-contact">
                                                        <?php if (!empty($unit['Unit_email'])): ?>
                                                            📧 <?php echo htmlspecialchars($unit['Unit_email']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!empty($unit['Unit_tel'])): ?>
                                                            <?php if (!empty($unit['Unit_email'])): ?><br><?php endif; ?>
                                                            📞 <?php echo htmlspecialchars($unit['Unit_tel']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $typeClasses = ['faculty' => 'status-faculty', 'major' => 'status-major', 'department' => 'status-department'];
                                            $typeLabels = ['faculty' => 'คณะ', 'major' => 'สาขา', 'department' => 'แผนก'];
                                            ?>
                                            <span class="status-badge <?php echo $typeClasses[$unit['Unit_type']]; ?>">
                                                <?php echo $typeLabels[$unit['Unit_type']]; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($unit['parent_name'] ?: '-'); ?></td>
                                        <td>
                                            <div class="population-display">
                                                <div class="population-count student-count"><?php echo number_format($unit['student_count']); ?></div>
                                                <div class="population-label">คน</div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="population-display">
                                                <div class="population-count teacher-count"><?php echo number_format($unit['teacher_count']); ?></div>
                                                <div class="population-label">คน</div>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn btn-warning btn-sm" onclick="editUnit(<?php echo $unit['Unit_id']; ?>, '<?php echo addslashes($unit['Unit_name']); ?>', '<?php echo addslashes($unit['Unit_icon']); ?>', '<?php echo addslashes($unit['Unit_email']); ?>', '<?php echo addslashes($unit['Unit_tel']); ?>', '<?php echo $unit['Unit_type']; ?>', <?php echo intval($unit['Unit_parent_id'] ?? 0); ?>)">
                                                แก้ไข
                                            </button>
                                            <button class="btn btn-delete-row btn-sm" onclick="deleteUnit(<?php echo $unit['Unit_id']; ?>, '<?php echo addslashes($unit['Unit_name']); ?>', <?php echo intval($unit['student_count']); ?>, <?php echo intval($unit['teacher_count']); ?>, <?php echo intval($unit['admin_count'] ?? 0); ?>, <?php echo intval($unit['child_count']); ?>)">
                                                ลบ
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Unit Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">เพิ่มหน่วยงานใหม่</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="addUnitForm">
                <input type="hidden" name="action" value="add_unit">
                <input type="hidden" name="unit_icon" id="selectedIcon" value="🏢">

                <!-- Icon Selector -->
                <div class="icon-selector">
                    <label>เลือกไอคอน</label>
                    <div class="selected-icon">
                        <div class="selected-icon-display" id="selectedIconDisplay">🏢</div>
                        <span>ไอคอนที่เลือก (คลิกเพื่อเปลี่ยน)</span>
                    </div>
                    <div class="icon-grid" id="iconGrid">
                        <!-- Icons will be populated by JavaScript -->
                    </div>
                </div>

                <!-- Parent Selection Rules Info -->
                <div class="parent-rule-info" id="parentRuleInfo">
                    <strong>กฎการเลือกหน่วยงานแม่:</strong>
                    <ul style="margin: 10px 0; padding-left: 20px;">
                        <li><strong>คณะ:</strong> ไม่ต้องเลือกหน่วยงานแม่</li>
                        <li><strong>สาขาวิชา:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li>
                        <li><strong>แผนก:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li>
                    </ul>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>ชื่อหน่วยงาน *</label>
                        <input type="text" name="unit_name" class="form-control" required placeholder="เช่น คณะวิศวกรรมศาสตร์">
                    </div>
                    <div class="form-group">
                        <label>ประเภทหน่วยงาน *</label>
                        <select name="unit_type" class="form-control" required id="unitType">
                            <option value="">เลือกประเภท</option>
                            <option value="faculty">🏛️ คณะ</option>
                            <option value="major">📚 สาขาวิชา</option>
                            <option value="department">🏢 แผนก/หน่วยงาน</option>
                        </select>
                    </div>
                    <div class="form-group" id="parentGroup" style="display: none;">
                        <label id="parentLabel">หน่วยงานแม่</label>
                        <select name="unit_parent_id" class="form-control" id="unitParent">
                            <option value="">เลือกคณะ</option>
                            <?php foreach ($facultiesList as $faculty): ?>
                                <option value="<?php echo $faculty['Unit_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['Unit_icon'] . ' ' . $faculty['Unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>อีเมล</label>
                    <input type="email" name="unit_email" class="form-control" placeholder="contact@faculty.ac.th">
                </div>
                <div class="form-group">
                    <label>เบอร์โทร</label>
                    <input type="text" name="unit_tel" class="form-control" placeholder="02-123-4567">
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">แก้ไขหน่วยงาน</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="editUnitForm">
                <input type="hidden" name="action" value="edit_unit">
                <input type="hidden" name="unit_id" id="editUnitId">
                <input type="hidden" name="unit_icon" id="editSelectedIcon" value="🏢">

                <!-- Icon Selector for Edit -->
                <div class="icon-selector">
                    <label>เลือกไอคอน</label>
                    <div class="selected-icon">
                        <div class="selected-icon-display" id="editSelectedIconDisplay">🏢</div>
                        <span>ไอคอนที่เลือก (คลิกเพื่อเปลี่ยน)</span>
                    </div>
                    <div class="icon-grid" id="editIconGrid">
                        <!-- Icons will be populated by JavaScript -->
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>ชื่อหน่วยงาน *</label>
                        <input type="text" name="unit_name" id="editUnitName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>ประเภทหน่วยงาน *</label>
                        <select name="unit_type" class="form-control" required id="editUnitType">
                            <option value="">เลือกประเภท</option>
                            <option value="faculty">🏛️ คณะ</option>
                            <option value="major">📚 สาขาวิชา</option>
                            <option value="department">🏢 แผนก/หน่วยงาน</option>
                        </select>
                    </div>
                    <div class="form-group" id="editParentGroup" style="display: none;">
                        <label id="editParentLabel">คณะที่สังกัด *</label>
                        <select name="unit_parent_id" class="form-control" id="editUnitParent">
                            <option value="">เลือกคณะ</option>
                            <?php foreach ($facultiesList as $faculty): ?>
                                <option value="<?php echo $faculty['Unit_id']; ?>">
                                    <?php echo htmlspecialchars($faculty['Unit_icon'] . ' ' . $faculty['Unit_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>อีเมล</label>
                    <input type="email" name="unit_email" id="editUnitEmail" class="form-control">
                </div>
                <div class="form-group">
                    <label>เบอร์โทร</label>
                    <input type="text" name="unit_tel" id="editUnitTel" class="form-control">
                </div>
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 460px;">
            <div class="modal-header">
                <h2 class="modal-title">ยืนยันการลบ</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <div style="text-align: center; padding: 10px 0 25px;">
                <div style="font-size: 52px; margin-bottom: 15px;">🗑️</div>
                <p style="font-size: 16px; color: #2d3748; margin-bottom: 8px;">คุณต้องการลบหน่วยงาน</p>
                <p style="font-size: 18px; font-weight: bold; color: #667eea; margin-bottom: 16px;">"<span id="deleteUnitName"></span>"</p>
                <p style="font-size: 13px; color: #e53e3e; font-weight: 600;">⚠️ การลบนี้ไม่สามารถยกเลิกได้</p>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_unit">
                <input type="hidden" name="unit_id" id="deleteUnitId">
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()" style="min-width: 120px;">ยกเลิก</button>
                    <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" style="min-width: 120px;">ยืนยันการลบ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cannot Delete Alert Overlay -->
    <div id="cannotDeleteOverlay" style="
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 10000;
        align-items: center;
        justify-content: center;
    ">
        <div style="
            background: white;
            border-radius: 20px;
            padding: 35px 40px;
            max-width: 440px;
            width: 90%;
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            text-align: center;
            animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        ">
            <div style="
                width: 70px; height: 70px;
                background: linear-gradient(135deg, #fed7d7, #feb2b2);
                border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                font-size: 32px;
                margin: 0 auto 20px;
            ">⚠️</div>
            <h3 style="color: #c53030; font-size: 20px; margin-bottom: 12px; font-weight: 700;">ไม่สามารถลบได้</h3>
            <div id="cannotDeleteMessage" style="
                background: #fff5f5;
                border: 1px solid #fed7d7;
                border-radius: 12px;
                padding: 14px 18px;
                color: #742a2a;
                font-size: 14px;
                line-height: 1.7;
                margin-bottom: 25px;
                text-align: left;
            "></div>
            <button onclick="closeCannotDeleteAlert()" style="
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                border-radius: 12px;
                padding: 12px 35px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            " onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(102,126,234,0.4)'"
               onmouseout="this.style.transform='';this.style.boxShadow=''">
                รับทราบ
            </button>
        </div>
    </div>
    <style>
        @keyframes popIn {
            from { transform: scale(0.8); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>

    <!-- JavaScript -->
    <script>
        // Available icons for selection
        const availableIcons = [
            '🏛️', '🏢', '🏠', '🏭', '🏪', '🏬', '🏫', '🏦', '🏤', '🏣',
            '📚', '📖', '📋', '📋', '📊', '📈', '📉', '💼', '💰', '💻',
            '⚙️', '🔧', '🔨', '🛠️', '⚡', '🔌', '💡', '🧪', '🔬', '🧬',
            '🎓', '👨‍🎓', '👩‍🎓', '👨‍🏫', '👩‍🏫', '👥', '👤', '🌍', '🌎', '🗺️',
            '📱', '📟', '📺', '🖥️', '⌨️', '🖱️', '🎮', '📷', '📹', '🎬',
            '🚗', '🚌', '🚚', '🚛', '✈️', '🚁', '⛵', '🚂', '🚇', '🚲',
            '🏥', '⚕️', '💊', '🩺', '🏃', '🤸', '🎯', '🎨', '🎭', '🎪'
        ];

        // Initialize icon grids
        function initializeIconGrids() {
            ['iconGrid', 'editIconGrid'].forEach(gridId => {
                const grid = document.getElementById(gridId);
                if (!grid) return;
                grid.innerHTML = '';
                const modalType = gridId === 'iconGrid' ? 'add' : 'edit';
                availableIcons.forEach(icon => {
                    const div = document.createElement('div');
                    div.className = 'icon-option';
                    div.textContent = icon;
                    div.onclick = () => selectIcon(icon, modalType, div);
                    grid.appendChild(div);
                });
            });
        }

        // Select icon function
        function selectIcon(icon, modalType, clickedElement) {
            if (modalType === 'add') {
                document.getElementById('selectedIcon').value = icon;
                document.getElementById('selectedIconDisplay').textContent = icon;
                document.querySelectorAll('#iconGrid .icon-option').forEach(el => el.classList.remove('selected'));
            } else {
                document.getElementById('editSelectedIcon').value = icon;
                document.getElementById('editSelectedIconDisplay').textContent = icon;
                document.querySelectorAll('#editIconGrid .icon-option').forEach(el => el.classList.remove('selected'));
            }
            clickedElement.classList.add('selected');
        }

        // Enhanced form handling for unit type selection
        function handleUnitTypeChange() {
            const unitTypeSelect = document.getElementById('unitType');
            const parentGroup = document.getElementById('parentGroup');
            const parentSelect = document.getElementById('unitParent');
            const parentLabel = document.getElementById('parentLabel');
            const parentRuleInfo = document.getElementById('parentRuleInfo');

            unitTypeSelect.addEventListener('change', function() {
                const selectedType = this.value;

                // Reset parent selection
                parentSelect.value = '';

                if (selectedType === 'faculty') {
                    // คณะไม่ต้องเลือก parent
                    parentGroup.style.display = 'none';
                    parentSelect.required = false; // สำคัญ: ต้องปิด required เมื่อซ่อน element
                    parentRuleInfo.className = 'parent-rule-info';
                    parentRuleInfo.innerHTML = '<strong>คณะ:</strong> ไม่ต้องเลือกหน่วยงานแม่ เนื่องจากคณะเป็นหน่วยงานระดับสูงสุด';
                } else if (selectedType === 'major') {
                    // สาขาต้องเลือกคณะ
                    parentGroup.style.display = 'block';
                    parentLabel.textContent = 'คณะที่สังกัด *';
                    parentSelect.required = true;
                    parentRuleInfo.className = 'parent-rule-info warning';
                    parentRuleInfo.innerHTML = '<strong>สาขาวิชา:</strong> จำเป็นต้องเลือกคณะที่สังกัด กรุณาเลือกคณะจากรายการด้านล่าง';
                } else if (selectedType === 'department') {
                    // แผนกต้องเลือกคณะ
                    parentGroup.style.display = 'block';
                    parentLabel.textContent = 'คณะที่สังกัด *';
                    parentSelect.required = true;
                    parentRuleInfo.className = 'parent-rule-info warning';
                    parentRuleInfo.innerHTML = '<strong>แผนก:</strong> จำเป็นต้องเลือกคณะที่สังกัด กรุณาเลือกคณะจากรายการด้านล่าง';
                } else {
                    // ไม่ได้เลือกอะไร
                    parentGroup.style.display = 'none';
                    parentSelect.required = false;
                    parentRuleInfo.className = 'parent-rule-info';
                    parentRuleInfo.innerHTML = '<strong>กฎการเลือกหน่วยงานแม่:</strong><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>คณะ:</strong> ไม่ต้องเลือกหน่วยงานแม่</li><li><strong>สาขาวิชา:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li><li><strong>แผนก:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li></ul>';
                }
            });
        }

        // Form Enhancement
        document.addEventListener('DOMContentLoaded', function() {
            handleUnitTypeChange();
            handleEditUnitTypeChange();
            initializeIconGrids();
        });

        // Modal Functions
        function openAddModal() {
            document.getElementById('addUnitForm').reset();
            document.getElementById('selectedIcon').value = '🏢';
            document.getElementById('selectedIconDisplay').textContent = '🏢';

            const parentGroup = document.getElementById('parentGroup');
            const parentSelect = document.getElementById('unitParent');
            const parentRuleInfo = document.getElementById('parentRuleInfo');
            parentGroup.style.display = 'none';
            parentSelect.required = false;
            parentSelect.value = '';
            parentRuleInfo.className = 'parent-rule-info';
            parentRuleInfo.innerHTML = '<strong>กฎการเลือกหน่วยงานแม่:</strong><ul style="margin: 10px 0; padding-left: 20px;"><li><strong>คณะ:</strong> ไม่ต้องเลือกหน่วยงานแม่</li><li><strong>สาขาวิชา:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li><li><strong>แผนก:</strong> ต้องเลือกคณะเป็นหน่วยงานแม่</li></ul>';

            document.getElementById('addModal').classList.add('show');
            document.body.style.overflow = 'hidden';
            initializeIconGrids();
        }

        function editUnit(id, name, icon, email, tel, unitType, parentId) {
            // โยนค่าเข้า edit form
            document.getElementById('editUnitId').value = id;
            document.getElementById('editUnitName').value = name;
            document.getElementById('editUnitEmail').value = email || '';
            document.getElementById('editUnitTel').value = tel || '';

            // ตั้งค่า unit_type
            const editUnitTypeSelect = document.getElementById('editUnitType');
            editUnitTypeSelect.value = unitType;

            // ตั้งค่า parent dropdown ตาม type
            const editParentGroup = document.getElementById('editParentGroup');
            const editUnitParent = document.getElementById('editUnitParent');
            if (unitType === 'major' || unitType === 'department') {
                editParentGroup.style.display = 'block';
                editUnitParent.required = true;
                editUnitParent.value = parentId || '';
            } else {
                editParentGroup.style.display = 'none';
                editUnitParent.required = false;
                editUnitParent.value = '';
            }

            // ตั้งค่า icon
            const currentIcon = icon || '🏢';
            document.getElementById('editSelectedIcon').value = currentIcon;
            document.getElementById('editSelectedIconDisplay').textContent = currentIcon;

            document.getElementById('editModal').classList.add('show');
            document.body.style.overflow = 'hidden';

            initializeIconGrids();
            setTimeout(() => {
                document.querySelectorAll('#editIconGrid .icon-option').forEach(el => {
                    el.classList.toggle('selected', el.textContent === currentIcon);
                });
            }, 100);
        }

        // จัดการการเปลี่ยน unit_type ใน edit form
        function handleEditUnitTypeChange() {
            document.getElementById('editUnitType').addEventListener('change', function() {
                const selectedType = this.value;
                const editParentGroup = document.getElementById('editParentGroup');
                const editUnitParent = document.getElementById('editUnitParent');

                if (selectedType === 'major' || selectedType === 'department') {
                    editParentGroup.style.display = 'block';
                    editUnitParent.required = true;
                    editUnitParent.value = '';
                } else {
                    editParentGroup.style.display = 'none';
                    editUnitParent.required = false;
                    editUnitParent.value = '';
                }
            });
        }

        function deleteUnit(id, name, studentCount, teacherCount, adminCount, childCount) {
            const totalPersonnel = studentCount + teacherCount + adminCount;
            const canDelete = (totalPersonnel === 0 && childCount === 0);

            if (!canDelete) {
                // แสดง alert ไม่สามารถลบได้ (ไม่เปิด modal)
                let details = [];
                if (childCount > 0)   details.push('<span style="font-size:15px;">🏢</span> มีหน่วยงานลูก <strong>' + childCount + ' หน่วย</strong>');
                if (studentCount > 0) details.push('<span style="font-size:15px;">👨‍🎓</span> มีนักศึกษา <strong>' + studentCount + ' คน</strong>');
                if (teacherCount > 0) details.push('<span style="font-size:15px;">👨‍🏫</span> มีอาจารย์ <strong>' + teacherCount + ' คน</strong>');
                if (adminCount > 0)   details.push('<span style="font-size:15px;">👔</span> มีเจ้าหน้าที่/แอดมิน <strong>' + adminCount + ' คน</strong>');

                document.getElementById('cannotDeleteMessage').innerHTML =
                    'กรุณาย้ายบุคลากรหรือลบหน่วยงานลูกก่อนทำการลบ <strong>"' + name + '"</strong><br><br>' +
                    details.map(d => '&nbsp;&nbsp;• ' + d).join('<br>');

                const overlay = document.getElementById('cannotDeleteOverlay');
                overlay.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            } else {
                // เปิด modal ยืนยันการลบ
                document.getElementById('deleteUnitId').value = id;
                document.getElementById('deleteUnitName').textContent = name;
                document.getElementById('deleteModal').classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeCannotDeleteAlert() {
            document.getElementById('cannotDeleteOverlay').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // ปิด cannotDeleteOverlay เมื่อคลิกนอกกรอบ
        document.getElementById('cannotDeleteOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeCannotDeleteAlert();
        });

        function closeModal() {
            document.querySelectorAll('.modal').forEach(modal => modal.classList.remove('show'));
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    closeModal();
                }
            });
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        // Enhanced Alert System
        function showAlert(message, type = 'success', title = null) {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());

            // Create new alert
            const alert = document.createElement('div');
            alert.className = `alert ${type}`;

            const defaultTitles = {
                'success': 'สำเร็จ',
                'error': 'ข้อผิดพลาด',
                'warning': 'คำเตือน',
                'info': 'ข้อมูล'
            };

            const alertTitle = title || defaultTitles[type] || defaultTitles['info'];

            alert.innerHTML = `
                <div class="alert-content">
                    <div class="alert-icon">${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}</div>
                    <div class="alert-text">
                        <div class="alert-title">${alertTitle}</div>
                        <div class="alert-message">${message}</div>
                    </div>
                </div>
            `;

            document.body.appendChild(alert);

            // Show alert with animation
            setTimeout(() => {
                alert.classList.add('show');
            }, 100);

            // Auto hide after 5 seconds
            setTimeout(() => {
                alert.classList.remove('show');
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            }, 5000);

            // Click to dismiss
            alert.addEventListener('click', function() {
                this.classList.remove('show');
                setTimeout(() => {
                    if (this.parentNode) {
                        this.remove();
                    }
                }, 300);
            });
        }

        // Enhanced form validation
        function validateForm(formId) {
            const form = document.getElementById(formId);
            const unitName = form.querySelector('input[name="unit_name"]').value.trim();

            if (!unitName) {
                showAlert('กรุณากรอกชื่อหน่วยงาน', 'error');
                return false;
            }

            // ตรวจสอบ unit_type และ parent (ทั้ง add และ edit form)
            const unitTypeSelect = form.querySelector('select[name="unit_type"]');
            const parentSelect = form.querySelector('select[name="unit_parent_id"]');

            if (unitTypeSelect) {
                const unitType = unitTypeSelect.value;
                if (!unitType) {
                    showAlert('กรุณาเลือกประเภทหน่วยงาน', 'error');
                    return false;
                }
                if ((unitType === 'major' || unitType === 'department') && parentSelect) {
                    if (!parentSelect.value) {
                        const typeLabel = unitType === 'major' ? 'สาขาวิชา' : 'แผนก';
                        showAlert(`${typeLabel}ต้องเลือกคณะที่สังกัด`, 'error');
                        return false;
                    }
                } else if (unitType === 'faculty' && parentSelect) {
                    parentSelect.required = false;
                    parentSelect.value = '';
                }
            }

            return true;
        }

        // Add form submission validation
        document.getElementById('addUnitForm').addEventListener('submit', function(e) {
            if (!validateForm('addUnitForm')) {
                e.preventDefault();
            }
        });

        // Edit form submission validation
        document.getElementById('editUnitForm').addEventListener('submit', function(e) {
            const iconValue = document.getElementById('editSelectedIcon').value;
            if (!iconValue || iconValue.trim() === '') {
                document.getElementById('editSelectedIcon').value = '🏢';
            }
            if (!validateForm('editUnitForm')) {
                e.preventDefault();
            }
        });

        // Show alert from PHP message
        <?php if ($message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showAlert('<?php echo addslashes($message); ?>', '<?php echo $messageType; ?>');
            });
        <?php endif; ?>
    </script>
</body>

</html>