CREATE TABLE IF NOT EXISTS trip_fuel_ris_entries (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    ris_date DATE NOT NULL,
    ris_no VARCHAR(60) NOT NULL,
    station_name VARCHAR(200) DEFAULT NULL,
    vehicle_id INT(10) UNSIGNED DEFAULT NULL,
    vehicle_plate_no VARCHAR(50) DEFAULT NULL,
    vehicle_name VARCHAR(150) DEFAULT NULL,
    fuel_type VARCHAR(30) NOT NULL DEFAULT 'Diesel',
    liters_purchased DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    liters_consumed DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount DECIMAL(12,2) DEFAULT NULL,
    odometer_reading DECIMAL(12,2) DEFAULT NULL,
    remarks TEXT DEFAULT NULL,
    source_tag VARCHAR(30) NOT NULL DEFAULT 'manual',
    created_by INT(10) UNSIGNED DEFAULT NULL,
    updated_by INT(10) UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trip_fuel_ris_entries_date (ris_date),
    KEY idx_trip_fuel_ris_entries_vehicle (vehicle_id),
    KEY idx_trip_fuel_ris_entries_ris_no (ris_no),
    CONSTRAINT fk_trip_fuel_ris_entries_vehicle_id
        FOREIGN KEY (vehicle_id) REFERENCES trip_vehicles (id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
