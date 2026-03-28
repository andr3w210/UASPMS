USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `user_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sender_user_id` INT UNSIGNED NOT NULL,
    `recipient_user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(200) NULL,
    `message_body` TEXT NOT NULL,
    `related_table` VARCHAR(50) NULL,
    `related_id` BIGINT UNSIGNED NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `hidden_for_sender` TINYINT(1) NOT NULL DEFAULT 0,
    `hidden_for_recipient` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sender` (`sender_user_id`),
    INDEX `idx_recipient` (`recipient_user_id`),
    INDEX `idx_recipient_read` (`recipient_user_id`, `is_read`),
    INDEX `idx_related` (`related_table`, `related_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `user_messages`
    ADD COLUMN IF NOT EXISTS `hidden_for_sender` TINYINT(1) NOT NULL DEFAULT 0 AFTER `read_at`,
    ADD COLUMN IF NOT EXISTS `hidden_for_recipient` TINYINT(1) NOT NULL DEFAULT 0 AFTER `hidden_for_sender`,
    ADD COLUMN IF NOT EXISTS `related_table` VARCHAR(50) NULL AFTER `message_body`,
    ADD COLUMN IF NOT EXISTS `related_id` BIGINT UNSIGNED NULL AFTER `related_table`;

CREATE TABLE IF NOT EXISTS `user_presence` (
    `user_id` INT UNSIGNED PRIMARY KEY,
    `last_seen_at` DATETIME NOT NULL,
    INDEX `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_channels` (
    `channel_key` VARCHAR(50) PRIMARY KEY,
    `channel_name` VARCHAR(100) NOT NULL,
    `channel_description` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `channel_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_key` VARCHAR(50) NOT NULL,
    `sender_user_id` INT UNSIGNED NOT NULL,
    `subject` VARCHAR(200) NULL,
    `message_body` TEXT NOT NULL,
    `related_table` VARCHAR(50) NULL,
    `related_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_channel_created` (`channel_key`, `created_at`),
    INDEX `idx_sender` (`sender_user_id`),
    INDEX `idx_related` (`related_table`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_channel_reads` (
    `channel_key` VARCHAR(50) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `last_read_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_read_at` DATETIME NULL,
    PRIMARY KEY (`channel_key`, `user_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `message_channel_hidden` (
    `channel_message_id` BIGINT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `hidden_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`channel_message_id`, `user_id`),
    INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `message_channels` (`channel_key`, `channel_name`, `channel_description`, `is_active`)
VALUES ('general', 'General Group Chat', 'Shared coordination channel for all active users.', 1)
ON DUPLICATE KEY UPDATE
    `channel_name` = VALUES(`channel_name`),
    `channel_description` = VALUES(`channel_description`),
    `is_active` = 1;
