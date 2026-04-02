<?php
if (!defined('DB_HOST')) {
    // ใช้ __DIR__ เพื่อระบุว่าไฟล์ config.php อยู่ข้างๆ ไฟล์นี้
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
    } else {
        // กรณีหาไม่เจอจริงๆ ให้แจ้งเตือน
        die("Error: ไม่พบไฟล์ config.php ในโฟลเดอร์ " . __DIR__);
    }
}

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                die("Database connection failed. Please try again later.");
            }
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize a singleton.");
    }
}

// ฟังก์ชันช่วยเหลือสำหรับฐานข้อมูล
class DatabaseHelper
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function execute($sql, $params = [])
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError($e->getMessage(), $sql, $params);
            throw $e;
        }
    }

    public function fetch($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }

    public function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->execute($sql, $data);

        return $this->db->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $setClause = [];
        $params = [];

        // สร้าง SET clause ด้วย named parameters
        foreach ($data as $key => $value) {
            $setClause[] = "{$key} = :set_{$key}";
            $params["set_{$key}"] = $value;
        }
        $setClause = implode(', ', $setClause);

        // แปลง WHERE clause จาก ? เป็น named parameters
        $whereIndex = 0;
        $where = preg_replace_callback('/\?/', function ($matches) use (&$whereIndex, $whereParams, &$params) {
            $paramName = "where_{$whereIndex}";
            if (isset($whereParams[$whereIndex])) {
                $params[$paramName] = $whereParams[$whereIndex];
            }
            $whereIndex++;
            return ":{$paramName}";
        }, $where);

        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";

        return $this->execute($sql, $params);
    }

    public function delete($table, $where, $params = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->execute($sql, $params);
    }

    public function count($table, $where = '1=1', $params = [])
    {
        $sql = "SELECT COUNT(*) as count FROM {$table} WHERE {$where}";
        $result = $this->fetch($sql, $params);
        return $result['count'];
    }

    public function exists($table, $where, $params = [])
    {
        return $this->count($table, $where, $params) > 0;
    }

    public function beginTransaction()
    {
        return $this->db->beginTransaction();
    }

    public function commit()
    {
        return $this->db->commit();
    }

    public function rollback()
    {
        return $this->db->rollback();
    }

    public function fetchOne($sql, $params = [])
    {
        return $this->fetch($sql, $params);
    }

    /**
     * ดึง Last Insert ID
     */
    public function getLastInsertId()
    {
        return $this->db->lastInsertId();
    }

    private function logError($message, $sql = '', $params = [])
    {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $logMessage = date('Y-m-d H:i:s') . " - Database Error: {$message}\n";
            $logMessage .= "SQL: {$sql}\n";
            $logMessage .= "Params: " . json_encode($params) . "\n\n";

            if (!is_dir('logs')) {
                mkdir('logs', 0755, true);
            }

            file_put_contents('logs/database_errors.log', $logMessage, FILE_APPEND | LOCK_EX);
        }
    }
}

// สร้าง instance
$db = null;

function getDB()
{
    global $db;
    if ($db === null) {
        try {
            $db = new DatabaseHelper();
        } catch (Exception $e) {
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Cannot create DatabaseHelper: " . $e->getMessage());
            }
            return null;
        }
    }
    return $db;
}

// ==========================================
// ฟังก์ชันเฉพาะสำหรับระบบข้อร้องเรียน (อัพเดตสำหรับฐานข้อมูลใหม่)
// ==========================================

/**
 * ดึงข้อมูลนักศึกษาด้วย student_id
 */
function getStudentById($studentId)
{
    $db = getDB();
    if (!$db) return null;

    try {
        // อัพเดตให้ใช้ organization_unit แทน faculty และ major
        $sql = "SELECT s.*, 
                       major.Unit_name as major_name, major.Unit_icon as major_icon,
                       faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon
                FROM student s 
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                WHERE s.Stu_id = ?";
        return $db->fetch($sql, [$studentId]);
    } catch (Exception $e) {
        error_log("getStudentById error: " . $e->getMessage());
        return null;
    }
}

/**
 * ดึงข้อมูลอาจารย์ด้วย teacher_id
 */
function getTeacherById($teacherId)
{
    $db = getDB();
    if (!$db) return null;

    try {
        $sql = "SELECT * FROM teacher WHERE Aj_id = ?";
        return $db->fetch($sql, [$teacherId]);
    } catch (Exception $e) {
        error_log("getTeacherById error: " . $e->getMessage());
        return null;
    }
}

/**
 * ดึงข้อมูลอาจารย์ด้วยชื่อ
 */
function getTeacherByName($teacherName)
{
    $db = getDB();
    if (!$db) return null;

    try {
        $sql = "SELECT * FROM teacher WHERE Aj_name = ?";
        return $db->fetch($sql, [$teacherName]);
    } catch (Exception $e) {
        error_log("getTeacherByName error: " . $e->getMessage());
        return null;
    }
}

/**
 * ดึงข้อร้องเรียนของนักศึกษา
 */
function getStudentRequests($studentId, $status = '', $type = '', $priority = '')
{
    $db = getDB();
    if (!$db) return [];

    try {
        $sql = "SELECT r.*, t.Type_infor, t.Type_icon, s.Stu_name
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                WHERE r.Stu_id = ?";
        $params = [$studentId];

        if (!empty($status)) {
            $sql .= " AND r.Re_status = ?";
            $params[] = $status;
        }

        if (!empty($type)) {
            $sql .= " AND r.Type_id = ?";
            $params[] = $type;
        }

        if (!empty($priority)) {
            $sql .= " AND r.Re_level = ?";
            $params[] = $priority;
        }

        $sql .= " ORDER BY r.Re_date DESC, r.Re_id DESC";

        return $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        error_log("getStudentRequests error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงข้อร้องเรียนทั้งหมดสำหรับเจ้าหน้าที่
 */
function getAllRequestsForStaff($filters = [], $limit = 50, $offset = 0)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $sql = "SELECT r.*, t.Type_infor, t.Type_icon, 
                       CASE r.Re_iden 
                           WHEN 1 THEN 'ไม่ระบุตัวตน' 
                           ELSE s.Stu_name 
                       END as requester_name,
                       s.Stu_id, 
                       major.Unit_name as major_name,
                       faculty.Unit_name as faculty_name,
                       sr.Sv_infor as staff_response, sr.Sv_date as response_date,
                       aj.Aj_name as staff_name, aj.Aj_position,
                       asn.Aj_name as assigned_name
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                LEFT JOIN teacher asn ON r.Aj_id = asn.Aj_id
                WHERE r.Re_is_spam = 0";

        $params = [];

        // Apply filters
        if (!empty($filters['status'])) {
            $sql .= " AND r.Re_status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $sql .= " AND r.Type_id = ?";
            $params[] = $filters['type'];
        }

        if (!empty($filters['level'])) {
            $sql .= " AND r.Re_level = ?";
            $params[] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (r.Re_infor LIKE ? OR r.Re_title LIKE ? OR s.Stu_name LIKE ? OR s.Stu_id LIKE ?)";
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND r.Re_date >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND r.Re_date <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['assigned_to'])) {
            $sql .= " AND r.Aj_id = ?";
            $params[] = $filters['assigned_to'];
        }

        $sql .= " ORDER BY r.Re_level DESC, r.Re_date DESC, r.Re_id DESC";

        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        return $db->fetchAll($sql, $params);
    } catch (Exception $e) {
        error_log("getAllRequestsForStaff error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงรายละเอียดข้อร้องเรียนสำหรับเจ้าหน้าที่
 */
function getRequestDetailForStaff($requestId)
{
    $db = getDB();
    if (!$db) return null;

    try {
        $sql = "SELECT r.*, t.Type_infor, t.Type_icon, 
                       CASE r.Re_iden 
                           WHEN 1 THEN 'ไม่ระบุตัวตน' 
                           ELSE s.Stu_name 
                       END as requester_name,
                       s.Stu_id, s.Stu_tel, s.Stu_email,
                       major.Unit_name as major_name,
                       faculty.Unit_name as faculty_name,
                       sr.Sv_infor as staff_response, sr.Sv_date as response_date, sr.Sv_type, sr.Sv_note,
                       aj.Aj_name as staff_name, aj.Aj_position,
                       asn.Aj_name as assigned_name, asn.Aj_position as assigned_position,
                       rr.Result_date,
                       e.Eva_score, e.Eva_sug as evaluation_comment, e.created_at as evaluation_date
                FROM request r 
                LEFT JOIN type t ON r.Type_id = t.Type_id 
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
                LEFT JOIN teacher aj ON sr.Aj_id = aj.Aj_id
                LEFT JOIN teacher asn ON r.Aj_id = asn.Aj_id
                LEFT JOIN result_request rr ON r.Re_id = rr.Re_id
                LEFT JOIN evaluation e ON r.Re_id = e.Re_id
                WHERE r.Re_id = ?";

        return $db->fetch($sql, [$requestId]);
    } catch (Exception $e) {
        error_log("getRequestDetailForStaff error: " . $e->getMessage());
        return null;
    }
}

/**
 * บันทึกการจัดการข้อร้องเรียนโดยเจ้าหน้าที่
 */
function saveStaffResponse($requestId, $teacherId, $response, $type = 'process', $note = '')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $db->beginTransaction();

        // บันทึกการตอบกลับ
        $saveData = [
            'Sv_infor' => $response,
            'Sv_type' => $type,
            'Sv_note' => $note,
            'Sv_date' => date('Y-m-d'),
            'Re_id' => $requestId,
            'Aj_id' => $teacherId
        ];

        $saveId = $db->insert('save_request', $saveData);

        if ($saveId) {
            // อัปเดตสถานะเป็น "ยืนยันแล้ว" (1)
            $db->update('request', ['Re_status' => '1'], 'Re_id = ?', [$requestId]);

            // สร้างการแจ้งเตือนให้นักศึกษา
            $request = $db->fetch("SELECT Stu_id, Re_infor FROM request WHERE Re_id = ?", [$requestId]);
            if ($request && $request['Stu_id']) {
                createNotification(
                    'ข้อร้องเรียนของคุณได้รับการตอบกลับแล้ว',
                    'ข้อร้องเรียน: ' . mb_substr($request['Re_infor'], 0, 50) . '... ได้รับการตอบกลับจากเจ้าหน้าที่แล้ว',
                    $requestId,
                    $request['Stu_id'],
                    null,
                    $teacherId
                );
            }

            $db->commit();
            return $saveId;
        }

        $db->rollback();
        return false;
    } catch (Exception $e) {
        $db->rollback();
        error_log("saveStaffResponse error: " . $e->getMessage());
        return false;
    }
}

/**
 * อัปเดตระดับความสำคัญและมอบหมายงาน
 */
function updateRequestPriorityAndAssign($requestId, $priority, $assignTo = null, $deadline = null)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $updateData = ['Re_level' => $priority];

        if ($assignTo !== null) {
            $updateData['Aj_id'] = $assignTo;
        }

        if ($deadline !== null) {
            $updateData['Re_deadline'] = $deadline;
        }

        return $db->update('request', $updateData, 'Re_id = ?', [$requestId]);
    } catch (Exception $e) {
        error_log("updateRequestPriorityAndAssign error: " . $e->getMessage());
        return false;
    }
}

/**
 * บันทึกผลการดำเนินการ
 */
function saveRequestResult($requestId, $teacherId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $db->beginTransaction();

        // ตรวจสอบว่ามี save_request หรือไม่
        $saveRequest = $db->fetch("SELECT Sv_id FROM save_request WHERE Re_id = ? ORDER BY created_at DESC LIMIT 1", [$requestId]);

        if (!$saveRequest) {
            $db->rollback();
            return false;
        }

        // บันทึกผลการดำเนินการ
        $resultData = [
            'Result_date' => date('Y-m-d'),
            'Sv_id' => $saveRequest['Sv_id'],
            'Re_id' => $requestId,
            'Aj_id' => $teacherId
        ];

        $resultId = $db->insert('result_request', $resultData);

        if ($resultId) {
            // อัปเดตสถานะเป็น "บันทึกผลการดำเนินงานแล้ว" (2)
            $db->update('request', ['Re_status' => '2'], 'Re_id = ?', [$requestId]);

            // สร้างการแจ้งเตือนให้นักศึกษา
            $request = $db->fetch("SELECT Stu_id, Re_infor FROM request WHERE Re_id = ?", [$requestId]);
            if ($request && $request['Stu_id']) {
                createNotification(
                    'ข้อร้องเรียนของคุณดำเนินการเสร็จสิ้นแล้ว',
                    'ข้อร้องเรียน: ' . mb_substr($request['Re_infor'], 0, 50) . '... ได้ดำเนินการเสร็จสิ้นแล้ว กรุณาประเมินความพึงพอใจ',
                    $requestId,
                    $request['Stu_id'],
                    null,
                    $teacherId
                );
            }

            $db->commit();
            return $resultId;
        }

        $db->rollback();
        return false;
    } catch (Exception $e) {
        $db->rollback();
        error_log("saveRequestResult error: " . $e->getMessage());
        return false;
    }
}

/**
 * ดึง Re_id ต่อไปที่ควรใช้ (เพื่อให้ต่อเนื่อง)
 */
function getNextRequestId()
{
    $db = getDB();
    if (!$db) return 1;

    try {
        $result = $db->fetch("SELECT MAX(Re_id) as max_id FROM request");
        return ($result['max_id'] ?? 0) + 1;
    } catch (Exception $e) {
        error_log("getNextRequestId error: " . $e->getMessage());
        return 1;
    }
}

/**
 * สร้างข้อร้องเรียนใหม่ - แก้ไขแล้ว: ส่ง Stu_id ไปเสมอ
 */
function createNewRequest($studentId, $typeId, $title, $information, $level = '2', $isAnonymous = 0)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $requestData = [
            'Re_title' => $title,
            'Re_infor' => $information,
            'Re_status' => '0', // รอยืนยัน
            'Re_level' => $level,
            'Re_iden' => $isAnonymous ? 1 : 0, // 0=ระบุตัวตน, 1=ไม่ระบุตัวตน
            'Re_date' => date('Y-m-d'),
            'Stu_id' => $studentId, // **แก้ไขแล้ว: ส่งไปเสมอ เพื่อให้สามารถติดตามได้**
            'Type_id' => $typeId
        ];

        return $db->insert('request', $requestData);
    } catch (Exception $e) {
        error_log("createNewRequest error: " . $e->getMessage());
        return false;
    }
}

/**
 * อัปเดตข้อร้องเรียน
 */
function updateRequest($requestId, $title, $information, $typeId, $level = null)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $updateData = [
            'Re_title' => $title,
            'Re_infor' => $information,
            'Type_id' => $typeId
        ];

        if ($level !== null) {
            $updateData['Re_level'] = $level;
        }

        return $db->update('request', $updateData, 'Re_id = ?', [$requestId]);
    } catch (Exception $e) {
        error_log("updateRequest error: " . $e->getMessage());
        return false;
    }
}

/**
 * ดึงข้อร้องเรียนที่เสร็จสิ้นแล้วสำหรับประเมิน
 */
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

/**
 * บันทึกการประเมิน
 */
function submitEvaluation($requestId, $score, $suggestion = '')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $db->beginTransaction();

        // ตรวจสอบว่าประเมินแล้วหรือไม่
        $existing = $db->fetch("SELECT Eva_id FROM evaluation WHERE Re_id = ?", [$requestId]);
        if ($existing) {
            $db->rollback();
            return false; // ประเมินแล้ว
        }

        $evaluationData = [
            'Eva_score' => $score,
            'Eva_sug' => $suggestion,
            'Re_id' => $requestId
        ];

        $result = $db->insert('evaluation', $evaluationData);

        if ($result) {
            // อัปเดตสถานะข้อร้องเรียนเป็น "ประเมินแล้ว" (3)
            $db->update('request', ['Re_status' => '3'], 'Re_id = ?', [$requestId]);

            // สร้างการแจ้งเตือนให้เจ้าหน้าที่
            $requestDetail = $db->fetch("
                SELECT r.Re_infor, sr.Aj_id 
                FROM request r 
                LEFT JOIN save_request sr ON r.Re_id = sr.Re_id 
                WHERE r.Re_id = ?
            ", [$requestId]);

            if ($requestDetail && $requestDetail['Aj_id']) {
                createNotification(
                    'มีการประเมินความพึงพอใจใหม่',
                    'ข้อร้องเรียน: ' . mb_substr($requestDetail['Re_infor'], 0, 50) . '... ได้รับการประเมินแล้ว (คะแนน: ' . $score . '/5)',
                    $requestId,
                    null,
                    $requestDetail['Aj_id']
                );
            }

            $db->commit();
            return $result;
        }

        $db->rollback();
        return false;
    } catch (Exception $e) {
        $db->rollback();
        error_log("submitEvaluation error: " . $e->getMessage());
        return false;
    }
}

/**
 * บันทึกหลักฐานประกอบ (อัปเดตให้ใช้โครงสร้างใหม่)
 */
function saveSupportingEvidence($requestId, $filename, $filepath, $filetype, $filesize, $uploadBy)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $evidenceData = [
            'Sup_filename' => $filename,
            'Sup_filepath' => $filepath,
            'Sup_filetype' => $filetype,
            'Sup_filesize' => $filesize,
            'Sup_upload_by' => $uploadBy,
            'Re_id' => $requestId
        ];

        return $db->insert('supporting_evidence', $evidenceData);
    } catch (Exception $e) {
        error_log("saveSupportingEvidence error: " . $e->getMessage());
        return false;
    }
}

/**
 * ดึงหลักฐานประกอบ
 */
function getSupportingEvidence($requestId)
{
    $db = getDB();
    if (!$db) return [];

    try {
        return $db->fetchAll("
            SELECT se.*, s.Stu_name as uploader_name 
            FROM supporting_evidence se
            LEFT JOIN student s ON se.Sup_upload_by = s.Stu_id
            WHERE se.Re_id = ? 
            ORDER BY se.Sup_upload_date DESC
        ", [$requestId]);
    } catch (Exception $e) {
        error_log("getSupportingEvidence error: " . $e->getMessage());
        return [];
    }
}

/**
 * ลบหลักฐานประกอบ
 */
function deleteSupportingEvidence($evidenceId, $userId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        // ตรวจสอบสิทธิ์
        $evidence = $db->fetch("SELECT * FROM supporting_evidence WHERE Sup_id = ?", [$evidenceId]);
        if (!$evidence || $evidence['Sup_upload_by'] !== $userId) {
            return false;
        }

        // ลบไฟล์จากเซิร์ฟเวอร์
        if (file_exists($evidence['Sup_filepath'])) {
            unlink($evidence['Sup_filepath']);
        }

        // ลบจากฐานข้อมูล
        return $db->delete('supporting_evidence', 'Sup_id = ?', [$evidenceId]);
    } catch (Exception $e) {
        error_log("deleteSupportingEvidence error: " . $e->getMessage());
        return false;
    }
}

/**
 * สร้างการแจ้งเตือน
 */
function createNotification($title, $message, $requestId = null, $studentId = null, $teacherId = null, $createdBy = null, $type = 'system')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $notificationData = [
            'Noti_title' => $title,
            'Noti_message' => $message,
            'Noti_type' => $type,
            'Re_id' => $requestId,
            'Stu_id' => $studentId,
            'Aj_id' => $teacherId,
            'created_by' => $createdBy
        ];

        return $db->insert('notification', $notificationData);
    } catch (Exception $e) {
        error_log("createNotification error: " . $e->getMessage());
        return false;
    }
}

/**
 * ดึงการแจ้งเตือนของผู้ใช้
 */
function getUserNotifications($userId, $userType = 'student', $limit = 10)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $sql = "SELECT n.*, r.Re_infor, r.Re_title
                FROM notification n
                LEFT JOIN request r ON n.Re_id = r.Re_id
                WHERE n.{$field} = ?
                ORDER BY n.Noti_date DESC";

        if ($limit > 0) {
            $sql .= " LIMIT ?";
            return $db->fetchAll($sql, [$userId, $limit]);
        }

        return $db->fetchAll($sql, [$userId]);
    } catch (Exception $e) {
        error_log("getUserNotifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * อัปเดตสถานะการอ่านการแจ้งเตือน
 */
function markNotificationAsRead($notificationId, $userId = null)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $whereClause = 'Noti_id = ?';
        $params = [$notificationId];

        if ($userId) {
            $whereClause .= ' AND (Stu_id = ? OR Aj_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }

        return $db->update('notification', ['Noti_status' => 1], $whereClause, $params);
    } catch (Exception $e) {
        error_log("markNotificationAsRead error: " . $e->getMessage());
        return false;
    }
}

/**
 * ดึงรายการคณะและสาขา - อัพเดตให้ใช้ organization_unit
 */
function getFacultiesList()
{
    $db = getDB();
    if (!$db) return [];

    try {
        return $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_type = 'faculty' 
            ORDER BY Unit_name
        ");
    } catch (Exception $e) {
        error_log("getFacultiesList error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงรายการสาขาตามคณะ - อัพเดตให้ใช้ organization_unit
 */
function getMajorsByFaculty($facultyId)
{
    $db = getDB();
    if (!$db) return [];

    try {
        return $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_parent_id = ? AND Unit_type = 'major'
            ORDER BY Unit_name
        ", [$facultyId]);
    } catch (Exception $e) {
        error_log("getMajorsByFaculty error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงรายการแผนก/หน่วยงาน - อัพเดตให้ใช้ organization_unit
 */
function getDepartmentsList()
{
    $db = getDB();
    if (!$db) return [];

    try {
        return $db->fetchAll("
            SELECT Unit_id, Unit_name, Unit_icon 
            FROM organization_unit 
            WHERE Unit_type = 'department'
            ORDER BY Unit_name
        ");
    } catch (Exception $e) {
        error_log("getDepartmentsList error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงรายการประเภทข้อร้องเรียน
 */
function getComplaintTypesList()
{
    $db = getDB();
    if (!$db) return [];

    try {
        // เรียงลำดับ "อื่นๆ" ไว้ล่างสุดเสมอ
        return $db->fetchAll("SELECT * FROM type ORDER BY CASE WHEN Type_infor = 'อื่นๆ' THEN 1 ELSE 0 END, Type_infor ASC");
    } catch (Exception $e) {
        error_log("getComplaintTypesList error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงสถิติสำหรับแดชบอร์ดเจ้าหน้าที่
 */
function getStaffDashboardStats()
{
    $db = getDB();
    if (!$db) return [];

    try {
        $stats = [];

        // ลบเงื่อนไข Re_is_spam = 0 ออกทั้งหมด เพื่อให้ตรงกับ Database จริง

        // ข้อร้องเรียนทั้งหมด
        $stats['total'] = $db->count('request', '1=1');

        // แยกตามสถานะ
        $stats['pending'] = $db->count('request', 'Re_status = ?', ['0']);
        $stats['confirmed'] = $db->count('request', 'Re_status = ?', ['1']);
        $stats['completed'] = $db->count('request', 'Re_status = ?', ['2']);
        $stats['evaluated'] = $db->count('request', 'Re_status = ?', ['3']);

        // แยกตามระดับความสำคัญ
        $stats['urgent'] = $db->count('request', 'Re_level = ?', ['4']);
        $stats['high'] = $db->count('request', 'Re_level = ?', ['3']);
        $stats['medium'] = $db->count('request', 'Re_level = ?', ['2']);
        $stats['low'] = $db->count('request', 'Re_level = ?', ['1']);

        // ข้อร้องเรียนวันนี้
        $stats['today'] = $db->count('request', 'DATE(Re_date) = CURDATE()');

        // ข้อร้องเรียนสัปดาห์นี้
        $stats['this_week'] = $db->count('request', 'YEARWEEK(Re_date, 1) = YEARWEEK(CURDATE(), 1)');

        // ข้อร้องเรียนเดือนนี้
        $stats['this_month'] = $db->count('request', 'YEAR(Re_date) = YEAR(CURDATE()) AND MONTH(Re_date) = MONTH(CURDATE())');

        // คะแนนประเมินเฉลี่ย
        $avgRating = $db->fetch("SELECT AVG(Eva_score) as avg_rating FROM evaluation WHERE Eva_score > 0");
        $stats['avg_rating'] = round($avgRating['avg_rating'] ?? 0, 1);

        // ค่าอื่นๆ ที่อาจจะ error ให้ใส่เป็น 0 ไว้ก่อน
        $stats['avg_response_hours'] = 0;
        $stats['type_stats'] = [];
        $stats['spam'] = 0;

        return $stats;
    } catch (Exception $e) {
        error_log("getStaffDashboardStats error: " . $e->getMessage());
        return [];
    }
}

/**
 * ดึงสถิติของนักศึกษา
 */
function getStudentRequestStats($studentId)
{
    $db = getDB();
    if (!$db) return ['total' => 0, 'pending' => 0, 'completed' => 0, 'avg_rating' => 0];

    try {
        $stats = [];

        // ข้อร้องเรียนทั้งหมด
        $stats['total'] = $db->count('request', 'Stu_id = ?', [$studentId]);

        // ข้อร้องเรียนที่รอดำเนินการ (สถานะ 0, 1)
        $stats['pending'] = $db->count('request', 'Stu_id = ? AND Re_status IN (?, ?)', [$studentId, '0', '1']);

        // ข้อร้องเรียนที่เสร็จสิ้น (สถานะ 3)
        $stats['completed'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '3']);

        // ข้อร้องเรียนที่กำลังดำเนินการ (สถานะ 2)
        $stats['processing'] = $db->count('request', 'Stu_id = ? AND Re_status = ?', [$studentId, '2']);

        // คะแนนเฉลี่ยการประเมิน
        $result = $db->fetch(
            "SELECT AVG(e.Eva_score) as avg_rating 
             FROM evaluation e 
             JOIN request r ON e.Re_id = r.Re_id 
             WHERE r.Stu_id = ? AND e.Eva_score > 0",
            [$studentId]
        );

        $stats['avg_rating'] = $result && $result['avg_rating'] ? round($result['avg_rating'], 1) : 0;

        return $stats;
    } catch (Exception $e) {
        error_log("getStudentRequestStats error: " . $e->getMessage());
        return ['total' => 0, 'pending' => 0, 'completed' => 0, 'processing' => 0, 'avg_rating' => 0];
    }
}

/**
 * ตรวจสอบสิทธิ์การเข้าถึงข้อร้องเรียน
 */
function canAccessRequest($requestId, $studentId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $request = $db->fetch("SELECT Re_id FROM request WHERE Re_id = ? AND Stu_id = ?", [$requestId, $studentId]);
        return $request !== false;
    } catch (Exception $e) {
        error_log("canAccessRequest error: " . $e->getMessage());
        return false;
    }
}

/**
 * อัปเดตสถานะข้อร้องเรียน
 */
function updateRequestStatus($requestId, $status)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->update('request', ['Re_status' => $status], 'Re_id = ?', [$requestId]);
    } catch (Exception $e) {
        error_log("updateRequestStatus error: " . $e->getMessage());
        return false;
    }
}

/**
 * มาร์คข้อร้องเรียนเป็น spam
 */
function markRequestAsSpam($requestId, $teacherId, $reason = '')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $db->beginTransaction();

        // อัปเดตสถานะ spam
        $result = $db->update('request', [
            'Re_is_spam' => 1,
            'Re_spam_by' => $teacherId,
            'Re_spam_date' => date('Y-m-d')
        ], 'Re_id = ?', [$requestId]);

        if ($result && !empty($reason)) {
            // บันทึกรายงาน abuse
            $reportData = [
                'Re_id' => $requestId,
                'Ra_reason' => $reason,
                'Ra_report_by' => $teacherId,
                'Ra_report_date' => date('Y-m-d')
            ];
            $db->insert('report_abuse', $reportData);
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollback();
        error_log("markRequestAsSpam error: " . $e->getMessage());
        return false;
    }
}

/**
 * ยกเลิกการมาร์ค spam
 */
function unmarkRequestAsSpam($requestId)
{
    $db = getDB();
    if (!$db) return false;

    try {
        return $db->update('request', [
            'Re_is_spam' => 0,
            'Re_spam_by' => null,
            'Re_spam_date' => null
        ], 'Re_id = ?', [$requestId]);
    } catch (Exception $e) {
        error_log("unmarkRequestAsSpam error: " . $e->getMessage());
        return false;
    }
}

/**
 * ตรวจสอบและสร้างตาราง
 */
function createTablesIfNotExist()
{
    $db = getDB();
    if (!$db) return false;

    try {
        $result = $db->fetch("SHOW TABLES LIKE 'student'");

        if (!$result) {
            throw new Exception('ฐานข้อมูลยังไม่ได้ถูกสร้าง กรุณารันไฟล์ install.php ก่อน');
        }

        return true;
    } catch (Exception $e) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            die('Database Error: ' . $e->getMessage());
        } else {
            die('ข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล');
        }
    }
}

// ตรวจสอบและสร้างตารางเมื่อโหลดไฟล์
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    try {
        createTablesIfNotExist();
    } catch (Exception $e) {
        if (isset($_SERVER['PHP_SELF']) && strpos($_SERVER['PHP_SELF'], 'install.php') === false) {
            if (php_sapi_name() !== 'cli' && !headers_sent()) {
                echo '<div style="background: #f8d7da; color: #721c24; padding: 20px; margin: 20px; border-radius: 10px; text-align: center;">';
                echo '<h3>⚠️ ต้องติดตั้งฐานข้อมูลก่อน</h3>';
                echo '<p>กรุณารันไฟล์ <a href="install.php" style="color: #721c24; font-weight: bold;">install.php</a> เพื่อติดตั้งฐานข้อมูล</p>';
                echo '</div>';
            }
        }
    }
}

// เพิ่มฟังก์ชันใหม่สำหรับระบบการแจ้งเตือน ที่ส่วนท้ายของไฟล์ database.php

/**
 * ดึงจำนวนการแจ้งเตือนที่ยังไม่ได้อ่าน
 */
function getUnreadNotificationCount($userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return 0;

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $result = $db->fetch("
            SELECT COUNT(*) as unread_count 
            FROM notification 
            WHERE {$field} = ? AND Noti_status = 0
        ", [$userId]);

        return $result['unread_count'] ?? 0;
    } catch (Exception $e) {
        error_log("getUnreadNotificationCount error: " . $e->getMessage());
        return 0;
    }
}

/**
 * ดึงการแจ้งเตือนล่าสุด
 */
function getRecentNotifications($userId, $userType = 'student', $limit = 10)
{
    $db = getDB();
    if (!$db) return [];

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $sql = "SELECT n.*, r.Re_title, r.Re_infor 
                FROM notification n
                LEFT JOIN request r ON n.Re_id = r.Re_id
                WHERE n.{$field} = ?
                ORDER BY n.Noti_date DESC";

        if ($limit > 0) {
            $sql .= " LIMIT ?";
            return $db->fetchAll($sql, [$userId, $limit]);
        }

        return $db->fetchAll($sql, [$userId]);
    } catch (Exception $e) {
        error_log("getRecentNotifications error: " . $e->getMessage());
        return [];
    }
}

/**
 * มาร์คการแจ้งเตือนทีละรายการว่าอ่านแล้ว
 */
function markSingleNotificationAsRead($notificationId, $userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $result = $db->execute("
            UPDATE notification 
            SET Noti_status = 1 
            WHERE Noti_id = ? AND {$field} = ?
        ", [$notificationId, $userId]);

        return $result !== false;
    } catch (Exception $e) {
        error_log("markSingleNotificationAsRead error: " . $e->getMessage());
        return false;
    }
}

/**
 * มาร์คการแจ้งเตือนทั้งหมดว่าอ่านแล้ว
 */
function markAllNotificationsAsRead($userId, $userType = 'student')
{
    $db = getDB();
    if (!$db) return false;

    try {
        $field = ($userType === 'student') ? 'Stu_id' : 'Aj_id';

        $result = $db->execute("
            UPDATE notification 
            SET Noti_status = 1 
            WHERE {$field} = ? AND Noti_status = 0
        ", [$userId]);

        return $result !== false;
    } catch (Exception $e) {
        error_log("markAllNotificationsAsRead error: " . $e->getMessage());
        return false;
    }
}

/**
 * ส่งการแจ้งเตือนอัตโนมัติเมื่อมีการเปลี่ยนแปลงสถานะ
 */
function sendAutoNotification($type, $requestId, $recipientId, $recipientType = 'student', $createdBy = null)
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
        ],
        'evaluation_received' => [
            'title' => 'มีการประเมินความพึงพอใจใหม่',
            'message' => 'ข้อร้องเรียนได้รับการประเมินความพึงพอใจแล้ว'
        ]
    ];

    if (!isset($templates[$type])) {
        error_log("sendAutoNotification: Unknown notification type: {$type}");
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
        $teacherId,
        $createdBy
    );
}

/**
 * ดึงสถิติการแจ้งเตือน
 */
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

/**
 * ลบการแจ้งเตือนเก่า (เก็บไว้เฉพาะ 30 วันล่าสุด)
 */
function cleanupOldNotifications($days = 30)
{
    $db = getDB();
    if (!$db) return false;

    try {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $db->execute("
            DELETE FROM notification 
            WHERE Noti_date < ? AND Noti_status = 1
        ", [$cutoffDate]);

        return $result !== false;
    } catch (Exception $e) {
        error_log("cleanupOldNotifications error: " . $e->getMessage());
        return false;
    }
}

/**
 * ตรวจสอบการแจ้งเตือนที่ค้างส่ง (สำหรับ cron job)
 */
function processPendingNotifications()
{
    $db = getDB();
    if (!$db) return false;

    try {
        // ดึงข้อร้องเรียนที่ยังไม่ได้รับการตอบกลับเกิน 24 ชั่วโมง
        $overdueRequests = $db->fetchAll("
            SELECT r.*, s.Stu_name 
            FROM request r
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            WHERE r.Re_status = '0' 
              AND r.Re_date < DATE_SUB(NOW(), INTERVAL 24 HOUR)
              AND r.Re_is_spam = 0
              AND NOT EXISTS (
                  SELECT 1 FROM notification n 
                  WHERE n.Re_id = r.Re_id 
                    AND n.Noti_title LIKE '%เตือนความจำ%'
                    AND n.Noti_date > DATE_SUB(NOW(), INTERVAL 1 DAY)
              )
        ");

        foreach ($overdueRequests as $request) {
            // ส่งการแจ้งเตือนให้เจ้าหน้าที่
            createNotification(
                'เตือนความจำ: ข้อร้องเรียนค้างการตอบกลับ',
                'ข้อร้องเรียน ID: ' . $request['Re_id'] . ' ยังไม่ได้รับการตอบกลับเกิน 24 ชั่วโมง',
                $request['Re_id'],
                null, // ไม่ส่งให้นักศึกษา
                null, // ส่งให้เจ้าหน้าที่ทั้งหมด (จะต้องปรับปรุงให้ส่งตาม department)
                null,
                'system'
            );
        }

        return count($overdueRequests);
    } catch (Exception $e) {
        error_log("processPendingNotifications error: " . $e->getMessage());
        return false;
    }
}