USE `spamsdb`;

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `document_total_amount` DECIMAL(14,2) NULL DEFAULT NULL AFTER `total_amount`;