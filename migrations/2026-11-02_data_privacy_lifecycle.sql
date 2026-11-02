CREATE TABLE IF NOT EXISTS `privacy_retention_policies` (
  `PolicyID` int unsigned NOT NULL AUTO_INCREMENT,
  `PolicyKey` varchar(50) NOT NULL,
  `RecordLabel` varchar(120) NOT NULL,
  `RetentionDays` int unsigned NOT NULL,
  `TriggerEvent` varchar(255) NOT NULL,
  `DisposalMethod` enum('Anonymize','Secure Delete','Review Only') NOT NULL,
  `LegalBasis` varchar(500) NOT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`PolicyID`), UNIQUE KEY `uq_retention_policy_key` (`PolicyKey`),
  CONSTRAINT `fk_retention_policy_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `privacy_retention_policies` (`PolicyKey`,`RecordLabel`,`RetentionDays`,`TriggerEvent`,`DisposalMethod`,`LegalBasis`) VALUES
('applications','Withdrawn or closed applications',1825,'Final closure or withdrawal date','Anonymize','Scholarship administration, institutional records rules, and applicable sponsor requirements'),
('uploaded_documents','Uploaded application documents',1825,'Application final closure date','Secure Delete','Data minimization after the scholarship transaction and required verification period'),
('rejected_applicants','Rejected or disqualified applicants',730,'Final rejection/disqualification date after appeal period','Anonymize','Defend decisions during the appeal/complaint period, then minimize personal data'),
('scholar_records','Approved scholar and benefit records',3650,'End of scholarship or final benefit release','Review Only','Long-term institutional, sponsor, accounting, and government reporting obligations'),
('audit_logs','Security and activity audit logs',2555,'Activity date','Secure Delete','Security accountability, investigation, and institutional audit requirements'),
('backups','Database backup files',30,'Backup creation date','Secure Delete','Recovery window balanced with storage limitation and exposure risk');

CREATE TABLE IF NOT EXISTS `privacy_consents` (
  `ConsentID` bigint unsigned NOT NULL AUTO_INCREMENT, `UserID` int(11) NOT NULL,
  `NoticeVersion` varchar(40) NOT NULL, `ConsentStatus` enum('Granted','Withdrawn') NOT NULL DEFAULT 'Granted',
  `ConsentText` varchar(500) NOT NULL, `IPAddress` varchar(45) DEFAULT NULL,
  `GrantedAt` datetime NOT NULL DEFAULT current_timestamp(), `WithdrawnAt` datetime DEFAULT NULL,
  PRIMARY KEY (`ConsentID`), KEY `idx_privacy_consent_user` (`UserID`,`GrantedAt`),
  CONSTRAINT `fk_privacy_consent_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `privacy_consents` (`UserID`,`NoticeVersion`,`ConsentStatus`,`ConsentText`,`IPAddress`,`GrantedAt`)
SELECT u.UserID,'legacy-registration','Granted','Legacy consent recorded from the mandatory ScholarLink registration privacy checkbox.',NULL,NOW()
FROM users u
WHERE u.Role='Student' AND NOT EXISTS (SELECT 1 FROM privacy_consents pc WHERE pc.UserID=u.UserID);

CREATE TABLE IF NOT EXISTS `privacy_requests` (
  `RequestID` bigint unsigned NOT NULL AUTO_INCREMENT, `UserID` int(11) NOT NULL,
  `RequestType` enum('Access','Correction','Anonymization','Erasure or Blocking','Consent Withdrawal') NOT NULL,
  `RequestDetails` text NOT NULL, `Status` enum('Submitted','Under Review','Approved','Partially Approved','Denied','Completed') NOT NULL DEFAULT 'Submitted',
  `ResolutionNotes` text DEFAULT NULL, `ReviewedBy` int(11) DEFAULT NULL, `SubmittedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DueAt` datetime NOT NULL, `ResolvedAt` datetime DEFAULT NULL, `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`RequestID`), KEY `idx_privacy_request_queue` (`Status`,`DueAt`),
  CONSTRAINT `fk_privacy_request_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_privacy_request_reviewer` FOREIGN KEY (`ReviewedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `privacy_retention_cases` (
  `CaseID` bigint unsigned NOT NULL AUTO_INCREMENT, `PolicyKey` varchar(50) NOT NULL,
  `RecordType` varchar(50) NOT NULL, `RecordID` varchar(255) NOT NULL, `SubjectUserID` int(11) DEFAULT NULL,
  `RecordDescription` varchar(500) NOT NULL, `EligibleAt` datetime NOT NULL,
  `LegalHold` tinyint(1) NOT NULL DEFAULT 0, `LegalHoldReason` varchar(500) DEFAULT NULL,
  `Status` enum('Eligible','Approved','Deferred','Completed','Cancelled') NOT NULL DEFAULT 'Eligible',
  `ApprovalReason` text DEFAULT NULL, `ApprovedBy` int(11) DEFAULT NULL, `ApprovedAt` datetime DEFAULT NULL,
  `ExecutedBy` int(11) DEFAULT NULL, `ExecutedAt` datetime DEFAULT NULL, `DispositionEvidence` varchar(500) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(), `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`CaseID`), UNIQUE KEY `uq_retention_record` (`PolicyKey`,`RecordType`,`RecordID`),
  KEY `idx_retention_queue` (`Status`,`LegalHold`,`EligibleAt`),
  CONSTRAINT `fk_retention_case_subject` FOREIGN KEY (`SubjectUserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_retention_case_approver` FOREIGN KEY (`ApprovedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_retention_case_executor` FOREIGN KEY (`ExecutedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
