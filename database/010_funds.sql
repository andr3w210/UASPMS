USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `funds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fund_code` VARCHAR(50) NOT NULL,
  `fund_name` VARCHAR(150) NOT NULL,
  `fund_source` VARCHAR(150) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_funds_fund_code` (`fund_code`),
  UNIQUE KEY `uk_funds_fund_name` (`fund_name`),
  KEY `idx_funds_created_by` (`created_by`),
  KEY `idx_funds_updated_by` (`updated_by`),
  CONSTRAINT `fk_funds_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_funds_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'funds', 'FND', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'funds');

INSERT INTO `funds` (`fund_code`, `fund_name`, `fund_source`, `description`)
SELECT 'FND-2026-0001', 'General Fund', 'Government Appropriations', 'Default general fund for regular procurement'
WHERE NOT EXISTS (SELECT 1 FROM `funds` WHERE `fund_name` = 'General Fund');
