USE `spamsdb`;

ALTER TABLE `distribution_item_details`
    ADD COLUMN IF NOT EXISTS `correction_status` VARCHAR(40) NULL AFTER `is_disposed`,
    ADD COLUMN IF NOT EXISTS `correction_reason` VARCHAR(80) NULL AFTER `correction_status`,
    ADD COLUMN IF NOT EXISTS `correction_remarks` TEXT NULL AFTER `correction_reason`,
    ADD COLUMN IF NOT EXISTS `corrected_at` DATETIME NULL AFTER `correction_remarks`,
    ADD COLUMN IF NOT EXISTS `corrected_by` BIGINT UNSIGNED NULL AFTER `corrected_at`;

