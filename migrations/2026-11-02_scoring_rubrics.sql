ALTER TABLE `scholarship_criteria`
  ADD COLUMN `WeightPercent` decimal(5,2) NOT NULL DEFAULT 100.00 AFTER `CriteriaName`,
  ADD COLUMN `MaximumScore` decimal(8,2) NOT NULL DEFAULT 100.00 AFTER `WeightPercent`,
  ADD COLUMN `PassingScore` decimal(8,2) DEFAULT NULL AFTER `MaximumScore`,
  ADD COLUMN `CommentRequired` tinyint(1) NOT NULL DEFAULT 0 AFTER `PassingScore`,
  ADD COLUMN `TieBreakPriority` int unsigned DEFAULT NULL AFTER `CommentRequired`,
  ADD COLUMN `SortOrder` int unsigned NOT NULL DEFAULT 0 AFTER `TieBreakPriority`;

UPDATE `scholarship_criteria` sc
JOIN (SELECT ScholarshipID, COUNT(*) CriterionCount FROM scholarship_criteria WHERE IsActive = 1 GROUP BY ScholarshipID) totals ON totals.ScholarshipID = sc.ScholarshipID
SET sc.WeightPercent = ROUND(100 / totals.CriterionCount, 2)
WHERE sc.IsActive = 1;

CREATE TABLE IF NOT EXISTS `scholarship_scoring_rubrics` (
  `RubricID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `PassingScore` decimal(5,2) NOT NULL DEFAULT 75.00,
  `TotalMaximumScore` decimal(8,2) NOT NULL DEFAULT 100.00,
  `TieBreakingRules` text NOT NULL,
  `GeneralCommentsRequired` tinyint(1) NOT NULL DEFAULT 1,
  `OverrideProcedure` text NOT NULL,
  `UpdatedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`RubricID`),
  UNIQUE KEY `uq_scoring_rubric_scholarship` (`ScholarshipID`),
  CONSTRAINT `fk_scoring_rubric_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE,
  CONSTRAINT `fk_scoring_rubric_updater` FOREIGN KEY (`UpdatedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `application_scorecards` (
  `ScorecardID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) DEFAULT NULL,
  `RawTotal` decimal(10,2) NOT NULL,
  `WeightedScore` decimal(5,2) NOT NULL,
  `PassingScore` decimal(5,2) NOT NULL,
  `Result` enum('Passed','Failed') NOT NULL,
  `EvaluatorComments` text DEFAULT NULL,
  `RubricSnapshotJson` longtext NOT NULL,
  `IsCurrent` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ScorecardID`),
  KEY `idx_scorecard_application_current` (`ApplicationID`,`IsCurrent`),
  CONSTRAINT `fk_scorecard_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_scorecard_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `application_score_items` (
  `ScoreItemID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ScorecardID` bigint unsigned NOT NULL,
  `CriteriaID` int(11) DEFAULT NULL,
  `CriteriaName` varchar(255) NOT NULL,
  `RawScore` decimal(8,2) NOT NULL,
  `MaximumScore` decimal(8,2) NOT NULL,
  `WeightPercent` decimal(5,2) NOT NULL,
  `WeightedScore` decimal(8,2) NOT NULL,
  `EvaluatorComment` text DEFAULT NULL,
  PRIMARY KEY (`ScoreItemID`),
  KEY `idx_score_item_scorecard` (`ScorecardID`),
  CONSTRAINT `fk_score_item_scorecard` FOREIGN KEY (`ScorecardID`) REFERENCES `application_scorecards` (`ScorecardID`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_item_criteria` FOREIGN KEY (`CriteriaID`) REFERENCES `scholarship_criteria` (`CriteriaID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `score_overrides` (
  `OverrideID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `ScorecardID` bigint unsigned DEFAULT NULL,
  `PreviousScore` decimal(5,2) NOT NULL,
  `OverrideScore` decimal(5,2) NOT NULL,
  `Reason` text NOT NULL,
  `AuthorizedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`OverrideID`),
  KEY `idx_score_override_application` (`ApplicationID`,`CreatedAt`),
  CONSTRAINT `fk_score_override_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_score_override_scorecard` FOREIGN KEY (`ScorecardID`) REFERENCES `application_scorecards` (`ScorecardID`) ON DELETE SET NULL,
  CONSTRAINT `fk_score_override_authorizer` FOREIGN KEY (`AuthorizedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
