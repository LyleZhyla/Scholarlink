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
  `Status` varchar(50) DEFAULT 'Pending',
  `TotalScore` int(11) DEFAULT 0,
  `GPA` decimal(4,2) DEFAULT NULL,
  `YearLevel` varchar(20) DEFAULT NULL,
  `TermID` int(11) DEFAULT NULL,
  PRIMARY KEY (`ApplicationID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `application_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `application_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `application` WRITE;
/*!40000 ALTER TABLE `application` DISABLE KEYS */;
/*!40000 ALTER TABLE `application` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `application_versions`;
CREATE TABLE `application_versions` (
  `VersionID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `VersionNumber` int unsigned NOT NULL,
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
  CONSTRAINT `fk_application_versions_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_application_versions_user` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
DROP TABLE IF EXISTS `document_requirement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document_requirement` (
  `RequirementID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `DocumentName` varchar(100) NOT NULL,
  PRIMARY KEY (`RequirementID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `document_requirement_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `document_requirement` WRITE;
/*!40000 ALTER TABLE `document_requirement` DISABLE KEYS */;
INSERT INTO `document_requirement` VALUES (1,1,'Certificate of Registration (COR)'),(2,1,'Report of Grades'),(3,2,'Certificate of Registration (COR)'),(4,2,'Report of Grades'),(5,3,'Certificate of Registration (COR)'),(6,3,'Report of Grades'),(7,3,'Endorsement from Sports Development Director'),(8,4,'Certificate of Registration (COR)'),(9,4,'Report of Grades'),(10,4,'Endorsement from Socio-Cultural Director'),(11,5,'Certificate of Registration (COR)'),(12,5,'Report of Grades'),(13,5,'Endorsement from Publication Adviser'),(14,6,'Certificate of Registration (COR)'),(15,6,'Report of Grades'),(16,6,'ROTC Commandant Endorsement'),(17,7,'Certificate of Registration (COR)'),(18,7,'Report of Grades'),(19,7,'Endorsement from Adviser'),(20,8,'Certificate of Registration (COR)'),(21,8,'Report of Grades'),(22,8,'Barangay Certificate of Indigency'),(23,9,'Certificate of Registration (COR)'),(24,9,'Report of Grades'),(25,9,'Certificate of Indigency'),(26,9,'Certificate of Good Moral Character'),(27,10,'Certificate of Registration (COR)'),(28,10,'Report of Grades'),(29,10,'BIR Income Tax Return or Tax Exemption'),(30,11,'Certificate of Registration (COR)'),(31,11,'Report of Grades'),(32,11,'Certificate of Good Moral Character'),(33,12,'Certificate of Registration (COR)'),(34,12,'Report of Grades'),(35,12,'Barangay Certificate of Indigency'),(36,13,'Certificate of Registration (COR)'),(37,13,'Report of Grades'),(38,13,'Barangay Certificate of Indigency'),(39,14,'Certificate of Registration (COR)'),(40,14,'Report of Grades'),(41,14,'Barangay Certificate of Indigency'),(42,15,'Certificate of Registration (COR)'),(43,15,'Report of Grades'),(44,15,'Barangay Certificate of Indigency'),(45,16,'Certificate of Registration (COR)'),(46,16,'Report of Grades'),(47,16,'Barangay Certificate of Residency'),(48,16,'DSWD Certificate of Indigency'),(49,16,'Certificate of Good Moral Character'),(50,16,'Recommendation Letter 1'),(51,17,'Certificate of Registration (COR)'),(52,17,'Report of Grades'),(53,17,'DSWD Certificate of Indigency'),(54,17,'Certificate of Good Moral Character'),(55,17,'Recommendation Letter 1'),(56,18,'Certificate of Registration (COR)'),(57,18,'Report of Grades'),(58,18,'Barangay Certificate of Residency'),(59,18,'DSWD Certificate of Indigency'),(60,19,'Certificate of Registration (COR)'),(61,19,'Report of Grades'),(62,19,'Barangay Certificate of Indigency'),(63,20,'Certificate of Registration (COR)'),(64,20,'Report of Grades'),(65,20,'Barangay Certificate of Residency'),(66,20,'DSWD Certificate of Indigency'),(67,20,'Certificate of Good Moral Character'),(68,20,'Recommendation Letter 1'),(70,21,'Report of Grades'),(71,21,'Certificate of Good Moral Character'),(72,21,'Recommendation Letter 1'),(73,21,'Medical Certificate (Fit, X-ray, Urinalysis, Fecalysis, Eye Exam)'),(74,22,'Certificate of Registration (COR)'),(75,22,'Report of Grades'),(76,22,'Barangay Certificate of Residency'),(77,22,'DSWD Certificate of Indigency'),(78,22,'Certificate of Good Moral Character'),(79,22,'Recommendation Letter 1'),(80,23,'Certificate of Registration (COR)'),(81,23,'Report of Grades'),(82,23,'IP Certification/ID'),(83,23,'Barangay Certificate of Residency'),(84,23,'DSWD Certificate of Indigency'),(85,23,'Certificate of Good Moral Character'),(86,23,'Recommendation Letter 1'),(87,24,'Certificate of Registration (COR)'),(88,24,'Report of Grades'),(89,24,'Barangay Certificate of Residency'),(90,24,'DSWD Certificate of Indigency'),(91,24,'Certificate of Good Moral Character'),(92,24,'Recommendation Letter 1'),(93,25,'Certificate of Registration (COR)'),(94,25,'Report of Grades'),(95,25,'Barangay Certificate of Indigency (Mayantoc)'),(96,25,'MSWDO Certificate of Indigency'),(97,25,'BIR Certificate of Tax Exemption'),(98,26,'Certificate of Registration (COR)'),(99,26,'Report of Grades'),(100,26,'Income Tax Return (ITR)'),(101,27,'Certificate of Registration (COR)'),(102,27,'Report of Grades'),(103,27,'Barangay Certificate of Residency'),(104,27,'DSWD Certificate of Indigency'),(105,27,'Certificate of Good Moral Character'),(106,27,'Recommendation Letter 1'),(107,16,'Recommendation Letter 2'),(108,17,'Recommendation Letter 2'),(109,20,'Recommendation Letter 2'),(110,21,'Recommendation Letter 2'),(111,22,'Recommendation Letter 2'),(112,23,'Recommendation Letter 2'),(113,24,'Recommendation Letter 2'),(114,27,'Recommendation Letter 2'),(115,21,'Certificate of Registration');
/*!40000 ALTER TABLE `document_requirement` ENABLE KEYS */;
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
INSERT INTO `scholarship` VALUES (1,NULL,NULL,'Academic Scholars - Full','GWA of 1.20-1.00 of an academic load for regular students with no dropped, conditional, or failed grades.','2026-08-30',5000.00,NULL,'Active',1.20,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(2,NULL,NULL,'Academic Scholars - Partial','GWA of 1.75-1.21. Must be regularly enrolled with a minimum of 12 units and no dropped, conditional, or failed grades.','2026-08-30',1500.00,NULL,'Active',1.75,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(3,NULL,NULL,'Athletes Regular Training Allowance','Subject to monitoring, evaluation, and endorsement of the Director for Sports Development.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(4,NULL,NULL,'Cultural Performers Allowance','Subject to monitoring, evaluation, and endorsement of the Socio-Cultural Development Director.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(5,NULL,NULL,'Golden Harvest Incentives','Incentives for the Official Student Publication editorial board.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Incentive','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(6,NULL,NULL,'ROTC Cadet Allowance','Incentives for First Class (P5,000) and Second Class (P2,500) Cadets.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Allowance','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(7,NULL,NULL,'SSC/CSC Leadership Incentives','For students holding leadership positions indicated in the TAU Code.','2026-08-30',5000.00,NULL,'Active',3.00,2,'Any','Government','Incentive','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(8,NULL,NULL,'Tulong Dunong Program','One-time SUC program for low-income earning families.','2026-09-15',7500.00,NULL,'Active',3.00,2,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(9,1,NULL,'City of Tarlac Integrated Scholarship (Agriculture)','For indigent families enrolled in Agriculture/Agri-related courses.','2026-09-15',3500.00,NULL,'Active',2.00,17,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(10,NULL,NULL,'ACEF-GIAHEP Program','For agriculture, forestry, fisheries, and veterinary medicine students.','2026-09-30',12500.00,NULL,'Active',3.00,19,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(11,NULL,NULL,'DOST Scholarships (RA 7687 / MERIT)','For talented students in priority S&T courses.','2026-09-30',14000.00,NULL,'Active',2.00,18,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(12,NULL,NULL,'Tertiary Educational Subsidy (TES)','For low-income families certified by the Barangay.','2026-10-15',10000.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(13,NULL,NULL,'TES Tulong Dunong Program','For low-income families certified by the Barangay.','2026-10-15',7500.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(14,NULL,NULL,'Tulong Agri Program','For students in agriculture, fisheries, forestry, food tech, and veterinary medicine.','2026-10-15',13500.00,NULL,'Active',3.00,3,'Any','Government','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(15,NULL,NULL,'TAU Alumni and Friends Grant','For bonafide TAU students from low-income families.','2026-09-15',4000.00,NULL,'Active',3.00,24,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(16,NULL,'1st Year','Lorna Fernando Dinos Scholarship Program','For 1st-year single students (max 25 yrs old) in Tarlac.','2026-08-30',9000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(17,NULL,'3rd Year','Philchema Inc. Scholarship Grant','For 3rd-year BS Animal Science, Agriculture, or VetMed students.','2026-08-30',10000.00,NULL,'Active',2.00,20,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(18,NULL,NULL,'Prado Builders Scholarship','For residents of Tarlac Province from low-income families.','2026-09-15',20000.00,NULL,'Active',3.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(19,NULL,'4th Year','The Camileños Inc. Scholarship Program','For graduating students from low-income families.','2026-08-30',3000.00,NULL,'Active',3.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(20,NULL,'1st Year','Tolentino - Dahlgren Scholarship Program','For 1st-year students from Tarlac.','2026-08-30',9000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(21,NULL,'2nd Year, 4th Year','Bounty Cares Foundation Inc.','For 2nd-year Agri, Biosystems, Food Tech, Biology, Chem, and 4th-year VetMed.','2026-08-25',37740.00,20,'Active',2.50,21,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(22,NULL,'1st Year','Cristobal Partido Scholarship Inc.','For 1st-year students from Tarlac.','2026-08-30',9573.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(23,NULL,'1st Year','Scholarship Program for IP Student','For 1st-year Indigenous Peoples (IP) students from Tarlac.','2026-09-30',12000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(24,NULL,'1st Year','CJ and LJ Scholarships','For 1st-year students from Tarlac.','2026-09-15',5000.00,NULL,'Active',2.00,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(25,NULL,'3rd Year','Don Francisco Santos Scholarship','For regular 3rd-year students. Must have a GPA of 2.5 or higher and no incomplete grades.','2026-09-30',6000.00,NULL,'Active',2.50,25,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(26,NULL,'1st Year','Ninoy and Cory Aquino Foundation Inc.','For 1st-year students. Annual household gross income must not exceed P375,000.00.','2026-09-15',15000.00,NULL,'Active',2.00,22,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL),(27,17,'1st Year','Geodetic Engineers of the Philippines Inc.','For 1st-year BS Geodetic Engineering students from Tarlac.','2026-09-15',2000.00,NULL,'Active',2.00,23,'Any','Private','Scholarship','Academic,Family,Financial,Essay','Per Semester','No',NULL,NULL,NULL);
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
DROP TABLE IF EXISTS `scholarship_criteria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scholarship_criteria` (
  `CriteriaID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `CriteriaName` varchar(255) NOT NULL,
  PRIMARY KEY (`CriteriaID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  CONSTRAINT `scholarship_criteria_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `scholarship_criteria` WRITE;
/*!40000 ALTER TABLE `scholarship_criteria` DISABLE KEYS */;
INSERT INTO `scholarship_criteria` VALUES (1,1,'Essay'),(2,2,'Essay'),(3,3,'Essay'),(4,4,'Essay'),(5,5,'Essay'),(6,6,'Essay'),(7,7,'Essay'),(8,8,'Essay'),(9,9,'Essay'),(10,10,'Essay'),(11,11,'Essay'),(12,12,'Essay'),(13,13,'Essay'),(15,15,'Essay'),(16,16,'Essay'),(17,17,'Essay'),(18,18,'Essay'),(19,19,'Essay'),(20,20,'Essay'),(21,21,'Essay'),(22,22,'Essay'),(23,23,'Essay'),(24,24,'Essay'),(25,25,'Essay'),(26,26,'Essay'),(27,27,'Essay'),(28,14,'Essay');
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
DROP TABLE IF EXISTS `submitted_document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `submitted_document` (
  `SubmittedDocID` int(11) NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `RequirementID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `UploadDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `VerificationStatus` varchar(50) DEFAULT 'Pending',
  PRIMARY KEY (`SubmittedDocID`),
  KEY `ApplicationID` (`ApplicationID`),
  KEY `RequirementID` (`RequirementID`),
  CONSTRAINT `submitted_document_ibfk_1` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `submitted_document_ibfk_2` FOREIGN KEY (`RequirementID`) REFERENCES `document_requirement` (`RequirementID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `submitted_document` WRITE;
/*!40000 ALTER TABLE `submitted_document` DISABLE KEYS */;
/*!40000 ALTER TABLE `submitted_document` ENABLE KEYS */;
UNLOCK TABLES;
DROP TABLE IF EXISTS `submitted_document_versions`;
CREATE TABLE `submitted_document_versions` (
  `DocumentVersionID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `SubmittedDocID` int(11) DEFAULT NULL,
  `ApplicationID` int(11) NOT NULL,
  `RequirementID` int(11) NOT NULL,
  `VersionNumber` int unsigned NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) DEFAULT NULL,
  `VerificationStatus` varchar(50) NOT NULL DEFAULT 'Pending',
  `ChangeType` varchar(30) NOT NULL DEFAULT 'Uploaded',
  `UploadedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`DocumentVersionID`),
  UNIQUE KEY `uq_document_version` (`ApplicationID`,`RequirementID`,`VersionNumber`),
  KEY `idx_document_versions_doc` (`SubmittedDocID`),
  CONSTRAINT `fk_document_versions_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_versions_requirement` FOREIGN KEY (`RequirementID`) REFERENCES `document_requirement` (`RequirementID`) ON DELETE CASCADE,
  CONSTRAINT `fk_document_versions_user` FOREIGN KEY (`UploadedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
INSERT INTO `system_settings` VALUES ('require_2fa','0'),('session_timeout','300'),('strict_password','1');
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
  `UploadDate` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`VaultID`),
  KEY `UserID` (`UserID`),
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
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Username` (`Username`),
  UNIQUE KEY `Email` (`Email`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'superadmin','admin@tau.edu.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','System','Admin',NULL,NULL,'Super_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'IT Department',NULL,NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(2,'internal01','scholarships@tau.edu.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Office of','Student Affairs',NULL,NULL,'Internal_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'OSA',NULL,NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(3,'ched_eval','region3@ched.gov.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Regional','Evaluator',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'CHED Region III',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(17,'tarlac_lgu','lgu@tarlaccity.gov.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Tarlac City','LGU',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Tarlac City Government',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(18,'dost_ro3','region3@dost.gov.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','DOST','Region III',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Department of Science and Technology',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(19,'da_acef','acef@da.gov.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Dept. of','Agriculture',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'DA - ACEF',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(20,'philchema','hr@philchema.com','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Philchema','Inc.',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Philchema Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(21,'bounty_cares','foundation@bounty.com','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Bounty Cares','Foundation',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Bounty Cares Foundation Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(22,'ncaf_admin','info@ncaf.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Ninoy & Cory','Aquino Foundation',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Ninoy and Cory Aquino Foundation Inc.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(23,'gep_reg3','reg3@gep.org.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Geodetic','Engineers',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Geodetic Engineers of the Phil.',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(24,'tau_alumni','alumni@tau.edu.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','TAU','Alumni Assoc',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'TAU Alumni and Friends',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL),(25,'tarlac_sponsors','sponsors@tarlac.ph','$2y$10$qiupMJterLO4xwaJd4JYw.ql0qgfSsjgirrBj7.pxbt5JiyP5SmJ6','Private','Benefactors',NULL,NULL,'External_Admin','Active',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Tarlac Private Foundations Coalition',NULL,NULL,NULL,NULL,NULL,'Not Specified',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'No','No',NULL,NULL,0,'Filipino',NULL);
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

