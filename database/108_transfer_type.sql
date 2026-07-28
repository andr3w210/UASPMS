USE `spamsdb`;

ALTER TABLE `asset_transfers`
    ADD COLUMN IF NOT EXISTS `transfer_type` ENUM('donation','relocate','reassignment','others') NULL AFTER `to_responsibility_code_id`;

ALTER TABLE `transfer_batches`
    ADD COLUMN IF NOT EXISTS `transfer_type` ENUM('donation','relocate','reassignment','others') NULL AFTER `to_responsibility_code_id`;
