ALTER TABLE `scholarship`
  ADD COLUMN IF NOT EXISTS `AppealWindowDays` smallint unsigned NOT NULL DEFAULT 7 AFTER `AllowsDual`;

ALTER TABLE `application`
  ADD COLUMN IF NOT EXISTS `DecisionAt` datetime DEFAULT NULL AFTER `ResubmissionAllowed`,
  ADD COLUMN IF NOT EXISTS `AppealDeadline` datetime DEFAULT NULL AFTER `DecisionAt`;

CREATE TABLE IF NOT EXISTS `application_appeals` (
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
  CONSTRAINT `fk_appeal_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_appeal_applicant` FOREIGN KEY (`ApplicantID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_appeal_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `appeal_documents` (
  `AppealDocumentID` int(11) NOT NULL AUTO_INCREMENT,
  `AppealID` int(11) NOT NULL,
  `FilePath` varchar(255) NOT NULL,
  `OriginalFileName` varchar(255) NOT NULL,
  `UploadedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`AppealDocumentID`),
  KEY `idx_appeal_documents_appeal` (`AppealID`),
  CONSTRAINT `fk_appeal_documents_appeal` FOREIGN KEY (`AppealID`) REFERENCES `application_appeals` (`AppealID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

