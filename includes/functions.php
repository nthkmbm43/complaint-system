<?php
if (!defined('SECURE_ACCESS')) {
    exit('Direct access not allowed');
}

/**
 * ฟังก์ชันทั่วไปสำหรับระบบข้อร้องเรียน - อัพเดตสำหรับฐานข้อมูลใหม่
 */

/**
 * ส่งอีเมลแจ้งเตือน
 */
if (!function_exists('sendEmailNotification')) {
    function sendEmailNotification($to, $subject, $body, $isHtml = true)
    {
        // ในระบบจริงจะใช้ PHPMailer หรือ Swift Mailer
        return true;
    }
}

/**
 * ตรวจสอบและปรับขนาดรูปภาพ
 */
if (!function_exists('resizeImage')) {
    function resizeImage($sourcePath, $destinationPath, $maxWidth = 800, $maxHeight = 600)
    {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $sourceWidth = $imageInfo[0];
        $sourceHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];

        // คำนวณขนาดใหม่
        $ratio = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $newWidth = (int)($sourceWidth * $ratio);
        $newHeight = (int)($sourceHeight * $ratio);

        // สร้างภาพต้นฉบับ
        switch ($mimeType) {
            case 'image/jpeg':
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $sourceImage = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $sourceImage = imagecreatefromgif($sourcePath);
                break;
            default:
                return false;
        }

        // สร้างภาพใหม่
        $destImage = imagecreatetruecolor($newWidth, $newHeight);

        // จัดการความโปร่งใสสำหรับ PNG และ GIF
        if ($mimeType == 'image/png' || $mimeType == 'image/gif') {
            imagealphablending($destImage, false);
            imagesavealpha($destImage, true);
            $transparent = imagecolorallocatealpha($destImage, 255, 255, 255, 127);
            imagefilledrectangle($destImage, 0, 0, $newWidth, $newHeight, $transparent);
        }

        // ปรับขนาดภาพ
        imagecopyresampled($destImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

        // บันทึกภาพ
        $result = false;
        switch ($mimeType) {
            case 'image/jpeg':
                $result = imagejpeg($destImage, $destinationPath, 85);
                break;
            case 'image/png':
                $result = imagepng($destImage, $destinationPath, 8);
                break;
            case 'image/gif':
                $result = imagegif($destImage, $destinationPath);
                break;
        }

        // ล้างหน่วยความจำ
        imagedestroy($sourceImage);
        imagedestroy($destImage);

        return $result;
    }
}

/**
 * อัพโหลดไฟล์แนบสำหรับข้อร้องเรียน (อัพเดตให้ใช้โครงสร้างใหม่)
 */
if (!function_exists('uploadRequestFile')) {
    function uploadRequestFile($file, $requestId, $studentId, $uploadDir = '../uploads/requests/')
    {
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedTypes = ALLOWED_FILE_TYPES;
        $maxFileSize = MAX_FILE_SIZE;

        // ตรวจสอบข้อผิดพลาด
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการอัพโหลดไฟล์'];
        }

        // ตรวจสอบขนาดไฟล์
        if ($file['size'] > $maxFileSize) {
            return ['success' => false, 'message' => 'ขนาดไฟล์เกินกำหนด (สูงสุด ' . formatFileSize($maxFileSize) . ')'];
        }

        // ตรวจสอบประเภทไฟล์
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedTypes)) {
            return ['success' => false, 'message' => 'ประเภทไฟล์ไม่รองรับ'];
        }

        // สร้างชื่อไฟล์ใหม่
        $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
        $newFileName = $requestId . '_' . time() . '_' . uniqid() . '.' . $fileExtension;
        $uploadPath = $uploadDir . $newFileName;

        // อัพโหลดไฟล์
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            // ถ้าเป็นรูปภาพให้ปรับขนาด
            if (in_array($fileExtension, ['jpg', 'jpeg', 'png', 'gif'])) {
                $resizedPath = $uploadDir . 'thumb_' . $newFileName;
                resizeImage($uploadPath, $resizedPath, 300, 300);
            }

            // บันทึกข้อมูลไฟล์ลงฐานข้อมูล
            $evidenceId = saveSupportingEvidence(
                $requestId,
                $originalName . '.' . $fileExtension,
                $uploadPath,
                $fileExtension,
                $file['size'],
                $studentId
            );

            if ($evidenceId) {
                return [
                    'success' => true,
                    'evidence_id' => $evidenceId,
                    'filename' => $newFileName,
                    'original_name' => $file['name'],
                    'path' => $uploadPath,
                    'size' => $file['size'],
                    'type' => $fileExtension
                ];
            } else {
                // ลบไฟล์ถ้าบันทึกฐานข้อมูลไม่สำเร็จ
                unlink($uploadPath);
                return ['success' => false, 'message' => 'ไม่สามารถบันทึกข้อมูลไฟล์ได้'];
            }
        } else {
            return ['success' => false, 'message' => 'ไม่สามารถอัพโหลดไฟล์ได้'];
        }
    }
}

/**
 * ลบไฟล์แนบ
 */
if (!function_exists('deleteFile')) {
    function deleteFile($filePath)
    {
        if (file_exists($filePath)) {
            unlink($filePath);

            // ลบ thumbnail ถ้ามี
            $thumbPath = dirname($filePath) . '/thumb_' . basename($filePath);
            if (file_exists($thumbPath)) {
                unlink($thumbPath);
            }

            return true;
        }
        return false;
    }
}

/**
 * ดึงไฟล์แนบของข้อร้องเรียน
 */
if (!function_exists('getRequestFiles')) {
    function getRequestFiles($requestId)
    {
        return getSupportingEvidence($requestId);
    }
}

/**
 * เพิ่มการตอบกลับข้อร้องเรียน (อัพเดตสำหรับฐานข้อมูลใหม่)
 */
if (!function_exists('addRequestReply')) {
    function addRequestReply($requestId, $teacherId, $message, $type = 'process', $note = '')
    {
        return saveStaffResponse($requestId, $teacherId, $message, $type, $note);
    }
}

/**
 * ดึงสถิติข้อร้องเรียน
 */
if (!function_exists('getComplaintStats')) {
    function getComplaintStats($userId = null, $role = 'student')
    {
        if ($role === 'student' && $userId) {
            return getStudentRequestStats($userId);
        } elseif (in_array($role, ['teacher', 'staff', 'admin'])) {
            return getStaffDashboardStats();
        }

        return ['total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'evaluated' => 0, 'avg_rating' => 0];
    }
}

/**
 * ดึงข้อร้องเรียนตามเงื่อนไข (อัพเดต)
 */
if (!function_exists('getRequestsList')) {
    function getRequestsList($filters = [], $limit = 10, $offset = 0)
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $whereConditions = ['r.Re_is_spam = 0']; // ไม่แสดง spam
            $params = [];

            // สร้างเงื่อนไขการค้นหา
            if (!empty($filters['student_id'])) {
                $whereConditions[] = 'r.Stu_id = ?';
                $params[] = $filters['student_id'];
            }

            if (!empty($filters['status'])) {
                $whereConditions[] = 'r.Re_status = ?';
                $params[] = $filters['status'];
            }

            if (!empty($filters['type'])) {
                $whereConditions[] = 'r.Type_id = ?';
                $params[] = $filters['type'];
            }

            if (!empty($filters['level'])) {
                $whereConditions[] = 'r.Re_level = ?';
                $params[] = $filters['level'];
            }

            if (!empty($filters['search'])) {
                $whereConditions[] = '(r.Re_infor LIKE ? OR r.Re_title LIKE ? OR s.Stu_name LIKE ?)';
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($filters['assigned_to'])) {
                $whereConditions[] = 'r.Aj_id = ?';
                $params[] = $filters['assigned_to'];
            }

            $whereClause = implode(' AND ', $whereConditions);

            // อัพเดตให้ใช้ organization_unit
            $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                           CASE r.Re_iden 
                               WHEN 1 THEN 'ไม่ระบุตัวตน' 
                               ELSE s.Stu_name 
                           END as requester_name,
                           s.Stu_id, 
                           major.Unit_name as major_name,
                           faculty.Unit_name as faculty_name,
                           asn.Aj_name as assigned_name
                    FROM request r
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN student s ON r.Stu_id = s.Stu_id
                    LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                    LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                    LEFT JOIN teacher asn ON r.Aj_id = asn.Aj_id
                    WHERE {$whereClause}
                    ORDER BY r.Re_level DESC, r.Re_date DESC, r.Re_id DESC
                    LIMIT ? OFFSET ?";

            $params[] = $limit;
            $params[] = $offset;

            return $db->fetchAll($sql, $params);
        } catch (Exception $e) {
            error_log("getRequestsList error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * นับจำนวนข้อร้องเรียนตามเงื่อนไข
 */
if (!function_exists('countRequests')) {
    function countRequests($filters = [])
    {
        $db = getDB();
        if (!$db) return 0;

        try {
            $whereConditions = ['Re_is_spam = 0']; // ไม่นับ spam
            $params = [];

            // สร้างเงื่อนไขการค้นหาเหมือนกับ getRequestsList
            if (!empty($filters['student_id'])) {
                $whereConditions[] = 'Stu_id = ?';
                $params[] = $filters['student_id'];
            }

            if (!empty($filters['status'])) {
                $whereConditions[] = 'Re_status = ?';
                $params[] = $filters['status'];
            }

            if (!empty($filters['type'])) {
                $whereConditions[] = 'Type_id = ?';
                $params[] = $filters['type'];
            }

            if (!empty($filters['level'])) {
                $whereConditions[] = 'Re_level = ?';
                $params[] = $filters['level'];
            }

            if (!empty($filters['search'])) {
                // ต้องใช้ JOIN สำหรับการค้นหา
                $sql = "SELECT COUNT(*) as count 
                        FROM request r
                        LEFT JOIN student s ON r.Stu_id = s.Stu_id
                        WHERE " . implode(' AND ', $whereConditions) . " 
                        AND (r.Re_infor LIKE ? OR r.Re_title LIKE ? OR s.Stu_name LIKE ?)";
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;

                $result = $db->fetch($sql, $params);
                return $result['count'];
            }

            if (!empty($filters['assigned_to'])) {
                $whereConditions[] = 'Aj_id = ?';
                $params[] = $filters['assigned_to'];
            }

            $whereClause = implode(' AND ', $whereConditions);
            return $db->count('request', $whereClause, $params);
        } catch (Exception $e) {
            error_log("countRequests error: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * ตรวจสอบสิทธิ์การเข้าถึงข้อร้องเรียน
 */
if (!function_exists('canAccessRequest')) {
    function canAccessRequest($requestId, $userId, $userRole)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            $request = $db->fetch("SELECT Stu_id, Re_iden FROM request WHERE Re_id = ?", [$requestId]);

            if (!$request) {
                return false;
            }

            // Admin และ teacher สามารถเข้าถึงได้ทั้งหมด
            if (in_array($userRole, ['admin', 'teacher'])) {
                return true;
            }

            // นักศึกษาสามารถเข้าถึงได้เฉพาะข้อร้องเรียนของตน
            if ($userRole === 'student') {
                return $request['Stu_id'] == $userId;
            }

            return false;
        } catch (Exception $e) {
            error_log("canAccessRequest error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * บันทึก log การทำงานของระบบ
 */
if (!function_exists('logActivity')) {
    function logActivity($action, $description, $userId = null, $requestId = null)
    {
        try {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'action' => $action,
                'description' => $description,
                'user_id' => $userId,
                'request_id' => $requestId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];

            $logString = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";

            $logDir = '../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            file_put_contents($logDir . '/activity.log', $logString, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            error_log("logActivity error: " . $e->getMessage());
        }
    }
}

/**
 * ตรวจสอบและสร้างโฟลเดอร์ที่จำเป็น
 */
if (!function_exists('ensureDirectoriesExist')) {
    function ensureDirectoriesExist()
    {
        $directories = [
            '../uploads',
            '../uploads/requests',
            '../uploads/evidence',
            '../uploads/notifications',
            '../logs'
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}

// เรียกใช้ฟังก์ชันสร้างโฟลเดอร์เมื่อโหลดไฟล์
ensureDirectoriesExist();

/**
 * ฟังก์ชันแปลงข้อความหลายบรรทัดเป็น HTML
 */
if (!function_exists('nl2br_html')) {
    function nl2br_html($text)
    {
        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }
}

/**
 * ตัดข้อความให้สั้นลง
 */
if (!function_exists('truncateText')) {
    function truncateText($text, $length = 100, $suffix = '...')
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length, 'UTF-8') . $suffix;
    }
}

/**
 * แปลงไฟล์ขนาดเป็นข้อความที่อ่านง่าย
 */
if (!function_exists('formatFileSize')) {
    function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

/**
 * สร้าง pagination HTML
 */
if (!function_exists('createPagination')) {
    function createPagination($currentPage, $totalPages, $baseUrl, $params = [])
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

        // Previous button
        if ($currentPage > 1) {
            $prevPage = $currentPage - 1;
            $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $prevPage]));
            $html .= '<li class="page-item"><a href="' . $url . '" class="page-link">&laquo; ก่อนหน้า</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo; ก่อนหน้า</span></li>';
        }

        // Page numbers
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        if ($start > 1) {
            $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => 1]));
            $html .= '<li class="page-item"><a href="' . $url . '" class="page-link">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i == $currentPage) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $i]));
                $html .= '<li class="page-item"><a href="' . $url . '" class="page-link">' . $i . '</a></li>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $totalPages]));
            $html .= '<li class="page-item"><a href="' . $url . '" class="page-link">' . $totalPages . '</a></li>';
        }

        // Next button
        if ($currentPage < $totalPages) {
            $nextPage = $currentPage + 1;
            $url = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $nextPage]));
            $html .= '<li class="page-item"><a href="' . $url . '" class="page-link">ถัดไป &raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">ถัดไป &raquo;</span></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}

/**
 * ตรวจสอบว่าสามารถแก้ไขข้อร้องเรียนได้หรือไม่
 */
if (!function_exists('canEditRequest')) {
    function canEditRequest($requestId, $userId)
    {
        $db = getDB();
        if (!$db) return ['allowed' => false, 'reason' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'];

        try {
            // ดึงข้อมูลข้อร้องเรียน
            $request = $db->fetch("SELECT * FROM request WHERE Re_id = ? AND Stu_id = ?", [$requestId, $userId]);

            if (!$request) {
                return [
                    'allowed' => false,
                    'reason' => 'ไม่พบข้อร้องเรียนที่ระบุ'
                ];
            }

            // ตรวจสอบว่าเป็นวันเดียวกับที่ส่งหรือไม่
            $createdDate = date('Y-m-d', strtotime($request['Re_date']));
            $today = date('Y-m-d');

            if ($createdDate !== $today) {
                return [
                    'allowed' => false,
                    'reason' => 'สามารถแก้ไขได้เฉพาะในวันที่ส่งข้อร้องเรียนเท่านั้น'
                ];
            }

            // ตรวจสอบสถานะ - สามารถแก้ไขได้เฉพาะเมื่อยังเป็น pending (0)
            if ($request['Re_status'] !== '0') {
                $statusTexts = [
                    '1' => 'กำลังดำเนินการ',
                    '2' => 'รอการประเมินผล',
                    '3' => 'เสร็จสิ้น',
                    '4' => 'ปฏิเสธคำร้อง'
                ];

                return [
                    'allowed' => false,
                    'reason' => 'ไม่สามารถแก้ไขได้เนื่องจากข้อร้องเรียนอยู่ในสถานะ: ' . ($statusTexts[$request['Re_status']] ?? $request['Re_status'])
                ];
            }

            return [
                'allowed' => true,
                'reason' => ''
            ];
        } catch (Exception $e) {
            error_log("canEditRequest error: " . $e->getMessage());
            return [
                'allowed' => false,
                'reason' => 'เกิดข้อผิดพลาดในการตรวจสอบสิทธิ์'
            ];
        }
    }
}

/**
 * ฟังก์ชันแสดงระดับความสำคัญ
 */
if (!function_exists('getPriorityDisplayText')) {
    function getPriorityDisplayText($priority, $status)
    {
        // หากยังเป็นสถานะ pending และยังไม่ได้กำหนดระดับ ให้แสดงเป็น "ยังไม่ระบุ"
        if ($status === '0' && $priority === '2') {
            return 'ยังไม่ระบุ';
        }

        $priorities = [
            '1' => 'ไม่เร่งด่วน',
            '2' => 'ปกติ',
            '3' => 'เร่งด่วน',
            '4' => 'เร่งด่วนมาก',
            '5' => 'วิกฤต/ฉุกเฉิน'
        ];

        return $priorities[$priority] ?? 'ปกติ';
    }
}

/**
 * ฟังก์ชันแสดงสีของระดับความสำคัญ
 */
if (!function_exists('getPriorityColor')) {
    function getPriorityColor($priority, $status)
    {
        // หากยังเป็นสถานะ pending ให้แสดงสีเทา
        if ($status === '0') {
            return '#6c757d';
        }

        $colors = [
            '1' => '#28a745',  // เขียว
            '2' => '#ffc107',  // เหลือง
            '3' => '#fd7e14',  // ส้ม
            '4' => '#dc3545',  // แดง
            '5' => '#6f42c1'   // ม่วง
        ];

        return $colors[$priority] ?? '#6c757d';
    }
}

/**
 * ดึงข้อมูลข้อร้องเรียนสำหรับการแก้ไข
 */
if (!function_exists('getRequestDataForEdit')) {
    function getRequestDataForEdit($requestId, $userId)
    {
        $db = getDB();
        if (!$db) return null;

        try {
            // ดึงข้อมูลข้อร้องเรียนด้วย Re_id
            $request = $db->fetch("SELECT * FROM request WHERE Re_id = ? AND Stu_id = ?", [$requestId, $userId]);

            if (!$request) {
                return null;
            }

            // ตรวจสอบว่าสามารถแก้ไขได้หรือไม่
            $canEdit = canEditRequest($request['Re_id'], $userId);

            if (!$canEdit['allowed']) {
                return null;
            }

            return $request;
        } catch (Exception $e) {
            error_log("getRequestDataForEdit error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * ตรวจสอบว่าข้อร้องเรียนถูกส่งในวันนี้หรือไม่
 */
if (!function_exists('isRequestSentToday')) {
    function isRequestSentToday($requestId, $userId)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            $request = $db->fetch("SELECT Re_date FROM request WHERE Re_id = ? AND Stu_id = ?", [$requestId, $userId]);

            if (!$request) {
                return false;
            }

            $createdDate = date('Y-m-d', strtotime($request['Re_date']));
            $today = date('Y-m-d');

            return $createdDate === $today;
        } catch (Exception $e) {
            error_log("isRequestSentToday error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ดึงสถิติการแจ้งเตือน
 */
if (!function_exists('getNotificationStats')) {
    function getNotificationStats($userId, $userType = 'student')
    {
        $db = getDB();
        if (!$db) return ['total' => 0, 'unread' => 0];

        try {
            $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

            $total = $db->count('notification', $field . ' = ?', [$userId]);
            $unread = $db->count('notification', $field . ' = ? AND Noti_status = 0', [$userId]);

            return ['total' => $total, 'unread' => $unread];
        } catch (Exception $e) {
            error_log("getNotificationStats error: " . $e->getMessage());
            return ['total' => 0, 'unread' => 0];
        }
    }
}

/**
 * ตรวจสอบการมอบหมายงาน
 */
if (!function_exists('canAssignRequest')) {
    function canAssignRequest($teacherId, $permission)
    {
        // เฉพาะ admin (permission = 3) และ หัวหน้างาน (permission = 2) เท่านั้นที่สามารถมอบหมายงานได้
        return in_array($permission, [2, 3]);
    }
}

/**
 * ดึงรายชื่อเจ้าหน้าที่สำหรับมอบหมายงาน
 */
if (!function_exists('getStaffListForAssignment')) {
    function getStaffListForAssignment()
    {
        $db = getDB();
        if (!$db) return [];

        try {
            return $db->fetchAll("
                SELECT Aj_id, Aj_name, Aj_position, Aj_per 
                FROM teacher 
                WHERE Aj_status = 1 
                ORDER BY Aj_per DESC, Aj_name ASC
            ");
        } catch (Exception $e) {
            error_log("getStaffListForAssignment error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ตรวจสอบความถี่ในการส่งข้อร้องเรียน (ป้องกัน spam)
 */
if (!function_exists('checkRequestFrequency')) {
    function checkRequestFrequency($studentId, $timeFrame = 3600) // 1 ชั่วโมง
    {
        $db = getDB();
        if (!$db) return ['allowed' => true, 'message' => ''];

        try {
            $since = date('Y-m-d H:i:s', time() - $timeFrame);
            $count = $db->count('request', 'Stu_id = ? AND created_at >= ?', [$studentId, $since]);

            if ($count >= 5) { // จำกัดไม่เกิน 5 ครั้งต่อชั่วโมง
                return [
                    'allowed' => false,
                    'message' => 'คุณส่งข้อร้องเรียนบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่'
                ];
            }

            return ['allowed' => true, 'message' => ''];
        } catch (Exception $e) {
            error_log("checkRequestFrequency error: " . $e->getMessage());
            return ['allowed' => true, 'message' => ''];
        }
    }
}

/**
 * ส่งการแจ้งเตือนอัตโนมัติ
 */
if (!function_exists('sendAutoNotification')) {
    function sendAutoNotification($type, $requestId, $recipientId, $recipientType = 'student', $data = [])
    {
        $templates = [
            'request_received' => [
                'title' => 'ได้รับข้อร้องเรียนของคุณแล้ว',
                'message' => 'ระบบได้รับข้อร้องเรียนของคุณแล้ว เจ้าหน้าที่จะดำเนินการตรวจสอบและติดต่อกลับภายใน 72 ชั่วโมง'
            ],
            'request_confirmed' => [
                'title' => 'ข้อร้องเรียนของคุณได้รับการยืนยันแล้ว',
                'message' => 'เจ้าหน้าที่ได้ยืนยันข้อร้องเรียนของคุณแล้ว และกำลังดำเนินการแก้ไข'
            ],
            'request_completed' => [
                'title' => 'ข้อร้องเรียนของคุณเสร็จสิ้นแล้ว',
                'message' => 'ข้อร้องเรียนของคุณได้รับการแก้ไขเสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจ'
            ],
            'new_request_staff' => [
                'title' => 'มีข้อร้องเรียนใหม่',
                'message' => 'มีข้อร้องเรียนใหม่ที่ต้องการการตรวจสอบ'
            ]
        ];

        if (!isset($templates[$type])) {
            return false;
        }

        $template = $templates[$type];
        $studentId = ($recipientType === 'student') ? $recipientId : null;
        $teacherId = ($recipientType === 'teacher') ? $recipientId : null;

        return createNotification(
            $template['title'],
            $template['message'],
            $requestId,
            $studentId,
            $teacherId
        );
    }
}

/**
 * สร้างรายงานสถิติ
 */
if (!function_exists('generateStatsReport')) {
    function generateStatsReport($startDate, $endDate, $type = 'summary')
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $report = [];
            $dateCondition = "Re_date BETWEEN ? AND ?";
            $params = [$startDate, $endDate];

            // สถิติทั่วไป
            $report['period'] = ['start' => $startDate, 'end' => $endDate];
            $report['total_requests'] = $db->count('request', $dateCondition . ' AND Re_is_spam = 0', $params);

            // สถิติตามสถานะ
            $statusStats = [];
            for ($i = 0; $i <= 3; $i++) {
                $count = $db->count('request', $dateCondition . ' AND Re_status = ? AND Re_is_spam = 0', array_merge($params, [$i]));
                $statusStats[] = ['status' => $i, 'count' => $count];
            }
            $report['status_stats'] = $statusStats;

            // สถิติตามประเภท
            $typeStats = $db->fetchAll("
                SELECT t.Type_infor, t.Type_icon, COUNT(r.Re_id) as count
                FROM type t
                LEFT JOIN request r ON t.Type_id = r.Type_id 
                    AND r.Re_date BETWEEN ? AND ? 
                    AND r.Re_is_spam = 0
                GROUP BY t.Type_id
                ORDER BY count DESC
            ", $params);
            $report['type_stats'] = $typeStats;

            // สถิติคะแนนประเมิน
            $evalStats = $db->fetch("
                SELECT 
                    AVG(e.Eva_score) as avg_score,
                    COUNT(e.Eva_id) as total_evaluations
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE r.Re_date BETWEEN ? AND ?
                    AND r.Re_is_spam = 0
            ", $params);
            $report['evaluation_stats'] = $evalStats;

            return $report;
        } catch (Exception $e) {
            error_log("generateStatsReport error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ฟังก์ชันสำหรับแสดงข้อมูลตามโหมดไม่ระบุตัวตน
 */
if (!function_exists('shouldShowPersonalInfo')) {
    function shouldShowPersonalInfo($request, $currentUserId = null)
    {
        // ถ้าเป็นโหมดไม่ระบุตัวตน
        if (isset($request['Re_iden']) && $request['Re_iden'] == 1) {
            // แสดงข้อมูลส่วนตัวได้เฉพาะเจ้าของข้อร้องเรียนหรือเจ้าหน้าที่
            return ($currentUserId && isset($request['Stu_id']) && $request['Stu_id'] === $currentUserId) ||
                (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'teacher');
        }

        // ถ้าเป็นโหมดระบุตัวตน แสดงได้ทุกคน
        return true;
    }
}

/**
 * ได้ชื่อที่จะแสดงตามโหมดไม่ระบุตัวตน
 */
if (!function_exists('getAnonymousDisplayName')) {
    function getAnonymousDisplayName($request, $currentUserId = null)
    {
        if (shouldShowPersonalInfo($request, $currentUserId)) {
            return $request['Stu_name'] ?? $request['student_name'] ?? $request['requester_name'] ?? 'ไม่ระบุชื่อ';
        }

        return 'ไม่ระบุตัวตน';
    }
}

/**
 * ได้รหัสนักศึกษาที่จะแสดงตามโหมดไม่ระบุตัวตน
 */
if (!function_exists('getAnonymousDisplayId')) {
    function getAnonymousDisplayId($request, $currentUserId = null)
    {
        if (shouldShowPersonalInfo($request, $currentUserId)) {
            return $request['Stu_id'] ?? 'ไม่ทราบรหัส';
        }

        return 'ไม่ระบุรหัส';
    }
}

/**
 * จัดรูปแบบข้อมูลการแสดงผลตามโหมดไม่ระบุตัวตน
 */
if (!function_exists('formatAnonymousInfo')) {
    function formatAnonymousInfo($request, $field, $currentUserId = null)
    {
        if (!shouldShowPersonalInfo($request, $currentUserId)) {
            switch ($field) {
                case 'name':
                    return 'ไม่ระบุตัวตน';
                case 'student_id':
                    return 'ไม่ระบุรหัส';
                case 'email':
                case 'phone':
                    return 'ไม่แสดง';
                case 'faculty':
                case 'major':
                    return 'ไม่ระบุ';
                default:
                    return 'ข้อมูลส่วนตัว';
            }
        }

        return $request[$field] ?? '';
    }
}

/**
 * ตรวจสอบว่าผู้ใช้เป็นเจ้าของข้อร้องเรียนหรือไม่
 */
if (!function_exists('isRequestOwner')) {
    function isRequestOwner($request, $userId)
    {
        return isset($request['Stu_id']) && $request['Stu_id'] === $userId;
    }
}

/**
 * ได้ระดับการเข้าถึงข้อร้องเรียน
 */
if (!function_exists('getRequestAccessLevel')) {
    function getRequestAccessLevel($request, $userId = null, $userRole = null)
    {
        // เจ้าหน้าที่มีสิทธิ์เข้าถึงทุกอย่าง
        if ($userRole === 'teacher') {
            return 'staff';
        }

        // เจ้าของข้อร้องเรียน
        if ($userId && isRequestOwner($request, $userId)) {
            return 'owner';
        }

        // ข้อร้องเรียนแบบไม่ระบุตัวตน
        if (isset($request['Re_iden']) && $request['Re_iden'] == 1) {
            return 'restricted';
        }

        // ข้อร้องเรียนแบบระบุตัวตน
        return 'public';
    }
}

/**
 * ตรวจสอบสถานะระบบ
 */
if (!function_exists('getSystemHealthStatus')) {
    function getSystemHealthStatus()
    {
        $status = [
            'database' => false,
            'uploads_dir' => false,
            'logs_dir' => false,
            'memory_usage' => 0,
            'errors' => []
        ];

        // ตรวจสอบฐานข้อมูล
        try {
            $db = getDB();
            if ($db) {
                $result = $db->fetch("SELECT 1 as test");
                $status['database'] = $result !== false;
            }
        } catch (Exception $e) {
            $status['errors'][] = 'Database: ' . $e->getMessage();
        }

        // ตรวจสอบโฟลเดอร์
        $status['uploads_dir'] = is_writable('../uploads');
        $status['logs_dir'] = is_writable('../logs') || is_writable('logs');

        // ตรวจสอบการใช้หน่วยความจำ
        $status['memory_usage'] = memory_get_usage(true);

        return $status;
    }
}

// ==========================================
// ฟังก์ชันเพิ่มเติมสำหรับระบบประเมินความพึงพอใจ
// ==========================================

/**
 * ดึงประวัติการประเมินของนักศึกษา
 */
if (!function_exists('getEvaluationHistory')) {
    function getEvaluationHistory($studentId, $limit = 10, $offset = 0)
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $sql = "SELECT e.*, r.Re_title, r.Re_infor, r.Re_date, r.Re_level, r.Re_iden,
                           t.Type_infor, t.Type_icon,
                           sr.Sv_infor as staff_response, sr.Sv_date as response_date,
                           aj.Aj_name as staff_name, aj.Aj_position
                    FROM evaluation e
                    JOIN request r ON e.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                    LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    WHERE r.Stu_id = ?
                    ORDER BY e.created_at DESC";

            if ($limit > 0) {
                $sql .= " LIMIT ? OFFSET ?";
                return $db->fetchAll($sql, [$studentId, $limit, $offset]);
            }

            return $db->fetchAll($sql, [$studentId]);
        } catch (Exception $e) {
            error_log("getEvaluationHistory error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ดึงข้อร้องเรียนที่พร้อมประเมิน (สถานะ 2 = บันทึกผลแล้ว)
 */
if (!function_exists('getRequestsReadyForEvaluation')) {
    function getRequestsReadyForEvaluation($studentId)
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                           sr.Sv_infor as staff_response, sr.Sv_date as response_date,
                           aj.Aj_name as staff_name, aj.Aj_position,
                           CASE 
                               WHEN e.Eva_id IS NOT NULL THEN 'evaluated'
                               ELSE 'pending'
                           END as evaluation_status,
                           e.Eva_score, e.Eva_sug as evaluation_comment
                    FROM request r
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                    LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    LEFT JOIN evaluation e ON r.Re_id = e.Re_id
                    WHERE r.Stu_id = ? AND r.Re_status = '2'
                    ORDER BY r.Re_date DESC";

            return $db->fetchAll($sql, [$studentId]);
        } catch (Exception $e) {
            error_log("getRequestsReadyForEvaluation error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ตรวจสอบว่าข้อร้องเรียนถูกประเมินแล้วหรือไม่
 */
if (!function_exists('isRequestEvaluated')) {
    function isRequestEvaluated($requestId)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            $result = $db->fetch("SELECT Eva_id FROM evaluation WHERE Re_id = ?", [$requestId]);
            return $result !== false;
        } catch (Exception $e) {
            error_log("isRequestEvaluated error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ดึงรายละเอียดการประเมิน
 */
if (!function_exists('getEvaluationDetail')) {
    function getEvaluationDetail($requestId, $studentId = null)
    {
        $db = getDB();
        if (!$db) return null;

        try {
            $sql = "SELECT e.*, r.Re_title, r.Re_infor, r.Re_date, r.Re_level, r.Re_iden,
                           t.Type_infor, t.Type_icon,
                           sr.Sv_infor as staff_response, sr.Sv_date as response_date,
                           aj.Aj_name as staff_name, aj.Aj_position
                    FROM evaluation e
                    JOIN request r ON e.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                    LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    WHERE e.Re_id = ?";

            $params = [$requestId];

            if ($studentId !== null) {
                $sql .= " AND r.Stu_id = ?";
                $params[] = $studentId;
            }

            return $db->fetch($sql, $params);
        } catch (Exception $e) {
            error_log("getEvaluationDetail error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * นับจำนวนการประเมิน
 */
if (!function_exists('countEvaluations')) {
    function countEvaluations($studentId)
    {
        $db = getDB();
        if (!$db) return 0;

        try {
            $result = $db->fetch("
                SELECT COUNT(e.Eva_id) as count 
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE r.Stu_id = ?
            ", [$studentId]);

            return $result['count'] ?? 0;
        } catch (Exception $e) {
            error_log("countEvaluations error: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * ดึงสถิติการประเมินของนักศึกษา
 */
if (!function_exists('getStudentEvaluationStats')) {
    function getStudentEvaluationStats($studentId)
    {
        $db = getDB();
        if (!$db) return ['total' => 0, 'average' => 0, 'by_score' => []];

        try {
            // สถิติทั่วไป
            $totalResult = $db->fetch("
                SELECT COUNT(e.Eva_id) as total,
                       AVG(e.Eva_score) as average
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE r.Stu_id = ? AND e.Eva_score > 0
            ", [$studentId]);

            // จำนวนตามคะแนน
            $scoreResults = $db->fetchAll("
                SELECT e.Eva_score, COUNT(*) as count
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE r.Stu_id = ? AND e.Eva_score > 0
                GROUP BY e.Eva_score
                ORDER BY e.Eva_score DESC
            ", [$studentId]);

            $byScore = [];
            foreach ($scoreResults as $row) {
                $byScore[$row['Eva_score']] = $row['count'];
            }

            return [
                'total' => $totalResult['total'] ?? 0,
                'average' => round($totalResult['average'] ?? 0, 1),
                'by_score' => $byScore
            ];
        } catch (Exception $e) {
            error_log("getStudentEvaluationStats error: " . $e->getMessage());
            return ['total' => 0, 'average' => 0, 'by_score' => []];
        }
    }
}

/**
 * อัพเดทหรือลบการประเมิน (สำหรับแก้ไข)
 */
if (!function_exists('updateEvaluation')) {
    function updateEvaluation($evaluationId, $score, $suggestion = '', $studentId = null)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            $db->beginTransaction();

            // ตรวจสอบสิทธิ์ (ถ้าระบุ studentId)
            if ($studentId !== null) {
                $check = $db->fetch("
                    SELECT e.Eva_id 
                    FROM evaluation e
                    JOIN request r ON e.Re_id = r.Re_id
                    WHERE e.Eva_id = ? AND r.Stu_id = ?
                ", [$evaluationId, $studentId]);

                if (!$check) {
                    $db->rollback();
                    return false;
                }
            }

            // อัพเดทการประเมิน
            $result = $db->update('evaluation', [
                'Eva_score' => $score,
                'Eva_sug' => $suggestion
            ], 'Eva_id = ?', [$evaluationId]);

            if ($result) {
                $db->commit();
                return true;
            } else {
                $db->rollback();
                return false;
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("updateEvaluation error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ลบการประเมิน
 */
if (!function_exists('deleteEvaluation')) {
    function deleteEvaluation($evaluationId, $studentId = null)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            $db->beginTransaction();

            // ดึงข้อมูลการประเมิน
            $evaluation = $db->fetch("
                SELECT e.*, r.Stu_id, r.Re_id
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE e.Eva_id = ?
            ", [$evaluationId]);

            if (!$evaluation) {
                $db->rollback();
                return false;
            }

            // ตรวจสอบสิทธิ์
            if ($studentId !== null && $evaluation['Stu_id'] !== $studentId) {
                $db->rollback();
                return false;
            }

            // ลบการประเมิน
            $result = $db->delete('evaluation', 'Eva_id = ?', [$evaluationId]);

            if ($result) {
                // อัพเดทสถานะข้อร้องเรียนกลับเป็น "บันทึกผลแล้ว" (2)
                $db->update('request', ['Re_status' => '2'], 'Re_id = ?', [$evaluation['Re_id']]);

                $db->commit();
                return true;
            } else {
                $db->rollback();
                return false;
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("deleteEvaluation error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ดึงข้อมูลการประเมินสำหรับการแก้ไข
 */
if (!function_exists('getEvaluationForEdit')) {
    function getEvaluationForEdit($requestId, $studentId)
    {
        $db = getDB();
        if (!$db) return null;

        try {
            $sql = "SELECT e.*, r.Re_title, r.Re_infor, r.Re_date, r.Re_status,
                           t.Type_infor, t.Type_icon,
                           sr.Sv_infor as staff_response, sr.Sv_date as response_date,
                           aj.Aj_name as staff_name, aj.Aj_position
                    FROM evaluation e
                    JOIN request r ON e.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                    LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    WHERE r.Re_id = ? AND r.Stu_id = ?";

            return $db->fetch($sql, [$requestId, $studentId]);
        } catch (Exception $e) {
            error_log("getEvaluationForEdit error: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * ตรวจสอบว่าสามารถแก้ไขการประเมินได้หรือไม่
 */
if (!function_exists('canEditEvaluation')) {
    function canEditEvaluation($requestId, $studentId)
    {
        $db = getDB();
        if (!$db) return ['allowed' => false, 'reason' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'];

        try {
            // ดึงข้อมูลการประเมิน
            $evaluation = $db->fetch("
                SELECT e.*, r.Re_status, r.Re_date
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE r.Re_id = ? AND r.Stu_id = ?
            ", [$requestId, $studentId]);

            if (!$evaluation) {
                return [
                    'allowed' => false,
                    'reason' => 'ไม่พบการประเมินที่ระบุ'
                ];
            }

            // ตรวจสอบว่าเป็นการประเมินในวันเดียวกับที่ประเมิน
            $evaluatedDate = date('Y-m-d', strtotime($evaluation['created_at']));
            $today = date('Y-m-d');

            if ($evaluatedDate !== $today) {
                return [
                    'allowed' => false,
                    'reason' => 'สามารถแก้ไขการประเมินได้เฉพาะในวันที่ประเมินเท่านั้น'
                ];
            }

            // ตรวจสอบสถานะข้อร้องเรียน - ต้องเป็นสถานะ 3 (ประเมินแล้ว)
            if ($evaluation['Re_status'] !== '3') {
                return [
                    'allowed' => false,
                    'reason' => 'สถานะข้อร้องเรียนไม่อนุญาตให้แก้ไขการประเมิน'
                ];
            }

            return [
                'allowed' => true,
                'reason' => ''
            ];
        } catch (Exception $e) {
            error_log("canEditEvaluation error: " . $e->getMessage());
            return [
                'allowed' => false,
                'reason' => 'เกิดข้อผิดพลาดในการตรวจสอบสิทธิ์'
            ];
        }
    }
}

/**
 * สร้างรายงานการประเมิน
 */
if (!function_exists('generateEvaluationReport')) {
    function generateEvaluationReport($startDate = null, $endDate = null, $filters = [])
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $whereConditions = ['r.Re_is_spam = 0'];
            $params = [];

            // กรองตามวันที่
            if ($startDate && $endDate) {
                $whereConditions[] = "e.created_at BETWEEN ? AND ?";
                $params[] = $startDate . ' 00:00:00';
                $params[] = $endDate . ' 23:59:59';
            }

            // กรองตามประเภท
            if (!empty($filters['type_id'])) {
                $whereConditions[] = "r.Type_id = ?";
                $params[] = $filters['type_id'];
            }

            // กรองตามคะแนน
            if (!empty($filters['min_score'])) {
                $whereConditions[] = "e.Eva_score >= ?";
                $params[] = $filters['min_score'];
            }

            if (!empty($filters['max_score'])) {
                $whereConditions[] = "e.Eva_score <= ?";
                $params[] = $filters['max_score'];
            }

            $whereClause = implode(' AND ', $whereConditions);

            // สถิติรวม
            $totalStats = $db->fetch("
                SELECT COUNT(e.Eva_id) as total_evaluations,
                       AVG(e.Eva_score) as average_score,
                       MIN(e.Eva_score) as min_score,
                       MAX(e.Eva_score) as max_score
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE {$whereClause}
            ", $params);

            // สถิติตามคะแนน
            $scoreDistribution = $db->fetchAll("
                SELECT e.Eva_score, COUNT(*) as count,
                       ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM evaluation e2 JOIN request r2 ON e2.Re_id = r2.Re_id WHERE {$whereClause})), 2) as percentage
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE {$whereClause}
                GROUP BY e.Eva_score
                ORDER BY e.Eva_score DESC
            ", $params);

            // สถิติตามประเภทข้อร้องเรียน
            $typeStats = $db->fetchAll("
                SELECT t.Type_infor, t.Type_icon,
                       COUNT(e.Eva_id) as evaluation_count,
                       AVG(e.Eva_score) as average_score
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                JOIN type t ON r.Type_id = t.Type_id
                WHERE {$whereClause}
                GROUP BY t.Type_id, t.Type_infor, t.Type_icon
                ORDER BY evaluation_count DESC
            ", $params);

            return [
                'total_stats' => $totalStats,
                'score_distribution' => $scoreDistribution,
                'type_stats' => $typeStats,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ]
            ];
        } catch (Exception $e) {
            error_log("generateEvaluationReport error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ดึงความคิดเห็นล่าสุด
 */
if (!function_exists('getRecentEvaluationComments')) {
    function getRecentEvaluationComments($limit = 5, $minLength = 10)
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $sql = "SELECT e.Eva_sug, e.Eva_score, e.created_at,
                           r.Re_title, r.Re_infor, t.Type_infor, t.Type_icon
                    FROM evaluation e
                    JOIN request r ON e.Re_id = r.Re_id
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    WHERE LENGTH(TRIM(e.Eva_sug)) >= ? 
                      AND r.Re_is_spam = 0
                    ORDER BY e.created_at DESC
                    LIMIT ?";

            return $db->fetchAll($sql, [$minLength, $limit]);
        } catch (Exception $e) {
            error_log("getRecentEvaluationComments error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ดึงข้อร้องเรียนที่เสร็จสิ้นแล้วสำหรับประเมิน
 */
if (!function_exists('getCompletedRequestsForEvaluation')) {
    function getCompletedRequestsForEvaluation($studentId)
    {
        $db = getDB();
        if (!$db) return [];

        try {
            $sql = "SELECT r.*, t.Type_infor, t.Type_icon,
                           CASE 
                               WHEN e.Eva_id IS NOT NULL THEN 'evaluated'
                               ELSE 'pending'
                           END as evaluation_status,
                           e.Eva_score,
                           e.Eva_sug as evaluation_comment,
                           sr.Sv_infor as staff_response,
                           aj.Aj_name as staff_name, aj.Aj_position
                    FROM request r 
                    LEFT JOIN type t ON r.Type_id = t.Type_id
                    LEFT JOIN evaluation e ON r.Re_id = e.Re_id
                    LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                    LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    WHERE r.Stu_id = ? AND r.Re_status = '2'
                    ORDER BY r.Re_date DESC";

            return $db->fetchAll($sql, [$studentId]);
        } catch (Exception $e) {
            error_log("getCompletedRequestsForEvaluation error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ตรวจสอบสิทธิ์การดูรายละเอียดการประเมิน
 */
if (!function_exists('canViewEvaluationDetail')) {
    function canViewEvaluationDetail($requestId, $userId, $userRole)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            // เจ้าหน้าที่สามารถดูได้ทั้งหมด
            if ($userRole === 'teacher') {
                return true;
            }

            // นักศึกษาสามารถดูได้เฉพาะของตน
            if ($userRole === 'student') {
                $request = $db->fetch("SELECT Stu_id FROM request WHERE Re_id = ?", [$requestId]);
                return $request && $request['Stu_id'] === $userId;
            }

            return false;
        } catch (Exception $e) {
            error_log("canViewEvaluationDetail error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ดึงข้อมูลสำหรับกราฟสถิติการประเมิน
 */
if (!function_exists('getEvaluationChartData')) {
    function getEvaluationChartData($period = '6months', $filters = [])
    {
        $db = getDB();
        if (!$db) return [];

        try {
            // กำหนดช่วงเวลา
            switch ($period) {
                case '1month':
                    $dateFrom = date('Y-m-d', strtotime('-1 month'));
                    $groupBy = 'DATE(e.created_at)';
                    $dateFormat = '%Y-%m-%d';
                    break;
                case '3months':
                    $dateFrom = date('Y-m-d', strtotime('-3 months'));
                    $groupBy = 'YEARWEEK(e.created_at, 1)';
                    $dateFormat = '%Y-W%u';
                    break;
                case '6months':
                default:
                    $dateFrom = date('Y-m-d', strtotime('-6 months'));
                    $groupBy = 'DATE_FORMAT(e.created_at, "%Y-%m")';
                    $dateFormat = '%Y-%m';
                    break;
                case '1year':
                    $dateFrom = date('Y-m-d', strtotime('-1 year'));
                    $groupBy = 'DATE_FORMAT(e.created_at, "%Y-%m")';
                    $dateFormat = '%Y-%m';
                    break;
            }

            $whereConditions = ['e.created_at >= ?', 'r.Re_is_spam = 0'];
            $params = [$dateFrom . ' 00:00:00'];

            // เพิ่มตัวกรองเพิ่มเติม
            if (!empty($filters['type_id'])) {
                $whereConditions[] = 'r.Type_id = ?';
                $params[] = $filters['type_id'];
            }

            $whereClause = implode(' AND ', $whereConditions);

            // ข้อมูลสำหรับกราฟ
            $chartData = $db->fetchAll("
                SELECT {$groupBy} as period,
                       COUNT(e.Eva_id) as total_evaluations,
                       AVG(e.Eva_score) as average_score,
                       SUM(CASE WHEN e.Eva_score >= 4 THEN 1 ELSE 0 END) as positive_count,
                       SUM(CASE WHEN e.Eva_score <= 2 THEN 1 ELSE 0 END) as negative_count
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE {$whereClause}
                GROUP BY {$groupBy}
                ORDER BY period ASC
            ", $params);

            return [
                'chart_data' => $chartData,
                'period' => $period,
                'date_from' => $dateFrom
            ];
        } catch (Exception $e) {
            error_log("getEvaluationChartData error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ดึงสถิติการประเมินแบบเรียลไทม์
 */
if (!function_exists('getRealTimeEvaluationStats')) {
    function getRealTimeEvaluationStats()
    {
        $db = getDB();
        if (!$db) return [];

        try {
            // สถิติวันนี้
            $today = date('Y-m-d');
            $todayStats = $db->fetch("
                SELECT COUNT(e.Eva_id) as count,
                       AVG(e.Eva_score) as average
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE DATE(e.created_at) = ? AND r.Re_is_spam = 0
            ", [$today]);

            // สถิติสัปดาห์นี้
            $weekStats = $db->fetch("
                SELECT COUNT(e.Eva_id) as count,
                       AVG(e.Eva_score) as average
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE YEARWEEK(e.created_at, 1) = YEARWEEK(CURDATE(), 1) 
                  AND r.Re_is_spam = 0
            ");

            // สถิติเดือนนี้
            $monthStats = $db->fetch("
                SELECT COUNT(e.Eva_id) as count,
                       AVG(e.Eva_score) as average
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                WHERE YEAR(e.created_at) = YEAR(CURDATE()) 
                  AND MONTH(e.created_at) = MONTH(CURDATE())
                  AND r.Re_is_spam = 0
            ");

            // การประเมินล่าสุด
            $latestEvaluations = $db->fetchAll("
                SELECT e.Eva_score, e.created_at,
                       r.Re_title, r.Re_infor,
                       t.Type_infor, t.Type_icon
                FROM evaluation e
                JOIN request r ON e.Re_id = r.Re_id
                LEFT JOIN type t ON r.Type_id = t.Type_id
                WHERE r.Re_is_spam = 0
                ORDER BY e.created_at DESC
                LIMIT 5
            ");

            return [
                'today' => [
                    'count' => $todayStats['count'] ?? 0,
                    'average' => round($todayStats['average'] ?? 0, 1)
                ],
                'week' => [
                    'count' => $weekStats['count'] ?? 0,
                    'average' => round($weekStats['average'] ?? 0, 1)
                ],
                'month' => [
                    'count' => $monthStats['count'] ?? 0,
                    'average' => round($monthStats['average'] ?? 0, 1)
                ],
                'latest' => $latestEvaluations
            ];
        } catch (Exception $e) {
            error_log("getRealTimeEvaluationStats error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ส่งการแจ้งเตือนเมื่อมีการประเมิน
 */
if (!function_exists('notifyEvaluationReceived')) {
    function notifyEvaluationReceived($requestId, $score, $teacherId = null)
    {
        $db = getDB();
        if (!$db) return false;

        try {
            // ดึงข้อมูลเจ้าหน้าที่ที่รับผิดชอบ
            if (!$teacherId) {
                $staffInfo = $db->fetch("
                    SELECT aj.Aj_id 
                    FROM save_request sr
                    JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                    WHERE sr.Re_id = ?
                    ORDER BY sr.created_at DESC
                    LIMIT 1
                ", [$requestId]);

                $teacherId = $staffInfo['Aj_id'] ?? null;
            }

            if ($teacherId) {
                // ดึงข้อมูลข้อร้องเรียน
                $request = $db->fetch("
                    SELECT Re_title, Re_infor 
                    FROM request 
                    WHERE Re_id = ?
                ", [$requestId]);

                $scoreText = [
                    1 => 'ไม่พอใจ (1/5)',
                    2 => 'น้อย (2/5)',
                    3 => 'ปานกลาง (3/5)',
                    4 => 'ดี (4/5)',
                    5 => 'ดีที่สุด (5/5)'
                ];

                $title = 'มีการประเมินความพึงพอใจใหม่';
                $message = 'ข้อร้องเรียน: ' . truncateText($request['Re_infor'] ?? '', 50) .
                    ' ได้รับการประเมิน: ' . ($scoreText[$score] ?? $score);

                return createNotification(
                    $title,
                    $message,
                    $requestId,
                    null,
                    $teacherId
                );
            }

            return false;
        } catch (Exception $e) {
            error_log("notifyEvaluationReceived error: " . $e->getMessage());
            return false;
        }
    }
}
