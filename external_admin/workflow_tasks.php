<?php
session_start();include '../includes/session_manager.php';if(!isset($_SESSION['logged_in'])||$_SESSION['role']!=='External_Admin'){header('Location: ../admin_login.php');exit;}include '../includes/db_connect.php';require_once '../includes/application_authority.php';include '../includes/header.php';include '../includes/external_sidebar.php';include '../includes/workflow_tasks_view.php';include '../includes/footer.php';

