USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `distributions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `system_reference` VARCHAR(50) NOT NULL,
  `document_type` ENUM('ics', 'par') NOT NULL,
  `document_no` VARCHAR(50) NOT NULL,
  `distribution_date` DATE NOT NULL,
  `office_id` BIGINT UNSIGNED NOT NULL,
  `employee_id` BIGINT UNSIGNED DEFAULT NULL,
  `purpose` TEXT DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('posted', 'cancelled') NOT NULL DEFAULT 'posted',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_distributions_system_reference` (`system_reference`),
  UNIQUE KEY `uk_distributions_document_no` (`document_no`),
  KEY `idx_distributions_office_id` (`office_id`),
  KEY `idx_distributions_employee_id` (`employee_id`),
  KEY `idx_distributions_created_by` (`created_by`),
  KEY `idx_distributions_updated_by` (`updated_by`),
  CONSTRAINT `fk_distributions_office_id`
    FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_distributions_employee_id`
    FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_distributions_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_distributions_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `distribution_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_id` BIGINT UNSIGNED NOT NULL,
  `receiving_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity_distributed` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remarks` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_distribution_items_distribution_id` (`distribution_id`),
  KEY `idx_distribution_items_receiving_item_id` (`receiving_item_id`),
  CONSTRAINT `fk_distribution_items_distribution_id`
    FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_distribution_items_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `distribution_item_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `distribution_item_id` BIGINT UNSIGNED NOT NULL,
  `receiving_item_detail_id` BIGINT UNSIGNED DEFAULT NULL,
  `brand` VARCHAR(150) DEFAULT NULL,
  `model` VARCHAR(150) DEFAULT NULL,
  `serial_no` VARCHAR(150) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_distribution_item_details_distribution_item_id` (`distribution_item_id`),
  KEY `idx_distribution_item_details_receiving_item_detail_id` (`receiving_item_detail_id`),
  CONSTRAINT `fk_distribution_item_details_distribution_item_id`
    FOREIGN KEY (`distribution_item_id`) REFERENCES `distribution_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_distribution_item_details_receiving_item_detail_id`
    FOREIGN KEY (`receiving_item_detail_id`) REFERENCES `receiving_item_details` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'distributions', 'DST', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'distributions');
