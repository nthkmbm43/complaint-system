<?php
// แสดง Error ทั้งหมด
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>กำลังทดสอบการเชื่อมต่อฐานข้อมูล...</h2>";

// ค่าที่คุณตั้งไว้ใน config.php
$servername = "sql113.infinityfree.com";
$username = "if0_40673385";
$password = "CYCLWuk6XRQ7TEo";
$dbname = "if0_40673385_complaint_system";

// ลองเชื่อมต่อ
$conn = new mysqli($servername, $username, $password, $dbname);

// เช็คผลลัพธ์
if ($conn->connect_error) {
    echo "<h3 style='color:red'>❌ การเชื่อมต่อล้มเหลว!</h3>";
    echo "<b>สาเหตุ:</b> " . $conn->connect_error;
} else {
    echo "<h3 style='color:green'>✅ การเชื่อมต่อสำเร็จ!</h3>";
    echo "ฐานข้อมูล <b>$dbname</b> พร้อมใช้งาน";
}
?>