<?php
// ✨ THE BROWSER'S INACTIVITY TIMER RAN OUT
// Marks the session as expired, then db_connect.php signs the user out the usual way
// (Auto Logout in the audit trail, message on the login screen).
session_start();
if (!empty($_SESSION['logged_in'])) $_SESSION['last_activity'] = 0;
require '../includes/db_connect.php';

// Already signed out (e.g. in another tab)
header('Location: ../student_login.php');
