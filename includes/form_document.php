<?php
// ✨ WORD-STYLE APPLICATION FORM DOCUMENTS
// A scholarship's printed form can be the official PDF (edited in the PDF designer) or a document written like in Word
// (TinyMCE in the form builder: header, body, footer, tables, pictures). The document becomes the PDF with dompdf; its
// answer spots (<span class="sl-field" data-field-id="…"> / data-sys="…") are filled with the student's answers.
require_once __DIR__ . '/form_fields.php';

const FORM_DOCUMENT_PAPERS = ['A4' => [210, 297], 'Letter' => [215.9, 279.4], 'Legal' => [215.9, 355.6]];
// What else can print in the document (besides the questions)
const FORM_DOCUMENT_SYSTEM_FIELDS = [
    'student_name' => 'Student full name', 'student_no' => 'Student number', 'course' => 'Course / program', 'year_level' => 'Year level',
    'email' => 'Email address', 'contact' => 'Contact number', 'scholarship_name' => 'Scholarship name', 'application_no' => 'Application number',
    'date_today' => "Today's date", 'page_number' => 'Page number', 'page_count' => 'Number of pages',
];
const FORM_DOCUMENT_MAX_BYTES = 6000000;   // pictures are kept inside the document

function formDocumentsReady(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try { $pdo->query('SELECT 1 FROM application_form_documents LIMIT 1'); $ready = true; } catch (PDOException $e) { $ready = false; }
    }
    return $ready;
}

function loadFormDocument(PDO $pdo, int $scholarship_id): ?array {
    if (!formDocumentsReady($pdo)) return null;
    try {
        $stmt = $pdo->prepare('SELECT BodyHtml, HeaderHtml, FooterHtml, PaperSize, HeaderHeightMm, FooterHeightMm, LayoutJson FROM application_form_documents WHERE ScholarshipID = ?');
        $stmt->execute([$scholarship_id]);
    } catch (PDOException $e) {   // before the margins were kept
        $stmt = $pdo->prepare('SELECT BodyHtml, HeaderHtml, FooterHtml, PaperSize, HeaderHeightMm, FooterHeightMm FROM application_form_documents WHERE ScholarshipID = ?');
        $stmt->execute([$scholarship_id]);
    }
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    $doc = ['body' => (string) $d['BodyHtml'], 'header' => (string) ($d['HeaderHtml'] ?? ''), 'footer' => (string) ($d['FooterHtml'] ?? ''),
        'paper' => $d['PaperSize'], 'header_mm' => (float) $d['HeaderHeightMm'], 'footer_mm' => (float) $d['FooterHeightMm']];
    $layout = cleanFormDocumentLayout(json_decode((string) ($d['LayoutJson'] ?? ''), true));
    if ($layout) $doc['layout'] = $layout;
    return $doc;
}

// Page margins in mm (top, right, bottom, left) and whether the header runs to the paper's left and right edges
function cleanFormDocumentLayout($layout): ?array {
    if (!is_array($layout) || !isset($layout['margins']) || !is_array($layout['margins']) || count($layout['margins']) !== 4) return null;
    return ['margins' => array_map(fn($v) => round(max(0, min(60, (float) $v)), 1), array_values($layout['margins'])), 'bleed' => !empty($layout['bleed'])];
}

// ---------- cleaning what the editor sends (only formatting survives: no scripts, links or outside files) ----------
function sanitizeFormDocumentHtml(string $html): string {
    if (trim($html) === '') return '';
    $allowed = ['p', 'br', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col', 'img', 'div', 'hr', 'blockquote'];
    $drop = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'svg', 'math', 'template', 'noscript', 'video', 'audio', 'canvas'];
    $attrs = ['style', 'class', 'colspan', 'rowspan', 'width', 'height', 'align', 'valign', 'border', 'cellpadding', 'cellspacing', 'src', 'alt', 'start', 'type', 'data-field-id', 'data-qi', 'data-sys', 'data-label', 'data-pdf-field'];
    $styles = ['color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'vertical-align', 'line-height',
        'width', 'height', 'min-width', 'max-width', 'min-height', 'display', 'border-radius', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style',
        'border-collapse', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'letter-spacing', 'text-indent', 'list-style-type', 'white-space', 'page-break-before', 'page-break-after', 'break-before', 'break-after', 'float'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="sl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $root = $doc->getElementById('sl-root');
    if (!$root) return '';

    $clean = function (DOMNode $node) use (&$clean, $allowed, $drop, $attrs, $styles) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) { $node->removeChild($child); continue; }
            if (!($child instanceof DOMElement)) continue;
            $tag = strtolower($child->tagName);
            if (in_array($tag, $drop, true)) { $node->removeChild($child); continue; }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {   // unknown tags: keep their text
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            foreach (iterator_to_array($child->attributes) as $a) {
                $name = strtolower($a->name);
                $value = $a->value;
                if (!in_array($name, $attrs, true)) { $child->removeAttribute($a->name); continue; }
                if ($name === 'src') {
                    // Pictures only as data kept in the document (never an outside address)
                    if ($tag !== 'img' || !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#', $value)) $child->removeAttribute($a->name);
                } elseif ($name === 'class') {
                    $keep = array_values(array_intersect(preg_split('/\s+/', $value), ['sl-field', 'page-break', 'sl-missing', 'sl-check']));
                    $keep ? $child->setAttribute('class', implode(' ', $keep)) : $child->removeAttribute('class');
                } elseif ($name === 'style') {
                    $kept = [];
                    foreach (explode(';', $value) as $decl) {
                        if (!str_contains($decl, ':')) continue;
                        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
                        $prop = strtolower($prop);
                        if (!in_array($prop, $styles, true) || preg_match('/url\s*\(|expression|javascript|behavior|@import|\\\\/i', $val)) continue;
                        if ($prop === 'display' && !in_array(strtolower($val), ['inline-block', 'block'], true)) continue;
                        $kept[] = $prop . ': ' . $val;
                    }
                    $kept ? $child->setAttribute('style', implode('; ', $kept)) : $child->removeAttribute('style');
                } elseif (in_array($name, ['data-field-id', 'data-qi'], true)) {
                    if (!ctype_digit($value)) $child->removeAttribute($a->name);
                } elseif ($name === 'data-pdf-field') {
                    if (!preg_match('/^[\w .()\/#:\[\]-]{1,100}$/u', $value)) $child->removeAttribute($a->name);
                } elseif ($name === 'data-sys') {
                    if (!isset(FORM_DOCUMENT_SYSTEM_FIELDS[$value])) $child->removeAttribute($a->name);
                } elseif (preg_match('/^\s*(javascript|vbscript|data):/i', $value)) {
                    $child->removeAttribute($a->name);
                }
            }
        }
    };
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

// Answer spots saved as data-qi (the question's place in the form) become data-field-id (its id once saved)
function formDocumentLinkFields(string $html, array $field_ids): string {
    if (!str_contains($html, 'data-qi')) return $html;
    return preg_replace_callback('/data-qi="(\d+)"/', function ($m) use ($field_ids) {
        $i = (int) $m[1];
        return isset($field_ids[$i]) ? 'data-field-id="' . (int) $field_ids[$i] . '"' : 'data-field-id="0"';
    }, $html);
}

// ---------- printing ----------
// $values: FieldID => answer; $system: data-sys => value; $sample: show the answer spots' names instead (a preview)
function formDocumentFill(string $html, array $values, array $system, array $labels, bool $sample): string {
    if ($html === '' || !str_contains($html, 'sl-field')) return $html;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="sl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//span[contains(concat(" ", normalize-space(@class), " "), " sl-field ")]') as $span) {
        $sys = $span->getAttribute('data-sys');
        $fid = (int) $span->getAttribute('data-field-id');
        $pdf_field = $span->getAttribute('data-pdf-field');   // a blank of the official PDF the document was made from
        while ($span->firstChild) $span->removeChild($span->firstChild);
        if ($sys === 'page_number' || $sys === 'page_count') {
            $span->setAttribute('class', $sys === 'page_number' ? 'sl-page' : 'sl-pages');   // filled by dompdf on each page
            continue;
        }
        if ($sample) {
            $name = $pdf_field !== '' ? ($span->getAttribute('data-label') ?: ($labels['pdf:' . $pdf_field] ?? ucwords(trim(preg_replace('/[_\s]+/', ' ', $pdf_field)))))
                : ($sys !== '' ? (FORM_DOCUMENT_SYSTEM_FIELDS[$sys] ?? $sys) : ($labels[$fid] ?? 'Removed question'));
            $span->appendChild($doc->createTextNode('[' . $name . ']'));
            $span->setAttribute('class', 'sl-answer sl-sample');
            continue;
        }
        $value = $pdf_field !== '' ? (string) ($values['pdf:' . $pdf_field] ?? '') : ($sys !== '' ? (string) ($system[$sys] ?? '') : (string) ($values[$fid] ?? ''));
        if (str_contains(' ' . $span->getAttribute('class') . ' ', ' sl-check ')) {
            // a checkbox: X when ticked, a little space when not (no answer line)
            $span->appendChild($doc->createTextNode($value !== '' ? 'X' : "\u{00A0}\u{00A0}\u{00A0}"));
            $span->setAttribute('class', 'sl-tick');
            continue;
        }
        $span->appendChild($doc->createTextNode($value !== '' ? $value : "\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}\u{00A0}"));
        $span->setAttribute('class', 'sl-answer');
    }
    $out = '';
    foreach ($doc->getElementById('sl-root')->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

// Column widths set in the editor (<colgroup><col style="width: …">) go on the cells, which is what the PDF follows
function formDocumentColumnWidths(string $html): string {
    if (!str_contains($html, '<colgroup')) return $html;
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="sl-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//table[colgroup]') as $table) {
        $widths = [];
        foreach ($xp->query('./colgroup/col', $table) as $col) $widths[] = preg_match('/width\s*:\s*([\d.]+(?:%|px|pt|mm|cm|in))/i', $col->getAttribute('style'), $m) ? $m[1] : null;
        foreach ($xp->query('./tr | ./thead/tr | ./tbody/tr | ./tfoot/tr', $table) as $tr) {
            $at = 0;
            foreach ($xp->query('./td | ./th', $tr) as $cell) {
                $span = max(1, (int) $cell->getAttribute('colspan'));
                $w = $widths[$at] ?? null;
                if ($span === 1 && $w && !preg_match('/(^|;)\s*width\s*:/i', $cell->getAttribute('style'))) $cell->setAttribute('style', trim('width: ' . $w . '; ' . $cell->getAttribute('style')));
                $at += $span;
            }
        }
        foreach ($xp->query('./colgroup', $table) as $cg) $table->removeChild($cg);
    }
    $out = '';
    foreach ($doc->getElementById('sl-root')->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

function formDocumentHtml(array $d, array $values, array $system, array $labels, bool $sample): string {
    [$pw, $ph] = FORM_DOCUMENT_PAPERS[$d['paper']] ?? FORM_DOCUMENT_PAPERS['A4'];
    $hasHeader = trim(strip_tags($d['header'], '<img><table>')) !== '';
    $hasFooter = trim(strip_tags($d['footer'], '<img><table>')) !== '';
    // Measured on screen; the PDF's letters run a little taller, so a little more room
    $grow = isset($d['layout']) ? fn($v) => $v : fn($v) => $v * 1.15 + 3;
    $hh = $hasHeader ? max(8, min(130, $grow((float) ($d['header_mm'] ?? 20)))) : 0;
    $fh = $hasFooter ? max(6, min(90, $grow((float) ($d['footer_mm'] ?? 12)))) : 0;
    [$mt, $mr, $mb, $ml] = $d['layout']['margins'] ?? [15, 15, 15, 15];
    $bleed = !empty($d['layout']['bleed']);
    $gap = isset($d['layout']) ? 0 : 4;   // between the header / footer and the page (a copied PDF has its own spacing, measured from where they are)
    $top = $mt + $hh + ($hasHeader ? $gap : 0);
    $bottom = $mb + $fh + ($hasFooter ? $gap : 0);
    $hl = $bleed ? -$ml : 0;
    $hr = $bleed ? -$mr : 0;
    $css = "@page { size: {$pw}mm {$ph}mm; margin: {$top}mm {$mr}mm {$bottom}mm {$ml}mm; }
        body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; line-height: 1.25; color: #000; margin: 0; }
        p { margin: 0 0 6pt 0; } h1 { font-size: 20pt; margin: 0 0 8pt; } h2 { font-size: 16pt; margin: 0 0 6pt; } h3 { font-size: 13pt; margin: 0 0 6pt; }
        h4, h5, h6 { font-size: 11pt; margin: 0 0 4pt; } ul, ol { margin: 0 0 6pt 0; padding-left: 18pt; }
        table { border-collapse: collapse; max-width: 100%; } td, th { padding: 2pt 4pt; vertical-align: top; word-wrap: break-word; }
        table[border=\"1\"] td, table[border=\"1\"] th { border: 0.75pt solid #000; }
        img { max-width: 100%; } .page-break { page-break-after: always; height: 0; }
        .sl-answer { border-bottom: 0.6pt solid #000; padding: 0 2pt; font-family: Helvetica, Arial, sans-serif; font-size: 0.85em; }
        .sl-tick { font-family: Helvetica, Arial, sans-serif; font-weight: bold; padding: 0 1pt; }
        hr { border: 0; border-top: 0.75pt solid #888; margin: 3pt 0; }
        .sl-sample { color: #2563eb; border-bottom-style: dashed; }
        .sl-page:after { content: counter(page); } .sl-pages:after { content: counter(pages); }
        #sl-header { position: fixed; left: {$hl}mm; right: {$hr}mm; top: -" . ($hh + $gap) . "mm; height: {$hh}mm; }
        #sl-footer { position: fixed; left: 0; right: 0; bottom: -" . ($fh + $gap) . "mm; height: {$fh}mm; }";
    $fill = fn($h) => formDocumentColumnWidths(formDocumentFill($h, $values, $system, $labels, $sample));
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>'
        . ($hasHeader ? '<div id="sl-header">' . $fill($d['header']) . '</div>' : '')
        . ($hasFooter ? '<div id="sl-footer">' . $fill($d['footer']) . '</div>' : '')
        . '<div id="sl-body">' . $fill($d['body']) . '</div></body></html>';
}

function formDocumentPdf(array $d, array $values, array $system, array $labels, bool $sample): string {
    $make = function (string $html) {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);        // nothing is fetched from outside
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Times');
        $options->set('chroot', realpath(__DIR__ . '/..'));
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();
        return $dompdf;
    };
    $html = formDocumentHtml($d, $values, $system, $labels, $sample);
    $dompdf = $make($html);
    // "Page 2 of 3": the number of pages is only known once it is laid out, so it is laid out again with it
    if (str_contains($html, 'sl-pages')) {
        $count = $dompdf->getCanvas()->get_page_count();
        $dompdf = $make(preg_replace('#<span class="sl-pages"[^>]*></span>#', '<span>' . $count . '</span>', $html));
    }
    return $dompdf->output();
}

// The question names, for a preview's answer spots
function formDocumentLabels(PDO $pdo, int $scholarship_id): array {
    $labels = [];
    foreach (getFormFields($pdo, $scholarship_id, false) as $f) $labels[(int) $f['FieldID']] = $f['FieldName'];
    return $labels;
}
