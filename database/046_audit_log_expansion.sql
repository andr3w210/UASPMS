USE `spamsdb`;

ALTER TABLE `audit_logs`
    ADD COLUMN IF NOT EXISTS `action` VARCHAR(50) NOT NULL DEFAULT 'update' AFTER `user_id`,
    ADD COLUMN IF NOT EXISTS `table_name` VARCHAR(100) NOT NULL DEFAULT 'unknown' AFTER `action`,
    ADD COLUMN IF NOT EXISTS `old_values` LONGTEXT NULL AFTER `record_id`,
    ADD COLUMN IF NOT EXISTS `new_values` LONGTEXT NULL AFTER `old_values`;

ALTER TABLE `audit_logs`
    MODIFY COLUMN `module_name` VARCHAR(100) NULL,
    MODIFY COLUMN `record_type` VARCHAR(100) NULL,
    MODIFY COLUMN `record_id` VARCHAR(100) NULL,
    MODIFY COLUMN `action_name` VARCHAR(100) NULL;
