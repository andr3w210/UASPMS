USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `legacy_assets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NULL,
    `property_number` VARCHAR(100) NOT NULL,
    `item_type` VARCHAR(30) NOT NULL DEFAULT 'equipment',
    `item_description` TEXT NOT NULL,
    `classification_id` INT UNSIGNED NULL,
    `account_code_id` INT UNSIGNED NULL,
    `supplier_id` INT UNSIGNED NULL,
    `brand_id` INT UNSIGNED NULL,
    `model_id` INT UNSIGNED NULL,
    `brand` VARCHAR(200) NULL,
    `model` VARCHAR(200) NULL,
    `serial_no` VARCHAR(200) NULL,
    `acquisition_date` DATE NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `acquisition_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `office_id` INT UNSIGNED NULL,
    `employee_id` INT UNSIGNED NULL,
    `responsibility_code_id` INT UNSIGNED NULL,
    `condition_status` VARCHAR(50) NULL,
    `remarks` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_property_number` (`property_number`),
    INDEX `idx_office` (`office_id`),
    INDEX `idx_employee` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `legacy_assets`
    ADD COLUMN IF NOT EXISTS `item_type` VARCHAR(30) NOT NULL DEFAULT 'equipment' AFTER `property_number`,
    ADD COLUMN IF NOT EXISTS `supplier_id` INT UNSIGNED NULL AFTER `account_code_id`,
    ADD COLUMN IF NOT EXISTS `brand_id` INT UNSIGNED NULL AFTER `supplier_id`,
    ADD COLUMN IF NOT EXISTS `model_id` INT UNSIGNED NULL AFTER `brand_id`,
    ADD COLUMN IF NOT EXISTS `quantity` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `acquisition_date`,
    ADD COLUMN IF NOT EXISTS `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `quantity`;

UPDATE `legacy_assets` SET `item_type` = 'equipment' WHERE `item_type` IS NULL OR `item_type` = '';
UPDATE `legacy_assets` SET `quantity` = 1 WHERE `quantity` IS NULL OR `quantity` <= 0;
UPDATE `legacy_assets` SET `unit_cost` = `acquisition_cost` WHERE `unit_cost` IS NULL OR `unit_cost` = 0;

CREATE TABLE IF NOT EXISTS `maintenance_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `maintenance_date` DATE NOT NULL,
    `distribution_item_detail_id` BIGINT UNSIGNED NULL,
    `work_description` TEXT NOT NULL,
    `performed_by` VARCHAR(200) NULL,
    `cost` DECIMAL(12,2) NULL DEFAULT 0.00,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `asset_transfers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `transfer_date` DATE NOT NULL,
    `source_type` ENUM('system','legacy') NOT NULL,
    `distribution_item_detail_id` BIGINT UNSIGNED NULL,
    `legacy_asset_id` BIGINT UNSIGNED NULL,
    `property_number` VARCHAR(100) NULL,
    `from_office_id` INT UNSIGNED NULL,
    `from_employee_id` INT UNSIGNED NULL,
    `from_responsibility_code_id` INT UNSIGNED NULL,
    `to_office_id` INT UNSIGNED NULL,
    `to_employee_id` INT UNSIGNED NULL,
    `to_responsibility_code_id` INT UNSIGNED NULL,
    `reason` TEXT NULL,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_order_delivery_extensions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `old_expected_delivery_date` DATE NOT NULL,
    `new_expected_delivery_date` DATE NOT NULL,
    `requested_extension_days` INT UNSIGNED NULL,
    `reason` TEXT NOT NULL,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `purchase_order_delivery_extensions`
    ADD COLUMN IF NOT EXISTS `requested_extension_days` INT UNSIGNED NULL AFTER `new_expected_delivery_date`;
