<?php
// ajax/export_pdf.php - Export รายงานเป็น PDF (อัปเดต: แสดง filter และรองรับ permission)
define('SECURE_ACCESS', true);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

if (!isLoggedIn()) { die('Unauthorized access'); }

require_once '../../tcpdf/tcpdf.php';

$db   = getDB();
$user = getCurrentUser();

$thaiFont       = 'thsarabunnew';
$userPermission = (int)($_SESSION['permission'] ?? 1);
$userUnitId     = (int)($_SESSION['unit_id']    ?? 0);
$userUnitType   = $_SESSION['unit_type']         ?? '';
$isAdminOrSupervisor = ($userPermission >= 2);

// ===== รับค่า Filter จาก POST =====
$filter_type       = isset($_POST['type_id'])       ? intval($_POST['type_id'])       : 0;
$filter_faculty    = isset($_POST['faculty_id'])    ? intval($_POST['faculty_id'])    : 0;
$filter_major      = isset($_POST['major_id'])      ? intval($_POST['major_id'])      : 0;
$filter_department = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;
$filter_date_from  = isset($_POST['date_from'])     ? $_POST['date_from']             : '';
$filter_date_to    = isset($_POST['date_to'])       ? $_POST['date_to']               : '';
$filter_status     = isset($_POST['status'])        ? $_POST['status']                : '';
$filter_identity   = isset($_POST['identity'])      ? $_POST['identity']              : '';
$filter_evaluation = isset($_POST['evaluation'])    ? $_POST['evaluation']            : '';

// ===== สร้าง WHERE Conditions =====
$whereConditions = [];
$params          = [];

// สิทธิ์ 1: ล็อกตามหน่วยงานตัวเอง
if (!$isAdminOrSupervisor && $userUnitId > 0) {
    if ($userUnitType === 'major') {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $userUnitId;
    } elseif ($userUnitType === 'faculty') {
        $whereConditions[] = "(s.Unit_id = ? OR s.Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?))";
        $params[] = $userUnitId;
        $params[] = $userUnitId;
    } elseif ($userUnitType === 'department') {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $userUnitId;
    }
}

// สิทธิ์ 2-3: ใช้ filter จาก form
if ($isAdminOrSupervisor) {
    if ($filter_department > 0) {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $filter_department;
    } elseif ($filter_major > 0) {
        $whereConditions[] = "s.Unit_id = ?";
        $params[] = $filter_major;
    } elseif ($filter_faculty > 0) {
        $whereConditions[] = "(s.Unit_id = ? OR s.Unit_id IN (SELECT Unit_id FROM organization_unit WHERE Unit_parent_id = ?))";
        $params[] = $filter_faculty;
        $params[] = $filter_faculty;
    }
}

// Filter ร่วม
if ($filter_type > 0) { $whereConditions[] = "r.Type_id = ?"; $params[] = $filter_type; }
if ($filter_status !== '') { $whereConditions[] = "r.Re_status = ?"; $params[] = $filter_status; }
if ($filter_identity !== '') { $whereConditions[] = "r.Re_iden = ?"; $params[] = $filter_identity; }
if (!empty($filter_date_from)) { $whereConditions[] = "r.Re_date >= ?"; $params[] = $filter_date_from; }
if (!empty($filter_date_to))   { $whereConditions[] = "r.Re_date <= ?"; $params[] = $filter_date_to; }
if ($filter_evaluation !== '') {
    if ($filter_evaluation === 'has_rating')    { $whereConditions[] = "e.Eva_score IS NOT NULL AND e.Eva_score > 0"; }
    elseif ($filter_evaluation === 'no_rating') { $whereConditions[] = "(e.Eva_score IS NULL OR e.Eva_score = 0)"; }
    elseif (is_numeric($filter_evaluation))     { $whereConditions[] = "e.Eva_score = ?"; $params[] = intval($filter_evaluation); }
}

$whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

// ===== Query หลัก =====
$reportData = $db->fetchAll("
    SELECT
        r.Re_id, r.Re_title, r.Re_infor, r.Re_status, r.Re_level, r.Re_date, r.Re_iden,
        t.Type_infor, s.Stu_id, s.Stu_name,
        ou.Unit_name AS Student_unit_name,
        parent_ou.Unit_name AS Parent_unit_name,
        e.Eva_score,
        CASE WHEN r.Re_iden = 1 THEN 'ไม่ระบุตัวตน' ELSE s.Stu_name END AS requester_name,
        CASE r.Re_status
            WHEN '0' THEN 'รอยืนยัน' WHEN '1' THEN 'กำลังดำเนินการ'
            WHEN '2' THEN 'รอประเมินผล' WHEN '3' THEN 'เสร็จสิ้น' WHEN '4' THEN 'ปฏิเสธ'
            ELSE 'ไม่ทราบสถานะ' END AS status_text,
        CASE r.Re_level
            WHEN '0' THEN 'รอพิจารณา' WHEN '1' THEN 'ไม่เร่งด่วน' WHEN '2' THEN 'ปกติ'
            WHEN '3' THEN 'เร่งด่วน' WHEN '4' THEN 'เร่งด่วนมาก' WHEN '5' THEN 'วิกฤต/ฉุกเฉิน'
            ELSE 'ไม่ทราบ' END AS level_text
    FROM request r
    LEFT JOIN type t ON r.Type_id = t.Type_id
    LEFT JOIN student s ON r.Stu_id = s.Stu_id
    LEFT JOIN organization_unit ou ON s.Unit_id = ou.Unit_id
    LEFT JOIN organization_unit parent_ou ON ou.Unit_parent_id = parent_ou.Unit_id
    LEFT JOIN evaluation e ON r.Re_id = e.Re_id
    $whereClause
    ORDER BY r.Re_id ASC
", $params);

// ===== สถิติ =====
$totalCount   = count($reportData);
$statusCounts = ['0' => 0, '1' => 0, '2' => 0, '3' => 0, '4' => 0];
$typeCounts   = [];

foreach ($reportData as $row) {
    if (isset($statusCounts[$row['Re_status']])) $statusCounts[$row['Re_status']]++;
    $typeKey = $row['Type_infor'] ?? 'ไม่ระบุ';
    $typeCounts[$typeKey] = ($typeCounts[$typeKey] ?? 0) + 1;
}

// ===== สร้าง Filter Summary สำหรับแสดงใน PDF =====
$filterRows = []; // [['label' => '...', 'value' => '...']]

// ขอบเขตสิทธิ์ 1
if (!$isAdminOrSupervisor && $userUnitId > 0) {
    $unitInfo = $db->fetch("SELECT Unit_name, Unit_type FROM organization_unit WHERE Unit_id = ?", [$userUnitId]);
    if ($unitInfo) {
        $typeMap = ['faculty' => 'คณะ', 'major' => 'สาขา', 'department' => 'แผนก/หน่วยงาน'];
        $filterRows[] = ['label' => 'ขอบเขตข้อมูล', 'value' => ($typeMap[$unitInfo['Unit_type']] ?? '') . ': ' . $unitInfo['Unit_name']];
    }
}

if ($isAdminOrSupervisor) {
    if ($filter_faculty > 0) {
        $info = $db->fetch("SELECT Unit_name FROM organization_unit WHERE Unit_id = ?", [$filter_faculty]);
        $filterRows[] = ['label' => 'คณะ', 'value' => $info['Unit_name'] ?? '-'];
    }
    if ($filter_major > 0) {
        $info = $db->fetch("SELECT Unit_name FROM organization_unit WHERE Unit_id = ?", [$filter_major]);
        $filterRows[] = ['label' => 'สาขา', 'value' => $info['Unit_name'] ?? '-'];
    }
    if ($filter_department > 0) {
        $info = $db->fetch("SELECT Unit_name FROM organization_unit WHERE Unit_id = ?", [$filter_department]);
        $filterRows[] = ['label' => 'แผนก/หน่วยงาน', 'value' => $info['Unit_name'] ?? '-'];
    }
}

if ($filter_type > 0) {
    $info = $db->fetch("SELECT Type_infor FROM type WHERE Type_id = ?", [$filter_type]);
    $filterRows[] = ['label' => 'ประเภทข้อร้องเรียน', 'value' => $info['Type_infor'] ?? '-'];
}

$statusNames = ['0' => 'รอยืนยัน', '1' => 'กำลังดำเนินการ', '2' => 'รอประเมินผล', '3' => 'เสร็จสิ้น', '4' => 'ปฏิเสธ'];
if ($filter_status !== '') {
    $filterRows[] = ['label' => 'สถานะ', 'value' => $statusNames[$filter_status] ?? '-'];
}

$identityNames = ['0' => 'ระบุตัวตน', '1' => 'ไม่ระบุตัวตน'];
if ($filter_identity !== '') {
    $filterRows[] = ['label' => 'การระบุตัวตน', 'value' => $identityNames[$filter_identity] ?? '-'];
}

$evalNames = ['has_rating' => 'มีการประเมินแล้ว', 'no_rating' => 'ยังไม่ได้ประเมิน', '5' => '5 ดาว - ดีมาก', '4' => '4 ดาว - ดี', '3' => '3 ดาว - ปานกลาง', '2' => '2 ดาว - พอใช้', '1' => '1 ดาว - ต้องปรับปรุง'];
if ($filter_evaluation !== '') {
    $filterRows[] = ['label' => 'ความพึงพอใจ', 'value' => $evalNames[$filter_evaluation] ?? '-'];
}

if (!empty($filter_date_from) || !empty($filter_date_to)) {
    $dateVal = '';
    if (!empty($filter_date_from)) $dateVal .= date('d/m/Y', strtotime($filter_date_from));
    $dateVal .= ' — ';
    if (!empty($filter_date_to))  $dateVal .= date('d/m/Y', strtotime($filter_date_to));
    $filterRows[] = ['label' => 'ช่วงวันที่', 'value' => trim($dateVal, ' —')];
}

// ถ้าไม่มี filter ใดเลย
$hasFilter = !empty($filterRows);
if (!$hasFilter) {
    $filterRows[] = ['label' => 'ขอบเขตข้อมูล', 'value' => 'ข้อมูลทั้งหมดในระบบ'];
}

// ===== PDF Class =====
class MYPDF extends TCPDF
{
    protected $reportTitle    = 'รายงานข้อร้องเรียน';
    protected $universityName = 'มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน';
    protected $thaiFont       = 'thsarabunnew';
    protected $generatorName  = '';
    protected $generatorPos   = '';

    public function setReportInfo($title, $name, $pos)
    {
        $this->reportTitle   = $title;
        $this->generatorName = $name;
        $this->generatorPos  = $pos;
    }
    public function setThaiFont($font) { $this->thaiFont = $font; }

    public function Header()
    {
        // แถบสีบน
        $this->SetFillColor(102, 126, 234);
        $this->Rect(0, 0, $this->getPageWidth(), 12, 'F');

        // ชื่อมหาวิทยาลัย
        $this->SetFont($this->thaiFont, 'B', 16);
        $this->SetTextColor(102, 126, 234);
        $this->SetY(14);
        $this->Cell(0, 8, $this->universityName, 0, 1, 'C');

        // ชื่อรายงาน
        $this->SetFont($this->thaiFont, 'B', 14);
        $this->SetTextColor(45, 55, 72);
        $this->Cell(0, 7, $this->reportTitle, 0, 1, 'C');

        // เส้นคั่น gradient-like
        $this->SetDrawColor(102, 126, 234);
        $this->SetLineWidth(0.8);
        $this->Line(15, $this->GetY() + 2, $this->getPageWidth() - 15, $this->GetY() + 2);
        $this->Ln(6);
    }

    public function Footer()
    {
        $this->SetY(-22);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line(15, $this->GetY(), $this->getPageWidth() - 15, $this->GetY());
        $this->Ln(3);
        $this->SetFont($this->thaiFont, '', 9);
        $this->SetTextColor(150, 150, 150);
        $pw = $this->getPageWidth();
        // ซ้าย: ผู้จัดทำ
        $this->Cell($pw * 0.45, 5, 'ผู้จัดทำ: ' . $this->generatorName . ' (' . $this->generatorPos . ')', 0, 0, 'L');
        // กลาง: เลขหน้า
        $this->Cell($pw * 0.20, 5, 'หน้า ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
        // ขวา: วันที่พิมพ์
        $this->Cell($pw * 0.25, 5, 'พิมพ์: ' . date('d/m/Y H:i'), 0, 0, 'R');
    }
}

// ===== สร้าง PDF =====
$pdf = new MYPDF('L', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('ระบบข้อร้องเรียน RMUTI');
$pdf->SetAuthor($user['Aj_name'] ?? 'เจ้าหน้าที่');
$pdf->SetTitle('รายงานข้อร้องเรียน');
$pdf->setReportInfo(
    'รายงานข้อร้องเรียน',
    $user['Aj_name'] ?? 'เจ้าหน้าที่',
    $user['Aj_position'] ?? 'เจ้าหน้าที่'
);
$pdf->setThaiFont($thaiFont);
$pdf->SetMargins(15, 42, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(18);
$pdf->SetAutoPageBreak(true, 28);
$pdf->SetFont($thaiFont, '', 12);
$pdf->AddPage();

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 1: กล่อง "เงื่อนไขการกรองข้อมูล"
// ─────────────────────────────────────────────────────────────
$pdf->SetFont($thaiFont, 'B', 12);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFillColor(102, 126, 234);
$pdf->Cell(0, 9, '  เงื่อนไขการกรองข้อมูล', 0, 1, 'L', true);

$pdf->SetFont($thaiFont, '', 11);
$pdf->SetTextColor(45, 55, 72);

$labelW = 52;
$valueW = $pdf->getPageWidth() - 30 - $labelW - 4;
$rowH   = 7;
$alt    = false;

foreach ($filterRows as $fr) {
    $pdf->SetFillColor($alt ? 245 : 250, $alt ? 247 : 249, $alt ? 252 : 255);
    // label
    $pdf->SetFont($thaiFont, 'B', 11);
    $pdf->SetTextColor(80, 80, 130);
    $pdf->Cell($labelW, $rowH, '  ' . $fr['label'], 'LB', 0, 'L', true);
    // separator
    $pdf->SetFont($thaiFont, '', 11);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(4, $rowH, ':', 'B', 0, 'C', true);
    // value
    $pdf->SetTextColor(45, 55, 72);
    $pdf->Cell($valueW, $rowH, $fr['value'], 'RB', 1, 'L', true);
    $alt = !$alt;
}

$pdf->Ln(6);

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 2: ตารางสรุปสถิติ
// ─────────────────────────────────────────────────────────────
$pdf->SetFont($thaiFont, 'B', 12);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFillColor(102, 126, 234);
$pdf->Cell(0, 9, '  สรุปสถิติ', 0, 1, 'L', true);

$pdf->SetFont($thaiFont, 'B', 11);
$pdf->SetFillColor(235, 238, 255);
$pdf->SetTextColor(60, 60, 120);

$colW = ($pdf->getPageWidth() - 30) / 6;

$statHeaders = ['ทั้งหมด', 'รอยืนยัน', 'กำลังดำเนินการ', 'รอประเมินผล', 'เสร็จสิ้น', 'ปฏิเสธ'];
foreach ($statHeaders as $h) {
    $pdf->Cell($colW, 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont($thaiFont, 'B', 13);
$pdf->SetFillColor(255, 255, 255);

$statColors = [
    [102, 126, 234], // ทั้งหมด - ม่วงน้ำเงิน
    [236, 201, 75],  // รอยืนยัน - เหลือง
    [66, 153, 225],  // ดำเนินการ - น้ำเงิน
    [237, 137, 54],  // รอประเมิน - ส้ม
    [72, 187, 120],  // เสร็จสิ้น - เขียว
    [252, 129, 129], // ปฏิเสธ - แดง
];
$statValues = [$totalCount, $statusCounts['0'], $statusCounts['1'], $statusCounts['2'], $statusCounts['3'], $statusCounts['4']];

foreach ($statValues as $idx => $val) {
    $c = $statColors[$idx];
    $pdf->SetTextColor($c[0], $c[1], $c[2]);
    $pdf->Cell($colW, 10, number_format($val), 1, 0, 'C', false);
}
$pdf->Ln();
$pdf->SetTextColor(45, 55, 72);

$pdf->Ln(5);

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 3: ตารางสถิติตามประเภท
// ─────────────────────────────────────────────────────────────
if (!empty($typeCounts)) {
    $pdf->SetFont($thaiFont, 'B', 12);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFillColor(102, 126, 234);
    $pdf->Cell(0, 9, '  สถิติตามประเภทข้อร้องเรียน', 0, 1, 'L', true);

    $typeW      = $pdf->getPageWidth() - 30;
    $typeNameW  = $typeW * 0.62;
    $typeCountW = $typeW * 0.20;
    $typePctW   = $typeW * 0.18;

    $pdf->SetFont($thaiFont, 'B', 11);
    $pdf->SetFillColor(235, 238, 255);
    $pdf->SetTextColor(60, 60, 120);
    $pdf->Cell($typeNameW,  8, 'ประเภท',  1, 0, 'C', true);
    $pdf->Cell($typeCountW, 8, 'จำนวน',   1, 0, 'C', true);
    $pdf->Cell($typePctW,   8, 'ร้อยละ',  1, 1, 'C', true);

    $pdf->SetFont($thaiFont, '', 11);
    $pdf->SetTextColor(45, 55, 72);
    $alt = false;
    foreach ($typeCounts as $type => $cnt) {
        $pct = $totalCount > 0 ? round(($cnt / $totalCount) * 100, 1) : 0;
        $pdf->SetFillColor($alt ? 245 : 255, $alt ? 247 : 255, $alt ? 250 : 255);
        $pdf->Cell($typeNameW,  7, '  ' . $type,         1, 0, 'L', true);
        $pdf->Cell($typeCountW, 7, number_format($cnt),  1, 0, 'C', true);
        $pdf->Cell($typePctW,   7, $pct . '%',           1, 1, 'C', true);
        $alt = !$alt;
    }
    $pdf->Ln(5);
}

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 4: ตารางรายละเอียด (หน้าใหม่)
// ─────────────────────────────────────────────────────────────
$pdf->AddPage();

$pdf->SetFont($thaiFont, 'B', 12);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFillColor(102, 126, 234);
$pdf->Cell(0, 9, '  รายละเอียดข้อร้องเรียน (' . number_format($totalCount) . ' รายการ)', 0, 1, 'L', true);
$pdf->Ln(2);

if ($totalCount > 0) {
    $pageW = $pdf->getPageWidth() - 30;
    // กำหนดความกว้างคอลัมน์
    $colNo      = 8;
    $colId      = 14;
    $colTitle   = round($pageW * 0.22);
    $colType    = round($pageW * 0.14);
    $colWho     = round($pageW * 0.14);
    $colUnit    = round($pageW * 0.16);
    $colDate    = 22;
    $colLevel   = round($pageW * 0.09);
    $colStatus  = $pageW - $colNo - $colId - $colTitle - $colType - $colWho - $colUnit - $colDate - $colLevel;

    // Helper: พิมพ์ header ตาราง
    $printTableHeader = function() use ($pdf, $thaiFont, $colNo, $colId, $colTitle, $colType, $colWho, $colUnit, $colDate, $colLevel, $colStatus) {
        $pdf->SetFont($thaiFont, 'B', 9);
        $pdf->SetFillColor(102, 126, 234);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($colNo,     7, '#',          1, 0, 'C', true);
        $pdf->Cell($colId,     7, 'รหัส',       1, 0, 'C', true);
        $pdf->Cell($colTitle,  7, 'หัวข้อ',     1, 0, 'C', true);
        $pdf->Cell($colType,   7, 'ประเภท',     1, 0, 'C', true);
        $pdf->Cell($colWho,    7, 'ผู้ร้องเรียน', 1, 0, 'C', true);
        $pdf->Cell($colUnit,   7, 'หน่วยงาน',   1, 0, 'C', true);
        $pdf->Cell($colDate,   7, 'วันที่',     1, 0, 'C', true);
        $pdf->Cell($colLevel,  7, 'ระดับ',      1, 0, 'C', true);
        $pdf->Cell($colStatus, 7, 'สถานะ',      1, 1, 'C', true);
    };

    $printTableHeader();

    $pdf->SetFont($thaiFont, '', 9);
    $pdf->SetTextColor(45, 55, 72);
    $alt = false;
    $i   = 1;

    foreach ($reportData as $row) {
        // ตรวจสอบขึ้นหน้าใหม่
        if ($pdf->GetY() > ($pdf->getPageHeight() - 35)) {
            $pdf->AddPage();
            $printTableHeader();
            $pdf->SetFont($thaiFont, '', 9);
            $pdf->SetTextColor(45, 55, 72);
        }

        $pdf->SetFillColor($alt ? 245 : 255, $alt ? 247 : 255, $alt ? 250 : 255);

        $title     = mb_substr($row['Re_title'] ?? $row['Re_infor'] ?? '-', 0, 30);
        $typeName  = mb_substr($row['Type_infor'] ?? '-', 0, 18);
        $who       = mb_substr($row['requester_name'] ?? '-', 0, 18);
        $unit      = '';
        if ($row['Parent_unit_name']) {
            $unit = mb_substr($row['Parent_unit_name'], 0, 8) . '/' . mb_substr($row['Student_unit_name'] ?? '', 0, 12);
        } else {
            $unit = mb_substr($row['Student_unit_name'] ?? '-', 0, 20);
        }

        $pdf->Cell($colNo,     6, $i++,                                         1, 0, 'C', true);
        $pdf->Cell($colId,     6, $row['Re_id'],                                1, 0, 'C', true);
        $pdf->Cell($colTitle,  6, $title,                                       1, 0, 'L', true);
        $pdf->Cell($colType,   6, $typeName,                                    1, 0, 'L', true);
        $pdf->Cell($colWho,    6, $who,                                         1, 0, 'L', true);
        $pdf->Cell($colUnit,   6, $unit,                                        1, 0, 'L', true);
        $pdf->Cell($colDate,   6, date('d/m/y', strtotime($row['Re_date'])),    1, 0, 'C', true);
        $pdf->Cell($colLevel,  6, $row['level_text'],                           1, 0, 'C', true);
        $pdf->Cell($colStatus, 6, $row['status_text'],                          1, 1, 'C', true);
        $alt = !$alt;
    }
} else {
    $pdf->SetFont($thaiFont, '', 12);
    $pdf->SetTextColor(150, 150, 150);
    $pdf->Cell(0, 20, 'ไม่พบข้อมูลตามเงื่อนไขที่กำหนด', 0, 1, 'C');
}

// ─────────────────────────────────────────────────────────────
// ลายเซ็น
// ─────────────────────────────────────────────────────────────
$signY = $pdf->GetY();
if ($signY > $pdf->getPageHeight() - 55) { $pdf->AddPage(); }
$pdf->Ln(12);

$pw = $pdf->getPageWidth() - 30;
$pdf->SetFont($thaiFont, '', 11);
$pdf->SetTextColor(45, 55, 72);

// กล่องลายเซ็น
$boxX = 15 + $pw * 0.55;
$boxW = $pw * 0.45;

$pdf->SetXY($boxX, $pdf->GetY());
$pdf->SetDrawColor(180, 180, 200);
$pdf->SetLineWidth(0.3);
$pdf->Rect($boxX, $pdf->GetY(), $boxW, 38, 'D');

$pdf->SetXY($boxX, $pdf->GetY() + 3);
$pdf->Cell($boxW, 7, 'ลงชื่อ ...................................', 0, 1, 'C');
$pdf->SetX($boxX);
$pdf->Cell($boxW, 7, '(' . ($user['Aj_name'] ?? 'ผู้จัดทำรายงาน') . ')', 0, 1, 'C');
$pdf->SetX($boxX);
$pdf->Cell($boxW, 7, 'ตำแหน่ง: ' . ($user['Aj_position'] ?? 'เจ้าหน้าที่'), 0, 1, 'C');
$pdf->SetX($boxX);
$pdf->SetFont($thaiFont, '', 10);
$pdf->SetTextColor(120, 120, 120);
$pdf->Cell($boxW, 7, 'วันที่พิมพ์: ' . date('d/m/Y H:i'), 0, 1, 'C');

// Output PDF
$filename = 'report_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'I');