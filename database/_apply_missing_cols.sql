-- Temporary: add missing columns that failed to apply
ALTER TABLE mode_of_procurements
    ADD COLUMN mode_code VARCHAR(50) NULL AFTER id;

UPDATE mode_of_procurements SET mode_code = CONCAT('MOP-', YEAR(CURDATE()), '-', LPAD(id,4,'0'))
WHERE mode_code IS NULL OR mode_code = '';

ALTER TABLE distribution_item_details
    ADD COLUMN is_disposed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_distributed;
