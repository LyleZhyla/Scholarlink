-- ScholarLink database: structure and reference data only (programs, scholarships, requirements, forms, settings).
-- No students, applications or messages. Staff/provider accounts use the placeholder password ChangeMe@2026: change it after the first sign-in.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `academic_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `academic_terms` (
  `TermID` int(11) NOT NULL AUTO_INCREMENT,
  `TermName` varchar(100) NOT NULL,
  `Status` enum('Active','Ended') NOT NULL DEFAULT 'Active',
  `StartDate` date NOT NULL,
  `EndDate` date DEFAULT NULL,
  PRIMARY KEY (`TermID`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `academic_terms` WRITE;
/*!40000 ALTER TABLE `academic_terms` DISABLE KEYS */;
INSERT INTO `academic_terms` VALUES (1,'2nd Semester AY 2026-2027','Ended','2026-08-25','2026-08-28'),(2,'1st Semester AY 2027-2028','Ended','2026-08-28','2026-08-28'),(3,'2nd Semester AY 2027-2028','Ended','2026-08-28','2026-08-28'),(4,'1st Semester AY 2025-2026','Ended','2026-08-28','2026-08-28'),(5,'2nd Semester AY 2025-2026','Ended','2026-08-28','2026-08-28'),(7,'1st Semester AY 2025-2026','Ended','2026-08-28','2026-08-28'),(8,'1st Semester AY 2026-2027','Ended','2026-08-28','2026-08-28'),(9,'1st Semester AY 2026-2027','Active','2026-08-28',NULL);
/*!40000 ALTER TABLE `academic_terms` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `ai_document_analyses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_document_analyses` (
  `AnalysisID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `SubmittedDocID` int(11) NOT NULL,
  `SourceDocument` varchar(150) NOT NULL,
  `SourceFileHash` char(64) NOT NULL,
  `ModelName` varchar(100) DEFAULT NULL,
  `OverallConfidence` decimal(5,2) NOT NULL DEFAULT 0.00,
  `AuthenticitySuggestion` enum('Likely Authentic','Needs Review','Potential Issue') NOT NULL DEFAULT 'Needs Review',
  `QualificationSuggestion` enum('Meets Criterion','Fails Criterion','Not Applicable','Needs Review') NOT NULL DEFAULT 'Needs Review',
  `ExtractedFieldsJson` longtext DEFAULT NULL,
  `FailedCriteriaJson` longtext DEFAULT NULL,
  `Explanation` text DEFAULT NULL,
  `RequiresManualReview` tinyint(1) NOT NULL DEFAULT 1,
  `ReviewStatus` enum('Pending','Confirmed','Overridden','Inconclusive') NOT NULL DEFAULT 'Pending',
  `ManualDecision` enum('Verify Document','Request Correction','Inconclusive') DEFAULT NULL,
  `OverrideReason` text DEFAULT NULL,
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AnalysisID`),
  KEY `idx_ai_review_queue` (`RequiresManualReview`,`ReviewStatus`,`CreatedAt`),
  KEY `idx_ai_analysis_application` (`ApplicationID`,`SubmittedDocID`),
  KEY `fk_ai_analysis_document` (`SubmittedDocID`),
  KEY `fk_ai_analysis_creator` (`CreatedBy`),
  KEY `fk_ai_analysis_reviewer` (`ReviewedBy`),
  CONSTRAINT `fk_ai_analysis_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_analysis_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_analysis_document` FOREIGN KEY (`SubmittedDocID`) REFERENCES `submitted_document` (`SubmittedDocID`) ON DELETE CASCADE,
  CONSTRAINT `fk_ai_analysis_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `ai_document_analyses` WRITE;
/*!40000 ALTER TABLE `ai_document_analyses` DISABLE KEYS */;
/*!40000 ALTER TABLE `ai_document_analyses` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `appeal_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appeal_documents` (
  `AppealDocumentID` int(11) NOT NULL AUTO_INCREMENT,
  `AppealID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) NOT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AppealDocumentID`),
  KEY `idx_appeal_documents_appeal` (`AppealID`),
  CONSTRAINT `fk_appeal_documents_appeal` FOREIGN KEY (`AppealID`) REFERENCES `application_appeals` (`AppealID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `appeal_documents` WRITE;
/*!40000 ALTER TABLE `appeal_documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `appeal_documents` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application` (
  `ApplicationID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `DateSubmitted` timestamp NOT NULL DEFAULT current_timestamp(),
  `LastSavedAt` datetime DEFAULT NULL,
  `WithdrawnAt` datetime DEFAULT NULL,
  `ResubmissionAllowed` tinyint(1) NOT NULL DEFAULT 0,
  `DecisionAt` datetime DEFAULT NULL,
  `AppealDeadline` datetime DEFAULT NULL,
  `Status` varchar(50) DEFAULT 'Pending',
  `TotalScore` int(11) DEFAULT 0,
  `GPA` decimal(4,2) DEFAULT NULL,
  `YearLevel` varchar(20) DEFAULT NULL,
  `TermID` int(11) DEFAULT NULL,
  `ConfigurationVersionID` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`ApplicationID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  KEY `UserID` (`UserID`),
  KEY `idx_application_configuration_version` (`ConfigurationVersionID`),
  CONSTRAINT `application_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `application_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_configuration_version` FOREIGN KEY (`ConfigurationVersionID`) REFERENCES `scholarship_configuration_versions` (`ConfigurationVersionID`) ON DELETE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application` WRITE;
/*!40000 ALTER TABLE `application` DISABLE KEYS */;
/*!40000 ALTER TABLE `application` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_appeals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_appeals` (
  `AppealID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `ApplicantID` int(11) NOT NULL,
  `AppealReason` text NOT NULL,
  `Status` enum('Submitted','Under Review','Approved','Denied') NOT NULL DEFAULT 'Submitted',
  `Deadline` datetime NOT NULL,
  `SubmittedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewerComments` text DEFAULT NULL,
  `FinalResolution` text DEFAULT NULL,
  `ResolvedAt` datetime DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`AppealID`),
  UNIQUE KEY `uq_application_appeal` (`ApplicationID`),
  KEY `idx_appeal_status_deadline` (`Status`,`Deadline`),
  KEY `idx_appeal_applicant` (`ApplicantID`),
  KEY `fk_appeal_reviewer` (`ReviewedBy`),
  CONSTRAINT `fk_appeal_applicant` FOREIGN KEY (`ApplicantID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_appeal_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_appeal_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_appeals` WRITE;
/*!40000 ALTER TABLE `application_appeals` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_appeals` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_custom_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_custom_answers` (
  `AnswerID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `FieldID` int(11) NOT NULL,
  `AnswerText` text NOT NULL,
  PRIMARY KEY (`AnswerID`),
  KEY `ApplicationID` (`ApplicationID`),
  KEY `FieldID` (`FieldID`),
  CONSTRAINT `fk_aca_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_aca_field` FOREIGN KEY (`FieldID`) REFERENCES `scholarship_custom_fields` (`FieldID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_custom_answers` WRITE;
/*!40000 ALTER TABLE `application_custom_answers` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_custom_answers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_score_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_score_items` (
  `ScoreItemID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ScorecardID` bigint(20) unsigned NOT NULL,
  `CriteriaID` int(11) DEFAULT NULL,
  `CriteriaName` varchar(255) NOT NULL,
  `RawScore` decimal(8,2) NOT NULL,
  `MaximumScore` decimal(8,2) NOT NULL,
  `WeightPercent` decimal(5,2) NOT NULL,
  `WeightedScore` decimal(8,2) NOT NULL,
  `EvaluatorComment` text DEFAULT NULL,
  PRIMARY KEY (`ScoreItemID`),
  KEY `idx_score_item_scorecard` (`ScorecardID`),
  KEY `fk_score_item_criteria` (`CriteriaID`),
  CONSTRAINT `fk_score_item_criteria` FOREIGN KEY (`CriteriaID`) REFERENCES `scholarship_criteria` (`CriteriaID`) ON DELETE SET NULL,
  CONSTRAINT `fk_score_item_scorecard` FOREIGN KEY (`ScorecardID`) REFERENCES `application_scorecards` (`ScorecardID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_score_items` WRITE;
/*!40000 ALTER TABLE `application_score_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_score_items` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_scorecards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_scorecards` (
  `ScorecardID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) DEFAULT NULL,
  `RawTotal` decimal(10,2) NOT NULL,
  `WeightedScore` decimal(5,2) NOT NULL,
  `PassingScore` decimal(5,2) NOT NULL,
  `Result` enum('Passed','Failed') NOT NULL,
  `EvaluatorComments` text DEFAULT NULL,
  `RubricSnapshotJson` longtext NOT NULL,
  `IsCurrent` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ScorecardID`),
  KEY `idx_scorecard_application_current` (`ApplicationID`,`IsCurrent`),
  KEY `fk_scorecard_evaluator` (`EvaluatorID`),
  CONSTRAINT `fk_scorecard_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_scorecard_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_scorecards` WRITE;
/*!40000 ALTER TABLE `application_scorecards` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_scorecards` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_versions` (
  `VersionID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `VersionNumber` int(10) unsigned NOT NULL,
  `EventType` varchar(40) NOT NULL,
  `Status` varchar(50) NOT NULL,
  `GPASnapshot` decimal(4,2) DEFAULT NULL,
  `AnswersSnapshot` longtext DEFAULT NULL,
  `DocumentsSnapshot` longtext DEFAULT NULL,
  `Notes` varchar(500) DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`VersionID`),
  UNIQUE KEY `uq_application_version` (`ApplicationID`,`VersionNumber`),
  KEY `idx_application_versions_created` (`ApplicationID`,`CreatedAt`),
  KEY `fk_application_versions_user` (`CreatedBy`),
  CONSTRAINT `fk_application_versions_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_versions_user` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_versions` WRITE;
/*!40000 ALTER TABLE `application_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_waitlist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_waitlist` (
  `WaitlistID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `ScholarshipID` int(11) NOT NULL,
  `RankPosition` int(10) unsigned NOT NULL,
  `RankingScore` decimal(8,2) NOT NULL DEFAULT 0.00,
  `Status` enum('Waiting','Offered','Promoted','Removed') NOT NULL DEFAULT 'Waiting',
  `PlacedBy` int(11) DEFAULT NULL,
  `PlacedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `OfferedBy` int(11) DEFAULT NULL,
  `OfferedAt` datetime DEFAULT NULL,
  `PromotedBy` int(11) DEFAULT NULL,
  `PromotedAt` datetime DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`WaitlistID`),
  UNIQUE KEY `uq_waitlist_application` (`ApplicationID`),
  KEY `idx_waitlist_rank` (`ScholarshipID`,`Status`,`RankPosition`),
  KEY `fk_waitlist_placed_by` (`PlacedBy`),
  KEY `fk_waitlist_offered_by` (`OfferedBy`),
  KEY `fk_waitlist_promoted_by` (`PromotedBy`),
  CONSTRAINT `fk_waitlist_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_waitlist_offered_by` FOREIGN KEY (`OfferedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_waitlist_placed_by` FOREIGN KEY (`PlacedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_waitlist_promoted_by` FOREIGN KEY (`PromotedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_waitlist_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_waitlist` WRITE;
/*!40000 ALTER TABLE `application_waitlist` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_waitlist` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_workflow_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application_workflow_progress` (
  `ProgressID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `WorkflowStageID` int(11) DEFAULT NULL,
  `StageOrder` int(10) unsigned NOT NULL,
  `StageKey` varchar(50) NOT NULL,
  `StageName` varchar(120) NOT NULL,
  `AssignedRole` enum('Internal_Admin','External_Admin','Super_Admin') NOT NULL,
  `CompletionAction` enum('none','under_review','shortlisted','approve','award_confirm') NOT NULL DEFAULT 'none',
  `Instructions` text DEFAULT NULL,
  `Status` enum('Pending','In Progress','Completed','Returned','Skipped') NOT NULL DEFAULT 'Pending',
  `ReviewerComments` text DEFAULT NULL,
  `StartedAt` datetime DEFAULT NULL,
  `CompletedAt` datetime DEFAULT NULL,
  `CompletedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ProgressID`),
  UNIQUE KEY `uq_application_workflow_order` (`ApplicationID`,`StageOrder`),
  KEY `idx_workflow_queue` (`AssignedRole`,`Status`,`StageOrder`),
  KEY `fk_workflow_progress_stage` (`WorkflowStageID`),
  KEY `fk_workflow_progress_reviewer` (`CompletedBy`),
  CONSTRAINT `fk_workflow_progress_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_workflow_progress_reviewer` FOREIGN KEY (`CompletedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_workflow_progress_stage` FOREIGN KEY (`WorkflowStageID`) REFERENCES `scholarship_workflow_stages` (`WorkflowStageID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application_workflow_progress` WRITE;
/*!40000 ALTER TABLE `application_workflow_progress` DISABLE KEYS */;
/*!40000 ALTER TABLE `application_workflow_progress` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `AuditID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) DEFAULT NULL,
  `ActionPerformed` varchar(255) DEFAULT NULL,
  `ActionDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `Description` text DEFAULT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`AuditID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `backup_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_records` (
  `BackupID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `BackupType` enum('Scheduled','Manual','Pre-Restore') NOT NULL,
  `FileName` varchar(255) NOT NULL,
  `StoragePath` varchar(700) NOT NULL,
  `EncryptionMethod` varchar(80) NOT NULL DEFAULT 'AES-256-GCM',
  `PlainChecksum` char(64) NOT NULL,
  `EncryptedChecksum` char(64) NOT NULL,
  `FileSize` bigint(20) unsigned NOT NULL,
  `Status` enum('Available','Missing','Failed','Expired') NOT NULL DEFAULT 'Available',
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `VerifiedAt` datetime DEFAULT NULL,
  `FailureDetails` text DEFAULT NULL,
  PRIMARY KEY (`BackupID`),
  UNIQUE KEY `uq_backup_filename` (`FileName`),
  KEY `idx_backup_catalog` (`Status`,`CreatedAt`),
  KEY `fk_backup_creator` (`CreatedBy`),
  CONSTRAINT `fk_backup_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `backup_records` WRITE;
/*!40000 ALTER TABLE `backup_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `backup_records` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `benefit_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `benefit_releases` (
  `BenefitReleaseID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `BenefitAccountID` bigint(20) unsigned NOT NULL,
  `ApplicationID` int(11) NOT NULL,
  `Amount` decimal(12,2) NOT NULL,
  `ReleasePeriod` varchar(100) NOT NULL,
  `PaymentDate` date NOT NULL,
  `ReleaseMode` varchar(40) NOT NULL,
  `ReferenceNumber` varchar(100) DEFAULT NULL,
  `AcknowledgementReceipt` varchar(255) NOT NULL,
  `OriginalReceiptName` varchar(255) NOT NULL,
  `RecordedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`BenefitReleaseID`),
  KEY `idx_benefit_release_application_date` (`ApplicationID`,`PaymentDate`),
  KEY `idx_benefit_release_account` (`BenefitAccountID`),
  KEY `fk_benefit_release_recorded_by` (`RecordedBy`),
  CONSTRAINT `fk_benefit_release_account` FOREIGN KEY (`BenefitAccountID`) REFERENCES `scholar_benefit_accounts` (`BenefitAccountID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_release_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_release_recorded_by` FOREIGN KEY (`RecordedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `benefit_releases` WRITE;
/*!40000 ALTER TABLE `benefit_releases` DISABLE KEYS */;
/*!40000 ALTER TABLE `benefit_releases` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `criteria` (
  `CriteriaID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `CriteriaName` varchar(100) NOT NULL,
  `Weight` decimal(5,2) NOT NULL,
  PRIMARY KEY (`CriteriaID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `criteria_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `criteria` WRITE;
/*!40000 ALTER TABLE `criteria` DISABLE KEYS */;
/*!40000 ALTER TABLE `criteria` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `deadline_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deadline_reminders` (
  `ApplicationID` int(11) NOT NULL,
  `DaysBefore` tinyint(4) NOT NULL COMMENT '7, 3 or 1 day(s) before the deadline',
  `SentAt` datetime NOT NULL DEFAULT current_timestamp(),
  `Emailed` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ApplicationID`,`DaysBefore`),
  CONSTRAINT `fk_reminder_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `deadline_reminders` WRITE;
/*!40000 ALTER TABLE `deadline_reminders` DISABLE KEYS */;
/*!40000 ALTER TABLE `deadline_reminders` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `disaster_recovery_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `disaster_recovery_settings` (
  `SettingsID` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `ScheduleEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `ScheduleIntervalHours` int(10) unsigned NOT NULL DEFAULT 24,
  `RPOHours` decimal(8,2) NOT NULL DEFAULT 24.00,
  `RTOHours` decimal(8,2) NOT NULL DEFAULT 4.00,
  `SecondaryStoragePath` varchar(500) DEFAULT NULL,
  `RetentionDays` int(10) unsigned NOT NULL DEFAULT 30,
  `LastScheduledAt` datetime DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`SettingsID`),
  KEY `fk_dr_settings_updater` (`UpdatedBy`),
  CONSTRAINT `fk_dr_settings_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `disaster_recovery_settings` WRITE;
/*!40000 ALTER TABLE `disaster_recovery_settings` DISABLE KEYS */;
INSERT INTO `disaster_recovery_settings` VALUES (1,1,24,24.00,4.00,NULL,30,NULL,NULL,'2026-11-04 01:17:29');
/*!40000 ALTER TABLE `disaster_recovery_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `document_requirement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_requirement` (
  `RequirementID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `DocumentName` varchar(100) NOT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`RequirementID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `document_requirement_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `document_requirement` WRITE;
/*!40000 ALTER TABLE `document_requirement` DISABLE KEYS */;
INSERT INTO `document_requirement` VALUES (1,1,'Certificate of Registration (COR)',1),(2,1,'Report of Grades',1),(3,2,'Certificate of Registration (COR)',1),(4,2,'Report of Grades',1),(5,3,'Certificate of Registration (COR)',1),(6,3,'Report of Grades',1),(7,3,'Endorsement from Sports Development Director',1),(8,4,'Certificate of Registration (COR)',1),(9,4,'Report of Grades',1),(10,4,'Endorsement from Socio-Cultural Director',1),(11,5,'Certificate of Registration (COR)',1),(12,5,'Report of Grades',1),(13,5,'Endorsement from Publication Adviser',1),(14,6,'Certificate of Registration (COR)',1),(15,6,'Report of Grades',1),(16,6,'ROTC Commandant Endorsement',1),(17,7,'Certificate of Registration (COR)',1),(18,7,'Report of Grades',1),(19,7,'Endorsement from Adviser',1),(20,8,'Certificate of Registration (COR)',1),(21,8,'Report of Grades',1),(22,8,'Barangay Certificate of Indigency',1),(23,9,'Certificate of Registration (COR)',1),(24,9,'Report of Grades',1),(25,9,'Certificate of Indigency',1),(26,9,'Certificate of Good Moral Character',1),(27,10,'Certificate of Registration (COR)',1),(28,10,'Report of Grades',1),(29,10,'BIR Income Tax Return or Tax Exemption',1),(30,11,'Certificate of Registration (COR)',1),(31,11,'Report of Grades',1),(32,11,'Certificate of Good Moral Character',1),(33,12,'Certificate of Registration (COR)',1),(34,12,'Report of Grades',1),(35,12,'Barangay Certificate of Indigency',1),(36,13,'Certificate of Registration (COR)',1),(37,13,'Report of Grades',1),(38,13,'Barangay Certificate of Indigency',1),(39,14,'Certificate of Registration (COR)',1),(40,14,'Report of Grades',1),(41,14,'Barangay Certificate of Indigency',1),(42,15,'Certificate of Registration (COR)',1),(43,15,'Report of Grades',1),(44,15,'Barangay Certificate of Indigency',1),(45,16,'Certificate of Registration (COR)',1),(46,16,'Report of Grades',1),(47,16,'Barangay Certificate of Residency',1),(48,16,'DSWD Certificate of Indigency',1),(49,16,'Certificate of Good Moral Character',1),(50,16,'Recommendation Letter 1',1),(51,17,'Certificate of Registration (COR)',1),(52,17,'Report of Grades',1),(53,17,'DSWD Certificate of Indigency',1),(54,17,'Certificate of Good Moral Character',1),(55,17,'Recommendation Letter 1',1),(56,18,'Certificate of Registration (COR)',1),(57,18,'Report of Grades',1),(58,18,'Barangay Certificate of Residency',1),(59,18,'DSWD Certificate of Indigency',1),(60,19,'Certificate of Registration (COR)',1),(61,19,'Report of Grades',1),(62,19,'Barangay Certificate of Indigency',1),(63,20,'Certificate of Registration (COR)',1),(64,20,'Report of Grades',1),(65,20,'Barangay Certificate of Residency',1),(66,20,'DSWD Certificate of Indigency',1),(67,20,'Certificate of Good Moral Character',1),(68,20,'Recommendation Letter 1',1),(70,21,'Report of Grades',1),(71,21,'Certificate of Good Moral Character',1),(72,21,'Recommendation Letter 1',1),(73,21,'Medical Certificate (Fit, X-ray, Urinalysis, Fecalysis, Eye Exam)',1),(74,22,'Certificate of Registration (COR)',1),(75,22,'Report of Grades',1),(76,22,'Barangay Certificate of Residency',1),(77,22,'DSWD Certificate of Indigency',1),(78,22,'Certificate of Good Moral Character',1),(79,22,'Recommendation Letter 1',1),(80,23,'Certificate of Registration (COR)',1),(81,23,'Report of Grades',1),(82,23,'IP Certification/ID',1),(83,23,'Barangay Certificate of Residency',1),(84,23,'DSWD Certificate of Indigency',1),(85,23,'Certificate of Good Moral Character',1),(86,23,'Recommendation Letter 1',1),(87,24,'Certificate of Registration (COR)',1),(88,24,'Report of Grades',1),(89,24,'Barangay Certificate of Residency',1),(90,24,'DSWD Certificate of Indigency',1),(91,24,'Certificate of Good Moral Character',1),(92,24,'Recommendation Letter 1',1),(93,25,'Certificate of Registration (COR)',1),(94,25,'Report of Grades',1),(95,25,'Barangay Certificate of Indigency (Mayantoc)',1),(96,25,'MSWDO Certificate of Indigency',1),(97,25,'BIR Certificate of Tax Exemption',1),(98,26,'Certificate of Registration (COR)',1),(99,26,'Report of Grades',1),(100,26,'Income Tax Return (ITR)',1),(101,27,'Certificate of Registration (COR)',1),(102,27,'Report of Grades',1),(103,27,'Barangay Certificate of Residency',1),(104,27,'DSWD Certificate of Indigency',1),(105,27,'Certificate of Good Moral Character',1),(106,27,'Recommendation Letter 1',1),(107,16,'Recommendation Letter 2',1),(108,17,'Recommendation Letter 2',1),(109,20,'Recommendation Letter 2',1),(110,21,'Recommendation Letter 2',1),(111,22,'Recommendation Letter 2',1),(112,23,'Recommendation Letter 2',1),(113,24,'Recommendation Letter 2',1),(114,27,'Recommendation Letter 2',1),(115,21,'Certificate of Registration',1);
/*!40000 ALTER TABLE `document_requirement` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `evaluation_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_assignments` (
  `AssignmentID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) NOT NULL,
  `AssignedBy` int(11) DEFAULT NULL,
  `AssignmentStatus` enum('Active','Conflict','Reassigned','Completed') NOT NULL DEFAULT 'Active',
  `AssignmentReason` varchar(255) DEFAULT NULL,
  `AssignedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `EndedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`AssignmentID`),
  KEY `idx_evaluation_assignment_active` (`ApplicationID`,`AssignmentStatus`),
  KEY `idx_evaluation_assignment_evaluator` (`EvaluatorID`,`AssignmentStatus`),
  KEY `fk_eval_assignment_assigner` (`AssignedBy`),
  CONSTRAINT `fk_eval_assignment_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_assignment_assigner` FOREIGN KEY (`AssignedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_eval_assignment_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `evaluation_assignments` WRITE;
/*!40000 ALTER TABLE `evaluation_assignments` DISABLE KEYS */;
/*!40000 ALTER TABLE `evaluation_assignments` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `evaluation_conflict_declarations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `evaluation_conflict_declarations` (
  `DeclarationID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `AssignmentID` bigint(20) unsigned NOT NULL,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) NOT NULL,
  `ConflictStatus` enum('No Conflict','Conflict Disclosed') NOT NULL,
  `RelationshipType` enum('None','Relative','Personally Known','Financial or Business Interest','Other Prohibited Relationship') NOT NULL DEFAULT 'None',
  `Details` text DEFAULT NULL,
  `ResolutionStatus` enum('Cleared','Pending Reassignment','Reassigned') NOT NULL,
  `ReassignedTo` int(11) DEFAULT NULL,
  `ResolvedBy` int(11) DEFAULT NULL,
  `DeclaredAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ResolvedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`DeclarationID`),
  UNIQUE KEY `uq_conflict_assignment` (`AssignmentID`),
  KEY `idx_conflict_queue` (`ResolutionStatus`,`DeclaredAt`),
  KEY `fk_conflict_application` (`ApplicationID`),
  KEY `fk_conflict_evaluator` (`EvaluatorID`),
  KEY `fk_conflict_reassigned_to` (`ReassignedTo`),
  KEY `fk_conflict_resolver` (`ResolvedBy`),
  CONSTRAINT `fk_conflict_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_assignment` FOREIGN KEY (`AssignmentID`) REFERENCES `evaluation_assignments` (`AssignmentID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_reassigned_to` FOREIGN KEY (`ReassignedTo`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_conflict_resolver` FOREIGN KEY (`ResolvedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `evaluation_conflict_declarations` WRITE;
/*!40000 ALTER TABLE `evaluation_conflict_declarations` DISABLE KEYS */;
/*!40000 ALTER TABLE `evaluation_conflict_declarations` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `form_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `form_templates` (
  `TemplateID` int(11) NOT NULL AUTO_INCREMENT,
  `FileName` varchar(150) NOT NULL,
  `Label` varchar(150) NOT NULL,
  `UploadedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`TemplateID`),
  UNIQUE KEY `FileName` (`FileName`),
  KEY `UploadedBy` (`UploadedBy`),
  CONSTRAINT `fk_ft_user` FOREIGN KEY (`UploadedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `form_templates` WRITE;
/*!40000 ALTER TABLE `form_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `form_templates` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `landing_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `landing_content` (
  `section_key` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  PRIMARY KEY (`section_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `landing_content` WRITE;
/*!40000 ALTER TABLE `landing_content` DISABLE KEYS */;
INSERT INTO `landing_content` VALUES ('grants_header','Active Scholarships','Currently accepting applications for this semester.'),('hero','Unlock your future with ScholarLink.','Discover financial assistance programs, track your applications, and focus on your education. Browse the available TAU grants below to get started.'),('no_grants','No active scholarships','There are currently no scholarship programs accepting applications. Please check back later.');
/*!40000 ALTER TABLE `landing_content` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `MessageID` int(11) NOT NULL AUTO_INCREMENT,
  `SenderID` int(11) NOT NULL,
  `ReceiverID` int(11) NOT NULL,
  `MessageText` text NOT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`MessageID`),
  KEY `SenderID` (`SenderID`),
  KEY `ReceiverID` (`ReceiverID`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`SenderID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`ReceiverID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `NotificationID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `Title` varchar(255) NOT NULL,
  `Message` text NOT NULL,
  `Type` varchar(50) DEFAULT 'info',
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`NotificationID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `privacy_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privacy_consents` (
  `ConsentID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `NoticeVersion` varchar(40) NOT NULL,
  `ConsentStatus` enum('Granted','Withdrawn') NOT NULL DEFAULT 'Granted',
  `ConsentText` varchar(500) NOT NULL,
  `IPAddress` varchar(45) DEFAULT NULL,
  `GrantedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `WithdrawnAt` datetime DEFAULT NULL,
  PRIMARY KEY (`ConsentID`),
  KEY `idx_privacy_consent_user` (`UserID`,`GrantedAt`),
  CONSTRAINT `fk_privacy_consent_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `privacy_consents` WRITE;
/*!40000 ALTER TABLE `privacy_consents` DISABLE KEYS */;
/*!40000 ALTER TABLE `privacy_consents` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `privacy_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privacy_requests` (
  `RequestID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `RequestType` enum('Access','Correction','Anonymization','Erasure or Blocking','Consent Withdrawal') NOT NULL,
  `RequestDetails` text NOT NULL,
  `Status` enum('Submitted','Under Review','Approved','Partially Approved','Denied','Completed') NOT NULL DEFAULT 'Submitted',
  `ResolutionNotes` text DEFAULT NULL,
  `ReviewedBy` int(11) DEFAULT NULL,
  `SubmittedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DueAt` datetime NOT NULL,
  `ResolvedAt` datetime DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`RequestID`),
  KEY `idx_privacy_request_queue` (`Status`,`DueAt`),
  KEY `fk_privacy_request_user` (`UserID`),
  KEY `fk_privacy_request_reviewer` (`ReviewedBy`),
  CONSTRAINT `fk_privacy_request_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_privacy_request_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `privacy_requests` WRITE;
/*!40000 ALTER TABLE `privacy_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `privacy_requests` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `privacy_retention_cases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privacy_retention_cases` (
  `CaseID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `PolicyKey` varchar(50) NOT NULL,
  `RecordType` varchar(50) NOT NULL,
  `RecordID` varchar(255) NOT NULL,
  `SubjectUserID` int(11) DEFAULT NULL,
  `RecordDescription` varchar(500) NOT NULL,
  `EligibleAt` datetime NOT NULL,
  `LegalHold` tinyint(1) NOT NULL DEFAULT 0,
  `LegalHoldReason` varchar(500) DEFAULT NULL,
  `Status` enum('Eligible','Approved','Deferred','Completed','Cancelled') NOT NULL DEFAULT 'Eligible',
  `ApprovalReason` text DEFAULT NULL,
  `ApprovedBy` int(11) DEFAULT NULL,
  `ApprovedAt` datetime DEFAULT NULL,
  `ExecutedBy` int(11) DEFAULT NULL,
  `ExecutedAt` datetime DEFAULT NULL,
  `DispositionEvidence` varchar(500) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`CaseID`),
  UNIQUE KEY `uq_retention_record` (`PolicyKey`,`RecordType`,`RecordID`),
  KEY `idx_retention_queue` (`Status`,`LegalHold`,`EligibleAt`),
  KEY `fk_retention_case_subject` (`SubjectUserID`),
  KEY `fk_retention_case_approver` (`ApprovedBy`),
  KEY `fk_retention_case_executor` (`ExecutedBy`),
  CONSTRAINT `fk_retention_case_approver` FOREIGN KEY (`ApprovedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_retention_case_executor` FOREIGN KEY (`ExecutedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_retention_case_subject` FOREIGN KEY (`SubjectUserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `privacy_retention_cases` WRITE;
/*!40000 ALTER TABLE `privacy_retention_cases` DISABLE KEYS */;
/*!40000 ALTER TABLE `privacy_retention_cases` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `privacy_retention_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `privacy_retention_policies` (
  `PolicyID` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `PolicyKey` varchar(50) NOT NULL,
  `RecordLabel` varchar(120) NOT NULL,
  `RetentionDays` int(10) unsigned NOT NULL,
  `TriggerEvent` varchar(255) NOT NULL,
  `DisposalMethod` enum('Anonymize','Secure Delete','Review Only') NOT NULL,
  `LegalBasis` varchar(500) NOT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PolicyID`),
  UNIQUE KEY `uq_retention_policy_key` (`PolicyKey`),
  KEY `fk_retention_policy_updater` (`UpdatedBy`),
  CONSTRAINT `fk_retention_policy_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `privacy_retention_policies` WRITE;
/*!40000 ALTER TABLE `privacy_retention_policies` DISABLE KEYS */;
INSERT INTO `privacy_retention_policies` VALUES (1,'applications','Withdrawn or closed applications',1825,'Final closure or withdrawal date','Anonymize','Scholarship administration, institutional records rules, and applicable sponsor requirements',1,NULL,'2026-11-04 01:17:29'),(2,'uploaded_documents','Uploaded application documents',1825,'Application final closure date','Secure Delete','Data minimization after the scholarship transaction and required verification period',1,NULL,'2026-11-04 01:17:29'),(3,'rejected_applicants','Rejected or disqualified applicants',730,'Final rejection/disqualification date after appeal period','Anonymize','Defend decisions during the appeal/complaint period, then minimize personal data',1,NULL,'2026-11-04 01:17:29'),(4,'scholar_records','Approved scholar and benefit records',3650,'End of scholarship or final benefit release','Review Only','Long-term institutional, sponsor, accounting, and government reporting obligations',1,NULL,'2026-11-04 01:17:29'),(5,'audit_logs','Security and activity audit logs',2555,'Activity date','Secure Delete','Security accountability, investigation, and institutional audit requirements',1,NULL,'2026-11-04 01:17:29'),(6,'backups','Database backup files',30,'Backup creation date','Secure Delete','Recovery window balanced with storage limitation and exposure risk',1,NULL,'2026-11-04 01:17:29');
/*!40000 ALTER TABLE `privacy_retention_policies` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `profile_change_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `profile_change_requests` (
  `RequestID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `Changes` text NOT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'Pending',
  `ReviewNote` varchar(500) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ReviewedBy` int(11) DEFAULT NULL,
  `ReviewedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`RequestID`),
  KEY `idx_profile_req_status` (`Status`,`CreatedAt`),
  KEY `idx_profile_req_user` (`UserID`,`Status`),
  CONSTRAINT `fk_profile_req_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `profile_change_requests` WRITE;
/*!40000 ALTER TABLE `profile_change_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `profile_change_requests` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `program`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `program` (
  `ProgramID` int(11) NOT NULL AUTO_INCREMENT,
  `ProgramName` varchar(150) NOT NULL,
  PRIMARY KEY (`ProgramID`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `program` WRITE;
/*!40000 ALTER TABLE `program` DISABLE KEYS */;
INSERT INTO `program` VALUES (1,'BS Agriculture'),(2,'Bachelor of Animal Science (BAS)'),(3,'BS Forestry'),(4,'BS Food Technology'),(5,'AB Economics'),(6,'BS Psychology'),(7,'BS Development Communication'),(8,'BS Business Administration'),(9,'BS Entrepreneurship'),(10,'BS Agribusiness'),(11,'BS Tourism Management'),(12,'Bachelor of Elementary Education (BEEd)'),(13,'Bachelor of Secondary Education (BSEd)'),(14,'Bachelor of Early Childhood Education (BECEd)'),(15,'Bachelor of Technology and Livelihood Education (BTLEd)'),(16,'BS Agricultural and Biosystems Engineering'),(17,'BS Geodetic Engineering'),(18,'BS Information Technology (BSIT)'),(19,'Doctor of Veterinary Medicine (DVM)');
/*!40000 ALTER TABLE `program` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `restoration_drills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `restoration_drills` (
  `DrillID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `BackupID` bigint(20) unsigned NOT NULL,
  `TargetDatabase` varchar(100) NOT NULL,
  `Status` enum('Running','Passed','Failed') NOT NULL DEFAULT 'Running',
  `StartedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `CompletedAt` datetime DEFAULT NULL,
  `BackupAgeHours` decimal(10,2) DEFAULT NULL,
  `RestoreDurationSeconds` decimal(12,3) DEFAULT NULL,
  `TableCount` int(10) unsigned DEFAULT NULL,
  `RowCount` bigint(20) unsigned DEFAULT NULL,
  `RPOCompliant` tinyint(1) DEFAULT NULL,
  `RTOCompliant` tinyint(1) DEFAULT NULL,
  `ValidationNotes` text DEFAULT NULL,
  `ExecutedBy` int(11) DEFAULT NULL,
  PRIMARY KEY (`DrillID`),
  KEY `idx_drill_history` (`Status`,`StartedAt`),
  KEY `fk_drill_backup` (`BackupID`),
  KEY `fk_drill_executor` (`ExecutedBy`),
  CONSTRAINT `fk_drill_backup` FOREIGN KEY (`BackupID`) REFERENCES `backup_records` (`BackupID`),
  CONSTRAINT `fk_drill_executor` FOREIGN KEY (`ExecutedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `restoration_drills` WRITE;
/*!40000 ALTER TABLE `restoration_drills` DISABLE KEYS */;
/*!40000 ALTER TABLE `restoration_drills` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholar_benefit_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholar_benefit_accounts` (
  `BenefitAccountID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `TotalEntitlement` decimal(12,2) NOT NULL,
  `ReleaseFrequency` varchar(50) NOT NULL,
  `Notes` varchar(500) DEFAULT NULL,
  `SetBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`BenefitAccountID`),
  UNIQUE KEY `uq_benefit_account_application` (`ApplicationID`),
  KEY `fk_benefit_account_set_by` (`SetBy`),
  CONSTRAINT `fk_benefit_account_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_account_set_by` FOREIGN KEY (`SetBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholar_benefit_accounts` WRITE;
/*!40000 ALTER TABLE `scholar_benefit_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholar_benefit_accounts` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholar_renewals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholar_renewals` (
  `RenewalID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `TermID` int(11) NOT NULL,
  `ROG_FilePath` varchar(255) DEFAULT NULL,
  `COR_FilePath` varchar(255) DEFAULT NULL,
  `Appeal_FilePath` varchar(255) DEFAULT NULL,
  `AI_Extracted_GWA` decimal(4,2) DEFAULT NULL,
  `AI_Remarks` text DEFAULT NULL,
  `Status` enum('Pending_Submission','Under_Review','Failed_GWA','Appeal_Requested','Renewed','Dropped') NOT NULL DEFAULT 'Pending_Submission',
  `AdminNotes` text DEFAULT NULL,
  `DateCreated` datetime NOT NULL DEFAULT current_timestamp(),
  `DateSubmitted` datetime DEFAULT NULL,
  `AdminDecisionDate` datetime DEFAULT NULL,
  PRIMARY KEY (`RenewalID`),
  KEY `ApplicationID` (`ApplicationID`),
  KEY `UserID` (`UserID`),
  KEY `TermID` (`TermID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholar_renewals` WRITE;
/*!40000 ALTER TABLE `scholar_renewals` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholar_renewals` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship` (
  `ScholarshipID` int(11) NOT NULL AUTO_INCREMENT,
  `ProgramID` int(11) DEFAULT NULL,
  `YearLevel` varchar(50) DEFAULT NULL,
  `Name` varchar(150) NOT NULL,
  `Description` text DEFAULT NULL,
  `Deadline` date NOT NULL,
  `AwardAmount` decimal(10,2) DEFAULT NULL,
  `NumberOfSlots` int(11) DEFAULT NULL,
  `Status` varchar(20) DEFAULT 'Active',
  `MinimumGWA` decimal(4,2) DEFAULT 2.00,
  `CreatedBy` int(11) DEFAULT NULL,
  `GenderRequirement` varchar(20) NOT NULL DEFAULT 'Any',
  `ScholarshipType` enum('Private','Government') DEFAULT 'Private',
  `Category` varchar(50) NOT NULL DEFAULT 'Scholarship',
  `FormConfig` varchar(255) DEFAULT 'Academic,Family,Financial,Essay',
  `ReleaseFrequency` varchar(50) DEFAULT 'Per Semester',
  `AllowsDual` enum('Yes','No') NOT NULL DEFAULT 'No',
  `AppealWindowDays` smallint(5) unsigned NOT NULL DEFAULT 7,
  `FormInstructions` text DEFAULT NULL,
  `FormUpdatedAt` datetime DEFAULT NULL,
  `FormTemplate` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`ScholarshipID`),
  KEY `ProgramID` (`ProgramID`),
  KEY `fk_scholarship_creator` (`CreatedBy`),
  CONSTRAINT `fk_scholarship_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `scholarship_ibfk_1` FOREIGN KEY (`ProgramID`) REFERENCES `program` (`ProgramID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship` WRITE;
/*!40000 ALTER TABLE `scholarship` DISABLE KEYS */;
INSERT INTO `scholarship` VALUES (1,NULL,NULL,'Academic Scholars - Full','GWA of 1.20-1.00 of an academic load for regular students with no dropped, conditional, or failed grades.','2026-08-30',5000.00,NULL,'Active',1.20,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(2,NULL,NULL,'Academic Scholars - Partial','GWA of 1.75-1.21. Must be regularly enrolled with a minimum of 12 units and no dropped, conditional, or failed grades.','2026-08-30',1500.00,NULL,'Active',1.75,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(3,NULL,NULL,'Athletes Regular Training Allowance','Subject to monitoring, evaluation, and endorsement of the Director for Sports Development.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(4,NULL,NULL,'Cultural Performers Allowance','Subject to monitoring, evaluation, and endorsement of the Socio-Cultural Development Director.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(5,NULL,NULL,'Golden Harvest Incentives','Incentives for the Official Student Publication editorial board.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Incentive','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(6,NULL,NULL,'ROTC Cadet Allowance','Incentives for First Class (P5,000) and Second Class (P2,500) Cadets.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(7,NULL,NULL,'SSC/CSC Leadership Incentives','For students holding leadership positions indicated in the TAU Code.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Incentive','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(8,NULL,NULL,'Tulong Dunong Program','One-time SUC program for low-income earning families.','2026-09-15',7500.00,NULL,'Active',3.00,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(9,1,NULL,'City of Tarlac Integrated Scholarship (Agriculture)','For indigent families enrolled in Agriculture/Agri-related courses.','2026-09-15',3500.00,NULL,'Active',2.00,17,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(10,NULL,NULL,'ACEF-GIAHEP Program','For agriculture, forestry, fisheries, and veterinary medicine students.','2026-09-30',12500.00,NULL,'Active',3.00,19,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(11,NULL,NULL,'DOST Scholarships (RA 7687 / MERIT)','For talented students in priority S&T courses.','2026-09-30',14000.00,NULL,'Active',2.00,18,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(12,NULL,NULL,'Tertiary Educational Subsidy (TES)','For low-income families certified by the Barangay.','2026-10-15',10000.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(13,NULL,NULL,'TES Tulong Dunong Program','For low-income families certified by the Barangay.','2026-10-15',7500.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(14,NULL,NULL,'Tulong Agri Program','For students in agriculture, fisheries, forestry, food tech, and veterinary medicine.','2026-10-15',13500.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(15,NULL,NULL,'TAU Alumni and Friends Grant','For bonafide TAU students from low-income families.','2026-09-15',4000.00,NULL,'Active',3.00,24,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(16,NULL,'1st Year','Lorna Fernando Dinos Scholarship Program','For 1st-year single students (max 25 yrs old) in Tarlac.','2026-08-30',9000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(17,NULL,'3rd Year','Philchema Inc. Scholarship Grant','For 3rd-year BS Animal Science, Agriculture, or VetMed students.','2026-08-30',10000.00,NULL,'Active',2.00,20,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(18,NULL,NULL,'Prado Builders Scholarship','For residents of Tarlac Province from low-income families.','2026-09-15',20000.00,NULL,'Active',3.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(19,NULL,'4th Year','The Camileños Inc. Scholarship Program','For graduating students from low-income families.','2026-08-30',3000.00,NULL,'Active',3.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(20,NULL,'1st Year','Tolentino - Dahlgren Scholarship Program','For 1st-year students from Tarlac.','2026-08-30',9000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(21,NULL,'2nd Year, 4th Year','Bounty Cares Foundation Inc.','For 2nd-year Agri, Biosystems, Food Tech, Biology, Chem, and 4th-year VetMed.','2026-08-25',37740.00,20,'Active',2.50,21,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(22,NULL,'1st Year','Cristobal Partido Scholarship Inc.','For 1st-year students from Tarlac.','2026-08-30',9573.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(23,NULL,'1st Year','Scholarship Program for IP Student','For 1st-year Indigenous Peoples (IP) students from Tarlac.','2026-09-30',12000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(24,NULL,'1st Year','CJ and LJ Scholarships','For 1st-year students from Tarlac.','2026-09-15',5000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(25,NULL,'3rd Year','Don Francisco Santos Scholarship','For regular 3rd-year students. Must have a GPA of 2.5 or higher and no incomplete grades.','2026-09-30',6000.00,NULL,'Active',2.50,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(26,NULL,'1st Year','Ninoy and Cory Aquino Foundation Inc.','For 1st-year students. Annual household gross income must not exceed P375,000.00.','2026-09-15',15000.00,NULL,'Active',2.00,22,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL),(27,17,'1st Year','Geodetic Engineers of the Philippines Inc.','For 1st-year BS Geodetic Engineering students from Tarlac.','2026-09-15',2000.00,NULL,'Active',2.00,23,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',7,NULL,NULL,NULL);
/*!40000 ALTER TABLE `scholarship` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_award_notice`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_award_notice` (
  `ScholarshipID` int(11) NOT NULL,
  `DisplayName` varchar(150) DEFAULT NULL COMMENT 'Name shown on the notice (blank = scholarship name)',
  `Semester` varchar(40) DEFAULT NULL COMMENT 'Blank = active term',
  `AcademicYear` varchar(20) DEFAULT NULL COMMENT 'Blank = active term',
  `NoticeDate` date DEFAULT NULL COMMENT 'Blank = date generated',
  `BatchLabel` varchar(60) DEFAULT NULL,
  `Intro` varchar(255) NOT NULL DEFAULT 'Visit the OSSD and submit the following:',
  `Requirements` text NOT NULL COMMENT 'JSON: [{"text": "...", "sub": ["...", "..."]}]',
  `SubmissionDeadline` date DEFAULT NULL,
  `ClosingNote` text DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ScholarshipID`),
  CONSTRAINT `fk_award_notice_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_award_notice` WRITE;
/*!40000 ALTER TABLE `scholarship_award_notice` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_award_notice` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_configuration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_configuration_versions` (
  `ConfigurationVersionID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `VersionNumber` int(10) unsigned NOT NULL,
  `SnapshotHash` char(64) NOT NULL,
  `SnapshotJson` longtext NOT NULL,
  `ChangeSummary` varchar(255) DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ConfigurationVersionID`),
  UNIQUE KEY `uq_scholarship_config_version` (`ScholarshipID`,`VersionNumber`),
  KEY `idx_scholarship_config_hash` (`ScholarshipID`,`SnapshotHash`),
  KEY `fk_config_version_creator` (`CreatedBy`),
  CONSTRAINT `fk_config_version_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_config_version_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_configuration_versions` WRITE;
/*!40000 ALTER TABLE `scholarship_configuration_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_configuration_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_criteria` (
  `CriteriaID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `CriteriaName` varchar(255) NOT NULL,
  `WeightPercent` decimal(5,2) NOT NULL DEFAULT 100.00,
  `MaximumScore` decimal(8,2) NOT NULL DEFAULT 100.00,
  `PassingScore` decimal(8,2) DEFAULT NULL,
  `CommentRequired` tinyint(1) NOT NULL DEFAULT 0,
  `TieBreakPriority` int(10) unsigned DEFAULT NULL,
  `SortOrder` int(10) unsigned NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`CriteriaID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `scholarship_criteria_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_criteria` WRITE;
/*!40000 ALTER TABLE `scholarship_criteria` DISABLE KEYS */;
INSERT INTO `scholarship_criteria` VALUES (1,1,'Essay',100.00,100.00,NULL,0,NULL,0,1),(2,2,'Essay',100.00,100.00,NULL,0,NULL,0,1),(3,3,'Essay',100.00,100.00,NULL,0,NULL,0,1),(4,4,'Essay',100.00,100.00,NULL,0,NULL,0,1),(5,5,'Essay',100.00,100.00,NULL,0,NULL,0,1),(6,6,'Essay',100.00,100.00,NULL,0,NULL,0,1),(7,7,'Essay',100.00,100.00,NULL,0,NULL,0,1),(8,8,'Essay',100.00,100.00,NULL,0,NULL,0,1),(9,9,'Essay',100.00,100.00,NULL,0,NULL,0,1),(10,10,'Essay',100.00,100.00,NULL,0,NULL,0,1),(11,11,'Essay',100.00,100.00,NULL,0,NULL,0,1),(12,12,'Essay',100.00,100.00,NULL,0,NULL,0,1),(13,13,'Essay',100.00,100.00,NULL,0,NULL,0,1),(15,15,'Essay',100.00,100.00,NULL,0,NULL,0,1),(16,16,'Essay',100.00,100.00,NULL,0,NULL,0,1),(17,17,'Essay',100.00,100.00,NULL,0,NULL,0,1),(18,18,'Essay',100.00,100.00,NULL,0,NULL,0,1),(19,19,'Essay',100.00,100.00,NULL,0,NULL,0,1),(20,20,'Essay',100.00,100.00,NULL,0,NULL,0,1),(21,21,'Essay',100.00,100.00,NULL,0,NULL,0,1),(22,22,'Essay',100.00,100.00,NULL,0,NULL,0,1),(23,23,'Essay',100.00,100.00,NULL,0,NULL,0,1),(24,24,'Essay',100.00,100.00,NULL,0,NULL,0,1),(25,25,'Essay',100.00,100.00,NULL,0,NULL,0,1),(26,26,'Essay',100.00,100.00,NULL,0,NULL,0,1),(27,27,'Essay',100.00,100.00,NULL,0,NULL,0,1),(28,14,'Essay',100.00,100.00,NULL,0,NULL,0,1);
/*!40000 ALTER TABLE `scholarship_criteria` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_custom_fields` (
  `FieldID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `FieldName` varchar(255) NOT NULL,
  `FieldType` varchar(50) NOT NULL DEFAULT 'Text',
  `HelpText` varchar(255) DEFAULT NULL,
  `Placeholder` varchar(150) DEFAULT NULL,
  `Options` text DEFAULT NULL COMMENT 'Choices for Select/Radio/Checkbox, one per line',
  `IsRequired` tinyint(1) NOT NULL DEFAULT 1,
  `SortOrder` int(11) NOT NULL DEFAULT 0,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = removed from the form but kept for past answers',
  `PDFFieldName` varchar(100) DEFAULT NULL,
  `PdfPage` smallint(6) DEFAULT NULL,
  `PdfX` decimal(8,6) DEFAULT NULL,
  `PdfY` decimal(8,6) DEFAULT NULL,
  `PdfW` decimal(8,6) DEFAULT NULL,
  `PdfH` decimal(8,6) DEFAULT NULL,
  `PdfFontSize` decimal(4,1) DEFAULT NULL COMMENT 'NULL = shrink to fit the box',
  PRIMARY KEY (`FieldID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `fk_scf_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_custom_fields` WRITE;
/*!40000 ALTER TABLE `scholarship_custom_fields` DISABLE KEYS */;
INSERT INTO `scholarship_custom_fields` VALUES (11,12,'Home Address','Auto_Address',NULL,NULL,NULL,1,0,1,'homeaddress',NULL,NULL,NULL,NULL,NULL,NULL),(12,12,'Religion','Auto_Religion',NULL,NULL,NULL,1,0,1,'religion',NULL,NULL,NULL,NULL,NULL,NULL),(13,12,'Family Income','Auto_Income',NULL,NULL,NULL,1,0,1,'ParentsAnnualGrossIncome',NULL,NULL,NULL,NULL,NULL,NULL),(14,12,'Middle Name','Auto_MiddleName',NULL,NULL,NULL,1,0,1,'middleName',NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `scholarship_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_pdf_edits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_pdf_edits` (
  `EditID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `Kind` enum('erase','text') NOT NULL,
  `PdfPage` smallint(6) NOT NULL,
  `PdfX` decimal(8,6) NOT NULL,
  `PdfY` decimal(8,6) NOT NULL,
  `PdfW` decimal(8,6) NOT NULL,
  `PdfH` decimal(8,6) NOT NULL,
  `Content` text DEFAULT NULL,
  `FontFamily` varchar(20) NOT NULL DEFAULT 'Helvetica',
  `FontSize` decimal(4,1) DEFAULT NULL,
  `IsBold` tinyint(1) NOT NULL DEFAULT 0,
  `Cover` tinyint(1) NOT NULL DEFAULT 0,
  `SortOrder` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`EditID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `fk_spe_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_pdf_edits` WRITE;
/*!40000 ALTER TABLE `scholarship_pdf_edits` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_pdf_edits` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_pdf_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_pdf_overrides` (
  `ScholarshipID` int(11) NOT NULL,
  `FieldName` varchar(150) NOT NULL,
  `Action` enum('hide','move') NOT NULL,
  `PdfPage` smallint(6) DEFAULT NULL,
  `PdfX` decimal(8,6) DEFAULT NULL,
  `PdfY` decimal(8,6) DEFAULT NULL,
  `PdfW` decimal(8,6) DEFAULT NULL,
  `PdfH` decimal(8,6) DEFAULT NULL,
  `PdfFontSize` decimal(4,1) DEFAULT NULL,
  PRIMARY KEY (`ScholarshipID`,`FieldName`),
  CONSTRAINT `fk_spo_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_pdf_overrides` WRITE;
/*!40000 ALTER TABLE `scholarship_pdf_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_pdf_overrides` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_programs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_programs` (
  `ScholarshipID` int(11) NOT NULL,
  `ProgramID` int(11) NOT NULL,
  PRIMARY KEY (`ScholarshipID`,`ProgramID`),
  KEY `ProgramID` (`ProgramID`),
  CONSTRAINT `scholarship_programs_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `scholarship_programs_ibfk_2` FOREIGN KEY (`ProgramID`) REFERENCES `program` (`ProgramID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_programs` WRITE;
/*!40000 ALTER TABLE `scholarship_programs` DISABLE KEYS */;
INSERT INTO `scholarship_programs` VALUES (14,4),(14,10),(14,19),(21,4),(21,16),(21,19);
/*!40000 ALTER TABLE `scholarship_programs` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_report_formats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_report_formats` (
  `ScholarshipID` int(11) NOT NULL,
  `Title` varchar(200) DEFAULT NULL,
  `Subtitle` varchar(255) DEFAULT NULL,
  `Columns` text NOT NULL COMMENT 'JSON: [{"key": "...", "label": "..."}, ...] in report order',
  `PreparedBy` varchar(150) DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ScholarshipID`),
  CONSTRAINT `fk_srf_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_report_formats` WRITE;
/*!40000 ALTER TABLE `scholarship_report_formats` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_report_formats` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_scoring_rubrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_scoring_rubrics` (
  `RubricID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `PassingScore` decimal(5,2) NOT NULL DEFAULT 75.00,
  `TotalMaximumScore` decimal(8,2) NOT NULL DEFAULT 100.00,
  `TieBreakingRules` text NOT NULL,
  `GeneralCommentsRequired` tinyint(1) NOT NULL DEFAULT 1,
  `OverrideProcedure` text NOT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`RubricID`),
  UNIQUE KEY `uq_scoring_rubric_scholarship` (`ScholarshipID`),
  KEY `fk_scoring_rubric_updater` (`UpdatedBy`),
  CONSTRAINT `fk_scoring_rubric_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `fk_scoring_rubric_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_scoring_rubrics` WRITE;
/*!40000 ALTER TABLE `scholarship_scoring_rubrics` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_scoring_rubrics` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `scholarship_workflow_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_workflow_stages` (
  `WorkflowStageID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `StageOrder` int(10) unsigned NOT NULL,
  `StageKey` varchar(50) NOT NULL DEFAULT 'custom',
  `StageName` varchar(120) NOT NULL,
  `AssignedRole` enum('Internal_Admin','External_Admin','Super_Admin') NOT NULL,
  `CompletionAction` enum('none','under_review','shortlisted','approve','award_confirm') NOT NULL DEFAULT 'none',
  `Instructions` text DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`WorkflowStageID`),
  UNIQUE KEY `uq_workflow_stage_order` (`ScholarshipID`,`StageOrder`),
  KEY `idx_workflow_stage_role` (`AssignedRole`,`IsActive`),
  CONSTRAINT `fk_workflow_stage_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_workflow_stages` WRITE;
/*!40000 ALTER TABLE `scholarship_workflow_stages` DISABLE KEYS */;
/*!40000 ALTER TABLE `scholarship_workflow_stages` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `score`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `score` (
  `ScoreID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `CriteriaID` int(11) NOT NULL,
  `EvaluatorID` int(11) DEFAULT NULL,
  `ScoreValue` decimal(5,2) NOT NULL,
  `Comments` text DEFAULT NULL,
  PRIMARY KEY (`ScoreID`),
  KEY `ApplicationID` (`ApplicationID`),
  KEY `CriteriaID` (`CriteriaID`),
  KEY `EvaluatorID` (`EvaluatorID`),
  CONSTRAINT `score_ibfk_1` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `score_ibfk_2` FOREIGN KEY (`CriteriaID`) REFERENCES `criteria` (`CriteriaID`) ON DELETE CASCADE,
  CONSTRAINT `score_ibfk_3` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `score` WRITE;
/*!40000 ALTER TABLE `score` DISABLE KEYS */;
/*!40000 ALTER TABLE `score` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `score_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `score_overrides` (
  `OverrideID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `ScorecardID` bigint(20) unsigned DEFAULT NULL,
  `PreviousScore` decimal(5,2) NOT NULL,
  `OverrideScore` decimal(5,2) NOT NULL,
  `Reason` text NOT NULL,
  `AuthorizedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`OverrideID`),
  KEY `idx_score_override_application` (`ApplicationID`,`CreatedAt`),
  KEY `fk_score_override_scorecard` (`ScorecardID`),
  KEY `fk_score_override_authorizer` (`AuthorizedBy`),
  CONSTRAINT `fk_score_override_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_override_authorizer` FOREIGN KEY (`AuthorizedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_score_override_scorecard` FOREIGN KEY (`ScorecardID`) REFERENCES `application_scorecards` (`ScorecardID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `score_overrides` WRITE;
/*!40000 ALTER TABLE `score_overrides` DISABLE KEYS */;
/*!40000 ALTER TABLE `score_overrides` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `security_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_rate_limits` (
  `RateLimitID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `Scope` varchar(30) NOT NULL,
  `IdentifierHash` char(64) NOT NULL,
  `IPAddress` varchar(45) NOT NULL,
  `AttemptCount` int(10) unsigned NOT NULL DEFAULT 0,
  `WindowStartedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `LastAttemptAt` datetime NOT NULL DEFAULT current_timestamp(),
  `LockedUntil` datetime DEFAULT NULL,
  PRIMARY KEY (`RateLimitID`),
  UNIQUE KEY `uq_security_rate_limit` (`Scope`,`IdentifierHash`,`IPAddress`),
  KEY `idx_security_rate_lock` (`Scope`,`LockedUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `security_rate_limits` WRITE;
/*!40000 ALTER TABLE `security_rate_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `security_rate_limits` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `submitted_document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submitted_document` (
  `SubmittedDocID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `RequirementID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` bigint(20) unsigned DEFAULT NULL,
  `FileChecksum` char(64) DEFAULT NULL,
  `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked',
  `ScannerEngine` varchar(150) DEFAULT NULL,
  `ScannedAt` datetime DEFAULT NULL,
  `UploadDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `VerificationStatus` varchar(50) DEFAULT 'Pending',
  PRIMARY KEY (`SubmittedDocID`),
  KEY `ApplicationID` (`ApplicationID`),
  KEY `RequirementID` (`RequirementID`),
  KEY `idx_submitted_checksum` (`FileChecksum`),
  CONSTRAINT `submitted_document_ibfk_1` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `submitted_document_ibfk_2` FOREIGN KEY (`RequirementID`) REFERENCES `document_requirement` (`RequirementID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `submitted_document` WRITE;
/*!40000 ALTER TABLE `submitted_document` DISABLE KEYS */;
/*!40000 ALTER TABLE `submitted_document` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `submitted_document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submitted_document_versions` (
  `DocumentVersionID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `SubmittedDocID` int(11) DEFAULT NULL,
  `ApplicationID` int(11) NOT NULL,
  `RequirementID` int(11) NOT NULL,
  `VersionNumber` int(10) unsigned NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` bigint(20) unsigned DEFAULT NULL,
  `FileChecksum` char(64) DEFAULT NULL,
  `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked',
  `ScannerEngine` varchar(150) DEFAULT NULL,
  `ScannedAt` datetime DEFAULT NULL,
  `VerificationStatus` varchar(50) NOT NULL DEFAULT 'Pending',
  `ChangeType` varchar(30) NOT NULL DEFAULT 'Uploaded',
  `UploadedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`DocumentVersionID`),
  UNIQUE KEY `uq_document_version` (`ApplicationID`,`RequirementID`,`VersionNumber`),
  KEY `idx_document_versions_doc` (`SubmittedDocID`),
  KEY `fk_document_versions_requirement` (`RequirementID`),
  KEY `fk_document_versions_user` (`UploadedBy`),
  CONSTRAINT `fk_document_versions_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_versions_requirement` FOREIGN KEY (`RequirementID`) REFERENCES `document_requirement` (`RequirementID`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_versions_user` FOREIGN KEY (`UploadedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `submitted_document_versions` WRITE;
/*!40000 ALTER TABLE `submitted_document_versions` DISABLE KEYS */;
/*!40000 ALTER TABLE `submitted_document_versions` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_notifications` (
  `NotifID` int(11) NOT NULL AUTO_INCREMENT,
  `RecipientID` int(11) NOT NULL,
  `Title` varchar(255) NOT NULL,
  `Message` text NOT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `DateCreated` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`NotifID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_notifications` WRITE;
/*!40000 ALTER TABLE `system_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_notifications` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES ('absolute_session_timeout','28800'),('login_lock_minutes','15'),('login_max_attempts','5'),('provider_default_access_days','365'),('recovery_max_requests','3'),('require_2fa','1'),('session_timeout','300'),('strict_password','1');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `user_profile_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_profile_answers` (
  `AnswerID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `FieldID` int(11) NOT NULL,
  `AnswerText` text DEFAULT NULL,
  PRIMARY KEY (`AnswerID`),
  KEY `UserID` (`UserID`),
  KEY `FieldID` (`FieldID`),
  CONSTRAINT `fk_profile_field` FOREIGN KEY (`FieldID`) REFERENCES `user_profile_fields` (`FieldID`) ON DELETE CASCADE,
  CONSTRAINT `fk_profile_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `user_profile_answers` WRITE;
/*!40000 ALTER TABLE `user_profile_answers` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_profile_answers` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `user_profile_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_profile_fields` (
  `FieldID` int(11) NOT NULL AUTO_INCREMENT,
  `FieldName` varchar(255) NOT NULL,
  `FieldType` varchar(50) NOT NULL DEFAULT 'Text',
  `DateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`FieldID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `user_profile_fields` WRITE;
/*!40000 ALTER TABLE `user_profile_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_profile_fields` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `user_vault`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_vault` (
  `VaultID` int(11) NOT NULL AUTO_INCREMENT,
  `UserID` int(11) NOT NULL,
  `DocumentType` varchar(100) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `MimeType` varchar(100) DEFAULT NULL,
  `FileSize` bigint(20) unsigned DEFAULT NULL,
  `FileChecksum` char(64) DEFAULT NULL,
  `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked',
  `ScannerEngine` varchar(150) DEFAULT NULL,
  `ScannedAt` datetime DEFAULT NULL,
  `UploadDate` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`VaultID`),
  KEY `UserID` (`UserID`),
  KEY `idx_vault_checksum` (`FileChecksum`),
  CONSTRAINT `user_vault_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `user_vault` WRITE;
/*!40000 ALTER TABLE `user_vault` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_vault` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `UserID` int(11) NOT NULL AUTO_INCREMENT,
  `Username` varchar(50) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `FirstName` varchar(100) NOT NULL,
  `LastName` varchar(100) NOT NULL,
  `MiddleName` varchar(100) DEFAULT NULL,
  `ExtName` varchar(20) DEFAULT NULL,
  `Role` enum('Student','Internal_Admin','External_Admin','Super_Admin') DEFAULT 'Student',
  `AccountStatus` varchar(20) DEFAULT 'Active',
  `StudentID_Num` varchar(50) DEFAULT NULL,
  `LRN` varchar(50) DEFAULT NULL,
  `PhilSysID` varchar(50) DEFAULT NULL,
  `FourPsID` varchar(50) DEFAULT NULL,
  `YearLevel` varchar(50) DEFAULT NULL,
  `GPA` decimal(4,2) DEFAULT NULL,
  `Major` varchar(100) DEFAULT NULL,
  `ProgramID` int(11) DEFAULT NULL,
  `Department` varchar(100) DEFAULT NULL,
  `Organization` varchar(100) DEFAULT NULL,
  `ContactNumber` varchar(20) DEFAULT NULL,
  `DateOfBirth` date DEFAULT NULL,
  `ProfilePicture` varchar(255) DEFAULT NULL,
  `ResetToken` varchar(255) DEFAULT NULL,
  `ResetTokenExpire` datetime DEFAULT NULL,
  `Gender` varchar(20) NOT NULL DEFAULT 'Not Specified',
  `FatherLastName` varchar(100) DEFAULT NULL,
  `FatherFirstName` varchar(100) DEFAULT NULL,
  `FatherMiddleName` varchar(100) DEFAULT NULL,
  `MotherLastName` varchar(100) DEFAULT NULL,
  `MotherFirstName` varchar(100) DEFAULT NULL,
  `MotherMiddleName` varchar(100) DEFAULT NULL,
  `StreetBarangay` varchar(255) DEFAULT NULL,
  `CityMunicipality` varchar(100) DEFAULT NULL,
  `Province` varchar(100) DEFAULT NULL,
  `Region` varchar(100) DEFAULT NULL,
  `ZipCode` varchar(20) DEFAULT NULL,
  `DisabilityType` varchar(100) DEFAULT NULL,
  `IsSoloParentChild` enum('Yes','No') DEFAULT 'No',
  `IsFirstGenStudent` enum('Yes','No') DEFAULT 'No',
  `IndigenousGroup` varchar(100) DEFAULT NULL,
  `GoogleID` varchar(255) DEFAULT NULL,
  `UnitsEnrolled` int(11) DEFAULT 0,
  `Citizenship` varchar(50) DEFAULT 'Filipino',
  `TourCompletedAt` datetime DEFAULT NULL,
  `FailedLoginCount` int(10) unsigned NOT NULL DEFAULT 0,
  `LockedUntil` datetime DEFAULT NULL,
  `AuthVersion` int(10) unsigned NOT NULL DEFAULT 1,
  `ProviderAccessStatus` varchar(20) NOT NULL DEFAULT 'Active',
  `ProviderAccessUntil` date DEFAULT NULL,
  `ProviderAccessReason` varchar(500) DEFAULT NULL,
  `ProviderAccessReviewedAt` datetime DEFAULT NULL,
  `ProviderAccessReviewedBy` int(11) DEFAULT NULL,
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Username` (`Username`),
  UNIQUE KEY `Email` (`Email`),
  KEY `idx_user_login_lock` (`LockedUntil`),
  KEY `idx_provider_access` (`Role`,`ProviderAccessStatus`,`ProviderAccessUntil`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'superadmin','admin@tau.edu.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','System','Admin',NULL,NULL,'Super_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'IT Department',NULL,NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(2,'internal01','scholarships@tau.edu.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Office of','Student Affairs',NULL,NULL,'Internal_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'OSA',NULL,NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(3,'ched_eval','region3@ched.gov.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Regional','Evaluator',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'CHED Region III',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(17,'tarlac_lgu','lgu@tarlaccity.gov.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Tarlac City','LGU',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Tarlac City Government',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(18,'dost_ro3','region3@dost.gov.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','DOST','Region III',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Department of Science and Technology',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(19,'da_acef','acef@da.gov.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Dept. of','Agriculture',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'DA - ACEF',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(20,'philchema','hr@philchema.com','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Philchema','Inc.',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Philchema Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(21,'bounty_cares','foundation@bounty.com','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Bounty Cares','Foundation',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Bounty Cares Foundation Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(22,'ncaf_admin','info@ncaf.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Ninoy & Cory','Aquino Foundation',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Ninoy and Cory Aquino Foundation Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(23,'gep_reg3','reg3@gep.org.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Geodetic','Engineers',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Geodetic Engineers of the Phil.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(24,'tau_alumni','alumni@tau.edu.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','TAU','Alumni Assoc',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'TAU Alumni and Friends',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL),(25,'tarlac_sponsors','sponsors@tarlac.ph','$2y$10$M0ZAOEcXA.w7Ngb7TTo0x..XKEAfvH3STwZYea0YYnvqjS8R83Hom','Private','Benefactors',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Tarlac Private Foundations Coalition',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL,0,NULL,1,'Active',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

