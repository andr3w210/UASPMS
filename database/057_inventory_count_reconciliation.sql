ALTER TABLE inventory_count_items
    ADD COLUMN resolution_status ENUM('unresolved', 'resolved') NOT NULL DEFAULT 'unresolved' AFTER remarks,
    ADD COLUMN resolution_action VARCHAR(50) NULL AFTER resolution_status,
    ADD COLUMN resolution_notes TEXT NULL AFTER resolution_action,
    ADD COLUMN resolved_at DATETIME NULL AFTER resolution_notes,
    ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
    ADD KEY idx_inventory_count_items_resolution (session_id, resolution_status),
    ADD CONSTRAINT fk_inventory_count_items_resolved_by
        FOREIGN KEY (resolved_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

UPDATE inventory_count_items
SET resolution_status = 'resolved'
WHERE status = 'found';
