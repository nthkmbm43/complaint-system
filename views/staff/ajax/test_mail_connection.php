<?php
// staff/ajax/test_mail_connection.php
header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h2>📧 ทดสอบการเชื่อมต่อ SMTP...</h2>";

// 1. โหลด Config
if (!file_exists('../../../config/email_config.php')) die("❌ ไม่พบไฟล์ config");
require_once '../../../config/email_config.php';

// 2. โหลด PHPMailer (ตรวจสอบ Path ให้แม่นยำ)
$vendorPath = '../../../vendor/PHPMailer/src/'; // ชื่อตัวแปรที่ถูกต้อง

if (!file_exists($vendorPath . 'PHPMailer.php')) {
    die("❌ ไม่พบไฟล์ PHPMailer ที่: " . realpath($vendorPath));
}

// *** จุดที่แก้ไข: ใช้ $vendorPath ให้เหมือนกันทุกบรรทัด ***
require_once $vendorPath . 'Exception.php';
require_once $vendorPath . 'PHPMailer.php';
require_once $vendorPath . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    echo "<b>Current Config:</b><br>";
    echo "Host: " . SMTP_HOST . "<br>";
    echo "User: " . SMTP_USERNAME . "<br>";
    echo "Pass Check: " . (strlen(SMTP_PASSWORD) == 16 ? "✅ ความยาว 16 ตัวอักษร (ถูกต้อง)" : "❌ ความยาวผิดปกติ (" . strlen(SMTP_PASSWORD) . " ตัว)") . "<br><hr>";

    // ตั้งค่า Server
    $mail->SMTPDebug = 2;
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(SMTP_USERNAME, 'Test Sender');
    $mail->addAddress(SMTP_USERNAME); // ส่งหาตัวเอง

    $mail->isHTML(true);
    $mail->Subject = 'Test Email Connection Success';
    $mail->Body    = 'ทดสอบการตั้งค่า Config สำเร็จ! หากได้รับเมลนี้แสดงว่าระบบพร้อมใช้งาน';

    $mail->send();
    echo "<h3 style='color:green'>✅ ส่งสำเร็จ! (การตั้งค่าถูกต้องแล้ว)</h3>";
} catch (Exception $e) {
    echo "<h3 style='color:red'>❌ ส่งไม่ผ่าน</h3>";
    echo "<b>Error:</b> " . $mail->ErrorInfo;
}
