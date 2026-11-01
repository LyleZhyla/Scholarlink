ALTER TABLE `application`
  ADD COLUMN IF NOT EXISTS `LastSavedAt` datetime DEFAULT NULL AFTER `DateSubmitted`,
  ADD COLUMN IF NOT EXISTS `WithdrawnAt` datetime DEFAULT NULL AFTER `LastSavedAt`,
  ADD COLUMN IF NOT EXISTS `ResubmissionAllowed` tinyint(1) NOT NULL DEFAULT 0 AFTER `WithdrawnAt`;

CREATE TABLE IF NOT EXISTS `application_versions` (
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

CREATE TABLE IF NOT EXISTS `submitted_document_versions` (
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

INSERT INTO `submitted_document_versions`
  (`SubmittedDocID`, `ApplicationID`, `RequirementID`, `VersionNumber`, `FilePath`, `OriginalFileName`, `VerificationStatus`, `ChangeType`, `UploadedBy`, `CreatedAt`)
SELECT d.`SubmittedDocID`, d.`ApplicationID`, d.`RequirementID`, 1, d.`FilePath`, SUBSTRING_INDEX(d.`FilePath`, '/', -1),
       COALESCE(d.`VerificationStatus`, 'Pending'), 'Baseline', a.`UserID`, d.`UploadDate`
FROM `submitted_document` d
JOIN `application` a ON a.`ApplicationID` = d.`ApplicationID`
WHERE NOT EXISTS (
  SELECT 1 FROM `submitted_document_versions` v
  WHERE v.`ApplicationID` = d.`ApplicationID` AND v.`RequirementID` = d.`RequirementID`
);
