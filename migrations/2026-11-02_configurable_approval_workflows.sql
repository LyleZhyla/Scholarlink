CREATE TABLE IF NOT EXISTS `scholarship_workflow_stages` (
  `WorkflowStageID` int(11) NOT NULL AUTO_INCREMENT,
  `ScholarshipID` int(11) NOT NULL,
  `StageOrder` int unsigned NOT NULL,
  `StageKey` varchar(50) NOT NULL DEFAULT 'custom',
  `StageName` varchar(120) NOT NULL,
  `AssignedRole` enum('Internal_Admin','External_Admin','Super_Admin') NOT NULL,
  `CompletionAction` enum('none','under_review','shortlisted','approve','award_confirm') NOT NULL DEFAULT 'none',
  `Instructions` text DEFAULT NULL,
  `IsActive` tinyint(1) NOT NULL DEFAULT 1,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`WorkflowStageID`),
  UNIQUE KEY `uq_workflow_stage_order` (`ScholarshipID`,`StageOrder`),
  KEY `idx_workflow_stage_role` (`AssignedRole`,`IsActive`),
  CONSTRAINT `fk_workflow_stage_scholarship` FOREIGN KEY (`ScholarshipID`) REFERENCES `scholarship` (`ScholarshipID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `application_workflow_progress` (
  `ProgressID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `WorkflowStageID` int(11) DEFAULT NULL,
  `StageOrder` int unsigned NOT NULL,
  `StageKey` varchar(50) NOT NULL,
  `StageName` varchar(120) NOT NULL,
  `AssignedRole` enum('Internal_Admin','External_Admin','Super_Admin') NOT NULL,
  `CompletionAction` enum('none','under_review','shortlisted','approve','award_confirm') NOT NULL DEFAULT 'none',
  `Instructions` text DEFAULT NULL,
  `Status` enum('Pending','In Progress','Completed','Returned','Skipped') NOT NULL DEFAULT 'Pending',
  `ReviewerComments` text DEFAULT NULL,
  `StartedAt` datetime DEFAULT NULL,
  `CompletedAt` datetime DEFAULT NULL,
  `CompletedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ProgressID`),
  UNIQUE KEY `uq_application_workflow_order` (`ApplicationID`,`StageOrder`),
  KEY `idx_workflow_queue` (`AssignedRole`,`Status`,`StageOrder`),
  CONSTRAINT `fk_workflow_progress_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_workflow_progress_stage` FOREIGN KEY (`WorkflowStageID`) REFERENCES `scholarship_workflow_stages` (`WorkflowStageID`) ON DELETE SET NULL,
  CONSTRAINT `fk_workflow_progress_reviewer` FOREIGN KEY (`CompletedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

