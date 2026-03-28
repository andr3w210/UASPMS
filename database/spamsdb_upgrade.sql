-- spamsdb_upgrade.sql
-- Run on top of the backup spamsdb-before-thresholds.sql to bring DB up to date
-- Generated: 2026-03-25

SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- STEP 1: ADD MISSING COLUMNS TO EXISTING TABLES
-- =====================================================

-- 1a. classifications — add system_reference and useful_life_years
ALTER TABLE classifications
    ADD COLUMN IF NOT EXISTS system_reference VARCHAR(50) NULL AFTER id,
    ADD COLUMN IF NOT EXISTS useful_life_years TINYINT UNSIGNED NULL DEFAULT NULL
        AFTER classification_name;

-- 1a.1 Add classification_code if missing (used by older code paths)
ALTER TABLE classifications
    ADD COLUMN IF NOT EXISTS classification_code VARCHAR(50) NULL AFTER id;

-- Update existing classifications with system_reference if null
UPDATE classifications SET system_reference = CONCAT('CLS-2026-', LPAD(id, 4, '0'))
WHERE system_reference IS NULL OR system_reference = '';

-- 1b. receiving_item_details — add stock_item_id and FK
ALTER TABLE receiving_item_details
    ADD COLUMN IF NOT EXISTS stock_item_id BIGINT UNSIGNED NULL DEFAULT NULL
        AFTER receiving_item_id,
    ADD INDEX IF NOT EXISTS idx_rid_stock_item_id (stock_item_id);

-- Add foreign key (use plain ALTER; IF NOT EXISTS is not supported on all MySQL/MariaDB)
ALTER TABLE receiving_item_details
    ADD CONSTRAINT fk_rid_stock_item_id
        FOREIGN KEY (stock_item_id) REFERENCES stock_items(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- 1c. stock_items — drop unique key and add semi_expendable_type
-- Drop the unique key on receiving_item_id (if present)
ALTER TABLE stock_items
    DROP INDEX IF EXISTS uk_stock_items_receiving_item_id;

ALTER TABLE stock_items
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER item_type;

-- 1d. distribution_item_details — add property_number, is_distributed, is_disposed
ALTER TABLE distribution_item_details
    ADD COLUMN IF NOT EXISTS property_number VARCHAR(100) NULL DEFAULT NULL
        AFTER serial_no,
    ADD COLUMN IF NOT EXISTS is_distributed TINYINT(1) NOT NULL DEFAULT 1
        AFTER property_number,
    ADD COLUMN IF NOT EXISTS is_disposed TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_distributed;

-- 1e. distribution_items — add property_number, is_disposed
ALTER TABLE distribution_items
    ADD COLUMN IF NOT EXISTS property_number VARCHAR(100) NULL DEFAULT NULL
        AFTER line_total,
    ADD COLUMN IF NOT EXISTS is_disposed TINYINT(1) NOT NULL DEFAULT 0
        AFTER property_number;

-- 1f. distributions — add semi_expendable_type
ALTER TABLE distributions
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER document_type;

-- 1g. receivings — add ris_no
ALTER TABLE receivings
    ADD COLUMN IF NOT EXISTS ris_no VARCHAR(50) NULL DEFAULT NULL
        AFTER invoice_no;

-- 1h. Fix PO status to include partial
ALTER TABLE purchase_orders
    MODIFY COLUMN status
        ENUM('encoded','partial','completed','cancelled')
        NOT NULL DEFAULT 'encoded';

-- 1i. purchase_order_items - add semi_expendable_type
ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER item_type;


-- =====================================================
-- STEP 2: CREATE NEW TABLES
-- =====================================================

-- 2a. property_thresholds
CREATE TABLE IF NOT EXISTS property_thresholds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    equipment_min DECIMAL(15,2) NOT NULL DEFAULT 50000.00,
    semi_hv_min DECIMAL(15,2) NOT NULL DEFAULT 5000.01,
    effective_date DATE NOT NULL,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pt_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default threshold (COA 2022-004)
INSERT IGNORE INTO property_thresholds (equipment_min, semi_hv_min, effective_date, notes)
VALUES (50000.00, 5000.01, '2022-01-01', 'COA Circular 2022-004');

-- 2b. disposals
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disposals_did FOREIGN KEY (distribution_item_detail_id)
        REFERENCES distribution_item_details(id) ON UPDATE CASCADE,
    CONSTRAINT fk_disposals_approved_by FOREIGN KEY (approved_by)
        REFERENCES employees(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_disposals_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2c. returns
CREATE TABLE IF NOT EXISTS returns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    system_reference VARCHAR(50) NOT NULL UNIQUE,
    return_date DATE NOT NULL,
    distribution_item_detail_id BIGINT UNSIGNED NOT NULL,
    reason TEXT NULL,
    remarks TEXT NULL,
    status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_returns_did FOREIGN KEY (distribution_item_detail_id)
        REFERENCES distribution_item_details(id) ON UPDATE CASCADE,
    CONSTRAINT fk_returns_created_by FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- STEP 3: ADD MISSING SERIES NUMBERS
-- =====================================================
INSERT IGNORE INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
VALUES
('disposals', 'DIS', YEAR(NOW()), 0, 4),
('returns',   'RET', YEAR(NOW()), 0, 4);


-- =====================================================
-- STEP 4: FIX EXISTING DATA
-- =====================================================

-- Fix POs that have blank status
UPDATE purchase_orders SET status = 'encoded'
WHERE status IS NULL OR status = '';

-- Fix POs that have receiving records but still show encoded -> partial
UPDATE purchase_orders po
SET po.status = 'partial'
WHERE EXISTS (
    SELECT 1 FROM receivings r
    WHERE r.purchase_order_id = po.id
    AND r.status != 'cancelled'
) AND po.status = 'encoded';

-- Fix POs that are fully received to completed
UPDATE purchase_orders po
SET po.status = 'completed'
WHERE (
    SELECT SUM(poi.quantity)
    FROM purchase_order_items poi
    WHERE poi.purchase_order_id = po.id
) <= (
    SELECT COALESCE(SUM(ri.quantity_delivered), 0)
    FROM receiving_items ri
    INNER JOIN receivings r ON r.id = ri.receiving_id
    WHERE r.purchase_order_id = po.id
    AND r.status != 'cancelled'
) AND po.status = 'partial';

SOURCE 038_stock_catalog_stock_no_reformat.sql;
SOURCE 039_classification_family.sql;
SOURCE 040_stock_catalog_stock_no_from_classification_family.sql;

SET FOREIGN_KEY_CHECKS = 1;
