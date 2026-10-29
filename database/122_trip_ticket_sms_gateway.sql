USE `uaspms_tripdb`;

CREATE TABLE IF NOT EXISTS `trip_ticket_sms_queue` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `trip_ticket_id` INT(10) UNSIGNED NOT NULL,
  `recipient_phone` VARCHAR(20) NOT NULL,
  `message_body` VARCHAR(459) NOT NULL,
  `status` ENUM('pending', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `lease_token_hash` CHAR(64) DEFAULT NULL,
  `lease_expires_at` DATETIME DEFAULT NULL,
  `result_message` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_trip_ticket_sms_queue_claim` (`status`, `lease_expires_at`, `id`),
  KEY `idx_trip_ticket_sms_queue_ticket` (`trip_ticket_id`),
  CONSTRAINT `fk_trip_ticket_sms_queue_ticket`
    FOREIGN KEY (`trip_ticket_id`) REFERENCES `trip_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
