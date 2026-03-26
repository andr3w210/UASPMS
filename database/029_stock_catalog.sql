USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `stock_catalog` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_no` VARCHAR(100) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `item_type` ENUM('supply','semi_expendable','equipment') NOT NULL DEFAULT 'supply',
  `classification_id` INT(10) UNSIGNED DEFAULT NULL,
  `account_code_id` INT(10) UNSIGNED DEFAULT NULL,
  `unit_of_measure_id` INT(10) UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT(10) UNSIGNED DEFAULT NULL,
  `updated_by` INT(10) UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_catalog_stock_no` (`stock_no`),
  KEY `idx_stock_catalog_item_type` (`item_type`),
  KEY `idx_stock_catalog_classification_id` (`classification_id`),
  KEY `idx_stock_catalog_account_code_id` (`account_code_id`),
  CONSTRAINT `fk_sc_classification`
    FOREIGN KEY (`classification_id`)
    REFERENCES `classifications`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_account_code`
    FOREIGN KEY (`account_code_id`)
    REFERENCES `account_codes`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_uom`
    FOREIGN KEY (`unit_of_measure_id`)
    REFERENCES `unit_of_measures`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_created_by`
    FOREIGN KEY (`created_by`)
    REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sc_updated_by`
    FOREIGN KEY (`updated_by`)
    REFERENCES `users`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `purchase_order_items`
  ADD COLUMN IF NOT EXISTS `stock_catalog_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Reference to stock catalog item if selected from catalog' AFTER `purchase_order_id`;

ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_stock_catalog`
    FOREIGN KEY (`stock_catalog_id`)
    REFERENCES `stock_catalog`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `stock_items`
  ADD COLUMN IF NOT EXISTS `stock_catalog_id` INT(10) UNSIGNED DEFAULT NULL COMMENT 'Reference to stock catalog item' AFTER `system_reference`;

ALTER TABLE `stock_items`
  ADD CONSTRAINT `fk_si_stock_catalog`
    FOREIGN KEY (`stock_catalog_id`)
    REFERENCES `stock_catalog`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;
