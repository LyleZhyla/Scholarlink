ALTER TABLE `submitted_document`
  ADD COLUMN `OriginalFileName` varchar(255) DEFAULT NULL AFTER `FilePath`,
  ADD COLUMN `MimeType` varchar(100) DEFAULT NULL AFTER `OriginalFileName`,
  ADD COLUMN `FileSize` bigint unsigned DEFAULT NULL AFTER `MimeType`,
  ADD COLUMN `FileChecksum` char(64) DEFAULT NULL AFTER `FileSize`,
  ADD COLUMN `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked' AFTER `FileChecksum`,
  ADD COLUMN `ScannerEngine` varchar(150) DEFAULT NULL AFTER `MalwareScanStatus`,
  ADD COLUMN `ScannedAt` datetime DEFAULT NULL AFTER `ScannerEngine`,
  ADD KEY `idx_submitted_checksum` (`FileChecksum`);

ALTER TABLE `submitted_document_versions`
  ADD COLUMN `MimeType` varchar(100) DEFAULT NULL AFTER `OriginalFileName`,
  ADD COLUMN `FileSize` bigint unsigned DEFAULT NULL AFTER `MimeType`,
  ADD COLUMN `FileChecksum` char(64) DEFAULT NULL AFTER `FileSize`,
  ADD COLUMN `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked' AFTER `FileChecksum`,
  ADD COLUMN `ScannerEngine` varchar(150) DEFAULT NULL AFTER `MalwareScanStatus`,
  ADD COLUMN `ScannedAt` datetime DEFAULT NULL AFTER `ScannerEngine`;

ALTER TABLE `user_vault`
  ADD COLUMN `OriginalFileName` varchar(255) DEFAULT NULL AFTER `FilePath`,
  ADD COLUMN `MimeType` varchar(100) DEFAULT NULL AFTER `OriginalFileName`,
  ADD COLUMN `FileSize` bigint unsigned DEFAULT NULL AFTER `MimeType`,
  ADD COLUMN `FileChecksum` char(64) DEFAULT NULL AFTER `FileSize`,
  ADD COLUMN `MalwareScanStatus` enum('Clean','Rejected','Error','LegacyUnchecked') NOT NULL DEFAULT 'LegacyUnchecked' AFTER `FileChecksum`,
  ADD COLUMN `ScannerEngine` varchar(150) DEFAULT NULL AFTER `MalwareScanStatus`,
  ADD COLUMN `ScannedAt` datetime DEFAULT NULL AFTER `ScannerEngine`,
  ADD KEY `idx_vault_checksum` (`FileChecksum`);
