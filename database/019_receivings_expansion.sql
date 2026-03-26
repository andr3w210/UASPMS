USE `spamsdb`;

SET @receivings_status_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receivings'
              AND COLUMN_NAME = 'status'
              AND COLUMN_TYPE NOT LIKE '%draft%'
        ),
        'ALTER TABLE `receivings` MODIFY COLUMN `status` ENUM(''encoded'', ''posted'', ''draft'', ''partial'', ''completed'', ''cancelled'') NOT NULL DEFAULT ''draft''',
        'SELECT 1'
    )
);
PREPARE stmt FROM @receivings_status_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @receivings_ris_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receivings'
              AND COLUMN_NAME = 'ris_no'
        ),
        'SELECT 1',
        'ALTER TABLE `receivings` ADD COLUMN `ris_no` VARCHAR(100) DEFAULT NULL AFTER `purchase_order_id`'
    )
);
PREPARE stmt FROM @receivings_ris_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `receivings`
SET `status` = CASE
    WHEN `status` = 'posted' THEN 'completed'
    WHEN `status` = 'cancelled' THEN 'cancelled'
    WHEN `status` = 'encoded' THEN 'partial'
    ELSE `status`
END;

ALTER TABLE `receivings`
MODIFY COLUMN `status` ENUM('draft', 'partial', 'completed', 'cancelled') NOT NULL DEFAULT 'draft';

SET @receiving_items_delivered_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_items'
              AND COLUMN_NAME = 'quantity_delivered'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_items` CHANGE COLUMN `quantity_received` `quantity_delivered` DECIMAL(14,2) NOT NULL DEFAULT 0.00'
    )
);
PREPARE stmt FROM @receiving_items_delivered_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @receiving_items_accepted_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_items'
              AND COLUMN_NAME = 'quantity_accepted'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_items` ADD COLUMN `quantity_accepted` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `quantity_delivered`'
    )
);
PREPARE stmt FROM @receiving_items_accepted_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @receiving_items_rejected_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_items'
              AND COLUMN_NAME = 'quantity_rejected'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_items` ADD COLUMN `quantity_rejected` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `quantity_accepted`'
    )
);
PREPARE stmt FROM @receiving_items_rejected_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @receiving_items_condition_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'receiving_items'
              AND COLUMN_NAME = 'item_condition'
        ),
        'SELECT 1',
        'ALTER TABLE `receiving_items` ADD COLUMN `item_condition` VARCHAR(100) DEFAULT NULL AFTER `quantity_rejected`'
    )
);
PREPARE stmt FROM @receiving_items_condition_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE `receiving_items`
MODIFY COLUMN `remarks` TEXT DEFAULT NULL;

UPDATE `receiving_items`
SET `quantity_accepted` = CASE
        WHEN `quantity_accepted` = 0 THEN `quantity_delivered`
        ELSE `quantity_accepted`
    END,
    `quantity_rejected` = COALESCE(`quantity_rejected`, 0.00),
    `item_condition` = COALESCE(NULLIF(`item_condition`, ''), 'Good Condition');

CREATE TABLE IF NOT EXISTS `receiving_item_details` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiving_item_id` BIGINT UNSIGNED NOT NULL,
  `brand` VARCHAR(150) DEFAULT NULL,
  `model` VARCHAR(150) DEFAULT NULL,
  `serial_no` VARCHAR(150) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_receiving_item_details_receiving_item_id` (`receiving_item_id`),
  CONSTRAINT `fk_receiving_item_details_receiving_item_id`
    FOREIGN KEY (`receiving_item_id`) REFERENCES `receiving_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
