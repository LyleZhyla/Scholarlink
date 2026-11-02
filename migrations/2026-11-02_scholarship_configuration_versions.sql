ALTER TABLE `document_requirement`
  ADD COLUMN `IsActive` tinyint(1) NOT NULL DEFAULT 1 AFTER `DocumentName`;

ALTER TABLE `scholarship_criteria`
  ADD COLUMN `IsActive` tinyint(1) NOT NULL DEFAULT 1 AFTER `CriteriaName`;

CREATE TABLE IF NOT EXISTS `scholarship_configuration_versions` (
  `ConfigurationVersionID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `VersionNumber` int unsigned NOT NULL,
  `SnapshotHash` char(64) NOT NULL,
  `SnapshotJson` longtext NOT NULL,
  `ChangeSummary` varchar(255) DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ConfigurationVersionID`),
  UNIQUE KEY `uq_scholarship_config_version` (`ScholarshipID`,`VersionNumber`),
  KEY `idx_scholarship_config_hash` (`ScholarshipID`,`SnapshotHash`),
  CONSTRAINT `fk_config_version_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `fk_config_version_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `application`
  ADD COLUMN `ConfigurationVersionID` bigint unsigned DEFAULT NULL AFTER `TermID`,
  ADD KEY `idx_application_configuration_version` (`ConfigurationVersionID`),
  ADD CONSTRAINT `fk_application_configuration_version` FOREIGN KEY (`ConfigurationVersionID`) REFERENCES `scholarship_configuration_versions` (`ConfigurationVersionID`) ON DELETE RESTRICT;

