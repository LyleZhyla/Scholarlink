<?php
session_start();
require '../includes/db_connect.php';
require_once '../vendor/autoload.php';
require_once '../includes/pdf_templates.php';

use mikehaertl\pdftk\Pdf;

if (!isset($_SESSION['user_id']) || !isset($_GET['app_id'])) {
    exit('Unauthorized access or missing application ID.');
}

$user_id = $_SESSION['user_id'];
$application_id = (int)$_GET['app_id'];

try {
    // 1. FETCH ALL DATA (Added u.Citizenship here)
    $stmt = $pdo->prepare("
        SELECT 
            a.DateSubmitted,
            s.Name AS ScholarshipName, s.FormTemplate,
            u.FirstName, u.LastName, u.MiddleName, u.ExtName, 
            u.StudentID_Num, u.Major, u.YearLevel, u.GPA,
            u.ContactNumber, u.Email, u.DateOfBirth, u.Gender, u.Citizenship,
            u.FatherLastName, u.FatherFirstName, u.FatherMiddleName,
            u.MotherLastName, u.MotherFirstName, u.MotherMiddleName,
            u.StreetBarangay, u.CityMunicipality, u.Province, u.Region, u.ZipCode,
            u.DisabilityType, u.IsSoloParentChild, u.IsFirstGenStudent, u.IndigenousGroup
        FROM application a
        JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
        JOIN users u ON a.UserID = u.UserID
        WHERE a.ApplicationID = :app_id AND a.UserID = :user_id
    ");
    $stmt->execute(['app_id' => $application_id, 'user_id' => $user_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        exit('Application not found or you do not have permission to view it.');
    }
    
    // 2. FETCH DYNAMIC CUSTOM FIELD ANSWERS
    $custom_stmt = $pdo->prepare("
        SELECT scf.PDFFieldName, aca.AnswerText
        FROM application_custom_answers aca
        JOIN scholarship_custom_fields scf ON scf.FieldID = aca.FieldID
        WHERE aca.ApplicationID = :app_id
          AND scf.PDFFieldName IS NOT NULL
          AND scf.PDFFieldName != ''
    ");
    $custom_stmt->execute(['app_id' => $application_id]);
    $custom_answers = $custom_stmt->fetchAll(PDO::FETCH_ASSOC);

    $custom_field_values = [];
    foreach ($custom_answers as $ca) {
        $custom_field_values[$ca['PDFFieldName']] = $ca['AnswerText'];
    }

    // ✨ ANSWERS FOR BOXES DRAWN ON THE PDF IN THE FORM BUILDER (printed with an overlay below)
    $box_stmt = $pdo->prepare("
        SELECT scf.PdfPage, scf.PdfX, scf.PdfY, scf.PdfW, scf.PdfH, scf.PdfFontSize, scf.FieldType, aca.AnswerText
        FROM application_custom_answers aca
        JOIN scholarship_custom_fields scf ON scf.FieldID = aca.FieldID
        WHERE aca.ApplicationID = :app_id AND scf.PdfPage IS NOT NULL
    ");
    $box_stmt->execute(['app_id' => $application_id]);
    $drawn_boxes = array_map(fn($b) => [
        'page' => (int) $b['PdfPage'], 'x' => (float) $b['PdfX'], 'y' => (float) $b['PdfY'],
        'w' => (float) $b['PdfW'], 'h' => (float) $b['PdfH'], 'size' => $b['PdfFontSize'],
        'text' => $b['AnswerText'], 'multiline' => $b['FieldType'] === 'Textarea',
    ], $box_stmt->fetchAll(PDO::FETCH_ASSOC));

    // 3. OFFICIAL PDF FORM: the one chosen in the Form Builder, otherwise the automatic rule
    $template_filename = resolvePdfTemplate($data['FormTemplate'], $data['ScholarshipName']);

    // 4. PROFILE DATA -> PDF FIELDS (see includes/pdf_templates.php)
    $field_map = buildProfilePdfMap($template_filename, $data);

    // 5. MERGE THE STUDENT'S ANSWERS TO THE FORM BUILDER QUESTIONS
    // PDF checkboxes only accept their "on" value: tick them for Yes / any choice, untick for No / blank
    $pdf_checkboxes = array_column(array_filter(getPdfTemplateFields($template_filename), fn($f) => $f['type'] === 'Checkbox'), 'on', 'name');
    foreach ($custom_field_values as $pdf_field => $answer) {
        if (isset($pdf_checkboxes[$pdf_field])) {
            $custom_field_values[$pdf_field] = (trim($answer) === '' || strcasecmp(trim($answer), 'No') === 0) ? 'Off' : $pdf_checkboxes[$pdf_field];
        }
    }
    $field_map = array_merge($field_map, $custom_field_values);

    // ✨ THIS SCHOLARSHIP'S CHANGES TO THE PDF'S OWN FIELDS (PDF designer):
    // hidden fields are left blank; moved fields print their value in the new box instead
    $ovr_stmt = $pdo->prepare("SELECT o.* FROM scholarship_pdf_overrides o JOIN application a ON a.ScholarshipID = o.ScholarshipID WHERE a.ApplicationID = ?");
    $ovr_stmt->execute([$application_id]);
    foreach ($ovr_stmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $value = $field_map[$o['FieldName']] ?? '';
        unset($field_map[$o['FieldName']]);
        if ($o['Action'] !== 'move') continue;

        if (isset($pdf_checkboxes[$o['FieldName']])) {
            $value = ($value !== '' && $value !== 'Off') ? 'X' : '';
        }
        $drawn_boxes[] = [
            'page' => (int) $o['PdfPage'], 'x' => (float) $o['PdfX'], 'y' => (float) $o['PdfY'],
            'w' => (float) $o['PdfW'], 'h' => (float) $o['PdfH'], 'size' => $o['PdfFontSize'],
            'text' => (string) $value, 'multiline' => false,
        ];
    }

    // 6. PROCESS PDF
    $pdf_template_path = realpath(__DIR__ . '/../forms/' . $template_filename);

    if (!$pdf_template_path) { 
        exit("FATAL ERROR: The required PDF template '{$template_filename}' is missing in /forms/ directory.");
    }

    $temp_dir = __DIR__ . '/../temp';
    if (!is_dir($temp_dir)) {
        @mkdir($temp_dir, 0777, true);
    }

    $pdftk_options = [
        'command' => PDFTK_BIN,
        'useExec' => true,
    ];
    $pdf = new Pdf($pdf_template_path, $pdftk_options);

    if (is_dir($temp_dir) && is_writable($temp_dir)) {
        $pdf->tempDir = $temp_dir;
    }

    // Plain / scanned PDFs (e.g. a provider's uploaded form) have no fillable fields: pdftk can't
    // "fill" them, so their answers are only printed in the boxes drawn in the PDF designer
    $has_fillable_fields = (bool) getPdfTemplateFields($template_filename);
    if ($has_fillable_fields) {
        $pdf->fillForm($field_map)->needAppearances();
    }

    // ✨ THIS SCHOLARSHIP'S EDITS TO THE PDF'S PRINTED CONTENT (erased areas, new / replaced text)
    $edit_stmt = $pdo->prepare("SELECT e.* FROM scholarship_pdf_edits e JOIN application a ON a.ScholarshipID = e.ScholarshipID WHERE a.ApplicationID = ? ORDER BY e.SortOrder, e.EditID");
    $edit_stmt->execute([$application_id]);
    $content_edits = array_map(fn($e) => [
        'kind' => $e['Kind'], 'page' => (int) $e['PdfPage'], 'x' => (float) $e['PdfX'], 'y' => (float) $e['PdfY'],
        'w' => (float) $e['PdfW'], 'h' => (float) $e['PdfH'], 'text' => (string) $e['Content'], 'font' => $e['FontFamily'],
        'size' => $e['FontSize'], 'bold' => (bool) $e['IsBold'], 'cover' => (bool) $e['Cover'],
    ], $edit_stmt->fetchAll(PDO::FETCH_ASSOC));

    if ($has_fillable_fields && empty($drawn_boxes) && empty($content_edits)) {
        $result = $pdf->send();
        if ($result === false) {
            exit("PDF Generation Error: " . $pdf->getError());
        }
    } else {
        // Fill the PDF's own fields first (if it has any), then write the drawn-box answers and content edits on top
        $filled_path = $temp_dir . '/filled_' . uniqid() . '.pdf';
        $saved = $has_fillable_fields ? $pdf->saveAs($filled_path) : copy($pdf_template_path, $filled_path);
        if (!$saved) {
            exit("PDF Generation Error: " . ($has_fillable_fields ? $pdf->getError() : 'could not read the PDF form.'));
        }
        $stamped_path = stampPdfBoxes($filled_path, getPdfPageSizes($template_filename), $drawn_boxes, $temp_dir, $content_edits);
        @unlink($filled_path);
        if (!$stamped_path) {
            exit("PDF Generation Error: could not print the answers in the boxes drawn on the PDF.");
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $data['ScholarshipName']) . '_Application.pdf"');
        header('Content-Length: ' . filesize($stamped_path));
        readfile($stamped_path);
        @unlink($stamped_path);
    }

} catch (Exception $e) {
    exit('An error occurred while generating the PDF: ' . $e->getMessage());
}
?>