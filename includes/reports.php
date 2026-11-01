<?php
// ✨ SYSTEM REPORTS
// The four reports (scholars per scholarship, scholarships available, all applicants, all scholars),
// their filters (academic year, semester, gender, citizenship, region, municipality, status),
// summary counts, and each sponsor's own report format. Used by the System Reports page and
// actions/export_report.php (Excel / PDF).

require_once __DIR__ . '/application_authority.php';

if (!function_exists('runReport')) {

    define('REPORT_TYPES', [
        'scholars_per_scholarship' => 'List of Scholars per Scholarship',
        'scholarships'             => 'List of Scholarships Available',
        'applicants'               => 'List of All Scholarship Applicants / Applications',
        'scholars'                 => 'List of All Scholars',
    ]);

    define('REPORT_STATUS_FILTERS', [
        ''            => 'All statuses',
        'Pending'     => 'Pending / Under review',
        'Shortlisted' => 'Shortlisted',
        'Approved'    => 'Approved (scholars)',
        'Rejected'    => 'Rejected',
    ]);

    // Every column a student-based report can show: key => label
    define('REPORT_COLUMNS', [
        'no' => 'No.', 'student_id' => 'Student ID No.', 'full_name' => 'Full Name (Last, First Middle)',
        'last_name' => 'Last Name', 'first_name' => 'First Name', 'middle_name' => 'Middle Name', 'ext_name' => 'Ext. Name',
        'sex' => 'Sex', 'birthdate' => 'Date of Birth', 'age' => 'Age', 'citizenship' => 'Citizenship',
        'program' => 'Degree Program', 'year_level' => 'Year Level', 'gwa' => 'GWA',
        'street' => 'Street / Barangay', 'municipality' => 'City / Municipality', 'province' => 'Province', 'region' => 'Region',
        'zip' => 'ZIP Code', 'address' => 'Complete Address', 'contact' => 'Contact No.', 'email' => 'Email Address',
        'father' => "Father's Name", 'father_last' => "Father's Last Name", 'father_first' => "Father's First Name", 'father_middle' => "Father's Middle Name",
        'mother' => "Mother's Maiden Name", 'mother_last' => "Mother's Maiden Last Name", 'mother_first' => "Mother's First Name", 'mother_middle' => "Mother's Middle Name",
        'units' => 'Units Enrolled',
        'lrn' => 'LRN', 'philsys' => 'PhilSys ID', 'fourps' => '4Ps ID No.',
        'disability' => 'Disability', 'indigenous' => 'Indigenous Group', 'solo_parent' => 'Child of Solo Parent', 'first_gen' => 'First-Gen Student',
        'scholarship' => 'Scholarship', 'sponsor' => 'Sponsor / Provider', 'category' => 'Category', 'type' => 'Type',
        'award' => 'Award Amount', 'frequency' => 'Release',
        'status' => 'Application Status', 'date_applied' => 'Date Applied', 'academic_year' => 'Academic Year', 'semester' => 'Semester',
        'score' => 'Evaluation Score',
    ]);

    // The registration form's sections, for the "Information to include" picker
    define('REPORT_COLUMN_GROUPS', [
        'Personal Information'      => ['no', 'student_id', 'full_name', 'last_name', 'first_name', 'middle_name', 'ext_name', 'sex', 'birthdate', 'age', 'citizenship'],
        'Identification Numbers'    => ['lrn', 'philsys', 'fourps'],
        'Contact & Address'         => ['contact', 'email', 'street', 'municipality', 'province', 'region', 'zip', 'address'],
        'Academic Information'      => ['program', 'year_level', 'gwa', 'units'],
        'Family Background'         => ['father', 'father_last', 'father_first', 'father_middle', 'mother', 'mother_last', 'mother_first', 'mother_middle'],
        'Special Status'            => ['disability', 'indigenous', 'solo_parent', 'first_gen'],
        'Scholarship & Application' => ['scholarship', 'sponsor', 'category', 'type', 'award', 'frequency', 'status', 'date_applied', 'academic_year', 'semester', 'score'],
    ]);

    // Columns of the "List of Scholarships Available" report
    define('SCHOLARSHIP_REPORT_COLUMNS', [
        'no' => 'No.', 'scholarship' => 'Scholarship', 'sponsor' => 'Sponsor / Provider', 'category' => 'Category', 'type' => 'Type',
        'award' => 'Award Amount', 'frequency' => 'Release', 'slots' => 'Slots', 'scholars' => 'Scholars', 'remaining' => 'Slots Left',
        'applicants' => 'Applicants', 'min_gwa' => 'Min. GWA', 'year_level' => 'Year Level', 'programs' => 'Eligible Programs',
        'deadline' => 'Deadline', 'status' => 'Status',
    ]);

    // Standard (ScholarLink) layouts, used when a sponsor hasn't set its own
    define('REPORT_STANDARD_COLUMNS', [
        'scholars_per_scholarship' => ['no', 'student_id', 'full_name', 'sex', 'program', 'year_level', 'gwa', 'municipality', 'province', 'region', 'contact', 'email'],
        'applicants'               => ['no', 'student_id', 'full_name', 'sex', 'program', 'year_level', 'scholarship', 'status', 'date_applied', 'semester', 'municipality'],
        'scholars'                 => ['no', 'student_id', 'full_name', 'sex', 'program', 'year_level', 'scholarship', 'sponsor', 'award', 'municipality', 'region'],
    ]);

    // Breakdowns shown under every student-based report
    define('REPORT_SUMMARY_DIMENSIONS', ['sex' => 'Sex', 'citizenship' => 'Citizenship', 'region' => 'Region', 'municipality' => 'City / Municipality']);

    // ---------- Terms: "1st Semester AY 2026-2027" -> AY 2026-2027, 1st Semester ----------
    function parseTermName(string $name): array {
        $ay = preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $name, $m) ? "{$m[1]}-{$m[2]}" : '';
        $sem = 'Other';
        if (preg_match('/\b(1st|first)\b/i', $name)) $sem = '1st Semester';
        elseif (preg_match('/\b(2nd|second)\b/i', $name)) $sem = '2nd Semester';
        elseif (preg_match('/mid\s*-?\s*year|summer/i', $name)) $sem = 'Midyear / Summer';
        return ['ay' => $ay, 'semester' => $sem];
    }

    function reportTerms(PDO $pdo): array {
        $terms = [];
        foreach ($pdo->query("SELECT TermID, TermName, Status FROM academic_terms ORDER BY TermID") as $t) {
            $terms[(int) $t['TermID']] = $t + parseTermName($t['TermName']);
        }
        return $terms;
    }

    // Term IDs matching the chosen academic year / semester (null = no term filter)
    function reportTermIds(array $terms, string $ay, string $semester): ?array {
        if ($ay === '' && $semester === '') return null;
        $ids = array_keys(array_filter($terms, fn($t) => ($ay === '' || $t['ay'] === $ay) && ($semester === '' || $t['semester'] === $semester)));
        return $ids ?: [0];
    }

    // ---------- Who may report on what ----------
    // Scholarships the admin may report on: providers see their own, OSSD / Super Admin see all
    function reportScholarships(PDO $pdo, string $role, int $user_id): array {
        $sql = "SELECT s.ScholarshipID, s.Name, s.Status, s.CreatedBy,
                       COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS Provider
                FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy";
        $params = [];
        if ($role === 'External_Admin') { $sql .= " WHERE s.CreatedBy = ?"; $params[] = $user_id; }
        $stmt = $pdo->prepare($sql . " ORDER BY s.Name");
        $stmt->execute($params);
        $list = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $s['IsTulongDunong'] = isTulongDunong($s['Name']);
            $list[(int) $s['ScholarshipID']] = $s;
        }
        return $list;
    }

    // The CHED Annex 1 template belongs to Tulong Dunong (TDP / TES) only
    function isTulongDunong(string $scholarship_name): bool {
        return stripos($scholarship_name, 'Tulong Dunong') !== false || preg_match('/\bTES\b|Tertiary Educational Subsidy/i', $scholarship_name) === 1;
    }

    function reportFilterOptions(PDO $pdo): array {
        $distinct = fn($col) => $pdo->query("SELECT DISTINCT TRIM($col) v FROM users WHERE Role = 'Student' AND $col IS NOT NULL AND TRIM($col) <> '' ORDER BY v")->fetchAll(PDO::FETCH_COLUMN);
        $terms = reportTerms($pdo);
        $years = array_values(array_unique(array_filter(array_column($terms, 'ay'))));
        rsort($years);
        return [
            'years' => $years,
            'semesters' => ['1st Semester', '2nd Semester', 'Midyear / Summer'],
            'genders' => $distinct('Gender'),
            'citizenships' => $distinct('Citizenship'),
            'regions' => $distinct('Region'),
            'municipalities' => $distinct('CityMunicipality'),
        ];
    }

    // ---------- Sponsor report formats ----------
    // Columns a report can use: the catalogue, extra profile fields admins added (p<ID>),
    // and, for one scholarship, its own application questions (q<ID>)
    function reportAvailableColumns(PDO $pdo, ?int $scholarship_id): array {
        $columns = REPORT_COLUMNS;
        try {
            foreach ($pdo->query("SELECT FieldID, FieldName FROM user_profile_fields ORDER BY FieldName") as $p) $columns['p' . $p['FieldID']] = $p['FieldName'];
        } catch (PDOException $e) { }
        if ($scholarship_id) {
            $stmt = $pdo->prepare("SELECT FieldID, FieldName FROM scholarship_custom_fields WHERE ScholarshipID = ? AND FieldType <> 'Section' ORDER BY IsActive DESC, SortOrder, FieldID");
            $stmt->execute([$scholarship_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $q) $columns['q' . $q['FieldID']] = $q['FieldName'];
        }
        return $columns;
    }

    // The scholarship's own format, or null when it uses the standard one
    function getSponsorReportFormat(PDO $pdo, int $scholarship_id): ?array {
        $stmt = $pdo->prepare("SELECT * FROM scholarship_report_formats WHERE ScholarshipID = ?");
        $stmt->execute([$scholarship_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['Columns'] = json_decode($row['Columns'], true) ?: [];
        return $row;
    }

    // The report's title, saying exactly what it lists: status, scholarship and term chosen
    // e.g. "List of Pending Scholarship Applicants — KOICA-KOREA (1st Semester AY 2026-2027)"
    function reportTitle(string $type, array $f, array $scope, int $sid): string {
        $status = (string) ($f['status'] ?? '');
        $title = match ($type) {
            'scholars' => $sid ? 'List of Scholars' : 'List of All Scholars',
            'scholars_per_scholarship' => $sid ? 'List of Scholars' : 'List of Scholars per Scholarship',
            'applicants' => match ($status) {
                'Pending'     => 'List of Pending Scholarship Applicants',
                'Shortlisted' => 'List of Shortlisted Scholarship Applicants',
                'Approved'    => 'List of Approved Scholarship Applicants',
                'Rejected'    => 'List of Rejected Scholarship Applicants',
                default       => $sid ? 'List of Scholarship Applicants' : 'List of All Scholarship Applicants / Applications',
            },
            'scholarships' => !empty($f['include_closed']) ? 'List of All Scholarships (Open and Closed)' : 'List of Scholarships Available',
            default => REPORT_TYPES[$type] ?? 'Report',
        };
        if ($sid && isset($scope[$sid])) $title .= ' — ' . $scope[$sid]['Name'];
        $term = trim(($f['semester'] ?? '') . (($f['ay'] ?? '') !== '' ? ' AY ' . $f['ay'] : ''));
        if ($term !== '' && $type !== 'scholarships') $title .= " ($term)";
        return $title;
    }

    // ---------- Running a report ----------
    // $f: type, scholarship_id, ay, semester, gender, citizenship, region, municipality, status, include_closed
    function runReport(PDO $pdo, array $f, string $role, int $user_id): array {
        $type = isset(REPORT_TYPES[$f['type'] ?? '']) ? $f['type'] : 'scholars';
        $scope = reportScholarships($pdo, $role, $user_id);
        $sid = (int) ($f['scholarship_id'] ?? 0);
        if ($sid && !isset($scope[$sid])) throw new RuntimeException('You can only report on your own scholarships.');

        // ALL scholarships, for scholars per scholarship and for applicants: one section per scholarship
        // (a new PDF page / Excel sheet each); scholars per scholarship use each sponsor's own format
        if (in_array($type, ['scholars_per_scholarship', 'applicants'], true) && !$sid) {
            $combined = null;
            $empty = [];
            foreach ($scope as $id => $s) {
                $part = runReport($pdo, ['scholarship_id' => $id] + $f, $role, $user_id);
                $combined = $combined ?? array_merge($part, ['sections' => [], 'summary' => [], 'scholarship' => null, 'title' => reportTitle($type, $f, $scope, 0), 'subtitle' => '',
                    'format' => $type === 'scholars_per_scholarship' ? 'Each sponsor\'s format' : 'One section per scholarship']);
                if (!$part['sections'][0]['rows']) { $empty[] = $s['Name']; continue; }
                $combined['sections'][] = $part['sections'][0];
                foreach ($part['summary'] as $dim => $counts) {
                    foreach ($counts as $v => $n) $combined['summary'][$dim][$v] = ($combined['summary'][$dim][$v] ?? 0) + $n;
                }
                $combined['summary']['Scholarship'][$s['Name']] = count($part['sections'][0]['rows']);
            }
            $combined = $combined ?? ['type' => $type, 'title' => reportTitle($type, $f, $scope, 0), 'subtitle' => '', 'prepared_by' => '', 'filters' => [], 'sections' => [], 'summary' => [], 'scholarship' => null, 'format' => 'Standard'];
            foreach ($combined['summary'] as &$counts) arsort($counts);
            $combined['empty_scholarships'] = $empty;
            return $combined;
        }

        $terms = reportTerms($pdo);
        $term_ids = reportTermIds($terms, (string) ($f['ay'] ?? ''), (string) ($f['semester'] ?? ''));
        $filter_labels = array_filter([
            ($f['ay'] ?? '') !== '' ? 'AY ' . $f['ay'] : '', $f['semester'] ?? '', $f['gender'] ?? '',
            ($f['citizenship'] ?? '') !== '' ? 'Citizenship: ' . $f['citizenship'] : '',
            ($f['region'] ?? '') !== '' ? 'Region: ' . $f['region'] : '',
            ($f['municipality'] ?? '') !== '' ? 'Municipality: ' . $f['municipality'] : '',
            ($type === 'applicants' && ($f['status'] ?? '') !== '') ? 'Status: ' . REPORT_STATUS_FILTERS[$f['status']] : '',
        ]);

        $report = ['type' => $type, 'title' => reportTitle($type, $f, $scope, $sid), 'subtitle' => '', 'prepared_by' => '', 'filters' => $filter_labels,
            'columns' => [], 'rows' => [], 'summary' => [], 'scholarship' => $sid ? $scope[$sid] : null, 'format' => 'Standard'];

        if ($type === 'scholarships') return withReportSections(buildScholarshipsReport($pdo, $report, array_keys($scope), $sid, !empty($f['include_closed']), reportSelectedColumns($f)));

        // ---- student-based reports ----
        $where = [$scope ? 'a.ScholarshipID IN (' . implode(',', array_map('intval', array_keys($scope))) . ')' : '1 = 0'];
        $params = [];
        if ($sid) { $where[] = 'a.ScholarshipID = ?'; $params[] = $sid; }

        $is_scholar_report = $type !== 'applicants';
        if ($is_scholar_report) {
            $where[] = "a.Status = 'Approved'";
        } elseif (($f['status'] ?? '') !== '') {
            $where[] = $f['status'] === 'Pending' ? "a.Status IN ('Pending', 'Submitted', 'Under Review', 'Under_Review')" : 'a.Status = ?';
            if ($f['status'] !== 'Pending') $params[] = $f['status'];
        }

        if ($term_ids !== null) {
            $in = implode(',', array_map('intval', $term_ids));
            // Scholars count for a term when they were approved in it or renewed in it
            $where[] = $is_scholar_report
                ? "(a.TermID IN ($in) OR EXISTS (SELECT 1 FROM scholar_renewals r WHERE r.ApplicationID = a.ApplicationID AND r.TermID IN ($in) AND r.Status = 'Renewed'))"
                : "a.TermID IN ($in)";
        }
        foreach (['gender' => 'u.Gender', 'citizenship' => 'u.Citizenship', 'region' => 'u.Region', 'municipality' => 'u.CityMunicipality'] as $key => $col) {
            if (($f[$key] ?? '') !== '') { $where[] = "TRIM($col) = ?"; $params[] = $f[$key]; }
        }

        $stmt = $pdo->prepare("
            SELECT u.*, a.ApplicationID, a.Status AS AppStatus, a.DateSubmitted, a.GPA AS AppGPA, a.TermID AS AppTermID, a.TotalScore,
                   s.ScholarshipID AS SchID, s.Name AS ScholarshipName, s.Category, s.ScholarshipType, s.AwardAmount, s.ReleaseFrequency,
                   COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS Provider,
                   p.ProgramName
            FROM application a
            JOIN users u ON u.UserID = a.UserID
            JOIN scholarship s ON s.ScholarshipID = a.ScholarshipID
            LEFT JOIN users cu ON cu.UserID = s.CreatedBy
            LEFT JOIN program p ON p.ProgramID = u.ProgramID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.Name, u.LastName, u.FirstName");
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Columns: the sponsor's own format for its list of scholars, otherwise the standard layout
        $available = reportAvailableColumns($pdo, $sid ?: null);
        $columns = array_map(fn($k) => ['key' => $k, 'label' => $available[$k]], REPORT_STANDARD_COLUMNS[$type]);
        if ($sid && $type === 'scholars_per_scholarship' && ($custom = getSponsorReportFormat($pdo, $sid))) {
            $custom_cols = array_values(array_filter($custom['Columns'], fn($c) => isset($available[$c['key'] ?? ''])));
            if ($custom_cols) {
                $columns = array_map(fn($c) => ['key' => $c['key'], 'label' => trim($c['label'] ?? '') ?: $available[$c['key']]], $custom_cols);
                $report['format'] = 'Sponsor format';
                if (trim((string) $custom['Title']) !== '') $report['title'] = $custom['Title'];
                $report['subtitle'] = (string) $custom['Subtitle'];
                $report['prepared_by'] = (string) $custom['PreparedBy'];
            }
        }
        // The admin picked the information to include: that replaces the format's columns
        // (application questions another scholarship doesn't have are simply left out)
        $picked = array_values(array_filter(reportSelectedColumns($f), fn($k) => isset($available[$k])));
        if ($picked) {
            $labels = array_column($columns, 'label', 'key');
            $columns = array_map(fn($k) => ['key' => $k, 'label' => $labels[$k] ?? $available[$k]], $picked);
            $report['format'] = $report['format'] === 'Sponsor format' ? 'Sponsor format, selected information' : 'Selected information';
        }
        if ($sid && in_array($type, ['scholars_per_scholarship', 'applicants'], true) && !str_starts_with($report['format'], 'Sponsor')) {
            $report['subtitle'] = 'Sponsor / Provider: ' . $scope[$sid]['Provider'];
        }

        // Answers to the scholarship's own questions, when the format uses them
        $answers = [];
        $question_ids = array_map(fn($c) => (int) substr($c['key'], 1), array_filter($columns, fn($c) => preg_match('/^q\d+$/', $c['key'])));
        if ($question_ids && $records) {
            $app_ids = implode(',', array_map('intval', array_column($records, 'ApplicationID')));
            $q_ids = implode(',', $question_ids);
            foreach ($pdo->query("SELECT ApplicationID, FieldID, AnswerText FROM application_custom_answers WHERE ApplicationID IN ($app_ids) AND FieldID IN ($q_ids)") as $ans) {
                $answers[$ans['ApplicationID']]['q' . $ans['FieldID']] = $ans['AnswerText'];
            }
        }

        // Answers to extra profile fields, when picked
        $profile_answers = [];
        $profile_ids = array_map(fn($c) => (int) substr($c['key'], 1), array_filter($columns, fn($c) => preg_match('/^p\d+$/', $c['key'])));
        if ($profile_ids && $records) {
            $user_ids = implode(',', array_map('intval', array_unique(array_column($records, 'UserID'))));
            foreach ($pdo->query("SELECT UserID, FieldID, AnswerText FROM user_profile_answers WHERE UserID IN ($user_ids) AND FieldID IN (" . implode(',', $profile_ids) . ")") as $ans) {
                $profile_answers[$ans['UserID']]['p' . $ans['FieldID']] = $ans['AnswerText'];
            }
        }

        foreach ($records as $i => $r) {
            $values = reportRowValues($r, $i + 1, $terms, ($answers[$r['ApplicationID']] ?? []) + ($profile_answers[$r['UserID']] ?? []));
            $row = [];
            foreach ($columns as $c) $row[$c['key']] = $values[$c['key']] ?? '';
            $report['rows'][] = $row;
            foreach (REPORT_SUMMARY_DIMENSIONS as $dim => $label) {
                $v = trim((string) $values[$dim]) ?: 'Not specified';
                $report['summary'][$label][$v] = ($report['summary'][$label][$v] ?? 0) + 1;
            }
            if (!$sid) {
                $report['summary']['Scholarship'][$r['ScholarshipName']] = ($report['summary']['Scholarship'][$r['ScholarshipName']] ?? 0) + 1;
            }
        }
        foreach ($report['summary'] as &$counts) arsort($counts);
        $report['columns'] = $columns;
        return withReportSections($report);
    }

    // Column keys the admin picked ("columns" as a comma list or an array); empty means the format's own
    function reportSelectedColumns(array $f): array {
        $cols = $f['columns'] ?? [];
        if (is_string($cols)) $cols = explode(',', $cols);
        return array_values(array_unique(array_filter(array_map(fn($k) => trim((string) $k), (array) $cols), fn($k) => preg_match('/^[a-z_]+$|^[pq]\d+$/', $k))));
    }

    // A report's tables, as sections: single reports have one, "all scholarships" has one per scholarship
    function withReportSections(array $report): array {
        $report['sections'] = [[
            'title' => $report['title'], 'subtitle' => $report['subtitle'], 'prepared_by' => $report['prepared_by'],
            'format' => $report['format'], 'columns' => $report['columns'], 'rows' => $report['rows'], 'scholarship' => $report['scholarship'],
        ]];
        return $report;
    }

    // Every possible column value for one application
    function reportRowValues(array $r, int $no, array $terms, array $answers): array {
        $term = $terms[(int) $r['AppTermID']] ?? null;
        $age = '';
        if (!empty($r['DateOfBirth'])) $age = (new DateTime($r['DateOfBirth']))->diff(new DateTime('today'))->y;
        $full = trim(mb_strtoupper((string) $r['LastName']) . ', ' . trim($r['FirstName'] . ' ' . ($r['MiddleName'] ?? '') . ' ' . ($r['ExtName'] ?? '')));
        $gwa = $r['AppGPA'] !== null ? $r['AppGPA'] : $r['GPA'];
        return $answers + [
            'no' => $no, 'student_id' => $r['StudentID_Num'], 'full_name' => $full,
            'last_name' => $r['LastName'], 'first_name' => $r['FirstName'], 'middle_name' => $r['MiddleName'], 'ext_name' => $r['ExtName'],
            'sex' => $r['Gender'] === 'Not Specified' ? '' : $r['Gender'],
            'birthdate' => $r['DateOfBirth'] ? date('m/d/Y', strtotime($r['DateOfBirth'])) : '', 'age' => $age,
            'citizenship' => $r['Citizenship'], 'program' => $r['ProgramName'] ?: $r['Major'], 'year_level' => $r['YearLevel'],
            'gwa' => $gwa !== null && $gwa !== '' ? number_format((float) $gwa, 2) : '',
            'street' => $r['StreetBarangay'], 'municipality' => trim((string) $r['CityMunicipality']), 'province' => $r['Province'],
            'region' => trim((string) $r['Region']), 'zip' => $r['ZipCode'],
            'address' => trim(implode(', ', array_filter([$r['StreetBarangay'], $r['CityMunicipality'], $r['Province'], $r['Region']])), ', '),
            'contact' => $r['ContactNumber'], 'email' => $r['Email'],
            'father' => trim(($r['FatherFirstName'] ?? '') . ' ' . ($r['FatherMiddleName'] ?? '') . ' ' . ($r['FatherLastName'] ?? '')),
            'father_last' => $r['FatherLastName'], 'father_first' => $r['FatherFirstName'], 'father_middle' => $r['FatherMiddleName'],
            'mother' => trim(($r['MotherFirstName'] ?? '') . ' ' . ($r['MotherMiddleName'] ?? '') . ' ' . ($r['MotherLastName'] ?? '')),
            'mother_last' => $r['MotherLastName'], 'mother_first' => $r['MotherFirstName'], 'mother_middle' => $r['MotherMiddleName'],
            'units' => $r['UnitsEnrolled'] ?: '',
            'lrn' => $r['LRN'], 'philsys' => $r['PhilSysID'], 'fourps' => $r['FourPsID'],
            'disability' => $r['DisabilityType'], 'indigenous' => $r['IndigenousGroup'],
            'solo_parent' => $r['IsSoloParentChild'], 'first_gen' => $r['IsFirstGenStudent'],
            'scholarship' => $r['ScholarshipName'], 'sponsor' => $r['Provider'], 'category' => $r['Category'], 'type' => $r['ScholarshipType'],
            'award' => $r['AwardAmount'] !== null ? number_format((float) $r['AwardAmount'], 2) : '', 'frequency' => $r['ReleaseFrequency'],
            'status' => $r['AppStatus'], 'date_applied' => $r['DateSubmitted'] ? date('m/d/Y', strtotime($r['DateSubmitted'])) : '',
            'academic_year' => $term ? ($term['ay'] ? 'AY ' . $term['ay'] : '') : '', 'semester' => $term ? $term['semester'] : '',
            'score' => $r['TotalScore'] ?: '',
        ];
    }

    // List of scholarships available (not student-based, so the demographic filters don't apply)
    function buildScholarshipsReport(PDO $pdo, array $report, array $scope_ids, int $sid, bool $include_closed, array $picked = []): array {
        $where = [$scope_ids ? 's.ScholarshipID IN (' . implode(',', array_map('intval', $scope_ids)) . ')' : '1 = 0'];
        if ($sid) $where[] = 's.ScholarshipID = ' . $sid;
        if (!$include_closed) $where[] = "s.Status = 'Active' AND s.Deadline >= CURDATE()";
        $rows = $pdo->query("
            SELECT s.*, COALESCE(NULLIF(cu.Organization, ''), CONCAT(cu.FirstName, ' ', cu.LastName)) AS Provider,
                   (SELECT COUNT(*) FROM application a WHERE a.ScholarshipID = s.ScholarshipID AND a.Status = 'Approved') AS Scholars,
                   (SELECT COUNT(*) FROM application a WHERE a.ScholarshipID = s.ScholarshipID) AS Applicants,
                   (SELECT GROUP_CONCAT(p.ProgramName ORDER BY p.ProgramName SEPARATOR ', ') FROM scholarship_programs sp JOIN program p ON p.ProgramID = sp.ProgramID WHERE sp.ScholarshipID = s.ScholarshipID) AS Programs
            FROM scholarship s LEFT JOIN users cu ON cu.UserID = s.CreatedBy
            WHERE " . implode(' AND ', $where) . " ORDER BY s.Category, s.Name")->fetchAll(PDO::FETCH_ASSOC);

        $keys = array_values(array_filter($picked, fn($k) => isset(SCHOLARSHIP_REPORT_COLUMNS[$k]))) ?: array_keys(SCHOLARSHIP_REPORT_COLUMNS);
        if (count($keys) < count(SCHOLARSHIP_REPORT_COLUMNS)) $report['format'] = 'Selected information';
        $report['columns'] = array_map(fn($k) => ['key' => $k, 'label' => SCHOLARSHIP_REPORT_COLUMNS[$k]], $keys);
        if (!$include_closed) $report['filters'][] = 'Open scholarships only';

        foreach ($rows as $i => $s) {
            $slots = $s['NumberOfSlots'] !== null ? (int) $s['NumberOfSlots'] : null;
            $row = [
                'no' => $i + 1, 'scholarship' => $s['Name'], 'sponsor' => $s['Provider'], 'category' => $s['Category'], 'type' => $s['ScholarshipType'],
                'award' => number_format((float) $s['AwardAmount'], 2), 'frequency' => $s['ReleaseFrequency'],
                'slots' => $slots ?? 'Open', 'scholars' => (int) $s['Scholars'], 'remaining' => $slots !== null ? max(0, $slots - (int) $s['Scholars']) : '—',
                'applicants' => (int) $s['Applicants'], 'min_gwa' => $s['MinimumGWA'], 'year_level' => $s['YearLevel'] ?: 'All',
                'programs' => $s['Programs'] ?: 'All programs', 'deadline' => date('m/d/Y', strtotime($s['Deadline'])), 'status' => $s['Status'],
            ];
            $report['rows'][] = array_intersect_key($row, array_flip($keys));
            $report['summary']['Category'][$s['Category']] = ($report['summary']['Category'][$s['Category']] ?? 0) + 1;
            $report['summary']['Type'][$s['ScholarshipType']] = ($report['summary']['Type'][$s['ScholarshipType']] ?? 0) + 1;
        }
        return $report;
    }
}
