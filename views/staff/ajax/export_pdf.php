<?php
// ajax/export_pdf.php - Export รายงานเป็น PDF (อัปเดต: สมบูรณ์ 100% Dynamic Supervisor + Layout มาตรฐานราชการ)
define('SECURE_ACCESS', true);

require_once '../../../config/config.php';
require_once '../../../config/database.php';
require_once '../../../models/Auth.php';
require_once '../../../core/functions.php';

if (!isLoggedIn()) {
    die('Unauthorized access');
}

require_once '../../../tcpdf/tcpdf.php';

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

if ($filter_type > 0) {
    $whereConditions[] = "r.Type_id = ?";
    $params[] = $filter_type;
}
if ($filter_status !== '') {
    $whereConditions[] = "r.Re_status = ?";
    $params[] = $filter_status;
}
if ($filter_identity !== '') {
    $whereConditions[] = "r.Re_iden = ?";
    $params[] = $filter_identity;
}
if (!empty($filter_date_from)) {
    $whereConditions[] = "r.Re_date >= ?";
    $params[] = $filter_date_from;
}
if (!empty($filter_date_to)) {
    $whereConditions[] = "r.Re_date <= ?";
    $params[] = $filter_date_to;
}
if ($filter_evaluation !== '') {
    if ($filter_evaluation === 'has_rating') {
        $whereConditions[] = "e.Eva_score IS NOT NULL AND e.Eva_score > 0";
    } elseif ($filter_evaluation === 'no_rating') {
        $whereConditions[] = "(e.Eva_score IS NULL OR e.Eva_score = 0)";
    } elseif (is_numeric($filter_evaluation)) {
        $whereConditions[] = "e.Eva_score = ?";
        $params[] = intval($filter_evaluation);
    }
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

// ===== ดึงข้อมูลสาขา และ ค้นหา "ชื่อหัวหน้าสาขา" อัตโนมัติ =====
$unitName = '';
$dynamicHeadPosition = 'หัวหน้าสาขาวิชา........................................';
$dynamicHeadName = '........................................................'; // ค่าเริ่มต้นถ้าไม่มีหัวหน้า

if ($userUnitId > 0) {
    // 1. หาชื่อสาขา
    $unitInfo = $db->fetch("SELECT Unit_name FROM organization_unit WHERE Unit_id = ?", [$userUnitId]);
    if ($unitInfo && !empty($unitInfo['Unit_name'])) {
        $unitName = $unitInfo['Unit_name'];
        $dynamicHeadPosition = 'หัวหน้าสาขาวิชา' . $unitName;
    }

    // 2. ค้นหาชื่อคนที่เป็น "หัวหน้า" ในสาขานั้นจากตาราง teacher
    $headQuery = "SELECT Aj_name FROM teacher WHERE Unit_id = ? AND Aj_position LIKE '%หัวหน้า%' AND Aj_status = 1 LIMIT 1";
    $headInfo = $db->fetch($headQuery, [$userUnitId]);

    if ($headInfo && !empty($headInfo['Aj_name'])) {
        $dynamicHeadName = $headInfo['Aj_name'];
    }
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
    public function setThaiFont($font)
    {
        $this->thaiFont = $font;
    }

    public function Header()
    {
        // ว่างไว้เพื่อไม่ให้สร้าง Header อัตโนมัติทุกหน้า
    }

    public function Footer()
    {
        $this->SetY(-18);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.3);

        // หักลบขอบซ้าย 15 และขวา 15 ออกจากความกว้างเต็ม (เหลือ 180 มม.) เพื่อแก้ปัญหา Footer ตกขอบ
        $contentWidth = $this->getPageWidth() - 30;

        $this->Line(15, $this->GetY(), 15 + $contentWidth, $this->GetY());
        $this->Ln(2);
        $this->SetFont($this->thaiFont, '', 11);
        $this->SetTextColor(80, 80, 80);

        $this->SetX(15);
        $this->Cell($contentWidth * 0.45, 5, 'ระบบข้อร้องเรียน มทร.อีสาน  |  เอกสารนี้ออกโดยระบบ', 0, 0, 'L');
        $this->Cell($contentWidth * 0.20, 5, 'หน้า ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
        $this->Cell($contentWidth * 0.35, 5, 'พิมพ์เมื่อ: ' . date('d/m/Y H:i น.'), 0, 0, 'R');
    }
}

// ===== สร้าง PDF =====
$pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);

$pdf->SetCreator('ระบบข้อร้องเรียน RMUTI');
$pdf->SetAuthor($user['Aj_name'] ?? 'เจ้าหน้าที่');
$pdf->SetTitle('รายงานข้อร้องเรียน');
$pdf->setReportInfo(
    'รายงานข้อร้องเรียน',
    $user['Aj_name'] ?? 'เจ้าหน้าที่',
    $user['Aj_position'] ?? 'เจ้าหน้าที่'
);
$pdf->setThaiFont($thaiFont);
$pdf->setPrintHeader(false);
$pdf->SetMargins(15, 15, 15);
$pdf->SetHeaderMargin(5);
$pdf->SetFooterMargin(18);
$pdf->SetAutoPageBreak(true, 28);
$pdf->SetFont($thaiFont, '', 16);
$pdf->AddPage();

// ─────────────────────────────────────────────────────────────
// ส่วนหัวบันทึกข้อความมาตรฐานราชการ
// ─────────────────────────────────────────────────────────────
$logoPath = __DIR__ . '/../../../assets/img/rmuti_logo.png';
$thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$thaiDate = date('j') . ' ' . $thaiMonths[date('n')] . ' ' . (date('Y') + 543);

$rowH  = 9;
$lx    = 15;

// 1. โลโก้ และ คำว่า "บันทึกข้อความ"
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, $lx, 15, 0, 16, 'PNG');
}
$pdf->SetXY(15, 18);
$pdf->SetFont($thaiFont, 'B', 29);
$pdf->Cell(180, 10, 'บันทึกข้อความ', 0, 1, 'C');

// 2. แถว "ส่วนราชการ" (เพิ่มวิทยาเขตขอนแก่น)
$y1 = 33;
$pdf->SetXY($lx, $y1);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(26, $rowH, 'ส่วนราชการ', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(154, $rowH, 'มหาวิทยาลัยเทคโนโลยีราชมงคลอีสาน วิทยาเขตขอนแก่น', 0, 1, 'L');

// 3. แถว "ที่" และ "วันที่"
$y2 = $y1 + $rowH;
$pdf->SetXY($lx, $y2);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(8, $rowH, 'ที่', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(100, $rowH, '....................................................................', 0, 0, 'L');

$pdf->SetXY(125, $y2);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(14, $rowH, 'วันที่', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(40, $rowH, $thaiDate, 0, 1, 'L');

// 4. แถว "เรื่อง"
$y3 = $y2 + $rowH;
$pdf->SetXY($lx, $y3);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(14, $rowH, 'เรื่อง', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(166, $rowH, 'รายงานข้อร้องเรียน', 0, 1, 'L');

// 5. แถว "เรียน" (ดึงชื่อตำแหน่งจากระบบ Dynamic)
$y4 = $y3 + $rowH;
$pdf->SetXY($lx, $y4);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(14, $rowH, 'เรียน', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(166, $rowH, $dynamicHeadPosition, 0, 1, 'L');

// 6. แถว "สิ่งที่ส่งมาด้วย"
$y5 = $y4 + $rowH;
$pdf->SetXY($lx, $y5);
$pdf->SetFont($thaiFont, 'B', 20);
$pdf->Cell(34, $rowH, 'สิ่งที่ส่งมาด้วย', 0, 0, 'L');
$pdf->SetFont($thaiFont, '', 16);
$pdf->Cell(146, $rowH, 'รายงานสถิติและรายละเอียดข้อร้องเรียน จำนวน ' . number_format($totalCount) . ' รายการ', 0, 1, 'L');

// ตั้งค่าตำแหน่งย่อหน้าแรกให้เว้นระยะลงมาสวยงาม (ลบเส้นคั่นทิ้งเพื่อให้เป็นทางการ 100%)
$pdf->SetY($y5 + $rowH + 4);

// ─────────────────────────────────────────────────────────────
// ย่อหน้าเกริ่นนำ
// ─────────────────────────────────────────────────────────────
$pdf->SetFont($thaiFont, '', 16);
$pdf->SetTextColor(0, 0, 0);

$statusNames = ['0' => 'รอยืนยัน', '1' => 'กำลังดำเนินการ', '2' => 'รอประเมินผล', '3' => 'เสร็จสิ้น', '4' => 'ปฏิเสธ'];
$identityNames = ['0' => 'ระบุตัวตน', '1' => 'ไม่ระบุตัวตน'];
$evalNames = ['has_rating' => 'มีการประเมินแล้ว', 'no_rating' => 'ยังไม่ได้ประเมิน', '5' => '5 ดาว', '4' => '4 ดาว', '3' => '3 ดาว', '2' => '2 ดาว', '1' => '1 ดาว'];

$introText = "รายงานฉบับนี้จัดทำขึ้นเพื่อสรุปข้อมูลสถิติและรายละเอียดข้อร้องเรียน";
$filters = [];
if (!empty($filter_date_from) || !empty($filter_date_to)) {
    $dateVal = '';
    if (!empty($filter_date_from)) $dateVal .= date('d/m/Y', strtotime($filter_date_from));
    $dateVal .= ' - ';
    if (!empty($filter_date_to))  $dateVal .= date('d/m/Y', strtotime($filter_date_to));
    $filters[] = "ในช่วงวันที่ " . trim($dateVal, ' - ');
}
if ($filter_status !== '') {
    $filters[] = "สถานะ '" . ($statusNames[$filter_status] ?? '') . "'";
}
if ($filter_identity !== '') {
    $filters[] = "การระบุตัวตน '" . ($identityNames[$filter_identity] ?? '') . "'";
}
if ($filter_evaluation !== '') {
    $filters[] = "ผลการประเมิน '" . ($evalNames[$filter_evaluation] ?? '') . "'";
}

if (!empty($filters)) {
    $introText .= " โดยมีเงื่อนไขการกรองข้อมูลดังนี้: " . implode(', ', $filters) . " ";
} else {
    $introText .= " จากข้อมูลทั้งหมดในระบบ ";
}
$introText .= "ซึ่งมีรายละเอียดและผลการดำเนินงานดังตารางสถิติต่อไปนี้";

$pdf->MultiCell(0, 8, "               " . $introText, 0, 'J', false, 1);
$pdf->Ln(2);

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 2: ตารางสรุปสถิติ
// ─────────────────────────────────────────────────────────────
$pdf->SetFont($thaiFont, 'B', 16);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 9, '  สรุปสถิติ', 1, 1, 'L', true);

$pdf->SetFont($thaiFont, 'B', 16);
$pdf->SetFillColor(245, 245, 245);

$colW = ($pdf->getPageWidth() - 30) / 6;

$statHeaders = ['ทั้งหมด', 'รอยืนยัน', 'กำลังดำเนินการ', 'รอประเมินผล', 'เสร็จสิ้น', 'ปฏิเสธ'];
foreach ($statHeaders as $h) {
    $pdf->Cell($colW, 8, $h, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFont($thaiFont, 'B', 16);
$statValues = [$totalCount, $statusCounts['0'], $statusCounts['1'], $statusCounts['2'], $statusCounts['3'], $statusCounts['4']];

foreach ($statValues as $val) {
    $pdf->Cell($colW, 10, number_format($val), 1, 0, 'C', false);
}
$pdf->Ln();

$pdf->Ln(5);

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 3: ตารางสถิติตามประเภท
// ─────────────────────────────────────────────────────────────
if (!empty($typeCounts)) {
    $pdf->SetFont($thaiFont, 'B', 16);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(0, 9, '  สถิติตามประเภทข้อร้องเรียน', 1, 1, 'L', true);

    $typeW      = $pdf->getPageWidth() - 30;
    $typeNameW  = $typeW * 0.62;
    $typeCountW = $typeW * 0.20;
    $typePctW   = $typeW * 0.18;

    $pdf->SetFont($thaiFont, 'B', 16);
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Cell($typeNameW,  8, 'ประเภท',  1, 0, 'C', true);
    $pdf->Cell($typeCountW, 8, 'จำนวน',   1, 0, 'C', true);
    $pdf->Cell($typePctW,   8, 'ร้อยละ',  1, 1, 'C', true);

    $pdf->SetFont($thaiFont, '', 16);
    foreach ($typeCounts as $type => $cnt) {
        $pct = $totalCount > 0 ? round(($cnt / $totalCount) * 100, 1) : 0;
        $pdf->Cell($typeNameW,  7, '  ' . $type,         1, 0, 'L', false);
        $pdf->Cell($typeCountW, 7, number_format($cnt),  1, 0, 'C', false);
        $pdf->Cell($typePctW,   7, $pct . '%',           1, 1, 'C', false);
    }
    $pdf->Ln(5);
}

// ─────────────────────────────────────────────────────────────
// ส่วนที่ 4: ตารางรายละเอียด (หน้าใหม่)
// ─────────────────────────────────────────────────────────────
$pdf->AddPage();

$pdf->SetFont($thaiFont, 'B', 16);
$pdf->SetFillColor(230, 230, 230);
$pdf->Cell(0, 9, '  รายละเอียดข้อร้องเรียน (' . number_format($totalCount) . ' รายการ)', 1, 1, 'L', true);
$pdf->Ln(2);

if ($totalCount > 0) {
    $pageW = $pdf->getPageWidth() - 30;
    $colNo      = 8;
    $colId      = 12;
    $colDate    = 18;
    $colLevel   = 14;
    $colStatus  = 18;

    $remaining  = $pageW - ($colNo + $colId + $colDate + $colLevel + $colStatus);
    $colTitle   = round($remaining * 0.35);
    $colType    = round($remaining * 0.18);
    $colWho     = round($remaining * 0.22);
    $colUnit    = $remaining - $colTitle - $colType - $colWho;

    $printTableHeader = function () use ($pdf, $thaiFont, $colNo, $colId, $colTitle, $colType, $colWho, $colUnit, $colDate, $colLevel, $colStatus) {
        $pdf->SetFont($thaiFont, 'B', 14);
        $pdf->SetFillColor(245, 245, 245);
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
    $i   = 1;

    foreach ($reportData as $row) {
        $title     = $row['Re_title'] ?? $row['Re_infor'] ?? '-';
        $typeName  = $row['Type_infor'] ?? '-';
        $who       = $row['requester_name'] ?? '-';
        $unit      = '';
        if (!empty($row['Parent_unit_name'])) {
            $unit = $row['Parent_unit_name'] . '/' . ($row['Student_unit_name'] ?? '');
        } else {
            $unit = $row['Student_unit_name'] ?? '-';
        }

        $dateStr = date('d/m/y', strtotime($row['Re_date']));
        $levelStr = $row['level_text'];
        $statusStr = $row['status_text'];

        $pdf->SetFont($thaiFont, '', 14);

        $hTitle  = $pdf->getStringHeight($colTitle, $title);
        $hType   = $pdf->getStringHeight($colType, $typeName);
        $hWho    = $pdf->getStringHeight($colWho, $who);
        $hUnit   = $pdf->getStringHeight($colUnit, $unit);
        $hStatus = $pdf->getStringHeight($colStatus, $statusStr);

        $h = max($hTitle, $hType, $hWho, $hUnit, $hStatus);
        if ($h < 8) $h = 8;

        if ($pdf->GetY() + $h > ($pdf->getPageHeight() - 30)) {
            $pdf->AddPage();
            $printTableHeader();
            $pdf->SetFont($thaiFont, '', 14);
        }

        $pdf->MultiCell($colNo,     $h, $i++,      1, 'C', false, 0);
        $pdf->MultiCell($colId,     $h, $row['Re_id'], 1, 'C', false, 0);
        $pdf->MultiCell($colTitle,  $h, $title,    1, 'L', false, 0);
        $pdf->MultiCell($colType,   $h, $typeName, 1, 'L', false, 0);
        $pdf->MultiCell($colWho,    $h, $who,      1, 'L', false, 0);
        $pdf->MultiCell($colUnit,   $h, $unit,     1, 'L', false, 0);
        $pdf->MultiCell($colDate,   $h, $dateStr,  1, 'C', false, 0);
        $pdf->MultiCell($colLevel,  $h, $levelStr, 1, 'C', false, 0);
        $pdf->MultiCell($colStatus, $h, $statusStr, 1, 'C', false, 1);
    }
} else {
    $pdf->SetFont($thaiFont, '', 16);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 20, 'ไม่พบข้อมูลตามเงื่อนไขที่กำหนด', 0, 1, 'C');
}

// ─────────────────────────────────────────────────────────────
// ส่วนลงนาม (แบบฟอร์มราชการมาตรฐาน - ดึงชื่อหัวหน้าจากฐานข้อมูล)
// ─────────────────────────────────────────────────────────────
$pdf->Ln(12);
$signY = $pdf->GetY();
if ($signY > $pdf->getPageHeight() - 75) {
    $pdf->AddPage();
    $pdf->Ln(10);
}

$pw   = $pdf->getPageWidth() - 30; // ~180mm
$pdf->SetFont($thaiFont, '', 16);
$pdf->SetTextColor(0, 0, 0);

// แบ่งเป็น 2 คอลัมน์
$colSig  = $pw * 0.48;
$colGap  = $pw * 0.04;
$leftX   = 15;
$rightX  = 15 + $colSig + $colGap;

// ---- ผู้จัดทำรายงาน (ซ้าย) ----
$pdf->SetX($leftX);
$pdf->Cell($colSig, 7, '(ลงชื่อ) ............................................', 0, 1, 'C');
$pdf->SetX($leftX);
$pdf->Cell($colSig, 7, '( ' . ($user['Aj_name'] ?? '................................................') . ' )', 0, 1, 'C');
$pdf->SetX($leftX);
$pdf->Cell($colSig, 7, 'ตำแหน่ง ' . ($user['Aj_position'] ?? 'เจ้าหน้าที่'), 0, 1, 'C');
$pdf->SetX($leftX);
$pdf->Cell($colSig, 7, 'วันที่ ' . $thaiDate, 0, 1, 'C');

// ---- ผู้บังคับบัญชา / หัวหน้าสาขา (ขวา) ----
$pdf->SetXY($rightX, $signY);
$pdf->Cell($colSig, 7, '(ลงชื่อ) ............................................', 0, 1, 'C');
$pdf->SetX($rightX);
// ดึงชื่อหัวหน้าจาก Database ที่ค้นหาไว้ ถ้าไม่มีจะเป็นจุดไข่ปลา
$pdf->Cell($colSig, 7, '( ' . $dynamicHeadName . ' )', 0, 1, 'C');
$pdf->SetX($rightX);
$pdf->SetFont($thaiFont, '', 14);
$pdf->Cell($colSig, 7, 'ตำแหน่ง ' . $dynamicHeadPosition, 0, 1, 'C');
$pdf->SetFont($thaiFont, '', 16);
$pdf->SetX($rightX);
$pdf->Cell($colSig, 7, 'วันที่ ........./........./.........', 0, 1, 'C');

// Output PDF
$filename = 'report_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'I');
