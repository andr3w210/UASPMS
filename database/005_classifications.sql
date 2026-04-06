USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `classifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `classification_code` VARCHAR(50) NOT NULL,
  `classification_name` VARCHAR(150) NOT NULL,
  `classification_group` ENUM('supply', 'asset', 'semi_expendable') NOT NULL DEFAULT 'asset',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_classifications_classification_code` (`classification_code`),
  UNIQUE KEY `uk_classifications_group_name` (`classification_group`, `classification_name`),
  KEY `idx_classifications_created_by` (`created_by`),
  KEY `idx_classifications_updated_by` (`updated_by`),
  CONSTRAINT `fk_classifications_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_classifications_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'classifications', 'CLS', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'classifications');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `description`)
SELECT 'CLS-2026-0001', 'Desktop Computer', 'asset', 'Standard desktop workstation'
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Desktop Computer');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `description`)
SELECT 'CLS-2026-0002', 'Projector', 'asset', 'Projection equipment for meetings and classrooms'
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Projector');
