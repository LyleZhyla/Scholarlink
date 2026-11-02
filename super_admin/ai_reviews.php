<?php
session_start();include '../includes/session_manager.php';if(!isset($_SESSION['logged_in'])||$_SESSION['role']!=='Super_Admin'){header('Location: ../admin_login.php');exit;}include '../includes/db_connect.php';include '../includes/header.php';include '../includes/super_sidebar.php';include '../includes/ai_reviews_view.php';include '../includes/footer.php';
