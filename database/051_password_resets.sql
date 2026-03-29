USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_password_resets_token_hash` (`token_hash`),
    KEY `idx_password_resets_user_id` (`user_id`),
    KEY `idx_password_resets_expires_at` (`expires_at`),
    KEY `idx_password_resets_used_at` (`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
