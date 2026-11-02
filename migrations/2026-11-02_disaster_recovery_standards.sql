CREATE TABLE IF NOT EXISTS `disaster_recovery_settings` (
  `SettingsID` tinyint unsigned NOT NULL DEFAULT 1,
  `ScheduleEnabled` tinyint(1) NOT NULL DEFAULT 1,
  `ScheduleIntervalHours` int unsigned NOT NULL DEFAULT 24,
  `RPOHours` decimal(8,2) NOT NULL DEFAULT 24.00,
  `RTOHours` decimal(8,2) NOT NULL DEFAULT 4.00,
  `SecondaryStoragePath` varchar(500) DEFAULT NULL,
  `RetentionDays` int unsigned NOT NULL DEFAULT 30,
  `LastScheduledAt` datetime DEFAULT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`SettingsID`),
  CONSTRAINT `fk_dr_settings_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `disaster_recovery_settings` (`SettingsID`) VALUES (1);

CREATE TABLE IF NOT EXISTS `backup_records` (
  `BackupID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `BackupType` enum('Scheduled','Manual','Pre-Restore') NOT NULL,
  `FileName` varchar(255) NOT NULL,
  `StoragePath` varchar(700) NOT NULL,
  `EncryptionMethod` varchar(80) NOT NULL DEFAULT 'AES-256-GCM',
  `PlainChecksum` char(64) NOT NULL,
  `EncryptedChecksum` char(64) NOT NULL,
  `FileSize` bigint unsigned NOT NULL,
  `Status` enum('Available','Missing','Failed','Expired') NOT NULL DEFAULT 'Available',
  `CreatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `VerifiedAt` datetime DEFAULT NULL,
  `FailureDetails` text DEFAULT NULL,
  PRIMARY KEY (`BackupID`),
  UNIQUE KEY `uq_backup_filename` (`FileName`),
  KEY `idx_backup_catalog` (`Status`,`CreatedAt`),
  CONSTRAINT `fk_backup_creator` FOREIGN KEY (`CreatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `restoration_drills` (
  `DrillID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `BackupID` bigint unsigned NOT NULL,
  `TargetDatabase` varchar(100) NOT NULL,
  `Status` enum('Running','Passed','Failed') NOT NULL DEFAULT 'Running',
  `StartedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `CompletedAt` datetime DEFAULT NULL,
  `BackupAgeHours` decimal(10,2) DEFAULT NULL,
  `RestoreDurationSeconds` decimal(12,3) DEFAULT NULL,
  `TableCount` int unsigned DEFAULT NULL,
  `RowCount` bigint unsigned DEFAULT NULL,
  `RPOCompliant` tinyint(1) DEFAULT NULL,
  `RTOCompliant` tinyint(1) DEFAULT NULL,
  `ValidationNotes` text DEFAULT NULL,
  `ExecutedBy` int(11) DEFAULT NULL,
  PRIMARY KEY (`DrillID`),
  KEY `idx_drill_history` (`Status`,`StartedAt`),
  CONSTRAINT `fk_drill_backup` FOREIGN KEY (`BackupID`) REFERENCES `backup_records` (`BackupID`) ON DELETE RESTRICT,
  CONSTRAINT `fk_drill_executor` FOREIGN KEY (`ExecutedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
