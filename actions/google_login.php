<?php
session_start();
require '../vendor/autoload.php';
require '../includes/config.php';
require '../includes/db_connect.php';

// Capture intent (login or register)
if (isset($_GET['intent']) && $_GET['intent'] === 'register') {
    $_SESSION['google_intent'] = 'register';
} elseif (!isset($_GET['code'])) {
    // Default intent fallback if not returning from Google yet
    $_SESSION['google_intent'] = 'login';
}

// Create Client Request to access Google API
$client = new Google_Client();
$client->setClientId(GOOGLE_CLIENT_ID);
$client->setClientSecret(GOOGLE_CLIENT_SECRET);
$client->setRedirectUri(GOOGLE_REDIRECT_URL);
$client->addScope("email");
$client->addScope("profile");
$client->addScope("https://www.googleapis.com/auth/user.birthday.read");
$client->addScope("https://www.googleapis.com/auth/user.gender.read");

// ✨ FORCES GOOGLE TO SHOW THE ACCOUNT CHOOSER EVERY TIME
$client->setPrompt('select_account');

// 1. KUNG WALA PANG 'code', I-REDIRECT ANG STUDENT SA GOOGLE
if (!isset($_GET['code'])) {
    header("Location: " . $client->createAuthUrl());
    exit;
}

// 2. KUNG MAY 'code' NA (Bumalik na ang student mula sa Google auth)
try {
    // I-exchange ang code para sa access token
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if (isset($token['error'])) {
        throw new Exception("Google Authentication Error: " . $token['error']);
    }
    
    $client->setAccessToken($token['access_token']);

    // ✨ GAMITIN ANG PEOPLE API PARA MA-EXTRACT ANG GENDER AT BIRTHDAY
    $peopleService = new Google_Service_PeopleService($client);
    
    // Kunin ang partikular na fields na kailangan natin
    $person = $peopleService->people->get('people/me', ['personFields' => 'names,emailAddresses,birthdays,genders']);

    // Extract Basic Info
    $google_id = str_replace('people/', '', $person->getResourceName()); 
    $first_name = $person->getNames() ? $person->getNames()[0]->getGivenName() : '';
    $last_name = $person->getNames() ? $person->getNames()[0]->getFamilyName() : '';
    $email = $person->getEmailAddresses() ? $person->getEmailAddresses()[0]->getValue() : '';

    // ✨ Extract Gender
    $gender = '';
    if ($person->getGenders() && count($person->getGenders()) > 0) {
        // Papalitan natin ng uppercase ang unang letra (e.g., 'male' -> 'Male') para tugma sa dropdown mo
        $gender = ucfirst($person->getGenders()[0]->getValue()); 
    }

    // ✨ Extract Birthday
    $date_of_birth = '';
    if ($person->getBirthdays() && count($person->getBirthdays()) > 0) {
        $date = $person->getBirthdays()[0]->getDate();
        // Format to YYYY-MM-DD para pumasok sa <input type="date">
        if ($date && $date->getYear() && $date->getMonth() && $date->getDay()) {
            $date_of_birth = sprintf('%04d-%02d-%02d', $date->getYear(), $date->getMonth(), $date->getDay());
        }
    }

    // CHECK KUNG EXISTING NA ANG USER SA DATABASE (Login Logic)
    if ($_SESSION['google_intent'] === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE Email = ? AND Role = 'Student'");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Existing user, log them in immediately
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['UserID'];
            $_SESSION['role'] = $user['Role'];
            
            // I-update ang Google ID kung wala pa sa database
            if (empty($user['GoogleID'])) {
                $update = $pdo->prepare("UPDATE users SET GoogleID = ? WHERE UserID = ?");
                $update->execute([$google_id, $user['UserID']]);
            }
            
            header("Location: ../student/dashboard.php");
            exit;
        } else {
            $_SESSION['error'] = "No existing account found with this Google email. Please register first.";
            header("Location: ../student_login.php");
            exit;
        }
    }

    // KUNG REGISTRATION INTENT (I-pasa ang data sa form via Session)
    $_SESSION['google_signup_data'] = [
        'google_id'     => $google_id,
        'first_name'    => $first_name,
        'last_name'     => $last_name,
        'email'         => $email,
        'gender'        => $gender,
        'date_of_birth' => $date_of_birth
    ];

    // I-redirect pabalik sa modal para ma-auto-fill
    header("Location: ../student_login.php?action=completereg");
    exit;

} catch (Exception $e) {
    $_SESSION['error'] = "Authentication Failed: " . $e->getMessage();
    header("Location: ../student_login.php");
    exit;
}
?>