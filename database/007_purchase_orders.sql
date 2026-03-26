USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `po_number` VARCHAR(100) NOT NULL,
  `po_date` DATE NOT NULL,
  `supplier_id` BIGINT UNSIGNED NOT NULL,
  `supplier_address` VARCHAR(255) DEFAULT NULL,
  `mode_of_procurement` VARCHAR(100) DEFAULT NULL,
  `place_of_delivery` VARCHAR(255) NOT NULL DEFAULT 'University of Antique',
  `delivery_term_days` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('encoded', 'verified', 'cancelled') NOT NULL DEFAULT 'encoded',
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_orders_system_reference` (`system_reference`),
  UNIQUE KEY `uk_purchase_orders_po_number` (`po_number`),
  KEY `idx_purchase_orders_supplier_id` (`supplier_id`),
  KEY `idx_purchase_orders_created_by` (`created_by`),
  KEY `idx_purchase_orders_updated_by` (`updated_by`),
  CONSTRAINT `fk_purchase_orders_supplier_id`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_purchase_orders_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `line_no` INT UNSIGNED NOT NULL,
  `item_type` ENUM('supply', 'semi_expendable', 'equipment') NOT NULL DEFAULT 'supply',
  `classification_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_of_measure_id` BIGINT UNSIGNED DEFAULT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_order_items_purchase_order_id` (`purchase_order_id`),
  KEY `idx_purchase_order_items_classification_id` (`classification_id`),
  KEY `idx_purchase_order_items_unit_of_measure_id` (`unit_of_measure_id`),
  CONSTRAINT `fk_purchase_order_items_purchase_order_id`
    FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_order_items_classification_id`
    FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_order_items_unit_of_measure_id`
    FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'purchase_orders', 'POREC', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'purchase_orders');
