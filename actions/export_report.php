<?php
// ✨ SYSTEM REPORTS: preview (html), PDF or Excel (.xlsx)
// Same filters as the Generate Reports window on the Masterlist (GET), see includes/reports.php.
session_start();
require '../includes/db_connect.php';
require '../vendor/autoload.php';
require_once '../includes/reports.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin', 'Super_Admin'], true)) {
    http_response_code(403);
    exit('Unauthorized access.');
}

$format = in_array($_GET['format'] ?? '', ['pdf', 'xlsx', 'html'], true) ? $_GET['format'] : 'pdf';
try {
    $report = runReport($pdo, $_GET, $_SESSION['role'], (int) $_SESSION['user_id']);
} catch (RuntimeException $e) {
    http_response_code(400);
    exit($e->getMessage());
}

$generated = date('F j, Y g:i A');
$default_signatory = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$filters_line = $report['filters'] ? implode(' · ', $report['filters']) : 'All records';
$total_rows = array_sum(array_map(fn($s) => count($s['rows']), $report['sections']));
$multi = count($report['sections']) > 1 || $report['type'] === 'scholars_per_scholarship' && !$report['scholarship'];
$file_base = 'ScholarLink_' . preg_replace('/[^A-Za-z0-9]+/', '_', $report['title']) . '_' . date('Y-m-d');

if ($format !== 'html') {
    $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, ActionDate, Description, IPAddress) VALUES (?, 'Report Generated', NOW(), ?, ?)")
        ->execute([$_SESSION['user_id'], "Generated \"{$report['title']}\" (" . strtoupper($format) . ", $total_rows rows) — $filters_line", $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
}

// What a scholarship with no rows has none of
$empty_word = ($report['type'] ?? '') === 'applicants' ? 'applicants' : 'scholars';

// =====================================================================
// EXCEL: one sheet per section (one per scholarship for "all scholarships") + Summary
// =====================================================================
if ($format === 'xlsx') {
    $book = new Spreadsheet();
    $book->removeSheetByIndex(0);
    $used_names = ['summary' => true];
    $tabs = [];   // [sheet name, scholarship, rows] for the Summary's list of tabs

    // Tab names: Excel allows 31 characters, unique, without []:*?/\ — long names are shortened word by word, not cut off
    $sheet_name = function (string $full) use (&$used_names): string {
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[\[\]:*?\/\\\\]/', '', $full))) ?: 'Report';
        $short = ['/\bScholarships?\b/i' => 'Sch.', '/\bPrograms?\b/i' => 'Prog.', '/\bFoundation\b/i' => 'Fdn.', '/\bIncorporated\b/i' => 'Inc.',
                  '/\bPhilippines\b/i' => 'Phil.', '/\bAllowance\b/i' => 'Allow.', '/\bIntegrated\b/i' => 'Integ.', '/\bTraining\b/i' => 'Trng.',
                  '/\bRegular\b/i' => 'Reg.', '/\bAssociation\b/i' => 'Assoc.', '/\bUniversity\b/i' => 'Univ.', '/\bAgriculture\b/i' => 'Agri.',
                  '/\bEducational\b/i' => 'Educ.', '/\bEngineers\b/i' => 'Engrs.', '/\bIncentives?\b/i' => 'Incent.', '/\s+and\s+/i' => ' & '];
        foreach ($short as $pattern => $abbr) {
            if (mb_strlen($name) <= 31) break;
            $name = preg_replace($pattern, $abbr, $name);
        }
        if (mb_strlen($name) > 31) $name = rtrim(mb_substr($name, 0, 30)) . '…';
        $base = $name;
        for ($n = 2; isset($used_names[mb_strtolower($name)]); $n++) $name = rtrim(mb_substr($base, 0, 26)) . " ($n)";
        $used_names[mb_strtolower($name)] = true;
        return $name;
    };

    $sections = $report['sections'] ?: [['title' => $report['title'], 'subtitle' => '', 'prepared_by' => '', 'columns' => [['key' => 'none', 'label' => 'Result']], 'rows' => [], 'scholarship' => null]];
    foreach ($sections as $section) {
        // Tab: the scholarship's name, or what the report lists ("Pending Scholarship Applicants")
        $name = $sheet_name($section['scholarship']['Name'] ?? preg_replace('/^List of (All )?/i', '', explode(' — ', $section['title'])[0]));
        $tabs[] = [$name, $section['scholarship']['Name'] ?? $section['title'], count($section['rows'])];

        $sheet = $book->createSheet();
        $sheet->setTitle($name);
        $col_count = max(1, count($section['columns']));
        $last_col = Coordinate::stringFromColumnIndex($col_count);

        $header = [
            ['Republic of the Philippines', 10, false],
            ['TARLAC AGRICULTURAL UNIVERSITY', 14, true],
            ['Office of Student Services and Development · Camiling, Tarlac', 10, false],
            ['', 6, false],
            [mb_strtoupper($section['title']), 13, true],
        ];
        if ($section['subtitle'] !== '') $header[] = [$section['subtitle'], 11, true];
        $header[] = [$filters_line . '   ·   Generated ' . $generated, 9, false];

        $row = 1;
        foreach ($header as [$text, $size, $bold]) {
            $sheet->setCellValue("A$row", $text);
            $sheet->mergeCells("A$row:$last_col$row");
            $sheet->getStyle("A$row")->getFont()->setSize($size)->setBold($bold);
            $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }
        $row++;

        $head_row = $row;
        foreach ($section['columns'] as $i => $c) $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . $row, $c['label']);
        $sheet->getStyle("A$row:$last_col$row")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0B3D2A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        foreach ($section['rows'] as $r) {
            $row++;
            foreach ($section['columns'] as $i => $c) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $row, (string) ($r[$c['key']] ?? ''), DataType::TYPE_STRING);
            }
        }
        if (!$section['rows']) {
            $row++;
            $sheet->setCellValue("A$row", 'No records match the selected filters.');
            $sheet->mergeCells("A$row:$last_col$row");
        }
        $sheet->getStyle("A$head_row:$last_col$row")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A' . ($head_row + 1));
        foreach (range(1, $col_count) as $i) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);

        $row += 2;
        $sheet->setCellValue("A$row", 'Total: ' . count($section['rows']));
        $sheet->getStyle("A$row")->getFont()->setBold(true);
        $row += 2;
        $sheet->setCellValue("A$row", 'Prepared by:');
        $sheet->setCellValue('A' . ($row + 2), $section['prepared_by'] ?: $default_signatory);
        $sheet->getStyle('A' . ($row + 2))->getFont()->setBold(true);
        // Way back to the list of tabs when there are several scholarships
        if (count($sections) > 1) {
            $sheet->setCellValue('A' . ($row + 4), '← Back to Summary');
            $sheet->getCell('A' . ($row + 4))->getHyperlink()->setUrl("sheet://'Summary'!A1");
            $sheet->getStyle('A' . ($row + 4))->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');
        }

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd($head_row, $head_row);
    }

    if ($report['summary'] || !empty($report['empty_scholarships']) || count($sections) > 1) {
        $sum = $book->createSheet();
        $sum->setTitle('Summary');
        $sum->setCellValue('A1', 'SUMMARY — ' . mb_strtoupper($report['title']));
        $sum->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sum->setCellValue('A2', $filters_line . '   ·   Total records: ' . $total_rows);
        $r = 4;

        // One tab per scholarship: list them, each name opens its tab
        if (count($sections) > 1) {
            $sum->setCellValue("A$r", 'Scholarship (click to open its tab)');
            $sum->setCellValue("B$r", 'Records');
            $sum->setCellValue("C$r", 'Tab');
            $sum->getStyle("A$r:C$r")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0B3D2A']]]);
            $start = $r;
            foreach ($tabs as [$tab, $full, $count]) {
                $r++;
                $sum->setCellValueExplicit("A$r", $full, DataType::TYPE_STRING);
                $sum->getCell("A$r")->getHyperlink()->setUrl("sheet://'" . str_replace("'", "''", $tab) . "'!A1");
                $sum->getStyle("A$r")->getFont()->setUnderline(true)->getColor()->setRGB('1D4ED8');
                $sum->setCellValue("B$r", $count);
                $sum->setCellValueExplicit("C$r", $tab, DataType::TYPE_STRING);
            }
            $sum->getStyle("A$start:C$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sum->getColumnDimension('C')->setAutoSize(true);
            $r += 2;
        }
        foreach ($report['summary'] as $dimension => $counts) {
            $sum->setCellValue("A$r", "By $dimension");
            $sum->setCellValue("B$r", 'Count');
            $sum->getStyle("A$r:B$r")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']]]);
            $start = $r;
            foreach ($counts as $value => $count) {
                $r++;
                $sum->setCellValueExplicit("A$r", (string) $value, DataType::TYPE_STRING);
                $sum->setCellValue("B$r", $count);
            }
            $r++;
            $sum->setCellValue("A$r", 'Total');
            $sum->setCellValue("B$r", array_sum($counts));
            $sum->getStyle("A$r:B$r")->getFont()->setBold(true);
            $sum->getStyle("A$start:B$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $r += 2;
        }
        if (!empty($report['empty_scholarships'])) {
            $sum->setCellValue("A$r", 'Scholarships with no ' . $empty_word . ' for these filters');
            $sum->getStyle("A$r")->getFont()->setBold(true);
            foreach ($report['empty_scholarships'] as $name) { $r++; $sum->setCellValue("A$r", $name); }
        }
        $sum->getColumnDimension('A')->setAutoSize(true);
        $sum->getColumnDimension('B')->setWidth(12);
        // With one tab per scholarship, the Summary (their list) comes first
        if (count($sections) > 1) $book->setIndexByName('Summary', 0);
    }
    $book->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file_base . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($book))->save('php://output');
    exit();
}

// =====================================================================
// PDF / HTML PREVIEW: same page; each scholarship starts on a new page for "all scholarships"
// =====================================================================
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$logo_path = __DIR__ . '/../assets/img/tau_logo.png';
$logo = is_file($logo_path) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_path)) : '';
$max_cols = max(1, ...array_map(fn($s) => count($s['columns']), $report['sections'] ?: [['columns' => []]]));
$font = $max_cols > 12 ? 6.5 : ($max_cols > 9 ? 7.5 : 8.5);
$preview = $format === 'html';

ob_start(); ?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title><?= $e($report['title']) ?></title><style>
    @page { margin: 28px 30px 40px; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: <?= $font ?>pt; color: #111; <?= $preview ? 'background:#e2e8f0; margin:0; padding:20px;' : '' ?> }
    <?php if ($preview): ?>.sheet { background: #fff; max-width: 1100px; margin: 0 auto 20px; padding: 28px 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.12); }<?php endif; ?>
    .letterhead { text-align: center; border-bottom: 2px solid #0b3d2a; padding-bottom: 6px; margin-bottom: 8px; position: relative; }
    .letterhead img { height: 46px; <?= $preview ? 'position:absolute; left:0; top:0;' : 'float:left;' ?> }
    .letterhead .rp { font-size: 8pt; } .letterhead .uni { font-size: 13pt; font-weight: bold; color: #0b3d2a; } .letterhead .office { font-size: 8pt; }
    h1 { font-size: 12pt; text-align: center; margin: 6px 0 2px; text-transform: uppercase; }
    .subtitle { text-align: center; font-size: 10pt; font-weight: bold; margin-bottom: 2px; }
    .filters { text-align: center; font-size: 7.5pt; color: #555; margin-bottom: 8px; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #0b3d2a; color: #fff; padding: 4px 3px; border: 0.5px solid #0b3d2a; font-size: <?= $font ?>pt; }
    table.data td { border: 0.5px solid #999; padding: 3px; vertical-align: top; }
    table.data tr:nth-child(even) td { background: #f3f8f5; }
    .total { margin-top: 6px; font-weight: bold; }
    .summary { margin-top: 14px; page-break-inside: avoid; }
    .summary h2 { font-size: 10pt; margin: 0 0 4px; }
    .summary table { border-collapse: collapse; margin: 0 10px 8px 0; display: inline-table; vertical-align: top; }
    .summary th { background: #198754; color: #fff; padding: 3px 8px; text-align: left; font-size: 7.5pt; }
    .summary td { border: 0.5px solid #bbb; padding: 2px 8px; font-size: 7.5pt; }
    .signature { margin-top: 22px; width: 240px; }
    .signature .line { border-top: 1px solid #111; margin-top: 26px; padding-top: 2px; font-weight: bold; }
    .page-break { page-break-after: always; }
</style></head><body>
<?php
$letterhead = fn() => '<div class="letterhead">' . ($logo ? '<img src="' . $logo . '">' : '')
    . '<div class="rp">Republic of the Philippines</div><div class="uni">TARLAC AGRICULTURAL UNIVERSITY</div>'
    . '<div class="office">Office of Student Services and Development · Camiling, Tarlac</div></div>';

if (!$report['sections']): ?>
    <div class="sheet"><?= $letterhead() ?><h1><?= $e($report['title']) ?></h1>
        <div class="filters"><?= $e($filters_line) ?> · Generated <?= $e($generated) ?></div>
        <p style="text-align:center;padding:20px">No records match the selected filters.</p></div>
<?php endif;

foreach ($report['sections'] as $i => $section): $cols = count($section['columns']); ?>
    <div class="sheet <?= $i < count($report['sections']) - 1 || $report['summary'] ? 'page-break' : '' ?>">
        <?= $letterhead() ?>
        <h1><?= $e($section['title']) ?></h1>
        <?php if ($section['subtitle'] !== ''): ?><div class="subtitle"><?= $e($section['subtitle']) ?></div><?php endif; ?>
        <div class="filters"><?= $e($filters_line) ?> · <?= $e($section['format']) ?> · Generated <?= $e($generated) ?></div>
        <table class="data">
            <thead><tr><?php foreach ($section['columns'] as $c): ?><th><?= $e($c['label']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php foreach ($section['rows'] as $r): ?>
                    <tr><?php foreach ($section['columns'] as $c): ?><td><?= $e($r[$c['key']] ?? '') ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                <?php if (!$section['rows']): ?><tr><td colspan="<?= $cols ?>" style="text-align:center;padding:12px">No records match the selected filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <div class="total">Total: <?= count($section['rows']) ?></div>
        <div class="signature">Prepared by:<div class="line"><?= $e($section['prepared_by'] ?: $default_signatory) ?></div></div>
    </div>
<?php endforeach; ?>

<?php if ($report['summary'] || !empty($report['empty_scholarships'])): ?>
    <div class="sheet">
        <?php if ($multi || $report['sections']): ?><?= $multi ? $letterhead() . '<h1>Summary — ' . $e($report['title']) . '</h1><div class="filters">' . $e($filters_line) . ' · Total records: ' . $total_rows . '</div>' : '' ?><?php endif; ?>
        <div class="summary">
            <?php if (!$multi): ?><h2>Summary</h2><?php endif; ?>
            <?php foreach ($report['summary'] as $dimension => $counts): ?>
                <table>
                    <tr><th>By <?= $e($dimension) ?></th><th>Count</th></tr>
                    <?php foreach ($counts as $value => $count): ?><tr><td><?= $e($value) ?></td><td style="text-align:right"><?= $count ?></td></tr><?php endforeach; ?>
                    <tr><td><b>Total</b></td><td style="text-align:right"><b><?= array_sum($counts) ?></b></td></tr>
                </table>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($report['empty_scholarships'])): ?>
            <p style="font-size:7.5pt;color:#555;margin-top:10px"><b>Scholarships with no <?= $e($empty_word) ?> for these filters (<?= count($report['empty_scholarships']) ?>):</b> <?= $e(implode(', ', $report['empty_scholarships'])) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
</body></html>
<?php
$html = ob_get_clean();

if ($preview) {
    // Single-column sections lose their page break gap in the browser, so the .sheet cards carry it
    echo $html;
    exit();
}

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper($max_cols > 12 ? 'legal' : 'A4', 'landscape');
$dompdf->render();
$dompdf->getCanvas()->page_text(30, $dompdf->getCanvas()->get_height() - 28, "ScholarLink · " . $report['title'] . " · Page {PAGE_NUM} of {PAGE_COUNT}", null, 7, [0.4, 0.4, 0.4]);
$dompdf->stream($file_base . '.pdf', ['Attachment' => ($_GET['download'] ?? '0') === '1']);
