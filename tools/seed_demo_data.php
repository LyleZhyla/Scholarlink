<?php
// ✨ DEMO DATA FOR THE PANEL PRESENTATION
// Creates demo students and, for EVERY scholarship, 5 Pending + 5 Shortlisted + 5 Approved + 5 Rejected applications
// in the active term, so System Reports, the masterlists and the dashboards have realistic data to show.
//
//   php tools/seed_demo_data.php          add the demo data (refuses if it is already there)
//   php tools/seed_demo_data.php --fresh  remove the old demo data first, then add it again
//   php tools/remove_demo_data.php        remove all demo data
//
// Every demo student has an @demo.tau.edu.ph email: that is how the demo data is recognised and removed.
// Applicants fit each scholarship's rules (program, year level, GWA); nobody is approved in two scholarships.
// Demo students can sign in with their student number and the password Demo@2026.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db_connect.php';

const DEMO_DOMAIN = 'demo.tau.edu.ph';
const DEMO_PASSWORD = 'Demo@2026';
const PER_STATUS = 5;
const STATUSES = ['Pending', 'Shortlisted', 'Approved', 'Rejected'];

mt_srand(20261024);   // same data on every run

$existing = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE Email LIKE '%@" . DEMO_DOMAIN . "'")->fetchColumn();
if ($existing) {
    if (!in_array('--fresh', $argv, true)) exit("Demo data is already loaded ($existing demo students). Use --fresh to rebuild it, or tools/remove_demo_data.php to remove it.\n");
    require __DIR__ . '/remove_demo_data.php';
}

// ---------- Names & places ----------
$male = ['Juan', 'Jose', 'Mark', 'John Paul', 'Christian', 'Joshua', 'Angelo', 'Carlo', 'Rafael', 'Miguel', 'Kenneth', 'Jerome', 'Paolo', 'Adrian', 'Gerald', 'Jayson', 'Ronald', 'Marvin', 'Bryan', 'Daniel', 'Francis', 'Kevin', 'Renz', 'Aldrin', 'Jericho', 'Nathaniel', 'Emmanuel', 'Vincent', 'Ralph', 'Jhon Lloyd'];
$female = ['Maria', 'Angelica', 'Kristine', 'Princess', 'Jasmine', 'Nicole', 'Camille', 'Andrea', 'Patricia', 'Mary Joy', 'Rhea', 'Jessa', 'Shiela', 'Kimberly', 'Hazel', 'Joanna', 'Erica', 'Trisha', 'Bea', 'Alyssa', 'Mariel', 'Danica', 'Lovely', 'Rose Ann', 'Janine', 'Claire', 'Diane', 'Ella Mae', 'Jenny', 'Leslie'];
$last = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Pascual', 'Bautista', 'Aquino', 'Castillo', 'Domingo', 'Agustin', 'Valdez', 'Ramos', 'Tomas', 'Manuel', 'Mateo', 'Corpuz', 'Navarro', 'Sison', 'Villanueva', 'Fernandez', 'Gutierrez', 'Hipolito', 'Lagunero', 'Pagaduan', 'Sagun', 'Calimlim', 'Macaraeg', 'Tablante', 'Esteban', 'Galang', 'Ocampo', 'Soriano', 'Torres', 'Salvador', 'Valencia', 'Abad', 'Bernabe', 'Cabrera', 'Dizon', 'Estrada', 'Francisco', 'Ignacio', 'Lopez', 'Marquez', 'Natividad', 'Pineda', 'Rivera', 'Tolentino', 'Velasco'];
$middle = ['Andres', 'Bautista', 'Cruz', 'De Guzman', 'Espino', 'Flores', 'Gomez', 'Javier', 'Lacson', 'Miranda', 'Ortega', 'Perez', 'Quiambao', 'Robles', 'Sales', 'Tiglao', 'Umali', 'Vergara', 'Yap', 'Zamora'];
// [municipality, province, region, zip, weight]
$places = [
    ['Camiling', 'Tarlac', 'Region III', '2306', 18], ['Tarlac City', 'Tarlac', 'Region III', '2300', 12], ['Paniqui', 'Tarlac', 'Region III', '2307', 8],
    ['Mayantoc', 'Tarlac', 'Region III', '2304', 6], ['Santa Ignacia', 'Tarlac', 'Region III', '2303', 6], ['San Clemente', 'Tarlac', 'Region III', '2305', 5],
    ['Gerona', 'Tarlac', 'Region III', '2302', 5], ['Moncada', 'Tarlac', 'Region III', '2308', 4], ['Victoria', 'Tarlac', 'Region III', '2313', 3],
    ['Capas', 'Tarlac', 'Region III', '2315', 3], ['Concepcion', 'Tarlac', 'Region III', '2316', 3], ['Guimba', 'Nueva Ecija', 'Region III', '3115', 3],
    ['Bayambang', 'Pangasinan', 'Region I', '2423', 6], ['Malasiqui', 'Pangasinan', 'Region I', '2421', 4], ['San Carlos City', 'Pangasinan', 'Region I', '2420', 3],
    ['Urdaneta City', 'Pangasinan', 'Region I', '2428', 2], ['Tuguegarao City', 'Cagayan', 'Region II', '3500', 2], ['Santiago City', 'Isabela', 'Region II', '3311', 2],
    ['Baguio City', 'Benguet', 'CAR', '2600', 2], ['Quezon City', 'Metro Manila', 'NCR', '1100', 2],
];
$barangays = ['Poblacion A', 'Poblacion B', 'San Isidro', 'Malacampa', 'Bobon 1st', 'Cayaoan', 'Libueg', 'Pao 1st', 'Sinilian', 'Bilad', 'San Jose', 'Sta. Maria', 'Bagumbayan', 'Matubog', 'Lasong'];
$citizenships = [['Filipino', 96], ['Dual Citizen (Filipino-American)', 2], ['Korean', 1], ['Chinese', 1]];
$indigenous = ['Aeta', 'Ilongot', 'Kankanaey', 'Ibaloi'];
$disabilities = ['Visual impairment', 'Hearing impairment', 'Orthopedic disability'];
$years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

$pick = fn(array $a) => $a[mt_rand(0, count($a) - 1)];
$weighted = function (array $items) {
    $total = array_sum(array_column($items, count($items[0]) - 1));
    $r = mt_rand(1, $total);
    foreach ($items as $it) { $r -= end($it); if ($r <= 0) return $it; }
    return end($items);
};

$programs = $pdo->query("SELECT ProgramID, ProgramName FROM program")->fetchAll(PDO::FETCH_KEY_PAIR);
$program_ids = array_keys($programs);

// ---------- Scholarship rules ----------
$scholarships = $pdo->query("SELECT ScholarshipID, Name, MinimumGWA, YearLevel, ProgramID FROM scholarship ORDER BY ScholarshipID")->fetchAll(PDO::FETCH_ASSOC);
$sch_programs = [];
foreach ($pdo->query("SELECT ScholarshipID, ProgramID FROM scholarship_programs") as $r) $sch_programs[$r['ScholarshipID']][] = (int) $r['ProgramID'];
foreach ($scholarships as &$s) {
    $s['programs'] = $sch_programs[$s['ScholarshipID']] ?? ($s['ProgramID'] ? [(int) $s['ProgramID']] : []);
    $s['years'] = $s['YearLevel'] ? array_map('trim', explode(',', $s['YearLevel'])) : [];
    $s['gwa'] = (float) ($s['MinimumGWA'] ?: 3.00);
}
unset($s);

$term_id = (int) $pdo->query("SELECT TermID FROM academic_terms WHERE Status = 'Active' ORDER BY TermID DESC LIMIT 1")->fetchColumn() ?: null;
$term_start = strtotime((string) $pdo->query("SELECT StartDate FROM academic_terms WHERE TermID = " . (int) $term_id)->fetchColumn()) ?: strtotime('-60 days');
$now = time();

// ---------- Students ----------
$students = [];                 // UserID => profile
$used_ids = array_flip($pdo->query("SELECT StudentID_Num FROM users WHERE StudentID_Num IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
$password_hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
$insert_user = $pdo->prepare("
    INSERT INTO users (Username, Email, PasswordHash, FirstName, LastName, MiddleName, ExtName, Role, AccountStatus, StudentID_Num, LRN, PhilSysID, FourPsID,
        YearLevel, GPA, Major, ProgramID, ContactNumber, DateOfBirth, Gender, FatherLastName, FatherFirstName, FatherMiddleName,
        MotherLastName, MotherFirstName, MotherMiddleName, StreetBarangay, CityMunicipality, Province, Region, ZipCode,
        DisabilityType, IsSoloParentChild, IsFirstGenStudent, IndigenousGroup, UnitsEnrolled, Citizenship)
    VALUES (?, ?, ?, ?, ?, ?, ?, 'Student', 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

// A new student who fits the scholarship's rules ($eligible = false: may fall short, for rejected applications)
$new_student = function (array $sch, bool $eligible) use (&$students, &$used_ids, $insert_user, $password_hash, $pick, $weighted, $male, $female, $last, $middle, $places, $barangays, $citizenships, $indigenous, $disabilities, $years, $programs, $program_ids, $pdo) {
    $is_male = mt_rand(0, 99) < 45;
    $first = $pick($is_male ? $male : $female);
    $ln = $pick($last);
    $mn = $pick($middle);
    $ext = $is_male && mt_rand(0, 99) < 4 ? $pick(['Jr.', 'III']) : null;
    $program = $sch['programs'] ? $pick($sch['programs']) : $pick($program_ids);
    $year = $sch['years'] ? $pick($sch['years']) : $pick($years);
    // GWA (1.00 best): eligible ones within the scholarship's cut-off
    $gwa = $eligible
        ? round(mt_rand(100, (int) round($sch['gwa'] * 100)) / 100, 2)
        : round(min(3.00, $sch['gwa'] + mt_rand(5, 60) / 100), 2);
    if (!$eligible && mt_rand(0, 1)) $gwa = round(mt_rand(100, (int) round($sch['gwa'] * 100)) / 100, 2);   // some rejected for other reasons
    $entry_year = 2026 - (int) $year[0] + 1;
    do { $sid = $entry_year . '9' . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT); } while (isset($used_ids[$sid]));
    $used_ids[$sid] = true;
    [$town, $province, $region, $zip] = $weighted($places);
    $citizenship = $weighted($citizenships)[0];
    $age = 17 + (int) $year[0] + mt_rand(0, 2);
    $dob = date('Y-m-d', mktime(0, 0, 0, mt_rand(1, 12), mt_rand(1, 28), 2026 - $age));
    $father_first = $pick($male);
    $mother_last = $pick($last);
    $email_name = strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $ln)) . substr($sid, -3);

    $insert_user->execute([
        $sid, $email_name . '@' . DEMO_DOMAIN, $password_hash, $first, $ln, $mn, $ext, $sid,
        (string) mt_rand(100000, 999999) . str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
        mt_rand(0, 99) < 60 ? implode('-', [mt_rand(1000, 9999), mt_rand(1000, 9999), mt_rand(1000, 9999), mt_rand(1000, 9999)]) : null,
        mt_rand(0, 99) < 22 ? '1234-' . mt_rand(1000000, 9999999) : null,
        $year, $gwa, $programs[$program], $program,
        '09' . mt_rand(10, 99) . mt_rand(1000000, 9999999), $dob, $is_male ? 'Male' : 'Female',
        $ln, $father_first, $pick($middle), $mother_last, $pick($female), $pick($middle),
        $pick($barangays), $town, $province, $region, $zip,
        mt_rand(0, 99) < 3 ? $pick($disabilities) : null,
        mt_rand(0, 99) < 12 ? 'Yes' : 'No', mt_rand(0, 99) < 35 ? 'Yes' : 'No',
        mt_rand(0, 99) < 6 ? $pick($indigenous) : null, mt_rand(15, 26), $citizenship,
    ]);
    $id = (int) $pdo->lastInsertId();
    $students[$id] = ['program' => $program, 'year' => $year, 'gwa' => $gwa, 'apps' => [], 'approved' => false];
    return $id;
};

// An existing demo student who also fits (so students apply to several scholarships), or a new one
$find_student = function (array $sch, string $status) use (&$students, $new_student) {
    $eligible = $status !== 'Rejected';
    $candidates = [];
    foreach ($students as $id => $st) {
        if (count($st['apps']) >= 3 || isset($st['apps'][$sch['ScholarshipID']])) continue;
        if ($status === 'Approved' && $st['approved']) continue;
        if ($sch['programs'] && !in_array($st['program'], $sch['programs'], true)) continue;
        if ($sch['years'] && !in_array($st['year'], $sch['years'], true)) continue;
        if ($eligible && $st['gwa'] > $sch['gwa']) continue;
        $candidates[] = $id;
    }
    // About half the applicants are students who already applied elsewhere
    if ($candidates && mt_rand(0, 99) < 50) return $candidates[mt_rand(0, count($candidates) - 1)];
    return $new_student($sch, $eligible);
};

// ---------- Applications ----------
$insert_app = $pdo->prepare("INSERT INTO application (ScholarshipID, UserID, DateSubmitted, Status, TotalScore, GPA, YearLevel, TermID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$pdo->beginTransaction();
$counts = array_fill_keys(STATUSES, 0);
foreach ($scholarships as $sch) {
    foreach (STATUSES as $status) {
        for ($i = 0; $i < PER_STATUS; $i++) {
            $uid = $find_student($sch, $status);
            $st = &$students[$uid];
            // Decided applications were submitted earlier in the term than pending ones
            $from = $status === 'Pending' ? max($term_start, $now - 21 * 86400) : $term_start;
            $to = $status === 'Pending' ? $now : $now - 7 * 86400;
            $submitted = date('Y-m-d H:i:s', mt_rand($from, max($from, $to)));
            $score = match ($status) { 'Shortlisted' => mt_rand(75, 92), 'Approved' => mt_rand(85, 99), 'Rejected' => mt_rand(35, 70), default => 0 };
            $insert_app->execute([$sch['ScholarshipID'], $uid, $submitted, $status, $score, $st['gwa'], $st['year'], $term_id]);
            $st['apps'][$sch['ScholarshipID']] = true;
            if ($status === 'Approved') $st['approved'] = true;
            unset($st);
            $counts[$status]++;
        }
    }
}
$pdo->commit();

printf("Demo data added: %d students, %d applications (%s) across %d scholarships, term #%s.\n",
    count($students), array_sum($counts), implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)), count($scholarships), $term_id);
printf("Demo students sign in with their student number and the password %s.\n", DEMO_PASSWORD);

// The audit trail that goes with it
$argv = [];
require __DIR__ . '/seed_demo_logs.php';
