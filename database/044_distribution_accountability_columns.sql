USE `spamsdb`;

ALTER TABLE `distribution_item_details`
    ADD COLUMN IF NOT EXISTS `current_office_id` BIGINT UNSIGNED NULL AFTER `is_distributed`,
    ADD COLUMN IF NOT EXISTS `current_employee_id` BIGINT UNSIGNED NULL AFTER `current_office_id`,
    ADD COLUMN IF NOT EXISTS `current_responsibility_code_id` BIGINT UNSIGNED NULL AFTER `current_employee_id`,
    ADD COLUMN IF NOT EXISTS `is_disposed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `current_responsibility_code_id`;
