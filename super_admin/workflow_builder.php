<?php
session_start(); include '../includes/session_manager.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Super_Admin') { header('Location: ../admin_login.php'); exit; }
include '../includes/db_connect.php'; require_once '../includes/application_authority.php'; require_once '../includes/approval_workflow.php';
$id=(int)($_GET['id']??0); if(!canManageScholarshipWorkflow($pdo,$id,$_SESSION['role'],(int)$_SESSION['user_id'])){$_SESSION['error']='Scholarship not found.';header('Location: scholarships.php');exit;}
$stmt=$pdo->prepare('SELECT * FROM scholarship WHERE ScholarshipID=?');$stmt->execute([$id]);$scholarship=$stmt->fetch();$back_url='scholarships.php';
include '../includes/header.php';include '../includes/super_sidebar.php';include '../includes/workflow_builder_view.php';include '../includes/footer.php';

