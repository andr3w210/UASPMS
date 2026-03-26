USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `brands` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_code` VARCHAR(30) NOT NULL,
  `brand_name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_brands_brand_code` (`brand_code`),
  UNIQUE KEY `uk_brands_brand_name` (`brand_name`),
  KEY `idx_brands_created_by` (`created_by`),
  KEY `idx_brands_updated_by` (`updated_by`),
  CONSTRAINT `fk_brands_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_brands_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `models` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` BIGINT UNSIGNED NOT NULL,
  `model_code` VARCHAR(30) NOT NULL,
  `model_name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_models_model_code` (`model_code`),
  UNIQUE KEY `uk_models_brand_model_name` (`brand_id`, `model_name`),
  KEY `idx_models_brand_id` (`brand_id`),
  KEY `idx_models_created_by` (`created_by`),
  KEY `idx_models_updated_by` (`updated_by`),
  CONSTRAINT `fk_models_brand_id`
    FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_models_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_models_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @detail_brand_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_item_details'
              AND COLUMN_NAME = 'brand_id'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_item_details` ADD COLUMN `brand_id` BIGINT UNSIGNED DEFAULT NULL AFTER `receiving_item_id`'
    )
);
PREPARE stmt FROM @detail_brand_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @detail_model_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_item_details'
              AND COLUMN_NAME = 'model_id'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_item_details` ADD COLUMN `model_id` BIGINT UNSIGNED DEFAULT NULL AFTER `brand_id`'
    )
);
PREPARE stmt FROM @detail_model_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @detail_brand_fk_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_item_details'
              AND CONSTRAINT_NAME = 'fk_receiving_item_details_brand_id'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_item_details` ADD CONSTRAINT `fk_receiving_item_details_brand_id` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON UPDATE CASCADE ON DELETE SET NULL'
    )
);
PREPARE stmt FROM @detail_brand_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @detail_model_fk_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_item_details'
              AND CONSTRAINT_NAME = 'fk_receiving_item_details_model_id'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_item_details` ADD CONSTRAINT `fk_receiving_item_details_model_id` FOREIGN KEY (`model_id`) REFERENCES `models` (`id`) ON UPDATE CASCADE ON DELETE SET NULL'
    )
);
PREPARE stmt FROM @detail_model_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'brands', 'BRD', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'brands');

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'models', 'MDL', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'models');
