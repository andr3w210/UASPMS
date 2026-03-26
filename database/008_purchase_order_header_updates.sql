USE `spamsdb`;

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `supplier_address` VARCHAR(255) DEFAULT NULL AFTER `supplier_id`,
  ADD COLUMN IF NOT EXISTS `mode_of_procurement` VARCHAR(100) DEFAULT NULL AFTER `supplier_address`,
  ADD COLUMN IF NOT EXISTS `place_of_delivery` VARCHAR(255) NOT NULL DEFAULT 'University of Antique' AFTER `mode_of_procurement`,
  ADD COLUMN IF NOT EXISTS `delivery_term_days` INT UNSIGNED DEFAULT NULL AFTER `place_of_delivery`;

SET @office_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_orders'
    AND `CONSTRAINT_NAME` = 'fk_purchase_orders_office_id'
);

SET @drop_office_fk_sql := IF(
  @office_fk_exists > 0,
  'ALTER TABLE `purchase_orders` DROP FOREIGN KEY `fk_purchase_orders_office_id`',
  'SELECT 1'
);

PREPARE `purchase_orders_stmt` FROM @drop_office_fk_sql;
EXECUTE `purchase_orders_stmt`;
DEALLOCATE PREPARE `purchase_orders_stmt`;

ALTER TABLE `purchase_orders`
  MODIFY COLUMN `office_id` BIGINT UNSIGNED NULL;
