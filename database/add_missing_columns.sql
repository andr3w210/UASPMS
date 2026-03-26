-- Add missing columns to classifications
ALTER TABLE classifications
    ADD COLUMN IF NOT EXISTS classification_group VARCHAR(100) NULL DEFAULT NULL
        AFTER classification_name,
    ADD COLUMN IF NOT EXISTS useful_life_years TINYINT UNSIGNED NULL DEFAULT NULL
        AFTER classification_group,
    ADD COLUMN IF NOT EXISTS account_code_id BIGINT UNSIGNED NULL DEFAULT NULL
        AFTER useful_life_years,
    ADD COLUMN IF NOT EXISTS system_reference VARCHAR(50) NULL DEFAULT NULL
        AFTER id;

-- Add missing columns to receiving_item_details
ALTER TABLE receiving_item_details
    ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL DEFAULT NULL
        AFTER receiving_item_id;

-- Add missing columns to stock_items
ALTER TABLE stock_items
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER item_type;

-- Drop unique key on stock_items.receiving_item_id (one stock per unit now)
ALTER TABLE stock_items
    DROP INDEX IF EXISTS uk_stock_items_receiving_item_id;

-- Add missing columns to distribution_item_details
ALTER TABLE distribution_item_details
    ADD COLUMN IF NOT EXISTS property_number VARCHAR(100) NULL DEFAULT NULL
        AFTER serial_no,
    ADD COLUMN IF NOT EXISTS is_distributed TINYINT(1) NOT NULL DEFAULT 1
        AFTER property_number,
    ADD COLUMN IF NOT EXISTS is_disposed TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_distributed;

-- Add missing columns to distribution_items
ALTER TABLE distribution_items
    ADD COLUMN IF NOT EXISTS property_number VARCHAR(100) NULL DEFAULT NULL
        AFTER line_total,
    ADD COLUMN IF NOT EXISTS is_disposed TINYINT(1) NOT NULL DEFAULT 0
        AFTER property_number;

-- Add missing columns to distributions
ALTER TABLE distributions
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER document_type;

-- Fix PO status enum to include partial
ALTER TABLE purchase_orders
    MODIFY COLUMN status
        ENUM('encoded','partial','completed','cancelled')
        NOT NULL DEFAULT 'encoded';

-- Add property_thresholds table if missing
CREATE TABLE IF NOT EXISTS property_thresholds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_min DECIMAL(15,2) NOT NULL DEFAULT 50000.00,
    semi_hv_min DECIMAL(15,2) NOT NULL DEFAULT 5000.01,
    effective_date DATE NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO property_thresholds
    (equipment_min, semi_hv_min, effective_date)
VALUES
    (50000.00, 5000.01, '2022-01-01');

-- Add disposals table if missing
CREATE TABLE IF NOT EXISTS disposals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    disposal_date DATE NOT NULL,
    distribution_item_detail_id BIGINT UNSIGNED NOT NULL,
    disposal_reason ENUM('unserviceable','obsolete','lost','beyond_repair','other')
        NOT NULL DEFAULT 'unserviceable',
    approved_by BIGINT UNSIGNED NULL,
    remarks TEXT NULL,
    status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add returns table if missing
CREATE TABLE IF NOT EXISTS returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    return_date DATE NOT NULL,
    distribution_item_detail_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NULL,
    remarks TEXT NULL,
    status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add missing series numbers
INSERT IGNORE INTO series_numbers
    (module_key, prefix, year_value, current_value, padding_length)
VALUES
    ('disposals', 'DIS', YEAR(NOW()), 0, 4),
    ('returns',   'RET', YEAR(NOW()), 0, 4);

-- Update classifications with system_reference if missing
UPDATE classifications
SET system_reference = CONCAT('CLS-2026-', LPAD(id, 4, '0'))
WHERE system_reference IS NULL OR system_reference = '';

-- Update classifications group based on name
UPDATE classifications SET classification_group = 'supply'
WHERE (classification_name LIKE '%Supply%'
   OR classification_name LIKE '%Paper%'
   OR classification_name LIKE '%Ink%'
   OR classification_name LIKE '%Cleaning%')
AND classification_group IS NULL;

UPDATE classifications SET classification_group = 'semi_expendable'
WHERE classification_name LIKE '%Semi%'
AND classification_group IS NULL;

UPDATE classifications SET classification_group = 'asset'
WHERE classification_group IS NULL;

-- Add missing columns to purchase_orders
ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS expected_delivery_date DATE NULL DEFAULT NULL
        AFTER delivery_term_days,
    ADD COLUMN IF NOT EXISTS delivery_term_days INT NULL DEFAULT NULL
        AFTER place_of_delivery,
    ADD COLUMN IF NOT EXISTS place_of_delivery VARCHAR(200) NULL DEFAULT NULL
        AFTER supplier_address,
    ADD COLUMN IF NOT EXISTS supplier_address TEXT NULL DEFAULT NULL
        AFTER supplier_id,
    ADD COLUMN IF NOT EXISTS mode_of_procurement_id BIGINT UNSIGNED NULL DEFAULT NULL
        AFTER fund_id,
    ADD COLUMN IF NOT EXISTS purpose TEXT NULL DEFAULT NULL
        AFTER expected_delivery_date,
    ADD COLUMN IF NOT EXISTS remarks TEXT NULL DEFAULT NULL
        AFTER purpose,
    ADD COLUMN IF NOT EXISTS updated_by BIGINT UNSIGNED NULL DEFAULT NULL
        AFTER created_by,
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL DEFAULT NULL
        ON UPDATE CURRENT_TIMESTAMP AFTER updated_by;
