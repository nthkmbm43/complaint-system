<?php
// includes/DataExport.php - ระบบ Export ข้อมูล
define('SECURE_ACCESS', true);

class DataExport
{
    private $db;
    private $exportDir;

    public function __construct()
    {
        $this->db = getDB();
        $this->exportDir = '../exports/';

        // สร้างโฟลเดอร์ export ถ้ายังไม่มี
        if (!is_dir($this->exportDir)) {
            mkdir($this->exportDir, 0755, true);
        }
    }

    /**
     * Export ข้อมูลข้อร้องเรียน
     */
    public function exportComplaints($format = 'csv', $filters = [])
    {
        try {
            $data = $this->getComplaintsData($filters);

            switch (strtolower($format)) {
                case 'csv':
                    return $this->exportToCSV($data, 'complaints');
                case 'excel':
                    return $this->exportToExcel($data, 'complaints');
                case 'pdf':
                    return $this->exportToPDF($data, 'complaints');
                case 'json':
                    return $this->exportToJSON($data, 'complaints');
                default:
                    throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
            }
        } catch (Exception $e) {
            error_log("Export complaints error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export รายงานสถิติ
     */
    public function exportStatistics($format = 'excel', $dateFrom = null, $dateTo = null)
    {
        try {
            $data = $this->getStatisticsData($dateFrom, $dateTo);

            switch (strtolower($format)) {
                case 'excel':
                    return $this->exportStatisticsToExcel($data, $dateFrom, $dateTo);
                case 'pdf':
                    return $this->exportStatisticsToPDF($data, $dateFrom, $dateTo);
                case 'csv':
                    return $this->exportToCSV($data['summary'], 'statistics');
                default:
                    throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
            }
        } catch (Exception $e) {
            error_log("Export statistics error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export ข้อมูลนักศึกษา
     */
    public function exportStudents($format = 'csv')
    {
        try {
            $data = $this->getStudentsData();

            switch (strtolower($format)) {
                case 'csv':
                    return $this->exportToCSV($data, 'students');
                case 'excel':
                    return $this->exportToExcel($data, 'students');
                default:
                    throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
            }
        } catch (Exception $e) {
            error_log("Export students error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export ข้อมูลเจ้าหน้าที่
     */
    public function exportStaff($format = 'csv')
    {
        try {
            $data = $this->getStaffData();

            switch (strtolower($format)) {
                case 'csv':
                    return $this->exportToCSV($data, 'staff');
                case 'excel':
                    return $this->exportToExcel($data, 'staff');
                default:
                    throw new Exception('รูปแบบไฟล์ไม่ถูกต้อง');
            }
        } catch (Exception $e) {
            error_log("Export staff error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ดึงข้อมูลข้อร้องเรียน
     */
    private function getComplaintsData($filters = [])
    {
        $whereConditions = ['r.Re_is_spam = 0'];
        $params = [];

        // ใช้ filters
        if (!empty($filters['date_from'])) {
            $whereConditions[] = 'r.Re_date >= ?';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $whereConditions[] = 'r.Re_date <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['status'])) {
            $whereConditions[] = 'r.Re_status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['type_id'])) {
            $whereConditions[] = 'r.Type_id = ?';
            $params[] = $filters['type_id'];
        }

        if (!empty($filters['priority'])) {
            $whereConditions[] = 'r.Re_level = ?';
            $params[] = $filters['priority'];
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "
            SELECT 
                r.Re_id as 'รหัสข้อร้องเรียน',
                r.Re_title as 'หัวเรื่อง',
                r.Re_infor as 'รายละเอียด',
                t.Type_infor as 'ประเภท',
                CASE r.Re_level
                    WHEN '1' THEN 'ไม่เร่งด่วน'
                    WHEN '2' THEN 'ปกติ'
                    WHEN '3' THEN 'สำคัญ'
                    WHEN '4' THEN 'เร่งด่วน'
                    WHEN '5' THEN 'วิกฤต'
                    ELSE 'ไม่ระบุ'
                END as 'ระดับความสำคัญ',
                CASE r.Re_status
                    WHEN '0' THEN 'ยื่นคำร้อง'
                    WHEN '1' THEN 'กำลังดำเนินการ'
                    WHEN '2' THEN 'รอการประเมินผล'
                    WHEN '3' THEN 'เสร็จสิ้น'
                    WHEN '4' THEN 'ปฏิเสธคำร้อง'
                    ELSE 'ไม่ระบุ'
                END as 'สถานะ',
                CASE r.Re_iden
                    WHEN 1 THEN 'ไม่ระบุตัวตน'
                    ELSE COALESCE(s.Stu_name, 'ไม่ระบุ')
                END as 'ผู้ส่ง',
                COALESCE(s.Stu_id, '-') as 'รหัสนักศึกษา',
                COALESCE(aj.Aj_name, '-') as 'เจ้าหน้าที่รับผิดชอบ',
                DATE_FORMAT(r.Re_date, '%d/%m/%Y %H:%i') as 'วันที่ส่ง',
                COALESCE(DATE_FORMAT(r.updated_at, '%d/%m/%Y %H:%i'), '-') as 'วันที่อัพเดตล่าสุด',
                COALESCE(e.Eva_score, '-') as 'คะแนนประเมิน',
                COALESCE(e.Eva_sug, '-') as 'ข้อเสนอแนะ'
            FROM request r
            LEFT JOIN type t ON r.Type_id = t.Type_id
            LEFT JOIN student s ON r.Stu_id = s.Stu_id
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            WHERE {$whereClause}
            ORDER BY r.Re_date DESC
        ";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * ดึงข้อมูลสถิติ
     */
    private function getStatisticsData($dateFrom = null, $dateTo = null)
    {
        $dateFrom = $dateFrom ?: date('Y-m-01'); // เริ่มต้นเดือน
        $dateTo = $dateTo ?: date('Y-m-t'); // สิ้นเดือน

        $data = [];

        // สรุปข้อมูลรวม
        $data['summary'] = [
            [
                'รายการ' => 'ข้อร้องเรียนทั้งหมด',
                'จำนวน' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59'])
            ],
            [
                'รายการ' => 'รอดำเนินการ',
                'จำนวน' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_status = "0" AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59'])
            ],
            [
                'รายการ' => 'ยืนยันแล้ว',
                'จำนวน' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_status = "1" AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59'])
            ],
            [
                'รายการ' => 'เสร็จสิ้น',
                'จำนวน' => $this->db->count('request', 'Re_date BETWEEN ? AND ? AND Re_status IN ("2", "3") AND Re_is_spam = 0', [$dateFrom, $dateTo . ' 23:59:59'])
            ]
        ];

        // สถิติตามประเภท
        $data['by_type'] = $this->db->fetchAll("
            SELECT 
                t.Type_infor as 'ประเภทข้อร้องเรียน',
                COUNT(r.Re_id) as 'จำนวน',
                ROUND((COUNT(r.Re_id) * 100.0 / (SELECT COUNT(*) FROM request WHERE Re_date BETWEEN ? AND ? AND Re_is_spam = 0)), 2) as 'เปอร์เซ็นต์',
                ROUND(AVG(CASE WHEN e.Eva_score > 0 THEN e.Eva_score END), 2) as 'คะแนนเฉลี่ย'
            FROM type t
            LEFT JOIN request r ON t.Type_id = r.Type_id AND r.Re_date BETWEEN ? AND ? AND r.Re_is_spam = 0
            LEFT JOIN evaluation e ON r.Re_id = e.Re_id
            GROUP BY t.Type_id
            ORDER BY COUNT(r.Re_id) DESC
        ", [$dateFrom, $dateTo . ' 23:59:59', $dateFrom, $dateTo . ' 23:59:59']);

        // สถิติตามเจ้าหน้าที่
        $data['by_staff'] = $this->db->fetchAll("
            SELECT 
                COALESCE(aj.Aj_name, 'ยังไม่ได้มอบหมาย') as 'เจ้าหน้าที่',
                COUNT(r.Re_id) as 'ข้อร้องเรียนที่รับผิดชอบ',
                COUNT(CASE WHEN r.Re_status IN ('2', '3') THEN 1 END) as 'เสร็จสิ้นแล้ว',
                ROUND((COUNT(CASE WHEN r.Re_status IN ('2', '3') THEN 1 END) * 100.0 / COUNT(r.Re_id)), 2) as 'อัตราความสำเร็จ (%)',
                ROUND(AVG(TIMESTAMPDIFF(HOUR, r.Re_date, sr.Sv_date)), 2) as 'เวลาตอบสนองเฉลี่ย (ชั่วโมง)'
            FROM request r
            LEFT JOIN teacher aj ON r.Aj_id = aj.Aj_id
            LEFT JOIN save_request sr ON r.Re_id = sr.Re_id
            WHERE r.Re_date BETWEEN ? AND ? AND r.Re_is_spam = 0
            GROUP BY r.Aj_id
            ORDER BY COUNT(r.Re_id) DESC
        ", [$dateFrom, $dateTo . ' 23:59:59']);

        // แนวโน้มรายวัน
        $data['daily_trend'] = $this->db->fetchAll("
            SELECT 
                DATE_FORMAT(Re_date, '%d/%m/%Y') as 'วันที่',
                COUNT(*) as 'จำนวนข้อร้องเรียน'
            FROM request 
            WHERE Re_date BETWEEN ? AND ? AND Re_is_spam = 0
            GROUP BY DATE(Re_date)
            ORDER BY Re_date
        ", [$dateFrom, $dateTo . ' 23:59:59']);

        return $data;
    }

    /**
     * ดึงข้อมูลนักศึกษา
     */
    private function getStudentsData()
    {
        return $this->db->fetchAll("
            SELECT 
                s.Stu_id as 'รหัสนักศึกษา',
                s.Stu_name as 'ชื่อ-นามสกุล',
                COALESCE(f.Unit_name, '-') as 'คณะ',
                COALESCE(m.Unit_name, '-') as 'สาขา',
                COALESCE(s.Stu_tel, '-') as 'เบอร์โทร',
                COALESCE(s.Stu_email, '-') as 'อีเมล',
                CASE s.Stu_status
                    WHEN 1 THEN 'ใช้งาน'
                    ELSE 'ระงับ'
                END as 'สถานะ',
                COUNT(r.Re_id) as 'จำนวนข้อร้องเรียน',
                DATE_FORMAT(s.created_at, '%d/%m/%Y %H:%i') as 'วันที่ลงทะเบียน'
            FROM student s
            LEFT JOIN organization_unit m ON s.Unit_id = m.Unit_id
            LEFT JOIN organization_unit f ON m.Unit_parent_id = f.Unit_id
            LEFT JOIN request r ON s.Stu_id = r.Stu_id AND r.Re_is_spam = 0
            GROUP BY s.Stu_id
            ORDER BY s.created_at DESC
        ");
    }

    /**
     * ดึงข้อมูลเจ้าหน้าที่
     */
    private function getStaffData()
    {
        return $this->db->fetchAll("
            SELECT 
                t.Aj_id as 'รหัสเจ้าหน้าที่',
                t.Aj_name as 'ชื่อ-นามสกุล',
                t.Aj_position as 'ตำแหน่ง',
                CASE t.Aj_per
                    WHEN 1 THEN 'เจ้าหน้าที่'
                    WHEN 2 THEN 'หัวหน้างาน'
                    WHEN 3 THEN 'ผู้ดูแลระบบ'
                    ELSE 'ไม่ระบุ'
                END as 'ระดับสิทธิ์',
                COALESCE(t.Aj_email, '-') as 'อีเมล',
                COALESCE(t.Aj_phone, '-') as 'เบอร์โทร',
                CASE t.Aj_status
                    WHEN 1 THEN 'ใช้งาน'
                    ELSE 'ระงับ'
                END as 'สถานะ',
                COUNT(r.Re_id) as 'ข้อร้องเรียนที่รับผิดชอบ',
                COUNT(CASE WHEN r.Re_status IN ('2', '3') THEN 1 END) as 'เสร็จสิ้นแล้ว'
            FROM teacher t
            LEFT JOIN request r ON t.Aj_id = r.Aj_id AND r.Re_is_spam = 0
            GROUP BY t.Aj_id
            ORDER BY t.Aj_per DESC, t.Aj_name ASC
        ");
    }

    /**
     * Export เป็น CSV
     */
    private function exportToCSV($data, $type)
    {
        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับ export');
        }

        $filename = $type . '_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = $this->exportDir . $filename;

        $file = fopen($filepath, 'w');

        // เพิ่ม BOM สำหรับ UTF-8
        fwrite($file, "\xEF\xBB\xBF");

        // Header
        $headers = array_keys($data[0]);
        fputcsv($file, $headers);

        // Data
        foreach ($data as $row) {
            fputcsv($file, array_values($row));
        }

        fclose($file);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'records' => count($data)
        ];
    }

    /**
     * Export เป็น Excel (HTML table with Excel mime type)
     */
    private function exportToExcel($data, $type)
    {
        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับ export');
        }

        $filename = $type . '_' . date('Y-m-d_H-i-s') . '.xls';
        $filepath = $this->exportDir . $filename;

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .number { text-align: right; }
    </style>
</head>
<body>
    <h2>รายงาน' . ucfirst($type) . ' - ' . date('d/m/Y H:i') . '</h2>
    <table>
        <thead>
            <tr>';

        // Headers
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }

        $html .= '</tr>
        </thead>
        <tbody>';

        // Data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $class = is_numeric($cell) ? 'number' : '';
                $html .= '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>
    </table>
</body>
</html>';

        file_put_contents($filepath, $html);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'records' => count($data)
        ];
    }

    /**
     * Export สถิติเป็น Excel
     */
    private function exportStatisticsToExcel($data, $dateFrom, $dateTo)
    {
        $filename = 'statistics_' . date('Y-m-d_H-i-s') . '.xls';
        $filepath = $this->exportDir . $filename;

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .number { text-align: right; }
        .section-title { font-size: 18px; font-weight: bold; margin: 20px 0 10px 0; }
    </style>
</head>
<body>
    <h1>รายงานสถิติระบบข้อร้องเรียน</h1>
    <p>ช่วงวันที่: ' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo)) . '</p>
    <p>วันที่สร้างรายงาน: ' . date('d/m/Y H:i') . '</p>';

        // สรุปข้อมูลรวม
        $html .= '<div class="section-title">📊 สรุปข้อมูลรวม</div>';
        $html .= $this->generateTableHTML($data['summary']);

        // สถิติตามประเภท
        if (!empty($data['by_type'])) {
            $html .= '<div class="section-title">📋 สถิติตามประเภทข้อร้องเรียน</div>';
            $html .= $this->generateTableHTML($data['by_type']);
        }

        // สถิติตามเจ้าหน้าที่
        if (!empty($data['by_staff'])) {
            $html .= '<div class="section-title">👨‍🏫 สถิติตามเจ้าหน้าที่</div>';
            $html .= $this->generateTableHTML($data['by_staff']);
        }

        // แนวโน้มรายวัน
        if (!empty($data['daily_trend'])) {
            $html .= '<div class="section-title">📈 แนวโน้มรายวัน</div>';
            $html .= $this->generateTableHTML($data['daily_trend']);
        }

        $html .= '</body></html>';

        file_put_contents($filepath, $html);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'sections' => count($data)
        ];
    }

    /**
     * สร้าง HTML Table
     */
    private function generateTableHTML($data)
    {
        if (empty($data)) return '<p>ไม่มีข้อมูล</p>';

        $html = '<table><thead><tr>';

        // Headers
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }

        $html .= '</tr></thead><tbody>';

        // Data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $class = is_numeric($cell) ? 'number' : '';
                $html .= '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Export เป็น JSON
     */
    private function exportToJSON($data, $type)
    {
        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับ export');
        }

        $filename = $type . '_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = $this->exportDir . $filename;

        $jsonData = [
            'export_info' => [
                'type' => $type,
                'export_date' => date('Y-m-d H:i:s'),
                'total_records' => count($data),
                'generated_by' => SITE_NAME
            ],
            'data' => $data
        ];

        $json = json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($filepath, $json);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'records' => count($data)
        ];
    }

    /**
     * Export เป็น PDF (HTML to PDF)
     */
    private function exportToPDF($data, $type)
    {
        // สำหรับ production ควรใช้ library เช่น TCPDF หรือ mPDF
        // ตอนนี้จะสร้างเป็น HTML ที่สามารถ print เป็น PDF ได้

        if (empty($data)) {
            throw new Exception('ไม่มีข้อมูลสำหรับ export');
        }

        $filename = $type . '_' . date('Y-m-d_H-i-s') . '.html';
        $filepath = $this->exportDir . $filename;

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>รายงาน ' . ucfirst($type) . '</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        
        body { 
            font-family: "Sarabun", "Arial", sans-serif; 
            font-size: 12px; 
            line-height: 1.4;
            margin: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        
        .header h1 { 
            font-size: 20px; 
            margin: 0;
            color: #333;
        }
        
        .header p { 
            margin: 5px 0; 
            color: #666;
        }
        
        table { 
            border-collapse: collapse; 
            width: 100%; 
            margin-bottom: 20px;
            font-size: 11px;
        }
        
        th, td { 
            border: 1px solid #333; 
            padding: 6px; 
            text-align: left;
            vertical-align: top;
        }
        
        th { 
            background-color: #f0f0f0; 
            font-weight: bold;
            text-align: center;
        }
        
        .number { text-align: right; }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
        
        .no-print {
            text-align: center;
            margin: 20px 0;
        }
        
        .print-btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="print-btn" onclick="window.print()">🖨️ พิมพ์รายงาน</button>
        <button class="print-btn" onclick="window.close()" style="background: #6c757d;">✖️ ปิด</button>
    </div>
    
    <div class="header">
        <h1>📋 รายงาน' . ucfirst($type) . '</h1>
        <p>มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</p>
        <p>วันที่พิมพ์: ' . date('d/m/Y H:i') . ' น.</p>
        <p>จำนวนรายการ: ' . number_format(count($data)) . ' รายการ</p>
    </div>
    
    <table>
        <thead>
            <tr>';

        // Headers
        foreach (array_keys($data[0]) as $header) {
            $html .= '<th>' . htmlspecialchars($header) . '</th>';
        }

        $html .= '</tr>
        </thead>
        <tbody>';

        // Data
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $class = is_numeric($cell) ? 'number' : '';
                $html .= '<td class="' . $class . '">' . htmlspecialchars($cell) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>รายงานนี้สร้างโดยระบบข้อร้องเรียนนักศึกษา มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน</p>
        <p>© ' . date('Y') . ' RMUT-Isan. All rights reserved.</p>
    </div>
    
    <script>
        // Auto print when opened in new window
        if (window.location.search.includes("auto_print=1")) {
            window.print();
        }
    </script>
</body>
</html>';

        file_put_contents($filepath, $html);

        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'records' => count($data)
        ];
    }

    /**
     * ดาวน์โหลดไฟล์
     */
    public function downloadFile($filename)
    {
        $filepath = $this->exportDir . $filename;

        if (!file_exists($filepath)) {
            throw new Exception('ไม่พบไฟล์ที่ต้องการดาวน์โหลด');
        }

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $mimeTypes = [
            'csv' => 'text/csv',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            'html' => 'text/html'
        ];

        $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        readfile($filepath);
        exit;
    }

    /**
     * ลบไฟล์เก่า
     */
    public function cleanupOldFiles($daysOld = 7)
    {
        $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
        $deletedCount = 0;

        if (is_dir($this->exportDir)) {
            $files = glob($this->exportDir . '*');

            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $deletedCount++;
                    }
                }
            }
        }

        return $deletedCount;
    }

    /**
     * รายการไฟล์ export ทั้งหมด
     */
    public function getExportedFiles()
    {
        $files = [];

        if (is_dir($this->exportDir)) {
            $fileList = glob($this->exportDir . '*');

            foreach ($fileList as $file) {
                if (is_file($file)) {
                    $files[] = [
                        'filename' => basename($file),
                        'size' => filesize($file),
                        'size_formatted' => $this->formatFileSize(filesize($file)),
                        'created' => filemtime($file),
                        'created_formatted' => date('d/m/Y H:i', filemtime($file)),
                        'extension' => pathinfo($file, PATHINFO_EXTENSION)
                    ];
                }
            }

            // เรียงตามวันที่สร้างล่าสุด
            usort($files, function ($a, $b) {
                return $b['created'] - $a['created'];
            });
        }

        return $files;
    }

    /**
     * จัดรูปแบบขนาดไฟล์
     */
    private function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// ฟังก์ชันช่วยเหลือ
function getDataExportService()
{
    static $service = null;
    if ($service === null) {
        $service = new DataExport();
    }
    return $service;
}

/**
 * Export ข้อมูลแบบง่าย
 */
function quickExport($type, $format = 'csv', $filters = [])
{
    $exportService = getDataExportService();

    switch ($type) {
        case 'complaints':
            return $exportService->exportComplaints($format, $filters);
        case 'students':
            return $exportService->exportStudents($format);
        case 'staff':
            return $exportService->exportStaff($format);
        case 'statistics':
            return $exportService->exportStatistics($format, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        default:
            return false;
    }
}

/**
 * Auto cleanup old export files (เรียกใช้ผ่าน cron job)
 */
function autoCleanupExports($daysOld = 7)
{
    $exportService = getDataExportService();
    return $exportService->cleanupOldFiles($daysOld);
}

// CLI interface สำหรับ cron jobs
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    switch ($argv[1]) {
        case 'cleanup':
            $days = $argv[2] ?? 7;
            $deleted = autoCleanupExports($days);
            echo "Cleaned up {$deleted} old export files.\n";
            break;

        case 'export':
            $type = $argv[2] ?? 'complaints';
            $format = $argv[3] ?? 'csv';
            $result = quickExport($type, $format);
            echo $result ? "Export successful: {$result['filename']}\n" : "Export failed\n";
            break;
    }
}
