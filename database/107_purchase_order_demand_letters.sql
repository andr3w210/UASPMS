CREATE TABLE IF NOT EXISTS `purchase_order_demand_letters` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `purchase_order_id` BIGINT UNSIGNED NOT NULL,
    `sent_date` DATE NOT NULL,
    `sent_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_demand_letters_purchase_order` (`purchase_order_id`),
    INDEX `idx_demand_letters_sent_date` (`sent_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
