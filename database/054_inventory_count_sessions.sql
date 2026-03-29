CREATE TABLE IF NOT EXISTS inventory_count_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    count_type ENUM('annual', 'surprise') NOT NULL DEFAULT 'annual',
    office_id INT UNSIGNED NOT NULL,
    count_date DATE NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    closed_by INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_count_sessions_office_status (office_id, status),
    INDEX idx_inventory_count_sessions_count_date (count_date),
    CONSTRAINT fk_inventory_count_sessions_office
        FOREIGN KEY (office_id) REFERENCES offices(id)
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_sessions_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_sessions_closed_by
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_count_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    source_type ENUM('system', 'legacy') NOT NULL,
    distribution_item_detail_id INT UNSIGNED NULL,
    legacy_asset_id BIGINT UNSIGNED NULL,
    property_number VARCHAR(120) NOT NULL,
    item_type ENUM('equipment', 'semi_expendable') NOT NULL,
    office_id INT UNSIGNED NULL,
    employee_id INT UNSIGNED NULL,
    classification_name VARCHAR(255) NULL,
    item_description VARCHAR(255) NOT NULL,
    brand VARCHAR(120) NULL,
    model VARCHAR(120) NULL,
    serial_no VARCHAR(120) NULL,
    accountable_name VARCHAR(255) NULL,
    status ENUM('pending', 'found', 'missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable') NOT NULL DEFAULT 'pending',
    remarks TEXT NULL,
    checked_at DATETIME NULL,
    checked_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inventory_count_items_session_status (session_id, status),
    INDEX idx_inventory_count_items_property (property_number),
    INDEX idx_inventory_count_items_system_asset (distribution_item_detail_id),
    INDEX idx_inventory_count_items_legacy_asset (legacy_asset_id),
    CONSTRAINT fk_inventory_count_items_session
        FOREIGN KEY (session_id) REFERENCES inventory_count_sessions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_items_distribution_detail
        FOREIGN KEY (distribution_item_detail_id) REFERENCES distribution_item_details(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_items_legacy_asset
        FOREIGN KEY (legacy_asset_id) REFERENCES legacy_assets(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_items_office
        FOREIGN KEY (office_id) REFERENCES offices(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_items_employee
        FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_inventory_count_items_checked_by
        FOREIGN KEY (checked_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT uq_inventory_count_system_item
        UNIQUE KEY (session_id, distribution_item_detail_id),
    CONSTRAINT uq_inventory_count_legacy_item
        UNIQUE KEY (session_id, legacy_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
