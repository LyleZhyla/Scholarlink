<?php
// ✨ OFFICIAL PDF APPLICATION FORMS (the fillable PDFs in /forms)
// Shared by the Form Builder (pick a template, map questions to its fields) and
// actions/generate_application_pdf.php (fills the template for an application).

if (!defined('PDF_FORMS_DIR')) {
    define('PDF_FORMS_DIR', realpath(__DIR__ . '/../forms'));
    define('PDF_FIELD_CACHE_DIR', __DIR__ . '/../temp');
    // Windows (XAMPP) install path, otherwise pdftk on the PATH (Linux server)
    define('PDFTK_BIN', file_exists('C:\Program Files (x86)\PDFtk Server\bin\pdftk.exe') ? 'C:\Program Files (x86)\PDFtk Server\bin\pdftk.exe' : 'pdftk');
    define('ANNEX1_TEMPLATE', 'Annex 1 TDP-FORM_updated.docx.pdf');
    define('GENERIC_TEMPLATE', 'TAU-OSSD-QF-19B_Application_Form_Scholarship_Generic.pdf');
}

if (!function_exists('listPdfTemplates')) {

    // filename => readable name, for every PDF in /forms
    function listPdfTemplates(): array {
        $templates = [];
        foreach (glob(PDF_FORMS_DIR . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $path) {
            $file = basename($path);
            $templates[$file] = trim(preg_replace('/[_\s]+/', ' ', preg_replace('/(\.docx)?\.pdf$/i', '', $file)));
        }
        ksort($templates);
        return $templates;
    }

    // Forms an admin may pick: the built-in ones (no form_templates row), their own uploads
    // (Super Admins see every upload), plus $always_include (the form a scholarship already uses).
    // filename => ['label' => ..., 'custom' => uploaded?, 'mine' => uploaded by this admin?]
    function visiblePdfTemplates(PDO $pdo, string $role, int $user_id, ?string $always_include = null): array {
        $uploaded = [];
        foreach ($pdo->query("SELECT FileName, Label, UploadedBy FROM form_templates") as $row) {
            $uploaded[$row['FileName']] = $row;
        }
        $visible = [];
        foreach (listPdfTemplates() as $file => $label) {
            $row = $uploaded[$file] ?? null;
            $mine = $row && (int) $row['UploadedBy'] === $user_id;
            if ($row && !$mine && $role !== 'Super_Admin' && $file !== $always_include) continue;
            $visible[$file] = ['label' => $row ? $row['Label'] : $label, 'custom' => (bool) $row, 'mine' => $mine];
        }
        return $visible;
    }

    // Template used when a scholarship hasn't chosen one (the original rule)
    function defaultPdfTemplate(string $scholarship_name): string {
        return (stripos($scholarship_name, 'Tulong Dunong') !== false || stripos($scholarship_name, 'TES') !== false)
            ? ANNEX1_TEMPLATE : GENERIC_TEMPLATE;
    }

    function resolvePdfTemplate(?string $chosen, string $scholarship_name): string {
        return ($chosen && isset(listPdfTemplates()[$chosen])) ? $chosen : defaultPdfTemplate($scholarship_name);
    }

    // Fillable fields of a template: [['name' => ..., 'type' => 'Text'|'Checkbox'], ...]
    // Signatures and image buttons are skipped. Cached until the PDF file changes.
    function getPdfTemplateFields(string $file): array {
        $path = PDF_FORMS_DIR . DIRECTORY_SEPARATOR . basename($file);
        if (!is_file($path)) return [];

        $cache = PDF_FIELD_CACHE_DIR . '/pdf_fields_' . md5($path . filemtime($path)) . '.json';
        if (is_file($cache)) {
            $cached = json_decode(file_get_contents($cache), true);
            if (is_array($cached)) return $cached;
        }

        $output = shell_exec(escapeshellarg(PDFTK_BIN) . ' ' . escapeshellarg($path) . ' dump_data_fields_utf8 2>&1');
        $fields = [];
        foreach (preg_split('/^---$/m', (string) $output) as $block) {
            if (!preg_match('/^FieldName: (.+)$/m', $block, $name) || !preg_match('/^FieldType: (\w+)$/m', $block, $type)) continue;
            if ($type[1] === 'Text') {
                $fields[] = ['name' => trim($name[1]), 'type' => 'Text'];
            } elseif ($type[1] === 'Button' && preg_match('/^FieldStateOption: (?!Off$)(.+)$/m', $block, $on)) {
                $fields[] = ['name' => trim($name[1]), 'type' => 'Checkbox', 'on' => trim($on[1])];
            }
        }

        if ($fields && is_dir(PDF_FIELD_CACHE_DIR) && is_writable(PDF_FIELD_CACHE_DIR)) {
            file_put_contents($cache, json_encode($fields));
        }
        return $fields;
    }

    // PDF fields filled automatically from the student's profile, for a given template
    function buildProfilePdfMap(string $template, array $data): array {
        $data += array_fill_keys(['ScholarshipName', 'DateSubmitted', 'FirstName', 'LastName', 'MiddleName', 'StudentID_Num', 'Major', 'YearLevel',
            'ContactNumber', 'Email', 'DateOfBirth', 'Gender', 'Citizenship', 'FatherFirstName', 'FatherLastName', 'MotherFirstName', 'MotherLastName',
            'StreetBarangay', 'CityMunicipality', 'Province', 'ZipCode', 'DisabilityType', 'IndigenousGroup'], null);

        $full_address = trim(($data['StreetBarangay'] ?? '') . ', ' . ($data['CityMunicipality'] ?? '') . ', ' . ($data['Province'] ?? ''), ', ');
        $birth_date = $data['DateOfBirth'] ? date('m/d/Y', strtotime($data['DateOfBirth'])) : 'N/A';
        $application_date = $data['DateSubmitted'] ? date('F j, Y', strtotime($data['DateSubmitted'])) : '';
        $citizenship = !empty($data['Citizenship']) ? $data['Citizenship'] : 'Filipino';
        $father = trim(strtoupper(($data['FatherFirstName'] ?? '') . ' ' . ($data['FatherLastName'] ?? '')));
        $mother = trim(strtoupper(($data['MotherFirstName'] ?? '') . ' ' . ($data['MotherLastName'] ?? '')));

        if ($template === ANNEX1_TEMPLATE) {
            // ✨ MAPPING FOR ANNEX 1 TDP FORM ✨
            return [
                'lastName'        => strtoupper($data['LastName'] ?? ''),
                'firstName'       => strtoupper($data['FirstName'] ?? ''),
                'middlename'      => strtoupper($data['MiddleName'] ?? ''),
                'birthDate'       => $birth_date,
                'YearLevel'       => $data['YearLevel'] ?? '',
                'email'           => $data['Email'] ?? '',
                'mobileNumber'    => $data['ContactNumber'] ?? '',
                'applicationDate' => $application_date,
                'School ID Number'          => $data['StudentID_Num'] ?? '',
                'Name of School Attended'   => 'Tarlac Agricultural University',
                'School Address'            => 'Camiling, Tarlac',
                'Male FemaleCitizenship'    => $citizenship,
                'permadd'                   => $full_address,
                'Zip Code'                  => $data['ZipCode'] ?? '',
                'disability'                => $data['DisabilityType'] ?? '',
                'tribalmem'                 => $data['IndigenousGroup'] ?? '',
                'Father  Living  DeceasedName'    => $father,
                'Father  Living  DeceasedAddress' => $full_address,
                'Mother   Living   DeceasedName'    => $mother,
                'Mother   Living   DeceasedAddress' => $full_address,
            ];
        }

        $age = 'N/A';
        if ($data['DateOfBirth']) {
            $age = (new DateTime($data['DateOfBirth']))->diff(new DateTime('today'))->y;
        }

        // ✨ MAPPING FOR TAU-OSSD-QF-19B GENERIC FORM (also used for any other template) ✨
        return [
            'scholarshipName' => strtoupper($data['ScholarshipName'] ?? ''),
            'applicationDate' => $application_date,
            'lastName'        => strtoupper($data['LastName'] ?? ''),
            'firstName'       => strtoupper($data['FirstName'] ?? ''),
            'middleName'      => strtoupper($data['MiddleName'] ?? ''),
            'course'          => $data['Major'] ?? '',
            'age'             => $age,
            'birthDate'       => $birth_date,
            'sex'             => $data['Gender'] ?? '',
            'email'           => $data['Email'] ?? '',
            'mobileNumber'    => $data['ContactNumber'] ?? '',
            'homeaddress'     => $full_address,
            'Citizenship'     => $citizenship,
            'status'          => 'Single',
            'FatherName'      => $father,
            'FatherAddress'   => $full_address,
            'MotherName'      => $mother,
            'MotherAddress'   => $full_address,
        ];
    }

    // Names of the fields buildProfilePdfMap() fills, for showing in the builder
    function profilePdfFieldNames(string $template): array {
        return array_keys(buildProfilePdfMap($template, []));
    }

    // Page sizes of a template in points: [[width, height], ...] (cached like the field list)
    function getPdfPageSizes(string $file): array {
        $path = PDF_FORMS_DIR . DIRECTORY_SEPARATOR . basename($file);
        if (!is_file($path)) return [];

        $cache = PDF_FIELD_CACHE_DIR . '/pdf_pages_' . md5($path . filemtime($path)) . '.json';
        if (is_file($cache)) {
            $cached = json_decode(file_get_contents($cache), true);
            if (is_array($cached)) return $cached;
        }

        $output = shell_exec(escapeshellarg(PDFTK_BIN) . ' ' . escapeshellarg($path) . ' dump_data 2>&1');
        preg_match_all('/^PageMediaDimensions: ([\d.]+) ([\d.]+)$/m', (string) $output, $m, PREG_SET_ORDER);
        $sizes = array_map(fn($d) => [(float) $d[1], (float) $d[2]], $m);

        if ($sizes && is_dir(PDF_FIELD_CACHE_DIR) && is_writable(PDF_FIELD_CACHE_DIR)) {
            file_put_contents($cache, json_encode($sizes));
        }
        return $sizes;
    }

    // Writes answers into the boxes admins drew in the Form Builder, and applies content edits.
    // $boxes: [['page' => 1, 'x' => 0-1, 'y' => 0-1, 'w' => 0-1, 'h' => 0-1, 'size' => pt|null, 'text' => '...', 'multiline' => bool], ...]
    // $edits: [['kind' => 'erase'|'text', 'page', 'x', 'y', 'w', 'h', 'text', 'font' => Helvetica|Times|Courier, 'size', 'bold', 'cover'], ...]
    // Builds a transparent overlay with FPDF and merges it onto $pdf_path with pdftk. Returns the stamped file path, or null on failure.
    function stampPdfBoxes(string $pdf_path, array $page_sizes, array $boxes, string $temp_dir, array $edits = []): ?string {
        if ((!$boxes && !$edits) || !$page_sizes) return $pdf_path;

        $overlay = new FPDF('P', 'pt');
        $overlay->SetAutoPageBreak(false);
        $overlay->SetMargins(0, 0, 0);
        $overlay->SetTextColor(0, 0, 0);
        $overlay->SetFillColor(255, 255, 255);

        foreach ($page_sizes as $index => [$page_w, $page_h]) {
            $overlay->AddPage($page_w > $page_h ? 'L' : 'P', [$page_w, $page_h]);

            // ✨ CONTENT EDITS: erased areas first, then new text (with a white background when replacing)
            $page_edits = array_filter($edits, fn($e) => (int) $e['page'] === $index + 1 && in_array($e['kind'], ['erase', 'text'], true));
            usort($page_edits, fn($a, $b) => ($a['kind'] === 'erase' ? 0 : 1) <=> ($b['kind'] === 'erase' ? 0 : 1));
            foreach ($page_edits as $edit) {
                $x = $edit['x'] * $page_w;
                $y = $edit['y'] * $page_h;
                $w = $edit['w'] * $page_w;
                $h = $edit['h'] * $page_h;

                if ($edit['kind'] === 'erase' || !empty($edit['cover'])) {
                    $overlay->Rect($x, $y, $w, $h, 'F');
                }
                if ($edit['kind'] !== 'text' || trim((string) $edit['text']) === '') continue;

                $family = in_array($edit['font'] ?? '', ['Helvetica', 'Times', 'Courier'], true) ? $edit['font'] : 'Helvetica';
                $style = !empty($edit['bold']) ? 'B' : '';
                $text = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', preg_replace('/\s+/', ' ', trim($edit['text'])));
                $size = $edit['size'] ? (float) $edit['size'] : min(14, max(6, $h * 0.75));
                // Keep the chosen size unless the text would spill out of its box
                do {
                    $overlay->SetFont($family, $style, $size);
                    if ($overlay->GetStringWidth($text) <= $w || $size <= 5) break;
                    $size -= 0.5;
                } while (true);
                $overlay->Text($x, $y + ($h + $size * 0.7) / 2, $text);
            }

            foreach ($boxes as $box) {
                if ((int) $box['page'] !== $index + 1 || trim((string) $box['text']) === '') continue;

                $x = $box['x'] * $page_w;
                $y = $box['y'] * $page_h;
                $w = $box['w'] * $page_w;
                $h = $box['h'] * $page_h;
                // FPDF's built-in fonts use Windows-1252 (covers ñ, é, etc.)
                $text = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', preg_replace('/\s+/', ' ', trim($box['text'])));
                $size = $box['size'] ? (float) $box['size'] : min(11, max(6, $h * 0.7));

                // Shrink until the text fits the box (one line, or wrapped lines for paragraphs)
                do {
                    $overlay->SetFont('Helvetica', '', $size);
                    $lines = $box['multiline'] ? wrapPdfText($overlay, $text, $w - 4) : [$text];
                    $fits = $box['multiline']
                        ? count($lines) * $size * 1.15 <= $h
                        : $overlay->GetStringWidth($text) <= $w - 4;
                    if ($fits || $size <= 5) break;
                    $size -= 0.5;
                } while (true);

                if ($box['multiline']) {
                    foreach ($lines as $n => $line) {
                        $overlay->Text($x + 2, $y + $size * (1.15 * $n + 1), $line);
                    }
                } else {
                    $overlay->Text($x + 2, $y + $h / 2 + $size * 0.35, $text);
                }
            }
        }

        $overlay_path = $temp_dir . '/overlay_' . uniqid() . '.pdf';
        $output_path = $temp_dir . '/stamped_' . uniqid() . '.pdf';
        $overlay->Output('F', $overlay_path);

        shell_exec(escapeshellarg(PDFTK_BIN) . ' ' . escapeshellarg($pdf_path) . ' multistamp ' . escapeshellarg($overlay_path) . ' output ' . escapeshellarg($output_path) . ' 2>&1');
        @unlink($overlay_path);
        return is_file($output_path) ? $output_path : null;
    }

    // ✨ INSERTED ROWS AND COLUMNS (PDF designer → Edit content). The same cutting as layoutPieces() in
    // includes/form_builder_view.php: pieces of the page (as fractions) that keep their place or move; 'copy' pieces
    // repeat a strip in the new space. A 'move' takes the strip y..y+h out and puts it at 'to' (closing the gap it leaves).
    // $inserts: one page's 'row' / 'column' / 'move' edits, in order.
    function pdfLayoutPieces(array $inserts): array {
        $cut = function (array $p, float $at, string $axis): array {
            [$pos, $size, $src] = $axis === 'y' ? ['dy', 'h', 'sy'] : ['dx', 'w', 'sx'];
            if ($at <= $p[$pos] + 1e-9) return [null, $p];
            if ($at >= $p[$pos] + $p[$size] - 1e-9) return [$p, null];
            $k = $at - $p[$pos];
            return [array_merge($p, [$size => $k]), array_merge($p, [$src => $p[$src] + $k, $pos => $at, $size => $p[$size] - $k])];
        };
        $pieces = [['sx' => 0.0, 'sy' => 0.0, 'w' => 1.0, 'h' => 1.0, 'dx' => 0.0, 'dy' => 0.0, 'copy' => false]];
        foreach ($inserts as $ins) {
            if ($ins['kind'] === 'move') {
                [$a, $h, $d] = [(float) $ins['y'], (float) $ins['h'], (float) $ins['to']];
                $top = $d <= $a ? $d : $d - $h;
                $map = function (float $y) use ($a, $h, $d, $top): float {
                    if ($y >= $a && $y < $a + $h) return $y - $a + $top;
                    if ($d <= $a && $y >= $d && $y < $a) return $y + $h;
                    if ($d >= $a + $h && $y >= $a + $h && $y < $d) return $y - $h;
                    return $y;
                };
                foreach ([$a, $a + $h, $d] as $at) {
                    $split = [];
                    foreach ($pieces as $p) foreach ($cut($p, $at, 'y') as $part) if ($part) $split[] = $part;
                    $pieces = $split;
                }
                $pieces = array_map(fn($p) => array_merge($p, ['dy' => $map($p['dy'] + $p['h'] / 2) - $p['h'] / 2]), $pieces);
                continue;
            }
            $out = []; $copies = [];
            [$x, $y, $w, $h, $copy] = [(float) $ins['x'], (float) $ins['y'], (float) $ins['w'], (float) $ins['h'], !empty($ins['cover'])];
            foreach ($pieces as $p) {
                if ($ins['kind'] === 'row') {
                    if ($copy) {
                        $from = $cut($p, $y, 'y')[1];
                        $strip = $from ? $cut($from, $y + $h, 'y')[0] : null;
                        if ($strip) $copies[] = array_merge($strip, ['dy' => $strip['dy'] + $h, 'copy' => true]);
                    }
                    [$above, $below] = $cut($p, $y + $h, 'y');
                    if ($above) $out[] = $above;
                    if ($below) $out[] = array_merge($below, ['dy' => $below['dy'] + $h]);
                    continue;
                }
                [$above, $rest] = $cut($p, $y, 'y');
                if ($above) $out[] = $above;
                if (!$rest) continue;
                [$band, $below] = $cut($rest, $y + $h, 'y');
                if ($below) $out[] = $below;
                if (!$band) continue;
                if ($copy) {
                    $from = $cut($band, $x, 'x')[1];
                    $strip = $from ? $cut($from, $x + $w, 'x')[0] : null;
                    if ($strip) $copies[] = array_merge($strip, ['dx' => $strip['dx'] + $w, 'copy' => true]);
                }
                [$left, $right] = $cut($band, $x + $w, 'x');
                if ($left) $out[] = $left;
                if ($right) $out[] = array_merge($right, ['dx' => $right['dx'] + $w]);
            }
            $pieces = array_merge($out, $copies);
        }
        return $pieces;
    }

    // Printed pages after the layout: each source page, and for a page that grew past its bottom (it has a 'break' edit) a second
    // page with what is below the break, moved up to just under the top margin. [['page' => source page, 'from' => y, 'to' => y, 'shift' => y], ...]
    function pdfOutputPages(int $source_pages, array $edits): array {
        $breaks = [];
        foreach ($edits as $e) if ($e['kind'] === 'break') $breaks[(int) $e['page']] = (float) $e['y'];
        $out = [];
        for ($i = 1; $i <= $source_pages; $i++) {
            if (!isset($breaks[$i])) { $out[] = ['page' => $i, 'from' => 0.0, 'to' => 1.0, 'shift' => 0.0]; continue; }
            $b = $breaks[$i];
            $out[] = ['page' => $i, 'from' => 0.0, 'to' => $b, 'shift' => 0.0];
            $out[] = ['page' => $i, 'from' => $b, 'to' => 9.0, 'shift' => $b - 0.05];   // 0.05: NEW_PAGE_TOP in the designer
        }
        return $out;
    }

    // Moves answer boxes and content edits to the printed page they land on (see pdfOutputPages)
    function pdfPlaceOnOutputPages(array $items, array $out_pages): array {
        $placed = [];
        foreach ($items as $item) {
            foreach ($out_pages as $n => $o) {
                $mid = (float) $item['y'] + (float) $item['h'] / 2;
                if ((int) $item['page'] === $o['page'] && $mid >= $o['from'] && $mid < $o['to']) {
                    $placed[] = array_merge($item, ['page' => $n + 1, 'y' => (float) $item['y'] - $o['shift']]);
                    break;
                }
            }
        }
        return $placed;
    }

    // Rebuilds the pages that have inserted rows / columns. $filled: the filled form, flattened (its answers are part of the
    // page); $blank: the empty form, flattened, which copied strips are taken from so no student's answer is ever repeated.
    // Returns the new file, $filled itself when nothing is inserted, or null on failure.
    function relayoutPdfPages(string $filled, string $blank, array $page_sizes, array $edits, string $temp_dir): ?string {
        $by_page = [];
        foreach ($edits as $e) if (in_array($e['kind'], ['row', 'column', 'move'], true)) $by_page[(int) $e['page']][] = $e;
        if (!$by_page && !array_filter($edits, fn($e) => $e['kind'] === 'break')) return $filled;
        try {
            $pdf = new ScholarLinkLayoutPdf('P', 'pt');
            $pdf->SetAutoPageBreak(false);
            $pages = $pdf->setSourceFile($filled);
            $templates = [];
            $template = function (string $file, int $page) use ($pdf, &$templates) {
                if (!isset($templates[$file][$page])) { $pdf->setSourceFile($file); $templates[$file][$page] = $pdf->importPage($page); }
                return $templates[$file][$page];
            };
            foreach (pdfOutputPages($pages, $edits) as $o) {
                $i = $o['page'];
                [$w, $h] = $page_sizes[$i - 1] ?? [612.0, 792.0];
                $pdf->AddPage($w > $h ? 'L' : 'P', [$w, $h]);
                // This printed page shows the laid-out page from 'from' to 'to', moved up by 'shift'
                $top = max(0.0, $o['from'] - $o['shift']);
                $bottom = min(1.0, $o['to'] - $o['shift']);
                foreach (pdfLayoutPieces($by_page[$i] ?? []) as $c) {
                    $y1 = max($c['dy'], $o['from']) - $o['shift'];
                    $y2 = min($c['dy'] + $c['h'], $o['to']) - $o['shift'];
                    $y1 = max($y1, $top); $y2 = min($y2, $bottom);
                    if ($c['dx'] >= 1 || $y2 <= $y1) continue;
                    $pdf->clipRect($c['dx'] * $w, $y1 * $h, $c['w'] * $w, ($y2 - $y1) * $h);
                    $pdf->useTemplate($template($c['copy'] ? $blank : $filled, $i), ($c['dx'] - $c['sx']) * $w, ($c['dy'] - $o['shift'] - $c['sy']) * $h, $w, $h);
                    $pdf->endClip();
                }
            }
            $out = $temp_dir . '/layout_' . uniqid() . '.pdf';
            $pdf->Output('F', $out);
            return is_file($out) ? $out : null;
        } catch (Throwable $e) {
            error_log('PDF layout error: ' . $e->getMessage());
            return null;
        }
    }

    function wrapPdfText(FPDF $pdf, string $text, float $max_width): array {
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && $pdf->GetStringWidth($try) > $max_width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }
}

// FPDI page builder that can limit drawing to a rectangle (used by relayoutPdfPages)
if (!class_exists('ScholarLinkLayoutPdf') && class_exists(\setasign\Fpdi\Fpdi::class)) {
    class ScholarLinkLayoutPdf extends \setasign\Fpdi\Fpdi {
        public function clipRect(float $x, float $y, float $w, float $h): void {
            $this->_out(sprintf('q %.3F %.3F %.3F %.3F re W n', $x * $this->k, ($this->h - $y - $h) * $this->k, $w * $this->k, $h * $this->k));
        }
        public function endClip(): void { $this->_out('Q'); }
    }
}
