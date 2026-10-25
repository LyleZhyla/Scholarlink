
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
DROP TABLE IF EXISTS `application`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `application` (
  `ApplicationID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `DateSubmitted` timestamp NOT NULL DEFAULT current_timestamp(),
  `Status` varchar(50) DEFAULT 'Pending',
  `TotalScore` int(11) DEFAULT 0,
  `GPA` decimal(4,2) DEFAULT NULL,
  `YearLevel` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`ApplicationID`),
  KEY `ScholarshipID` (`ScholarshipID`),
  KEY `UserID` (`UserID`),
  CONSTRAINT `application_ibfk_1` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `application_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=262 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `program`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `program` (
  `ProgramID` int(11) NOT NULL AUTO_INCREMENT,
  `ProgramName` varchar(150) NOT NULL,
  PRIMARY KEY (`ProgramID`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
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
  PRIMARY KEY (`UserID`),
  UNIQUE KEY `Username` (`Username`),
  UNIQUE KEY `Email` (`Email`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

