-- Add foreign keys for item_name_id after item_names master has been seeded.
-- This migration is idempotent and only adds constraints when safe.

-- Ensure there is an index on rpcppe_batch_items.item_name_id for FK performance.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'rpcppe_batch_items'
      AND index_name = 'idx_rpcppe_batch_items_item_name_id'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE rpcppe_batch_items ADD INDEX idx_rpcppe_batch_items_item_name_id (item_name_id)',
    'SELECT "idx_rpcppe_batch_items_item_name_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- legacy_assets -> item_names
SET @legacy_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'legacy_assets'
      AND constraint_type = 'FOREIGN KEY'
      AND constraint_name = 'fk_legacy_assets_item_name_id'
);
SET @legacy_orphans := (
    SELECT COUNT(*)
    FROM legacy_assets la
    LEFT JOIN item_names i ON i.id = la.item_name_id
    WHERE la.item_name_id IS NOT NULL
      AND i.id IS NULL
);
SET @sql := IF(
    @legacy_fk_exists > 0,
    'SELECT "fk_legacy_assets_item_name_id already exists"',
    IF(
        @legacy_orphans = 0,
        'ALTER TABLE legacy_assets ADD CONSTRAINT fk_legacy_assets_item_name_id FOREIGN KEY (item_name_id) REFERENCES item_names(id) ON UPDATE CASCADE ON DELETE SET NULL',
        'SELECT "Skipped fk_legacy_assets_item_name_id due to orphan rows"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- rpcppe_batch_items -> item_names
SET @batch_fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'rpcppe_batch_items'
      AND constraint_type = 'FOREIGN KEY'
      AND constraint_name = 'fk_rpcppe_batch_items_item_name_id'
);
SET @batch_orphans := (
    SELECT COUNT(*)
    FROM rpcppe_batch_items bi
    LEFT JOIN item_names i ON i.id = bi.item_name_id
    WHERE bi.item_name_id IS NOT NULL
      AND i.id IS NULL
);
SET @sql := IF(
    @batch_fk_exists > 0,
    'SELECT "fk_rpcppe_batch_items_item_name_id already exists"',
    IF(
        @batch_orphans = 0,
        'ALTER TABLE rpcppe_batch_items ADD CONSTRAINT fk_rpcppe_batch_items_item_name_id FOREIGN KEY (item_name_id) REFERENCES item_names(id) ON UPDATE CASCADE ON DELETE SET NULL',
        'SELECT "Skipped fk_rpcppe_batch_items_item_name_id due to orphan rows"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
