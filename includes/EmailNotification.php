<?php
// includes/EmailNotification.php - ระบบ Email Notification
define('SECURE_ACCESS', true);

class EmailNotification
{
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $fromEmail;
    private $fromName;
    private $isEnabled;

    public function __construct()
    {
        $this->smtpHost = SMTP_HOST ?? 'smtp.gmail.com';
        $this->smtpPort = SMTP_PORT ?? 587;
        $this->smtpUsername = SMTP_USERNAME ?? '';
        $this->smtpPassword = SMTP_PASSWORD ?? '';
        $this->fromEmail = SMTP_FROM_EMAIL ?? 'noreply@rmuti.ac.th';
        $this->fromName = SMTP_FROM_NAME ?? 'ระบบข้อร้องเรียน RMUTI';
        $this->isEnabled = !empty($this->smtpUsername) && !empty($this->smtpPassword);
    }

    /**
     * ส่งอีเมลแจ้งเตือนหลัก
     */
    public function sendNotificationEmail($to, $subject, $message, $requestId = null, $template = 'default')
    {
        if (!$this->isEnabled) {
            error_log("Email notification disabled - missing SMTP configuration");
            return false;
        }

        try {
            $htmlMessage = $this->buildEmailTemplate($template, [
                'subject' => $subject,
                'message' => $message,
                'request_id' => $requestId,
                'site_name' => SITE_NAME,
                'site_url' => SITE_URL
            ]);

            return $this->sendEmail($to, $subject, $htmlMessage);
        } catch (Exception $e) {
            error_log("Email notification error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ส่งอีเมลต้อนรับนักศึกษาใหม่
     */
    public function sendWelcomeEmail($studentEmail, $studentName, $studentId)
    {
        $subject = "ยินดีต้อนรับสู่ระบบข้อร้องเรียน - " . SITE_SHORT_NAME;
        $message = "สวัสดี คุณ{$studentName}\n\n";
        $message .= "ยินดีต้อนรับสู่ระบบข้อร้องเรียนนักศึกษา มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน\n\n";
        $message .= "รหัสนักศึกษาของคุณ: {$studentId}\n";
        $message .= "คุณสามารถเข้าใช้งานระบบได้ที่: " . SITE_URL . "\n\n";
        $message .= "คุณสามารถ:\n";
        $message .= "• ส่งข้อร้องเรียนและข้อเสนอแนะ\n";
        $message .= "• ติดตามสถานะข้อร้องเรียน\n";
        $message .= "• ประเมินความพึงพอใจ\n";
        $message .= "• รับการแจ้งเตือนอัตโนมัติ\n\n";
        $message .= "หากมีปัญหาการใช้งาน กรุณาติดต่อ: " . ADMIN_EMAIL;

        return $this->sendNotificationEmail($studentEmail, $subject, $message, null, 'welcome');
    }

    /**
     * ส่งอีเมลแจ้งเตือนข้อร้องเรียนใหม่ให้เจ้าหน้าที่
     */
    public function sendNewComplaintNotification($staffEmail, $staffName, $requestId, $requestTitle, $requestType, $priority)
    {
        $subject = "มีข้อร้องเรียนใหม่ที่ต้องดำเนินการ - #{$requestId}";
        $message = "สวัสดี คุณ{$staffName}\n\n";
        $message .= "มีข้อร้องเรียนใหม่ที่ต้องการการตรวจสอบ:\n\n";
        $message .= "รหัสข้อร้องเรียน: #{$requestId}\n";
        $message .= "หัวเรื่อง: {$requestTitle}\n";
        $message .= "ประเภท: {$requestType}\n";
        $message .= "ระดับความสำคัญ: {$priority}\n";
        $message .= "วันที่ส่ง: " . date('d/m/Y H:i') . "\n\n";
        $message .= "กรุณาเข้าระบบเพื่อดำเนินการ: " . SITE_URL . "staff/\n\n";
        $message .= "หมายเหตุ: กรุณาดำเนินการภายใน 72 ชั่วโมง";

        return $this->sendNotificationEmail($staffEmail, $subject, $message, $requestId, 'staff_notification');
    }

    /**
     * ส่งอีเมลแจ้งเปลี่ยนสถานะให้นักศึกษา
     */
    public function sendStatusUpdateNotification($studentEmail, $studentName, $requestId, $oldStatus, $newStatus, $note = '')
    {
        $statusTexts = [
            '0' => 'ยื่นคำร้อง',
            '1' => 'กำลังดำเนินการ',
            '2' => 'รอการประเมินผล',
            '3' => 'เสร็จสิ้น',
            '4' => 'ปฏิเสธคำร้อง'
        ];

        $subject = "อัปเดตสถานะข้อร้องเรียน #{$requestId}";
        $message = "สวัสดี คุณ{$studentName}\n\n";
        $message .= "ข้อร้องเรียน #{$requestId} ของคุณมีการอัปเดตสถานะ:\n\n";
        $message .= "สถานะเดิม: {$statusTexts[$oldStatus]}\n";
        $message .= "สถานะใหม่: {$statusTexts[$newStatus]}\n";

        if ($note) {
            $message .= "หมายเหตุ: {$note}\n";
        }

        $message .= "\nคุณสามารถติดตามรายละเอียดได้ที่: " . SITE_URL . "students/tracking.php\n\n";

        if ($newStatus == '2') {
            $message .= "ข้อร้องเรียนของคุณได้รับการแก้ไขเสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจ";
        }

        return $this->sendNotificationEmail($studentEmail, $subject, $message, $requestId, 'status_update');
    }

    /**
     * ส่งอีเมลเตือนข้อร้องเรียนค้างการตอบ
     */
    public function sendOverdueReminder($staffEmail, $staffName, $overdueRequests)
    {
        $subject = "เตือนความจำ: ข้อร้องเรียนค้างการตอบ (" . count($overdueRequests) . " รายการ)";
        $message = "สวัสดี คุณ{$staffName}\n\n";
        $message .= "คุณมีข้อร้องเรียนที่ค้างการตอบ " . count($overdueRequests) . " รายการ:\n\n";

        foreach ($overdueRequests as $request) {
            $hoursOverdue = round($request['hours_overdue'], 1);
            $message .= "• #{$request['Re_id']} - {$request['Re_title']} (ค้าง {$hoursOverdue} ชั่วโมง)\n";
        }

        $message .= "\nกรุณาเข้าระบบเพื่อดำเนินการ: " . SITE_URL . "staff/\n\n";
        $message .= "หมายเหตุ: ข้อร้องเรียนที่ค้างเกิน 72 ชั่วโมงจะส่งผลต่อการประเมินประสิทธิภาพ";

        return $this->sendNotificationEmail($staffEmail, $subject, $message, null, 'overdue_reminder');
    }

    /**
     * ส่งอีเมลรายงานสรุปประจำวัน
     */
    public function sendDailyReport($adminEmail, $adminName, $reportData)
    {
        $subject = "รายงานสรุปประจำวัน - " . date('d/m/Y');
        $message = "สวัสดี คุณ{$adminName}\n\n";
        $message .= "รายงานสรุปการใช้งานระบบประจำวัน:\n\n";
        $message .= "📊 สถิติวันนี้:\n";
        $message .= "• ข้อร้องเรียนใหม่: {$reportData['new_requests']} รายการ\n";
        $message .= "• ข้อร้องเรียนที่ตอบแล้ว: {$reportData['replied_requests']} รายการ\n";
        $message .= "• ข้อร้องเรียนที่เสร็จสิ้น: {$reportData['completed_requests']} รายการ\n";
        $message .= "• การลงทะเบียนใหม่: {$reportData['new_registrations']} คน\n\n";
        $message .= "⚠️ ข้อร้องเรียนค้างตอบ: {$reportData['pending_requests']} รายการ\n";
        $message .= "🚨 ข้อร้องเรียนเร่งด่วน: {$reportData['urgent_requests']} รายการ\n\n";
        $message .= "📈 เวลาตอบสนองเฉลี่ย: {$reportData['avg_response_time']} ชั่วโมง\n";
        $message .= "⭐ คะแนนความพึงพอใจเฉลี่ย: {$reportData['avg_satisfaction']}/5\n\n";
        $message .= "ดูรายงานเต็ม: " . SITE_URL . "staff/reports.php";

        return $this->sendNotificationEmail($adminEmail, $subject, $message, null, 'daily_report');
    }

    /**
     * สร้าง HTML Template สำหรับอีเมล
     */
    private function buildEmailTemplate($template, $data)
    {
        $logoUrl = SITE_URL . 'assets/images/logo.png';
        $primaryColor = '#667eea';
        $backgroundColor = '#f8f9fa';

        $html = '<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($data['subject']) . '</title>
    <style>
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: ' . $backgroundColor . '; }
        .container { max-width: 600px; margin: 0 auto; background: white; }
        .header { background: linear-gradient(135deg, ' . $primaryColor . ' 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 24px; }
        .content { padding: 30px; }
        .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        .btn { background: ' . $primaryColor . '; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 10px 0; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 5px; }
        .alert-info { background: #d1ecf1; color: #0c5460; border-left: 4px solid #17a2b8; }
        .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎓 ' . htmlspecialchars($data['site_name']) . '</h1>
        </div>
        <div class="content">';

        // เนื้อหาตาม template
        switch ($template) {
            case 'welcome':
                $html .= $this->getWelcomeEmailContent($data);
                break;
            case 'staff_notification':
                $html .= $this->getStaffNotificationContent($data);
                break;
            case 'status_update':
                $html .= $this->getStatusUpdateContent($data);
                break;
            case 'overdue_reminder':
                $html .= $this->getOverdueReminderContent($data);
                break;
            case 'daily_report':
                $html .= $this->getDailyReportContent($data);
                break;
            default:
                $html .= '<div class="alert alert-info">' . nl2br(htmlspecialchars($data['message'])) . '</div>';
        }

        $html .= '</div>
        <div class="footer">
            <p>อีเมลนี้ส่งโดยระบบอัตโนมัติ กรุณาอย่าตอบกลับ</p>
            <p>© ' . date('Y') . ' มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน | <a href="' . htmlspecialchars($data['site_url']) . '">เข้าใช้งานระบบ</a></p>
        </div>
    </div>
</body>
</html>';

        return $html;
    }

    private function getWelcomeEmailContent($data)
    {
        return '
            <h2>🎉 ยินดีต้อนรับ!</h2>
            <div class="alert alert-success">
                <strong>การลงทะเบียนสำเร็จ!</strong> คุณสามารถใช้งานระบบข้อร้องเรียนได้แล้ว
            </div>
            <p>' . nl2br(htmlspecialchars($data['message'])) . '</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($data['site_url']) . 'students/" class="btn">🚀 เริ่มใช้งานระบบ</a>
            </div>
        ';
    }

    private function getStaffNotificationContent($data)
    {
        return '
            <h2>🔔 มีข้อร้องเรียนใหม่</h2>
            <div class="alert alert-warning">
                <strong>ต้องการการดำเนินการ!</strong> มีข้อร้องเรียนใหม่ที่รอการตรวจสอบ
            </div>
            <p>' . nl2br(htmlspecialchars($data['message'])) . '</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($data['site_url']) . 'staff/manage-complaints.php?id=' . $data['request_id'] . '" class="btn">👁️ ดูรายละเอียด</a>
            </div>
        ';
    }

    private function getStatusUpdateContent($data)
    {
        return '
            <h2>📢 อัปเดตสถานะข้อร้องเรียน</h2>
            <div class="alert alert-info">
                <strong>มีการอัปเดต!</strong> ข้อร้องเรียนของคุณมีการเปลี่ยนแปลงสถานะ
            </div>
            <p>' . nl2br(htmlspecialchars($data['message'])) . '</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($data['site_url']) . 'students/tracking.php" class="btn">📊 ติดตามสถานะ</a>
            </div>
        ';
    }

    private function getOverdueReminderContent($data)
    {
        return '
            <h2>⏰ เตือนความจำ</h2>
            <div class="alert alert-warning">
                <strong>ข้อร้องเรียนค้างตอบ!</strong> คุณมีข้อร้องเรียนที่ต้องดำเนินการ
            </div>
            <p>' . nl2br(htmlspecialchars($data['message'])) . '</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($data['site_url']) . 'staff/complaint-replies.php" class="btn">💬 ตอบกลับทันที</a>
            </div>
        ';
    }

    private function getDailyReportContent($data)
    {
        return '
            <h2>📊 รายงานสรุปประจำวัน</h2>
            <div class="alert alert-info">
                <strong>สรุปการใช้งานระบบ</strong> วันที่ ' . date('d/m/Y') . '
            </div>
            <p>' . nl2br(htmlspecialchars($data['message'])) . '</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($data['site_url']) . 'staff/reports.php" class="btn">📈 ดูรายงานเต็ม</a>
            </div>
        ';
    }

    /**
     * ส่งอีเมลหลัก (ใช้ PHP mail() function - สำหรับ development)
     */
    private function sendEmail($to, $subject, $htmlMessage)
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        // ในการใช้งานจริง ควรใช้ PHPMailer หรือ SwiftMailer
        if (function_exists('mail')) {
            return mail($to, $subject, $htmlMessage, implode("\r\n", $headers));
        }

        // สำหรับ development - บันทึกเป็นไฟล์
        return $this->saveEmailToFile($to, $subject, $htmlMessage);
    }

    /**
     * บันทึกอีเมลเป็นไฟล์ (สำหรับ development)
     */
    private function saveEmailToFile($to, $subject, $htmlMessage)
    {
        $emailDir = '../logs/emails/';
        if (!is_dir($emailDir)) {
            mkdir($emailDir, 0755, true);
        }

        $filename = date('Y-m-d_H-i-s') . '_' . md5($to . $subject) . '.html';
        $filepath = $emailDir . $filename;

        $content = "<!-- Email Details -->\n";
        $content .= "<!-- To: {$to} -->\n";
        $content .= "<!-- Subject: {$subject} -->\n";
        $content .= "<!-- Date: " . date('Y-m-d H:i:s') . " -->\n\n";
        $content .= $htmlMessage;

        $result = file_put_contents($filepath, $content);

        if ($result && defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("Email saved to file: {$filepath} (To: {$to}, Subject: {$subject})");
        }

        return $result !== false;
    }

    /**
     * ส่งอีเมลแบบ batch (หลายคนพร้อมกัน)
     */
    public function sendBatchEmails($recipients, $subject, $message, $template = 'default')
    {
        $successCount = 0;
        $totalCount = count($recipients);

        foreach ($recipients as $recipient) {
            $personalizedMessage = str_replace('{name}', $recipient['name'] ?? 'คุณ', $message);

            if ($this->sendNotificationEmail($recipient['email'], $subject, $personalizedMessage, null, $template)) {
                $successCount++;
            }

            // หน่วงเวลาเล็กน้อยเพื่อไม่ให้ส่งเร็วเกินไป
            usleep(100000); // 0.1 วินาที
        }

        return [
            'success' => $successCount,
            'total' => $totalCount,
            'failed' => $totalCount - $successCount
        ];
    }

    /**
     * ตรวจสอบการตั้งค่าอีเมล
     */
    public function testEmailConfiguration()
    {
        if (!$this->isEnabled) {
            return [
                'status' => false,
                'message' => 'Email system is disabled - missing SMTP configuration'
            ];
        }

        try {
            $testResult = $this->sendNotificationEmail(
                ADMIN_EMAIL,
                'ทดสอบระบบอีเมล - ' . date('Y-m-d H:i:s'),
                'นี่คืออีเมลทดสอบระบบ Email Notification ระบบทำงานปกติ',
                null,
                'default'
            );

            return [
                'status' => $testResult,
                'message' => $testResult ? 'Email system is working correctly' : 'Failed to send test email'
            ];
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Email test failed: ' . $e->getMessage()
            ];
        }
    }
}

// ฟังก์ชันช่วยเหลือ
function getEmailNotificationService()
{
    static $service = null;
    if ($service === null) {
        $service = new EmailNotification();
    }
    return $service;
}

/**
 * ส่งอีเมลแจ้งเตือนแบบง่าย
 */
function sendQuickNotification($to, $subject, $message, $requestId = null)
{
    $emailService = getEmailNotificationService();
    return $emailService->sendNotificationEmail($to, $subject, $message, $requestId);
}

/**
 * ส่งการแจ้งเตือนอัตโนมัติตามเหตุการณ์
 */
function sendAutoEmailNotification($event, $requestId, $recipientEmail, $additionalData = [])
{
    $emailService = getEmailNotificationService();

    switch ($event) {
        case 'student_registered':
            return $emailService->sendWelcomeEmail(
                $recipientEmail,
                $additionalData['name'] ?? '',
                $additionalData['student_id'] ?? ''
            );

        case 'new_complaint':
            return $emailService->sendNewComplaintNotification(
                $recipientEmail,
                $additionalData['staff_name'] ?? '',
                $requestId,
                $additionalData['request_title'] ?? '',
                $additionalData['request_type'] ?? '',
                $additionalData['priority'] ?? ''
            );

        case 'status_updated':
            return $emailService->sendStatusUpdateNotification(
                $recipientEmail,
                $additionalData['student_name'] ?? '',
                $requestId,
                $additionalData['old_status'] ?? '',
                $additionalData['new_status'] ?? '',
                $additionalData['note'] ?? ''
            );

        default:
            return false;
    }
}

/**
 * ส่งรายงานประจำวันให้ Admin
 */
function sendDailyReportEmail()
{
    try {
        $db = getDB();
        if (!$db) return false;

        // รวบรวมข้อมูลสำหรับรายงาน
        $reportData = [
            'new_requests' => $db->count('request', 'DATE(Re_date) = CURDATE()'),
            'replied_requests' => $db->count('request r JOIN save_request sr ON r.Re_id = sr.Re_id', 'DATE(sr.Sv_date) = CURDATE()'),
            'completed_requests' => $db->count('request', 'DATE(updated_at) = CURDATE() AND Re_status IN ("2", "3")'),
            'new_registrations' => $db->count('student', 'DATE(created_at) = CURDATE()'),
            'pending_requests' => $db->count('request', 'Re_status = "0"'),
            'urgent_requests' => $db->count('request', 'Re_level IN ("4", "5") AND Re_status IN ("0", "1")'),
            'avg_response_time' => round($db->fetch("SELECT AVG(TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date)) as avg_hours FROM request r JOIN save_request sr ON r.Re_id = sr.Re_id WHERE DATE(sr.Sv_date) = CURDATE()")['avg_hours'] ?? 0, 1),
            'avg_satisfaction' => round($db->fetch("SELECT AVG(Eva_score) as avg_score FROM evaluation WHERE DATE(created_at) = CURDATE()")['avg_score'] ?? 0, 1)
        ];

        // ดึงข้อมูล Admin
        $admins = $db->fetchAll("SELECT Aj_email, Aj_name FROM teacher WHERE Aj_per = 3 AND Aj_status = 1 AND Aj_email IS NOT NULL AND Aj_email != ''");

        $emailService = getEmailNotificationService();
        $successCount = 0;

        foreach ($admins as $admin) {
            if ($emailService->sendDailyReport($admin['Aj_email'], $admin['Aj_name'], $reportData)) {
                $successCount++;
            }
        }

        return $successCount > 0;
    } catch (Exception $e) {
        error_log("Daily report email error: " . $e->getMessage());
        return false;
    }
}

/**
 * ตรวจสอบและส่งการแจ้งเตือนข้อร้องเรียนค้างตอบ
 */
function sendOverdueNotifications()
{
    try {
        $db = getDB();
        if (!$db) return false;

        // หาข้อร้องเรียนที่ค้างเกิน 48 ชั่วโมง
        $overdueRequests = $db->fetchAll("
            SELECT r.Re_id, r.Re_title, r.Aj_id, 
                   TIMESTAMPDIFF(HOUR, r.Re_date, NOW()) as hours_overdue,
                   t.Aj_email, t.Aj_name
            FROM request r
            JOIN teacher t ON r.Aj_id = t.Aj_id
            WHERE r.Re_status = '0' 
              AND TIMESTAMPDIFF(HOUR, r.Re_date, NOW()) >= 48
              AND t.Aj_email IS NOT NULL AND t.Aj_email != ''
              AND r.Re_is_spam = 0
        ");

        if (empty($overdueRequests)) {
            return true; // ไม่มีข้อร้องเรียนค้าง
        }

        // จัดกลุ่มตามเจ้าหน้าที่
        $staffOverdue = [];
        foreach ($overdueRequests as $request) {
            $staffId = $request['Aj_id'];
            if (!isset($staffOverdue[$staffId])) {
                $staffOverdue[$staffId] = [
                    'email' => $request['Aj_email'],
                    'name' => $request['Aj_name'],
                    'requests' => []
                ];
            }
            $staffOverdue[$staffId]['requests'][] = $request;
        }

        $emailService = getEmailNotificationService();
        $successCount = 0;

        foreach ($staffOverdue as $staffData) {
            if ($emailService->sendOverdueReminder($staffData['email'], $staffData['name'], $staffData['requests'])) {
                $successCount++;
            }
        }

        return $successCount > 0;
    } catch (Exception $e) {
        error_log("Overdue notifications error: " . $e->getMessage());
        return false;
    }
}

// Auto-run functions (ถ้าเรียกใช้ผ่าน cron job)
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    switch ($argv[1]) {
        case 'daily_report':
            sendDailyReportEmail();
            break;
        case 'overdue_check':
            sendOverdueNotifications();
            break;
        case 'test_email':
            $service = getEmailNotificationService();
            $result = $service->testEmailConfiguration();
            echo json_encode($result);
            break;
    }
}
