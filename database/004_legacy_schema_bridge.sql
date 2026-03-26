USE `spamsdb`;

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `is_unit_head` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `offices`
  ADD COLUMN IF NOT EXISTS `office_head_employee_id` INT(10) UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `created_by` INT(10) UNSIGNED DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_by` INT(10) UNSIGNED DEFAULT NULL;

UPDATE `offices`
SET `office_head_employee_id` = `head_employee_id`
WHERE `office_head_employee_id` IS NULL
  AND `head_employee_id` IS NOT NULL;
