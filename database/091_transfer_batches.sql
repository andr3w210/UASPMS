USE `spamsdb`;

ALTER TABLE `asset_transfers`
    ADD COLUMN IF NOT EXISTS `batch_id` BIGINT UNSIGNED NULL AFTER `legacy_asset_id`,
    ADD KEY `idx_asset_transfers_batch_id` (`batch_id`);

CREATE TABLE IF NOT EXISTS `transfer_batches` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `document_type` ENUM('ptr','itr') NOT NULL,
    `transfer_date` DATE NOT NULL,
    `source_office_id` INT UNSIGNED NULL,
    `source_employee_id` INT UNSIGNED NULL,
    `to_office_id` INT UNSIGNED NULL,
    `to_employee_id` INT UNSIGNED NULL,
    `to_responsibility_code_id` INT UNSIGNED NULL,
    `transfer_type` ENUM('donation','relocate','reassignment','others') NULL,
    `reason` TEXT NULL,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_transfer_batches_system_reference` (`system_reference`),
    KEY `idx_transfer_batches_document_type` (`document_type`),
    KEY `idx_transfer_batches_transfer_date` (`transfer_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transfer_batch_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id` BIGINT UNSIGNED NOT NULL,
    `asset_transfer_id` BIGINT UNSIGNED NOT NULL,
    `source_type` ENUM('system','legacy') NOT NULL,
    `distribution_item_detail_id` BIGINT UNSIGNED NULL,
    `legacy_asset_id` BIGINT UNSIGNED NULL,
    `property_number` VARCHAR(100) NULL,
    `item_type` ENUM('equipment','semi_expendable') NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_transfer_batch_items_asset_transfer_id` (`asset_transfer_id`),
    KEY `idx_transfer_batch_items_batch_id` (`batch_id`),
    KEY `idx_transfer_batch_items_property_number` (`property_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
