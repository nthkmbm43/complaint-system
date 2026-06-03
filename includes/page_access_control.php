<?php

/**
 * Page Access Control System - Enhanced Version
 * ระบบควบคุมการเข้าถึงหน้าต่างๆ ตามสิทธิ์ผู้ใช้
 * 
 * วิธีใช้: เรียกใช้ที่ด้านบนของแต่ละหน้า PHP
 * เช่น: checkPageAccess('student'); หรือ checkPageAccess('teacher', 2);
 */

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Include required files if not already included
if (!function_exists('isLoggedIn')) {
    require_once 'auth.php';
}

/**
 * ตรวจสอบสิทธิ์การเข้าถึงหน้า - Enhanced Version
 * 
 * @param string $requiredRole บทบาทที่ต้องการ ('student', 'teacher', 'admin')
 * @param int $requiredPermission ระดับสิทธิ์ที่ต้องการ (1=เจ้าหน้าที่, 2=หัวหน้างาน, 3=ผู้ดูแลระบบ)
 * @param string $customRedirect URL สำหรับ redirect กรณีไม่มีสิทธิ์
 * @param bool $showDebug แสดงข้อมูล debug หรือไม่
 */
function checkPageAccess($requiredRole = null, $requiredPermission = 0, $customRedirect = null, $showDebug = false)
{
    // เปิด Debug mode ถ้าต้องการ และไม่ได้ส่ง output ไปแล้ว
    $debugMode = $showDebug || (defined('DEBUG_MODE') && DEBUG_MODE) || isset($_GET['debug']);

    // ตรวจสอบว่าควรแสดง debug หรือไม่ (เฉพาะเมื่อไม่มีการ redirect)
    $canShowDebug = $debugMode && !headers_sent();

    if ($canShowDebug && $debugMode) {
        // เก็บข้อมูล debug ไว้ใน buffer แทนการแสดงทันที
        ob_start();
    }

    // ตรวจสอบการเข้าสู่ระบบ
    if (!isLoggedIn()) {
        if ($canShowDebug) {
            ob_end_clean(); // ล้าง buffer
        }
        handleUnauthorizedAccess('not_logged_in', $customRedirect, $requiredRole, $requiredPermission, false);
        return;
    }

    $currentRole = $_SESSION['user_role'] ?? '';
    $currentPermission = $_SESSION['permission'] ?? 0;
    $userId = $_SESSION['user_id'] ?? 'unknown';

    // บันทึกข้อมูล debug ลง session แทนการแสดงทันที
    if ($debugMode) {
        $_SESSION['debug_info'] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'current_page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'required_role' => $requiredRole ?? 'none',
            'required_permission' => $requiredPermission,
            'user_id' => $userId,
            'current_role' => $currentRole,
            'current_permission' => $currentPermission,
            'logged_in' => true
        ];
    }

    // Log การพยายามเข้าถึง
    logPageAccess($userId, $currentRole, $requiredRole, $requiredPermission);

    // ตรวจสอบบทบาท
    if ($requiredRole && !hasRequiredRole($currentRole, $requiredRole)) {
        if ($canShowDebug) {
            ob_end_clean(); // ล้าง buffer
        }
        if ($debugMode) {
            $_SESSION['debug_info']['result'] = 'DENIED: Insufficient role';
            $_SESSION['debug_info']['reason'] = "Current: {$currentRole}, Required: {$requiredRole}";
        }
        handleUnauthorizedAccess('insufficient_role', $customRedirect, $requiredRole, $requiredPermission, false);
        return;
    }

    // ตรวจสอบระดับสิทธิ์สำหรับ teacher
    if ($currentRole === 'teacher' && $requiredPermission > 0 && $currentPermission < $requiredPermission) {
        if ($canShowDebug) {
            ob_end_clean(); // ล้าง buffer
        }
        if ($debugMode) {
            $_SESSION['debug_info']['result'] = 'DENIED: Insufficient permission';
            $_SESSION['debug_info']['reason'] = "Current: {$currentPermission}, Required: {$requiredPermission}";
        }
        handleUnauthorizedAccess('insufficient_permission', $customRedirect, $requiredRole, $requiredPermission, false);
        return;
    }

    // การเข้าถึงได้รับอนุญาต
    if ($debugMode) {
        $_SESSION['debug_info']['result'] = 'GRANTED: Access allowed';
    }

    if ($canShowDebug) {
        ob_end_clean(); // ล้าง buffer เพราะไม่ต้อง redirect
        showDebugInfo(); // แสดงข้อมูล debug
    }

    // บันทึก log การเข้าถึงสำเร็จ
    logSuccessfulAccess($userId, $currentRole);
}

/**
 * ตรวจสอบว่าผู้ใช้มีบทบาทที่ต้องการหรือไม่
 */
function hasRequiredRole($currentRole, $requiredRole)
{
    // กรณีพิเศษ: admin สามารถเข้าถึงหน้า teacher ได้
    if ($currentRole === 'teacher' && isset($_SESSION['permission']) && $_SESSION['permission'] == 3) {
        if ($requiredRole === 'admin' || $requiredRole === 'teacher') {
            return true;
        }
    }

    return $currentRole === $requiredRole;
}

/**
 * แสดงข้อมูล Debug ที่เก็บไว้ใน Session
 */
function showDebugInfo()
{
    if (isset($_SESSION['debug_info'])) {
        $debug = $_SESSION['debug_info'];

        echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc; font-family: monospace;'>";
        echo "<h4>🔍 DEBUG: Page Access Control</h4>";
        echo "Timestamp: " . $debug['timestamp'] . "<br>";
        echo "Current Page: " . $debug['current_page'] . "<br>";
        echo "Required Role: " . $debug['required_role'] . "<br>";
        echo "Required Permission: " . $debug['required_permission'] . "<br>";

        if ($debug['logged_in']) {
            echo "<span style='color: green;'>✅ User is logged in</span><br>";
            echo "User ID: " . $debug['user_id'] . "<br>";
            echo "Current Role: " . $debug['current_role'] . "<br>";
            echo "Current Permission: " . $debug['current_permission'] . "<br>";
        } else {
            echo "<span style='color: red;'>❌ Not logged in</span><br>";
        }

        if (isset($debug['result'])) {
            $color = strpos($debug['result'], 'GRANTED') !== false ? 'green' : 'red';
            echo "<span style='color: {$color};'>" . $debug['result'] . "</span><br>";

            if (isset($debug['reason'])) {
                echo $debug['reason'] . "<br>";
            }
        }

        echo "</div>";

        // ล้างข้อมูล debug หลังแสดงแล้ว
        unset($_SESSION['debug_info']);
    }
}

/**
 * จัดการกรณีไม่มีสิทธิ์เข้าถึง - Fixed Version
 */
function handleUnauthorizedAccess($reason, $customRedirect = null, $requiredRole = null, $requiredPermission = 0, $showDebugPage = false)
{
    $currentRole = $_SESSION['user_role'] ?? '';
    $userId = $_SESSION['user_id'] ?? 'unknown';

    // บันทึก log การเข้าถึงไม่ได้รับอนุญาต
    logUnauthorizedAccess($userId, $currentRole, $reason, $requiredRole, $requiredPermission);

    // ถ้าเป็นโหมด debug และต้องการแสดงหน้า debug
    if ($showDebugPage && isset($_GET['debug'])) {
        showDebugDeniedPage($reason, $requiredRole, $requiredPermission);
        return;
    }

    // กำหนด redirect URL
    $redirectUrl = determineRedirectUrl($reason, $currentRole, $customRedirect, false);

    // กำหนดข้อความแจ้งเตือน
    $message = getAccessDeniedMessage($reason, $requiredRole, $requiredPermission);

    // เพิ่มข้อมูล debug ใน session
    if (isset($_GET['debug'])) {
        $_SESSION['debug_redirect'] = [
            'reason' => $reason,
            'message' => $message,
            'from_page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'redirect_to' => $redirectUrl,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    // Redirect พร้อมข้อความ (ปกติ)
    if (strpos($redirectUrl, '?') !== false) {
        $redirectUrl .= '&message=' . urlencode($message) . '&reason=' . $reason;
    } else {
        $redirectUrl .= '?message=' . urlencode($message) . '&reason=' . $reason;
    }

    // เพิ่ม debug parameter ถ้ามี
    if (isset($_GET['debug'])) {
        $redirectUrl .= '&debug=1';
    }

    header("Location: $redirectUrl");
    exit;
}

/**
 * แสดงหน้า Debug สำหรับกรณีถูกปฏิเสธ
 */
function showDebugDeniedPage($reason, $requiredRole, $requiredPermission)
{
    $currentRole = $_SESSION['user_role'] ?? '';
    $userId = $_SESSION['user_id'] ?? 'unknown';
    $message = getAccessDeniedMessage($reason, $requiredRole, $requiredPermission);
    $redirectUrl = determineRedirectUrl($reason, $currentRole, null, true);

    echo "<!DOCTYPE html><html><head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Access Denied - Debug</title>
    <style>
        /* Global Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head><body>";
    echo "<div style='font-family: Arial; padding: 20px;'>";
    echo "<h2>🚫 Access Denied - Debug Mode</h2>";

    echo "<div style='background: #f8d7da; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<h3>Debug Information:</h3>";
    echo "User ID: {$userId}<br>";
    echo "Current Role: {$currentRole}<br>";
    echo "Required Role: " . ($requiredRole ?? 'none') . "<br>";
    echo "Required Permission: {$requiredPermission}<br>";
    echo "Reason: {$reason}<br>";
    echo "Message: {$message}<br>";
    echo "Redirect URL: {$redirectUrl}<br>";
    echo "</div>";

    echo "<div style='background: #d1ecf1; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "<p>This page will redirect automatically in 5 seconds...</p>";
    echo "<a href='{$redirectUrl}?message=" . urlencode($message) . "&reason={$reason}' style='color: blue;'>Click here to redirect now</a>";
    echo "</div>";

    echo "</div>";
    echo "<script>setTimeout(function() { window.location.href = '{$redirectUrl}?message=" . urlencode($message) . "&reason={$reason}'; }, 5000);</script>";
    echo "</body></html>";
    exit;
}

/**
 * กำหนด URL สำหรับ redirect - Enhanced Version
 */
function determineRedirectUrl($reason, $currentRole, $customRedirect, $debugMode = false)
{
    if ($customRedirect) {
        return $customRedirect;
    }

    $currentPath = $_SERVER['REQUEST_URI'] ?? '';
    $currentDir = dirname($_SERVER['SCRIPT_NAME']);

    if ($debugMode) {
        echo "Current Path: {$currentPath}<br>";
        echo "Current Dir: {$currentDir}<br>";
    }

    switch ($reason) {
        case 'not_logged_in':
            // ยังไม่ได้เข้าสู่ระบบ - ไปหน้า login ของนักศึกษาเป็นค่าเริ่มต้น
            if (strpos($currentPath, '/staff/') !== false) {
                return '../staff/login.php?redirect=' . urlencode($currentPath);
            } elseif (strpos($currentPath, '/students/') !== false) {
                return '../studentslogin.php?redirect=' . urlencode($currentPath);
            } else {
                // หน้าหลัก - ไปหน้า login ของนักศึกษา
                return 'students/login.php?redirect=' . urlencode($currentPath);
            }

        case 'insufficient_role':
        case 'insufficient_permission':
            // มีสิทธิ์แต่ไม่เพียงพอ - กลับไปหน้าหลักของตัวเอง
            switch ($currentRole) {
                case 'student':
                    if (strpos($currentDir, '/students') !== false) {
                        return 'index.php';
                    }
                    return '../students/index.php';

                case 'teacher':
                    if (strpos($currentDir, '/staff') !== false) {
                        return 'index.php';
                    }
                    return '../staff/index.php';

                default:
                    return '../index.php';
            }

        default:
            return '../index.php';
    }
}

/**
 * สร้างข้อความแจ้งเตือนตามสาเหตุ - Enhanced Version
 */
function getAccessDeniedMessage($reason, $requiredRole = null, $requiredPermission = 0)
{
    switch ($reason) {
        case 'not_logged_in':
            return 'กรุณาเข้าสู่ระบบก่อนใช้งาน';

        case 'insufficient_role':
            $roleNames = [
                'student' => 'นักศึกษา',
                'teacher' => 'เจ้าหน้าที่',
                'admin' => 'ผู้ดูแลระบบ'
            ];
            $roleName = $roleNames[$requiredRole] ?? $requiredRole;
            return "หน้านี้สำหรับ{$roleName}เท่านั้น คุณไม่มีสิทธิ์เข้าถึง";

        case 'insufficient_permission':
            $permissionNames = [
                1 => 'อาจารย์/เจ้าหน้าที่',
                2 => 'ผู้ดำเนินการ',
                3 => 'ผู้ดูแลระบบ'
            ];
            $permissionName = $permissionNames[$requiredPermission] ?? "ระดับ {$requiredPermission}";
            return "ต้องการสิทธิ์ระดับ{$permissionName}ขึ้นไป คุณไม่มีสิทธิ์เพียงพอ";

        default:
            return 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้';
    }
}

/**
 * เพิ่มฟังก์ชันสำหรับทดสอบ Debug แบบไม่มี Header Warning
 */
function debugPageAccess($requiredRole = null, $requiredPermission = 0)
{
    echo "<div style='background: #e6f3ff; padding: 15px; margin: 10px; border: 2px solid #0066cc; font-family: Arial;'>";
    echo "<h3>🛠️ DEBUG: Page Access Testing</h3>";

    // แสดงข้อมูลปัจจุบัน
    echo "<strong>Current Session Info:</strong><br>";
    echo "Logged in: " . (isLoggedIn() ? '✅ Yes' : '❌ No') . "<br>";
    if (isLoggedIn()) {
        echo "User ID: " . ($_SESSION['user_id'] ?? 'N/A') . "<br>";
        echo "Role: " . ($_SESSION['user_role'] ?? 'N/A') . "<br>";
        echo "Permission: " . ($_SESSION['permission'] ?? 'N/A') . "<br>";
        echo "Name: " . ($_SESSION['user_name'] ?? 'N/A') . "<br>";
    }

    echo "<br><strong>Required Access:</strong><br>";
    echo "Required Role: " . ($requiredRole ?? 'Any') . "<br>";
    echo "Required Permission: " . $requiredPermission . "<br>";

    echo "<br><strong>Access Test Result:</strong><br>";

    // ทดสอบการเข้าถึงโดยไม่ redirect
    if (!isLoggedIn()) {
        echo "<span style='color: red; font-weight: bold;'>❌ DENIED: Not logged in</span><br>";
        echo "<span style='color: orange;'>→ จะ redirect ไป login page</span><br>";
    } elseif ($requiredRole && !hasRequiredRole($_SESSION['user_role'] ?? '', $requiredRole)) {
        echo "<span style='color: red; font-weight: bold;'>❌ DENIED: Insufficient role</span><br>";
        echo "<span style='color: orange;'>→ Current: " . ($_SESSION['user_role'] ?? 'N/A') . ", Required: {$requiredRole}</span><br>";
        echo "<span style='color: orange;'>→ จะ redirect กลับหน้าหลักของ " . ($_SESSION['user_role'] ?? 'user') . "</span><br>";
    } elseif ($_SESSION['user_role'] === 'teacher' && $requiredPermission > 0 && ($_SESSION['permission'] ?? 0) < $requiredPermission) {
        echo "<span style='color: red; font-weight: bold;'>❌ DENIED: Insufficient permission</span><br>";
        echo "<span style='color: orange;'>→ Current: " . ($_SESSION['permission'] ?? 0) . ", Required: {$requiredPermission}</span><br>";
        echo "<span style='color: orange;'>→ จะ redirect กลับหน้าหลัก staff</span><br>";
    } else {
        echo "<span style='color: green; font-weight: bold;'>✅ GRANTED: Access allowed</span><br>";
        echo "<span style='color: green;'>→ สามารถเข้าถึงหน้านี้ได้</span><br>";
    }

    // แสดงข้อมูล redirect debug ถ้ามี
    if (isset($_SESSION['debug_redirect'])) {
        echo "<br><strong>Last Redirect Info:</strong><br>";
        $debug = $_SESSION['debug_redirect'];
        echo "From: " . $debug['from_page'] . "<br>";
        echo "To: " . $debug['redirect_to'] . "<br>";
        echo "Reason: " . $debug['reason'] . "<br>";
        echo "Message: " . $debug['message'] . "<br>";
        echo "Time: " . $debug['timestamp'] . "<br>";
        unset($_SESSION['debug_redirect']);
    }

    echo "</div>";

    // เพิ่มปุ่มสำหรับทดสอบ
    echo "<div style='margin: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;'>";
    echo "<button onclick='location.reload()' style='padding: 5px 10px; margin: 5px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer;'>🔄 Refresh Test</button>";
    echo "<button onclick='clearDebug()' style='padding: 5px 10px; margin: 5px; background: #6c757d; color: white; border: none; border-radius: 3px; cursor: pointer;'>🗑️ Clear Debug</button>";
    echo "<button onclick='testWithoutDebug()' style='padding: 5px 10px; margin: 5px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;'>🎯 Test Real Access</button>";
    echo "</div>";

    echo "<script>
        function clearDebug() {
            const url = new URL(window.location);
            url.searchParams.delete('debug');
            window.location = url;
        }
        
        function testWithoutDebug() {
            const url = new URL(window.location);
            url.searchParams.delete('debug');
            url.searchParams.set('test_real', '1');
            window.location = url;
        }
    </script>";
}

// เหลือฟังก์ชันอื่นๆ เหมือนเดิม...
function logPageAccess($userId, $currentRole, $requiredRole, $requiredPermission)
{
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'current_role' => $currentRole,
        'required_role' => $requiredRole,
        'required_permission' => $requiredPermission,
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    writeAccessLog('page_access_attempt', $logData);
}

function logSuccessfulAccess($userId, $currentRole)
{
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'role' => $currentRole,
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];

    writeAccessLog('page_access_success', $logData);
}

function logUnauthorizedAccess($userId, $currentRole, $reason, $requiredRole, $requiredPermission)
{
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $userId,
        'current_role' => $currentRole,
        'reason' => $reason,
        'required_role' => $requiredRole,
        'required_permission' => $requiredPermission,
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    writeAccessLog('page_access_denied', $logData);
}

function writeAccessLog($type, $data)
{
    try {
        $logDir = '../logs';
        if (!is_dir($logDir)) {
            $logDir = 'logs';
        }
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/page_access.log';
        $logEntry = json_encode(['type' => $type, 'data' => $data], JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to write access log: " . $e->getMessage());
    }
}

// เหลือฟังก์ชันอื่นๆ เหมือนเดิม... (checkSuspiciousIP, countRecentAccessAttempts, etc.)
function checkSuspiciousIP()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $userId = $_SESSION['user_id'] ?? 'guest';

    $recentAttempts = countRecentAccessAttempts($ip);

    if ($recentAttempts > 50) {
        logSuspiciousActivity($ip, $userId, 'high_frequency_access', $recentAttempts);
        return true;
    }

    return false;
}

function countRecentAccessAttempts($ip)
{
    try {
        $logDir = '../logs';
        if (!is_dir($logDir)) {
            $logDir = 'logs';
        }

        $logFile = $logDir . '/page_access.log';
        if (!file_exists($logFile)) {
            return 0;
        }

        $tenMinutesAgo = time() - 600;
        $count = 0;

        $handle = fopen($logFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if ($entry && isset($entry['data']['ip']) && $entry['data']['ip'] === $ip) {
                    $timestamp = strtotime($entry['data']['timestamp']);
                    if ($timestamp > $tenMinutesAgo) {
                        $count++;
                    }
                }
            }
            fclose($handle);
        }

        return $count;
    } catch (Exception $e) {
        error_log("Failed to count access attempts: " . $e->getMessage());
        return 0;
    }
}

function logSuspiciousActivity($ip, $userId, $type, $details)
{
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $ip,
        'user_id' => $userId,
        'type' => $type,
        'details' => $details,
        'page' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];

    try {
        $logDir = '../logs';
        if (!is_dir($logDir)) {
            $logDir = 'logs';
        }
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/suspicious_activity.log';
        $logEntry = json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
        error_log("Failed to log suspicious activity: " . $e->getMessage());
    }
}

function cleanupOldLogs($days = 30)
{
    try {
        $logDir = '../logs';
        if (!is_dir($logDir)) {
            $logDir = 'logs';
        }

        $cutoffTime = time() - ($days * 24 * 60 * 60);
        $logFiles = ['page_access.log', 'suspicious_activity.log'];

        foreach ($logFiles as $logFile) {
            $fullPath = $logDir . '/' . $logFile;
            if (file_exists($fullPath)) {
                cleanupLogFile($fullPath, $cutoffTime);
            }
        }
    } catch (Exception $e) {
        error_log("Failed to cleanup old logs: " . $e->getMessage());
    }
}

function cleanupLogFile($logFile, $cutoffTime)
{
    $tempFile = $logFile . '.tmp';
    $inputHandle = fopen($logFile, 'r');
    $outputHandle = fopen($tempFile, 'w');

    if ($inputHandle && $outputHandle) {
        while (($line = fgets($inputHandle)) !== false) {
            $entry = json_decode($line, true);
            if ($entry && isset($entry['data']['timestamp'])) {
                $timestamp = strtotime($entry['data']['timestamp']);
                if ($timestamp > $cutoffTime) {
                    fwrite($outputHandle, $line);
                }
            }
        }

        fclose($inputHandle);
        fclose($outputHandle);

        rename($tempFile, $logFile);
    }
}

function performSecurityCheck()
{
    if (checkSuspiciousIP()) {
        return false;
    }

    if (detectSessionHijacking()) {
        session_destroy();
        header("Location: ../index.php?message=" . urlencode("ตรวจพบกิจกรรมที่น่าสงสัย กรุณาเข้าสู่ระบบใหม่"));
        exit;
    }

    return true;
}

function detectSessionHijacking()
{
    if (isset($_SESSION['user_agent'])) {
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($_SESSION['user_agent'] !== $currentUserAgent) {
            logSuspiciousActivity(
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SESSION['user_id'] ?? 'unknown',
                'user_agent_change',
                [
                    'old_agent' => $_SESSION['user_agent'],
                    'new_agent' => $currentUserAgent
                ]
            );
            return true;
        }
    } else {
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    if (isset($_SESSION['user_ip'])) {
        $currentIP = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($_SESSION['user_ip'] !== $currentIP) {
            logSuspiciousActivity(
                $currentIP,
                $_SESSION['user_id'] ?? 'unknown',
                'ip_change',
                [
                    'old_ip' => $_SESSION['user_ip'],
                    'new_ip' => $currentIP
                ]
            );
            $_SESSION['user_ip'] = $currentIP;
        }
    } else {
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }

    return false;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    performSecurityCheck();
}

if (rand(1, 100) === 1) {
    cleanupOldLogs();
}
