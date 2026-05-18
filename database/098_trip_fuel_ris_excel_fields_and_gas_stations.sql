CREATE TABLE IF NOT EXISTS trip_gas_stations (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    station_name VARCHAR(200) NOT NULL,
    station_address VARCHAR(255) DEFAULT NULL,
    contact_no VARCHAR(60) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT(10) UNSIGNED DEFAULT NULL,
    updated_by INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_trip_gas_stations_station_name (station_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE trip_fuel_ris_entries
    ADD COLUMN gas_station_id INT(10) UNSIGNED NULL AFTER station_name,
    ADD COLUMN quantity DECIMAL(10,2) NULL AFTER fuel_type,
    ADD COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'Liter' AFTER quantity,
    ADD COLUMN purpose TEXT NULL AFTER unit,
    ADD COLUMN driver_name VARCHAR(200) NULL AFTER purpose,
    ADD KEY idx_trip_fuel_ris_entries_gas_station_id (gas_station_id),
    ADD CONSTRAINT fk_trip_fuel_ris_entries_gas_station_id
        FOREIGN KEY (gas_station_id) REFERENCES trip_gas_stations (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL;

UPDATE trip_fuel_ris_entries
SET quantity = COALESCE(NULLIF(quantity, 0), liters_purchased, liters_consumed)
WHERE quantity IS NULL;

UPDATE trip_fuel_ris_entries
SET unit = 'Liter'
WHERE unit IS NULL OR TRIM(unit) = '';
