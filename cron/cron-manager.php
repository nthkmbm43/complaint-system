<?php

/**
 * Cron Jobs & Integration Manager
 * ระบบจัดการงานอัตโนมัติและการบูรณาการ
 * File: cron/cron-manager.php
 */

// เป็น CLI script เท่านั้น
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from command line');
}

define('SECURE_ACCESS', true);
define('CRON_RUNNING', true);

// Include required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailNotification.php';
require_once __DIR__ . '/../includes/DataExport.php';
require_once __DIR__ . '/../includes/PerformanceOptimizer.php';

/**
 * Main Cron Manager Class
 */
class CronManager
{
    private $db;
    private $logFile;
    private $lockDir;
    private $emailService;
    private $exportService;
    private $optimizer;

    public function __construct()
    {
        $this->db = getDB();
        $this->logFile = __DIR__ . '/logs/cron_' . date('Y-m-d') . '.log';
        $this->lockDir = __DIR__ . '/locks/';

        // Ensure directories exist
        $this->ensureDirectories();

        // Initialize services
        $this->emailService = getEmailNotificationService();
        $this->exportService = getDataExportService();
        $this->optimizer = getPerformanceOptimizer();
    }

    private function ensureDirectories()
    {
        $dirs = [
            dirname($this->logFile),
            $this->lockDir,
            __DIR__ . '/exports/',
            __DIR__ . '/backups/'
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Execute cron job with locking mechanism
     */
    public function execute($jobName, $callback)
    {
        $lockFile = $this->lockDir . $jobName . '.lock';

        // Check if job is already running
        if (file_exists($lockFile)) {
            $lockTime = filemtime($lockFile);
            $maxRunTime = 3600; // 1 hour max

            if (time() - $lockTime < $maxRunTime) {
                $this->log("Job {$jobName} is already running. Skipping.");
                return false;
            } else {
                // Remove stale lock
                unlink($lockFile);
                $this->log("Removed stale lock for job {$jobName}");
            }
        }

        // Create lock file
        file_put_contents($lockFile, getmypid());

        $startTime = microtime(true);
        $success = false;

        try {
            $this->log("Starting job: {$jobName}");

            $result = $callback();
            $success = true;

            $this->log("Job {$jobName} completed successfully. Result: " . json_encode($result));
        } catch (Exception $e) {
            $this->log("Job {$jobName} failed: " . $e->getMessage(), 'ERROR');
            $success = false;
        } finally {
            // Remove lock file
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }

            $executionTime = microtime(true) - $startTime;
            $this->log("Job {$jobName} finished in " . round($executionTime, 2) . " seconds");

            // Log performance
            $this->optimizer->logPerformance("cron_{$jobName}", $executionTime, [
                'success' => $success,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        return $success;
    }

    /**
     * Log message with timestamp
     */
    private function log($message, $level = 'INFO')
    {
        $logEntry = date('Y-m-d H:i:s') . " [{$level}] {$message}\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);

        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            echo $logEntry;
        }
    }

    // ========================================
    // CRON JOB DEFINITIONS
    // ========================================

    /**
     * Send daily reports (runs at 7:00 AM)
     */
    public function sendDailyReports()
    {
        return $this->execute('daily_reports', function () {
            $success = sendDailyReportEmail();

            if ($success) {
                return ['status' => 'sent', 'timestamp' => date('Y-m-d H:i:s')];
            } else {
                throw new Exception('Failed to send daily reports');
            }
        });
    }

    /**
     * Check for overdue complaints (runs every 2 hours)
     */
    public function checkOverdueComplaints()
    {
        return $this->execute('overdue_check', function () {
            $success = sendOverdueNotifications();

            // Also check for complaints that exceed maximum response time
            $criticalOverdue = $this->db->fetchAll("
                SELECT r.Re_id, r.Re_title, TIMESTAMPDIFF(HOUR, r.Re_date, NOW()) as hours_overdue,
                       s.Stu_name, s.Stu_email, t.Aj_name as staff_name, t.Aj_email as staff_email
                FROM request r
                LEFT JOIN student s ON r.Stu_id = s.Stu_id
                LEFT JOIN teacher t ON r.Aj_id = t.Aj_id
                WHERE r.Re_status = '0' 
                  AND TIMESTAMPDIFF(HOUR, r.Re_date, NOW()) >= 72
                  AND r.Re_is_spam = 0
                ORDER BY hours_overdue DESC
            ");

            $criticalCount = count($criticalOverdue);

            // Send alert to admin if there are critical overdue complaints
            if ($criticalCount > 0) {
                $adminEmails = $this->db->fetchAll("
                    SELECT Aj_email, Aj_name 
                    FROM teacher 
                    WHERE Aj_per = 3 AND Aj_status = 1 
                      AND Aj_email IS NOT NULL AND Aj_email != ''
                ");

                foreach ($adminEmails as $admin) {
                    $this->emailService->sendNotificationEmail(
                        $admin['Aj_email'],
                        "🚨 แจ้งเตือนเร่งด่วน: ข้อร้องเรียนค้างตอบเกิน 72 ชั่วโมง",
                        "มีข้อร้องเรียน {$criticalCount} รายการที่ค้างการตอบกลับเกิน 72 ชั่วโมง กรุณาดำเนินการทันที",
                        null,
                        'urgent_alert'
                    );
                }
            }

            return [
                'overdue_notifications_sent' => $success,
                'critical_overdue_count' => $criticalCount,
                'admin_alerts_sent' => $criticalCount > 0 ? count($adminEmails) : 0
            ];
        });
    }

    /**
     * Auto-backup database (runs at 2:00 AM)
     */
    public function backupDatabase()
    {
        return $this->execute('database_backup', function () {
            $backupDir = __DIR__ . '/backups/';
            $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $backupDir . $filename;

            // Create mysqldump command
            $command = sprintf(
                'mysqldump -h%s -u%s -p%s %s > %s 2>&1',
                escapeshellarg(DB_HOST),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASS),
                escapeshellarg(DB_NAME),
                escapeshellarg($filepath)
            );

            // Execute backup
            exec($command, $output, $returnVar);

            if ($returnVar === 0 && file_exists($filepath) && filesize($filepath) > 0) {
                // Compress the backup
                $gzFilepath = $filepath . '.gz';
                $compressed = gzopen($gzFilepath, 'w9');
                $original = fopen($filepath, 'r');

                if ($compressed && $original) {
                    while (!feof($original)) {
                        gzwrite($compressed, fread($original, 8192));
                    }
                    fclose($original);
                    gzclose($compressed);

                    // Remove uncompressed file
                    unlink($filepath);
                    $filepath = $gzFilepath;
                }

                // Clean up old backups (keep last 7 days)
                $this->cleanupOldBackups($backupDir, 7);

                return [
                    'status' => 'success',
                    'filename' => basename($filepath),
                    'size' => filesize($filepath),
                    'size_formatted' => $this->formatFileSize(filesize($filepath))
                ];
            } else {
                throw new Exception('Database backup failed: ' . implode('\n', $output));
            }
        });
    }

    /**
     * System maintenance (runs at 3:00 AM)
     */
    public function systemMaintenance()
    {
        return $this->execute('system_maintenance', function () {
            $results = $this->optimizer->runAutoMaintenance();

            // Additional maintenance tasks
            $results['notifications_cleaned'] = $this->cleanupOldNotifications();
            $results['logs_rotated'] = $this->rotateLogs();
            $results['temp_files_cleaned'] = $this->cleanupTempFiles();

            return $results;
        });
    }

    /**
     * Generate weekly reports (runs Sunday at 8:00 AM)
     */
    public function generateWeeklyReports()
    {
        return $this->execute('weekly_reports', function () {
            $dateFrom = date('Y-m-d', strtotime('last monday'));
            $dateTo = date('Y-m-d', strtotime('last sunday'));

            // Export weekly statistics
            $statsResult = $this->exportService->exportStatistics('excel', $dateFrom, $dateTo);

            if (!$statsResult['success']) {
                throw new Exception('Failed to generate weekly statistics report');
            }

            // Send to administrators
            $adminEmails = $this->db->fetchAll("
                SELECT Aj_email, Aj_name 
                FROM teacher 
                WHERE Aj_per = 3 AND Aj_status = 1 
                  AND Aj_email IS NOT NULL AND Aj_email != ''
            ");

            $emailsSent = 0;
            foreach ($adminEmails as $admin) {
                $emailSent = $this->emailService->sendNotificationEmail(
                    $admin['Aj_email'],
                    "รายงานสถิติประจำสัปดาห์ ({$dateFrom} ถึง {$dateTo})",
                    "รายงานสถิติระบบข้อร้องเรียนประจำสัปดาห์พร้อมแล้ว กรุณาดาวน์โหลดจากระบบ",
                    null,
                    'weekly_report'
                );

                if ($emailSent) {
                    $emailsSent++;
                }
            }

            return [
                'report_generated' => true,
                'report_file' => $statsResult['filename'],
                'period' => "{$dateFrom} to {$dateTo}",
                'emails_sent' => $emailsSent
            ];
        });
    }

    /**
     * Update system statistics cache (runs every 30 minutes)
     */
    public function updateStatisticsCache()
    {
        return $this->execute('update_statistics', function () {
            // Cache various statistics for better performance
            $cacheKeys = [
                'dashboard_stats_today' => function () {
                    return [
                        'new_requests' => $this->db->count('request', 'DATE(Re_date) = CURDATE() AND Re_is_spam = 0'),
                        'completed_requests' => $this->db->count('request', 'DATE(updated_at) = CURDATE() AND Re_status IN ("2", "3") AND Re_is_spam = 0'),
                        'new_registrations' => $this->db->count('student', 'DATE(created_at) = CURDATE()'),
                        'active_users' => $this->db->count('student', 'Stu_status = 1') + $this->db->count('teacher', 'Aj_status = 1')
                    ];
                },
                'complaint_types_stats' => function () {
                    return $this->optimizer->getCachedComplaintTypes();
                },
                'monthly_trend' => function () {
                    return $this->db->fetchAll("
                        SELECT DATE(Re_date) as date, COUNT(*) as count
                        FROM request 
                        WHERE Re_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) 
                          AND Re_is_spam = 0
                        GROUP BY DATE(Re_date)
                        ORDER BY date
                    ");
                }
            ];

            $updated = 0;
            foreach ($cacheKeys as $key => $callback) {
                try {
                    $data = $callback();
                    $this->optimizer->setCache($key, $data, ['type' => 'statistics', 'auto_generated' => true]);
                    $updated++;
                } catch (Exception $e) {
                    $this->log("Failed to update cache for {$key}: " . $e->getMessage(), 'WARNING');
                }
            }

            return ['caches_updated' => $updated, 'total_caches' => count($cacheKeys)];
        });
    }

    /**
     * Send notification digest (runs at 6:00 PM)
     */
    public function sendNotificationDigest()
    {
        return $this->execute('notification_digest', function () {
            // Get users who have unread notifications
            $usersWithNotifications = $this->db->fetchAll("
                SELECT 
                    COALESCE(n.Stu_id, n.Aj_id) as user_id,
                    CASE WHEN n.Stu_id IS NOT NULL THEN 'student' ELSE 'staff' END as user_type,
                    CASE WHEN n.Stu_id IS NOT NULL THEN s.Stu_name ELSE t.Aj_name END as user_name,
                    CASE WHEN n.Stu_id IS NOT NULL THEN s.Stu_email ELSE t.Aj_email END as user_email,
                    COUNT(*) as unread_count
                FROM notification n
                LEFT JOIN student s ON n.Stu_id = s.Stu_id
                LEFT JOIN teacher t ON n.Aj_id = t.Aj_id
                WHERE n.Noti_status = 0 
                  AND n.Noti_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND (s.Stu_email IS NOT NULL OR t.Aj_email IS NOT NULL)
                GROUP BY user_id, user_type
                HAVING unread_count >= 3
            ");

            $digestsSent = 0;
            foreach ($usersWithNotifications as $user) {
                if (!$user['user_email']) continue;

                // Get recent notifications for this user
                $notifications = $this->db->fetchAll("
                    SELECT Noti_title, Noti_message, Noti_date
                    FROM notification
                    WHERE " . ($user['user_type'] === 'student' ? 'Stu_id' : 'Aj_id') . " = ?
                      AND Noti_status = 0
                      AND Noti_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    ORDER BY Noti_date DESC
                    LIMIT 5
                ", [$user['user_id']]);

                $digestContent = "สวัสดี คุณ{$user['user_name']}\n\n";
                $digestContent .= "คุณมีการแจ้งเตือนที่ยังไม่ได้อ่าน {$user['unread_count']} รายการ:\n\n";

                foreach ($notifications as $notification) {
                    $digestContent .= "• {$notification['Noti_title']}\n";
                    $digestContent .= "  {$notification['Noti_message']}\n";
                    $digestContent .= "  วันที่: " . date('d/m/Y H:i', strtotime($notification['Noti_date'])) . "\n\n";
                }

                $digestContent .= "กรุณาเข้าสู่ระบบเพื่อดูรายละเอียดเพิ่มเติม: " . SITE_URL;

                $emailSent = $this->emailService->sendNotificationEmail(
                    $user['user_email'],
                    "สรุปการแจ้งเตือน - {$user['unread_count']} รายการ",
                    $digestContent,
                    null,
                    'notification_digest'
                );

                if ($emailSent) {
                    $digestsSent++;
                }
            }

            return [
                'users_with_notifications' => count($usersWithNotifications),
                'digests_sent' => $digestsSent
            ];
        });
    }

    /**
     * Process auto-evaluations (runs daily at 9:00 PM)
     */
    public function processAutoEvaluations()
    {
        return $this->execute('auto_evaluations', function () {
            // Find completed requests that haven't been evaluated after 7 days
            $autoEvaluateRequests = $this->db->fetchAll("
                SELECT r.Re_id, r.Stu_id, s.Stu_email, s.Stu_name
                FROM request r
                JOIN student s ON r.Stu_id = s.Stu_id
                WHERE r.Re_status = '2'
                  AND r.updated_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND NOT EXISTS (SELECT 1 FROM evaluation WHERE Re_id = r.Re_id)
                  AND r.Re_is_spam = 0
                LIMIT 50
            ");

            $remindersSet = 0;
            foreach ($autoEvaluateRequests as $request) {
                // Set status to evaluated with default rating
                $this->db->insert('evaluation', [
                    'Re_id' => $request['Re_id'],
                    'Eva_score' => 3, // Default neutral rating
                    'Eva_sug' => 'ประเมินอัตโนมัติ - ไม่มีการประเมินภายในเวลาที่กำหนด',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                // Update request status
                $this->db->update('request', [
                    'Re_status' => '3',
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'Re_id = ?', [$request['Re_id']]);

                // Send notification about auto-evaluation
                if ($request['Stu_email']) {
                    $this->emailService->sendNotificationEmail(
                        $request['Stu_email'],
                        "ข้อร้องเรียน #{$request['Re_id']} ได้รับการประเมินอัตโนมัติ",
                        "เนื่องจากไม่มีการประเมินความพึงพอใจภายใน 7 วัน ระบบได้ทำการประเมินอัตโนมัติให้แล้ว คุณยังสามารถแก้ไขการประเมินได้ในระบบ",
                        $request['Re_id'],
                        'auto_evaluation'
                    );
                }

                $remindersSet++;
            }

            return [
                'requests_found' => count($autoEvaluateRequests),
                'auto_evaluations_processed' => $remindersSet
            ];
        });
    }

    // ========================================
    // HELPER FUNCTIONS
    // ========================================

    private function cleanupOldBackups($backupDir, $daysToKeep)
    {
        $files = glob($backupDir . 'backup_*.sql*');
        $cutoffTime = time() - ($daysToKeep * 24 * 60 * 60);
        $deletedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    private function cleanupOldNotifications($daysToKeep = 90)
    {
        return $this->db->execute("
            DELETE FROM notification 
            WHERE Noti_date < DATE_SUB(NOW(), INTERVAL ? DAY)
        ", [$daysToKeep]);
    }

    private function rotateLogs()
    {
        $logDir = __DIR__ . '/logs/';
        $rotatedCount = 0;

        if (is_dir($logDir)) {
            $logFiles = glob($logDir . '*.log');
            $cutoffTime = time() - (30 * 24 * 60 * 60); // 30 days

            foreach ($logFiles as $logFile) {
                if (filemtime($logFile) < $cutoffTime) {
                    // Compress old log
                    $gzFile = $logFile . '.gz';
                    $original = fopen($logFile, 'r');
                    $compressed = gzopen($gzFile, 'w9');

                    if ($original && $compressed) {
                        while (!feof($original)) {
                            gzwrite($compressed, fread($original, 8192));
                        }
                        fclose($original);
                        gzclose($compressed);
                        unlink($logFile);
                        $rotatedCount++;
                    }
                }
            }
        }

        return $rotatedCount;
    }

    private function cleanupTempFiles()
    {
        $tempDirs = [
            sys_get_temp_dir(),
            __DIR__ . '/../cache/',
            __DIR__ . '/../uploads/temp/'
        ];

        $deletedCount = 0;
        $cutoffTime = time() - (24 * 60 * 60); // 24 hours

        foreach ($tempDirs as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/tmp_*');
                foreach ($files as $file) {
                    if (is_file($file) && filemtime($file) < $cutoffTime) {
                        if (unlink($file)) {
                            $deletedCount++;
                        }
                    }
                }
            }
        }

        return $deletedCount;
    }

    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// ========================================
// MAIN EXECUTION
// ========================================

if (count($argv) < 2) {
    echo "Usage: php cron-manager.php <job_name>\n";
    echo "Available jobs:\n";
    echo "  daily-reports      - Send daily reports\n";
    echo "  overdue-check      - Check overdue complaints\n";
    echo "  backup-database    - Backup database\n";
    echo "  system-maintenance - Run system maintenance\n";
    echo "  weekly-reports     - Generate weekly reports\n";
    echo "  update-cache       - Update statistics cache\n";
    echo "  notification-digest - Send notification digest\n";
    echo "  auto-evaluations   - Process auto evaluations\n";
    echo "  health-check       - System health check\n";
    echo "  all                - Run all appropriate jobs for current time\n";
    exit(1);
}

$cronManager = new CronManager();
$jobName = $argv[1];

try {
    switch ($jobName) {
        case 'daily-reports':
            $result = $cronManager->sendDailyReports();
            break;

        case 'overdue-check':
            $result = $cronManager->checkOverdueComplaints();
            break;

        case 'backup-database':
            $result = $cronManager->backupDatabase();
            break;

        case 'system-maintenance':
            $result = $cronManager->systemMaintenance();
            break;

        case 'weekly-reports':
            $result = $cronManager->generateWeeklyReports();
            break;

        case 'update-cache':
            $result = $cronManager->updateStatisticsCache();
            break;

        case 'notification-digest':
            $result = $cronManager->sendNotificationDigest();
            break;

        case 'auto-evaluations':
            $result = $cronManager->processAutoEvaluations();
            break;

        case 'health-check':
            $optimizer = getPerformanceOptimizer();
            $result = $optimizer->systemHealthCheck();
            echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
            break;

        case 'all':
            $hour = date('H');
            $dayOfWeek = date('w'); // 0 = Sunday

            $results = [];

            // Run different jobs based on time
            if ($hour == '02') {
                $results['backup'] = $cronManager->backupDatabase();
            }

            if ($hour == '03') {
                $results['maintenance'] = $cronManager->systemMaintenance();
            }

            if ($hour == '07') {
                $results['daily_reports'] = $cronManager->sendDailyReports();
            }

            if ($hour == '08' && $dayOfWeek == 0) { // Sunday
                $results['weekly_reports'] = $cronManager->generateWeeklyReports();
            }

            if ($hour == '18') {
                $results['notification_digest'] = $cronManager->sendNotificationDigest();
            }

            if ($hour == '21') {
                $results['auto_evaluations'] = $cronManager->processAutoEvaluations();
            }

            // Run every 2 hours
            if ($hour % 2 == 0) {
                $results['overdue_check'] = $cronManager->checkOverdueComplaints();
            }

            // Run every 30 minutes (if minutes are 0 or 30)
            $minute = date('i');
            if ($minute == '00' || $minute == '30') {
                $results['update_cache'] = $cronManager->updateStatisticsCache();
            }

            $result = $results;
            break;

        default:
            throw new Exception("Unknown job: {$jobName}");
    }

    if ($result) {
        echo "Job completed successfully: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
        exit(0);
    } else {
        echo "Job failed or was skipped\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
