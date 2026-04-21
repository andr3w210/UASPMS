USE `spamsdb`;

ALTER TABLE `distribution_item_details`
    ADD COLUMN IF NOT EXISTS `qr_tag_code` VARCHAR(80) NULL AFTER `serial_no`;

ALTER TABLE `legacy_assets`
    ADD COLUMN IF NOT EXISTS `qr_tag_code` VARCHAR(80) NULL AFTER `serial_no`;
