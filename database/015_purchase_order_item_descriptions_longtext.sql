USE `spamsdb`;

ALTER TABLE `purchase_order_items`
  MODIFY COLUMN `item_description` TEXT NOT NULL;
