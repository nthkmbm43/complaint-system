<?php

/**
 * ExportHandler - ระบบส่งออกข้อมูลรายงาน
 * รองรับการส่งออก Excel, PDF, CSV
 */

require_once '../config.php';
require_once '../database.php';

class ExportHandler
{
    private $db;
    private $exportDir;

    public function __construct()
    {
        $this->db = getDB();
        $this->exportDir = '../exports/';

        // สร้างโฟลเดอร์ exports ถ้าไม่มี
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }

        // ทำความสะอาดไฟล์เก่า (เก็บไว้ 24 ชั่วโมง)
        $this->cleanupOldFiles();
    }

    /**
     * ส่งออกข้อมูลตามรูปแบบที่ขอ
     */
    public function export($format, $type, $dateFrom, $dateTo, $department = 'all')
    {
        try {
            // ดึงข้อมูลสำหรับ export
            $data = $this->getExportData($type, $dateFrom, $dateTo, $department);

            $filename = $this->generateFilename($type, $format);
            $filepath = $this->exportDir . $filename;

            switch (strtolower($format)) {
                case 'excel':
                    $this->exportToExcel($data, $filepath);
                    break;

                case 'pdf':
                    $this->exportToPDF($data, $filepath);
                    break;

                case 'csv':
                    $this->exportToCSV($data, $filepath);
                    break;

                default:
                    throw new Exception('รูปแบบการส่งออกไม่รองรับ');
            }

            return [
                'success' => true,
                'filename' => $filename,
                'filepath' => $filepath,
                'download_url' => 'exports/' . $filename,
                'size' => filesize($filepath)
            ];
        } catch (Exception $e) {
            throw new Exception('เกิดข้อผิดพลาดในการส่งออกข้อมูล: ' . $e->getMessage());
        }
    }

    /**
     * ดึงข้อมูลสำหรับ export
     */
    private function getExportData($type, $dateFrom, $dateTo, $department)
    {
        $whereClause = "WHERE r.Re_date BETWEEN ? AND ? AND r.Re_is_spam = 0";
        $params = [$dateFrom, $dateTo . ' 23:59:59'];

        if ($department !== 'all') {
            $whereClause .= " AND t.Type_department = ?";
            $params[] = $department;
        }

        switch ($type) {
            case 'overview':
                return $this->getOverviewData($whereClause, $params);

            case 'detailed':
                return $this->getDetailedData($whereClause, $params);

            case 'performance':
                return $this->getPerformanceData($whereClause, $params);

            case 'satisfaction':
                return $this->getSatisfactionData($whereClause, $params);

            default:
                return $this->getOverviewData($whereClause, $params);
        }
    }

    /**
     * ข้อมูลภาพรวม
     */
    private function getOverviewData($whereClause, $params)
    {
        $query = "
            SELECT 
                r.Re_id as 'รหัสข้อร้องเรียน',
                DATE_FORMAT(r.Re_date, '%d/%m/%Y %H:%i') as 'วันที่ส่ง',
                t.Type_infor as 'ประเภท',
                CASE 
                    WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
                    ELSE s.Stu_name
                END as 'ผู้ส่ง',
                CASE r.Re_priority
                    WHEN '1' THEN 'ต่ำ'
                    WHEN '2' THEN 'ปกติ'
                    WHEN '3' THEN 'สูง'
                    WHEN '4' THEN 'เร่งด่วน'
                    ELSE 'ไม่ระบุ'
                END as 'ความสำคัญ',
                CASE r.Re_status
                    WHEN '0' THEN 'ยื่นคำร้อง'
                    WHEN '1' THEN 'กำลังดำเนินการ'
                    WHEN '2' THEN 'รอการประเมินผล'
                    WHEN '3' THEN 'เสร็จสิ้น'
                    WHEN '4' THEN 'ปฏิเสธคำร้อง'
                    ELSE 'ไม่ทราบ'
                END as 'สถานะ',
                IFNULL(aj.Aj_name, 'ยังไม่มอบหมาย') as 'เจ้าหน้าที่',
                IFNULL(e.E_star, 'ยังไม่ประเมิน') as 'คะแนนความพึงพอใจ'
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            $whereClause
            ORDER BY r.Re_date DESC
        ";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * ข้อมูลรายละเอียด
     */
    private function getDetailedData($whereClause, $params)
    {
        $query = "
            SELECT 
                r.Re_id as 'รหัสข้อร้องเรียน',
                DATE_FORMAT(r.Re_date, '%d/%m/%Y %H:%i') as 'วันที่ส่ง',
                t.Type_infor as 'ประเภท',
                r.Re_subject as 'หัวข้อ',
                LEFT(r.Re_detail, 100) as 'รายละเอียด (100 ตัวอักษรแรก)',
                CASE 
                    WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน'
                    ELSE CONCAT(s.Stu_name, ' (', s.Stu_id, ')')
                END as 'ผู้ส่ง',
                CASE r.Re_priority
                    WHEN '1' THEN 'ต่ำ'
                    WHEN '2' THEN 'ปกติ'
                    WHEN '3' THEN 'สูง'
                    WHEN '4' THEN 'เร่งด่วน'
                    ELSE 'ไม่ระบุ'
                END as 'ความสำคัญ',
                CASE r.Re_status
                    WHEN '0' THEN 'ยื่นคำร้อง'
                    WHEN '1' THEN 'กำลังดำเนินการ'
                    WHEN '2' THEN 'รอการประเมินผล'
                    WHEN '3' THEN 'เสร็จสิ้น'
                    WHEN '4' THEN 'ปฏิเสธคำร้อง'
                    ELSE 'ไม่ทราบ'
                END as 'สถานะ',
                IFNULL(aj.Aj_name, 'ยังไม่มอบหมาย') as 'เจ้าหน้าที่รับผิดชอบ',
                DATE_FORMAT(r.Re_reply_date, '%d/%m/%Y %H:%i') as 'วันที่ตอบกลับ',
                CASE 
                    WHEN r.Re_reply_date IS NOT NULL THEN 
                        CONCAT(TIMESTAMPDIFF(HOUR, r.Re_date, r.Re_reply_date), ' ชั่วโมง')
                    ELSE 'ยังไม่ได้ตอบกลับ'
                END as 'เวลาตอบสนอง',
                IFNULL(e.E_star, 'ยังไม่ประเมิน') as 'คะแนนความพึงพอใจ',
                LEFT(IFNULL(e.E_comment, ''), 100) as 'ความคิดเห็นเพิ่มเติม'
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            $whereClause
            ORDER BY r.Re_date DESC
        ";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * ข้อมูลประสิทธิภาพ
     */
    private function getPerformanceData($whereClause, $params)
    {
        $query = "
            SELECT 
                aj.Aj_name as 'เจ้าหน้าที่',
                COUNT(r.Re_id) as 'จำนวนเรื่องที่รับผิดชอบ',
                SUM(CASE WHEN r.Re_status IN ('2', '3') THEN 1 ELSE 0 END) as 'จำนวนเรื่องที่เสร็จสิ้น',
                ROUND(SUM(CASE WHEN r.Re_status IN ('2', '3') THEN 1 ELSE 0 END) / COUNT(r.Re_id) * 100, 2) as 'อัตราความสำเร็จ (%)',
                ROUND(AVG(TIMESTAMPDIFF(HOUR, r.Re_date, r.Re_reply_date)), 2) as 'เวลาตอบสนองเฉลี่ย (ชั่วโมง)',
                ROUND(AVG(e.E_star), 2) as 'คะแนนความพึงพอใจเฉลี่ย'
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            $whereClause AND aj.Aj_name IS NOT NULL
            GROUP BY aj.Aj_id, aj.Aj_name
            ORDER BY COUNT(r.Re_id) DESC
        ";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * ข้อมูลความพึงพอใจ
     */
    private function getSatisfactionData($whereClause, $params)
    {
        $query = "
            SELECT 
                t.Type_infor as 'ประเภทข้อร้องเรียน',
                COUNT(e.E_star) as 'จำนวนการประเมิน',
                ROUND(AVG(e.E_star), 2) as 'คะแนนเฉลี่ย',
                SUM(CASE WHEN e.E_star = 5 THEN 1 ELSE 0 END) as '5 ดาว',
                SUM(CASE WHEN e.E_star = 4 THEN 1 ELSE 0 END) as '4 ดาว',
                SUM(CASE WHEN e.E_star = 3 THEN 1 ELSE 0 END) as '3 ดาว',
                SUM(CASE WHEN e.E_star = 2 THEN 1 ELSE 0 END) as '2 ดาว',
                SUM(CASE WHEN e.E_star = 1 THEN 1 ELSE 0 END) as '1 ดาว'
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            $whereClause AND e.E_star IS NOT NULL
            GROUP BY t.Type_id, t.Type_infor
            ORDER BY AVG(e.E_star) DESC, COUNT(e.E_star) DESC
        ";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * ส่งออกเป็น CSV
     */
    private function exportToCSV($data, $filepath)
    {
        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับส่งออก');
        }

        $file = fopen($filepath, 'w');

        // เขียน BOM สำหรับ UTF-8
        fwrite($file, "\xEF\xBB\xBF");

        // เขียนหัวตาราง
        fputcsv($file, array_keys($data[0]));

        // เขียนข้อมูล
        foreach ($data as $row) {
            fputcsv($file, $row);
        }

        fclose($file);
    }

    /**
     * ส่งออกเป็น Excel (HTML format)
     */
    private function exportToExcel($data, $filepath)
    {
        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับส่งออก');
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="utf-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
    </style>

    <style>
        /* Global Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        html { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body>
    <h2>รายงานข้อร้องเรียนนักศึกษา</h2>
    <p>สร้างวันที่: ' . date('d/m/Y H:i:s') . '</p>
    <table>';

        // หัวตาราง
        $html .= '<tr>';
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';

        // ข้อมูล
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table></body></html>';

        file_put_contents($filepath, $html);
    }

    /**
     * ส่งออกเป็น PDF (HTML to PDF)
     */
    private function exportToPDF($data, $filepath)
    {
        // สำหรับตัวอย่างนี้ใช้ HTML format แทน PDF จริง
        // ในระบบจริงอาจใช้ไลบรารี่เช่น TCPDF หรือ mPDF

        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับส่งออก');
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: "Sarabun", Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 30px; }
        .title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
        .info { margin-bottom: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border: 1px solid #333; padding: 6px; text-align: left; font-size: 11px; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">รายงานข้อร้องเรียนนักศึกษา</div>
        <div class="subtitle">มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</div>
    </div>
    <div class="info">
        <p><strong>วันที่สร้างรายงาน:</strong> ' . date('d/m/Y H:i:s') . '</p>
        <p><strong>จำนวนรายการ:</strong> ' . count($data) . ' รายการ</p>
    </div>
    <table>';

        // หัวตาราง
        $html .= '<tr>';
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }
        $html .= '</tr>';

        // ข้อมูล
        foreach ($data as $index => $row) {
            // แบ่งหน้าทุก 25 แถว
            if ($index > 0 && $index % 25 == 0) {
                $html .= '</table><div class="page-break"></div><table>';
                $html .= '<tr>';
                foreach (array_keys($data[0]) as $header) {
                    $html .= '<th>' . htmlspecialchars($header) . '</th>';
                }
                $html .= '</tr>';
            }

            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</table></body></html>';

        file_put_contents($filepath, $html);
    }

    /**
     * สร้างชื่อไฟล์
     */
    private function generateFilename($type, $format)
    {
        $date = date('Y-m-d_H-i-s');
        $typeNames = [
            'overview' => 'ภาพรวม',
            'detailed' => 'รายละเอียด',
            'performance' => 'ประสิทธิภาพ',
            'satisfaction' => 'ความพึงพอใจ'
        ];

        $typeName = $typeNames[$type] ?? $type;
        return "รายงาน_{$typeName}_{$date}.{$format}";
    }

    /**
     * ทำความสะอาดไฟล์เก่า
     */
    private function cleanupOldFiles()
    {
        if (!is_dir($this->exportDir)) return;

        $files = glob($this->exportDir . '*');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 24 * 3600) { // 24 ชั่วโมง
                    unlink($file);
                }
            }
        }
    }
}
