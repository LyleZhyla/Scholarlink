<?php
session_start(); include '../includes/session_manager.php'; if(empty($_SESSION['logged_in'])||($_SESSION['role']??'')!=='External_Admin'){header('Location: ../admin_login.php');exit();}
include '../includes/db_connect.php';include '../includes/header.php';include '../includes/external_sidebar.php';include '../includes/benefits_admin_view.php';include '../includes/footer.php';
