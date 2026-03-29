CREATE TABLE IF NOT EXISTS supply_count_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    count_type ENUM('annual', 'surprise') NOT NULL DEFAULT 'annual',
    count_date DATE NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    closed_by INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_supply_count_sessions_status (status),
    INDEX idx_supply_count_sessions_count_date (count_date),
    CONSTRAINT fk_supply_count_sessions_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_supply_count_sessions_closed_by
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supply_count_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    stock_item_id INT UNSIGNED NOT NULL,
    stock_catalog_id INT UNSIGNED NULL,
    stock_reference VARCHAR(50) NOT NULL,
    stock_no VARCHAR(120) NULL,
    barcode VARCHAR(120) NULL,
    item_description VARCHAR(255) NOT NULL,
    classification_name VARCHAR(255) NULL,
    account_code VARCHAR(50) NULL,
    unit_of_measure VARCHAR(100) NULL,
    system_quantity DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    counted_quantity DECIMAL(15,2) NULL,
    variance_quantity DECIMAL(15,2) NULL,
    count_status ENUM('pending', 'match', 'shortage', 'overage', 'not_counted') NOT NULL DEFAULT 'pending',
    remarks TEXT NULL,
    checked_at DATETIME NULL,
    checked_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supply_count_session_stock_item (session_id, stock_item_id),
    KEY idx_supply_count_items_session_status (session_id, count_status),
    KEY idx_supply_count_items_barcode (barcode),
    KEY idx_supply_count_items_stock_no (stock_no),
    CONSTRAINT fk_supply_count_items_session
        FOREIGN KEY (session_id) REFERENCES supply_count_sessions(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_supply_count_items_stock_item
        FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_supply_count_items_stock_catalog
        FOREIGN KEY (stock_catalog_id) REFERENCES stock_catalog(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_supply_count_items_checked_by
        FOREIGN KEY (checked_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
