<?php
session_start();
require '../includes/db_connect.php';

// Security: Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];

    // 1. KUNIN ANG LAHAT NG DATA MULA SA FORM
    $email = trim($_POST['email'] ?? '');

    // Student Number doubles as the login Username
    $student_number = trim($_POST['student_number'] ?? '');

    // Personal Details
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $ext_name = trim($_POST['ext_name'] ?? '');
    $gender = trim($_POST['gender'] ?? 'Not Specified');
    $citizenship = trim($_POST['citizenship'] ?? 'Filipino');
    $contact = trim($_POST['contact_number'] ?? '');
    $dob = trim($_POST['date_of_birth'] ?? '');

    // Identifications
    $lrn = trim($_POST['lrn'] ?? '');
    $philsys_id = trim($_POST['philsys_id'] ?? '');
    $fourps_id = trim($_POST['fourps_id'] ?? '');

    // Family Background
    $father_fn = trim($_POST['father_fn'] ?? '');
    $father_mn = trim($_POST['father_mn'] ?? '');
    $father_ln = trim($_POST['father_ln'] ?? '');
    $mother_fn = trim($_POST['mother_fn'] ?? '');
    $mother_mn = trim($_POST['mother_mn'] ?? '');
    $mother_ln = trim($_POST['mother_ln'] ?? '');

    // Special Status
    $disability = trim($_POST['disability'] ?? '');
    $indigenous = trim($_POST['indigenous'] ?? '');
    $is_solo_parent = trim($_POST['is_solo_parent'] ?? 'No');
    $is_first_gen = trim($_POST['is_first_gen'] ?? 'No');

    // Academic Information (Program is not here: it comes from the scanned ID, see process_program_shift.php)
    $year_level = trim($_POST['year_level'] ?? '');
    $allowed_year_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

    // Address
    $street_brgy = trim($_POST['street_brgy'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $region = trim($_POST['region'] ?? '');
    $zipcode = trim($_POST['zipcode'] ?? '');

    $clean_contact = !empty($contact) ? $contact : null;
    $clean_dob     = !empty($dob) ? $dob : null;

    try {
        // Same rules as registration
        if ($first_name === '' || $last_name === '') {
            $_SESSION['error'] = "First name and last name are required.";
            header("Location: ../student/profile.php");
            exit();
        }

        if (strlen($student_number) !== 10 || !ctype_digit($student_number)) {
            $_SESSION['error'] = "Student ID must be exactly 10 digits.";
            header("Location: ../student/profile.php");
            exit();
        }

        // I-CHECK KUNG MAY KAPAREHAS NA STUDENT ID (ito rin ang Username sa login)
        $stmt = $pdo->prepare("SELECT UserID FROM users WHERE (StudentID_Num = ? OR Username = ?) AND UserID != ?");
        $stmt->execute([$student_number, $student_number, $user_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Student ID is already in use by another account.";
            header("Location: ../student/profile.php");
            exit();
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Please enter a valid email address.";
            header("Location: ../student/profile.php");
            exit();
        }

        // I-CHECK KUNG MAY KAPAREHAS NA EMAIL
        $stmt = $pdo->prepare("SELECT UserID FROM users WHERE Email = :email AND UserID != :uid");
        $stmt->execute(['email' => $email, 'uid' => $user_id]);
        if ($stmt->rowCount() > 0) {
            $_SESSION['error'] = "Email address is already in use by another account.";
            header("Location: ../student/profile.php");
            exit();
        }

        // I-CHECK KUNG VALID ANG YEAR LEVEL
        if (!in_array($year_level, $allowed_year_levels, true)) {
            $_SESSION['error'] = "Please select a valid year level.";
            header("Location: ../student/profile.php");
            exit();
        }

        $update_query = "UPDATE users SET
            Email = :email,
            Username = :username,
            StudentID_Num = :student_number,
            FirstName = :fname,
            LastName = :lname,
            MiddleName = :mname,
            ExtName = :ename,
            Gender = :gender,
            Citizenship = :citizenship,
            ContactNumber = :contact,
            DateOfBirth = :dob,
            LRN = :lrn,
            PhilSysID = :philsys,
            FourPsID = :fourps,
            YearLevel = :year_level,
            StreetBarangay = :street,
            CityMunicipality = :city,
            Province = :province,
            Region = :region,
            ZipCode = :zipcode,
            FatherFirstName = :father_fn,
            FatherMiddleName = :father_mn,
            FatherLastName = :father_ln,
            MotherFirstName = :mother_fn,
            MotherMiddleName = :mother_mn,
            MotherLastName = :mother_ln,
            DisabilityType = :disability,
            IndigenousGroup = :indigenous,
            IsSoloParentChild = :solo_parent,
            IsFirstGenStudent = :first_gen";

        $params = [
            ':email' => $email,
            ':username' => $student_number,
            ':student_number' => $student_number,
            ':fname' => $first_name,
            ':lname' => $last_name,
            ':mname' => $middle_name,
            ':ename' => $ext_name,
            ':gender' => $gender,
            ':citizenship' => $citizenship,
            ':contact' => $clean_contact,
            ':dob' => $clean_dob,
            ':lrn' => $lrn,
            ':philsys' => $philsys_id,
            ':fourps' => $fourps_id,
            ':year_level' => $year_level,
            ':street' => $street_brgy,
            ':city' => $city,
            ':province' => $province,
            ':region' => $region,
            ':zipcode' => $zipcode,
            ':father_fn' => $father_fn,
            ':father_mn' => $father_mn,
            ':father_ln' => $father_ln,
            ':mother_fn' => $mother_fn,
            ':mother_mn' => $mother_mn,
            ':mother_ln' => $mother_ln,
            ':disability' => $disability,
            ':indigenous' => $indigenous,
            ':solo_parent' => $is_solo_parent,
            ':first_gen' => $is_first_gen,
            ':uid' => $user_id
        ];

        // 4. PROFILE PICTURE UPLOAD LOGIC[cite: 26]
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB max
            
            if (!in_array($file['type'], $allowed_types)) {
                $_SESSION['error'] = "Invalid image type. Please upload a JPG, PNG, or WEBP.";
                header("Location: ../student/profile.php");
                exit();
            }
            
            if ($file['size'] > $max_size) {
                $_SESSION['error'] = "Image is too large. Maximum size is 5MB.";
                header("Location: ../student/profile.php");
                exit();
            }

            $target_dir = "../uploads/profiles/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

            $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $new_filename = "USER_" . $user_id . "_PROFILE_" . time() . "." . $file_ext;
            $target_path = $target_dir . $new_filename;

            if (move_uploaded_file($file['tmp_name'], $target_path)) {
                $update_query .= ", ProfilePicture = :pic";
                $params[':pic'] = $target_path;
            } else {
                $_SESSION['error'] = "Failed to save the image to the server.";
                header("Location: ../student/profile.php");
                exit();
            }
        }

        $update_query .= " WHERE UserID = :uid";

        $stmt = $pdo->prepare($update_query);
        $stmt->execute($params);

        // Keep the session in sync with the edited account details
        $_SESSION['username']   = $student_number;
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;
        $_SESSION['email']      = $email;

        $_SESSION['success'] = "Profile updated successfully!";
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Update failed: " . $e->getMessage();
    }
    
    header("Location: ../student/profile.php");
    exit();
}