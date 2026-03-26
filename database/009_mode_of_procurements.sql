USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `mode_of_procurements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mode_code` VARCHAR(50) NOT NULL,
  `mode_name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mode_of_procurements_mode_code` (`mode_code`),
  UNIQUE KEY `uk_mode_of_procurements_mode_name` (`mode_name`),
  KEY `idx_mode_of_procurements_created_by` (`created_by`),
  KEY `idx_mode_of_procurements_updated_by` (`updated_by`),
  CONSTRAINT `fk_mode_of_procurements_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_mode_of_procurements_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'mode_of_procurements', 'MOP', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'mode_of_procurements');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0001', 'Public Bidding', 'Competitive public bidding process'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Public Bidding');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0002', 'Shopping', 'Procurement through shopping under approved thresholds'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Shopping');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0003', 'Small Value Procurement', 'Procurement for small value requirements'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Small Value Procurement');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0004', 'Negotiated Procurement', 'Negotiated procurement mode'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Negotiated Procurement');

INSERT INTO `mode_of_procurements` (`mode_code`, `mode_name`, `description`)
SELECT 'MOP-2026-0005', 'Direct Contracting', 'Direct contracting procurement mode'
WHERE NOT EXISTS (SELECT 1 FROM `mode_of_procurements` WHERE `mode_name` = 'Direct Contracting');

ALTER TABLE `purchase_orders`
  ADD COLUMN IF NOT EXISTS `mode_of_procurement_id` BIGINT UNSIGNED NULL AFTER `supplier_address`,
  ADD COLUMN IF NOT EXISTS `expected_delivery_date` DATE DEFAULT NULL AFTER `delivery_term_days`;

UPDATE `purchase_orders` po
INNER JOIN `mode_of_procurements` mop ON mop.`mode_name` = po.`mode_of_procurement`
SET po.`mode_of_procurement_id` = mop.`id`
WHERE po.`mode_of_procurement_id` IS NULL
  AND po.`mode_of_procurement` IS NOT NULL
  AND po.`mode_of_procurement` <> '';

UPDATE `purchase_orders`
SET `expected_delivery_date` = DATE_ADD(`po_date`, INTERVAL `delivery_term_days` DAY)
WHERE `expected_delivery_date` IS NULL
  AND `delivery_term_days` IS NOT NULL;

ALTER TABLE `purchase_orders`
  MODIFY COLUMN `mode_of_procurement_id` BIGINT UNSIGNED NOT NULL;

SET @mode_mop_idx_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`STATISTICS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_orders'
    AND `INDEX_NAME` = 'idx_purchase_orders_mode_of_procurement_id'
);

SET @create_mop_idx_sql := IF(
  @mode_mop_idx_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_orders` ADD KEY `idx_purchase_orders_mode_of_procurement_id` (`mode_of_procurement_id`)'
);

PREPARE `purchase_orders_mode_idx_stmt` FROM @create_mop_idx_sql;
EXECUTE `purchase_orders_mode_idx_stmt`;
DEALLOCATE PREPARE `purchase_orders_mode_idx_stmt`;

SET @mode_mop_fk_exists := (
  SELECT COUNT(*)
  FROM `information_schema`.`TABLE_CONSTRAINTS`
  WHERE `CONSTRAINT_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'purchase_orders'
    AND `CONSTRAINT_NAME` = 'fk_purchase_orders_mode_of_procurement_id'
);

SET @create_mop_fk_sql := IF(
  @mode_mop_fk_exists > 0,
  'SELECT 1',
  'ALTER TABLE `purchase_orders` ADD CONSTRAINT `fk_purchase_orders_mode_of_procurement_id` FOREIGN KEY (`mode_of_procurement_id`) REFERENCES `mode_of_procurements` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT'
);

PREPARE `purchase_orders_mode_fk_stmt` FROM @create_mop_fk_sql;
EXECUTE `purchase_orders_mode_fk_stmt`;
DEALLOCATE PREPARE `purchase_orders_mode_fk_stmt`;
