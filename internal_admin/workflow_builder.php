<?php
session_start(); include '../includes/session_manager.php';
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'Internal_Admin') { header('Location: ../admin_login.php'); exit; }
include '../includes/db_connect.php'; require_once '../includes/application_authority.php'; require_once '../includes/approval_workflow.php';
$id=(int)($_GET['id']??0); if(!canManageScholarshipWorkflow($pdo,$id,$_SESSION['role'],(int)$_SESSION['user_id'])){$_SESSION['error']='You cannot edit this scholarship workflow.';header('Location: programs.php');exit;}
$stmt=$pdo->prepare('SELECT * FROM scholarship WHERE ScholarshipID=?');$stmt->execute([$id]);$scholarship=$stmt->fetch();$back_url='programs.php';
include '../includes/header.php';include '../includes/internal_sidebar.php';include '../includes/workflow_builder_view.php';include '../includes/footer.php';

