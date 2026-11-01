<?php
session_start();
require '../includes/db_connect.php';

// Security: Only logged-in students can upload
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../student_login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['document_file'])) {
    
    // 1. Get the Student ID for the currently logged-in user
    $stmt = $pdo->prepare("SELECT student_id FROM Student WHERE user_id = :uid");
    $stmt->execute(['uid' => $_SESSION['user_id']]);
    $student = $stmt->fetch();
    
    if (!$student) {
        $_SESSION['error'] = "Upload Failed: Student profile not found.";
        header("Location: ../student/requirements.php");
        exit();
    }
    
    $student_id = $student['student_id'];
    $document_type = trim($_POST['document_type'] ?? 'General Requirement');

    // 2. File Upload Settings
    $file = $_FILES['document_file'];
    $allowed_types = ['application/pdf'];
    $max_size = 5 * 1024 * 1024; // 5 MB maximum

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Upload Failed: Please select a valid file.";
        header("Location: ../student/requirements.php");
        exit();
    }

    if ($file['size'] > $max_size) {
        $_SESSION['error'] = "Upload Failed: File is too large. Maximum size is 5MB.";
        header("Location: ../student/requirements.php");
        exit();
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        $_SESSION['error'] = "Upload Failed: Only PDF files are allowed.";
        header("Location: ../student/requirements.php");
        exit();
    }

    // 3. Generate a Secure, Unique Filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $clean_type = preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '', $document_type));
    $new_filename = "STU-" . $student_id . "-" . $clean_type . "-" . uniqid() . "." . $extension;

    // 4. Define the Upload Path
    $upload_dir = '../uploads/documents/';
    $destination = $upload_dir . $new_filename;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // 5. Move the file and Save to YOUR EXACT "document" Table
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        try {
            // Perfectly aligned to your phpMyAdmin screenshot!
            $insert_stmt = $pdo->prepare("
                INSERT INTO document (student_id, document_type, file_path, verification_status) 
                VALUES (:sid, :type, :path, 'Pending')
            ");
            
            $insert_stmt->execute([
                'sid'   => $student_id,
                'type'  => $document_type,
                'path'  => $destination
            ]);

            $_SESSION['success'] = "Document uploaded successfully!";

        } catch (PDOException $e) {
            // If the Database fails, delete the file from the folder so we don't have junk files
            unlink($destination);
            $_SESSION['error'] = "Database Error: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Server Error: Could not save the file to the server folder.";
    }

    header("Location: ../student/requirements.php");
    exit();
}

// Fallback if accessed directly
header("Location: ../student/requirements.php");
exit();
?>