CREATE TABLE IF NOT EXISTS `purchase_order_email_notifications` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `purchase_order_id` BIGINT UNSIGNED NULL,
    `notification_type` VARCHAR(40) NOT NULL,
    `period_key` VARCHAR(20) NOT NULL,
    `recipient_email` VARCHAR(150) NOT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_po_email_notification` (`purchase_order_id`, `notification_type`, `period_key`, `recipient_email`),
    INDEX `idx_po_email_notification_period` (`notification_type`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
