ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `FailedLoginCount` int unsigned NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `LockedUntil` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `AuthVersion` int unsigned NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS `ProviderAccessStatus` varchar(20) NOT NULL DEFAULT 'Active',
  ADD COLUMN IF NOT EXISTS `ProviderAccessUntil` date DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ProviderAccessReason` varchar(500) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ProviderAccessReviewedAt` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `ProviderAccessReviewedBy` int(11) DEFAULT NULL,
  ADD KEY IF NOT EXISTS `idx_user_login_lock` (`LockedUntil`),
  ADD KEY IF NOT EXISTS `idx_provider_access` (`Role`,`ProviderAccessStatus`,`ProviderAccessUntil`);

CREATE TABLE IF NOT EXISTS `security_rate_limits` (
  `RateLimitID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `Scope` varchar(30) NOT NULL,
  `IdentifierHash` char(64) NOT NULL,
  `IPAddress` varchar(45) NOT NULL,
  `AttemptCount` int unsigned NOT NULL DEFAULT 0,
  `WindowStartedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `LastAttemptAt` datetime NOT NULL DEFAULT current_timestamp(),
  `LockedUntil` datetime DEFAULT NULL,
  PRIMARY KEY (`RateLimitID`),
  UNIQUE KEY `uq_security_rate_limit` (`Scope`,`IdentifierHash`,`IPAddress`),
  KEY `idx_security_rate_lock` (`Scope`,`LockedUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `system_settings` (`setting_key`,`setting_value`) VALUES
('require_2fa','1'),
('login_max_attempts','5'),
('login_lock_minutes','15'),
('absolute_session_timeout','28800'),
('recovery_max_requests','3'),
('provider_default_access_days','365')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);
