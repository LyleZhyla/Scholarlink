<?php
// ✨ DYNAMIC APPLICATION FORMS
// Shared by the Form Builder (admins), the student application pages, and the save handlers.
// Questions live in scholarship_custom_fields; answers in application_custom_answers.

if (!defined('FORM_FIELD_TYPES')) {
    // type => [label shown in the builder, Font Awesome icon, needs a list of choices?]
    define('FORM_FIELD_TYPES', [
        'Section'  => ['Section Heading', 'fa-heading', false],
        'Text'     => ['Short Answer', 'fa-font', false],
        'Textarea' => ['Paragraph', 'fa-align-left', false],
        'Number'   => ['Number', 'fa-hashtag', false],
        'Date'     => ['Date', 'fa-calendar-day', false],
        'Email'    => ['Email', 'fa-at', false],
        'Phone'    => ['Phone Number', 'fa-phone', false],
        'Select'   => ['Dropdown', 'fa-square-caret-down', true],
        'Radio'    => ['Multiple Choice', 'fa-circle-dot', true],
        'Checkbox' => ['Checkboxes', 'fa-square-check', true],
        'YesNo'    => ['Yes / No', 'fa-toggle-on', false],
    ]);
}

if (!function_exists('getFormFields')) {

    // Profile values that "Auto: ..." questions can pre-fill
    function getAutoFillTypes(PDO $pdo): array {
        $types = [
            'Auto_FirstName'     => 'First Name',
            'Auto_LastName'      => 'Last Name',
            'Auto_MiddleName'    => 'Middle Name',
            'Auto_StudentNumber' => 'Student Number',
            'Auto_Email'         => 'Email Address',
            'Auto_Program'       => 'Course / Program',
            'Auto_YearLevel'     => 'Year Level',
            'Auto_Gender'        => 'Gender',
            'Auto_Contact'       => 'Contact Number',
            'Auto_DOB'           => 'Date of Birth',
            'Auto_Address'       => 'Home Address',
            'Auto_Religion'      => 'Religion',
            'Auto_Income'        => 'Family Income',
        ];
        try {
            foreach ($pdo->query("SELECT FieldID, FieldName FROM user_profile_fields ORDER BY FieldName ASC") as $cpf) {
                $types['Auto_Custom_' . $cpf['FieldID']] = $cpf['FieldName'];
            }
        } catch (PDOException $e) { }
        return $types;
    }

    function isAutoFillType(string $type): bool {
        return strpos($type, 'Auto_') === 0;
    }

    function fieldTypeNeedsOptions(string $type): bool {
        return !empty(FORM_FIELD_TYPES[$type][2]);
    }

    // "Option A\nOption B" -> ['Option A', 'Option B']
    function parseFieldOptions(?string $options): array {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $options)), 'strlen'));
    }

    // Questions of a scholarship's form, in display order
    function getFormFields(PDO $pdo, $scholarship_id, bool $active_only = true): array {
        $stmt = $pdo->prepare("SELECT * FROM scholarship_custom_fields WHERE ScholarshipID = ?" . ($active_only ? " AND IsActive = 1" : "") . " ORDER BY SortOrder ASC, FieldID ASC");
        $stmt->execute([$scholarship_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Value a profile auto-fill question starts with
    function resolveAutoFillValue(PDO $pdo, string $type, array $student): string {
        if (strpos($type, 'Auto_Custom_') === 0) {
            $stmt = $pdo->prepare("SELECT AnswerText FROM user_profile_answers WHERE UserID = ? AND FieldID = ?");
            $stmt->execute([$student['UserID'], (int) substr($type, strlen('Auto_Custom_'))]);
            return (string) ($stmt->fetchColumn() ?: '');
        }
        if ($type === 'Auto_Program') {
            $stmt = $pdo->prepare("SELECT ProgramName FROM program WHERE ProgramID = ?");
            $stmt->execute([$student['ProgramID'] ?? 0]);
            return (string) ($stmt->fetchColumn() ?: ($student['Major'] ?? ''));
        }
        if ($type === 'Auto_Address') {
            return trim(implode(', ', array_filter([$student['StreetBarangay'] ?? '', $student['CityMunicipality'] ?? '', $student['Province'] ?? ''])));
        }
        $columns = [
            'Auto_FirstName' => 'FirstName', 'Auto_LastName' => 'LastName', 'Auto_MiddleName' => 'MiddleName',
            'Auto_StudentNumber' => 'StudentID_Num', 'Auto_Email' => 'Email', 'Auto_YearLevel' => 'YearLevel',
            'Auto_Gender' => 'Gender', 'Auto_Contact' => 'ContactNumber', 'Auto_DOB' => 'DateOfBirth',
        ];
        return (string) ($student[$columns[$type] ?? ''] ?? '');
    }

    // HTML for one question on the student form. $value = previous answer (or auto-fill value).
    function renderFormField(array $field, string $value = ''): string {
        $id = (int) $field['FieldID'];
        $type = $field['FieldType'];
        $label = htmlspecialchars($field['FieldName']);
        $help = trim((string) ($field['HelpText'] ?? ''));
        $placeholder = htmlspecialchars((string) ($field['Placeholder'] ?? ''));
        $required = !empty($field['IsRequired']) && $type !== 'Section';
        $req = $required ? ' required' : '';
        $name = "custom_answers[$id]";
        $v = htmlspecialchars($value);
        $input = 'w-full p-4 bg-slate-50 border border-slate-200 rounded-xl font-bold outline-none focus:border-blue-500 focus:bg-white transition-colors';

        if ($type === 'Section') {
            return '<div class="pt-2"><h4 class="text-base font-black text-slate-900">' . $label . '</h4>'
                . ($help !== '' ? '<p class="text-sm text-slate-500 mt-1">' . htmlspecialchars($help) . '</p>' : '')
                . '<div class="h-px bg-slate-100 mt-3"></div></div>';
        }

        $html = '<div class="space-y-2" data-field-id="' . $id . '">';
        $html .= '<label class="text-[11px] font-black text-slate-500 uppercase tracking-widest block">' . $label
            . ($required ? ' <span class="text-red-500">*</span>' : ' <span class="text-slate-300 normal-case tracking-normal font-bold">(optional)</span>') . '</label>';
        if ($help !== '') {
            $html .= '<p class="text-xs text-slate-400 font-medium -mt-1">' . htmlspecialchars($help) . '</p>';
        }

        if (isAutoFillType($type)) {
            $html .= '<input type="text" name="' . $name . '" value="' . $v . '" placeholder="' . ($placeholder ?: 'Type your answer here...') . '"' . $req
                . ' class="w-full p-4 bg-blue-50 focus:bg-white border border-blue-200 focus:border-blue-500 rounded-xl font-bold text-blue-800 focus:text-slate-900 outline-none transition-colors">'
                . '<p class="text-[10px] font-bold text-blue-500 uppercase tracking-widest">⚡ Filled from your profile — you can edit it</p>';
        } elseif ($type === 'Textarea') {
            $html .= '<textarea name="' . $name . '" rows="4" placeholder="' . $placeholder . '"' . $req . ' class="' . $input . ' text-sm font-medium">' . $v . '</textarea>';
        } elseif (in_array($type, ['Number', 'Date', 'Email', 'Phone'], true)) {
            $html_type = ['Number' => 'number" step="any', 'Date' => 'date', 'Email' => 'email', 'Phone' => 'tel'][$type];
            $html .= '<input type="' . $html_type . '" name="' . $name . '" value="' . $v . '" placeholder="' . $placeholder . '"' . $req . ' class="' . $input . '">';
        } elseif ($type === 'Select') {
            $html .= '<select name="' . $name . '"' . $req . ' class="' . $input . ' cursor-pointer"><option value="">' . ($placeholder ?: 'Select an option...') . '</option>';
            foreach (parseFieldOptions($field['Options'] ?? '') as $opt) {
                $o = htmlspecialchars($opt);
                $html .= '<option value="' . $o . '"' . ($opt === $value ? ' selected' : '') . '>' . $o . '</option>';
            }
            $html .= '</select>';
        } elseif ($type === 'Radio' || $type === 'YesNo') {
            $choices = $type === 'YesNo' ? ['Yes', 'No'] : parseFieldOptions($field['Options'] ?? '');
            $html .= '<div class="grid gap-2 ' . ($type === 'YesNo' ? 'grid-cols-2' : 'sm:grid-cols-2') . '">';
            foreach ($choices as $i => $opt) {
                $o = htmlspecialchars($opt);
                $html .= '<label class="flex items-center gap-3 p-3.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-400 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition-colors">'
                    . '<input type="radio" name="' . $name . '" value="' . $o . '"' . ($opt === $value ? ' checked' : '') . ($required && $i === 0 ? ' required' : '') . ' class="w-4 h-4 accent-blue-600">'
                    . '<span class="text-sm font-bold text-slate-700">' . $o . '</span></label>';
            }
            $html .= '</div>';
        } elseif ($type === 'Checkbox') {
            $picked = array_map('trim', explode(',', $value));
            $html .= '<div class="grid gap-2 sm:grid-cols-2"' . ($required ? ' data-required-group="1"' : '') . '>';
            foreach (parseFieldOptions($field['Options'] ?? '') as $opt) {
                $o = htmlspecialchars($opt);
                $html .= '<label class="flex items-center gap-3 p-3.5 bg-slate-50 border border-slate-200 rounded-xl cursor-pointer hover:border-blue-400 has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50 transition-colors">'
                    . '<input type="checkbox" name="' . $name . '[]" value="' . $o . '"' . (in_array($opt, $picked, true) ? ' checked' : '') . ' class="w-4 h-4 accent-blue-600 rounded">'
                    . '<span class="text-sm font-bold text-slate-700">' . $o . '</span></label>';
            }
            $html .= '</div>';
            if ($required) {
                $html .= '<p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Select at least one</p>';
            }
        } else {
            $html .= '<input type="text" name="' . $name . '" value="' . $v . '" placeholder="' . $placeholder . '"' . $req . ' class="' . $input . '">';
        }

        return $html . '</div>';
    }

    // HTML for a whole form (instructions + every question)
    function renderApplicationForm(PDO $pdo, array $fields, array $student, array $answers = [], ?string $instructions = null): string {
        $html = '';
        if (trim((string) $instructions) !== '') {
            $html .= '<div class="p-4 bg-blue-50 border border-blue-100 rounded-xl text-sm text-blue-900 font-medium whitespace-pre-line">ℹ️ '
                . htmlspecialchars($instructions) . '</div>';
        }
        foreach ($fields as $field) {
            $value = $answers[$field['FieldID']] ?? (isAutoFillType($field['FieldType']) ? resolveAutoFillValue($pdo, $field['FieldType'], $student) : '');
            $html .= renderFormField($field, (string) $value);
        }
        return $html;
    }

    // Checks the submitted answers against the form. Returns [answers by FieldID, list of error messages].
    function collectFormAnswers(array $fields, $posted): array {
        $posted = is_array($posted) ? $posted : [];
        $answers = [];
        $errors = [];

        foreach ($fields as $field) {
            $id = $field['FieldID'];
            $type = $field['FieldType'];
            if ($type === 'Section') continue;

            $raw = $posted[$id] ?? '';
            $label = $field['FieldName'];
            $options = $type === 'YesNo' ? ['Yes', 'No'] : parseFieldOptions($field['Options'] ?? '');

            if ($type === 'Checkbox') {
                $picked = array_values(array_intersect(is_array($raw) ? array_map('trim', $raw) : [], $options));
                $value = implode(', ', $picked);
            } else {
                $value = is_array($raw) ? '' : trim((string) $raw);
            }

            if ($value === '') {
                if (!empty($field['IsRequired'])) $errors[] = "\"$label\" is required.";
                continue;
            }

            if (in_array($type, ['Select', 'Radio', 'YesNo'], true) && !in_array($value, $options, true)) {
                $errors[] = "Please choose a valid option for \"$label\".";
            } elseif ($type === 'Number' && !is_numeric($value)) {
                $errors[] = "\"$label\" must be a number.";
            } elseif ($type === 'Email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "\"$label\" must be a valid email address.";
            } elseif ($type === 'Date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $errors[] = "\"$label\" must be a valid date.";
            }

            $answers[$id] = $value;
        }

        return [$answers, $errors];
    }
}
