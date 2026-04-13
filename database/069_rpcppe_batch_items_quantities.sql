USE `spamsdb`;

ALTER TABLE `rpcppe_batch_items`
    ADD COLUMN IF NOT EXISTS `qty_property_card`  SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `acquisition_date`,
    ADD COLUMN IF NOT EXISTS `qty_physical_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `qty_property_card`;
