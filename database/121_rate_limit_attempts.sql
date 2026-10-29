USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `rate_limit_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `action_key` VARCHAR(80) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rate_limit_lookup` (`ip_address`, `action_key`, `attempted_at`),
    KEY `idx_rate_limit_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
