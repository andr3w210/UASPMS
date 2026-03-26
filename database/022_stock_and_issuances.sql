USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `stock_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `receiving_id` BIGINT UNSIGNED DEFAULT NULL,
  `receiving_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `purchase_order_item_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_type` ENUM('supply', 'semi_expendable', 'equipment') NOT NULL DEFAULT 'supply',
  `account_code_id` BIGINT UNSIGNED DEFAULT NULL,
  `classification_id` BIGINT UNSIGNED DEFAULT NULL,
  `unit_of_measure_id` BIGINT UNSIGNED DEFAULT NULL,
  `item_description` TEXT NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_received` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_issued` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_on_hand` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_items_system_reference` (`system_reference`),
  UNIQUE KEY `uk_stock_items_receiving_item_id` (`receiving_item_id`),
  KEY `idx_stock_items_receiving_id` (`receiving_id`),
  KEY `idx_stock_items_purchase_order_item_id` (`purchase_order_item_id`),
  KEY `idx_stock_items_account_code_id` (`account_code_id`),
  KEY `idx_stock_items_classification_id` (`classification_id`),
  KEY `idx_stock_items_unit_of_measure_id` (`unit_of_measure_id`),
  KEY `idx_stock_items_created_by` (`created_by`),
  KEY `idx_stock_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_items_receiving_id`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_purchase_order_item_id`
    FOREIGN KEY (`purchase_order_item_id`) REFERENCES `purchase_order_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_account_code_id`
    FOREIGN KEY (`account_code_id`) REFERENCES `account_codes` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_classification_id`
    FOREIGN KEY (`classification_id`) REFERENCES `classifications` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_unit_of_measure_id`
    FOREIGN KEY (`unit_of_measure_id`) REFERENCES `unit_of_measures` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_item_id` BIGINT UNSIGNED NOT NULL,
  `movement_type` ENUM('receipt', 'issue', 'return', 'adjustment') NOT NULL,
  `movement_date` DATE NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` BIGINT UNSIGNED DEFAULT NULL,
  `quantity_in` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `quantity_out` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `balance_after` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stock_movements_stock_item_id` (`stock_item_id`),
  KEY `idx_stock_movements_created_by` (`created_by`),
  CONSTRAINT `fk_stock_movements_stock_item_id`
    FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_stock_movements_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `issuances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `issuance_date` DATE NOT NULL,
  `office_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('draft', 'posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issuances_system_reference` (`system_reference`),
  KEY `idx_issuances_office_id` (`office_id`),
  KEY `idx_issuances_employee_id` (`employee_id`),
  KEY `idx_issuances_created_by` (`created_by`),
  KEY `idx_issuances_updated_by` (`updated_by`),
  CONSTRAINT `fk_issuances_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_issuances_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_issuances_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_issuances_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `issuance_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id` BIGINT UNSIGNED NOT NULL,
  `stock_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_issued` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_issuance_items_issuance_id` (`issuance_id`),
  KEY `idx_issuance_items_stock_item_id` (`stock_item_id`),
  CONSTRAINT `fk_issuance_items_issuance_id`
    FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_issuance_items_stock_item_id`
    FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'stock_items', 'STK', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'stock_items');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'issuances', 'ISS', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'issuances');

INSERT INTO `stock_items` (
  `system_reference`, `receiving_id`, `receiving_item_id`, `purchase_order_item_id`, `item_type`,
  `account_code_id`, `classification_id`, `unit_of_measure_id`, `item_description`, `unit_cost`,
  `quantity_received`, `quantity_issued`, `quantity_on_hand`, `created_by`, `created_at`, `updated_at`
)
SELECT
  CONCAT('STK-', YEAR(r.received_date), '-', LPAD(ri.id, 4, '0')) AS system_reference,
  r.id,
  ri.id,
  poi.id,
  poi.item_type,
  poi.account_code_id,
  poi.classification_id,
  poi.unit_of_measure_id,
  poi.item_description,
  ri.unit_cost,
  ri.quantity_accepted,
  0.00,
  ri.quantity_accepted,
  r.created_by,
  r.created_at,
  r.updated_at
FROM `receiving_items` ri
INNER JOIN `receivings` r ON r.id = ri.receiving_id
INNER JOIN `purchase_order_items` poi ON poi.id = ri.purchase_order_item_id
LEFT JOIN `stock_items` si ON si.receiving_item_id = ri.id
WHERE poi.item_type = 'supply'
  AND ri.quantity_accepted > 0
  AND si.id IS NULL;

INSERT INTO `stock_movements` (
  `stock_item_id`, `movement_type`, `movement_date`, `reference_type`, `reference_id`,
  `quantity_in`, `quantity_out`, `balance_after`, `remarks`, `created_by`, `created_at`
)
SELECT
  si.id,
  'receipt',
  r.received_date,
  'receiving',
  r.id,
  si.quantity_received,
  0.00,
  si.quantity_on_hand,
  CONCAT('Backfilled from receiving ', r.system_reference),
  r.created_by,
  r.created_at
FROM `stock_items` si
INNER JOIN `receivings` r ON r.id = si.receiving_id
LEFT JOIN `stock_movements` sm
  ON sm.stock_item_id = si.id
 AND sm.reference_type = 'receiving'
 AND sm.reference_id = r.id
WHERE sm.id IS NULL;

UPDATE `series_numbers`
SET `current_value` = GREATEST(`current_value`, (
    SELECT COUNT(*)
    FROM `stock_items`
    WHERE `system_reference` LIKE CONCAT(`series_numbers`.`prefix`, '-', YEAR(CURDATE()), '-%')
))
WHERE `module_key` = 'stock_items';
