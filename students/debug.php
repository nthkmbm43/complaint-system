<?php
define('SECURE_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// เปิด debug แบบเต็มที่
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Handle AJAX request สำหรับดึงสาขา (ย้ายมาไว้ด้านบนสุด)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_majors') {
    // ปิด output buffering และ error display สำหรับ AJAX
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');

    try {
        requireRole('student', 'login.php');
        $user = getCurrentUser();
        $db = getDB();

        $facultyId = (int)($_GET['faculty_id'] ?? 0);

        if ($facultyId <= 0) {
            echo json_encode([]);
            exit;
        }

        $majors = $db->fetchAll("
            SELECT Unit_id, Unit_name 
            FROM organization_unit 
            WHERE Unit_parent_id = ? AND Unit_type = 'major'
            ORDER BY Unit_name
        ", [$facultyId]);

        echo json_encode($majors, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        error_log("AJAX get_majors error: " . $e->getMessage());
        echo json_encode([]);
        exit;
    }
}

echo "<!DOCTYPE html><html><head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta charset='UTF-8'><title>Debug Profile Update</title>
    <style>
        /* Global Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head><body>";
echo "<h1>🔧 Debug Profile Update Process</h1>";

// ตรวจสอบการล็อกอิน
try {
    requireRole('student', 'login.php');
    $user = getCurrentUser();
    echo "<p style='color: green;'>✓ User login OK: " . htmlspecialchars($user['Stu_name']) . " (" . $user['Stu_id'] . ")</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Login Error: " . $e->getMessage() . "</p>";
    exit;
}

// ตรวจสอบการเชื่อมต่อฐานข้อมูล
try {
    $db = getDB();
    if ($db) {
        echo "<p style='color: green;'>✓ Database connection OK</p>";
    } else {
        echo "<p style='color: red;'>✗ Database connection Failed</p>";
        exit;
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database Error: " . $e->getMessage() . "</p>";
    exit;
}

// ตรวจสอบข้อมูลปัจจุบัน
try {
    $currentData = $db->fetch("
        SELECT s.*, 
               m.Unit_name as major_name, m.Unit_icon as major_icon,
               f.Unit_name as faculty_name, f.Unit_icon as faculty_icon
        FROM student s
        LEFT JOIN organization_unit m ON s.Unit_id = m.Unit_id AND m.Unit_type = 'major'
        LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id AND f.Unit_type = 'faculty'
        WHERE s.Stu_id = ?
    ", [$user['Stu_id']]);

    echo "<h2>📊 ข้อมูลปัจจุบันในฐานข้อมูล:</h2>";
    echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>ฟิลด์</th><th>ค่า</th></tr>";
    echo "<tr><td>รหัสนักศึกษา</td><td>" . htmlspecialchars($currentData['Stu_id'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>ชื่อ-นามสกุล</td><td>" . htmlspecialchars($currentData['Stu_name'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>Unit_id (สาขา)</td><td>" . htmlspecialchars($currentData['Unit_id'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>ชื่อสาขา</td><td>" . htmlspecialchars($currentData['major_name'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>ชื่อคณะ</td><td>" . htmlspecialchars($currentData['faculty_name'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>เบอร์โทร</td><td>" . htmlspecialchars($currentData['Stu_tel'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>อีเมล</td><td>" . htmlspecialchars($currentData['Stu_email'] ?? 'N/A') . "</td></tr>";
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error fetching current data: " . $e->getMessage() . "</p>";
}

// ตรวจสอบคณะและสาขา
try {
    $faculties = $db->fetchAll("SELECT Unit_id, Unit_name FROM organization_unit WHERE Unit_type = 'faculty' ORDER BY Unit_name");
    echo "<h2>🏛️ คณะทั้งหมด (" . count($faculties) . " คณะ):</h2>";
    echo "<ul>";
    foreach ($faculties as $faculty) {
        echo "<li>ID: {$faculty['Unit_id']} - {$faculty['Unit_name']}</li>";
    }
    echo "</ul>";

    // ดึงสาขาของคณะปัจจุบัน
    if (!empty($currentData['Unit_id'])) {
        $currentMajor = $db->fetch("SELECT Unit_parent_id FROM organization_unit WHERE Unit_id = ?", [$currentData['Unit_id']]);
        if ($currentMajor) {
            $majors = $db->fetchAll("SELECT Unit_id, Unit_name FROM organization_unit WHERE Unit_parent_id = ? AND Unit_type = 'major'", [$currentMajor['Unit_parent_id']]);
            echo "<h2>📚 สาขาในคณะปัจจุบัน (" . count($majors) . " สาขา):</h2>";
            echo "<ul>";
            foreach ($majors as $major) {
                $selected = ($major['Unit_id'] == $currentData['Unit_id']) ? " <strong>(ปัจจุบัน)</strong>" : "";
                echo "<li>ID: {$major['Unit_id']} - {$major['Unit_name']}{$selected}</li>";
            }
            echo "</ul>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error fetching faculty/major data: " . $e->getMessage() . "</p>";
}

// ทดสอบการอัพเดต
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div style='border: 2px solid #ff6b6b; padding: 20px; margin: 20px 0; background: #ffe6e6;'>";
    echo "<h2>🔄 กำลังทดสอบการอัพเดต</h2>";

    // แสดงข้อมูลที่ส่งมา
    echo "<h3>📤 ข้อมูลที่ส่งมา:</h3>";
    echo "<pre style='background: #f4f4f4; padding: 10px; border: 1px solid #ddd;'>" . print_r($_POST, true) . "</pre>";

    if (isset($_POST['test_update'])) {
        try {
            // รับข้อมูลจากฟอร์ม
            $fullName = trim($_POST['full_name'] ?? '');
            $facultyId = (int)($_POST['faculty_id'] ?? 0);
            $majorId = (int)($_POST['major_id'] ?? 0);
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            echo "<h3>📝 ข้อมูลที่จะอัพเดต:</h3>";
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><td>ชื่อ-นามสกุล</td><td>{$fullName}</td></tr>";
            echo "<tr><td>คณะ ID</td><td>{$facultyId}</td></tr>";
            echo "<tr><td>สาขา ID</td><td>{$majorId}</td></tr>";
            echo "<tr><td>เบอร์โทร</td><td>{$phone}</td></tr>";
            echo "<tr><td>อีเมล</td><td>{$email}</td></tr>";
            echo "</table>";

            // Basic validation
            if (empty($fullName)) {
                throw new Exception('กรุณากรอกชื่อ-นามสกุล');
            }
            if (empty($facultyId)) {
                throw new Exception('กรุณาเลือกคณะ');
            }
            if (empty($majorId)) {
                throw new Exception('กรุณาเลือกสาขาวิชา');
            }

            echo "<p style='color: green;'>✓ Basic validation passed</p>";

            // ตรวจสอบความสัมพันธ์คณะ-สาขา
            $majorCheck = $db->fetch("
                SELECT m.Unit_id, m.Unit_name, m.Unit_parent_id,
                       f.Unit_name as faculty_name
                FROM organization_unit m
                LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id
                WHERE m.Unit_id = ? AND m.Unit_type = 'major' AND m.Unit_parent_id = ?
            ", [$majorId, $facultyId]);

            if ($majorCheck) {
                echo "<p style='color: green;'>✓ ความสัมพันธ์คณะ-สาขาถูกต้อง</p>";
                echo "<p><strong>สาขา:</strong> {$majorCheck['Unit_name']} <strong>อยู่ในคณะ:</strong> {$majorCheck['faculty_name']}</p>";

                // ตรวจสอบข้อมูลก่อนอัพเดต
                $beforeData = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$user['Stu_id']]);
                echo "<h4>📋 ข้อมูลก่อนอัพเดต:</h4>";
                echo "<pre style='background: #f9f9f9; padding: 10px;'>";
                echo "ชื่อ: " . ($beforeData['Stu_name'] ?? 'N/A') . "\n";
                echo "Unit_id: " . ($beforeData['Unit_id'] ?? 'N/A') . "\n";
                echo "เบอร์: " . ($beforeData['Stu_tel'] ?? 'N/A') . "\n";
                echo "อีเมล: " . ($beforeData['Stu_email'] ?? 'N/A') . "\n";
                echo "</pre>";

                // เริ่มทดสอบการอัพเดต
                $db->beginTransaction();

                $updateSql = "UPDATE student SET 
                             Stu_name = ?, 
                             Unit_id = ?, 
                             Stu_tel = ?, 
                             Stu_email = ?
                             WHERE Stu_id = ?";

                $updateParams = [
                    $fullName,
                    $majorId,
                    !empty($phone) ? $phone : null,
                    !empty($email) ? $email : null,
                    $user['Stu_id']
                ];

                echo "<h4>🔧 SQL ที่จะรัน:</h4>";
                echo "<pre style='background: #e6f3ff; padding: 10px; border: 1px solid #0066cc;'>{$updateSql}</pre>";
                echo "<h4>📊 Parameters:</h4>";
                echo "<pre style='background: #e6f3ff; padding: 10px; border: 1px solid #0066cc;'>" . print_r($updateParams, true) . "</pre>";

                $stmt = $db->execute($updateSql, $updateParams);
                $rowsAffected = $stmt->rowCount();

                echo "<h4 style='color: blue;'>📈 ผลการอัพเดต: Rows affected = {$rowsAffected}</h4>";

                if ($rowsAffected > 0) {
                    echo "<p style='color: green; font-size: 18px; font-weight: bold;'>🎉 อัพเดตสำเร็จ!</p>";

                    // ตรวจสอบข้อมูลหลังอัพเดต
                    $afterData = $db->fetch("SELECT * FROM student WHERE Stu_id = ?", [$user['Stu_id']]);
                    echo "<h4>📋 ข้อมูลหลังอัพเดต:</h4>";
                    echo "<pre style='background: #e6ffe6; padding: 10px; border: 1px solid #00cc00;'>";
                    echo "ชื่อ: " . ($afterData['Stu_name'] ?? 'N/A') . "\n";
                    echo "Unit_id: " . ($afterData['Unit_id'] ?? 'N/A') . "\n";
                    echo "เบอร์: " . ($afterData['Stu_tel'] ?? 'N/A') . "\n";
                    echo "อีเมล: " . ($afterData['Stu_email'] ?? 'N/A') . "\n";
                    echo "</pre>";

                    // แสดงการเปลี่ยนแปลง
                    echo "<h4>🔄 การเปลี่ยนแปลง:</h4>";
                    $changes = [];
                    if ($beforeData['Stu_name'] !== $afterData['Stu_name']) {
                        $changes[] = "ชื่อ: '{$beforeData['Stu_name']}' → '{$afterData['Stu_name']}'";
                    }
                    if ($beforeData['Unit_id'] !== $afterData['Unit_id']) {
                        $changes[] = "Unit_id: '{$beforeData['Unit_id']}' → '{$afterData['Unit_id']}'";
                    }
                    if ($beforeData['Stu_tel'] !== $afterData['Stu_tel']) {
                        $changes[] = "เบอร์: '{$beforeData['Stu_tel']}' → '{$afterData['Stu_tel']}'";
                    }
                    if ($beforeData['Stu_email'] !== $afterData['Stu_email']) {
                        $changes[] = "อีเมล: '{$beforeData['Stu_email']}' → '{$afterData['Stu_email']}'";
                    }

                    if (!empty($changes)) {
                        echo "<ul style='color: green;'>";
                        foreach ($changes as $change) {
                            echo "<li>{$change}</li>";
                        }
                        echo "</ul>";
                    } else {
                        echo "<p style='color: orange;'>⚠️ ไม่พบการเปลี่ยนแปลงในข้อมูล (ข้อมูลที่ส่งมาเหมือนกับข้อมูลเดิม)</p>";
                    }

                    $db->commit();
                } else {
                    echo "<p style='color: orange; font-size: 16px;'>⚠️ ไม่มีการเปลี่ยนแปลงข้อมูล (Rows affected = 0)</p>";
                    echo "<p>อาจเป็นเพราะ:</p>";
                    echo "<ul>";
                    echo "<li>ข้อมูลที่ส่งมาเหมือนกับข้อมูลเดิมทุกฟิลด์</li>";
                    echo "<li>เงื่อนไข WHERE ไม่ตรงกับข้อมูลในฐานข้อมูล</li>";
                    echo "<li>มีปัญหาอื่นๆ ในฐานข้อมูล</li>";
                    echo "</ul>";
                    $db->rollback();
                }
            } else {
                echo "<p style='color: red;'>✗ ความสัมพันธ์คณะ-สาขาไม่ถูกต้อง</p>";

                // ตรวจสอบข้อมูลที่มีอยู่จริง
                $actualMajor = $db->fetch("SELECT * FROM organization_unit WHERE Unit_id = ?", [$majorId]);
                $actualFaculty = $db->fetch("SELECT * FROM organization_unit WHERE Unit_id = ?", [$facultyId]);

                echo "<h4>🔍 ข้อมูลสาขาที่เลือก (ID: {$majorId}):</h4>";
                if ($actualMajor) {
                    echo "<p>ชื่อ: {$actualMajor['Unit_name']}, Parent ID: {$actualMajor['Unit_parent_id']}</p>";
                } else {
                    echo "<p style='color: red;'>ไม่พบสาขา ID: {$majorId}</p>";
                }

                echo "<h4>🔍 ข้อมูลคณะที่เลือก (ID: {$facultyId}):</h4>";
                if ($actualFaculty) {
                    echo "<p>ชื่อ: {$actualFaculty['Unit_name']}</p>";
                } else {
                    echo "<p style='color: red;'>ไม่พบคณะ ID: {$facultyId}</p>";
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            echo "<p style='color: red; font-size: 16px;'>💥 Error during update: " . $e->getMessage() . "</p>";
            echo "<details>";
            echo "<summary>📋 Stack trace (คลิกเพื่อดู)</summary>";
            echo "<pre style='background: #ffe6e6; padding: 10px;'>" . $e->getTraceAsString() . "</pre>";
            echo "</details>";
        }
    }
    echo "</div>";
}

// ฟอร์มทดสอบ
?>
<div style="border: 2px solid #007cba; padding: 20px; margin: 20px 0; background: #f0f8ff;">
    <h2>🧪 ทดสอบการอัพเดต</h2>
    <form method="POST" style="background: #ffffff; padding: 20px; border: 1px solid #ddd;">
        <h3>✏️ แก้ไขข้อมูล:</h3>

        <p>
            <label><strong>ชื่อ-นามสกุล:</strong></label><br>
            <input type="text" name="full_name" value="<?php echo htmlspecialchars($currentData['Stu_name'] ?? ''); ?>" required style="width: 100%; max-width: 400px; padding: 8px; font-size: 14px;">
        </p>

        <p>
            <label><strong>คณะ:</strong></label><br>
            <select name="faculty_id" id="faculty_select" required style="width: 100%; max-width: 400px; padding: 8px; font-size: 14px;" onchange="loadMajors()">
                <option value="">เลือกคณะ</option>
                <?php foreach ($faculties as $faculty): ?>
                    <option value="<?php echo $faculty['Unit_id']; ?>">
                        <?php echo htmlspecialchars($faculty['Unit_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label><strong>สาขา:</strong></label><br>
            <select name="major_id" id="major_select" required style="width: 100%; max-width: 400px; padding: 8px; font-size: 14px;">
                <option value="">เลือกสาขา</option>
            </select>
        </p>

        <p>
            <label><strong>เบอร์โทร:</strong></label><br>
            <input type="tel" name="phone" value="<?php echo htmlspecialchars($currentData['Stu_tel'] ?? ''); ?>" style="width: 100%; max-width: 400px; padding: 8px; font-size: 14px;" placeholder="10 หลัก">
        </p>

        <p>
            <label><strong>อีเมล:</strong></label><br>
            <input type="email" name="email" value="<?php echo htmlspecialchars($currentData['Stu_email'] ?? ''); ?>" style="width: 100%; max-width: 400px; padding: 8px; font-size: 14px;">
        </p>

        <p>
            <button type="submit" name="test_update" style="background: #007cba; color: white; padding: 12px 25px; border: none; cursor: pointer; font-size: 16px; border-radius: 5px;">
                🚀 ทดสอบการอัพเดต
            </button>
        </p>
    </form>
</div>

<script>
    // เพิ่ม debug information
    console.log('Debug page loaded');

    function loadMajors() {
        const facultySelect = document.getElementById('faculty_select');
        const majorSelect = document.getElementById('major_select');
        const facultyId = facultySelect.value;

        console.log('🔄 Loading majors for faculty:', facultyId);

        majorSelect.innerHTML = '<option value="">กำลังโหลด...</option>';

        if (!facultyId) {
            majorSelect.innerHTML = '<option value="">เลือกสาขา</option>';
            return;
        }

        // ใช้ AJAX endpoint ที่อยู่ในไฟล์เดียวกัน
        const url = `?ajax=get_majors&faculty_id=${facultyId}`;
        console.log('🌐 Fetching URL:', url);

        fetch(url)
            .then(response => {
                console.log('📡 Response status:', response.status);
                console.log('📋 Response headers:', response.headers.get('content-type'));

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                return response.text(); // ใช้ text() ก่อนเพื่อดู response
            })
            .then(text => {
                console.log('📄 Raw response:', text);

                try {
                    const majors = JSON.parse(text);
                    console.log('📚 Parsed majors:', majors);

                    majorSelect.innerHTML = '<option value="">เลือกสาขา</option>';

                    if (majors && majors.length > 0) {
                        majors.forEach(major => {
                            const option = document.createElement('option');
                            option.value = major.Unit_id;
                            option.textContent = major.Unit_name;
                            majorSelect.appendChild(option);
                        });
                        console.log(`✅ ${majors.length} majors loaded successfully`);
                    } else {
                        majorSelect.innerHTML = '<option value="">ไม่พบสาขาในคณะนี้</option>';
                        console.log('⚠️ No majors found for faculty');
                    }
                } catch (parseError) {
                    console.error('❌ JSON parse error:', parseError);
                    console.log('📄 Raw text that failed to parse:', text);
                    majorSelect.innerHTML = '<option value="">เกิดข้อผิดพลาดในการแปลงข้อมูล</option>';
                }
            })
            .catch(error => {
                console.error('💥 Fetch error:', error);
                majorSelect.innerHTML = '<option value="">เกิดข้อผิดพลาดในการโหลด</option>';
            });
    }

    // เลือกคณะปัจจุบันอัตโนมัติ
    <?php if (!empty($currentData['Unit_id'])): ?>
        <?php
        $currentMajor = $db->fetch("SELECT Unit_parent_id FROM organization_unit WHERE Unit_id = ?", [$currentData['Unit_id']]);
        if ($currentMajor): ?>
            console.log('🎯 Setting current faculty:', <?php echo $currentMajor['Unit_parent_id']; ?>);
            document.getElementById('faculty_select').value = '<?php echo $currentMajor['Unit_parent_id']; ?>';
            loadMajors();
            setTimeout(() => {
                console.log('🎯 Setting current major:', <?php echo $currentData['Unit_id']; ?>);
                document.getElementById('major_select').value = '<?php echo $currentData['Unit_id']; ?>';
            }, 1000);
        <?php endif; ?>
    <?php endif; ?>
</script>

<?php
echo "</div></body></html>";
?>