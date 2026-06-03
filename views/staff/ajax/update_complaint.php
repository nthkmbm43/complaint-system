<?php
// staff/ajax/update_complaint.php
// เวอร์ชันสมบูรณ์: Accept=1, Reject=4, Hardcoded Gmail + SSL Bypass

// 1. ป้องกัน Error พ่นใส่หน้าเว็บ (เก็บลง Log แทน) เพื่อไม่ให้ JSON พัง
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

ob_start(); // เริ่มเก็บ Output

// ดักจับ Error ร้ายแรง
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'System Error: ' . $error['message']]);
        exit;
    }
});

try {
    define('SECURE_ACCESS', true);

    // หา Path หลักของโปรเจค เพื่อให้หาไฟล์เจอแน่นอน 100%
    $rootPath = dirname(dirname(dirname(__DIR__)));

    $filesToLoad = [
        $rootPath . '/config/config.php',
        $rootPath . '/config/database.php',
        $rootPath . '/models/Auth.php',
        $rootPath . '/core/functions.php'
    ];

    foreach ($filesToLoad as $filePath) {
        if (!file_exists($filePath)) throw new Exception("หาไฟล์ไม่เจอ: " . basename($filePath));
        require_once $filePath;
    }

    requireLogin();
    requireRole(['teacher']);

    header('Content-Type: application/json; charset=utf-8');

    $db = getDB();
    if (!$db) throw new Exception("เชื่อมต่อฐานข้อมูลไม่ได้");

    $user = getCurrentUser();

    // =========================================================
    // 🔍 ส่วนหา ID อาจารย์ (แก้ปัญหา Aj_id is null)
    // =========================================================
    $teacherId = null;
    if (!empty($user['Aj_id'])) $teacherId = $user['Aj_id'];
    elseif (!empty($user['id'])) $teacherId = $user['id'];
    elseif (!empty($_SESSION['Aj_id'])) $teacherId = $_SESSION['Aj_id'];

    // ถ้ายังไม่เจอ ให้ค้นจาก DB ด้วยอีเมล
    if (!$teacherId && !empty($user['email'])) {
        $t = $db->fetch("SELECT Aj_id FROM teacher WHERE Aj_email = ?", [$user['email']]);
        if ($t) $teacherId = $t['Aj_id'];
    }

    if (!$teacherId) throw new Exception("ไม่พบรหัสเจ้าหน้าที่ (Aj_id) กรุณาล็อกอินใหม่");

    $operatorName = $user['name'] ?? $user['Aj_name'] ?? 'เจ้าหน้าที่';
    $action = $_POST['action'] ?? '';
    $complaintId = (int)($_POST['complaint_id'] ?? 0);

    if (!$complaintId) throw new Exception('ไม่พบรหัสข้อร้องเรียน');

    // ดึงข้อมูลข้อร้องเรียน
    $complaint = $db->fetch(
        "SELECT r.*, s.Stu_name, s.Stu_email FROM request r 
         LEFT JOIN student s ON r.Stu_id = s.Stu_id 
         WHERE r.Re_id = ?",
        [$complaintId]
    );

    if (!$complaint) throw new Exception('ไม่พบข้อมูลข้อร้องเรียน');

    $db->beginTransaction();

    switch ($action) {
        // ============================================================
        // กรณี: ปฏิเสธ (Status = 4)
        // ============================================================
        case 'reject_complaint':
            $reason = trim($_POST['reason'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $sendEmail = ($_POST['send_email'] ?? '0') === '1';

            if (empty($reason)) throw new Exception('กรุณาระบุเหตุผล');

            // ✅ 1. อัปเดตสถานะเป็น 4 (ตามที่ขอ)
            $db->execute("UPDATE request SET Re_status = '4' WHERE Re_id = ?", [$complaintId]);

            // 2. บันทึกประวัติ - หา MAX ID + 1 เพื่อป้องกันปัญหา AUTO_INCREMENT
            $fullNote = $reason . ($note ? "\nคำชี้แนะ: $note" : "") . "\nดำเนินการโดย: $operatorName";
            $maxSaveId = $db->fetch("SELECT COALESCE(MAX(Sv_id), 0) + 1 as new_id FROM save_request");
            $newSaveId = $maxSaveId['new_id'];
            $db->execute(
                "INSERT INTO save_request (Sv_id, Sv_infor, Sv_type, Sv_note, Sv_date, Re_id, Aj_id) 
                 VALUES (?, ?, 'reject', ?, CURDATE(), ?, ?)",
                [$newSaveId, 'ปฏิเสธข้อร้องเรียน', $fullNote, $complaintId, $teacherId]
            );

            // 3. ส่งอีเมล - ส่งทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
            $emailSent = false;
            $emailMsg = '';

            if ($sendEmail && !empty($complaint['Stu_email'])) {
                // ส่ง $rootPath และ Re_iden เพื่อให้ Helper ปรับเนื้อหาตามการระบุตัวตน
                $res = sendRejectEmail($complaint, $reason, $note, $operatorName, $rootPath);
                $emailSent = $res['success'];
                if (!$emailSent) $emailMsg = ' (แต่ส่งเมลไม่สำเร็จ: ' . $res['error'] . ')';
                else $emailMsg = ' (แจ้งทางอีเมลแล้ว)';
            } elseif ($sendEmail && empty($complaint['Stu_email'])) {
                $emailMsg = ' (ไม่พบอีเมลนักศึกษา)';
            }

            // 4. แจ้งเตือนในระบบ - หา MAX ID + 1 เพื่อป้องกันปัญหา AUTO_INCREMENT
            if (!empty($complaint['Stu_id'])) {
                $maxNotiId = $db->fetch("SELECT COALESCE(MAX(Noti_id), 0) + 1 as new_id FROM notification");
                $newNotiId = $maxNotiId['new_id'];
                $db->execute(
                    "INSERT INTO notification (Noti_id, Noti_title, Noti_message, Noti_type, Re_id, Stu_id, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $newNotiId,
                        'ข้อร้องเรียนของคุณถูกปฏิเสธ',
                        "เหตุผล: $reason",
                        $emailSent ? 'both' : 'system',
                        $complaintId,
                        $complaint['Stu_id'],
                        $teacherId
                    ]
                );
            }

            $db->commit();
            $response = ['success' => true, 'message' => "ปฏิเสธเรียบร้อย" . $emailMsg];
            break;

        // ============================================================
        // กรณี: รับเรื่อง (Status = 1)
        // ============================================================
        case 'accept_complaint':
            $sendEmail = ($_POST['send_email'] ?? '0') === '1';

            // ✅ 1. อัปเดตสถานะเป็น 1 (ตามที่ขอ)
            $db->execute("UPDATE request SET Re_status = '1' WHERE Re_id = ?", [$complaintId]);

            // 2. บันทึกประวัติ - หา MAX ID + 1 เพื่อป้องกันปัญหา AUTO_INCREMENT
            $maxSaveId = $db->fetch("SELECT COALESCE(MAX(Sv_id), 0) + 1 as new_id FROM save_request");
            $newSaveId = $maxSaveId['new_id'];
            $db->execute(
                "INSERT INTO save_request (Sv_id, Sv_infor, Sv_type, Sv_note, Sv_date, Re_id, Aj_id) 
                 VALUES (?, ?, 'receive', ?, CURDATE(), ?, ?)",
                [$newSaveId, 'ยืนยันข้อร้องเรียน', 'รับเรื่องโดย ' . $operatorName, $complaintId, $teacherId]
            );

            // 3. ส่งอีเมล - ส่งทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
            $emailSent = false;
            $emailMsg = '';
            if ($sendEmail && !empty($complaint['Stu_email'])) {
                $res = sendAcceptEmail($complaint, $operatorName, $rootPath);
                $emailSent = $res['success'];
                if ($emailSent) $emailMsg = ' (แจ้งทางอีเมลแล้ว)';
                else $emailMsg = ' (แต่ส่งเมลไม่สำเร็จ: ' . $res['error'] . ')';
            } elseif ($sendEmail && empty($complaint['Stu_email'])) {
                $emailMsg = ' (ไม่พบอีเมลนักศึกษา)';
            }

            // 4. แจ้งเตือนในระบบ - หา MAX ID + 1 เพื่อป้องกันปัญหา AUTO_INCREMENT
            if (!empty($complaint['Stu_id'])) {
                $maxNotiId = $db->fetch("SELECT COALESCE(MAX(Noti_id), 0) + 1 as new_id FROM notification");
                $newNotiId = $maxNotiId['new_id'];
                $db->execute(
                    "INSERT INTO notification (Noti_id, Noti_title, Noti_message, Noti_type, Re_id, Stu_id, created_by) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $newNotiId,
                        'รับเรื่องแล้ว',
                        "กำลังดำเนินการแก้ไข",
                        $emailSent ? 'both' : 'system',
                        $complaintId,
                        $complaint['Stu_id'],
                        $teacherId
                    ]
                );
            }

            $db->commit();
            $response = ['success' => true, 'message' => 'รับเรื่องเรียบร้อย' . $emailMsg];
            break;

        default:
            throw new Exception('Action not found');
    }
} catch (Exception $e) {
    if (isset($db)) try {
        $db->rollback();
    } catch (Exception $ex) {
    }
    $response = ['success' => false, 'message' => $e->getMessage()];
}

ob_end_clean();
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

// ============================================================
// ฟังก์ชันส่งอีเมล (ฝังค่า Config + ปลดล็อค SSL)
// ============================================================

function sendAcceptEmail($c, $sender, $rootPath)
{
    // ปรับเนื้อหาตามการระบุตัวตน
    $isAnonymous = ($c['Re_iden'] == 1);
    $greeting = $isAnonymous ? "เรียน ผู้ร้องเรียน," : "เรียนคุณ {$c['Stu_name']},";
    $senderInfo = $isAnonymous ? "เจ้าหน้าที่รับผิดชอบ" : $sender;
    $loginLink = defined('SITE_URL') ? SITE_URL . 'views/students/login.php' : 'http://localhost/complaint/views/students/login.php';
    
    $body = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>" .
            "<div style='text-align: center; margin-bottom: 20px;'>" .
            "<h2>ระบบจัดการข้อร้องเรียน</h2>" .
            "</div>" .
            "<p>$greeting</p>" .
            "<p>ทางเราขอแจ้งให้ทราบว่า <strong>ข้อร้องเรียนของคุณได้รับการยืนยันและรับเรื่องเข้าสู่ระบบแล้ว</strong></p>" .
            "<div style='background:#ecfdf5; padding:20px; border-radius:10px; border-left:4px solid #10b981; margin: 20px 0;'>" .
            "<h3 style='color:#059669; margin:0 0 10px 0;'>✅ รายละเอียดข้อร้องเรียน</h3>" .
            "<p style='margin:0 0 8px 0; color:#374151;'>หัวข้อ: <strong>" . htmlspecialchars($c['Re_title'] ?? 'ไม่ระบุหัวข้อ') . "</strong></p>" .
            "<p style='margin:0; color:#374151;'>รหัสอ้างอิง: <strong>#{$c['Re_id']}</strong></p>" .
            "</div>" .
            "<p>ขณะนี้เจ้าหน้าที่กำลังเร่งดำเนินการตรวจสอบและแก้ไขปัญหาตามข้อร้องเรียนที่คุณได้แจ้งมา คุณสามารถติดตามความคืบหน้าของข้อร้องเรียนนี้ได้อย่างใกล้ชิดผ่านทางระบบของเรา</p>" .
            "<div style='text-align: center; margin: 30px 0;'>" .
            "<a href='$loginLink' style='background-color: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>เข้าสู่ระบบเพื่อติดตามสถานะ</a>" .
            "</div>" .
            "<p><strong>ผู้ดำเนินการ:</strong> $senderInfo</p>" .
            "<hr style='border:none; border-top:1px solid #e5e7eb; margin:30px 0;'>" .
            "<p style='color:#6b7280; font-size: 12px; text-align: center;'>อีเมลฉบับนี้ถูกส่งจากระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้<br>หากมีข้อสงสัยเพิ่มเติม สามารถติดต่อสอบถามได้ที่สำนักงานคณะ</p>" .
            "</div>";
    
    return sendEmailHelper($c['Stu_email'], "✅ อัปเดตสถานะ: รับเรื่องข้อร้องเรียน #{$c['Re_id']} แล้ว", $body, $rootPath);
}

function sendRejectEmail($c, $reason, $note, $sender, $rootPath)
{
    // ปรับเนื้อหาตามการระบุตัวตน
    $isAnonymous = ($c['Re_iden'] == 1);
    $greeting = $isAnonymous ? "เรียน ผู้ร้องเรียน," : "เรียนคุณ {$c['Stu_name']},";
    $senderInfo = $isAnonymous ? "เจ้าหน้าที่รับผิดชอบ" : $sender;
    $loginLink = defined('SITE_URL') ? SITE_URL . 'views/students/login.php' : 'http://localhost/complaint/views/students/login.php';
    
    $body = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>" .
            "<div style='text-align: center; margin-bottom: 20px;'>" .
            "<h2>ระบบจัดการข้อร้องเรียน</h2>" .
            "</div>" .
            "<p>$greeting</p>" .
            "<p>ทางเราขอเรียนให้ทราบว่า ข้อร้องเรียนของคุณ <strong>ไม่สามารถดำเนินการต่อได้ในขณะนี้</strong> เนื่องจากพบประเด็นที่ต้องได้รับการชี้แจงเพิ่มเติมหรืออยู่นอกเหนือเงื่อนไขการรับเรื่อง</p>" .
            "<div style='background:#fef2f2; padding:20px; border-radius:10px; border-left:4px solid #ef4444; margin: 20px 0;'>" .
            "<h3 style='color:#dc2626; margin:0 0 10px 0;'>❌ รายละเอียดการปฏิเสธข้อร้องเรียน</h3>" .
            "<p style='margin:0 0 8px 0; color:#374151;'>หัวข้อ: <strong>" . htmlspecialchars($c['Re_title'] ?? 'ไม่ระบุหัวข้อ') . "</strong></p>" .
            "<p style='margin:0 0 15px 0; color:#374151;'>รหัสอ้างอิง: <strong>#{$c['Re_id']}</strong></p>" .
            "<p style='margin:0 0 8px 0; color:#dc2626;'><strong>สาเหตุหลัก:</strong> " . htmlspecialchars($reason) . "</p>";
    
    if ($note) {
        $body .= "<p style='margin:0; color:#374151;'><strong>คำชี้แนะจากเจ้าหน้าที่:</strong> " . htmlspecialchars($note) . "</p>";
    }
    
    $body .= "</div>" .
             "<p>คุณสามารถเข้าสู่ระบบเพื่อตรวจสอบรายละเอียดเพิ่มเติม หรือยื่นเรื่องใหม่หากคุณมีข้อมูลหรือหลักฐานประกอบที่ครบถ้วน</p>" .
             "<div style='text-align: center; margin: 30px 0;'>" .
             "<a href='$loginLink' style='background-color: #ef4444; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; display: inline-block;'>เข้าสู่ระบบเพื่อตรวจสอบข้อมูล</a>" .
             "</div>" .
             "<p><strong>ผู้ดำเนินการ:</strong> $senderInfo</p>" .
             "<hr style='border:none; border-top:1px solid #e5e7eb; margin:30px 0;'>" .
             "<p style='color:#6b7280; font-size: 12px; text-align: center;'>อีเมลฉบับนี้ถูกส่งจากระบบอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้<br>หากมีข้อสงสัยเพิ่มเติม สามารถติดต่อสอบถามได้ที่สำนักงานคณะ</p>" .
             "</div>";
    
    return sendEmailHelper($c['Stu_email'], "❌ แจ้งผลการพิจารณา: ข้อร้องเรียน #{$c['Re_id']}", $body, $rootPath);
}

function sendEmailHelper($to, $subject, $body, $rootPath)
{
    $res = ['success' => false, 'error' => ''];
    // ใช้ Path ที่แน่นอนจากที่ส่งมา
    $path = $rootPath . '/vendor/PHPMailer/src/';

    if (!file_exists($path . 'PHPMailer.php')) {
        return ['success' => false, 'error' => 'PHPMailer not found at: ' . $path];
    }

    try {
        require_once $path . 'Exception.php';
        require_once $path . 'PHPMailer.php';
        require_once $path . 'SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // ----------------------------------------------------
        // 🛠️ ตั้งค่า SMTP แบบ Hardcode + SSL Bypass
        // ----------------------------------------------------
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'nthkmbm43@gmail.com';  // อีเมลของคุณ
        $mail->Password   = 'xxtoldapngzrlmtn';     // รหัส App Password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        // ปลดล็อค SSL สำหรับ Localhost (ยาแก้ปวดขนานเอก)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );

        $mail->setFrom('nthkmbm43@gmail.com', 'ระบบข้อร้องเรียน');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        $res['success'] = true;
    } catch (Exception $e) {
        $res['error'] = $mail->ErrorInfo;
    }
    return $res;
}