<?php
session_start();
require '../includes/db_connect.php';

// MUST BE INTERNAL ADMIN to approve/reject
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') {
    $_SESSION['error'] = "Unauthorized action.";
    header("Location: ../admin_login.php");
    exit();
}

$action = $_GET['action'] ?? '';
$app_id = $_GET['id'] ?? 0;
$new_status = '';

if ($app_id && $action) {
    if ($action === 'shortlist') {
        $new_status = 'Shortlisted';
    } elseif ($action === 'approve') {
        $new_status = 'Approved';
    } elseif ($action === 'reject') {
        $new_status = 'Rejected';
    }

    if ($new_status) {
        // Internal Admins may only decide on internally funded scholarships
        require_once '../includes/application_authority.php';
        requireApplicationAuthority($pdo, $app_id, '../internal_admin/manage_applications.php');

        try {
            $stmt = $pdo->prepare("UPDATE application SET Status = ? WHERE ApplicationID = ?");
            $stmt->execute([$new_status, $app_id]);
            
            // Add to audit log
            $desc = "Internal Admin marked Application #$app_id as $new_status";
            $audit = $pdo->prepare("INSERT INTO audit_log (UserID, ActionPerformed, Description) VALUES (?, ?, ?)");
            $audit->execute([$_SESSION['user_id'], 'Application ' . $new_status, $desc]);

            $_SESSION['success'] = "Application successfully marked as $new_status!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Redirect back to the correct tab
$tab_redirect = 'pending';
if ($new_status === 'Approved') $tab_redirect = 'approved';
if ($new_status === 'Rejected') $tab_redirect = 'rejected';
if ($new_status === 'Shortlisted') $tab_redirect = 'shortlisted';

header("Location: ../internal_admin/manage_applications.php?tab=" . $tab_redirect);
exit();
?>