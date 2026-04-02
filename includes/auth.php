<?php
if (!defined('SECURE_ACCESS')) {
    exit('Direct access not allowed');
}

class Auth
{
    private $db;

    public function __construct()
    {
        try {
            // ลองใช้ getDB() function ก่อน
            if (function_exists('getDB')) {
                $this->db = getDB();
                if ($this->db) {
                    $this->startSession();
                    return; // สำเร็จแล้ว
                }
            }

            // ถ้าไม่สำเร็จ ลองสร้าง DatabaseHelper
            if (class_exists('DatabaseHelper')) {
                $this->db = new DatabaseHelper();
                if ($this->db) {
                    $this->startSession();
                    return; // สำเร็จแล้ว
                }
            }

            // ถ้ายังไม่สำเร็จ สร้าง PDO โดยตรง
            throw new Exception('Cannot use existing database helpers');
        } catch (Exception $e) {
            // สร้าง PDO connection โดยตรงและใช้ wrapper
            try {
                $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

                // สร้าง wrapper สำหรับ PDO
                $this->db = new class($pdo) {
                    private $pdo;

                    public function __construct($pdo)
                    {
                        $this->pdo = $pdo;
                    }

                    public function fetch($sql, $params = [])
                    {
                        try {
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute($params);
                            return $stmt->fetch();
                        } catch (PDOException $e) {
                            error_log("Database fetch error: " . $e->getMessage());
                            error_log("SQL: " . $sql);
                            error_log("Params: " . print_r($params, true));
                            return false;
                        }
                    }

                    public function fetchAll($sql, $params = [])
                    {
                        try {
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute($params);
                            return $stmt->fetchAll();
                        } catch (PDOException $e) {
                            error_log("Database fetchAll error: " . $e->getMessage());
                            error_log("SQL: " . $sql);
                            error_log("Params: " . print_r($params, true));
                            return [];
                        }
                    }

                    public function execute($sql, $params = [])
                    {
                        try {
                            $stmt = $this->pdo->prepare($sql);
                            return $stmt->execute($params);
                        } catch (PDOException $e) {
                            error_log("Database execute error: " . $e->getMessage());
                            error_log("SQL: " . $sql);
                            error_log("Params: " . print_r($params, true));
                            return false;
                        }
                    }

                    public function insert($table, $data)
                    {
                        try {
                            $columns = implode('`, `', array_keys($data));
                            $placeholders = implode(', ', array_fill(0, count($data), '?'));
                            $sql = "INSERT INTO `{$table}` (`{$columns}`) VALUES ({$placeholders})";

                            error_log("Auth Insert SQL: " . $sql);
                            error_log("Auth Insert Data: " . print_r(array_values($data), true));

                            $stmt = $this->pdo->prepare($sql);
                            $result = $stmt->execute(array_values($data));

                            if ($result) {
                                $rowCount = $stmt->rowCount();
                                error_log("Auth Insert successful, affected rows: " . $rowCount);

                                if ($rowCount > 0) {
                                    $lastId = $this->pdo->lastInsertId();
                                    error_log("Auth Last insert ID: " . ($lastId ?: 'NONE (non-auto-increment)'));
                                    return $lastId > 0 ? $lastId : true;
                                } else {
                                    error_log("Auth Insert executed but no rows affected");
                                    return false;
                                }
                            } else {
                                error_log("Auth Insert execution failed");
                                return false;
                            }
                        } catch (PDOException $e) {
                            error_log("Auth Database insert error: " . $e->getMessage());
                            error_log("Auth SQL: " . ($sql ?? 'N/A'));
                            error_log("Auth Data: " . print_r($data, true));
                            return false;
                        }
                    }

                    public function verifyInsert($table, $conditions)
                    {
                        try {
                            $whereClause = [];
                            $params = [];

                            foreach ($conditions as $column => $value) {
                                $whereClause[] = "`{$column}` = ?";
                                $params[] = $value;
                            }

                            $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE " . implode(' AND ', $whereClause);
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute($params);
                            $result = $stmt->fetch();

                            error_log("Auth Verify insert - SQL: " . $sql);
                            error_log("Auth Verify insert - Params: " . print_r($params, true));
                            error_log("Auth Verify insert - Count: " . ($result['count'] ?? 0));

                            return ($result['count'] ?? 0) > 0;
                        } catch (PDOException $e) {
                            error_log("Auth Verify insert error: " . $e->getMessage());
                            return false;
                        }
                    }

                    public function beginTransaction()
                    {
                        try {
                            return $this->pdo->beginTransaction();
                        } catch (PDOException $e) {
                            error_log("Auth Begin transaction error: " . $e->getMessage());
                            return false;
                        }
                    }

                    public function commit()
                    {
                        try {
                            return $this->pdo->commit();
                        } catch (PDOException $e) {
                            error_log("Auth Commit error: " . $e->getMessage());
                            return false;
                        }
                    }

                    public function rollback()
                    {
                        try {
                            return $this->pdo->rollBack();
                        } catch (PDOException $e) {
                            error_log("Auth Rollback error: " . $e->getMessage());
                            return false;
                        }
                    }

                    public function update($table, $data, $where, $whereParams = [])
                    {
                        try {
                            $setClause = [];
                            $params = [];

                            foreach ($data as $key => $value) {
                                $setClause[] = "`{$key}` = ?";
                                $params[] = $value;
                            }

                            foreach ($whereParams as $param) {
                                $params[] = $param;
                            }

                            $setClause = implode(', ', $setClause);
                            $sql = "UPDATE `{$table}` SET {$setClause} WHERE {$where}";

                            error_log("Auth Update SQL: " . $sql);
                            error_log("Auth Update Params: " . print_r($params, true));

                            $stmt = $this->pdo->prepare($sql);
                            return $stmt->execute($params);
                        } catch (PDOException $e) {
                            error_log("Auth Database update error: " . $e->getMessage());
                            error_log("Auth SQL: " . $sql);
                            error_log("Auth Params: " . print_r($params, true));
                            return false;
                        }
                    }

                    public function count($table, $where = '1=1', $params = [])
                    {
                        try {
                            $sql = "SELECT COUNT(*) as count FROM `{$table}` WHERE {$where}";
                            $stmt = $this->pdo->prepare($sql);
                            $stmt->execute($params);
                            $result = $stmt->fetch();
                            return $result['count'] ?? 0;
                        } catch (PDOException $e) {
                            error_log("Auth Database count error: " . $e->getMessage());
                            error_log("Auth SQL: " . $sql);
                            error_log("Auth Params: " . print_r($params, true));
                            return 0;
                        }
                    }
                };
            } catch (Exception $e2) {
                throw new Exception('Database connection failed: ' . $e2->getMessage());
            }
        }

        $this->startSession();
    }

    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
            ini_set('session.cookie_lifetime', $timeout);
            ini_set('session.gc_maxlifetime', $timeout);
            session_set_cookie_params([
                'lifetime' => $timeout,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }

        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            $this->logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * ตรวจสอบการล็อกอิน - อัพเดตสำหรับฐานข้อมูลใหม่
     */
    public function login($identifier, $password, $requiredRole = null)
    {
        try {
            $user = null;

            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Login attempt - Identifier: {$identifier}, Required Role: {$requiredRole}");
            }

            // แยกการเข้าสู่ระบบตาม role
            if ($requiredRole === 'teacher' || $requiredRole === 'staff') {
                // สำหรับ teacher/staff - ใช้ Unit_id แทน Aj_department
                $sql = "SELECT t.Aj_id as id, t.Aj_name as name, t.Aj_password as password, 
                               t.Aj_position as position, t.Aj_per as permission, t.Aj_status,
                               t.Aj_tel, t.Aj_email, t.Unit_id,
                               ou.Unit_name, ou.Unit_type, ou.Unit_icon,
                               CASE ou.Unit_type
                                   WHEN 'faculty' THEN 'คณะ'
                                   WHEN 'major' THEN 'สาขา'
                                   WHEN 'department' THEN 'แผนก'
                               END as Unit_type_thai,
                               'teacher' as role 
                        FROM teacher t
                        LEFT JOIN organization_unit ou ON t.Unit_id = ou.Unit_id
                        WHERE (t.Aj_id = ? OR t.Aj_name = ?) AND t.Aj_status = 1";

                $user = $this->db->fetch($sql, [$identifier, $identifier]);

                if (defined('DEBUG_MODE') && DEBUG_MODE && $user) {
                    error_log("Found teacher: ID={$user['id']}, Name={$user['name']}, Permission={$user['permission']}");
                } elseif (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log("Teacher not found with identifier: {$identifier}");
                }

                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'ไม่พบรหัสเจ้าหน้าที่ที่ระบุ หรือบัญชีถูกระงับ'
                    ];
                }
            } elseif ($requiredRole === 'student') {
                // สำหรับ student - ใช้ organization_unit แทน faculty และ major
                $sql = "SELECT s.Stu_id as id, s.Stu_name as name, s.Stu_password as password,
                               s.Unit_id, s.Stu_status, s.Stu_tel, s.Stu_email,
                               s.Stu_suspend_reason, s.Stu_suspend_date,
                               major.Unit_name as major_name, major.Unit_icon as major_icon,
                               faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                               'student' as role
                        FROM student s
                        LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                        LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                        WHERE s.Stu_id = ?";
                $user = $this->db->fetch($sql, [$identifier]);

                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'ไม่พบรหัสนักศึกษาที่ระบุ'
                    ];
                }

                if ($user['Stu_status'] == 0) {
                    return [
                        'success' => false,
                        'message' => 'บัญชีของคุณถูกระงับ: ' . ($user['Stu_suspend_reason'] ?? 'ไม่ระบุเหตุผล')
                    ];
                }
            } else {
                // กรณีไม่ระบุ role - ลองหา teacher ก่อน แล้วค่อยหา student
                $sql = "SELECT t.Aj_id as id, t.Aj_name as name, t.Aj_password as password, 
                               t.Aj_position as position, t.Aj_per as permission, t.Aj_status,
                               t.Aj_tel, t.Aj_email, t.Unit_id,
                               ou.Unit_name, ou.Unit_type, ou.Unit_icon,
                               CASE ou.Unit_type
                                   WHEN 'faculty' THEN 'คณะ'
                                   WHEN 'major' THEN 'สาขา'
                                   WHEN 'department' THEN 'แผนก'
                               END as Unit_type_thai,
                               'teacher' as role 
                        FROM teacher t
                        LEFT JOIN organization_unit ou ON t.Unit_id = ou.Unit_id
                        WHERE (t.Aj_id = ? OR t.Aj_name = ?) AND t.Aj_status = 1";
                $user = $this->db->fetch($sql, [$identifier, $identifier]);

                if (!$user) {
                    // ลองหาใน student
                    $sql = "SELECT s.Stu_id as id, s.Stu_name as name, s.Stu_password as password,
                                   s.Unit_id, s.Stu_status, s.Stu_tel, s.Stu_email,
                                   major.Unit_name as major_name, major.Unit_icon as major_icon,
                                   faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                                   'student' as role
                            FROM student s
                            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                            WHERE s.Stu_id = ?";
                    $user = $this->db->fetch($sql, [$identifier]);

                    if ($user && $user['Stu_status'] == 0) {
                        return [
                            'success' => false,
                            'message' => 'บัญชีของคุณถูกระงับ'
                        ];
                    }
                }

                if (!$user) {
                    return [
                        'success' => false,
                        'message' => 'ไม่พบผู้ใช้ที่ระบุ'
                    ];
                }
            }

            // ตรวจสอบรหัสผ่านแบบ plaintext
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Password check - Input: [{$password}], Stored: [{$user['password']}]");
            }

            if (trim($password) !== trim($user['password'])) {
                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log("Password mismatch!");
                }
                return [
                    'success' => false,
                    'message' => 'รหัสผ่านไม่ถูกต้อง'
                ];
            }

            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Password match! Proceeding with login...");
            }

            // ตรวจสอบบทบาทเพิ่มเติม
            if ($requiredRole === 'teacher' && $user['role'] !== 'teacher') {
                return [
                    'success' => false,
                    'message' => 'คุณไม่มีสิทธิ์เข้าถึงระบบเจ้าหน้าที่'
                ];
            }

            if ($requiredRole === 'student' && $user['role'] !== 'student') {
                return [
                    'success' => false,
                    'message' => 'คุณไม่มีสิทธิ์เข้าถึงระบบนักศึกษา'
                ];
            }

            // ล้าง session เก่าก่อนสร้างใหม่
            session_regenerate_id(true);

            // สร้าง session ใหม่
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['logged_in'] = true;

            // ข้อมูลเฉพาะสำหรับ student
            if ($user['role'] === 'student') {
                $_SESSION['student_id'] = $user['id'];
                $_SESSION['unit_id'] = $user['Unit_id'];
                $_SESSION['faculty_name'] = $user['faculty_name'];
                $_SESSION['major_name'] = $user['major_name'];
                $_SESSION['faculty_icon'] = $user['faculty_icon'] ?? '🏫';
                $_SESSION['major_icon'] = $user['major_icon'] ?? '📚';
                $_SESSION['user_tel'] = $user['Stu_tel'];
                $_SESSION['user_email'] = $user['Stu_email'];
            }

            // ข้อมูลเฉพาะสำหรับ teacher
            if ($user['role'] === 'teacher') {
                $_SESSION['teacher_id'] = $user['id'];
                $_SESSION['position'] = $user['position'];
                $_SESSION['permission'] = $user['permission'];
                $_SESSION['unit_id'] = $user['Unit_id']; // เปลี่ยนจาก department เป็น unit_id
                $_SESSION['unit_name'] = $user['Unit_name'];
                $_SESSION['unit_type'] = $user['Unit_type'];
                $_SESSION['unit_icon'] = $user['Unit_icon'];
                $_SESSION['unit_type_thai'] = $user['Unit_type_thai'];
                $_SESSION['user_tel'] = $user['Aj_tel'];
                $_SESSION['user_email'] = $user['Aj_email'];

                // กำหนดสิทธิ์ตามระดับ permission
                // 1=อาจารย์, 2=ผู้ดำเนินการ, 3=ผู้ดูแลระบบ
                if ($user['permission'] == 3) {
                    $_SESSION['is_admin'] = true;     // ผู้ดูแลระบบ (จัดการข้อมูลพื้นฐาน)
                }
                if ($user['permission'] >= 2) {
                    $_SESSION['can_assign'] = true;   // ผู้ดำเนินการขึ้นไป (จัดการข้อร้องเรียน)
                }

                if (defined('DEBUG_MODE') && DEBUG_MODE) {
                    error_log("Teacher session created - Permission: {$user['permission']}");
                }
            }

            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['csrf_token'] = $this->generateCSRFToken();

            // บันทึก log การเข้าสู่ระบบ
            if (function_exists('logActivity')) {
                logActivity($user['id'], 'login', 'User logged in successfully', null);
            }

            // บังคับบันทึก session
            session_write_close();
            session_start();

            // กำหนด redirect path ตาม role และ permission
            $redirectPath = $this->getRedirectPath($user['role'], $user['permission'] ?? 1);

            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Login successful! Redirecting to: {$redirectPath}");
            }

            return [
                'success' => true,
                'user' => $user,
                'redirect' => $redirectPath
            ];
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง'
            ];
        }
    }

    /**
     * ลงทะเบียนนักศึกษาใหม่ - อัพเดตสำหรับฐานข้อมูลใหม่
     */
    public function registerStudent($data)
    {
        try {
            error_log("Auth registerStudent - Start registration process");

            // ตรวจสอบข้อมูลที่จำเป็น
            $requiredFields = ['student_id', 'full_name', 'password', 'major_id', 'faculty_id'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'
                    ];
                }
            }

            // ตรวจสอบรูปแบบรหัสนักศึกษา
            if (function_exists('validateStudentId') && !validateStudentId($data['student_id'])) {
                return [
                    'success' => false,
                    'message' => 'รหัสนักศึกษาต้องเป็นตัวเลข 13 หลัก'
                ];
            }

            // ตรวจสอบความยาวรหัสผ่าน
            $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6;
            if (strlen($data['password']) < $minLength) {
                return [
                    'success' => false,
                    'message' => 'รหัสผ่านต้องมีอย่างน้อย ' . $minLength . ' ตัวอักษร'
                ];
            }

            // ตรวจสอบอีเมลถ้ามี
            if (!empty($data['email']) && function_exists('validateEmail') && !validateEmail($data['email'])) {
                return [
                    'success' => false,
                    'message' => 'รูปแบบอีเมลไม่ถูกต้อง'
                ];
            }

            // ตรวจสอบข้อมูลซ้ำ
            if ($this->studentExists($data['student_id'])) {
                return [
                    'success' => false,
                    'message' => 'รหัสนักศึกษานี้ถูกใช้งานแล้ว'
                ];
            }

            // ตรวจสอบอีเมลซ้ำ (ถ้ามี)
            if (!empty($data['email'])) {
                $existingEmail = $this->db->fetch("SELECT COUNT(*) as count FROM student WHERE Stu_email = ?", [$data['email']]);
                if ($existingEmail && $existingEmail['count'] > 0) {
                    return [
                        'success' => false,
                        'message' => 'อีเมลนี้ถูกใช้งานแล้ว'
                    ];
                }
            }

            // ตรวจสอบเบอร์โทรซ้ำ (ถ้ามี)
            if (!empty($data['phone'])) {
                $existingPhone = $this->db->fetch("SELECT COUNT(*) as count FROM student WHERE Stu_tel = ?", [$data['phone']]);
                if ($existingPhone && $existingPhone['count'] > 0) {
                    return [
                        'success' => false,
                        'message' => 'เบอร์โทรศัพท์นี้ถูกใช้งานแล้ว'
                    ];
                }
            }

            // ตรวจสอบคณะและสาขา - ใช้ organization_unit
            $majorCheck = $this->db->fetch("
                SELECT COUNT(*) as count 
                FROM organization_unit 
                WHERE Unit_id = ? AND Unit_parent_id = ? AND Unit_type = 'major'
            ", [$data['major_id'], $data['faculty_id']]);

            if (!$majorCheck || $majorCheck['count'] == 0) {
                return [
                    'success' => false,
                    'message' => 'คณะและสาขาที่เลือกไม่ตรงกัน'
                ];
            }

            // ปรับปรุงการบันทึกข้อมูลด้วย Transaction
            $insertData = [
                'Stu_id' => $data['student_id'],
                'Stu_name' => trim($data['full_name']),
                'Stu_password' => $data['password'], // plaintext
                'Stu_tel' => !empty($data['phone']) ? $data['phone'] : null,
                'Stu_email' => !empty($data['email']) ? $data['email'] : null,
                'Unit_id' => (int)$data['major_id'], // ใช้ Unit_id ของสาขา
                'Stu_status' => 1
            ];

            error_log("Auth registerStudent - Attempting insert with data: " . print_r($insertData, true));

            // ใช้ Transaction เพื่อความปลอดภัย
            $this->db->beginTransaction();

            try {
                $result = $this->db->insert('student', $insertData);

                if ($result !== false) {
                    // รอสักครู่เพื่อให้ฐานข้อมูลประมวลผลเสร็จ
                    usleep(200000); // 0.2 วินาที

                    $verified = $this->db->verifyInsert('student', ['Stu_id' => $data['student_id']]);

                    if ($verified) {
                        $this->db->commit();

                        error_log("Auth registerStudent - Registration successful for: " . $data['student_id']);

                        // บันทึก log การลงทะเบียน
                        if (function_exists('logActivity')) {
                            logActivity($data['student_id'], 'register', 'Student registered successfully');
                        }

                        return [
                            'success' => true,
                            'message' => 'ลงทะเบียนสำเร็จ สามารถเข้าสู่ระบบด้วยรหัสนักศึกษาได้แล้ว'
                        ];
                    } else {
                        $this->db->rollback();
                        error_log("Auth registerStudent - Verification failed for student: " . $data['student_id']);
                        return [
                            'success' => false,
                            'message' => 'ไม่สามารถยืนยันการบันทึกข้อมูลได้ กรุณาลองใหม่อีกครั้ง'
                        ];
                    }
                } else {
                    $this->db->rollback();
                    error_log("Auth registerStudent - Insert returned false for: " . $data['student_id']);
                    return [
                        'success' => false,
                        'message' => 'เกิดข้อผิดพลาดในการลงทะเบียน กรุณาลองใหม่อีกครั้ง'
                    ];
                }
            } catch (Exception $e) {
                $this->db->rollback();
                error_log("Auth registerStudent - Transaction failed: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => 'เกิดข้อผิดพลาดในการลงทะเบียน กรุณาลองใหม่อีกครั้ง'
                ];
            }
        } catch (Exception $e) {
            error_log("Auth registerStudent error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง'
            ];
        }
    }

    /**
     * สร้างบัญชีเจ้าหน้าที่ใหม่ - อัพเดตสำหรับฐานข้อมูลใหม่
     */
    public function createTeacherAccount($data)
    {
        try {
            // ตรวจสอบสิทธิ์ admin
            if (!isset($_SESSION['permission']) || $_SESSION['permission'] != 3) {
                return [
                    'success' => false,
                    'message' => 'คุณไม่มีสิทธิ์สร้างบัญชีเจ้าหน้าที่'
                ];
            }

            // ตรวจสอบข้อมูลที่จำเป็น
            $requiredFields = ['name', 'password', 'position', 'permission'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'
                    ];
                }
            }

            // ตรวจสอบความยาวรหัสผ่าน
            $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6;
            if (strlen($data['password']) < $minLength) {
                return [
                    'success' => false,
                    'message' => 'รหัสผ่านต้องมีอย่างน้อย ' . $minLength . ' ตัวอักษร'
                ];
            }

            // ตรวจสอบระดับสิทธิ์
            if (!in_array($data['permission'], [1, 2, 3])) {
                return [
                    'success' => false,
                    'message' => 'ระดับสิทธิ์ไม่ถูกต้อง'
                ];
            }

            // ตรวจสอบอีเมลถ้ามี
            if (!empty($data['email']) && function_exists('validateEmail') && !validateEmail($data['email'])) {
                return [
                    'success' => false,
                    'message' => 'รูปแบบอีเมลไม่ถูกต้อง'
                ];
            }

            // ตรวจสอบชื่อซ้ำ
            if ($this->teacherNameExists($data['name'])) {
                return [
                    'success' => false,
                    'message' => 'ชื่อเจ้าหน้าที่นี้ถูกใช้งานแล้ว'
                ];
            }

            // ตรวจสอบหน่วยงาน (ถ้ามี)
            if (!empty($data['unit_id'])) {
                $unitCheck = $this->db->fetch("SELECT COUNT(*) as count FROM organization_unit WHERE Unit_id = ?", [$data['unit_id']]);
                if (!$unitCheck || $unitCheck['count'] == 0) {
                    return [
                        'success' => false,
                        'message' => 'หน่วยงานที่เลือกไม่มีอยู่ในระบบ'
                    ];
                }
            }

            // บันทึกข้อมูลแบบ plaintext password
            $insertData = [
                'Aj_name' => trim($data['name']),
                'Aj_password' => $data['password'], // plaintext
                'Aj_position' => trim($data['position']),
                'Aj_tel' => !empty($data['phone']) ? $data['phone'] : null,
                'Aj_email' => !empty($data['email']) ? $data['email'] : null,
                'Unit_id' => !empty($data['unit_id']) ? (int)$data['unit_id'] : null, // ใช้ Unit_id แทน Aj_department
                'Aj_per' => (int)$data['permission']
            ];

            $result = $this->db->insert('teacher', $insertData);

            if ($result) {
                // บันทึก log การสร้างบัญชี
                if (function_exists('logActivity')) {
                    logActivity(
                        $_SESSION['user_id'],
                        'create_teacher_account',
                        'Created teacher account: ' . $data['name'],
                        null
                    );
                }

                return [
                    'success' => true,
                    'message' => 'สร้างบัญชีเจ้าหน้าที่สำเร็จ',
                    'teacher_id' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'เกิดข้อผิดพลาดในการสร้างบัญชี'
                ];
            }
        } catch (Exception $e) {
            error_log("Create teacher account error: " . $e->getMessage());

            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง'
            ];
        }
    }

    /**
     * ออกจากระบบ
     */
    public function logout()
    {
        // บันทึก log การออกจากระบบ
        if (isset($_SESSION['user_id'])) {
            if (function_exists('logActivity')) {
                logActivity($_SESSION['user_id'], 'logout', 'User logged out');
            }
        }

        $_SESSION = array();

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
        session_start();

        return true;
    }

    /**
     * ตรวจสอบว่าเข้าสู่ระบบแล้วหรือไม่
     */
    public function isLoggedIn()
    {
        $timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 3600;

        return isset($_SESSION['user_id']) &&
            isset($_SESSION['user_role']) &&
            isset($_SESSION['logged_in']) &&
            $_SESSION['logged_in'] === true &&
            isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity']) < $timeout;
    }

    /**
     * ตรวจสอบสิทธิ์การเข้าถึง - อัพเดตสำหรับระบบใหม่
     */
    public function hasRole($requiredRole)
    {
        if (!$this->isLoggedIn()) {
            return false;
        }

        $userRole = $_SESSION['user_role'];

        // Teacher ที่มี permission = 3 เป็น admin
        if ($userRole === 'teacher' && isset($_SESSION['permission'])) {
            if ($_SESSION['permission'] == 3) {
                // Admin สามารถเข้าถึงทุกอย่างได้
                if (is_array($requiredRole)) {
                    return in_array('admin', $requiredRole) || in_array('teacher', $requiredRole);
                } else {
                    return $requiredRole === 'admin' || $requiredRole === 'teacher';
                }
            }
        }

        if (is_array($requiredRole)) {
            return in_array($userRole, $requiredRole);
        } else {
            return $userRole === $requiredRole;
        }
    }

    /**
     * ตรวจสอบสิทธิ์ระดับสูง
     */
    public function hasPermission($requiredPermission)
    {
        if (!$this->isLoggedIn() || $_SESSION['user_role'] !== 'teacher') {
            return false;
        }

        return isset($_SESSION['permission']) && $_SESSION['permission'] >= $requiredPermission;
    }

    /**
     * บังคับให้ล็อกอิน
     */
    public function requireLogin($redirectTo = null)
    {
        if (!$this->isLoggedIn()) {
            if ($redirectTo === null) {
                $currentPath = $_SERVER['REQUEST_URI'] ?? '';
                if (strpos($currentPath, '/staff/') !== false || strpos($currentPath, '/admin/') !== false) {
                    $redirectTo = '../index.php?message=session_expired';
                } elseif (strpos($currentPath, '/students/') !== false) {
                    $redirectTo = '../index.php?message=session_expired';
                } else {
                    $redirectTo = 'index.php?message=session_expired';
                }
            }

            header("Location: {$redirectTo}");
            exit;
        }
    }

    /**
     * บังคับให้มีสิทธิ์เฉพาะ
     */
    public function requireRole($requiredRole, $redirectTo = null)
    {
        $this->requireLogin($redirectTo);

        if (!$this->hasRole($requiredRole)) {
            if ($redirectTo === null) {
                $redirectTo = '../index.php?message=permission_denied';
            }
            header("Location: {$redirectTo}");
            exit;
        }
    }

    /**
     * บังคับให้มีสิทธิ์ระดับสูง
     */
    public function requirePermission($requiredPermission, $redirectTo = null)
    {
        $this->requireLogin($redirectTo);

        if (!$this->hasPermission($requiredPermission)) {
            if ($redirectTo === null) {
                $redirectTo = '../index.php?message=insufficient_permission';
            }
            header("Location: {$redirectTo}");
            exit;
        }
    }

    /**
     * ได้ข้อมูลผู้ใช้ปัจจุบัน - อัพเดตสำหรับฐานข้อมูลใหม่
     */
    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        try {
            if ($_SESSION['user_role'] === 'student') {
                // อัพเดตให้ใช้ organization_unit
                $sql = "SELECT s.*, 
                               major.Unit_name as major_name, major.Unit_icon as major_icon,
                               major.Unit_type as major_type,
                               faculty.Unit_name as faculty_name, faculty.Unit_icon as faculty_icon,
                               faculty.Unit_type as faculty_type,
                               CASE major.Unit_type
                                   WHEN 'faculty' THEN 'คณะ'
                                   WHEN 'major' THEN 'สาขา'
                                   WHEN 'department' THEN 'แผนก'
                               END as major_type_thai,
                               CASE faculty.Unit_type
                                   WHEN 'faculty' THEN 'คณะ'
                                   WHEN 'major' THEN 'สาขา'
                                   WHEN 'department' THEN 'แผนก'
                               END as faculty_type_thai
                        FROM student s
                        LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
                        LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
                        WHERE s.Stu_id = ?";
                return $this->db->fetch($sql, [$_SESSION['user_id']]);
            } else {
                // อัพเดตให้ใช้ organization_unit สำหรับ teacher
                $sql = "SELECT t.*, 
                               ou.Unit_name, ou.Unit_type, ou.Unit_icon,
                               CASE ou.Unit_type
                                   WHEN 'faculty' THEN 'คณะ'
                                   WHEN 'major' THEN 'สาขา'
                                   WHEN 'department' THEN 'แผนก'
                               END as Unit_type_thai
                        FROM teacher t
                        LEFT JOIN organization_unit ou ON t.Unit_id = ou.Unit_id
                        WHERE t.Aj_id = ?";
                return $this->db->fetch($sql, [$_SESSION['user_id']]);
            }
        } catch (Exception $e) {
            error_log("getCurrentUser error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * เปลี่ยนรหัสผ่าน - อัพเดตสำหรับฐานข้อมูลใหม่
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        try {
            $table = ($_SESSION['user_role'] === 'student') ? 'student' : 'teacher';
            $idField = ($_SESSION['user_role'] === 'student') ? 'Stu_id' : 'Aj_id';
            $passwordField = ($_SESSION['user_role'] === 'student') ? 'Stu_password' : 'Aj_password';

            $user = $this->db->fetch("SELECT {$passwordField} as password FROM {$table} WHERE {$idField} = ?", [$userId]);

            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'ไม่พบผู้ใช้'
                ];
            }

            // ตรวจสอบรหัสผ่านปัจจุบันแบบ plaintext
            if ($currentPassword !== $user['password']) {
                return [
                    'success' => false,
                    'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'
                ];
            }

            // ตรวจสอบความยาวรหัสผ่านใหม่
            $minLength = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6;
            if (strlen($newPassword) < $minLength) {
                return [
                    'success' => false,
                    'message' => 'รหัสผ่านใหม่ต้องมีอย่างน้อย ' . $minLength . ' ตัวอักษร'
                ];
            }

            // บันทึกรหัสผ่านใหม่แบบ plaintext
            $result = $this->db->update($table, [$passwordField => $newPassword], "{$idField} = ?", [$userId]);

            if ($result) {
                // บันทึก log การเปลี่ยนรหัสผ่าน
                if (function_exists('logActivity')) {
                    logActivity($userId, 'change_password', 'Password changed successfully');
                }

                return [
                    'success' => true,
                    'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน'
                ];
            }
        } catch (Exception $e) {
            error_log("Change password error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการเปลี่ยนรหัสผ่าน'
            ];
        }
    }

    /**
     * สร้าง CSRF Token
     */
    public function generateCSRFToken()
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    /**
     * ตรวจสอบ CSRF Token
     */
    public function validateCSRFToken($token)
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * ตรวจสอบว่านักศึกษามีอยู่แล้วหรือไม่
     */
    private function studentExists($studentId)
    {
        try {
            $result = $this->db->fetch("SELECT COUNT(*) as count FROM `student` WHERE `Stu_id` = ?", [$studentId]);
            $exists = $result && $result['count'] > 0;
            error_log("Auth Student exists check for $studentId: " . ($exists ? 'YES' : 'NO'));
            return $exists;
        } catch (Exception $e) {
            error_log("Auth Student existence check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ตรวจสอบว่าชื่อเจ้าหน้าที่มีอยู่แล้วหรือไม่
     */
    private function teacherNameExists($teacherName)
    {
        try {
            $result = $this->db->fetch("SELECT COUNT(*) as count FROM `teacher` WHERE `Aj_name` = ?", [$teacherName]);
            return $result && $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Teacher name existence check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ตรวจสอบว่า table มีอยู่หรือไม่
     */
    private function tableExists($tableName)
    {
        try {
            $result = $this->db->fetch("
                SELECT COUNT(*) as count 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ?
            ", [$tableName]);

            return $result && $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Table existence check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * กำหนดเส้นทางหลังล็อกอิน
     */
    private function getRedirectPath($role, $permission = 1)
    {
        $currentDir = dirname($_SERVER['SCRIPT_NAME']);

        switch ($role) {
            case 'student':
                // ถ้าอยู่ในโฟลเดอร์ย่อย ให้ redirect ถูกต้อง
                if (strpos($currentDir, '/staff') !== false) {
                    return '../students/';
                }
                return 'students/';

            case 'teacher':
                // ตรวจสอบ permission level
                if ($permission >= 2) {
                    // Supervisor หรือ Admin
                    if (strpos($currentDir, '/staff') !== false) {
                        return 'index.php';
                    }
                    return 'staff/';
                } else {
                    // Staff ทั่วไป
                    if (strpos($currentDir, '/staff') !== false) {
                        return 'index.php';
                    }
                    return 'staff/';
                }

            default:
                return 'index.php';
        }
    }

    /**
     * ทดสอบการเชื่อมต่อฐานข้อมูล
     */
    public function testDatabaseConnection()
    {
        try {
            $result = $this->db->fetch("SELECT 1 as test");
            if ($result) {
                return [
                    'success' => true,
                    'message' => 'เชื่อมต่อฐานข้อมูลสำเร็จ'
                ];
            }

            return [
                'success' => false,
                'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'ข้อผิดพลาด: ' . $e->getMessage()
            ];
        }
    }
}

// สร้าง instance global with error handling
$auth = null;
try {
    $auth = new Auth();
} catch (Exception $e) {
    error_log("Auth class initialization failed: " . $e->getMessage());
    // Don't set $auth to anything - leave it null
}

// ฟังก์ชันช่วยเหลือ - with null checks
function getAuth()
{
    global $auth;
    return $auth;
}

function isLoggedIn()
{
    $auth = getAuth();
    return $auth ? $auth->isLoggedIn() : false;
}

function hasRole($role)
{
    $auth = getAuth();
    return $auth ? $auth->hasRole($role) : false;
}

function hasPermission($permission)
{
    $auth = getAuth();
    return $auth ? $auth->hasPermission($permission) : false;
}

function requireLogin($redirect = '../index.php')
{
    $auth = getAuth();
    if ($auth) {
        $auth->requireLogin($redirect);
    } else {
        header("Location: $redirect");
        exit;
    }
}

function requireStaffAccess()
{
    // ถ้าไม่ได้ login หรือเป็น student ให้ redirect ออกทันที
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        header('Location: ../index.php?message=session_expired');
        exit;
    }

    if ($_SESSION['user_role'] === 'student') {
        header('Location: ../students/index.php?message=permission_denied');
        exit;
    }
}

function requireRole($role, $redirect = '../index.php')
{
    $auth = getAuth();
    if ($auth) {
        $auth->requireRole($role, $redirect);
    } else {
        // Fallback redirect if auth is not available
        header("Location: $redirect");
        exit;
    }
}

function requirePermission($permission, $redirect = '../index.php')
{
    $auth = getAuth();
    if ($auth) {
        $auth->requirePermission($permission, $redirect);
    } else {
        // Fallback redirect if auth is not available
        header("Location: $redirect");
        exit;
    }
}

function getCurrentUser()
{
    $auth = getAuth();
    return $auth ? $auth->getCurrentUser() : null;
}

function logout()
{
    $auth = getAuth();
    return $auth ? $auth->logout() : false;
}

function validateCSRFToken($token)
{
    $auth = getAuth();
    return $auth ? $auth->validateCSRFToken($token) : false;
}
