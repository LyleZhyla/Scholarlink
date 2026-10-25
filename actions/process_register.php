<?php
session_start();
require '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../student_login.php');
    exit;
}

// --- FORM DATA COLLECTION & SANITIZATION ---
$first_name = trim($_POST['first_name'] ?? '');
$last_name = trim($_POST['last_name'] ?? '');
$middle_name = trim($_POST['middle_name'] ?? '');
$ext_name = trim($_POST['ext_name'] ?? ''); 

$student_number = trim($_POST['student_number'] ?? '');
$lrn = trim($_POST['lrn'] ?? '');
$philsys_id = trim($_POST['philsys_id'] ?? '');
$fourps_id = trim($_POST['fourps_id'] ?? '');

$email = trim($_POST['email'] ?? '');
// ✨ Program comes from the scanned student ID (set by scan_id_ocr.php), never from the form
$program_id = (int)($_SESSION['scanned_program_id'] ?? 0);
$year_level = trim($_POST['year_level'] ?? '');

// ✨ KINUHA NATIN ANG GPA DITO
$gpa = isset($_POST['gpa']) && trim($_POST['gpa']) !== '' ? (float)$_POST['gpa'] : null;

$gender = trim($_POST['gender'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$date_of_birth = trim($_POST['date_of_birth'] ?? '');

// Family Background
$father_ln = trim($_POST['father_ln'] ?? '');
$father_fn = trim($_POST['father_fn'] ?? '');
$father_mn = trim($_POST['father_mn'] ?? '');
$mother_ln = trim($_POST['mother_ln'] ?? '');
$mother_fn = trim($_POST['mother_fn'] ?? '');
$mother_mn = trim($_POST['mother_mn'] ?? '');

// Permanent Address
$street_brgy = trim($_POST['street_brgy'] ?? '');
$city = trim($_POST['city'] ?? '');
$province = trim($_POST['province'] ?? '');
$region = trim($_POST['region'] ?? '');
$zipcode = trim($_POST['zipcode'] ?? '');

// Special Statuses
$disability = trim($_POST['disability'] ?? '');
$is_solo_parent = trim($_POST['is_solo_parent'] ?? 'No'); 
$is_first_gen = trim($_POST['is_first_gen'] ?? 'No');     
$indigenous = trim($_POST['indigenous'] ?? '');

$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';
$google_id = trim($_POST['google_id'] ?? '');

// --- VALIDATION (Required fields only) ---
$errors = [];

if (empty($program_id)) {
    $errors[] = "Your program could not be read from your student ID. Please scan a clear photo of your ID.";
}

if (empty($first_name) || empty($last_name) || empty($student_number) || empty($email) || empty($year_level) || empty($gender) || empty($contact_number) || empty($date_of_birth)) {
    $errors[] = "Core identity fields are required.";
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Invalid email format.";
}

if (strlen($student_number) !== 10 || !ctype_digit($student_number)) {
    $errors[] = "Student number must be exactly 10 digits.";
}

if (empty($google_id)) {
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
}

try {
    $stmt = $pdo->prepare("SELECT UserID FROM users WHERE Email = ? OR StudentID_Num = ?");
    if ($profile_pic_db_path !== null) {
    $pdo->prepare("UPDATE users SET ProfilePicture = ? WHERE UserID = ?")->execute([$profile_pic_db_path, $new_user_id]);
    }
    $stmt->execute([$email, $student_number]);
    if ($stmt->fetch()) {
        $errors[] = "An account with this email or student number already exists.";
    }
} catch (PDOException $e) {
    $errors[] = "Database error during user check. Please try again.";
}

if (!empty($errors)) {
    $_SESSION['error'] = implode('<br>', $errors);
    header('Location: ../student_login.php');
    exit;
}

// --- DATABASE INSERTION ---
try {
    $hashed_password = !empty($google_id) ? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT) : password_hash($password, PASSWORD_DEFAULT);
    $final_google_id = !empty($google_id) ? $google_id : null;

            // ✨ DECODE AND SAVE EXTRACTED ID PORTRAIT PHOTO
        $profile_pic_db_path = null;
        if (!empty($_POST['extracted_id_photo'])) {
            $data_uri = $_POST['extracted_id_photo'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data_uri, $type)) {
                $img_data = substr($data_uri, strpos($data_uri, ',') + 1);
                $img_data = base64_decode($img_data);

                if ($img_data !== false) {
                    $upload_dir = '../uploads/profiles/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    $clean_stud_num = preg_replace('/[^0-9]/', '', $_POST['student_number'] ?? uniqid());
                    $file_name = 'ID_PORTRAIT_' . $clean_stud_num . '_' . time() . '.jpg';
                    $file_path = $upload_dir . $file_name;

                    if (file_put_contents($file_path, $img_data)) {
                        // Path relative to student/profile.php
                        $profile_pic_db_path = '../uploads/profiles/' . $file_name;
                    }
                }
            }
        }

    // ✨ ISINAMA NA NATIN ANG USERNAME AT GPA SA DATABASE INSERT
    $sql = "INSERT INTO users (
                Username, FirstName, LastName, MiddleName, ExtName, 
                StudentID_Num, LRN, PhilSysID, FourPsID, 
                Email, ProgramID, YearLevel, GPA, Gender, ContactNumber, DateOfBirth, 
                FatherLastName, FatherFirstName, FatherMiddleName, 
                MotherLastName, MotherFirstName, MotherMiddleName, 
                StreetBarangay, CityMunicipality, Province, Region, ZipCode, 
                DisabilityType, IsSoloParentChild, IsFirstGenStudent, IndigenousGroup,
                PasswordHash, Role, AccountStatus, GoogleID
            ) VALUES (
                :username, :first_name, :last_name, :middle_name, :ext_name,
                :student_number, :lrn, :philsys_id, :fourps_id,
                :email, :program_id, :year_level, :gpa, :gender, :contact_number, :date_of_birth,
                :father_ln, :father_fn, :father_mn,
                :mother_ln, :mother_fn, :mother_mn,
                :street_brgy, :city, :province, :region, :zipcode,
                :disability, :is_solo_parent, :is_first_gen, :indigenous,
                :password, 'Student', 'Active', :google_id
            )";
    
    $stmt = $pdo->prepare($sql);
    
    // ✨ INI-MAP NA NATIN ANG MGA VALUES PARA SA USERNAME AT GPA
    $stmt->execute([
        ':username'       => $student_number,
        ':first_name'     => $first_name, 
        ':last_name'      => $last_name, 
        ':middle_name'    => $middle_name,
        ':ext_name'       => $ext_name,
        ':student_number' => $student_number, 
        ':lrn'            => $lrn,
        ':philsys_id'     => $philsys_id,
        ':fourps_id'      => $fourps_id,
        ':email'          => $email, 
        ':program_id'     => $program_id, 
        ':year_level'     => $year_level, 
        ':gpa'            => $gpa, 
        ':gender'         => $gender, 
        ':contact_number' => $contact_number, 
        ':date_of_birth'  => $date_of_birth,
        ':father_ln'      => $father_ln,
        ':father_fn'      => $father_fn,
        ':father_mn'      => $father_mn,
        ':mother_ln'      => $mother_ln,
        ':mother_fn'      => $mother_fn,
        ':mother_mn'      => $mother_mn,
        ':street_brgy'    => $street_brgy,
        ':city'           => $city,
        ':province'       => $province,
        ':region'         => $region,
        ':zipcode'        => $zipcode,
        ':disability'     => $disability,
        ':is_solo_parent' => $is_solo_parent,
        ':is_first_gen'   => $is_first_gen,
        ':indigenous'     => $indigenous,
        ':password'       => $hashed_password, 
        ':google_id'      => $final_google_id
    ]);

    unset($_SESSION['scanned_program_id']);

    // Automatically log them in after a successful Google Sign-up
    if (!empty($google_id)) {
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['role'] = 'Student';
        $_SESSION['success'] = "Registration complete! Welcome to ScholarLink.";
        header('Location: ../student/dashboard.php');
        exit;
    }

    $_SESSION['success'] = "Registration successful! You can now log in.";
    header('Location: ../student_login.php');
    exit;

} catch (PDOException $e) {
    $_SESSION['error'] = "Registration failed: " . $e->getMessage();
    header('Location: ../student_login.php');
    exit;
}