-- Form Builder: richer application questions per scholarship.
-- Existing questions keep working: they stay required, active, and in their current order.

ALTER TABLE `scholarship_custom_fields`
  ADD COLUMN `HelpText` varchar(255) DEFAULT NULL AFTER `FieldType`,
  ADD COLUMN `Placeholder` varchar(150) DEFAULT NULL AFTER `HelpText`,
  ADD COLUMN `Options` text DEFAULT NULL COMMENT 'Choices for Select/Radio/Checkbox, one per line' AFTER `Placeholder`,
  ADD COLUMN `IsRequired` tinyint(1) NOT NULL DEFAULT 1 AFTER `Options`,
  ADD COLUMN `SortOrder` int(11) NOT NULL DEFAULT 0 AFTER `IsRequired`,
  ADD COLUMN `IsActive` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = removed from the form but kept for past answers' AFTER `SortOrder`;

UPDATE `scholarship_custom_fields` SET `SortOrder` = `FieldID`;

ALTER TABLE `scholarship`
  ADD COLUMN `FormInstructions` text DEFAULT NULL AFTER `AllowsDual`,
  ADD COLUMN `FormUpdatedAt` datetime DEFAULT NULL AFTER `FormInstructions`;
