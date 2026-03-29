USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `disposals` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `system_reference` VARCHAR(50) NOT NULL,
    `disposal_date` DATE NOT NULL,
    `distribution_item_detail_id` BIGINT UNSIGNED NULL,
    `disposal_type` ENUM('equipment','semi_expendable') NOT NULL DEFAULT 'equipment',
    `reason` VARCHAR(100) NOT NULL,
    `approved_by` BIGINT UNSIGNED NULL,
    `remarks` TEXT NULL,
    `status` ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_disposals_system_reference` (`system_reference`),
    KEY `idx_disposals_distribution_item_detail_id` (`distribution_item_detail_id`),
    KEY `idx_disposals_approved_by` (`approved_by`),
    KEY `idx_disposals_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
