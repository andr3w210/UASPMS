CREATE TABLE IF NOT EXISTS asset_photos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_source ENUM('system', 'legacy') NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_photos_asset (asset_source, asset_id),
    INDEX idx_asset_photos_primary (asset_source, asset_id, is_primary),
    INDEX idx_asset_photos_uploaded_by (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
