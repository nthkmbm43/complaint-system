<?php
// ป้องกันการเข้าถึงไฟล์โดยตรง
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// ตั้งค่าเขตเวลา
date_default_timezone_set('Asia/Bangkok');

// ข้อมูลการเชื่อมต่อฐานข้อมูล
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

// ข้อมูลเว็บไซต์
define('SITE_NAME', 'ระบบข้อร้องเรียนนักศึกษา มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน');
define('SITE_SHORT_NAME', 'ระบบข้อร้องเรียน RMUTI');
define('SITE_URL', 'https://complaint-student.great-site.net/');
define('ADMIN_EMAIL', 'admin@rmuti.ac.th');

// การตั้งค่าระบบ
define('SESSION_TIMEOUT', 3600); // 1 ชั่วโมง
define('MAX_FILE_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx']);

// การตั้งค่าข้อร้องเรียน
define('DEFAULT_RESPONSE_TIME', 72); // 72 ชั่วโมง (3 วัน)
define('MAX_ANONYMOUS_REPORTS', 3); // จำนวนการรายงานสูงสุดก่อนระงับบัญชี
define('MAX_REQUESTS_PER_HOUR', 5); // จำกัดการส่งข้อร้องเรียนต่อชั่วโมง
define('URGENT_RESPONSE_TIME', 24); // 24 ชั่วโมงสำหรับเรื่องเร่งด่วน

// การตั้งค่าอีเมล
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-app-password');
define('SMTP_FROM_EMAIL', 'noreply@rmuti.ac.th');
define('SMTP_FROM_NAME', 'ระบบข้อร้องเรียน RMUTI');

// โหมดการพัฒนา
// ⚠️ ปิด DEBUG_MODE สำหรับ InfinityFree (เพื่อหลีกเลี่ยง open_basedir restriction)
define('DEBUG_MODE', false);
define('SHOW_ERRORS', false);

// Security settings
define('SECURE_KEY', 'rmuti-complaint-system-2025');
define('PASSWORD_MIN_LENGTH', 6);
define('BCRYPT_COST', 12);

// สถานะข้อร้องเรียน - ตรงกับฐานข้อมูลใหม่
define('STATUS_PENDING', '0');        // ยื่นคำร้อง
define('STATUS_CONFIRMED', '1');      // กำลังดำเนินการ
define('STATUS_COMPLETED', '2');      // รอการประเมินผล
define('STATUS_EVALUATED', '3');      // เสร็จสิ้น
define('STATUS_REJECTED', '4');       // 🔴 ปฏิเสธ/ยกเลิกข้อร้องเรียน

// ระดับความสำคัญ - ตรงกับฐานข้อมูลใหม่
define('PRIORITY_LOW', '1');          // ไม่เร่งด่วน
define('PRIORITY_MEDIUM', '2');       // ปกติ
define('PRIORITY_HIGH', '3');         // เร่งด่วน
define('PRIORITY_URGENT', '4');       // เร่งด่วนมาก
define('PRIORITY_CRITICAL', '5');     // วิกฤต/ฉุกเฉิน

// บทบาทผู้ใช้ - ตรงกับฐานข้อมูลใหม่
define('ROLE_STUDENT', 'student');
define('ROLE_TEACHER', 'teacher');
define('ROLE_STAFF', 'teacher');      // Teacher ที่มี permission < 3
define('ROLE_ADMIN', 'teacher');      // Teacher ที่มี permission = 3

// สถานะการระบุตัวตน
define('IDENTITY_REVEALED', 0);       // ระบุตัวตน
define('IDENTITY_ANONYMOUS', 1);      // ไม่ระบุตัวตน

// ประเภทการแจ้งเตือน
define('NOTIFICATION_SYSTEM', 'system');
define('NOTIFICATION_EMAIL', 'email');
define('NOTIFICATION_BOTH', 'both');

// สถานะการแจ้งเตือน
define('NOTIFICATION_UNREAD', 0);
define('NOTIFICATION_READ', 1);

// ประเภทการจัดการข้อร้องเรียน
define('MANAGEMENT_RECEIVE', 'receive');    // รับเรื่อง
define('MANAGEMENT_FORWARD', 'forward');    // ส่งต่อ
define('MANAGEMENT_PROCESS', 'process');    // ดำเนินการ
define('MANAGEMENT_CLOSE', 'close');        // ปิดเรื่อง

// ระดับสิทธิ์เจ้าหน้าที่ - ตรงกับฐานข้อมูลใหม่
define('PERMISSION_STAFF', 1);        // เจ้าหน้าที่
define('PERMISSION_SUPERVISOR', 2);   // หัวหน้างาน
define('PERMISSION_ADMIN', 3);        // ผู้ดูแลระบบ

// ประเภทข้อร้องเรียน - อัพเดตตามฐานข้อมูลใหม่
$COMPLAINT_TYPES = [
    1 => ['name' => 'เรื่องการเรียนการสอน', 'icon' => '📚', 'color' => 'primary', 'department' => 'academic'],
    2 => ['name' => 'สิ่งอำนวยความสะดวก', 'icon' => '🏢', 'color' => 'success', 'department' => 'facility'],
    3 => ['name' => 'เรื่องการเงิน', 'icon' => '💰', 'color' => 'warning', 'department' => 'finance'],
    4 => ['name' => 'บุคลากร/เจ้าหน้าที่', 'icon' => '👥', 'color' => 'info', 'department' => 'hr'],
    5 => ['name' => 'ระบบเทคโนโลยี', 'icon' => '🌐', 'color' => 'secondary', 'department' => 'it'],
    6 => ['name' => 'การคมนาคม', 'icon' => '🚌', 'color' => 'dark', 'department' => 'transport'],
    7 => ['name' => 'บริการสุขภาพ', 'icon' => '🏥', 'color' => 'danger', 'department' => 'health'],
    8 => ['name' => 'อื่นๆ', 'icon' => '📋', 'color' => 'light', 'department' => 'general']
];

// คณะและสาขา - อัพเดตตามฐานข้อมูลใหม่
$FACULTIES = [
    1 => [
        'name' => 'คณะวิศวกรรมศาสตร์',
        'icon' => '⚙️',
        'majors' => [
            1 => ['name' => 'วิศวกรรมคอมพิวเตอร์', 'icon' => '💻'],
            2 => ['name' => 'วิศวกรรมไฟฟ้า', 'icon' => '⚡'],
            3 => ['name' => 'วิศวกรรมเครื่องกล', 'icon' => '🔧'],
            4 => ['name' => 'วิศวกรรมโยธา', 'icon' => '🏗️']
        ]
    ],
    2 => [
        'name' => 'คณะเทคโนโลยีการเกษตร',
        'icon' => '🌾',
        'majors' => [
            5 => ['name' => 'เทคโนโลยีการเกษตร', 'icon' => '🚜'],
            6 => ['name' => 'เทคโนโลยีอาหาร', 'icon' => '🍽️']
        ]
    ],
    3 => [
        'name' => 'คณะบริหารธุรกิจและเทคโนโลยีสารสนเทศ',
        'icon' => '💼',
        'majors' => [
            7 => ['name' => 'บริหารธุรกิจ', 'icon' => '📊'],
            8 => ['name' => 'เทคโนโลยีธุรกิจดิจิทัล', 'icon' => '📱'],
            9 => ['name' => 'เทคโนโลยีสารสนเทศ', 'icon' => '🖥️']
        ]
    ],
    4 => [
        'name' => 'คณะเทคโนโลยีอุตสาหกรรม',
        'icon' => '🏭',
        'majors' => [
            10 => ['name' => 'เทคโนโลยีอุตสาหกรรม', 'icon' => '🏭']
        ]
    ],
    5 => [
        'name' => 'คณะวิทยาศาสตร์ประยุกต์',
        'icon' => '🔬',
        'majors' => [
            11 => ['name' => 'วิทยาศาสตร์ประยุกต์', 'icon' => '🧪']
        ]
    ]
];

// แผนก/หน่วยงาน - เพิ่มใหม่ตามฐานข้อมูล
$DEPARTMENTS = [
    1 => ['name' => 'งานทะเบียนและประมวลผล', 'icon' => '📋', 'faculty' => 3],
    2 => ['name' => 'งานกิจการนักศึกษา', 'icon' => '👥', 'faculty' => 3],
    3 => ['name' => 'งานอาคารสถานที่', 'icon' => '🏢', 'faculty' => 1],
    4 => ['name' => 'งานเทคโนโลยีสารสนเทศ', 'icon' => '💻', 'faculty' => 3],
    5 => ['name' => 'สาขาวิศวกรรมคอมพิวเตอร์', 'icon' => '🖥️', 'faculty' => 1],
    6 => ['name' => 'สาขาบริหารธุรกิจ', 'icon' => '📈', 'faculty' => 3],
    7 => ['name' => 'งานการเงินและบัญชี', 'icon' => '💰', 'faculty' => 3],
    8 => ['name' => 'งานบุคลากร', 'icon' => '👔', 'faculty' => null],
    9 => ['name' => 'งานประชาสัมพันธ์', 'icon' => '📢', 'faculty' => null],
    10 => ['name' => 'งานวิเทศสัมพันธ์', 'icon' => '🌏', 'faculty' => null],
    11 => ['name' => 'งานประกันคุณภาพการศึกษา', 'icon' => '✅', 'faculty' => null],
    12 => ['name' => 'ศูนย์คอมพิวเตอร์', 'icon' => '🖥️', 'faculty' => null],
    13 => ['name' => 'หอสมุดกลาง', 'icon' => '📚', 'faculty' => null],
    14 => ['name' => 'งานรักษาความปลอดภัย', 'icon' => '🔒', 'faculty' => null],
    15 => ['name' => 'งานยานพาหนะ', 'icon' => '🚗', 'faculty' => null],
    16 => ['name' => 'งานสวัสดิการ', 'icon' => '🏥', 'faculty' => null]
];

// สถานะการประเมิน
$EVALUATION_SCORES = [
    1 => ['text' => 'ไม่พอใจ', 'color' => 'danger', 'icon' => '😞'],
    2 => ['text' => 'น้อย', 'color' => 'warning', 'icon' => '😐'],
    3 => ['text' => 'ปานกลาง', 'color' => 'info', 'icon' => '😊'],
    4 => ['text' => 'ดี', 'color' => 'success', 'icon' => '😄'],
    5 => ['text' => 'ดีที่สุด', 'color' => 'primary', 'icon' => '🤩']
];

// ระดับสิทธิ์เจ้าหน้าที่
$STAFF_PERMISSIONS = [
    1 => ['name' => 'เจ้าหน้าที่', 'color' => 'secondary', 'description' => 'จัดการข้อร้องเรียนพื้นฐาน'],
    2 => ['name' => 'หัวหน้างาน', 'color' => 'primary', 'description' => 'จัดการข้อร้องเรียนและมอบหมายงาน'],
    3 => ['name' => 'ผู้ดูแลระบบ', 'color' => 'danger', 'description' => 'จัดการระบบและผู้ใช้ทั้งหมด']
];

// สีสำหรับสถานะต่างๆ
$STATUS_COLORS = [
    '0' => ['color' => 'secondary', 'text' => 'ยื่นคำร้อง', 'icon' => '📝'],        // สีเทา
    '1' => ['color' => 'info',      'text' => 'กำลังดำเนินการ', 'icon' => '⏳'],    // สีฟ้า
    '2' => ['color' => 'warning',   'text' => 'รอการประเมินผล', 'icon' => '⭐'],   // สีเหลือง
    '3' => ['color' => 'success',   'text' => 'เสร็จสิ้น', 'icon' => '✅'],          // สีเขียว
    '4' => ['color' => 'danger',    'text' => 'ปฏิเสธ/ยกเลิก', 'icon' => '❌']      // สีแดง
];

// สีสำหรับระดับความสำคัญ
$PRIORITY_COLORS = [
    '1' => ['color' => 'success', 'text' => 'ไม่เร่งด่วน', 'icon' => '🟢'],
    '2' => ['color' => 'secondary', 'text' => 'ปกติ', 'icon' => '🔵'],
    '3' => ['color' => 'warning', 'text' => 'เร่งด่วน', 'icon' => '🟡'],
    '4' => ['color' => 'danger', 'text' => 'เร่งด่วนมาก', 'icon' => '🔴'],
    '5' => ['color' => 'dark', 'text' => 'วิกฤต/ฉุกเฉิน', 'icon' => '🟣']
];

// การตั้งค่าการแจ้งเตือน
$NOTIFICATION_SETTINGS = [
    'auto_notify_staff' => true,          // แจ้งเตือนเจ้าหน้าที่อัตโนมัติ
    'auto_notify_student' => true,        // แจ้งเตือนนักศึกษาอัตโนมัติ
    'email_notifications' => false,       // ส่งอีเมลแจ้งเตือน
    'urgent_notification_time' => 1,      // แจ้งเตือนเรื่องเร่งด่วนทันที (ชั่วโมง)
    'reminder_time' => 48                 // แจ้งเตือนเตือนความจำ (ชั่วโมง)
];

// ฟังก์ชันช่วยเหลือ
function getComplaintTypes()
{
    global $COMPLAINT_TYPES;
    return $COMPLAINT_TYPES;
}

function getFaculties()
{
    global $FACULTIES;
    return $FACULTIES;
}

function getDepartments()
{
    global $DEPARTMENTS;
    return $DEPARTMENTS;
}

function getEvaluationScores()
{
    global $EVALUATION_SCORES;
    return $EVALUATION_SCORES;
}

function getStaffPermissions()
{
    global $STAFF_PERMISSIONS;
    return $STAFF_PERMISSIONS;
}

function getStatusColors()
{
    global $STATUS_COLORS;
    return $STATUS_COLORS;
}

function getPriorityColors()
{
    global $PRIORITY_COLORS;
    return $PRIORITY_COLORS;
}

function getNotificationSettings()
{
    global $NOTIFICATION_SETTINGS;
    return $NOTIFICATION_SETTINGS;
}

// ฟังก์ชันจัดรูปแบบวันที่เป็นภาษาไทย
function formatThaiDate($timestamp)
{
    $thai_months = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม'
    ];

    $date = date('j', $timestamp);
    $month = $thai_months[date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;
    $time = date('H:i', $timestamp);

    return "{$date} {$month} {$year}, {$time} น.";
}

function formatThaiDateOnly($date)
{
    $thai_months = [
        1 => 'มกราคม',
        2 => 'กุมภาพันธ์',
        3 => 'มีนาคม',
        4 => 'เมษายน',
        5 => 'พฤษภาคม',
        6 => 'มิถุนายน',
        7 => 'กรกฎาคม',
        8 => 'สิงหาคม',
        9 => 'กันยายน',
        10 => 'ตุลาคม',
        11 => 'พฤศจิกายน',
        12 => 'ธันวาคม'
    ];

    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $thai_months[date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;

    return "{$day} {$month} {$year}";
}

function formatThaiDateTime($datetime)
{
    if (empty($datetime)) return '-';

    $timestamp = strtotime($datetime);
    return formatThaiDate($timestamp);
}

function sanitizeInput($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateSecureToken()
{
    return bin2hex(random_bytes(32));
}

function getStatusText($status)
{
    $statusColors = getStatusColors();
    return $statusColors[$status]['text'] ?? 'ไม่ทราบสถานะ';
}

function getStatusBadgeClass($status)
{
    $statusColors = getStatusColors();
    return $statusColors[$status]['color'] ?? 'secondary';
}

function getStatusIcon($status)
{
    $statusColors = getStatusColors();
    return $statusColors[$status]['icon'] ?? '❓';
}

function getPriorityText($priority)
{
    $priorityColors = getPriorityColors();
    return $priorityColors[$priority]['text'] ?? 'ปกติ';
}

function getPriorityBadgeClass($priority)
{
    $priorityColors = getPriorityColors();
    return $priorityColors[$priority]['color'] ?? 'secondary';
}

function getPriorityIcon($priority)
{
    $priorityColors = getPriorityColors();
    return $priorityColors[$priority]['icon'] ?? '🔵';
}

function getComplaintTypeById($typeId)
{
    $types = getComplaintTypes();
    return $types[$typeId] ?? ['name' => 'ไม่ทราบประเภท', 'icon' => '❓', 'color' => 'secondary'];
}

function getFacultyNameById($facultyId)
{
    $faculties = getFaculties();
    return $faculties[$facultyId]['name'] ?? 'ไม่ทราบคณะ';
}

function getFacultyIconById($facultyId)
{
    $faculties = getFaculties();
    return $faculties[$facultyId]['icon'] ?? '🏫';
}

function getMajorNameById($facultyId, $majorId)
{
    $faculties = getFaculties();
    return $faculties[$facultyId]['majors'][$majorId]['name'] ?? 'ไม่ทราบสาขา';
}

function getMajorIconById($facultyId, $majorId)
{
    $faculties = getFaculties();
    return $faculties[$facultyId]['majors'][$majorId]['icon'] ?? '📚';
}

function getDepartmentNameById($deptId)
{
    $departments = getDepartments();
    return $departments[$deptId]['name'] ?? 'ไม่ทราบแผนก';
}

function getDepartmentIconById($deptId)
{
    $departments = getDepartments();
    return $departments[$deptId]['icon'] ?? '🏢';
}

function getPermissionText($permission)
{
    $permissions = getStaffPermissions();
    return $permissions[$permission]['name'] ?? 'ไม่ทราบสิทธิ์';
}

function getPermissionColor($permission)
{
    $permissions = getStaffPermissions();
    return $permissions[$permission]['color'] ?? 'secondary';
}

function validateStudentId($studentId)
{
    // ตรวจสอบรูปแบบรหัสนักศึกษา (13 หลัก)
    return preg_match('/^[0-9]{13}$/', $studentId);
}

function validateEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateFileUpload($file)
{
    $errors = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'เกิดข้อผิดพลาดในการอัพโหลดไฟล์';
        return $errors;
    }

    // ตรวจสอบขนาดไฟล์
    if ($file['size'] > MAX_FILE_SIZE) {
        $errors[] = 'ไฟล์มีขนาดใหญ่เกินกำหนด (สูงสุด ' . formatFileSize(MAX_FILE_SIZE) . ')';
    }

    // ตรวจสอบประเภทไฟล์
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, ALLOWED_FILE_TYPES)) {
        $errors[] = 'ประเภทไฟล์ไม่ได้รับอนุญาต (รองรับเฉพาะ: ' . implode(', ', ALLOWED_FILE_TYPES) . ')';
    }

    // ตรวจสอบชื่อไฟล์
    if (empty($file['name']) || strlen($file['name']) > 255) {
        $errors[] = 'ชื่อไฟล์ไม่ถูกต้อง';
    }

    return $errors;
}

function formatFileSize($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, 2) . ' ' . $units[$pow];
}

function generateComplaintId()
{
    return 'CR' . date('Ymd') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function isUrgentPriority($priority)
{
    return in_array($priority, [PRIORITY_HIGH, PRIORITY_URGENT, PRIORITY_CRITICAL]);
}

function calculateResponseTime($priority)
{
    switch ($priority) {
        case PRIORITY_CRITICAL:
            return 6; // 6 ชั่วโมง
        case PRIORITY_URGENT:
            return 12; // 12 ชั่วโมง
        case PRIORITY_HIGH:
            return 24; // 24 ชั่วโมง
        case PRIORITY_MEDIUM:
        case PRIORITY_LOW:
        default:
            return DEFAULT_RESPONSE_TIME; // 72 ชั่วโมง
    }
}

function isOverdue($createdDate, $priority, $status)
{
    if (in_array($status, [STATUS_COMPLETED, STATUS_EVALUATED])) {
        return false; // เสร็จสิ้นแล้ว
    }

    $responseTime = calculateResponseTime($priority);
    $deadline = strtotime($createdDate . ' +' . $responseTime . ' hours');

    return time() > $deadline;
}

function getTimeRemaining($createdDate, $priority)
{
    $responseTime = calculateResponseTime($priority);
    $deadline = strtotime($createdDate . ' +' . $responseTime . ' hours');
    $remaining = $deadline - time();

    if ($remaining <= 0) {
        return 'เกินกำหนด';
    }

    $hours = floor($remaining / 3600);
    $minutes = floor(($remaining % 3600) / 60);

    if ($hours > 0) {
        return $hours . ' ชั่วโมง ' . $minutes . ' นาที';
    } else {
        return $minutes . ' นาที';
    }
}

// ฟังก์ชันสำหรับส่งอีเมลแจ้งเตือน
function sendNotificationEmail($to, $subject, $message, $priority = 'normal')
{
    // ตัวอย่างการส่งอีเมล - ต้องใช้ library เช่น PHPMailer
    if (DEBUG_MODE) {
        error_log("Email notification: TO={$to}, SUBJECT={$subject}, PRIORITY={$priority}");
        return true;
    }

    // TODO: Implement actual email sending
    return false;
}

// ฟังก์ชันสำหรับ logging
function logActivity($userId, $action, $details = '', $requestId = null)
{
    $logData = [
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
        'request_id' => $requestId,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ];

    // บันทึกลงไฟล์ log หรือฐานข้อมูล
    if (DEBUG_MODE) {
        if (!is_dir('logs')) {
            mkdir('logs', 0755, true);
        }

        $logMessage = json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents('logs/activity.log', $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// ฟังก์ชันตรวจสอบการเข้าถึงไฟล์
function validateFileAccess($filePath, $userId, $userRole)
{
    // ตรวจสอบว่าไฟล์อยู่ในโฟลเดอร์ที่อนุญาต
    $allowedPaths = [
        '../uploads/requests/',
        '../uploads/evidence/',
        '../uploads/profile/'
    ];

    $isAllowed = false;
    foreach ($allowedPaths as $allowedPath) {
        if (strpos(realpath($filePath), realpath($allowedPath)) === 0) {
            $isAllowed = true;
            break;
        }
    }

    if (!$isAllowed) {
        return false;
    }

    // Admin และ teacher สามารถเข้าถึงไฟล์ทั้งหมดได้
    if (in_array($userRole, ['admin', 'teacher'])) {
        return true;
    }

    // นักศึกษาสามารถเข้าถึงเฉพาะไฟล์ของตัวเอง
    if ($userRole === 'student') {
        return strpos(basename($filePath), $userId . '_') === 0;
    }

    return false;
}

// การแสดงข้อผิดพลาด
if (SHOW_ERRORS) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// ตั้งค่า timezone สำหรับ MySQL
if (function_exists('getDB')) {
    try {
        $db = getDB();
        if ($db) {
            $db->execute("SET time_zone = '+07:00'");
        }
    } catch (Exception $e) {
        // ไม่ต้องทำอะไร หากเชื่อมต่อไม่ได้
    }
}

// ฟังก์ชันสำหรับการแปลงข้อมูล JSON
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ฟังก์ชันตรวจสอบ CSRF Token
function verifyCsrfToken($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ฟังก์ชันสร้าง CSRF Token
function generateCsrfToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ฟังก์ชันสำหรับการ redirect ที่ปลอดภัย
function safeRedirect($url, $default = 'index.php')
{
    // ตรวจสอบว่า URL เป็น relative path และไม่มี script injection
    if (filter_var($url, FILTER_VALIDATE_URL) === false && !preg_match('/^https?:\/\//', $url)) {
        if (preg_match('/^[a-zA-Z0-9_\-\/\.]+\.php(\?[a-zA-Z0-9_\-=&]+)?$/', $url)) {
            header("Location: $url");
            exit;
        }
    }

    header("Location: $default");
    exit;
}

// ============================================
// เพิ่มฟังก์ชันใหม่สำหรับระบบข้อร้องเรียน
// ============================================

// ฟังก์ชันตรวจสอบการอัพโหลดไฟล์ - เพิ่ม function_exists check
if (!function_exists('isImageFile')) {
    /**
     * ตรวจสอบว่าไฟล์เป็นรูปภาพหรือไม่
     * @param string $filename ชื่อไฟล์
     * @return bool true ถ้าเป็นรูปภาพ, false ถ้าไม่ใช่
     */
    function isImageFile($filename)
    {
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, $imageExtensions);
    }
}

// ฟังก์ชันสร้าง URL สำหรับไฟล์ - เพิ่ม function_exists check
if (!function_exists('getFileUrl')) {
    /**
     * สร้าง URL สำหรับไฟล์
     * @param string $filepath path ของไฟล์
     * @return string|null URL ของไฟล์ หรือ null ถ้าไฟล์ไม่มี
     */
    function getFileUrl($filepath)
    {
        if (empty($filepath) || !file_exists($filepath)) {
            return null;
        }

        // แปลง path ให้เป็น URL
        $relativePath = str_replace('../', '', $filepath);
        return SITE_URL . $relativePath;
    }
}

// ฟังก์ชันตรวจสอบสิทธิ์การเข้าถึงหน้า - เพิ่ม function_exists check
if (!function_exists('checkPagePermission')) {
    /**
     * ตรวจสอบสิทธิ์การเข้าถึงหน้า
     * @param string|null $requiredRole บทบาทที่ต้องการ
     * @param int|null $requiredPermission ระดับสิทธิ์ที่ต้องการ
     */
    function checkPagePermission($requiredRole = null, $requiredPermission = null)
    {
        if (!isLoggedIn()) {
            safeRedirect('login.php?message=login_required');
        }

        if ($requiredRole && !hasRole($requiredRole)) {
            safeRedirect('index.php?message=permission_denied');
        }

        if ($requiredPermission && isset($_SESSION['permission']) && $_SESSION['permission'] < $requiredPermission) {
            safeRedirect('index.php?message=insufficient_permission');
        }
    }
}

// ==========================================
// ฟังก์ชันสำหรับโหมดไม่ระบุตัวตน
// ==========================================

if (!function_exists('shouldShowPersonalInfo')) {
    /**
     * ตรวจสอบว่าควรแสดงข้อมูลส่วนตัวหรือไม่ตามโหมดไม่ระบุตัวตน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string|null $currentUserId ID ของผู้ใช้ปัจจุบัน
     * @return bool true ถ้าควรแสดงข้อมูลส่วนตัว
     */
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

if (!function_exists('canModifyRequest')) {
    /**
     * ตรวจสอบสิทธิ์การแก้ไข/ยกเลิกข้อร้องเรียน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string $currentUserId ID ของผู้ใช้ปัจจุบัน
     * @return bool true ถ้าสามารถแก้ไขได้
     */
    function canModifyRequest($request, $currentUserId)
    {
        // ตรวจสอบว่าเป็นเจ้าของข้อร้องเรียนหรือไม่
        if (!isset($request['Stu_id']) || $request['Stu_id'] !== $currentUserId) {
            return false;
        }

        // ตรวจสอบสถานะ - สามารถแก้ไขได้เฉพาะเมื่อยังเป็น pending (0)
        return isset($request['Re_status']) && $request['Re_status'] === '0';
    }
}

if (!function_exists('canEditRequest')) {
    /**
     * ตรวจสอบว่าสามารถแก้ไขข้อร้องเรียนได้หรือไม่ (รายละเอียดเพิ่มเติม)
     * @param int $requestId ID ของข้อร้องเรียน
     * @param string $userId ID ของผู้ใช้
     * @return array ผลการตรวจสอบ ['allowed' => bool, 'reason' => string]
     */
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
                    '4' => 'ปฏิเสธ/ยกเลิก'
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

// ==========================================
// ฟังก์ชันเพิ่มเติมสำหรับการจัดการข้อร้องเรียน
// ==========================================

if (!function_exists('getAnonymousDisplayName')) {
    /**
     * ได้ชื่อที่จะแสดงตามโหมดไม่ระบุตัวตน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string|null $currentUserId ID ของผู้ใช้ปัจจุบัน
     * @return string ชื่อที่ควรแสดง
     */
    function getAnonymousDisplayName($request, $currentUserId = null)
    {
        if (shouldShowPersonalInfo($request, $currentUserId)) {
            return $request['Stu_name'] ?? $request['student_name'] ?? 'ไม่ระบุชื่อ';
        }

        return 'ไม่ระบุตัวตน';
    }
}

if (!function_exists('getAnonymousDisplayId')) {
    /**
     * ได้รหัสนักศึกษาที่จะแสดงตามโหมดไม่ระบุตัวตน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string|null $currentUserId ID ของผู้ใช้ปัจจุบัน
     * @return string รหัสนักศึกษาที่ควรแสดง
     */
    function getAnonymousDisplayId($request, $currentUserId = null)
    {
        if (shouldShowPersonalInfo($request, $currentUserId)) {
            return $request['Stu_id'] ?? 'ไม่ทราบรหัส';
        }

        return 'ไม่ระบุรหัส';
    }
}

if (!function_exists('formatAnonymousInfo')) {
    /**
     * จัดรูปแบบข้อมูลการแสดงผลตามโหมดไม่ระบุตัวตน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string $field ฟิลด์ที่ต้องการแสดง
     * @param string|null $currentUserId ID ของผู้ใช้ปัจจุบัน
     * @return string ข้อมูลที่จัดรูปแบบแล้ว
     */
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

if (!function_exists('isRequestOwner')) {
    /**
     * ตรวจสอบว่าผู้ใช้เป็นเจ้าของข้อร้องเรียนหรือไม่
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string $userId ID ของผู้ใช้
     * @return bool true ถ้าเป็นเจ้าของ
     */
    function isRequestOwner($request, $userId)
    {
        return isset($request['Stu_id']) && $request['Stu_id'] === $userId;
    }
}

if (!function_exists('getRequestAccessLevel')) {
    /**
     * ได้ระดับการเข้าถึงข้อร้องเรียน
     * @param array $request ข้อมูลข้อร้องเรียน
     * @param string|null $userId ID ของผู้ใช้
     * @param string|null $userRole บทบาทของผู้ใช้
     * @return string ระดับการเข้าถึง (owner, staff, public, restricted)
     */
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

// ==========================================
// ฟังก์ชันตรวจสอบสถานะระบบ
// ==========================================

if (!function_exists('getSystemHealthStatus')) {
    /**
     * ตรวจสอบสถานะระบบ
     * @return array สถานะระบบ
     */
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

        // ตรวจสอบโฟลเดอร์ (ใช้ absolute path สำหรับ InfinityFree)
        $baseDir = dirname(__DIR__); // ได้ path ของโฟลเดอร์หลัก
        $status['uploads_dir'] = is_dir($baseDir . '/uploads') && is_writable($baseDir . '/uploads');
        $status['logs_dir'] = is_dir($baseDir . '/logs') && is_writable($baseDir . '/logs');

        // ตรวจสอบการใช้หน่วยความจำ
        $status['memory_usage'] = memory_get_usage(true);

        return $status;
    }
}

// เรียกใช้ฟังก์ชันตรวจสอบระบบในโหมด DEBUG
// ⚠️ สำหรับ InfinityFree: ส่วนนี้จะไม่ทำงานเพราะ DEBUG_MODE = false
if (DEBUG_MODE) {
    try {
        // ใช้ absolute path แทน relative path
        $baseDir = dirname(__DIR__);
        $requiredDirs = [
            $baseDir . '/uploads',
            $baseDir . '/uploads/requests',
            $baseDir . '/uploads/evidence',
            $baseDir . '/uploads/profiles',
            $baseDir . '/logs'
        ];
        foreach ($requiredDirs as $dir) {
            if (!@is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    } catch (Exception $e) {
        error_log("Config.php directory creation error: " . $e->getMessage());
    }
}