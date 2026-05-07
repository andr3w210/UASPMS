ALTER TABLE distribution_item_details
    ADD COLUMN IF NOT EXISTS manual_location VARCHAR(255) NULL AFTER current_responsibility_code_id,
    ADD COLUMN IF NOT EXISTS location_lat DECIMAL(10,7) NULL AFTER manual_location,
    ADD COLUMN IF NOT EXISTS location_lng DECIMAL(10,7) NULL AFTER location_lat,
    ADD COLUMN IF NOT EXISTS location_updated_at DATETIME NULL AFTER location_lng,
    ADD COLUMN IF NOT EXISTS location_updated_by BIGINT UNSIGNED NULL AFTER location_updated_at;

ALTER TABLE legacy_assets
    ADD COLUMN IF NOT EXISTS manual_location VARCHAR(255) NULL AFTER responsibility_code_id,
    ADD COLUMN IF NOT EXISTS location_lat DECIMAL(10,7) NULL AFTER manual_location,
    ADD COLUMN IF NOT EXISTS location_lng DECIMAL(10,7) NULL AFTER location_lat,
    ADD COLUMN IF NOT EXISTS location_updated_at DATETIME NULL AFTER location_lng,
    ADD COLUMN IF NOT EXISTS location_updated_by BIGINT UNSIGNED NULL AFTER location_updated_at;

CREATE TABLE IF NOT EXISTS asset_location_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_source ENUM('system', 'legacy') NOT NULL,
    asset_id BIGINT UNSIGNED NOT NULL,
    inventory_session_id INT UNSIGNED NULL,
    inventory_count_item_id INT UNSIGNED NULL,
    changed_by BIGINT UNSIGNED NULL,
    change_reason VARCHAR(120) NOT NULL DEFAULT 'manual_update',
    old_manual_location VARCHAR(255) NULL,
    old_latitude DECIMAL(10,7) NULL,
    old_longitude DECIMAL(10,7) NULL,
    new_manual_location VARCHAR(255) NULL,
    new_latitude DECIMAL(10,7) NULL,
    new_longitude DECIMAL(10,7) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_asset_location_history_asset (asset_source, asset_id),
    KEY idx_asset_location_history_session (inventory_session_id),
    KEY idx_asset_location_history_item (inventory_count_item_id),
    KEY idx_asset_location_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
