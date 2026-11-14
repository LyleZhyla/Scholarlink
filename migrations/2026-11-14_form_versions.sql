-- Every save of the form builder keeps the whole form, so earlier versions can be opened and restored
CREATE TABLE IF NOT EXISTS `application_form_versions` (
  `FormVersionID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `VersionNumber` int(10) unsigned NOT NULL,
  `StateHash` char(64) NOT NULL,
  `StateJson` longtext NOT NULL,
  `Summary` varchar(255) DEFAULT NULL,
  `QuestionCount` int(11) NOT NULL DEFAULT 0,
  `PdfChangeCount` int(11) NOT NULL DEFAULT 0,
  `TemplateFile` varchar(150) DEFAULT NULL,
  `RestoredFrom` int(10) unsigned DEFAULT NULL,
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`FormVersionID`),
  UNIQUE KEY `uq_form_version` (`ScholarshipID`, `VersionNumber`),
  KEY `fk_form_version_creator` (`CreatedBy`),
  CONSTRAINT `fk_form_version_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `fk_form_version_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
