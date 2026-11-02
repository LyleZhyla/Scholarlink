CREATE TABLE IF NOT EXISTS `scholar_benefit_accounts` (
  `BenefitAccountID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `TotalEntitlement` decimal(12,2) NOT NULL,
  `ReleaseFrequency` varchar(50) NOT NULL,
  `Notes` varchar(500) DEFAULT NULL,
  `SetBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`BenefitAccountID`),
  UNIQUE KEY `uq_benefit_account_application` (`ApplicationID`),
  CONSTRAINT `fk_benefit_account_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_account_set_by` FOREIGN KEY (`SetBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `benefit_releases` (
  `BenefitReleaseID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `BenefitAccountID` bigint unsigned NOT NULL,
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
  CONSTRAINT `fk_benefit_release_account` FOREIGN KEY (`BenefitAccountID`) REFERENCES `scholar_benefit_accounts` (`BenefitAccountID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_release_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_benefit_release_recorded_by` FOREIGN KEY (`RecordedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
