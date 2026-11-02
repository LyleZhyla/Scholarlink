CREATE TABLE IF NOT EXISTS `evaluation_assignments` (
  `AssignmentID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) NOT NULL,
  `AssignedBy` int(11) DEFAULT NULL,
  `AssignmentStatus` enum('Active','Conflict','Reassigned','Completed') NOT NULL DEFAULT 'Active',
  `AssignmentReason` varchar(255) DEFAULT NULL,
  `AssignedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `EndedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`AssignmentID`),
  KEY `idx_evaluation_assignment_active` (`ApplicationID`,`AssignmentStatus`),
  KEY `idx_evaluation_assignment_evaluator` (`EvaluatorID`,`AssignmentStatus`),
  CONSTRAINT `fk_eval_assignment_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_assignment_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_eval_assignment_assigner` FOREIGN KEY (`AssignedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `evaluation_conflict_declarations` (
  `DeclarationID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `AssignmentID` bigint unsigned NOT NULL,
  `ApplicationID` int(11) NOT NULL,
  `EvaluatorID` int(11) NOT NULL,
  `ConflictStatus` enum('No Conflict','Conflict Disclosed') NOT NULL,
  `RelationshipType` enum('None','Relative','Personally Known','Financial or Business Interest','Other Prohibited Relationship') NOT NULL DEFAULT 'None',
  `Details` text DEFAULT NULL,
  `ResolutionStatus` enum('Cleared','Pending Reassignment','Reassigned') NOT NULL,
  `ReassignedTo` int(11) DEFAULT NULL,
  `ResolvedBy` int(11) DEFAULT NULL,
  `DeclaredAt` datetime NOT NULL DEFAULT current_timestamp(),
  `ResolvedAt` datetime DEFAULT NULL,
  PRIMARY KEY (`DeclarationID`),
  UNIQUE KEY `uq_conflict_assignment` (`AssignmentID`),
  KEY `idx_conflict_queue` (`ResolutionStatus`,`DeclaredAt`),
  CONSTRAINT `fk_conflict_assignment` FOREIGN KEY (`AssignmentID`) REFERENCES `evaluation_assignments` (`AssignmentID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_application` FOREIGN KEY (`ApplicationID`) REFERENCES `application` (`ApplicationID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_evaluator` FOREIGN KEY (`EvaluatorID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `fk_conflict_reassigned_to` FOREIGN KEY (`ReassignedTo`) REFERENCES `users` (`UserID`) ON DELETE SET NULL,
  CONSTRAINT `fk_conflict_resolver` FOREIGN KEY (`ResolvedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

