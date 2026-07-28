USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `return_batches` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `item_type` ENUM('equipment','semi_expendable') NOT NULL,
    `return_date` DATE NOT NULL,
    `reason` TEXT NULL,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_return_batches_system_reference` (`system_reference`),
    KEY `idx_return_batches_item_type` (`item_type`),
    KEY `idx_return_batches_return_date` (`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `returns`
    ADD COLUMN IF NOT EXISTS `return_batch_id` BIGINT UNSIGNED NULL AFTER `legacy_asset_id`,
    ADD KEY `idx_returns_return_batch_id` (`return_batch_id`);
