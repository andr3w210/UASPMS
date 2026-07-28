USE `spamsdb`;

ALTER TABLE `legacy_assets`
    ADD COLUMN IF NOT EXISTS `accountability_status` VARCHAR(40) NOT NULL DEFAULT 'active' AFTER `responsibility_code_id`,
    ADD COLUMN IF NOT EXISTS `last_office_id` INT UNSIGNED NULL AFTER `accountability_status`,
    ADD COLUMN IF NOT EXISTS `last_employee_id` INT UNSIGNED NULL AFTER `last_office_id`,
    ADD COLUMN IF NOT EXISTS `last_responsibility_code_id` INT UNSIGNED NULL AFTER `last_employee_id`,
    ADD COLUMN IF NOT EXISTS `accountability_cleared_at` DATETIME NULL AFTER `last_responsibility_code_id`,
    ADD COLUMN IF NOT EXISTS `accountability_cleared_by` INT UNSIGNED NULL AFTER `accountability_cleared_at`,
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_accountability_status` (`accountability_status`),
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_last_office_id` (`last_office_id`),
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_last_employee_id` (`last_employee_id`);

UPDATE `legacy_assets`
SET `accountability_status` = 'active'
WHERE `accountability_status` IS NULL OR `accountability_status` = '';
