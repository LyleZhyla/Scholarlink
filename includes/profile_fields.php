<?php
// ✨ STUDENT PROFILE: WHAT SAVES AT ONCE AND WHAT NEEDS THE SUPER ADMIN
// Personal Details (and the profile photo) save immediately. Every other section is sent as a change request
// (table profile_change_requests) that a Super Admin approves or rejects on super_admin/profile_requests.php.
// Keys are the form field names in student/profile.php. Always load this file with require_once.

// Saved immediately: [column, label]
const PROFILE_DIRECT_FIELDS = [
    'first_name'     => ['FirstName', 'First name'],
    'last_name'      => ['LastName', 'Last name'],
    'middle_name'    => ['MiddleName', 'Middle name'],
    'ext_name'       => ['ExtName', 'Name extension'],
    'gender'         => ['Gender', 'Sex'],
    'citizenship'    => ['Citizenship', 'Citizenship'],
    'contact_number' => ['ContactNumber', 'Contact number'],
    'date_of_birth'  => ['DateOfBirth', 'Date of birth'],
];

// Need approval: [column, label, section]
const PROFILE_APPROVAL_FIELDS = [
    'student_number' => ['StudentID_Num', 'Student number', 'Account Access'],
    'email'          => ['Email', 'Email address', 'Account Access'],
    'year_level'     => ['YearLevel', 'Year level', 'Academic Information'],
    'lrn'            => ['LRN', 'LRN', 'Identifications'],
    'philsys_id'     => ['PhilSysID', 'PhilSys ID', 'Identifications'],
    'fourps_id'      => ['FourPsID', '4Ps ID', 'Identifications'],
    'street_brgy'    => ['StreetBarangay', 'Street / Barangay', 'Permanent Address'],
    'city'           => ['CityMunicipality', 'City / Municipality', 'Permanent Address'],
    'province'       => ['Province', 'Province', 'Permanent Address'],
    'region'         => ['Region', 'Region', 'Permanent Address'],
    'zipcode'        => ['ZipCode', 'ZIP code', 'Permanent Address'],
    'father_fn'      => ['FatherFirstName', "Father's first name", 'Family Background'],
    'father_mn'      => ['FatherMiddleName', "Father's middle name", 'Family Background'],
    'father_ln'      => ['FatherLastName', "Father's last name", 'Family Background'],
    'mother_fn'      => ['MotherFirstName', "Mother's first name", 'Family Background'],
    'mother_mn'      => ['MotherMiddleName', "Mother's middle name", 'Family Background'],
    'mother_ln'      => ['MotherLastName', "Mother's last name", 'Family Background'],
    'disability'     => ['DisabilityType', 'Disability', 'Special Status'],
    'indigenous'     => ['IndigenousGroup', 'Indigenous group', 'Special Status'],
    'is_solo_parent' => ['IsSoloParentChild', 'Child of a solo parent', 'Special Status'],
    'is_first_gen'   => ['IsFirstGenStudent', 'First-generation student', 'Special Status'],
];

const PROFILE_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

function profileApprovalFields(): array { return PROFILE_APPROVAL_FIELDS; }

// The student's open request, or null
function pendingProfileRequest(PDO $pdo, int $user_id): ?array {
    $st = $pdo->prepare("SELECT * FROM profile_change_requests WHERE UserID = ? AND Status = 'Pending' ORDER BY RequestID DESC LIMIT 1");
    $st->execute([$user_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['ChangesList'] = json_decode($row['Changes'], true) ?: [];
    return $row;
}

// Problems with requested values (null when fine). Also run again at approval, since things may have changed meanwhile.
function profileChangeError(PDO $pdo, int $user_id, array $changes): ?string {
    if (array_key_exists('student_number', $changes)) {
        $sn = $changes['student_number'];
        if (strlen($sn) !== 10 || !ctype_digit($sn)) return "The student number must be exactly 10 digits.";
        $st = $pdo->prepare("SELECT 1 FROM users WHERE (StudentID_Num = ? OR Username = ?) AND UserID <> ?");
        $st->execute([$sn, $sn, $user_id]);
        if ($st->fetchColumn()) return "Student number $sn is already used by another account.";
    }
    if (array_key_exists('email', $changes)) {
        $email = $changes['email'];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "Please enter a valid email address.";
        $st = $pdo->prepare("SELECT 1 FROM users WHERE Email = ? AND UserID <> ?");
        $st->execute([$email, $user_id]);
        if ($st->fetchColumn()) return "That email address is already used by another account.";
    }
    if (array_key_exists('year_level', $changes) && !in_array($changes['year_level'], PROFILE_YEAR_LEVELS, true)) {
        return "Please select a valid year level.";
    }
    foreach (['is_solo_parent', 'is_first_gen'] as $yn) {
        if (array_key_exists($yn, $changes) && !in_array($changes[$yn], ['Yes', 'No'], true)) return "Please answer Yes or No.";
    }
    return null;
}

// Writes approved values to the account (the student number is also the login username)
function applyProfileChanges(PDO $pdo, int $user_id, array $changes): void {
    $sets = [];
    $params = [];
    foreach ($changes as $field => $value) {
        if (!isset(PROFILE_APPROVAL_FIELDS[$field])) continue;
        $col = PROFILE_APPROVAL_FIELDS[$field][0];
        $sets[] = "`$col` = ?";
        $params[] = $value === '' ? null : $value;
        if ($field === 'student_number') { $sets[] = "`Username` = ?"; $params[] = $value; }
    }
    if (!$sets) return;
    $params[] = $user_id;
    $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE UserID = ?")->execute($params);
}

function profileValueText($value): string {
    return ($value === null || $value === '') ? '(blank)' : (string) $value;
}
