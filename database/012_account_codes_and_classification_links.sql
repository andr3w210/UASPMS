USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `account_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_group` ENUM('supply', 'asset', 'semi_expendable') NOT NULL DEFAULT 'asset',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_codes_account_code` (`account_code`),
  UNIQUE KEY `uk_account_codes_account_name` (`account_name`),
  KEY `idx_account_codes_created_by` (`created_by`),
  KEY `idx_account_codes_updated_by` (`updated_by`),
  CONSTRAINT `fk_account_codes_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_account_codes_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1-07-05-030', 'Information and Communication Technology Equipment', 'asset', 'Starter account code for ICT equipment'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1-07-05-030');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5-02-03-010', 'Office Supplies Expenses', 'supply', 'Starter account code for office supplies'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5-02-03-010');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1-06-07-010', 'Semi-Expendable Office Equipment', 'semi_expendable', 'Starter account code for semi-expendable equipment'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1-06-07-010');

SET @classifications_account_column_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'classifications'
    AND `COLUMN_NAME` = 'account_code_id'
);

SET @add_classifications_account_column_sql := IF(
  @classifications_account_column_exists > 0,
  'SELECT 1',
  'ALTER TABLE `classifications` ADD COLUMN `account_code_id` BIGINT UNSIGNED NULL AFTER `classification_group`'
);

PREPARE `classifications_account_column_stmt` FROM @add_classifications_account_column_sql;
EXECUTE `classifications_account_column_stmt`;
DEALLOCATE PREPARE `classifications_account_column_stmt`;

UPDATE `classifications` c
INNER JOIN `account_codes` ac
  ON ac.`account_group` = c.`classification_group`
SET c.`account_code_id` = ac.`id`
WHERE c.`account_code_id` IS NULL;

SET @classification_account_idx_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'classifications'
    AND `INDEX_NAME` = 'idx_classifications_account_code_id'
);

SET @create_classification_account_idx_sql := IF(
  @classification_account_idx_exists > 0,
  'SELECT 1',
  'ALTER TABLE `classifications` ADD KEY `idx_classifications_account_code_id` (`account_code_id`)'
);

PREPARE `classifications_account_idx_stmt` FROM @create_classification_account_idx_sql;
EXECUTE `classifications_account_idx_stmt`;
DEALLOCATE PREPARE `classifications_account_idx_stmt`;

SET @classification_account_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'classifications'
    AND `CONSTRAINT_NAME` = 'fk_classifications_account_code_id'
);

SET @create_classification_account_fk_sql := IF(
  @classification_account_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `classifications` ADD CONSTRAINT `fk_classifications_account_code_id` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON UPDATE CASCADE ON DELETE SET NULL'
);

PREPARE `classifications_account_fk_stmt` FROM @create_classification_account_fk_sql;
EXECUTE `classifications_account_fk_stmt`;
DEALLOCATE PREPARE `classifications_account_fk_stmt`;

SET @poi_account_column_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_order_items'
    AND `COLUMN_NAME` = 'account_code_id'
);

SET @add_poi_account_column_sql := IF(
  @poi_account_column_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_order_items` ADD COLUMN `account_code_id` BIGINT UNSIGNED NULL AFTER `item_type`'
);

PREPARE `purchase_order_items_account_column_stmt` FROM @add_poi_account_column_sql;
EXECUTE `purchase_order_items_account_column_stmt`;
DEALLOCATE PREPARE `purchase_order_items_account_column_stmt`;

UPDATE `purchase_order_items` poi
LEFT JOIN `classifications` c ON c.`id` = poi.`classification_id`
SET poi.`account_code_id` = c.`account_code_id`
WHERE poi.`account_code_id` IS NULL;

SET @poi_account_idx_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_order_items'
    AND `INDEX_NAME` = 'idx_purchase_order_items_account_code_id'
);

SET @create_poi_account_idx_sql := IF(
  @poi_account_idx_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_order_items` ADD KEY `idx_purchase_order_items_account_code_id` (`account_code_id`)'
);

PREPARE `purchase_order_items_account_idx_stmt` FROM @create_poi_account_idx_sql;
EXECUTE `purchase_order_items_account_idx_stmt`;
DEALLOCATE PREPARE `purchase_order_items_account_idx_stmt`;

SET @poi_account_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_order_items'
    AND `CONSTRAINT_NAME` = 'fk_purchase_order_items_account_code_id'
);

SET @create_poi_account_fk_sql := IF(
  @poi_account_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_order_items` ADD CONSTRAINT `fk_purchase_order_items_account_code_id` FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`) ON UPDATE CASCADE ON DELETE SET NULL'
);

PREPARE `purchase_order_items_account_fk_stmt` FROM @create_poi_account_fk_sql;
EXECUTE `purchase_order_items_account_fk_stmt`;
DEALLOCATE PREPARE `purchase_order_items_account_fk_stmt`;
