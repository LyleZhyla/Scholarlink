<?php
session_start();require '../includes/db_connect.php';require_once '../includes/evaluation_conflicts.php';
if($_SERVER['REQUEST_METHOD']!=='POST'||!isset($_SESSION['logged_in'])||!in_array($_SESSION['role']??'', ['External_Admin','Super_Admin'],true)){header('Location: ../admin_login.php');exit;}
$app=(int)($_POST['application_id']??0);$relationship=$_POST['relationship_type']??'';$details=trim((string)($_POST['details']??''));$base=$_SESSION['role']==='External_Admin'?'../external_admin/':'../super_admin/';
try{$pdo->beginTransaction();if(!canEvaluateApplication($pdo,$app,$_SESSION['role'],(int)$_SESSION['user_id']))throw new RuntimeException('You are not the assigned evaluator for this application.');$assignment=discloseEvaluationConflict($pdo,$app,(int)$_SESSION['user_id'],$relationship,$details);logAudit($pdo,(int)$_SESSION['user_id'],'Evaluation Conflict Disclosed',"Application #$app; assignment #$assignment; $relationship. $details");$pdo->commit();$_SESSION['success']='Conflict disclosed. Evaluation is blocked and the application is awaiting reassignment.';}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['error']=$e->getMessage();}
header('Location: '.$base.'evaluation_conflicts.php');exit;

