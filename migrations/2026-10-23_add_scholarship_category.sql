-- Adds a Category (Scholarship / Incentive / Allowance) to every scholarship so students
-- and admins can filter by it. Categories are defined in includes/scholarship_categories.php.

ALTER TABLE `scholarship`
  ADD COLUMN `Category` varchar(50) NOT NULL DEFAULT 'Scholarship' AFTER `ScholarshipType`;

UPDATE `scholarship` SET `Category` = 'Allowance' WHERE `Name` LIKE '%Allowance%';
UPDATE `scholarship` SET `Category` = 'Incentive' WHERE `Name` LIKE '%Incentive%';
