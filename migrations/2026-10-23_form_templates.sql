-- PDF forms that admins upload in the Form Builder (the files themselves are saved in /forms).
-- Built-in forms in /forms have no row here and are available to everyone;
-- uploaded forms are listed for the person who uploaded them (and Super Admins).
CREATE TABLE IF NOT EXISTS `form_templates` (
  `TemplateID` int(11) NOT NULL AUTO_INCREMENT,
  `FileName` varchar(150) NOT NULL,
  `Label` varchar(150) NOT NULL,
  `UploadedBy` int(11) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`TemplateID`),
  UNIQUE KEY `FileName` (`FileName`),
  KEY `UploadedBy` (`UploadedBy`),
  CONSTRAINT `fk_ft_user` FOREIGN KEY (`UploadedBy`) REFERENCES `users` (`UserID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
