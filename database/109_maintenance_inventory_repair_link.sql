USE `spamsdb`;

ALTER TABLE `maintenance_logs`
    ADD COLUMN IF NOT EXISTS `inventory_count_item_id` INT UNSIGNED NULL AFTER `distribution_item_detail_id`,
    ADD COLUMN IF NOT EXISTS `completed_at` DATETIME NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `completed_by` BIGINT UNSIGNED NULL AFTER `completed_at`,
    ADD INDEX IF NOT EXISTS `idx_maintenance_logs_inventory_count_item_id` (`inventory_count_item_id`),
    ADD INDEX IF NOT EXISTS `idx_maintenance_logs_completed_at` (`completed_at`);

SET @maintenance_inv_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'maintenance_logs'
      AND CONSTRAINT_NAME = 'fk_maintenance_logs_inventory_count_item'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @maintenance_inv_fk_sql = IF(
    @maintenance_inv_fk_exists = 0,
    'ALTER TABLE `maintenance_logs`
        ADD CONSTRAINT `fk_maintenance_logs_inventory_count_item`
        FOREIGN KEY (`inventory_count_item_id`)
        REFERENCES `inventory_count_items` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE maintenance_inv_fk_stmt FROM @maintenance_inv_fk_sql;
EXECUTE maintenance_inv_fk_stmt;
DEALLOCATE PREPARE maintenance_inv_fk_stmt;

SET @maintenance_completed_by_fk_exists = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'maintenance_logs'
      AND CONSTRAINT_NAME = 'fk_maintenance_logs_completed_by'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @maintenance_completed_by_fk_sql = IF(
    @maintenance_completed_by_fk_exists = 0,
    'ALTER TABLE `maintenance_logs`
        ADD CONSTRAINT `fk_maintenance_logs_completed_by`
        FOREIGN KEY (`completed_by`)
        REFERENCES `users` (`id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE maintenance_completed_by_fk_stmt FROM @maintenance_completed_by_fk_sql;
EXECUTE maintenance_completed_by_fk_stmt;
DEALLOCATE PREPARE maintenance_completed_by_fk_stmt;