CREATE TABLE IF NOT EXISTS office_location_pins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    office_id INT UNSIGNED NOT NULL,
    office_name_snapshot VARCHAR(255) NULL,
    manual_location VARCHAR(255) NULL,
    location_lat DECIMAL(10,7) NOT NULL,
    location_lng DECIMAL(10,7) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_office_location_pins_office (office_id),
    KEY idx_office_location_pins_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
