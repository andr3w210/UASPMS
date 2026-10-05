USE `spamsdb`;

ALTER TABLE `returns`
    ADD COLUMN IF NOT EXISTS `disposition_status` VARCHAR(30) NOT NULL DEFAULT 'pending_inspection' AFTER `status`,
    ADD COLUMN IF NOT EXISTS `disposition_updated_by` INT UNSIGNED NULL AFTER `disposition_status`,
    ADD COLUMN IF NOT EXISTS `disposition_updated_at` DATETIME NULL AFTER `disposition_updated_by`;

UPDATE `returns`
SET `disposition_status` = 'pending_inspection'
WHERE `status` = 'posted' AND (`disposition_status` IS NULL OR `disposition_status` = '');
