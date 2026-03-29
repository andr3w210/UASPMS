CREATE TABLE IF NOT EXISTS stock_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    supply_count_session_id INT UNSIGNED NULL,
    adjustment_date DATE NOT NULL,
    status ENUM('posted', 'cancelled') NOT NULL DEFAULT 'posted',
    remarks TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_stock_adjustments_session (supply_count_session_id),
    KEY idx_stock_adjustments_status_date (status, adjustment_date),
    CONSTRAINT fk_stock_adjustments_session
        FOREIGN KEY (supply_count_session_id) REFERENCES supply_count_sessions(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_stock_adjustments_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_adjustment_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stock_adjustment_id INT UNSIGNED NOT NULL,
    supply_count_item_id INT UNSIGNED NULL,
    stock_item_id INT UNSIGNED NOT NULL,
    system_quantity DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    counted_quantity DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    variance_quantity DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    adjustment_type ENUM('increase', 'decrease') NOT NULL,
    remarks TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_stock_adjustment_items_header (stock_adjustment_id),
    KEY idx_stock_adjustment_items_stock_item (stock_item_id),
    CONSTRAINT fk_stock_adjustment_items_header
        FOREIGN KEY (stock_adjustment_id) REFERENCES stock_adjustments(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_stock_adjustment_items_count_item
        FOREIGN KEY (supply_count_item_id) REFERENCES supply_count_items(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_stock_adjustment_items_stock_item
        FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
