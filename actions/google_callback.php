<?php
session_start();
require '../vendor/autoload.php';
require '../includes/db_connect.php';

$env_path = __DIR__ . '/../.env';
$env = file_exists($env_path) ? parse_ini_file($env_path) : [];

$client_id = $env['GOOGLE_CLIENT_ID'] ?? '';
$client_secret = $env['GOOGLE_CLIENT_SECRET'] ?? '';
require_once '../includes/config.php';
$redirect_uri = GOOGLE_REDIRECT_URL;   // must match the one google_login.php sent to Google

$client = new Google\Client();
$client->setClientId($client_id);
$client->setClientSecret($client_secret);
$client->setRedirectUri($redirect_uri);

// ✨ REQUIRED: Idagdag ang scopes para mabasa ang gender at birthday
$client->addScope("email");
$client->addScope("profile");
$client->addScope("https://www.googleapis.com/auth/user.birthday.read");
$client->addScope("https://www.googleapis.com/auth/user.gender.read");

// Kung walang code, i-redirect ang user sa Google para mag-login
if (!isset($_GET['code'])) {
    header("Location: " . $client->createAuthUrl());
    exit();
}

if (isset($_GET['code'])) {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    if(!isset($token['error'])) {
        $client->setAccessToken($token['access_token']);
        
        // ✨ GAMITIN ANG PEOPLE SERVICE SA HALIP NA OAUTH2
        $peopleService = new Google\Service\PeopleService($client);
        $person = $peopleService->people->get('people/me', ['personFields' => 'names,emailAddresses,birthdays,genders']);

        $email = $person->getEmailAddresses() ? $person->getEmailAddresses()[0]->getValue() : '';
        $first_name = $person->getNames() ? $person->getNames()[0]->getGivenName() : '';
        $last_name = $person->getNames() ? $person->getNames()[0]->getFamilyName() : '';
        $google_id = str_replace('people/', '', $person->getResourceName());

        // ✨ EXTRACT GENDER
        $gender = '';
        if ($person->getGenders() && count($person->getGenders()) > 0) {
            $gender = ucfirst($person->getGenders()[0]->getValue()); // Gagawing 'Male' o 'Female'
        }

        // ✨ EXTRACT BIRTHDAY
        $date_of_birth = '';
        if ($person->getBirthdays() && count($person->getBirthdays()) > 0) {
            $date = $person->getBirthdays()[0]->getDate();
            if ($date && $date->getYear() && $date->getMonth() && $date->getDay()) {
                $date_of_birth = sprintf('%04d-%02d-%02d', $date->getYear(), $date->getMonth(), $date->getDay());
            }
        }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(Email) = LOWER(?) AND Role = 'Student'");
            $stmt->execute([trim($email)]);
            $user = $stmt->fetch();

        // Retrieve intent
        $intent = $_SESSION['google_intent'] ?? 'login';
        unset($_SESSION['google_intent']); // Clear intent after use

        if ($user) {
            if ($intent === 'register') {
                // PREVENT LOGIN: They clicked "Speed up with Google" on the Registration modal
                $_SESSION['error'] = "This Google account is already registered. Please log in.";
                header("Location: ../student_login.php");
                exit();
            } elseif ($blocked = accountArchivedMessage($user)) {
                // Archived accounts cannot sign in with Google either
                logAudit($pdo, $user['UserID'], 'Login Denied', $user['FirstName'] . ' ' . $user['LastName'] . ' tried to sign in with Google, but the account is archived');
                $_SESSION['error'] = $blocked;
                header("Location: ../student_login.php");
                exit();
            } else {
                // AFTER (Full Session Payload):
                    $_SESSION['logged_in']     = true;
                    $_SESSION['user_id']       = $user['UserID'];
                    $_SESSION['username']      = $user['Username'];
                    $_SESSION['role']          = $user['Role'];
                    $_SESSION['first_name']    = $user['FirstName'];
                    $_SESSION['last_name']     = $user['LastName'];
                    $_SESSION['email']         = $user['Email'];
                    $_SESSION['last_activity'] = time(); // Refreshes session_manager timeout

                    $_SESSION['success'] = "Welcome back, " . ($user['FirstName'] ?: $first_name) . "!";
                    header("Location: ../student/dashboard.php");
                    exit();
            }
        } else {
            if ($intent === 'login') {
                 // PREVENT REDIRECT TO REGISTRATION: They clicked login, but no account exists
                 $_SESSION['error'] = "No account found with this Google email. Please register first.";
                 header("Location: ../student_login.php");
                 exit();
            }

            // NEW ACCOUNT (INTENT = REGISTER): Send them to the registration modal
            $_SESSION['google_signup_data'] = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'google_id' => $google_id,
                'gender' => $gender,              // ✨ ISINAMA ANG GENDER
                'date_of_birth' => $date_of_birth // ✨ ISINAMA ANG BIRTHDAY
            ];
            header("Location: ../student_login.php?action=completereg");
            exit();
        }
    }
}

$_SESSION['error'] = "Google Sign-In failed. Please try again.";
header("Location: ../student_login.php");
exit();
?>