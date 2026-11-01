<?php
// ✨ FIX: Unlimited Memory at No Timeout para iwas HTTP 500 Crash
ob_start();
error_reporting(0); 
ini_set('display_errors', 0); 
ini_set('memory_limit', '-1'); 
set_time_limit(0); 

session_start();
require '../includes/db_connect.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'])) {
    die("Unauthorized access.");
}

// Kunin ang mga parameters mula sa Modal
$status = $_POST['status'] ?? 'Approved';
$scholarship_id = $_POST['scholarship_id'] ?? 'all';
$fields = $_POST['fields'] ?? []; 
$custom_fields_check = $_POST['custom_fields_check'] ?? [];
$period = $_POST['period'] ?? 'all';

$templatePath = '../templates/annex1_tarlac_agricultural_university (6).xlsm'; 

if (!file_exists($templatePath)) {
    die("Error: Excel template not found at " . $templatePath);
}

// ✨ Ihanda ang Query base sa pinili sa dropdown
$params = [];
$filters = [];

// Status Filter
switch ($status) {
    case 'All_Applicants':
        $filters[] = "a.Status IN ('Pending', 'Submitted', 'Under Review', 'Shortlisted', 'Approved', 'Rejected')";
        break;
    case 'Pending':
        $filters[] = "a.Status IN ('Pending', 'Submitted', 'Under Review')";
        break;
    case 'Shortlisted':
        $filters[] = "a.Status = 'Shortlisted'";
        break;
    case 'Rejected':
        $filters[] = "a.Status = 'Rejected'";
        break;
    case 'Approved':
    default:
        $filters[] = "a.Status = 'Approved'";
        break;
}

// ✨ CHED's Annex 1 is the Tulong Dunong (TDP / TES) template: it can't be used as the report for other scholarships.
// Other scholarships use System Reports, with the standard or their sponsor's own format.
require_once '../includes/reports.php';
if ($scholarship_id !== 'all' && !empty($scholarship_id)) {
    $name_stmt = $pdo->prepare("SELECT Name FROM scholarship WHERE ScholarshipID = ?");
    $name_stmt->execute([$scholarship_id]);
    $chosen_name = (string) $name_stmt->fetchColumn();
    if (!isTulongDunong($chosen_name)) {
        ob_end_clean();
        exit("CHED's Annex 1 template is only for Tulong Dunong / TES scholarships, so it can't be used for \"" . htmlspecialchars($chosen_name) . "\".\n"
            . "Use System Reports instead: it follows the sponsor's own report format and exports to PDF or Excel.");
    }
    $filters[] = "a.ScholarshipID = :sch_id";
    $params[':sch_id'] = $scholarship_id;
}
// "All scholarships" means all Tulong Dunong / TES scholarships
$filters[] = "(s.Name LIKE '%Tulong Dunong%' OR s.Name LIKE '%Tertiary Educational Subsidy%' OR s.Name LIKE 'TES %' OR s.Name LIKE '% TES' OR s.Name LIKE '%(TES)%')";
// Providers only export their own scholarships
if ($_SESSION['role'] === 'External_Admin') {
    $filters[] = "s.CreatedBy = :me";
    $params[':me'] = $_SESSION['user_id'];
}

if ($period === 'monthly') {
    $filters[] = "MONTH(a.DateSubmitted) = MONTH(CURRENT_DATE()) AND YEAR(a.DateSubmitted) = YEAR(CURRENT_DATE())";
} elseif ($period === 'quarterly') {
    $filters[] = "QUARTER(a.DateSubmitted) = QUARTER(CURRENT_DATE()) AND YEAR(a.DateSubmitted) = YEAR(CURRENT_DATE())";
} elseif ($period === 'annual') {
    $filters[] = "YEAR(a.DateSubmitted) = YEAR(CURRENT_DATE())";
}

$whereClause = implode(" AND ", $filters);

$query = "
    SELECT a.ApplicationID, a.ScholarshipID, u.*, s.Name as ScholarshipName 
    FROM application a
    JOIN users u ON a.UserID = u.UserID
    JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
    WHERE $whereClause
    ORDER BY s.Name ASC, u.LastName ASC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped_applicants = [];
foreach ($applicants as $app) {
    $grouped_applicants[$app['ScholarshipName']][] = $app;
}

$spreadsheet = IOFactory::load($templatePath);
$templateSheet = $spreadsheet->getSheetByName('Annex 1');

if ($templateSheet === null) {
    $templateSheet = $spreadsheet->getActiveSheet();
}

if (empty($grouped_applicants)) {
    $templateSheet->setCellValue('F9', 'No applicants found for this filter.');
} else {
    
    $isFirstGroup = true;

    foreach ($grouped_applicants as $schName => $students) {
        
        // Tab name: max 31 characters, no []:*?/\ and unique in the workbook
        $safeName = mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], '', $schName), 0, 31);
        $originalSafeName = $safeName;
        $counter = 1;
        while ($spreadsheet->sheetNameExists($safeName) && !($isFirstGroup && $spreadsheet->getSheetByName($safeName) === $templateSheet)) {
            $safeName = mb_substr($originalSafeName, 0, 28) . " " . $counter;
            $counter++;
        }

        // ✨ FIX: Hindi na natin ide-delete ang first sheet! Ito ay irerename na lang.
        if ($isFirstGroup) {
            $newSheet = $templateSheet;
            $newSheet->setTitle($safeName);
            $isFirstGroup = false;
        } else {
            // A copy of the template keeps the first sheet's name: rename it BEFORE adding it,
            // or the workbook refuses a second sheet with the same name (HTTP 500 for "All scholarships")
            $newSheet = clone $templateSheet;
            $newSheet->setTitle($safeName, false);
            $spreadsheet->addSheet($newSheet);
        }
        
        // DYNAMIC TITLE
        $statusLabel = ($status === 'All_Applicants') ? " (ALL STATUSES)" : " (" . mb_strtoupper($status, 'UTF-8') . " APPLICANTS)";
        $newSheet->setCellValue('A3', 'LIST OF ' . mb_strtoupper($schName, 'UTF-8') . ' APPLICANTS' . $statusLabel);
        
        // ✨ KUNIN ANG CUSTOM HEADERS MULA SA DATABASE
        $current_sch_id = $students[0]['ScholarshipID'];
        $customHeaders = [];
        
        $clean_checks = array_map(function($v) { 
            return strtolower(trim(htmlspecialchars_decode($v))); 
        }, $custom_fields_check);

        $cfStmt = $pdo->prepare("SELECT FieldName FROM scholarship_custom_fields WHERE ScholarshipID = ? ORDER BY FieldID ASC");
        $cfStmt->execute([$current_sch_id]);
        
        while ($cfRow = $cfStmt->fetch(PDO::FETCH_ASSOC)) {
            $originalName = $cfRow['FieldName'];
            $cleanName = strtolower(trim($originalName));
            
            if (in_array($cleanName, $clean_checks)) {
                $customHeaders[$originalName] = true;
            }
        }

        $appIds = array_column($students, 'ApplicationID');
        $customAnswers = [];

        if (!empty($appIds) && !empty($customHeaders)) {
            $inQuery = implode(',', array_fill(0, count($appIds), '?'));
            $ansStmt = $pdo->prepare("
                SELECT aca.ApplicationID, scf.FieldName, aca.AnswerText
                FROM application_custom_answers aca
                JOIN scholarship_custom_fields scf ON aca.FieldID = scf.FieldID
                WHERE aca.ApplicationID IN ($inQuery)
            ");
            $ansStmt->execute($appIds);
            
            while ($ansRow = $ansStmt->fetch(PDO::FETCH_ASSOC)) {
                $dbFNameClean = strtolower(trim($ansRow['FieldName']));
                foreach ($customHeaders as $headerKey => $val) {
                    if (strtolower(trim($headerKey)) === $dbFNameClean) {
                        $customAnswers[$ansRow['ApplicationID']][$headerKey] = $ansRow['AnswerText'];
                        break;
                    }
                }
            }
        }

        // ✨ GUMAWA NG BAGONG COLUMNS (Simula AE)
        $colLetter = 'AE';
        $headerMap = [];
        foreach (array_keys($customHeaders) as $headerName) {
            $newSheet->setCellValue($colLetter . '7', 'CUSTOM FIELD');
            $newSheet->setCellValue($colLetter . '8', mb_strtoupper($headerName, 'UTF-8'));
            
            // Magaan na styling para hindi mag-500 Error
            $newSheet->getStyle($colLetter . '7:' . $colLetter . '8')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF28F00']],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]
            ]);
            
            $newSheet->getColumnDimension($colLetter)->setWidth(25);
            $newSheet->getColumnDimension($colLetter)->setVisible(true);
            
            $headerMap[$headerName] = $colLetter; 
            $colLetter++; 
        }

        $row = 9;
        $seq = 1; 
        
        foreach ($students as $applicant) {
            
            if ($row == 9) {
                $newSheet->setCellValue('F9', ''); 
            }

            $newSheet->setCellValue('A' . $row, $seq); // Importante para hindi ibura ng macro
            $newSheet->setCellValue('B' . $row, $applicant['StudentID_Num'] ?? '');
            $newSheet->setCellValue('C' . $row, $applicant['LRN'] ?? '');
            $newSheet->setCellValue('D' . $row, $applicant['PhilSysID'] ?? '');
            $newSheet->setCellValue('E' . $row, $applicant['FourPsID'] ?? '');
            $newSheet->setCellValue('F' . $row, $applicant['LastName'] ?? '');
            $newSheet->setCellValue('G' . $row, $applicant['FirstName'] ?? '');
            $newSheet->setCellValue('H' . $row, $applicant['ExtName'] ?? '');
            $newSheet->setCellValue('I' . $row, $applicant['MiddleName'] ?? '');
            
            $genderStr = strtolower($applicant['Gender'] ?? '');
            $genderCode = ($genderStr === 'female') ? 1 : ($genderStr === 'male' ? 0 : '');
            $newSheet->setCellValue('J' . $row, $genderCode);
            $newSheet->setCellValue('K' . $row, $applicant['DateOfBirth'] ?? '');

            $newSheet->setCellValue('L' . $row, $applicant['Major'] ?? '');
            $yearLvl = $applicant['YearLevel'] ?? '';
            $yearNum = (int) filter_var($yearLvl, FILTER_SANITIZE_NUMBER_INT);
            $newSheet->setCellValue('M' . $row, ($yearNum > 0) ? $yearNum : '');

            $newSheet->setCellValue('N' . $row, $applicant['FatherLastName'] ?? '');
            $newSheet->setCellValue('O' . $row, $applicant['FatherFirstName'] ?? '');
            $newSheet->setCellValue('P' . $row, $applicant['FatherMiddleName'] ?? '');
            $newSheet->setCellValue('Q' . $row, $applicant['MotherLastName'] ?? '');
            $newSheet->setCellValue('R' . $row, $applicant['MotherFirstName'] ?? '');
            $newSheet->setCellValue('S' . $row, $applicant['MotherMiddleName'] ?? '');

            $newSheet->setCellValue('T' . $row, $applicant['StreetBarangay'] ?? '');
            $newSheet->setCellValue('U' . $row, $applicant['CityMunicipality'] ?? '');
            $newSheet->setCellValue('V' . $row, $applicant['Province'] ?? '');
            $newSheet->setCellValue('W' . $row, $applicant['Region'] ?? '');
            $newSheet->setCellValue('X' . $row, $applicant['ZipCode'] ?? '');

            $newSheet->setCellValue('Y' . $row, $applicant['ContactNumber'] ?? '');
            $newSheet->setCellValue('Z' . $row, $applicant['Email'] ?? '');

            $newSheet->setCellValue('AA' . $row, $applicant['DisabilityType'] ?? '');
            $soloStr = strtolower($applicant['IsSoloParentChild'] ?? '');
            $soloCode = ($soloStr === 'yes') ? 1 : ($soloStr === 'no' ? 0 : '');
            $newSheet->setCellValue('AB' . $row, $soloCode);
            $firstGenStr = strtolower($applicant['IsFirstGenStudent'] ?? '');
            $firstGenCode = ($firstGenStr === 'yes') ? 1 : ($firstGenStr === 'no' ? 0 : '');
            $newSheet->setCellValue('AC' . $row, $firstGenCode);
            $newSheet->setCellValue('AD' . $row, $applicant['IndigenousGroup'] ?? '');
            
            // ISULAT ANG CUSTOM ANSWERS SA CUSTOM COLUMNS
            if (isset($customAnswers[$applicant['ApplicationID']])) {
                foreach ($customAnswers[$applicant['ApplicationID']] as $fName => $fVal) {
                    if (isset($headerMap[$fName])) {
                        $targetColumn = $headerMap[$fName];
                        $newSheet->setCellValue($targetColumn . $row, $fVal);
                    }
                }
            }
            $row++;
            $seq++;
        }

        // ✨ MAGAAN NA BORDERS PARA SA CUSTOM COLUMNS
        if (!empty($headerMap) && $row > 9) {
            $lastCol = end($headerMap); 
            $newSheet->getStyle('AE9:' . $lastCol . ($row - 1))->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
            ]);
        }

        // ✨ MACRO-PROOF PADDING: Lagyan ng space ang susunod na cells para panatag ang Excel
        for ($r = $row; $r <= ($row + 30); $r++) {
            $newSheet->setCellValue('A' . $r, ' '); 
        }

        // ✨ HIDING UNCHECKED COLUMNS
        if (!in_array('lrn', $fields)) { $newSheet->getColumnDimension('C')->setVisible(false); }
        if (!in_array('philsys', $fields)) { $newSheet->getColumnDimension('D')->setVisible(false); }
        if (!in_array('4ps', $fields)) { $newSheet->getColumnDimension('E')->setVisible(false); }
        if (!in_array('program', $fields)) { $newSheet->getColumnDimension('L')->setVisible(false); }
        if (!in_array('year_level', $fields)) { $newSheet->getColumnDimension('M')->setVisible(false); }
        if (!in_array('father', $fields)) { 
            $newSheet->getColumnDimension('N')->setVisible(false);
            $newSheet->getColumnDimension('O')->setVisible(false);
            $newSheet->getColumnDimension('P')->setVisible(false);
        }
        if (!in_array('mother', $fields)) { 
            $newSheet->getColumnDimension('Q')->setVisible(false);
            $newSheet->getColumnDimension('R')->setVisible(false);
            $newSheet->getColumnDimension('S')->setVisible(false);
        }
        if (!in_array('street', $fields)) { $newSheet->getColumnDimension('T')->setVisible(false); }
        if (!in_array('city', $fields)) { $newSheet->getColumnDimension('U')->setVisible(false); }
        if (!in_array('province', $fields)) { $newSheet->getColumnDimension('V')->setVisible(false); }
        if (!in_array('region', $fields)) { $newSheet->getColumnDimension('W')->setVisible(false); }
        if (!in_array('zipcode', $fields)) { $newSheet->getColumnDimension('X')->setVisible(false); }
        if (!in_array('contact', $fields)) { $newSheet->getColumnDimension('Y')->setVisible(false); }
        if (!in_array('email', $fields)) { $newSheet->getColumnDimension('Z')->setVisible(false); }
        if (!in_array('disability', $fields)) { $newSheet->getColumnDimension('AA')->setVisible(false); }
        if (!in_array('solo_parent', $fields)) { $newSheet->getColumnDimension('AB')->setVisible(false); }
        if (!in_array('first_gen', $fields)) { $newSheet->getColumnDimension('AC')->setVisible(false); }
        if (!in_array('indigenous', $fields)) { $newSheet->getColumnDimension('AD')->setVisible(false); }
    } 
    
    // Set active sheet sa unang generated tab
    $spreadsheet->setActiveSheetIndex(0);
}

// ✨ EXPORT EXCEL FILE
$filename = 'ScholarLink_Annex1_Grants_' . date('Y-m-d') . '.xlsm';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.ms-excel.sheet.macroEnabled.12');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx'); 
$writer->save('php://output');
exit;