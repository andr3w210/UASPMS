USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `rpcppe_batches` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_year` SMALLINT UNSIGNED NOT NULL,
    `batch_name` VARCHAR(150) NOT NULL,
    `as_of_date` DATE NOT NULL,
    `status` ENUM('draft', 'finalized') NOT NULL DEFAULT 'draft',
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `finalized_by` BIGINT UNSIGNED NULL,
    `finalized_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rpcppe_batches_year_status` (`batch_year`, `status`),
    KEY `idx_rpcppe_batches_as_of_date` (`as_of_date`),
    CONSTRAINT `fk_rpcppe_batches_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT `fk_rpcppe_batches_finalized_by`
        FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rpcppe_batch_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `source_type` ENUM('system', 'legacy') NOT NULL,
    `distribution_item_detail_id` BIGINT UNSIGNED NULL,
    `legacy_asset_id` BIGINT UNSIGNED NULL,
    `property_number` VARCHAR(120) NOT NULL,
    `item_description` TEXT NOT NULL,
    `description_detail` TEXT NULL,
    `classification_name` VARCHAR(255) NULL,
    `classification_family` VARCHAR(255) NULL,
    `uom_name` VARCHAR(120) NULL,
    `abbreviation` VARCHAR(60) NULL,
    `unit_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `acquisition_date` DATE NULL,
    `qty_property_card` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `qty_physical_count` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `brand` VARCHAR(200) NULL,
    `model` VARCHAR(200) NULL,
    `serial_no` VARCHAR(200) NULL,
    `office_id` BIGINT UNSIGNED NULL,
    `office_name` VARCHAR(255) NULL,
    `employee_id` BIGINT UNSIGNED NULL,
    `employee_name` VARCHAR(255) NULL,
    `account_code_id` BIGINT UNSIGNED NULL,
    `account_code` VARCHAR(100) NULL,
    `account_name` VARCHAR(255) NULL,
    `fund_code` VARCHAR(100) NULL,
    `fund_source` VARCHAR(150) NULL,
    `fund_number` VARCHAR(10) NULL,
    `remarks` VARCHAR(120) NULL,
    `is_included` TINYINT(1) NOT NULL DEFAULT 1,
    `is_disposed` TINYINT(1) NOT NULL DEFAULT 0,
    `disposed_at` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rpcppe_batch_items_batch_include` (`batch_id`, `is_included`, `is_disposed`),
    KEY `idx_rpcppe_batch_items_property` (`property_number`),
    KEY `idx_rpcppe_batch_items_system_asset` (`distribution_item_detail_id`),
    KEY `idx_rpcppe_batch_items_legacy_asset` (`legacy_asset_id`),
    CONSTRAINT `fk_rpcppe_batch_items_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `rpcppe_batches` (`id`)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT `fk_rpcppe_batch_items_distribution_detail`
        FOREIGN KEY (`distribution_item_detail_id`) REFERENCES `distribution_item_details` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT `fk_rpcppe_batch_items_legacy_asset`
        FOREIGN KEY (`legacy_asset_id`) REFERENCES `legacy_assets` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT `uq_rpcppe_batch_system_item`
        UNIQUE KEY (`batch_id`, `distribution_item_detail_id`),
    CONSTRAINT `uq_rpcppe_batch_legacy_item`
        UNIQUE KEY (`batch_id`, `legacy_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;