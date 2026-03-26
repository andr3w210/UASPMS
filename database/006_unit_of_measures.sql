USE `spamsdb`;

CREATE TABLE IF NOT EXISTS `unit_of_measures` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uom_code` VARCHAR(50) NOT NULL,
  `uom_name` VARCHAR(100) NOT NULL,
  `abbreviation` VARCHAR(20) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `updated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unit_of_measures_uom_code` (`uom_code`),
  UNIQUE KEY `uk_unit_of_measures_uom_name` (`uom_name`),
  UNIQUE KEY `uk_unit_of_measures_abbreviation` (`abbreviation`),
  KEY `idx_unit_of_measures_created_by` (`created_by`),
  KEY `idx_unit_of_measures_updated_by` (`updated_by`),
  CONSTRAINT `fk_unit_of_measures_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_unit_of_measures_updated_by`
    FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'unit_of_measures', 'UOM', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'unit_of_measures');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`)
SELECT 'UOM-2026-0001', 'Piece', 'pcs', 'Individual piece count'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pcs');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`)
SELECT 'UOM-2026-0002', 'Unit', 'unit', 'Single equipment or supply unit'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'unit');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`)
SELECT 'UOM-2026-0003', 'Set', 'set', 'Grouped set of components'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'set');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`)
SELECT 'UOM-2026-0004', 'Box', 'box', 'Packaged by box'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'box');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`)
SELECT 'UOM-2026-0005', 'Lot', 'lot', 'Procured as one lot'
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'lot');
