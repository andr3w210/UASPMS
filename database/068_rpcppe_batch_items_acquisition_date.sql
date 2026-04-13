USE `spamsdb`;

ALTER TABLE `rpcppe_batch_items`
    ADD COLUMN IF NOT EXISTS `acquisition_date` DATE NULL AFTER `unit_cost`;
