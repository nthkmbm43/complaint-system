<?php
// includes/advanced_search.php - ระบบค้นหาขั้นสูง
define('SECURE_ACCESS', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * ระบบค้นหาขั้นสูงสำหรับข้อร้องเรียน
 */
class AdvancedSearch
{
    private $db;
    private $userRole;
    private $userId;

    public function __construct($userRole = 'student', $userId = null)
    {
        $this->db = getDB();
        $this->userRole = $userRole;
        $this->userId = $userId;
    }

    /**
     * ค้นหาแบบขั้นสูง
     */
    public function search($params = [])
    {
        $defaultParams = [
            'query' => '',
            'category' => '',
            'status' => '',
            'priority' => '',
            'date_from' => '',
            'date_to' => '',
            'assigned_to' => '',
            'identity' => '',
            'rating_min' => '',
            'rating_max' => '',
            'response_time' => '',
            'sort_by' => 'Re_date',
            'sort_order' => 'DESC',
            'page' => 1,
            'per_page' => 20,
            'include_spam' => false
        ];

        $params = array_merge($defaultParams, $params);

        // สร้าง Query หลัก
        $baseQuery = $this->buildBaseQuery();
        $whereConditions = $this->buildWhereConditions($params);
        $orderClause = $this->buildOrderClause($params['sort_by'], $params['sort_order']);
        $limitClause = $this->buildLimitClause($params['page'], $params['per_page']);

        // Query สำหรับนับจำนวนทั้งหมด
        $countQuery = "SELECT COUNT(DISTINCT r.Re_id) as total " . $baseQuery . $whereConditions['sql'];
        $totalResults = $this->db->fetch($countQuery, $whereConditions['params']);

        // Query สำหรับดึงข้อมูล
        $dataQuery = "SELECT " . $this->getSelectFields() . " " .
            $baseQuery . $whereConditions['sql'] .
            " GROUP BY r.Re_id " . $orderClause . $limitClause;

        $results = $this->db->fetchAll($dataQuery, $whereConditions['params']);

        // เพิ่มข้อมูลเสริม
        $results = $this->enhanceResults($results);

        return [
            'results' => $results,
            'total' => $totalResults['total'] ?? 0,
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'total_pages' => ceil(($totalResults['total'] ?? 0) / $params['per_page']),
            'query_info' => $this->getQueryInfo($params),
            'facets' => $this->getFacets($params)
        ];
    }

    /**
     * สร้าง Base Query
     */
    private function buildBaseQuery()
    {
        return "
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN organization_unit major ON s.Unit_id = major.Unit_id
            LEFT JOIN organization_unit faculty ON major.Unit_parent_id = faculty.Unit_id
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
            LEFT JOIN result_request rr ON r.Re_id = rr.Re_id
            LEFT JOIN supporting_evidence se ON r.Re_id = se.Re_id
        ";
    }

    /**
     * สร้างเงื่อนไข WHERE
     */
    private function buildWhereConditions($params)
    {
        $conditions = [];
        $params_array = [];

        // เงื่อนไขพื้นฐาน
        if (!$params['include_spam']) {
            $conditions[] = "r.Re_is_spam = 0";
        }

        // สิทธิ์การเข้าถึง
        if ($this->userRole === 'student' && $this->userId) {
            $conditions[] = "(r.Stu_id = ? OR r.Re_iden = 0)";
            $params_array[] = $this->userId;
        }

        // ค้นหาข้อความ (Full-text search)
        if (!empty($params['query'])) {
            $searchTerms = $this->parseSearchQuery($params['query']);
            $searchConditions = [];

            foreach ($searchTerms as $term) {
                if (strlen($term) >= 2) {
                    $searchConditions[] = "(
                        r.Re_infor LIKE ? OR 
                        r.Re_title LIKE ? OR 
                        s.Stu_name LIKE ? OR 
                        sr.Sv_detail LIKE ? OR
                        rr.Result_detail LIKE ?
                    )";
                    $likeTerm = '%' . $term . '%';
                    $params_array = array_merge($params_array, [$likeTerm, $likeTerm, $likeTerm, $likeTerm, $likeTerm]);
                }
            }

            if (!empty($searchConditions)) {
                $conditions[] = "(" . implode(" AND ", $searchConditions) . ")";
            }
        }

        // ประเภทข้อร้องเรียน
        if (!empty($params['category'])) {
            if (is_array($params['category'])) {
                $placeholders = str_repeat('?,', count($params['category']) - 1) . '?';
                $conditions[] = "r.Type_id IN ($placeholders)";
                $params_array = array_merge($params_array, $params['category']);
            } else {
                $conditions[] = "r.Type_id = ?";
                $params_array[] = $params['category'];
            }
        }

        // สถานะ
        if (!empty($params['status'])) {
            if (is_array($params['status'])) {
                $placeholders = str_repeat('?,', count($params['status']) - 1) . '?';
                $conditions[] = "r.Re_status IN ($placeholders)";
                $params_array = array_merge($params_array, $params['status']);
            } else {
                $conditions[] = "r.Re_status = ?";
                $params_array[] = $params['status'];
            }
        }

        // ระดับความสำคัญ
        if (!empty($params['priority'])) {
            if (is_array($params['priority'])) {
                $placeholders = str_repeat('?,', count($params['priority']) - 1) . '?';
                $conditions[] = "r.Re_level IN ($placeholders)";
                $params_array = array_merge($params_array, $params['priority']);
            } else {
                $conditions[] = "r.Re_level = ?";
                $params_array[] = $params['priority'];
            }
        }

        // ช่วงวันที่
        if (!empty($params['date_from'])) {
            $conditions[] = "r.Re_date >= ?";
            $params_array[] = $params['date_from'] . ' 00:00:00';
        }

        if (!empty($params['date_to'])) {
            $conditions[] = "r.Re_date <= ?";
            $params_array[] = $params['date_to'] . ' 23:59:59';
        }

        // เจ้าหน้าที่ที่รับผิดชอบ
        if (!empty($params['assigned_to'])) {
            $conditions[] = "r.Aj_id = ?";
            $params_array[] = $params['assigned_to'];
        }

        // การระบุตัวตน
        if ($params['identity'] !== '') {
            $conditions[] = "r.Re_iden = ?";
            $params_array[] = $params['identity'];
        }

        // คะแนนประเมิน
        if (!empty($params['rating_min'])) {
            $conditions[] = "e.Eva_score >= ?";
            $params_array[] = $params['rating_min'];
        }

        if (!empty($params['rating_max'])) {
            $conditions[] = "e.Eva_score <= ?";
            $params_array[] = $params['rating_max'];
        }

        // เวลาตอบสนอง
        if (!empty($params['response_time'])) {
            switch ($params['response_time']) {
                case 'fast': // น้อยกว่า 24 ชั่วโมง
                    $conditions[] = "TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date) <= 24";
                    break;
                case 'normal': // 24-72 ชั่วโมง
                    $conditions[] = "TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date) BETWEEN 24 AND 72";
                    break;
                case 'slow': // มากกว่า 72 ชั่วโมง
                    $conditions[] = "TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date) > 72";
                    break;
                case 'no_response': // ยังไม่มีการตอบ
                    $conditions[] = "sr.Sv_id IS NULL";
                    break;
            }
        }

        // มีไฟล์แนบหรือไม่
        if (isset($params['has_files']) && $params['has_files'] !== '') {
            if ($params['has_files'] == '1') {
                $conditions[] = "se.Sup_id IS NOT NULL";
            } else {
                $conditions[] = "se.Sup_id IS NULL";
            }
        }

        $whereClause = !empty($conditions) ? " WHERE " . implode(" AND ", $conditions) : "";

        return [
            'sql' => $whereClause,
            'params' => $params_array
        ];
    }

    /**
     * แยกคำค้นหา
     */
    private function parseSearchQuery($query)
    {
        // ลบอักขระพิเศษ
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);

        // แยกคำ
        $terms = preg_split('/\s+/', trim($query));

        // ลบคำที่สั้นเกินไป
        $terms = array_filter($terms, function ($term) {
            return mb_strlen($term) >= 2;
        });

        return array_unique($terms);
    }

    /**
     * สร้าง ORDER BY clause
     */
    private function buildOrderClause($sortBy, $sortOrder)
    {
        $allowedSorts = [
            'Re_date' => 'r.Re_date',
            'Re_id' => 'r.Re_id',
            'Re_level' => 'r.Re_level',
            'Re_status' => 'r.Re_status',
            'Type_infor' => 't.Type_infor',
            'Stu_name' => 's.Stu_name',
            'Eva_score' => 'e.Eva_score',
            'response_time' => 'TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date)'
        ];

        if (!isset($allowedSorts[$sortBy])) {
            $sortBy = 'Re_date';
        }

        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        return " ORDER BY " . $allowedSorts[$sortBy] . " " . $sortOrder;
    }

    /**
     * สร้าง LIMIT clause
     */
    private function buildLimitClause($page, $perPage)
    {
        $page = max(1, intval($page));
        $perPage = min(100, max(1, intval($perPage))); // จำกัดไม่เกิน 100 รายการต่อหน้า
        $offset = ($page - 1) * $perPage;

        return " LIMIT $perPage OFFSET $offset";
    }

    /**
     * กำหนดฟิลด์ที่ต้องการ SELECT
     */
    private function getSelectFields()
    {
        return "
            r.*,
            t.Type_infor,
            t.Type_icon,
            CASE 
                WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
                ELSE s.Stu_name
            END as requester_name,
            s.Stu_id,
            s.Stu_email,
            major.Unit_name as major_name,
            faculty.Unit_name as faculty_name,
            aj.Aj_name as assigned_staff_name,
            aj.Aj_position as assigned_staff_position,
            e.Eva_score,
            e.Eva_sug,
            e.created_at as evaluation_date,
            MIN(sr.Sv_date) as first_response_date,
            MAX(sr.Sv_date) as last_response_date,
            COUNT(DISTINCT sr.Sv_id) as response_count,
            COUNT(DISTINCT se.Sup_id) as file_count,
            rr.Result_date,
            rr.Result_detail,
            TIMESTAMPDIFF(HOUR, r.Re_date, MIN(sr.Sv_date)) as response_hours
        ";
    }

    /**
     * เพิ่มข้อมูลเสริมให้กับผลลัพธ์
     */
    private function enhanceResults($results)
    {
        foreach ($results as &$result) {
            // คำนวณเวลาที่ผ่านมา
            $result['time_ago'] = $this->getTimeAgo($result['Re_date']);

            // สถานะในรูปแบบข้อความ
            $result['status_text'] = $this->getStatusText($result['Re_status']);
            $result['status_color'] = $this->getStatusColor($result['Re_status']);

            // ระดับความสำคัญในรูปแบบข้อความ
            $result['priority_text'] = $this->getPriorityText($result['Re_level']);
            $result['priority_color'] = $this->getPriorityColor($result['Re_level']);

            // เวลาตอบสนอง
            if ($result['response_hours']) {
                $result['response_time_text'] = $this->formatResponseTime($result['response_hours']);
                $result['response_speed'] = $this->getResponseSpeed($result['response_hours']);
            }

            // คะแนนประเมินในรูปแบบดาว
            if ($result['Eva_score']) {
                $result['rating_stars'] = str_repeat('⭐', $result['Eva_score']);
            }

            // ตัดข้อความยาวๆ
            $result['Re_infor_excerpt'] = mb_substr($result['Re_infor'], 0, 150) .
                (mb_strlen($result['Re_infor']) > 150 ? '...' : '');
        }

        return $results;
    }

    /**
     * ดึงข้อมูล Facets สำหรับการกรอง
     */
    private function getFacets($currentParams)
    {
        $facets = [];

        // Facets ตามประเภท
        $facets['categories'] = $this->db->fetchAll("
            SELECT t.Type_id, t.Type_infor, t.Type_icon, COUNT(r.Re_id) as count
            FROM type t
            LEFT JOIN request r ON t.Type_id = r.Type_id AND r.Re_is_spam = 0
            GROUP BY t.Type_id
            ORDER BY count DESC
        ");

        // Facets ตามสถานะ
        $facets['statuses'] = $this->db->fetchAll("
            SELECT Re_status, COUNT(*) as count
            FROM request 
            WHERE Re_is_spam = 0
            GROUP BY Re_status
            ORDER BY Re_status
        ");

        // Facets ตามระดับความสำคัญ
        $facets['priorities'] = $this->db->fetchAll("
            SELECT Re_level, COUNT(*) as count
            FROM request 
            WHERE Re_is_spam = 0
            GROUP BY Re_level
            ORDER BY Re_level
        ");

        // Facets ตามเจ้าหน้าที่ (เฉพาะ staff)
        if ($this->userRole === 'teacher') {
            $facets['staff'] = $this->db->fetchAll("
                SELECT aj.Aj_id, aj.Aj_name, aj.Aj_position, COUNT(r.Re_id) as count
                FROM teacher aj
                LEFT JOIN request r ON aj.Aj_id = r.Aj_id AND r.Re_is_spam = 0
                WHERE aj.Aj_status = 1
                GROUP BY aj.Aj_id
                HAVING count > 0
                ORDER BY count DESC
            ");
        }

        return $facets;
    }

    /**
     * ข้อมูลเกี่ยวกับ Query
     */
    private function getQueryInfo($params)
    {
        $info = [];

        if (!empty($params['query'])) {
            $info[] = "ค้นหา: \"" . htmlspecialchars($params['query']) . "\"";
        }

        if (!empty($params['category'])) {
            $categoryName = $this->db->fetch("SELECT Type_infor FROM type WHERE Type_id = ?", [$params['category']]);
            if ($categoryName) {
                $info[] = "ประเภท: " . $categoryName['Type_infor'];
            }
        }

        if (!empty($params['status'])) {
            $info[] = "สถานะ: " . $this->getStatusText($params['status']);
        }

        if (!empty($params['date_from']) || !empty($params['date_to'])) {
            $dateRange = "ช่วงวันที่: ";
            if (!empty($params['date_from'])) {
                $dateRange .= date('j M Y', strtotime($params['date_from']));
            }
            if (!empty($params['date_to'])) {
                $dateRange .= " - " . date('j M Y', strtotime($params['date_to']));
            }
            $info[] = $dateRange;
        }

        return $info;
    }

    /**
     * ฟังก์ชันช่วยเหลือ
     */
    private function getTimeAgo($datetime)
    {
        $time = time() - strtotime($datetime);

        if ($time < 60) return 'เมื่อสักครู่';
        if ($time < 3600) return floor($time / 60) . ' นาทีที่แล้ว';
        if ($time < 86400) return floor($time / 3600) . ' ชั่วโมงที่แล้ว';
        if ($time < 2592000) return floor($time / 86400) . ' วันที่แล้ว';
        if ($time < 31536000) return floor($time / 2592000) . ' เดือนที่แล้ว';
        return floor($time / 31536000) . ' ปีที่แล้ว';
    }

    private function getStatusText($status)
    {
        $statuses = [
            '0' => 'ยื่นคำร้อง',
            '1' => 'กำลังดำเนินการ',
            '2' => 'รอการประเมินผล',
            '3' => 'เสร็จสิ้น',
            '4' => 'ปฏิเสธคำร้อง'
        ];
        return $statuses[$status] ?? 'ไม่ระบุ';
    }

    private function getStatusColor($status)
    {
        $colors = [
            '0' => '#6c757d', // secondary - สีเทา (ยื่นคำร้อง)
            '1' => '#17a2b8', // info - สีฟ้า (กำลังดำเนินการ)
            '2' => '#ffc107', // warning - สีเหลือง (รอการประเมินผล)
            '3' => '#28a745', // success - สีเขียว (เสร็จสิ้น)
            '4' => '#dc3545'  // danger - สีแดง (ปฏิเสธคำร้อง)
        ];
        return $colors[$status] ?? '#6c757d';
    }

    private function getPriorityText($level)
    {
        $priorities = [
            '1' => 'ปกติ',
            '2' => 'สำคัญ',
            '3' => 'เร่งด่วน',
            '4' => 'เร่งด่วนมาก',
            '5' => 'วิกฤต/ฉุกเฉิน'
        ];
        return $priorities[$level] ?? 'ไม่ระบุ';
    }

    private function getPriorityColor($level)
    {
        $colors = [
            '1' => '#48bb78', // green
            '2' => '#3182ce', // blue
            '3' => '#ed8936', // orange
            '4' => '#e53e3e', // red
            '5' => '#9f7aea'  // purple
        ];
        return $colors[$level] ?? '#a0aec0';
    }

    private function formatResponseTime($hours)
    {
        if ($hours < 1) {
            return 'น้อยกว่า 1 ชั่วโมง';
        } elseif ($hours < 24) {
            return floor($hours) . ' ชั่วโมง';
        } else {
            $days = floor($hours / 24);
            $remainingHours = $hours % 24;
            return $days . ' วัน' . ($remainingHours > 0 ? ' ' . floor($remainingHours) . ' ชั่วโมง' : '');
        }
    }

    private function getResponseSpeed($hours)
    {
        if ($hours <= 24) return 'fast';
        if ($hours <= 72) return 'normal';
        return 'slow';
    }

    /**
     * ค้นหาแบบเร็ว (Quick Search)
     */
    public function quickSearch($query, $limit = 10)
    {
        if (empty($query) || strlen($query) < 2) {
            return [];
        }

        $searchParams = [
            'query' => $query,
            'per_page' => $limit,
            'sort_by' => 'Re_date',
            'sort_order' => 'DESC'
        ];

        $result = $this->search($searchParams);
        return $result['results'];
    }

    /**
     * ค้นหาแบบคล้าย (Similar)
     */
    public function findSimilar($requestId, $limit = 5)
    {
        $originalRequest = $this->db->fetch("
            SELECT Re_infor, Type_id, Re_level 
            FROM request 
            WHERE Re_id = ?
        ", [$requestId]);

        if (!$originalRequest) {
            return [];
        }

        // แยกคำสำคัญจากข้อความ
        $keywords = $this->extractKeywords($originalRequest['Re_infor']);

        if (empty($keywords)) {
            return [];
        }

        // สร้างเงื่อนไขค้นหา
        $keywordConditions = [];
        $params = [];

        foreach ($keywords as $keyword) {
            $keywordConditions[] = "r.Re_infor LIKE ?";
            $params[] = '%' . $keyword . '%';
        }

        $params[] = $originalRequest['Type_id'];
        $params[] = $requestId;
        $params[] = $limit;

        return $this->db->fetchAll("
            SELECT r.*, t.Type_infor, t.Type_icon,
                   CASE WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน' ELSE s.Stu_name END as requester_name
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            WHERE r.Re_is_spam = 0
              AND (" . implode(" OR ", $keywordConditions) . ")
              AND r.Type_id = ?
              AND r.Re_id != ?
            ORDER BY r.Re_date DESC
            LIMIT ?
        ", $params);
    }

    /**
     * แยกคำสำคัญ
     */
    private function extractKeywords($text)
    {
        // ลบ HTML tags และอักขระพิเศษ
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        // แยกคำ
        $words = preg_split('/\s+/', trim($text));

        // กรองคำที่สำคัญ (ยาวกว่า 3 ตัวอักษร)
        $keywords = array_filter($words, function ($word) {
            return mb_strlen($word) >= 3;
        });

        // เอาคำที่ซ้ำออก และเรียงตามความยาว
        $keywords = array_unique($keywords);
        usort($keywords, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });

        // เอาแค่ 5 คำแรก
        return array_slice($keywords, 0, 5);
    }

    /**
     * สร้าง Search Suggestions
     */
    public function getSuggestions($query, $limit = 5)
    {
        if (empty($query) || strlen($query) < 2) {
            return [];
        }

        // ค้นหาจากข้อความที่เคยมี
        $suggestions = $this->db->fetchAll("
            SELECT DISTINCT 
                SUBSTRING(Re_infor, 1, 100) as suggestion,
                COUNT(*) as frequency
            FROM request 
            WHERE Re_infor LIKE ? 
              AND Re_is_spam = 0
              AND CHAR_LENGTH(Re_infor) >= 10
            GROUP BY SUBSTRING(Re_infor, 1, 100)
            ORDER BY frequency DESC, CHAR_LENGTH(suggestion) ASC
            LIMIT ?
        ", ['%' . $query . '%', $limit]);

        return array_column($suggestions, 'suggestion');
    }

    /**
     * สถิติการค้นหา
     */
    public function getSearchStats()
    {
        return [
            'total_requests' => $this->db->count('request', 'Re_is_spam = 0'),
            'categories_count' => $this->db->count('type'),
            'staff_count' => $this->db->count('teacher', 'Aj_status = 1'),
            'avg_rating' => $this->db->fetch("SELECT AVG(Eva_score) as avg FROM evaluation")['avg'] ?? 0
        ];
    }
}

/**
 * AJAX Handler สำหรับ Advanced Search
 */
if (isset($_REQUEST['ajax']) && $_REQUEST['ajax'] == '1') {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    $action = $_REQUEST['action'] ?? '';
    $userRole = $_SESSION['user_role'] ?? 'student';
    $userId = $_SESSION['user_id'] ?? null;

    $search = new AdvancedSearch($userRole, $userId);

    try {
        switch ($action) {
            case 'search':
                $params = [
                    'query' => $_REQUEST['q'] ?? '',
                    'category' => $_REQUEST['category'] ?? '',
                    'status' => $_REQUEST['status'] ?? '',
                    'priority' => $_REQUEST['priority'] ?? '',
                    'date_from' => $_REQUEST['date_from'] ?? '',
                    'date_to' => $_REQUEST['date_to'] ?? '',
                    'assigned_to' => $_REQUEST['assigned_to'] ?? '',
                    'identity' => $_REQUEST['identity'] ?? '',
                    'rating_min' => $_REQUEST['rating_min'] ?? '',
                    'rating_max' => $_REQUEST['rating_max'] ?? '',
                    'response_time' => $_REQUEST['response_time'] ?? '',
                    'has_files' => $_REQUEST['has_files'] ?? '',
                    'sort_by' => $_REQUEST['sort_by'] ?? 'Re_date',
                    'sort_order' => $_REQUEST['sort_order'] ?? 'DESC',
                    'page' => intval($_REQUEST['page'] ?? 1),
                    'per_page' => intval($_REQUEST['per_page'] ?? 20)
                ];

                $result = $search->search($params);
                echo json_encode($result);
                break;

            case 'quick_search':
                $query = $_REQUEST['q'] ?? '';
                $limit = intval($_REQUEST['limit'] ?? 10);
                $results = $search->quickSearch($query, $limit);
                echo json_encode(['results' => $results]);
                break;

            case 'suggestions':
                $query = $_REQUEST['q'] ?? '';
                $suggestions = $search->getSuggestions($query);
                echo json_encode(['suggestions' => $suggestions]);
                break;

            case 'similar':
                $requestId = intval($_REQUEST['request_id'] ?? 0);
                $similar = $search->findSimilar($requestId);
                echo json_encode(['similar' => $similar]);
                break;

            case 'stats':
                $stats = $search->getSearchStats();
                echo json_encode(['stats' => $stats]);
                break;

            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
}
