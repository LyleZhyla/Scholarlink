-- Lets a form field share its row with the next one (shown side by side)
ALTER TABLE `scholarship_custom_fields` ADD COLUMN `Width` enum('full','half') NOT NULL DEFAULT 'full' AFTER `SortOrder`;
