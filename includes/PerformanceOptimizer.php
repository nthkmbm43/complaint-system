<?php
// includes/PerformanceOptimizer.php - ระบบเพิ่มประสิทธิภาพ
define('SECURE_ACCESS', true);

class PerformanceOptimizer
{
    private $db;
    private $cacheDir;
    private $logDir;

    public function __construct()
    {
        $this->db = getDB();
        $this->cacheDir = '../cache/';
        $this->logDir = '../logs/performance/';

        // สร้างโฟลเดอร์ cache และ logs
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * ระบบ Cache แบบง่าย
     */
    public function getCache($key, $expiry = 3600)
    {
        $cacheFile = $this->cacheDir . md5($key) . '.cache';

        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);

            if ($cacheData && (time() - $cacheData['timestamp']) < $expiry) {
                return $cacheData['data'];
            } else {
                // Cache หมดอายุ - ลบไฟล์
                unlink($cacheFile);
            }
        }

        return null;
    }

    public function setCache($key, $data, $metadata = [])
    {
        $cacheFile = $this->cacheDir . md5($key) . '.cache';

        $cacheData = [
            'key' => $key,
            'data' => $data,
            'timestamp' => time(),
            'metadata' => $metadata
        ];

        return file_put_contents($cacheFile, json_encode($cacheData)) !== false;
    }

    public function deleteCache($key)
    {
        $cacheFile = $this->cacheDir . md5($key) . '.cache';
        return file_exists($cacheFile) ? unlink($cacheFile) : true;
    }

    public function clearAllCache()
    {
        $files = glob($this->cacheDir . '*.cache');
        $deleted = 0;

        foreach ($files as $file) {
            if (unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Cache สำหรับข้อมูลสถิติ
     */
    public function getCachedStatistics($dateFrom = null, $dateTo = null)
    {
        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-t');
        $cacheKey = "statistics_{$dateFrom}_{$dateTo}";

        // ตรวจสอบ cache ก่อน
        $cached = $this->getCache($cacheKey, 1800); // 30 นาที
        if ($cached !== null) {
            return $cached;
        }

        // ถ้าไม่มี cache ให้ดึงข้อมูลใหม่
        $stats = $this->generateStatistics($dateFrom, $dateTo);

        // บันทึก cache
        $this->setCache($cacheKey, $stats, [
            'type' => 'statistics',
            'date_range' => "{$dateFrom} to {$dateTo}"
        ]);

        return $stats;
    }

    /**
     * Cache สำหรับข้อมูลที่ใช้บ่อย
     */
    public function getCachedComplaintTypes()
    {
        $cacheKey = 'complaint_types';

        $cached = $this->getCache($cacheKey, 7200); // 2 ชั่วโมง
        if ($cached !== null) {
            return $cached;
        }

        $types = $this->db->fetchAll("
            SELECT t.*, COUNT(r.Re_id) as usage_count 
            FROM type t 
            LEFT JOIN request r ON t.Type_id = r.Type_id AND r.Re_is_spam = 0
            GROUP BY t.Type_id 
            ORDER BY t.Type_infor ASC
        ");

        $this->setCache($cacheKey, $types, ['type' => 'complaint_types']);
        return $types;
    }

    public function getCachedOrganizationUnits()
    {
        $cacheKey = 'organization_units';

        $cached = $this->getCache($cacheKey, 7200); // 2 ชั่วโมง
        if ($cached !== null) {
            return $cached;
        }

        $units = $this->db->fetchAll("
            SELECT u1.*, u2.Unit_name as parent_name
            FROM organization_unit u1
            LEFT JOIN organization_unit u2 ON u1.Unit_parent_id = u2.Unit_id
            ORDER BY u1.Unit_type ASC, u1.Unit_name ASC
        ");

        $this->setCache($cacheKey, $units, ['type' => 'organization_units']);
        return $units;
    }

    /**
     * การสร้างสถิติ
     */
    private function generateStatistics($dateFrom, $dateTo)
    {
        $stats = [];

        // สถิติพื้นฐาน
        $stats['summary'] = [
            'total_requests' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59']),
            'pending_requests' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_status = "0" AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59']),
            'completed_requests' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_status IN ("2", "3") AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59']),
            'average_rating' => round($this->db->fetch("SELECT AVG(e.Eva_score) as avg FROM evaluation e JOIN request r ON e.Re_id = r.Re_id WHERE r.Re_date BETWEEN ? AND ? AND r.Re_is_spam = 0", [$dateFrom, $dateTo . ' 23:59:59'])['avg'] ?? 0, 2)
        ];

        // อัตราความสำเร็จ
        $stats['summary']['success_rate'] = $stats['summary']['total_requests'] > 0
            ? round(($stats['summary']['completed_requests'] / $stats['summary']['total_requests']) * 100, 2)
            : 0;

        // สถิติตามประเภท (Top 5)
        $stats['top_types'] = $this->db->fetchAll("
            SELECT t.Type_infor, t.Type_icon, COUNT(r.Re_id) as count
            FROM type t
            JOIN request r ON t.Type_id = r.Type_id
            WHERE r.Re_date BETWEEN ? AND ? AND r.Re_is_spam = 0
            GROUP BY t.Type_id
            ORDER BY count DESC
            LIMIT 5
        ", [$dateFrom, $dateTo . ' 23:59:59']);

        // แนวโน้มรายวัน (7 วันล่าสุด)
        $stats['recent_trend'] = $this->db->fetchAll("
            SELECT DATE(Re_date) as date, COUNT(*) as count
            FROM request 
            WHERE Re_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND Re_is_spam = 0
            GROUP BY DATE(Re_date)
            ORDER BY date DESC
            LIMIT 7
        ");

        return $stats;
    }

    /**
     * Database Query Optimization
     */
    public function optimizeDatabase()
    {
        $results = [];

        try {
            // วิเคราะห์ตาราง
            $tables = ['request', 'student', 'teacher', 'notification', 'evaluation'];

            foreach ($tables as $table) {
                $result = $this->analyzeTable($table);
                $results[$table] = $result;
            }

            // เพิ่ม indexes ที่จำเป็น
            $this->addOptimalIndexes();

            // ทำความสะอาดข้อมูล
            $this->cleanupData();

            $results['optimization_complete'] = true;
            $results['timestamp'] = date('Y-m-d H:i:s');
        } catch (Exception $e) {
            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    private function analyzeTable($table)
    {
        try {
            $stats = $this->db->fetch("SELECT COUNT(*) as row_count FROM {$table}");
            $size = $this->db->fetch("
                SELECT 
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE() AND table_name = ?
            ", [$table]);

            return [
                'rows' => $stats['row_count'],
                'size_mb' => $size['size_mb'] ?? 0,
                'status' => 'analyzed'
            ];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function addOptimalIndexes()
    {
        $indexes = [
            'request' => [
                'idx_request_date_status' => 'CREATE INDEX IF NOT EXISTS idx_request_date_status ON request (Re_date, Re_status)',
                'idx_request_student_spam' => 'CREATE INDEX IF NOT EXISTS idx_request_student_spam ON request (Stu_id, Re_is_spam)',
                'idx_request_assign_status' => 'CREATE INDEX IF NOT EXISTS idx_request_assign_status ON request (Aj_id, Re_status)'
            ],
            'notification' => [
                'idx_notification_student_status' => 'CREATE INDEX IF NOT EXISTS idx_notification_student_status ON notification (Stu_id, Noti_status)',
                'idx_notification_staff_status' => 'CREATE INDEX IF NOT EXISTS idx_notification_staff_status ON notification (Aj_id, Noti_status)'
            ],
            'evaluation' => [
                'idx_evaluation_request' => 'CREATE INDEX IF NOT EXISTS idx_evaluation_request ON evaluation (Re_id)',
                'idx_evaluation_score_date' => 'CREATE INDEX IF NOT EXISTS idx_evaluation_score_date ON evaluation (Eva_score, created_at)'
            ]
        ];

        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $indexName => $sql) {
                try {
                    $this->db->execute($sql);
                } catch (Exception $e) {
                    error_log("Index creation failed for {$indexName}: " . $e->getMessage());
                }
            }
        }
    }

    private function cleanupData()
    {
        // ลบ notifications เก่า (เก่ากว่า 90 วัน)
        $deleted = $this->db->execute("
            DELETE FROM notification 
            WHERE Noti_date < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");

        // ลบ session เก่า (ถ้ามีตาราง sessions)
        try {
            $this->db->execute("
                DELETE FROM sessions 
                WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 WEEK))
            ");
        } catch (Exception $e) {
            // Table อาจจะไม่มี
        }

        return $deleted;
    }

    /**
     * Performance Monitoring
     */
    public function logPerformance($operation, $executionTime, $additionalData = [])
    {
        $logFile = $this->logDir . 'performance_' . date('Y-m-d') . '.log';

        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'operation' => $operation,
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'additional_data' => $additionalData
        ];

        $logLine = json_encode($logData) . "\n";

        return file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX) !== false;
    }

    public function getPerformanceStats($days = 7)
    {
        $stats = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $logFile = $this->logDir . "performance_{$date}.log";

            if (file_exists($logFile)) {
                $lines = file($logFile, FILE_IGNORE_NEW_LINES);
                $dayStats = [
                    'date' => $date,
                    'total_operations' => count($lines),
                    'avg_execution_time' => 0,
                    'max_execution_time' => 0,
                    'operations' => []
                ];

                $totalTime = 0;
                $operationCounts = [];

                foreach ($lines as $line) {
                    $data = json_decode($line, true);
                    if ($data) {
                        $totalTime += $data['execution_time'];
                        $dayStats['max_execution_time'] = max($dayStats['max_execution_time'], $data['execution_time']);

                        $op = $data['operation'];
                        $operationCounts[$op] = ($operationCounts[$op] ?? 0) + 1;
                    }
                }

                $dayStats['avg_execution_time'] = count($lines) > 0 ? round($totalTime / count($lines), 4) : 0;
                $dayStats['operations'] = $operationCounts;

                $stats[] = $dayStats;
            }
        }

        return $stats;
    }

    /**
     * Image Optimization
     */
    public function optimizeUploadedImages($maxWidth = 1200, $quality = 85)
    {
        $uploadsDir = '../uploads/';
        $optimizedCount = 0;

        if (!is_dir($uploadsDir)) {
            return ['error' => 'Uploads directory not found'];
        }

        $imageFiles = glob($uploadsDir . '*.{jpg,jpeg,png,gif}', GLOB_BRACE);

        foreach ($imageFiles as $imagePath) {
            try {
                $optimized = $this->optimizeImage($imagePath, $maxWidth, $quality);
                if ($optimized) {
                    $optimizedCount++;
                }
            } catch (Exception $e) {
                error_log("Image optimization failed for {$imagePath}: " . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'optimized_count' => $optimizedCount,
            'total_files' => count($imageFiles)
        ];
    }

    private function optimizeImage($imagePath, $maxWidth, $quality)
    {
        $imageInfo = getimagesize($imagePath);
        if (!$imageInfo) {
            return false;
        }

        $originalWidth = $imageInfo[0];
        $originalHeight = $imageInfo[1];
        $imageType = $imageInfo[2];

        // ถ้าภาพเล็กกว่า maxWidth แล้วไม่ต้อง optimize
        if ($originalWidth <= $maxWidth) {
            return false;
        }

        // คำนวณขนาดใหม่
        $newWidth = $maxWidth;
        $newHeight = intval(($originalHeight * $maxWidth) / $originalWidth);

        // สร้าง image resource
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = imagecreatefromgif($imagePath);
                break;
            default:
                return false;
        }

        if (!$sourceImage) {
            return false;
        }

        // สร้างภาพใหม่
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        // จัดการความโปร่งใส (PNG/GIF)
        if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
            imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);
        }

        // Resize
        imagecopyresampled($newImage, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // บันทึกภาพใหม่
        $backupPath = $imagePath . '.backup';
        rename($imagePath, $backupPath); // สำรองไฟล์เดิม

        $result = false;
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $result = imagejpeg($newImage, $imagePath, $quality);
                break;
            case IMAGETYPE_PNG:
                $result = imagepng($newImage, $imagePath, 9 - intval($quality / 10));
                break;
            case IMAGETYPE_GIF:
                $result = imagegif($newImage, $imagePath);
                break;
        }

        // ล้าง memory
        imagedestroy($sourceImage);
        imagedestroy($newImage);

        if ($result) {
            unlink($backupPath); // ลบไฟล์สำรอง
            return true;
        } else {
            rename($backupPath, $imagePath); // คืนไฟล์เดิม
            return false;
        }
    }

    /**
     * Session Optimization
     */
    public function optimizeSessions()
    {
        // ล้าง session files เก่า
        $sessionPath = session_save_path() ?: '/tmp';
        $sessionFiles = glob($sessionPath . '/sess_*');
        $deletedCount = 0;
        $cutoffTime = time() - SESSION_TIMEOUT;

        foreach ($sessionFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    /**
     * System Health Check
     */
    public function systemHealthCheck()
    {
        $health = [
            'timestamp' => date('Y-m-d H:i:s'),
            'overall_status' => 'healthy',
            'checks' => []
        ];

        // Database connectivity
        try {
            $this->db->fetch("SELECT 1");
            $health['checks']['database'] = ['status' => 'ok', 'message' => 'Database connection successful'];
        } catch (Exception $e) {
            $health['checks']['database'] = ['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()];
            $health['overall_status'] = 'unhealthy';
        }

        // Cache directory
        if (is_writable($this->cacheDir)) {
            $health['checks']['cache'] = ['status' => 'ok', 'message' => 'Cache directory is writable'];
        } else {
            $health['checks']['cache'] = ['status' => 'warning', 'message' => 'Cache directory is not writable'];
        }

        // Uploads directory
        $uploadsDir = '../uploads/';
        if (is_dir($uploadsDir) && is_writable($uploadsDir)) {
            $health['checks']['uploads'] = ['status' => 'ok', 'message' => 'Uploads directory is accessible'];
        } else {
            $health['checks']['uploads'] = ['status' => 'warning', 'message' => 'Uploads directory issues'];
        }

        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitBytes = $this->convertToBytes($memoryLimit);
        $memoryPercent = ($memoryUsage / $memoryLimitBytes) * 100;

        if ($memoryPercent < 80) {
            $health['checks']['memory'] = ['status' => 'ok', 'message' => "Memory usage: {$memoryPercent}%"];
        } else {
            $health['checks']['memory'] = ['status' => 'warning', 'message' => "High memory usage: {$memoryPercent}%"];
        }

        // Cache files count
        $cacheFiles = glob($this->cacheDir . '*.cache');
        $cacheCount = count($cacheFiles);
        $health['checks']['cache_files'] = ['status' => 'info', 'message' => "{$cacheCount} cache files"];

        // Recent error logs
        $errorLog = ini_get('error_log');
        if ($errorLog && file_exists($errorLog)) {
            $recentErrors = $this->countRecentErrors($errorLog);
            if ($recentErrors < 10) {
                $health['checks']['errors'] = ['status' => 'ok', 'message' => "{$recentErrors} recent errors"];
            } else {
                $health['checks']['errors'] = ['status' => 'warning', 'message' => "{$recentErrors} recent errors (high)"];
            }
        }

        return $health;
    }

    private function convertToBytes($value)
    {
        $unit = strtolower(substr($value, -1));
        $value = (int) $value;

        switch ($unit) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    private function countRecentErrors($errorLog, $hours = 24)
    {
        if (!file_exists($errorLog)) {
            return 0;
        }

        $cutoffTime = time() - ($hours * 3600);
        $errors = 0;

        $handle = fopen($errorLog, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
                    $logTime = strtotime($matches[1]);
                    if ($logTime >= $cutoffTime) {
                        $errors++;
                    }
                }
            }
            fclose($handle);
        }

        return $errors;
    }

    /**
     * Auto-maintenance tasks
     */
    public function runAutoMaintenance()
    {
        $results = [];

        // ล้าง cache เก่า
        $results['cache_cleanup'] = $this->clearExpiredCache();

        // ล้าง session เก่า
        $results['session_cleanup'] = $this->optimizeSessions();

        // ล้าง log เก่า
        $results['log_cleanup'] = $this->cleanupOldLogs();

        // Optimize database
        if (date('H') == '02') { // รันตอน 2 โมงเช้า
            $results['database_optimization'] = $this->optimizeDatabase();
        }

        // Health check
        $results['health_check'] = $this->systemHealthCheck();

        $results['maintenance_time'] = date('Y-m-d H:i:s');

        // บันทึก log การ maintenance
        $this->logMaintenance($results);

        return $results;
    }

    private function clearExpiredCache()
    {
        $files = glob($this->cacheDir . '*.cache');
        $deleted = 0;

        foreach ($files as $file) {
            $cacheData = json_decode(file_get_contents($file), true);
            if (!$cacheData || (time() - $cacheData['timestamp']) > 86400) { // เก่ากว่า 1 วัน
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function cleanupOldLogs($daysOld = 30)
    {
        $logFiles = glob($this->logDir . '*.log');
        $deleted = 0;
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);

        foreach ($logFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function logMaintenance($results)
    {
        $logFile = $this->logDir . 'maintenance_' . date('Y-m-d') . '.log';
        $logData = json_encode($results) . "\n";

        file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
    }
}

// ฟังก์ชันช่วยเหลือ
function getPerformanceOptimizer()
{
    static $optimizer = null;
    if ($optimizer === null) {
        $optimizer = new PerformanceOptimizer();
    }
    return $optimizer;
}

/**
 * Performance monitoring wrapper
 */
function monitorPerformance($operation, $callback, $additionalData = [])
{
    $startTime = microtime(true);
    $startMemory = memory_get_usage();

    try {
        $result = $callback();
        $success = true;
    } catch (Exception $e) {
        $result = null;
        $success = false;
        $additionalData['error'] = $e->getMessage();
    }

    $endTime = microtime(true);
    $endMemory = memory_get_usage();

    $executionTime = $endTime - $startTime;
    $memoryUsage = $endMemory - $startMemory;

    $additionalData['memory_used'] = $memoryUsage;
    $additionalData['success'] = $success;

    $optimizer = getPerformanceOptimizer();
    $optimizer->logPerformance($operation, $executionTime, $additionalData);

    return $result;
}

/**
 * Quick cache functions
 */
function quickCache($key, $callback, $expiry = 3600)
{
    $optimizer = getPerformanceOptimizer();

    $cached = $optimizer->getCache($key, $expiry);
    if ($cached !== null) {
        return $cached;
    }

    $data = $callback();
    $optimizer->setCache($key, $data);

    return $data;
}

// CLI interface สำหรับ cron jobs
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $optimizer = getPerformanceOptimizer();

    switch ($argv[1]) {
        case 'maintenance':
            $results = $optimizer->runAutoMaintenance();
            echo "Maintenance completed: " . json_encode($results) . "\n";
            break;

        case 'health':
            $health = $optimizer->systemHealthCheck();
            echo "System health: " . json_encode($health) . "\n";
            break;

        case 'optimize-db':
            $results = $optimizer->optimizeDatabase();
            echo "Database optimization: " . json_encode($results) . "\n";
            break;

        case 'optimize-images':
            $results = $optimizer->optimizeUploadedImages();
            echo "Image optimization: " . json_encode($results) . "\n";
            break;

        case 'clear-cache':
            $cleared = $optimizer->clearAllCache();
            echo "Cleared {$cleared} cache files\n";
            break;

        case 'performance-stats':
            $days = $argv[2] ?? 7;
            $stats = $optimizer->getPerformanceStats($days);
            echo "Performance stats: " . json_encode($stats) . "\n";
            break;
    }
}
