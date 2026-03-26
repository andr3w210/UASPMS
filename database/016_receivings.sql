USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `receivings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `purchase_order_id` BIGINT UNSIGNED NOT NULL,
  `received_date` DATE NOT NULL,
  `delivery_receipt_no` VARCHAR(100) DEFAULT NULL,
  `invoice_no` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('encoded', 'posted', 'cancelled') NOT NULL DEFAULT 'encoded',
  `remarks` TEXT DEFAULT NULL,
  `total_received_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receivings_system_reference` (`system_reference`),
  KEY `idx_receivings_purchase_order_id` (`purchase_order_id`),
  KEY `idx_receivings_created_by` (`created_by`),
  KEY `idx_receivings_updated_by` (`updated_by`),
  CONSTRAINT `fk_receivings_purchase_order_id`
    FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_receivings_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_receivings_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `receiving_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiving_id` BIGINT UNSIGNED NOT NULL,
  `purchase_order_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_received` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receiving_items_receiving_id` (`receiving_id`),
  KEY `idx_receiving_items_purchase_order_item_id` (`purchase_order_item_id`),
  CONSTRAINT `fk_receiving_items_receiving_id`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_receiving_items_purchase_order_item_id`
    FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'receivings', 'RCV', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'receivings');
