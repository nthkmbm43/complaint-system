<?php
// staff/my-assignments.php
// เริ่มต้น Session และ Output Buffering
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
define('SECURE_ACCESS', true);

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/includes/auth.php';
require_once $baseDir . '/includes/functions.php';

requireLogin();
requireRole('teacher');

$userPermission = $_SESSION['permission'] ?? 0;
if ($userPermission >= 2) {
    $accessDeniedMessage = "หน้านี้สำหรับอาจารย์/เจ้าหน้าที่เท่านั้น ผู้ดูแลระบบควรใช้หน้ามอบหมายงานแทน";
    $accessDeniedRedirect = "assign-complaint.php";
}

// โหลด PHPMailer
$phpMailerLoaded = false;
$possiblePaths = [
    $baseDir . '/vendor/PHPMailer/src/',
    $baseDir . '/vendor/phpmailer/src/',
    $baseDir . '/includes/PHPMailer/src/',
    $baseDir . '/includes/PHPMailer/'
];

foreach ($possiblePaths as $path) {
    if (file_exists($path . 'PHPMailer.php')) {
        require_once $path . 'Exception.php';
        require_once $path . 'PHPMailer.php';
        require_once $path . 'SMTP.php';
        $phpMailerLoaded = true;
        break;
    }
}

if (!$phpMailerLoaded && file_exists($baseDir . '/vendor/autoload.php')) {
    require_once $baseDir . '/vendor/autoload.php';
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $phpMailerLoaded = true;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

requireLogin();
$user = getCurrentUser();
$currentTeacherId = intval($user['id'] ?? $user['Aj_id'] ?? $_SESSION['user_id'] ?? 0);
$currentTeacherName = $user['name'] ?? $user['Aj_name'] ?? 'เจ้าหน้าที่';

if ($currentTeacherId <= 0) {
    header('Location: ../index.php?error=no_permission');
    exit;
}

$db = getDB();

// =======================================================
// 2. AJAX Handler (บันทึกและส่งเมล)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $response = ['success' => false, 'message' => 'เกิดข้อผิดพลาด'];

    try {
        $data = $_POST;
        if (empty($data)) {
            $jsonInput = file_get_contents('php://input');
            $dec = json_decode($jsonInput, true);
            if (!empty($dec)) $data = $dec;
        }

        $action = $data['ajax_action'] ?? '';
        $complaintId = intval($data['complaint_id'] ?? 0);
        $solutionDetail = trim($data['solution_detail'] ?? '');
        if (empty($solutionDetail)) $solutionDetail = '-';

        if ($complaintId <= 0) throw new Exception('ไม่พบรหัสข้อร้องเรียน');

        $complaint = $db->fetch(
            "SELECT r.*, s.Stu_email, s.Stu_name, s.Stu_id 
             FROM request r 
             LEFT JOIN student s ON r.Stu_id = s.Stu_id
             WHERE r.Re_id = ? AND r.Aj_id = ?",
            [$complaintId, $currentTeacherId]
        );

        if (!$complaint) throw new Exception('ไม่พบข้อมูล หรือคุณไม่ใช่ผู้รับผิดชอบ');

        // ========================================
        // ฟังก์ชันอัพโหลดไฟล์หลักฐาน (สำหรับอาจารย์)
        // ========================================
        $uploadEvidenceFunc = function ($files, $complaintId, $teacherId) use ($baseDir, $db) {
            $uploadedFiles = [];
            $uploadDir = $baseDir . '/uploads/requests/images/';

            // สร้างโฟลเดอร์ถ้ายังไม่มี
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // ประเภทไฟล์ที่อนุญาต
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            // ตรวจสอบว่ามีไฟล์ที่อัพโหลดหรือไม่
            if (!isset($files['evidence_files']) || empty($files['evidence_files']['name'][0])) {
                return $uploadedFiles; // ไม่มีไฟล์ให้อัพโหลด
            }

            $fileCount = count($files['evidence_files']['name']);

            for ($i = 0; $i < $fileCount; $i++) {
                // ข้ามถ้าไม่มีไฟล์
                if (empty($files['evidence_files']['name'][$i]) || $files['evidence_files']['error'][$i] !== UPLOAD_ERR_OK) {
                    continue;
                }

                $originalName = $files['evidence_files']['name'][$i];
                $tmpName = $files['evidence_files']['tmp_name'][$i];
                $fileSize = $files['evidence_files']['size'][$i];
                $fileType = $files['evidence_files']['type'][$i];

                // ตรวจสอบขนาดไฟล์
                if ($fileSize > $maxFileSize) {
                    continue; // ข้ามไฟล์ที่ใหญ่เกินไป
                }

                // ตรวจสอบประเภทไฟล์
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $tmpName);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedTypes)) {
                    continue; // ข้ามไฟล์ที่ไม่ใช่รูปภาพ
                }

                // ดึงนามสกุลไฟล์
                $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                if (!in_array($extension, $allowedExtensions)) {
                    continue;
                }

                // สร้างชื่อไฟล์ใหม่ (เพิ่ม _aj เพื่อแยกว่าเป็นไฟล์จากอาจารย์)
                $newFileName = $complaintId . '_aj' . $teacherId . '_' . time() . '_' . uniqid() . '.' . $extension;
                $filePath = $uploadDir . $newFileName;
                $dbFilePath = '../uploads/requests/images/' . $newFileName;

                // ย้ายไฟล์
                if (move_uploaded_file($tmpName, $filePath)) {
                    // หา ID ใหม่ (MAX + 1)
                    $maxSupId = $db->fetch("SELECT COALESCE(MAX(Sup_id), 0) + 1 as new_id FROM supporting_evidence");
                    $newSupId = $maxSupId['new_id'];

                    // บันทึกลงฐานข้อมูล (เพิ่ม Aj_id และ Sup_upload_by เป็น NULL)
                    $db->execute(
                        "INSERT INTO supporting_evidence (Sup_id, Sup_filename, Sup_filepath, Sup_filetype, Sup_filesize, Sup_upload_by, Aj_id, Re_id) 
                         VALUES (?, ?, ?, ?, ?, NULL, ?, ?)",
                        [$newSupId, $originalName, $dbFilePath, $extension, $fileSize, $teacherId, $complaintId]
                    );

                    $uploadedFiles[] = [
                        'id' => $newSupId,
                        'name' => $originalName,
                        'path' => $dbFilePath
                    ];
                }
            }

            return $uploadedFiles;
        };

        // ฟังก์ชันส่งเมล (ใช้ค่า Hardcode ที่ทำงานได้แล้ว)
        $sendMailFunc = function ($toStudentEmail, $studentName, $subject, $body) use ($phpMailerLoaded) {
            if (!$phpMailerLoaded) return "หาไฟล์ PHPMailer ไม่เจอ";

            // Credentials
            $senderEmail = 'nthkmbm43@gmail.com';
            $senderPass  = 'xxtoldapngzrlmtn';
            $senderName  = 'ระบบข้อร้องเรียน (RMUTI Complaint)';

            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $senderEmail;
                $mail->Password   = $senderPass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->CharSet    = 'UTF-8';
                $mail->Timeout    = 15;
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

                $mail->setFrom($senderEmail, $senderName);
                $mail->addAddress($toStudentEmail, $studentName);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;

                $mail->send();
                return true;
            } catch (Exception $e) {
                return $mail->ErrorInfo;
            }
        };

        switch ($action) {
            case 'submit_work':
                // 0. อัพโหลดไฟล์หลักฐาน (ถ้ามี)
                $uploadedFiles = $uploadEvidenceFunc($_FILES, $complaintId, $currentTeacherId);
                $uploadMsg = '';
                if (!empty($uploadedFiles)) {
                    $uploadMsg = ' (แนบไฟล์ ' . count($uploadedFiles) . ' ไฟล์)';
                }

                // 1. อัปเดตสถานะเป็น "รอประเมิน" (Status = 2)
                // [แก้ไข] ลบ updated_at ออก เพราะไม่มีในตาราง
                $db->execute("UPDATE request SET Re_status = '2' WHERE Re_id = ?", [$complaintId]);

                // 2. บันทึกประวัติการทำงาน (D8 - ข้อมูลการจัดการ)
                // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                $maxSaveId = $db->fetch("SELECT COALESCE(MAX(Sv_id), 0) + 1 as new_id FROM save_request");
                $saveId = $maxSaveId['new_id'];

                $db->execute(
                    "INSERT INTO save_request (Sv_id, Sv_infor, Sv_type, Sv_note, Sv_date, Re_id, Aj_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$saveId, 'ดำเนินการแก้ไขเรียบร้อย - รอประเมินผล', 'process', $solutionDetail, date('Y-m-d'), $complaintId, $currentTeacherId]
                );

                // 3. [เพิ่มใหม่ตาม DFD Process 8] บันทึกผลการดำเนินงานลงตาราง result_request (D9)
                if ($saveId) {
                    // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                    $maxResultId = $db->fetch("SELECT COALESCE(MAX(Result_id), 0) + 1 as new_id FROM result_request");
                    $newResultId = $maxResultId['new_id'];

                    $db->execute(
                        "INSERT INTO result_request (Result_id, Result_date, Sv_id, Re_id, Aj_id) VALUES (?, ?, ?, ?, ?)",
                        [$newResultId, date('Y-m-d'), $saveId, $complaintId, $currentTeacherId]
                    );
                }

                // 4. ส่งการแจ้งเตือน (Notification)
                if (!empty($complaint['Stu_id'])) {
                    $notiTitle = "อัปเดตสถานะข้อร้องเรียน #$complaintId";
                    $notiMsg = "การดำเนินการ: $solutionDetail (โดย $currentTeacherName)";

                    // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                    $maxNotiId = $db->fetch("SELECT COALESCE(MAX(Noti_id), 0) + 1 as new_id FROM notification");
                    $newNotiId = $maxNotiId['new_id'];

                    $db->execute(
                        "INSERT INTO notification (Noti_id, Noti_title, Noti_message, Noti_type, Noti_date, Re_id, Stu_id, created_by) 
                         VALUES (?, ?, ?, 'system', NOW(), ?, ?, ?)",
                        [$newNotiId, $notiTitle, $notiMsg, $complaintId, $complaint['Stu_id'], $currentTeacherId]
                    );
                }

                // 5. ส่งอีเมล (Email) - ส่งทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
                // (ระบบรู้ว่าใครร้องเรียนจาก Stu_id แม้อาจารย์จะไม่เห็น)
                $msg = 'บันทึกผลการดำเนินงานเรียบร้อย (บันทึก D9)' . $uploadMsg;

                if (!empty($complaint['Stu_email'])) {
                    $link = defined('SITE_URL') ? SITE_URL : 'https://complaint.rmuti.ac.th';

                    // ปรับข้อความตามการระบุตัวตน
                    $greeting = ($complaint['Re_iden'] == 0)
                        ? "เรียน คุณ" . htmlspecialchars($complaint['Stu_name'])
                        : "เรียน ผู้ร้องเรียน";

                    // ถ้าไม่ระบุตัวตน ไม่ต้องแสดงชื่อผู้ดำเนินการ (เพื่อความเป็นส่วนตัว)
                    $staffInfo = ($complaint['Re_iden'] == 0)
                        ? "<strong>👨‍🏫 ดำเนินการโดย:</strong> $currentTeacherName"
                        : "<strong>👨‍🏫 ดำเนินการโดย:</strong> เจ้าหน้าที่";

                    // แสดงข้อมูลไฟล์แนบในอีเมล (ถ้ามี)
                    $attachmentInfo = '';
                    if (!empty($uploadedFiles)) {
                        $attachmentInfo = "<br><br><strong>📎 ไฟล์หลักฐานที่แนบ:</strong> " . count($uploadedFiles) . " ไฟล์";
                    }

                    $body = "$greeting<br><br>" .
                        "เรื่อง: <strong>" . htmlspecialchars($complaint['Re_title']) . "</strong><br>" .
                        "รหัสข้อร้องเรียน: <strong>#$complaintId</strong><br>" .
                        "สถานะปัจจุบัน: <span style='color:#eab308; font-weight:bold;'>รอประเมินความพึงพอใจ</span><br><br>" .
                        "<div style='background:#f9fafb; padding:15px; border-radius:8px; border:1px solid #e5e7eb;'>" .
                        "<strong>📄 รายละเอียดการดำเนินการ:</strong><br>" .
                        nl2br(htmlspecialchars($solutionDetail)) . "<br><br>" .
                        $staffInfo .
                        $attachmentInfo .
                        "</div><br>" .
                        "กรุณาเข้าสู่ระบบเพื่อทำการประเมินผล:<br>" .
                        "<a href='$link' style='display:inline-block; background:#0ea5e9;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;margin-top:10px;'>เข้าสู่ระบบประเมินผล</a>" .
                        "<br><br><hr style='border:none; border-top:1px solid #e5e7eb;'>" .
                        "<small style='color:#6b7280;'>อีเมลนี้ถูกส่งอัตโนมัติจากระบบข้อร้องเรียน กรุณาอย่าตอบกลับอีเมลนี้</small>";

                    $mailRes = $sendMailFunc($complaint['Stu_email'], $complaint['Stu_name'] ?? 'นักศึกษา', "✅ แจ้งเตือน: ข้อร้องเรียน #$complaintId ดำเนินการเสร็จสิ้นแล้ว - รอประเมิน", $body);

                    if ($mailRes === true) $msg .= ' (และส่งอีเมลแจ้งเตือนแล้ว ✅)';
                    else $msg .= ' (แต่ส่งเมลไม่ผ่าน ❌ Error: ' . $mailRes . ')';
                } else {
                    $msg .= ' (ไม่ได้ส่งเมล: ไม่พบอีเมลของนักศึกษาในระบบ)';
                }

                $response = ['success' => true, 'message' => $msg, 'redirect' => 'my-assignments.php?tab=2'];
                break;

            case 'mark_complete':
                // [แก้ไข] ลบ updated_at ออก
                $db->execute("UPDATE request SET Re_status = '3' WHERE Re_id = ?", [$complaintId]);

                // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                $maxSaveId = $db->fetch("SELECT COALESCE(MAX(Sv_id), 0) + 1 as new_id FROM save_request");
                $saveId = $maxSaveId['new_id'];

                $db->execute(
                    "INSERT INTO save_request (Sv_id, Sv_infor, Sv_type, Sv_note, Sv_date, Re_id, Aj_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$saveId, 'ดำเนินการเสร็จสิ้น', 'close', $solutionDetail, date('Y-m-d'), $complaintId, $currentTeacherId]
                );

                // [เพิ่มใหม่ตาม DFD Process 8] บันทึกผลลง D9 เช่นกันเมื่อปิดงาน
                if ($saveId) {
                    // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                    $maxResultId = $db->fetch("SELECT COALESCE(MAX(Result_id), 0) + 1 as new_id FROM result_request");
                    $newResultId = $maxResultId['new_id'];

                    $db->execute(
                        "INSERT INTO result_request (Result_id, Result_date, Sv_id, Re_id, Aj_id) VALUES (?, ?, ?, ?, ?)",
                        [$newResultId, date('Y-m-d'), $saveId, $complaintId, $currentTeacherId]
                    );
                }

                // บันทึก Notification
                if (!empty($complaint['Stu_id'])) {
                    // หา ID ใหม่ (MAX + 1) เพื่อป้องกันปัญหา AUTO_INCREMENT
                    $maxNotiId = $db->fetch("SELECT COALESCE(MAX(Noti_id), 0) + 1 as new_id FROM notification");
                    $newNotiId = $maxNotiId['new_id'];

                    $db->execute(
                        "INSERT INTO notification (Noti_id, Noti_title, Noti_message, Noti_type, Noti_date, Re_id, Stu_id, created_by) 
                         VALUES (?, ?, ?, 'system', NOW(), ?, ?, ?)",
                        [$newNotiId, "ข้อร้องเรียน #$complaintId เสร็จสิ้น", "ปิดงานเรียบร้อยแล้ว: $solutionDetail", $complaintId, $complaint['Stu_id'], $currentTeacherId]
                    );
                }

                // ส่งอีเมลแจ้งเตือนเมื่อปิดงาน - ส่งทุกกรณี ไม่ว่าจะระบุตัวตนหรือไม่
                $msg = 'บันทึกสถานะเสร็จสิ้นเรียบร้อย (บันทึก D9)';

                if (!empty($complaint['Stu_email'])) {
                    $link = defined('SITE_URL') ? SITE_URL : 'https://complaint.rmuti.ac.th';

                    // ปรับข้อความตามการระบุตัวตน
                    $greeting = ($complaint['Re_iden'] == 0)
                        ? "เรียน คุณ" . htmlspecialchars($complaint['Stu_name'])
                        : "เรียน ผู้ร้องเรียน";

                    $staffInfo = ($complaint['Re_iden'] == 0)
                        ? "<strong>👨‍🏫 ดำเนินการโดย:</strong> $currentTeacherName"
                        : "<strong>👨‍🏫 ดำเนินการโดย:</strong> เจ้าหน้าที่";

                    $body = "$greeting<br><br>" .
                        "ข้อร้องเรียนของคุณได้รับการดำเนินการ<span style='color:#10b981; font-weight:bold;'>เสร็จสิ้น</span>แล้ว<br><br>" .
                        "เรื่อง: <strong>" . htmlspecialchars($complaint['Re_title']) . "</strong><br>" .
                        "รหัสข้อร้องเรียน: <strong>#$complaintId</strong><br>" .
                        "สถานะ: <span style='color:#10b981; font-weight:bold;'>✅ เสร็จสิ้น</span><br><br>" .
                        "<div style='background:#ecfdf5; padding:15px; border-radius:8px; border:1px solid #10b981;'>" .
                        "<strong>📄 สรุปการดำเนินการ:</strong><br>" .
                        nl2br(htmlspecialchars($solutionDetail ?: 'ดำเนินการเรียบร้อยแล้ว')) . "<br><br>" .
                        $staffInfo .
                        "</div><br>" .
                        "ขอบคุณที่ใช้บริการระบบข้อร้องเรียน<br>" .
                        "<a href='$link' style='display:inline-block; background:#10b981;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;margin-top:10px;'>เข้าสู่ระบบ</a>" .
                        "<br><br><hr style='border:none; border-top:1px solid #e5e7eb;'>" .
                        "<small style='color:#6b7280;'>อีเมลนี้ถูกส่งอัตโนมัติจากระบบข้อร้องเรียน กรุณาอย่าตอบกลับอีเมลนี้</small>";

                    $mailRes = $sendMailFunc($complaint['Stu_email'], $complaint['Stu_name'] ?? 'นักศึกษา', "✅ ข้อร้องเรียน #$complaintId ดำเนินการเสร็จสิ้นแล้ว", $body);

                    if ($mailRes === true) $msg .= ' (และส่งอีเมลแจ้งเตือนแล้ว ✅)';
                    else $msg .= ' (แต่ส่งเมลไม่ผ่าน ❌ Error: ' . $mailRes . ')';
                } else {
                    $msg .= ' (ไม่ได้ส่งเมล: ไม่พบอีเมลของนักศึกษาในระบบ)';
                }

                $response = ['success' => true, 'message' => $msg, 'redirect' => 'my-assignments.php?tab=3'];
                break;
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

if (ob_get_length()) ob_end_flush();

// =======================================================
// 3. ส่วนแสดงผล HTML (Pagination + Search Fix + Level Badge)
// =======================================================
$itemsPerPage = 15;
$pageInput = $_GET['page'] ?? 1;
$currentPage = is_numeric($pageInput) ? max(1, intval($pageInput)) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;
$currentTab = $_GET['tab'] ?? '1';

$complaints = [];
try {
    $whereConditions = ['r.Aj_id = ?', 'r.Re_status IN (1, 2, 3)'];
    $params = [$currentTeacherId];

    if ($currentTab === '1') $whereConditions[] = 'r.Re_status = 1';
    elseif ($currentTab === '2') $whereConditions[] = 'r.Re_status = 2';
    elseif ($currentTab === '3') $whereConditions[] = 'r.Re_status = 3';

    // *** แก้ไขส่วนค้นหา (Search Logic Update) ***
    if (!empty($_GET['search'])) {
        // เพิ่มให้ค้นหาจาก รหัส นศ. (Stu_id) และ ชื่อ นศ. (Stu_name) ได้ด้วย
        $whereConditions[] = '(r.Re_id LIKE ? OR r.Re_title LIKE ? OR s.Stu_id LIKE ? OR s.Stu_name LIKE ?)';
        $term = '%' . trim($_GET['search']) . '%';
        // ต้องใส่ params ให้ครบตามจำนวน ?
        array_push($params, $term, $term, $term, $term);
    }

    $whereClause = implode(' AND ', $whereConditions);

    // นับจำนวนรวมสำหรับแบ่งหน้า
    // ต้อง JOIN student เพื่อให้ค้นหาชื่อ นศ. ได้ในการนับจำนวนด้วย
    $countSql = "SELECT COUNT(*) as total 
                 FROM request r 
                 LEFT JOIN student s ON r.Stu_id = s.Stu_id 
                 WHERE {$whereClause}";
    $countRes = $db->fetch($countSql, $params);
    $totalComplaints = intval($countRes['total'] ?? 0);
    $totalPages = max(1, ceil($totalComplaints / $itemsPerPage));

    // ดึงข้อมูลหลัก (เพิ่ม Re_level เข้ามาในการแสดงผล)
    $complaints = $db->fetchAll(
        "SELECT r.*, s.Stu_name, t.Type_infor 
         FROM request r 
         LEFT JOIN student s ON r.Stu_id = s.Stu_id 
         LEFT JOIN type t ON r.Type_id = t.Type_id 
         WHERE {$whereClause} 
         ORDER BY r.Re_level DESC, r.Re_id DESC 
         LIMIT {$itemsPerPage} OFFSET {$offset}",
        $params
    );

    // นับจำนวน Badge ที่ Tab
    $c1 = $db->fetch("SELECT COUNT(*) as c FROM request WHERE Aj_id = ? AND Re_status = 1", [$currentTeacherId])['c'] ?? 0;
    $c2 = $db->fetch("SELECT COUNT(*) as c FROM request WHERE Aj_id = ? AND Re_status = 2", [$currentTeacherId])['c'] ?? 0;
    $c3 = $db->fetch("SELECT COUNT(*) as c FROM request WHERE Aj_id = ? AND Re_status = 3", [$currentTeacherId])['c'] ?? 0;
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Helper สำหรับแสดงสีความสำคัญ
function getLevelBadge($level)
{
    switch ($level) {
        case '5':
            return '<span class="level-badge level-5">🔥 วิกฤต/ฉุกเฉิน</span>';
        case '4':
            return '<span class="level-badge level-4">🚨 เร่งด่วนมาก</span>';
        case '3':
            return '<span class="level-badge level-3">⚠️ เร่งด่วน</span>';
        case '2':
            return '<span class="level-badge level-2">🔵 ปกติ</span>';
        case '1':
            return '<span class="level-badge level-1">🟢 ไม่เร่งด่วน</span>';
        default:
            return '<span class="level-badge level-0">⚪ รอพิจารณา</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>บันทึกผลการดำเนินงาน</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/staff.css">

    <style>
        /* CSS */
        :root {
            --bg-body: #eaeff5;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --gradient-header: linear-gradient(120deg, #4f46e5, #3b82f6);
            --color-1: #0ea5e9;
            --color-2: #eab308;
            --color-3: #10b981;
            --shadow-card: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }

        body {
            background-color: var(--bg-body);
            font-family: 'Sarabun', sans-serif;
            color: var(--text-main);
        }

        /* Level Badges */
        .level-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            display: inline-block;
            margin-top: 5px;
        }

        .level-5 {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        /* วิกฤต */
        .level-4 {
            background: #ffedd5;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        /* เร่งด่วนมาก */
        .level-3 {
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        /* เร่งด่วน */
        .level-2 {
            background: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        /* ปกติ */
        .level-1 {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }

        /* ไม่เร่งด่วน */
        .level-0 {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        /* รอพิจารณา */

        .page-header {
            background: var(--gradient-header);
            padding: 2.5rem;
            border-radius: 16px;
            margin-bottom: 2.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.4);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .header-title h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 800;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header-icon {
            font-size: 3rem;
            background: rgba(255, 255, 255, 0.2);
            width: 70px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            backdrop-filter: blur(5px);
        }

        .tab-wrapper {
            background: white;
            padding: 8px;
            border-radius: 50px;
            box-shadow: var(--shadow-card);
            display: inline-flex;
            gap: 8px;
            margin-bottom: 30px;
        }

        .tab-pill {
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            color: #64748b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: 0.3s;
        }

        .tab-pill:hover {
            background: #f8fafc;
            color: #334155;
        }

        .tab-pill.active-1 {
            background: var(--color-1);
            color: white;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.4);
        }

        .tab-pill.active-2 {
            background: var(--color-2);
            color: white;
            box-shadow: 0 4px 12px rgba(234, 179, 8, 0.4);
        }

        .tab-pill.active-3 {
            background: var(--color-3);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .badge-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }

        .tab-pill:not([class*="active-"]) .badge-count {
            background: #e2e8f0;
            color: #64748b;
        }

        .task-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-card);
            display: grid;
            grid-template-columns: 80px 1fr 120px 150px 140px;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
            border-left: 6px solid transparent;
            transition: all 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .card-status-1 {
            border-color: var(--color-1);
        }

        .card-status-2 {
            border-color: var(--color-2);
        }

        .card-status-3 {
            border-color: var(--color-3);
        }

        .col-id {
            font-weight: 800;
            font-size: 1.1rem;
            text-align: center;
            color: #1e293b;
        }

        .col-id span {
            display: block;
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 400;
            margin-top: 4px;
        }

        .col-info h3 {
            margin: 0 0 6px;
            font-size: 1rem;
            color: #1e293b;
            font-weight: 700;
        }

        .tag-type {
            background: #f1f5f9;
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
        }

        .status-pill {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }

        .st-1 {
            background: #e0f2fe;
            color: #0284c7;
        }

        .st-2 {
            background: #fef9c3;
            color: #a16207;
        }

        .st-3 {
            background: #dcfce7;
            color: #166534;
        }

        .btn-work {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.3);
            transition: 0.2s;
        }

        .btn-work:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.4);
        }

        .btn-view {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);
            transition: 0.2s;
        }

        .btn-view:hover {
            transform: translateY(-2px);
        }

        .text-wait {
            color: #d97706;
            font-style: italic;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            justify-content: flex-end;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(5px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .modal-overlay.show {
            opacity: 1;
        }

        .work-modal {
            background: white;
            width: 90%;
            max-width: 600px;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: scale(0.9);
            transition: transform 0.3s;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-overlay.show .work-modal {
            transform: scale(1);
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
            color: #1e293b;
            font-size: 1.4rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            width: 40px;
            height: 40px;
            border: none;
            background: #f1f5f9;
            border-radius: 50%;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
        }

        .close-btn:hover {
            background: #e2e8f0;
            color: #ef4444;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
        }

        .form-label .optional {
            color: #ef4444;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            transition: 0.2s;
            resize: vertical;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #0ea5e9;
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.1);
        }

        /* ========================================
           CSS สำหรับ File Upload
           ======================================== */
        .file-upload-wrapper {
            margin-top: 20px;
        }

        .file-upload-label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #334155;
        }

        .file-upload-area {
            border: 2px dashed #cbd5e1;
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .file-upload-area:hover {
            border-color: #0ea5e9;
            background: #f0f9ff;
        }

        .file-upload-area.dragover {
            border-color: #0ea5e9;
            background: #e0f2fe;
        }

        .file-upload-icon {
            font-size: 3rem;
            color: #94a3b8;
            margin-bottom: 10px;
        }

        .file-upload-text {
            color: #64748b;
            font-size: 0.95rem;
        }

        .file-upload-text strong {
            color: #0ea5e9;
        }

        .file-upload-hint {
            color: #94a3b8;
            font-size: 0.8rem;
            margin-top: 8px;
        }

        .file-input-hidden {
            display: none;
        }

        .file-preview-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 15px;
        }

        .file-preview-item {
            position: relative;
            width: 100px;
            height: 100px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .file-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .file-preview-remove {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 24px;
            height: 24px;
            background: #ef4444;
            border: none;
            border-radius: 50%;
            color: white;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .file-preview-item:hover .file-preview-remove {
            opacity: 1;
        }

        .file-preview-name {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            font-size: 0.65rem;
            padding: 4px;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #f1f5f9;
        }

        .btn-cancel {
            padding: 12px 24px;
            border: none;
            background: #dc3545;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            color: white;
        }

        .btn-cancel:hover {
            background: #c82333;
        }

        .btn-submit {
            background: linear-gradient(135deg, #0ea5e9, #0284c7);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(14, 165, 233, 0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(14, 165, 233, 0.4);
        }

        .confirm-modal-content {
            background: white;
            border-radius: 20px;
            padding: 35px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: scale(0.9);
            transition: transform 0.3s;
        }

        .confirm-modal.show .confirm-modal-content {
            transform: scale(1);
        }

        .confirm-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }

        .confirm-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn-confirm {
            padding: 12px 30px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .btn-confirm:hover {
            transform: translateY(-2px);
        }

        .toast-container {
            position: fixed;
            top: 100px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            padding: 16px 24px;
            border-radius: 12px;
            color: white;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.4s ease;
            margin-bottom: 10px;
        }

        .toast.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .toast.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .toast-close {
            margin-left: auto;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .d-md-block {
            display: none;
        }

        @media(min-width: 768px) {
            .d-md-block {
                display: block;
            }

            .page-header {
                flex-direction: row;
                text-align: left;
            }

            .task-card {
                grid-template-columns: 80px 1fr 120px 150px 140px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
            }

            .task-card {
                grid-template-columns: 1fr;
                border-left: none;
                border-top: 6px solid;
            }

            .card-status-1 {
                border-top-color: var(--color-1);
            }

            .card-status-2 {
                border-top-color: var(--color-2);
            }

            .card-status-3 {
                border-top-color: var(--color-3);
            }

            .col-id,
            .col-status,
            .col-action {
                display: flex;
                justify-content: space-between;
                width: 100%;
                margin-bottom: 10px;
            }
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

    <div class="toast-container" id="toastContainer"></div>

    <div class="main-content">
        <div class="container">

            <div class="page-header">
                <div class="header-title">
                    <h1>📋 บันทึกผลการดำเนินงาน</h1>
                    <p>บันทึกและติดตามผลการดำเนินงานข้อร้องเรียนที่ได้รับมอบหมาย</p>
                </div>
                <div class="d-md-block">
                    <div class="header-icon"><span>👨‍🏫</span></div>
                </div>
            </div>

            <div style="text-align:center;">
                <div class="tab-wrapper">
                    <a href="?tab=1" class="tab-pill <?php echo $currentTab === '1' ? 'active-1' : ''; ?>">
                        <i class="fas fa-tools"></i> ดำเนินการ
                        <?php if ($c1 > 0): ?><span class="badge-count"><?php echo $c1; ?></span><?php endif; ?>
                    </a>
                    <a href="?tab=2" class="tab-pill <?php echo $currentTab === '2' ? 'active-2' : ''; ?>">
                        <i class="fas fa-hourglass-half"></i> รอประเมิน
                        <?php if ($c2 > 0): ?><span class="badge-count"><?php echo $c2; ?></span><?php endif; ?>
                    </a>
                    <a href="?tab=3" class="tab-pill <?php echo $currentTab === '3' ? 'active-3' : ''; ?>">
                        <i class="fas fa-check-circle"></i> เสร็จสิ้น
                        <?php if ($c3 > 0): ?><span class="badge-count"><?php echo $c3; ?></span><?php endif; ?>
                    </a>
                </div>
            </div>

            <div style="max-width:600px; margin: 0 auto 30px auto;">
                <form method="GET">
                    <input type="hidden" name="tab" value="<?php echo $currentTab; ?>">
                    <div style="display:flex; gap:10px;">
                        <input type="text" name="search" class="form-control" style="margin:0; box-shadow: var(--shadow-card);"
                            placeholder="🔍 ค้นหา (รหัสเรื่อง, ชื่อเรื่อง, รหัส นศ., ชื่อ นศ.)"
                            value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        <button type="submit" class="btn-submit" style="padding:10px 25px;">ค้นหา</button>
                    </div>
                </form>
            </div>

            <div class="card-container">
                <?php if (empty($complaints)): ?>
                    <div style="text-align:center; padding:60px 20px; background:white; border-radius:16px; box-shadow:var(--shadow-card);">
                        <div style="background:#f1f5f9; width:80px; height:80px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
                            <i class="fas fa-check" style="font-size:2rem; color:#cbd5e1;"></i>
                        </div>
                        <h3 style="color:#64748b; margin:0;">ไม่มีรายการในสถานะนี้</h3>
                        <p style="color:#94a3b8; font-size:0.9rem;">หรือรายการที่ค้นหาไม่พบข้อมูล</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($complaints as $row):
                        $status = intval($row['Re_status']);
                    ?>
                        <div class="task-card card-status-<?php echo $status; ?>">
                            <div class="col-id">
                                #<?php echo $row['Re_id']; ?>
                                <span><?php echo date('d/m/y', strtotime($row['Re_date'])); ?></span>
                            </div>

                            <div class="col-info">
                                <h3><?php echo htmlspecialchars($row['Re_title']); ?></h3>
                                <div class="meta">
                                    <span class="tag-type"><i class="far fa-folder"></i> <?php echo htmlspecialchars($row['Type_infor'] ?? 'ทั่วไป'); ?></span>
                                    <span><i class="far fa-user"></i> <?php echo htmlspecialchars($row['Re_iden'] == 1 ? 'ไม่ระบุตัวตน' : ($row['Stu_name'] ?? 'ไม่ระบุ')); ?></span>
                                </div>
                            </div>

                            <div style="text-align:center;">
                                <?php echo getLevelBadge($row['Re_level']); ?>
                            </div>

                            <div class="col-status">
                                <?php if ($status === 1): ?>
                                    <span class="status-pill st-1"><i class="fas fa-spinner fa-spin"></i> กำลังดำเนินการ</span>
                                <?php elseif ($status === 2): ?>
                                    <span class="status-pill st-2"><i class="fas fa-clock"></i> รอประเมิน</span>
                                <?php elseif ($status === 3): ?>
                                    <span class="status-pill st-3"><i class="fas fa-check"></i> เสร็จสิ้น</span>
                                <?php endif; ?>
                            </div>

                            <div class="col-action">
                                <?php if ($status === 1): ?>
                                    <button class="btn-work" onclick="openWorkModal('<?php echo $row['Re_id']; ?>', '<?php echo htmlspecialchars($row['Re_title'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-edit"></i> บันทึกงาน
                                    </button>
                                    <a href="complaint-detail.php?id=<?php echo $row['Re_id']; ?>" class="btn-view" style="margin-top:8px;"><i class="fas fa-eye"></i> ดูรายละเอียด</a>
                                <?php elseif ($status === 2): ?>
                                    
                                    <a href="complaint-detail.php?id=<?php echo $row['Re_id']; ?>" class="btn-view" style="margin-top:8px;"><i class="fas fa-eye"></i> ดูรายละเอียด</a>
                                <?php elseif ($status === 3): ?>
                                    <a href="complaint-detail.php?id=<?php echo $row['Re_id']; ?>" class="btn-view"><i class="fas fa-eye"></i> ดูรายละเอียด</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div style="margin-top:40px; display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>&tab=<?php echo $currentTab; ?>&search=<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>"
                            style="padding:10px 16px; border-radius:10px; text-decoration:none; font-weight:700; transition:all 0.2s;
                           <?php echo $currentPage == $i ? 'background:linear-gradient(135deg, #0ea5e9, #0284c7); color:white; box-shadow:0 4px 10px rgba(14,165,233,0.3);' : 'background:white; color:#64748b; box-shadow:var(--shadow-card);'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal บันทึกผลการดำเนินงาน (เพิ่มส่วนอัพโหลดไฟล์) -->
    <div class="modal-overlay" id="workModal">
        <div class="work-modal">
            <div class="modal-header">
                <div class="modal-title"><i class="fas fa-tools" style="color:#0ea5e9;"></i> บันทึกผลการดำเนินงาน</div>
                <button class="close-btn" onclick="closeWorkModal()">&times;</button>
            </div>

            <form id="workForm" onsubmit="handleSubmitWork(event)" enctype="multipart/form-data">
                <input type="hidden" name="ajax_action" value="submit_work">
                <input type="hidden" name="complaint_id" id="modal_complaint_id">

                <div style="background:#f1f5f9; padding:15px; border-radius:10px; margin-bottom:20px; border-left:4px solid #0ea5e9;">
                    <strong style="color:#64748b; font-size:0.9rem;">หัวข้อเรื่อง:</strong>
                    <div id="modal_complaint_title" style="font-weight:700; color:#1e293b; margin-top:5px; font-size:1.05rem;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        รายละเอียดการแก้ไขปัญหา / สิ่งที่ดำเนินการไป <span class="optional">(สำคัญ)</span>
                    </label>
                    <textarea name="solution_detail" id="solution_detail" class="form-control" rows="5"
                        placeholder="ระบุรายละเอียดการแก้ไข เช่น เปลี่ยนอุปกรณ์ใหม่แล้ว, ซ่อมแซมเรียบร้อย..."></textarea>
                </div>

                <!-- ส่วนอัพโหลดไฟล์รูปภาพ/หลักฐาน -->
                <div class="file-upload-wrapper">
                    <label class="file-upload-label">
                        <i class="fas fa-paperclip"></i> แนบรูปภาพหลักฐาน <span style="color:#94a3b8; font-weight:normal;">(ไม่บังคับ)</span>
                    </label>
                    <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('evidence_files').click()">
                        <div class="file-upload-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="file-upload-text">
                            คลิกเพื่อเลือกไฟล์ หรือ <strong>ลากไฟล์มาวางที่นี่</strong>
                        </div>
                        <div class="file-upload-hint">รองรับไฟล์ JPG, PNG, GIF, WEBP (สูงสุด 5 MB ต่อไฟล์, เลือกได้หลายไฟล์)</div>
                    </div>
                    <input type="file" name="evidence_files[]" id="evidence_files" class="file-input-hidden"
                        accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    <div class="file-preview-container" id="filePreviewContainer"></div>
                </div>

                <div style="background:#fef3c7; padding:15px; border-radius:10px; margin-top:20px; margin-bottom:15px; display:flex; align-items:flex-start; gap:12px;">
                    <span style="font-size:1.3rem;">💡</span>
                    <div style="font-size:0.9rem; color:#92400e; line-height:1.5;">
                        <strong>สิ่งที่จะเกิดขึ้นหลังบันทึก:</strong><br>
                        1. สถานะจะเปลี่ยนเป็น <strong>"รอประเมิน"</strong><br>
                        2. <strong>บันทึกประวัติ</strong> การทำงานของคุณลงระบบ<br>
                        3. ส่ง <strong>การแจ้งเตือน</strong> และ <strong>อีเมล</strong> ไปหานักศึกษา<br>
                        4. <strong>แนบไฟล์หลักฐาน</strong> (ถ้ามี) เก็บไว้ในระบบ
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeWorkModal()">ยกเลิก</button>
                    <button type="submit" class="btn-submit" id="submitWorkBtn">
                        <i class="fas fa-paper-plane"></i> บันทึกและส่งประเมิน
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay confirm-modal" id="confirmModal">
        <div class="confirm-modal-content">
            <div class="confirm-icon" id="confirmIcon">✅</div>
            <h3 id="confirmTitle">ยืนยันการบันทึก</h3>
            <p id="confirmMessage">คุณแน่ใจหรือไม่ว่าต้องการบันทึกผลการดำเนินงาน?</p>
            <div class="confirm-buttons">
                <button type="button" class="btn-cancel" onclick="closeConfirmModal()">ยกเลิก</button>
                <button type="button" class="btn-confirm" id="confirmBtn" onclick="executeConfirmedAction()">
                    <i class="fas fa-check"></i> ยืนยัน
                </button>
            </div>
        </div>
    </div>

    <script>
        const modal = document.getElementById('workModal');
        const confirmModalEl = document.getElementById('confirmModal');
        const modalIdInput = document.getElementById('modal_complaint_id');
        const modalTitleDiv = document.getElementById('modal_complaint_title');
        const fileInput = document.getElementById('evidence_files');
        const fileUploadArea = document.getElementById('fileUploadArea');
        const filePreviewContainer = document.getElementById('filePreviewContainer');

        // เก็บไฟล์ที่เลือกไว้
        let selectedFiles = [];

        function openWorkModal(id, title) {
            modalIdInput.value = id;
            modalTitleDiv.textContent = title;
            document.getElementById('solution_detail').value = '';
            // รีเซ็ตไฟล์
            selectedFiles = [];
            fileInput.value = '';
            filePreviewContainer.innerHTML = '';
            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('show'), 10);
        }

        function closeWorkModal() {
            modal.classList.remove('show');
            setTimeout(() => modal.style.display = 'none', 300);
        }

        function closeConfirmModal() {
            confirmModalEl.classList.remove('show');
            setTimeout(() => confirmModalEl.style.display = 'none', 300);
        }

        // ========================================
        // จัดการ File Upload
        // ========================================

        // Drag and Drop
        fileUploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileUploadArea.classList.add('dragover');
        });

        fileUploadArea.addEventListener('dragleave', () => {
            fileUploadArea.classList.remove('dragover');
        });

        fileUploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            fileUploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            handleFiles(files);
        });

        // เมื่อเลือกไฟล์
        fileInput.addEventListener('change', (e) => {
            handleFiles(e.target.files);
        });

        function handleFiles(files) {
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            const maxSize = 5 * 1024 * 1024; // 5MB

            for (let i = 0; i < files.length; i++) {
                const file = files[i];

                // ตรวจสอบประเภทไฟล์
                if (!allowedTypes.includes(file.type)) {
                    showToast('error', `ไฟล์ "${file.name}" ไม่ใช่รูปภาพที่รองรับ`);
                    continue;
                }

                // ตรวจสอบขนาดไฟล์
                if (file.size > maxSize) {
                    showToast('error', `ไฟล์ "${file.name}" ใหญ่เกินไป (สูงสุด 5MB)`);
                    continue;
                }

                // เพิ่มไฟล์ถ้ายังไม่มี
                if (!selectedFiles.find(f => f.name === file.name && f.size === file.size)) {
                    selectedFiles.push(file);
                    addFilePreview(file, selectedFiles.length - 1);
                }
            }

            // อัพเดท input files
            updateFileInput();
        }

        function addFilePreview(file, index) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewItem = document.createElement('div');
                previewItem.className = 'file-preview-item';
                previewItem.dataset.index = index;
                previewItem.innerHTML = `
                    <img src="${e.target.result}" alt="${file.name}">
                    <button type="button" class="file-preview-remove" onclick="removeFile(${index})">×</button>
                    <div class="file-preview-name">${file.name}</div>
                `;
                filePreviewContainer.appendChild(previewItem);
            };
            reader.readAsDataURL(file);
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            // รีเรนเดอร์ preview ใหม่
            filePreviewContainer.innerHTML = '';
            selectedFiles.forEach((file, i) => {
                addFilePreview(file, i);
            });
            updateFileInput();
        }

        function updateFileInput() {
            // สร้าง DataTransfer ใหม่และใส่ไฟล์ที่เลือกทั้งหมด
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            fileInput.files = dt.files;
        }

        function handleSubmitWork(e) {
            e.preventDefault();
            document.getElementById('confirmIcon').textContent = '✅';
            document.getElementById('confirmTitle').textContent = 'ยืนยันการบันทึกผลงาน';

            let msg = 'คุณแน่ใจหรือไม่ว่าต้องการบันทึกผลการดำเนินงาน?';
            if (selectedFiles.length > 0) {
                msg += ` (พร้อมแนบไฟล์ ${selectedFiles.length} ไฟล์)`;
            }
            document.getElementById('confirmMessage').textContent = msg;

            confirmModalEl.style.display = 'flex';
            setTimeout(() => confirmModalEl.classList.add('show'), 10);
        }

        function executeConfirmedAction() {
            closeConfirmModal();
            const submitBtn = document.getElementById('submitWorkBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> กำลังบันทึก...';

            const formElement = document.getElementById('workForm');
            const formData = new FormData(formElement);

            fetch('my-assignments.php', {
                    method: 'POST',
                    body: formData
                    // ไม่ต้องตั้ง Content-Type เพราะ FormData จะจัดการเอง
                })
                .then(async res => {
                    const text = await res.text();
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error("Server Error:", text);
                        throw new Error("เกิดข้อผิดพลาดจากเซิร์ฟเวอร์");
                    }
                })
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    if (data.success) {
                        showToast('success', data.message);
                        closeWorkModal();
                        setTimeout(() => {
                            if (data.redirect) window.location.href = data.redirect;
                            else location.reload();
                        }, 2000);
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(err => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                    showToast('error', err.message || 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้');
                });
        }

        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icon = type === 'success' ? '✓' : '✕';
            toast.innerHTML = `<span style="font-size:1.3rem;">${icon}</span><span>${message}</span><button class="toast-close" onclick="this.parentElement.remove()">&times;</button>`;
            container.appendChild(toast);
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateX(100%)';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }

        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeWorkModal();
        });
        confirmModalEl.addEventListener('click', (e) => {
            if (e.target === confirmModalEl) closeConfirmModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (confirmModalEl.classList.contains('show')) closeConfirmModal();
                else if (modal.classList.contains('show')) closeWorkModal();
            }
        });
    </script>
</body>

</html>