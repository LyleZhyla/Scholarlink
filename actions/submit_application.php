<?php
session_start();
require '../includes/db_connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $scholarship_id = $_POST['scholarship_id'];
    $essay = trim($_POST['notes'] ?? '');

    try {
        // Check for duplicates
        $check = $pdo->prepare("SELECT ApplicationID FROM Application WHERE UserID = :uid AND ScholarshipID = :sid");
        $check->execute(['uid' => $user_id, 'sid' => $scholarship_id]);
        
        if ($check->fetch()) {
            $_SESSION['error'] = "You already have a pending application for this scholarship.";
            header("Location: ../student/programs.php");
            exit();
        }

        // Insert the new application using the current student's GPA from Users table
        $stmt = $pdo->prepare("
            INSERT INTO Application (UserID, ScholarshipID, Status, DateSubmitted, Essay, GPA) 
            SELECT UserID, :sid, 'Submitted', NOW(), :essay, GPA 
            FROM Users WHERE UserID = :uid
        ");
        $stmt->execute(['uid' => $user_id, 'sid' => $scholarship_id, 'essay' => $essay]);

        $_SESSION['success'] = "Application submitted! Go get that bread! 🍞💚";
        header("Location: ../student/dashboard.php");
        exit();

    } catch (PDOException $e) {
        $_SESSION['error'] = "System Error: " . $e->getMessage();
        header("Location: ../student/programs.php");
        exit();
    }
}