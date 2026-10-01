USE `spamsdb`;

ALTER TABLE `legacy_assets`
    ADD COLUMN IF NOT EXISTS `acquisition_source` VARCHAR(30) NOT NULL DEFAULT 'purchase' AFTER `po_number`,
    ADD COLUMN IF NOT EXISTS `donor_name` VARCHAR(255) NULL AFTER `acquisition_source`,
    ADD COLUMN IF NOT EXISTS `donation_reference` VARCHAR(100) NULL AFTER `donor_name`,
    ADD COLUMN IF NOT EXISTS `donation_date` DATE NULL AFTER `donation_reference`;

UPDATE `legacy_assets`
SET `acquisition_source` = 'purchase'
WHERE `acquisition_source` IS NULL OR `acquisition_source` = '';

ALTER TABLE `legacy_assets`
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_acquisition_source` (`acquisition_source`),
    ADD INDEX IF NOT EXISTS `idx_legacy_assets_donation_date` (`donation_date`);
