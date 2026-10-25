<?php
session_start();
require '../includes/db_connect.php';

// ✨ Include your brilliant email configuration
require '../includes/email_config.php'; 

// 🔒 THE SECURITY GATEKEEPER
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'], ['Internal_Admin', 'External_Admin'])) {
    $_SESSION['error'] = "Unauthorized access.";
    header("Location: ../admin_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $application_id = $_POST['application_id'] ?? null;
    $new_status = $_POST['status'] ?? null; // 'Approved', 'Rejected', etc.

    if (!$application_id || !$new_status) {
        $_SESSION['error'] = "Missing application data.";
        header("Location: ../internal_admin/dashboard.php");
        exit();
    }

    // Internal Admins decide internally funded scholarships; providers decide only their own
    require_once '../includes/application_authority.php';
    requireApplicationAuthority($pdo, $application_id, '../internal_admin/dashboard.php');

    try {
        // 1. Update the application status in the database
        $update_stmt = $pdo->prepare("UPDATE application SET Status = ? WHERE ApplicationID = ?");
        $update_stmt->execute([$new_status, $application_id]);

        // 2. Fetch the Student and Scholarship data required for your email function
        $info_stmt = $pdo->prepare("
            SELECT u.Email, u.FirstName, s.Name AS ScholarshipName 
            FROM application a
            JOIN users u ON a.UserID = u.UserID
            JOIN scholarship s ON a.ScholarshipID = s.ScholarshipID
            WHERE a.ApplicationID = ?
        ");
        $info_stmt->execute([$application_id]);
        $student_data = $info_stmt->fetch(PDO::FETCH_ASSOC);

        // 3. ✨ FIRE THE AUTOMATED EMAIL ✨
        if ($student_data && !empty($student_data['Email'])) {
            // This calls the exact function you built in your email_config.php
            sendScholarshipEmail(
                $student_data['Email'], 
                $student_data['FirstName'], 
                $student_data['ScholarshipName'], 
                $new_status
            );
        }

        // 4. Return the Admin back to where they came from with a success message
        $_SESSION['success'] = "Application marked as $new_status and student notified via email! 📧";
        
        $redirect_url = $_SERVER['HTTP_REFERER'] ?? '../internal_admin/dashboard.php';
        header("Location: " . $redirect_url);
        exit();

    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        $redirect_url = $_SERVER['HTTP_REFERER'] ?? '../internal_admin/dashboard.php';
        header("Location: " . $redirect_url);
        exit();
    }
} else {
    header("Location: ../internal_admin/dashboard.php");
    exit();
}
?>