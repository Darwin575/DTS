<?php
ob_start();
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
include_once '../server-logic/config/db.php';
require_once __DIR__ . '/../server-logic/config/session_init.php';
require_once __DIR__ . '/../server-logic/config/require_login.php';
$user_id = SessionManager::get('user')['id'] ?? 0;


// --------------------------
// 1. DOCUMENT FIELDS
// --------------------------
$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
$is_pdf = isset($_GET['as_pdf']) && $_GET['as_pdf'];

$sql = "SELECT subject, updated_at, final_status FROM tbl_documents WHERE document_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();
$docData = $result->fetch_assoc();

$subject     = !empty($docData['subject']) ? $docData['subject'] : '-- NONE --';
$updatedAt   = $docData['updated_at'] ?? null;

$date = !empty($updatedAt) ? date('Y-m-d', strtotime($updatedAt)) : '--';
$time = !empty($updatedAt) ? date('h:i A', strtotime($updatedAt)) : '--';

$final_status = !empty($docData['final_status']) ? $docData['final_status'] : '';

// ------------------------------
// 2. SENDER (creator)
// ------------------------------
$sql = "
  SELECT 
    d.creator_name, 
    u.office_name,
    d.esig_path
  FROM tbl_documents d
  JOIN tbl_users    u ON d.user_id = u.user_id
  WHERE d.document_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();

// 2) Fetch the row
$creator = $result->fetch_assoc();
// Fallback helper for signature path
function resolve_esig_path($esig_path)
{
    if (!isset($_SESSION['user_id']) || !$_SESSION['otp_verified']) {
        if (empty($esig_path)) return '';

        // Check if path exists as-is (absolute or relative)
        if (file_exists($esig_path)) return $esig_path;

        // Try to interpret as relative to /uploads/esig/
        $basename = basename($esig_path);
        $relative = __DIR__ . '/../uploads/esig/' . $basename;
        if (file_exists($relative)) return $relative;

        // Try relative to the current dir
        $relative2 = __DIR__ . '/' . $basename;
        if (file_exists($relative2)) return $relative2;

        // If nothing matches, return empty
    } else {
        return '';
    }
    return '';
}

$creator_name   = isset($creator['creator_name']) ? $creator['creator_name'] : '';
$creator_office = isset($creator['office_name'])   ? $creator['office_name']   : '';
$creator_esig   = isset($creator['esig_path'])     ? resolve_esig_path($creator['esig_path']) : '';



// --- Determine the date and time to use for the control number ---
$date_to_format = null; // Initialize
$time_to_format = null; // Initialize

if (!empty($updatedAt)) {
    // If $updatedAt is not empty, use it
    $datetime_obj = new DateTime($updatedAt);
} else {
    // If $updatedAt is empty, use current date and time
    $datetime_obj = new DateTime();
}

// --- Format the date as "monthdayyear" (e.g., 5325 for May 3, 2025) ---
// 'n' for numeric month without leading zeros (1-12)
// 'j' for day of the month without leading zeros (1-31)
// 'y' for two-digit year
$formatted_date_for_control_no = $datetime_obj->format('njy');

// --- Format the time as "HHMM[A/P]" (e.g., 916A, 330P) ---
// 'g' for 12-hour format of an hour without leading zeros (1-12)
// 'i' for minutes with leading zeros (00-59)
// 'A' for uppercase AM or PM
$hour_minutes = $datetime_obj->format('gi'); // e.g., "916", "330"
$ampm_char  = $datetime_obj->format('A'); // e.g., "AM", "PM"

// Convert AM/PM to A/P character
$formatted_time_for_control_no = $hour_minutes . substr($ampm_char, 0, 1);


// --- Construct the control number ---
$control_no = $creator_office . ' - ' . $formatted_date_for_control_no . ' - ' . $formatted_time_for_control_no;

// -----------------------------
// 3. ROUTING TABLE: DYNAMIC ROWS (1 creator + N recipients)
// -----------------------------
$route_sql = "
  SELECT r.route_id, r.recipient_name, r.esig_path, r.comments, r.in_at, r.out_at,
         u.user_id, u.office_name, u.name
  FROM tbl_document_routes r
  INNER JOIN tbl_users u ON r.to_user_id = u.user_id
  WHERE r.document_id = ?
  ORDER BY r.route_id ASC
";
$stmt = $conn->prepare($route_sql);
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();
$routes_db = [];
while ($row = $result->fetch_assoc()) {
    $routes_db[] = $row;
}
$num_routes = count($routes_db);

$rows = [];
// --- 1. CREATOR ROW: always first row ---
if ($num_routes > 0) {
    $first = $routes_db[0];
    $rows[] = [
        'to_office'   => $first['office_name'],
        'from_office' => $creator_office,
        'sender'      => $creator_name,
        'esig_path'    => $creator_esig,
        'in_date'     => '',
        'in_time'     => '',
        'out_date'    => $date,
        'out_time'    => $time,
        'route_id'    => null,
        'user_id'     => null
    ];
}
// --- 2. RECIPIENT ROWS ---
for ($i = 0; $i < $num_routes; $i++) {
    $route = $routes_db[$i];
    $next_office = ($i < $num_routes - 1) ? $routes_db[$i + 1]['office_name'] : '';
    $chosen_esig = !empty($route['esig_path']) ? $route['esig_path'] : $route['esig_path'];
    $final_esig_path = resolve_esig_path($chosen_esig);

    $current_from_office = $route['office_name'];
    $recipient_row = [
        'to_office'   => $next_office,
        'from_office' => $current_from_office,
        'sender'      => $route['recipient_name'] ?: $route['name'],
        'esig_path'   => $final_esig_path,
        'in_date'     => $route['in_at'] ? date('Y-m-d', strtotime($route['in_at'])) : '',
        'in_time'     => $route['in_at'] ? date('h:i A', strtotime($route['in_at'])) : '',
        'out_date'    => $route['out_at'] ? date('Y-m-d', strtotime($route['out_at'])) : '',
        'out_time'    => $route['out_at'] ? date('h:i A', strtotime($route['out_at'])) : '',
        'route_id'    => $route['route_id'],
        'user_id'     => $route['user_id']
    ];

    // If previous row exists and from_office is the same, update previous row with current info
    if (count($rows) > 0 && $rows[count($rows) - 1]['from_office'] === $current_from_office) {
        $rows[count($rows) - 1] = $recipient_row;
    } else {
        $rows[] = $recipient_row;
    }
}
// --- 3. FILLER ROWS if needed (at least 10 rows)
while (count($rows) < 10) {
    $rows[] = array_fill_keys(
        ['to_office', 'from_office', 'sender', 'esig_path', 'in_date', 'in_time', 'out_date', 'out_time', 'route_id', 'user_id'],
        ''
    );
}

// --------------------------
// 4. ACTION REQUEST CHECKS
// --------------------------
// Fetch embed code and QR URL for this document
$embed_code = '';
$qr_url = '';
$getCodesStmt = $conn->prepare("SELECT embed_token, qr_token FROM tbl_documents WHERE document_id = ?");
$getCodesStmt->bind_param("i", $document_id);
$getCodesStmt->execute();
$codesResult = $getCodesStmt->get_result();
if ($codesResult && $row = $codesResult->fetch_assoc()) {
    $embed_code = $row['embed_token'];
    $qr_url     = $row['qr_token'];
}
// fallback: try generated default locations for QR image if empty
if (!$qr_url && file_exists(__DIR__ . "/../uploads/qrcodes/doc_{$document_id}.png")) {
    $qr_url = __DIR__ . "/../uploads/qrcodes/doc_{$document_id}.png";
}
$predefined_labels = [
    'approval'           => 'APPROVAL / ENDORSEMENT',
    'appropriate_action' => 'APPROPRIATE ACTION',
    'comment'            => 'COMMENT / RECOMMENDATION',
    'study'              => 'STUDY / INVESTIGATION',
    'rewrite'            => 'REWRITE / REDRAFT',
    'reply'              => 'REPLY DIRECT TO WRITER',
    'information'        => 'INFORMATION',
    'see_me'             => 'SEE ME / CALL ME',
    'dispatch'           => 'DISPATCH',
    'file'               => 'FILE / REFERENCE',
    'prepare'            => 'PREPARE SPEECH MESSAGE',
    'see_remarks'        => 'SEE REMARKS'
];

// Helper function to normalize action keys for consistent comparison
function normalize_action_key($str)
{
    return strtolower(str_replace([' ', '/', '-', '_'], '', $str));
}

$actions = array_fill_keys(array_keys($predefined_labels), false);
$other_actions = [];
$sql = "SELECT action FROM tbl_document_actions WHERE document_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $document_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $db_action = $row['action'];
    $matched = false;
    $db_normalized = normalize_action_key($db_action);

    // Try to match against both the key and the label
    foreach ($predefined_labels as $key => $label) {
        if (
            $db_normalized === normalize_action_key($key) ||
            $db_normalized === normalize_action_key($label)
        ) {
            $actions[$key] = true;
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        $other_actions[] = $db_action;
    }
}
$actions['others'] = implode(', ', $other_actions);

//---------------------------------------
// 5. PROCESSING COMPLETION (date/time)
//---------------------------------------
$completion_date = $completion_time = '';
$stmt = $conn->prepare("SELECT out_at FROM tbl_document_routes WHERE document_id = ? AND status = 'completed' ORDER BY route_id DESC LIMIT 1");
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $out_at = $row['out_at'];
    $completion_date = $out_at ? date('Y-m-d', strtotime($out_at)) : '';
    $completion_time = $out_at ? date('H:i', strtotime($out_at)) : '';
}

// RIGHT-SIDE REMARKS
$doc_remarks = '';
$stmt = $conn->prepare("SELECT remarks FROM tbl_documents WHERE document_id = ?");
$stmt->bind_param("i", $document_id);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $doc_remarks = !empty($row['remarks']) ? $row['remarks'] : '';
}
// Build list of comments for the right-side, EXCLUDING the last (final) comment
$right_comments = [];
if ($num_routes > 0) {
    foreach ($routes_db as $i => $r) {
        $is_final_row = ($i == $num_routes - 1);
        // Only include comment if not the final one
        if (($r['comments'] ?? '') && !$is_final_row) {
            $right_comments[] = $r['comments'];
        }
    }
}
if (trim($doc_remarks) !== '') $right_comments[] = $doc_remarks;

$final_comment = '';
if ($num_routes > 0 && !empty($routes_db[$num_routes - 1]['comments'])) {
    $final_comment = $routes_db[$num_routes - 1]['comments'];
}

$doc = [
    'control_no' => $control_no,
    'date'       => $date,
    'subject'    => $subject,
];


// PDF Generation - TCPDF using FPDF-like layout
class RoutingPDF extends TCPDF
{
    public function Header() {} // No auto-header
    public function Footer() {} // No auto-footer
}

$pdf = new RoutingPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetMargins(1, 0, 5, true);
$pdf->SetAutoPageBreak(true, 0);
$pdf->AddPage();
$pdf->SetFont('dejavusans', '', 8);
$pageWidth = $pdf->getPageWidth();

$topMargin    = 12;
$leftMargin   = 3;
$rightMargin  = $pdf->GetPageWidth() - 5;

$pdf->Line($leftMargin, $topMargin, $rightMargin, $topMargin);
$pdf->Line($leftMargin, $topMargin, $leftMargin, $pdf->getPageHeight() - 11.5);
$pdf->Line($rightMargin, $topMargin, $rightMargin, $pdf->getPageHeight() - 76);

// Classification Header
$pdf->SetXY(10, 4);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 5, 'DOCUMENT CLASSIFICATION:', 0, 1, 'C');
$underlineLength = 40;
$rightMarginForUnderline = 10;
$endX = $pdf->GetPageWidth() - $rightMarginForUnderline;
$startX = $endX - $underlineLength;
$y = $pdf->GetY() + 1;
$pdf->Line($startX, $y, $endX, $y);


// Main Table Layout
$tableWidth = 200;
$leftWidth  = $tableWidth * 0.7;
$rightWidth = $tableWidth - $leftWidth;
$tableX = 10;
$tableY = 34;

// LEFT COLUMN CONTENT
$leftX = $tableX;
$leftY = $tableY;
$pdf->SetXY($leftX, $leftY - 22);

// Header: Logos & Title
$logoWidth  = 15;
$logoHeight = 15;
$headerHeight = 20;

// Left logo (bjmp.png)
if (file_exists('bjmp.png')) {
    $pdf->Ln(2);
    $pdf->Image('bjmp.png', 6, $pdf->GetY(), 18, 18, 'PNG', '', '', true, 300);
} else {
    $pdf->SetXY($leftX + 2, $pdf->GetY());
    $pdf->Cell($logoWidth, $logoHeight, 'Logo', 1, 0, 'C');
}
// Right logo (bjmp10.jpg)
$rightLogoX = $leftX + $leftWidth - $logoWidth - 5;
if (file_exists('bjmp10.jpg')) {
    $pdf->Image('bjmp10.jpg', $rightLogoX, $pdf->GetY() + 2, 15, 15, 'JPG', '', '', true, 300);
} else {
    $pdf->SetXY($rightLogoX, $pdf->GetY());
    $pdf->Cell($logoWidth, $logoHeight, 'Logo', 1, 0, 'C');
}
// Center title
$pdf->SetFont('dejavusans', 'B', 17);
$pdf->SetXY($leftX, $pdf->GetY());
$pdf->Cell($leftWidth - 9, $headerHeight, 'BJMP ROUTING SHEET', 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 8);
$lineY = $pdf->GetY();
$pdf->Line(3, $lineY, $leftX + $leftWidth, $lineY);

$pdf->SetFont('dejavusans', '', 12);
$pdf->Ln(2);

function printLabelWithUnderline($pdf, $label, $value, $labelWidth = 30, $totalWidth = 80, $leftPadding = 6)
{
    $isSubject = stripos($label, 'SUBJECT') !== false;
    $fixedHeight = $isSubject ? 18 : 6; // in mm
    $baseFontSize = 12; // in points
    $minFontSize = 8;
    $maxLinesFit = $isSubject ? 10 : 2;

    $startX = $pdf->GetX();
    $startY = $pdf->GetY();

    // Convert font sizes to mm (1 pt = 0.352778 mm)
    $baseFontSizeMm = $baseFontSize * 0.352778;
    $labelFontHeight = $isSubject ? $baseFontSizeMm : $fixedHeight;

    // Draw label aligned to the top
    $pdf->SetFont('dejavusans', '', $baseFontSize);
    $pdf->SetXY($leftPadding, $startY);
    $pdf->Cell($labelWidth, $labelFontHeight, $label, 0, 0, 'L');

    $valueX = $leftPadding + $labelWidth + 2;
    $valueWidth = $totalWidth - $labelWidth - 2;

    if ($isSubject) {
        $pdf->SetFont('dejavusans', '', $baseFontSize);
        $currentY = $startY;

        $lineHeight = $baseFontSizeMm * 1.2;
        $maxLines = min($maxLinesFit, floor($fixedHeight / $lineHeight));

        // Split value into lines that fit within the value width
        $words = explode(' ', $value);
        $lines = [];
        $currentLine = '';

        foreach ($words as $word) {
            $testLine = $currentLine ? "$currentLine $word" : $word;
            $testWidth = $pdf->GetStringWidth($testLine);
            if ($testWidth > $valueWidth) {
                if ($currentLine === '') {
                    $split = '';
                    for ($i = 0; $i < mb_strlen($word); $i++) {
                        $char = mb_substr($word, $i, 1);
                        $testChar = $split . $char;
                        if ($pdf->GetStringWidth($testChar) > $valueWidth) {
                            break;
                        }
                        $split = $testChar;
                    }
                    $lines[] = $split;
                    $currentLine = mb_substr($word, $i);
                } else {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                }
            } else {
                $currentLine = $testLine;
            }
        }
        if ($currentLine !== '') {
            $lines[] = $currentLine;
        }

        $lines = array_slice($lines, 0, $maxLines);

        // Print each line and underline
        foreach ($lines as $i => $line) {
            $y = $currentY + ($i * $lineHeight);
            $pdf->SetXY($valueX, $y);
            $pdf->Cell($valueWidth, $lineHeight, $line, 0, 0, 'L');
            $lineWidth = $pdf->GetStringWidth($line);
            $underlineY = $y + $lineHeight - 0.5;
            $pdf->Line($valueX, $underlineY, $valueX + $lineWidth, $underlineY);
        }
    } else {
        $fontSize = $baseFontSize;
        $lines = [];
        do {
            $pdf->SetFont('dejavusans', '', $fontSize);
            $fontSizeMm = $fontSize * 0.352778;
            $lineHeight = $fontSizeMm;
            $words = explode(' ', $value);
            $lines = [];
            $currentLine = '';
            foreach ($words as $word) {
                $testLine = $currentLine ? "$currentLine $word" : $word;
                $testWidth = $pdf->GetStringWidth($testLine);
                if ($testWidth > $valueWidth) {
                    if ($currentLine === '') {
                        $lines[] = $word;
                    } else {
                        $lines[] = $currentLine;
                        $currentLine = $word;
                    }
                } else {
                    $currentLine = $testLine;
                }
            }
            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
            $neededHeight = count($lines) * $lineHeight;
        } while ($neededHeight > $fixedHeight && $fontSize-- >= $minFontSize);

        $lines = array_slice($lines, 0, $maxLinesFit);

        // Print each line with underline using Cell's bottom border
        foreach ($lines as $i => $line) {
            $y = $startY + ($i * $lineHeight);
            $pdf->SetXY($valueX, $y);
            $lineWidth = $pdf->GetStringWidth($line) + 1; // +1mm padding to prevent cutoff
            $pdf->Cell($lineWidth, $lineHeight, $line, 'B', 0, 'L'); // 'B' adds bottom border
        }
    }

    $pdf->SetXY($startX, $startY + $fixedHeight);
}


// Usage (same parameters but fixed height):
printLabelWithUnderline($pdf, 'CONTROL NO.: ', $doc['control_no'], 30, $leftWidth - 4, 4);
printLabelWithUnderline($pdf, 'DATE: ', $doc['date'], 15, $leftWidth - 4, 4);
printLabelWithUnderline($pdf, 'SUBJECT: ', $doc['subject'], 20, $leftWidth - 4, 4);
$pdf->Ln(2);

// Routing Table
$startTableX = 4;
$cellWidths = [
    'to'       => 16,   // FOR / TO
    'from'     => 16,   // FROM
    'sender'   => 30,   // SENDER
    'esig'      => 19,   // SIG
    'in_date'  => 16,
    'in_time'  => 15,
    'out_date' => 16,
    'out_time' => 15,
];
$rowHeight = 7;

// Table header
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetFillColor(173, 216, 230);
$pdf->SetX($startTableX);
$pdf->Cell($cellWidths['to'], $rowHeight * 2, 'FOR / TO', 1, 0, 'C', true);
$pdf->Cell($cellWidths['from'], $rowHeight * 2, 'FROM', 1, 0, 'C', true);
$pdf->Cell($cellWidths['sender'], $rowHeight * 2, 'SENDER', 1, 0, 'C', true);
$pdf->Cell($cellWidths['esig'], $rowHeight * 2, 'SIG', 1, 0, 'C', true);
$pdf->Cell($cellWidths['in_date'] + $cellWidths['in_time'], $rowHeight, 'IN', 1, 0, 'C', true);
$pdf->Cell($cellWidths['out_date'] + $cellWidths['out_time'], $rowHeight, 'OUT', 1, 1, 'C', true);

// Second header row (IN and OUT subheaders)
$pdf->SetX($startTableX + $cellWidths['to'] + $cellWidths['from'] + $cellWidths['sender'] + $cellWidths['esig']);
$pdf->Cell($cellWidths['in_date'], $rowHeight, 'DATE', 1, 0, 'C', true);
$pdf->Cell($cellWidths['in_time'], $rowHeight, 'TIME', 1, 0, 'C', true);
$pdf->Cell($cellWidths['out_date'], $rowHeight, 'DATE', 1, 0, 'C', true);
$pdf->Cell($cellWidths['out_time'], $rowHeight, 'TIME', 1, 1, 'C', true);

// Table body
$pdf->SetFont('dejavusans', '', 9);
foreach ($rows as $route) {
    $pdf->SetX($startTableX);
    $pdf->Cell($cellWidths['to'], $rowHeight, $route['to_office'], 1, 0, 'C');
    $pdf->Cell($cellWidths['from'], $rowHeight, $route['from_office'], 1, 0, 'C');
    $pdf->SetFont('dejavusans', 'B', 5);
    $pdf->Cell($cellWidths['sender'], $rowHeight, $route['sender'], 1, 0, 'C');
    // For SIG: display image if available
    if (!empty($route['esig_path'])) {
        $xPos = $pdf->GetX();
        $yPos = $pdf->GetY();
        // Highlight the signature cell with light yellow background for emphasis
        $pdf->SetFillColor(255, 255, 200);
        $pdf->Cell($cellWidths['esig'], $rowHeight, '', 1, 0, 'C', true);
        $pdf->Image($route['esig_path'], $xPos + 1, $yPos + 1, $cellWidths['esig'] - 2, $rowHeight - 2, '', '', '', true, 300);
        $pdf->SetFillColor(173, 216, 230); // Reset fill color back to original
    } else {
        $pdf->Cell($cellWidths['esig'], $rowHeight, '', 1, 0, 'C');
    }
    $pdf->SetFont('dejavusans', '', 7.5);
    $pdf->Cell($cellWidths['in_date'], $rowHeight, $route['in_date'], 1, 0, 'C');
    $pdf->Cell($cellWidths['in_time'], $rowHeight, $route['in_time'], 1, 0, 'C');
    $pdf->Cell($cellWidths['out_date'], $rowHeight, $route['out_date'], 1, 0, 'C');
    $pdf->Cell($cellWidths['out_time'], $rowHeight, $route['out_time'], 1, 1, 'C');
}
$pdf->Ln(2);

// ACTION REQUEST
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetFillColor(173, 216, 230);
$pdf->SetX(4);
$pdf->Cell(143, 7, 'ACTION REQUEST', 1, 1, 'C', true);
$pdf->SetFont('dejavusans', '', 9);
$checkboxSize = 4;
$col1Keys = ['approval', 'appropriate_action', 'comment', 'study', 'rewrite', 'reply', 'others'];
$col2Keys = ['information', 'see_me', 'dispatch', 'file', 'prepare', 'see_remarks'];

$pdf->Ln(3);
$actionX = 15;
$actionY = $pdf->GetY();
$halfWidth = ($leftWidth - 2) / 2;
$lineHeight = 6;
$checkboxSize = 4;
$pdf->SetFont('dejavusans', '', 10);
// Column 1
$pdf->SetXY($actionX, $actionY);
foreach ($col1Keys as $key) {
    $pdf->SetX($actionX);
    if ($key === 'others') {
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell(18, $lineHeight, 'OTHERS:', 0, 0);
        $pdf->SetFont('dejavusans', 'U', 10);
        $othersText = $actions[$key] ? $actions[$key] : '____________________';
        $pdf->Cell($halfWidth - $checkboxSize - 2, $lineHeight, $othersText, 0, 0);
    } else {
        $pdf->SetFont('ZapfDingbats', '', 10);
        $pdf->Cell($checkboxSize, $checkboxSize, $actions[$key] ? '4' : '', 1, 0);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->Cell($halfWidth - $checkboxSize - 2, $lineHeight, $predefined_labels[$key], 0, 1);
    }
}
// Column 2
$pdf->SetXY($actionX + $halfWidth, $actionY);
foreach ($col2Keys as $key) {
    $pdf->SetX($actionX + $halfWidth);
    $pdf->SetFont('ZapfDingbats', '', 10);
    $pdf->Cell($checkboxSize, $checkboxSize, $actions[$key] ? '4' : '', 1, 0);
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->Cell($halfWidth - $checkboxSize - 2, $lineHeight, $predefined_labels[$key], 0, 1);
}
$pdf->Ln(6);

// FINAL ACTION
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetFillColor(173, 216, 230);
$pdf->SetX(4);
// FINAL ACTION label
$pdf->Cell(143, 7, 'FINAL ACTION', 1, 1, 'C', true);
$pdf->SetFont('dejavusans', '', 9);

// Set up ruled lines
$leftMarginRuled = 6;
$topY = $pdf->GetY();
$lineHeight = 5;
$lineCount = 7;
$boxHeight = ($lineCount - 1) * $lineHeight;
$lineWidthRuled = $leftWidth;

// Draw horizontal lines
for ($i = 0; $i < $lineCount; $i++) {
    $yLine = $topY + ($i * $lineHeight);
    $pdf->Line($leftMarginRuled, $yLine, $leftMarginRuled + $lineWidthRuled, $yLine);
}

// Place the comment inside the ruled area, render HTML
if ($final_comment) {
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTMLCell(
        $lineWidthRuled - 4, // width of text area
        $boxHeight,          // height of text area
        $leftMarginRuled + 2, // X position (with slight padding)
        $topY + 1,           // Y position (just below top line)
        $final_comment,      // actual HTML content from DB
        0,
        1,
        false,
        true,
        'L',
        false
    );
}

// Move the cursor below the box
$pdf->SetY($topY + $boxHeight + 3);


$pdf->Ln(3);

// FINAL STATUS
$cellW = 150 / 3;
$checkboxSize = 5;
$pdf->SetX(18);
$pdf->SetFont('ZapfDingbats', '', 10);
$pdf->Cell($checkboxSize, 4, $final_status === 'approved' ? '4' : '', 1, 0, 'C');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell($cellW - $checkboxSize, 4, 'APPROVED', 0, 0, 'L');
$pdf->SetFont('ZapfDingbats', '', 10);
$pdf->Cell($checkboxSize, 4, $final_status === 'disapproved' ? '4' : '', 1, 0, 'C');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell($cellW - $checkboxSize, 4, 'DISAPPROVED', 0, 0, 'L');
$pdf->SetFont('ZapfDingbats', '', 10);
$pdf->Cell($checkboxSize, 4, $final_status === 'noted' ? '4' : '', 1, 0, 'C');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell($cellW - $checkboxSize, 4, 'NOTED', 0, 1, 'L');
$pdf->Ln(3);

// FINAL SIGNATURE section
$pdf->SetX($startTableX + 1);
$pdf->Cell(80, 6, '', 'B', 1, 'C');
$pdf->SetX($startTableX + 1);
$pdf->Cell(0, 5, 'JAIL CHIEF SUPERINTENDENT', 0, 1, 'L');
$pdf->SetX($startTableX + 1);
$pdf->Cell(0, 5, 'REGIONAL DIRECTOR OF THE JAIL BUREAU', 0, 1, 'L');
$pdf->Ln(5);

// Processing Completion Row
$currentY = $pdf->GetY();
$pdf->SetXY($startTableX, $currentY);
$pdf->Cell(($leftWidth - 4) * 0.5, 6, 'Processing Completion', 0, 0, 'L');
$fullText = 'Date: ' . $completion_date . ' Time: ' . $completion_time;
$fullTextWidth = $pdf->GetStringWidth($fullText);
$rightMarginCompletion = 10;
$rightXCompletion = 147 - $rightMarginCompletion - $fullTextWidth;
$pdf->SetXY($rightXCompletion, $currentY);
$pdf->SetFont('dejavusans', '', 11);
$pdf->Cell($fullTextWidth, 6, $fullText, 0, 1, 'L');
$dateStartX = $rightXCompletion + $pdf->GetStringWidth('Date: ');
$dateWidth  = $pdf->GetStringWidth($completion_date);
$timeStartX = $dateStartX + $dateWidth + $pdf->GetStringWidth(' Time: ');
$timeWidth  = $pdf->GetStringWidth($completion_time);
$underlineY = $currentY + 6.5;
$pdf->Line($dateStartX, $underlineY, $dateStartX + $dateWidth, $underlineY);
$pdf->Line($timeStartX, $underlineY, $timeStartX + $timeWidth, $underlineY);
$lineY = $pdf->GetY() + 2;
$pdf->Line(3, $lineY, 30 + $leftWidth, $lineY);
$leftColumnBottom = $lineY;

// RIGHT COLUMN CONTENT
$rightXPosition = $tableX + 138;
$rightYPosition = $tableY - 22;
$pdf->SetXY($rightXPosition, $rightYPosition);
$paddingTop = 6.7;
$pdf->SetXY($rightXPosition, $rightYPosition + $paddingTop);
$pdf->SetFillColor(250, 250, 250);
$pdf->Rect($rightXPosition, $rightYPosition, $rightWidth, $leftColumnBottom - $tableY + 22, 'DF');
$pdf->SetFont('dejavusans', 'B', 10.5);
$beforeY = $pdf->GetY();
$pdf->MultiCell($rightWidth, 5, "COMMENTS/INSTRUCTIONS/\nREMARKS/NOTATION", 0, 'C');
$pdf->Ln(6);
$afterY = $pdf->GetY();
$pdf->Ln(5);
$pdf->Line($rightXPosition, $afterY, $rightXPosition + $rightWidth, $afterY);
$pdf->Ln(2);
$pdf->SetFont('dejavusans', '', 8);

$right_comments_html = '';
if (!empty($right_comments)) {
    $right_comments_html = implode(
        "\n------------------------------------------------------\n\n",
        array_map(
            function ($comment) {
                return trim((string)$comment);
            },
            $right_comments
        )
    );
}

$pdf->SetXY($rightXPosition + 2, $pdf->GetY());
$pdf->writeHTMLCell($rightWidth - 4, 0, $rightXPosition + 2, $pdf->GetY(), $right_comments_html ? $right_comments_html : '—', 0, 1, false, true, 'L');

// Footer
$pdf->SetY($leftColumnBottom + 0);
$pdf->SetX(4);
$pdf->SetFont('Times', 'BI', 9);
$pdf->Cell(0, 10, '"Changing Lives, Building a Safer Nation"', 0, 0, 'C');

// --- 6. SHOW EMBED CODE + QR CODE IMAGE: bottom right ---
// Decrypt tokens if needed
function decryptTokenLocal($enc)
{
    $key = 'PutYourStrongSecretKeyHere01234'; // Must match DTS_CRYPT_KEY in generate-codes.php
    $method = 'aes-256-cbc';
    $ivlen = openssl_cipher_iv_length($method);
    $enc = base64_decode($enc);
    if ($enc === false) return $enc; // Not base64 encoded
    $iv = substr($enc, 0, $ivlen);
    $ciphertext = substr($enc, $ivlen);
    return openssl_decrypt($ciphertext, $method, $key, 0, $iv);
}

// If values are encrypted, decrypt them
$plain_embed_code = $embed_code;
if ($embed_code && strlen($embed_code) > 16 && strpos($embed_code, '=') !== false) { // crude check for base64
    $plain_embed_code = decryptTokenLocal($embed_code);
}
$plain_qr_token = $qr_url;
if ($qr_url && strlen($qr_url) > 32 && strpos($qr_url, '=') !== false) { // crude check for base64
    $plain_qr_token = decryptTokenLocal($qr_url);
}

// Get local IP for QR code URL
function getLocalIpAddressForPDF()
{
    $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($socket, '8.8.8.8', 53);
    socket_getsockname($socket, $localIp);
    socket_close($socket);
    return $localIp;
}

// Display embed code and QR code if they exist
if ($plain_embed_code && $plain_qr_token) {
    // Position, size
    $rightMargin = 10;
    $bottomMargin = 15;
    $qr_img_size = 20; // mm
    $page_width = $pdf->getPageWidth();
    $page_height = $pdf->getPageHeight();
    $x = $page_width - $rightMargin - $qr_img_size;
    $y = $page_height - $bottomMargin - $qr_img_size;

    // Build QR code payload with local IP
    $localIp = getLocalIpAddressForPDF();
    $baseUrl = "http://$localIp/DTS";
    $qrPayload = $baseUrl . "/index.php?token=" . urlencode($plain_qr_token);

    // Get page dimensions
    $pageWidth = $pdf->getPageWidth();
    $pageHeight = $pdf->getPageHeight();
    $margin = 10; // Margin from edges

    // QR code settings
    $qr_size = 20; // 30x30 QR code
    $qr_x = $pageWidth - $qr_size - $margin; // Right-aligned
    $qr_y = $pageHeight - $qr_size - $margin; // Bottom-aligned

    // 1. Draw "Embed Code:" label (small text above value)
    $pdf->SetFont('courier', '', 8);
    $pdf->SetTextColor(100, 100, 100); // Gray text
    $pdf->SetXY($qr_x - 3, $qr_y - 17); // Position 20 units above QR
    $pdf->MultiCell($qr_size + 5, 0, "Embed Code:", 0, 'C', 0, 0, '', '', true);

    // 2. Draw the embed code value (bold, right above QR)
    $pdf->SetFont('courier', 'B', 11);
    $pdf->SetFillColor(248, 249, 255); // Light background
    $pdf->SetTextColor(33, 40, 54); // Dark text
    $pdf->SetXY($qr_x - 3, $qr_y - 12); // Position 12 units above QR
    $pdf->MultiCell($qr_size + 5, 0, $plain_embed_code, 0, 'C', 1, 0, '', '', true);


    // QR code below
    $pdf->SetXY($x, $y);
    $pdf->write2DBarcode($qrPayload, 'QRCODE,H', $x, $y, $qr_img_size, $qr_img_size);

    // Reset text color to default
    $pdf->SetTextColor(0, 0, 0);
}

// Output the PDF
$pdf->Output('routing_sheet.pdf', 'I');
