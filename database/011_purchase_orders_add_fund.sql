USE `spamsdb`;

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `fund_id` BIGINT UNSIGNED NULL AFTER `supplier_id`;

UPDATE `purchase_orders` po
SET po.`fund_id` = (
  SELECT f.`id`
  FROM `funds` f
  ORDER BY f.`id` ASC
  LIMIT 1
)
WHERE po.`fund_id` IS NULL;

ALTER TABLE `purchase_orders`
  MODIFY COLUMN `fund_id` BIGINT UNSIGNED NOT NULL;

SET @purchase_orders_fund_idx_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_orders'
    AND `INDEX_NAME` = 'idx_purchase_orders_fund_id'
);

SET @create_purchase_orders_fund_idx_sql := IF(
  @purchase_orders_fund_idx_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_orders` ADD KEY `idx_purchase_orders_fund_id` (`fund_id`)'
);

PREPARE `purchase_orders_fund_idx_stmt` FROM @create_purchase_orders_fund_idx_sql;
EXECUTE `purchase_orders_fund_idx_stmt`;
DEALLOCATE PREPARE `purchase_orders_fund_idx_stmt`;

SET @purchase_orders_fund_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_orders'
    AND `CONSTRAINT_NAME` = 'fk_purchase_orders_fund_id'
);

SET @create_purchase_orders_fund_fk_sql := IF(
  @purchase_orders_fund_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_orders` ADD CONSTRAINT `fk_purchase_orders_fund_id` FOREIGN KEY (`fund_id`) REFERENCES `funds` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT'
);

PREPARE `purchase_orders_fund_fk_stmt` FROM @create_purchase_orders_fund_fk_sql;
EXECUTE `purchase_orders_fund_fk_stmt`;
DEALLOCATE PREPARE `purchase_orders_fund_fk_stmt`;
