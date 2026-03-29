ALTER TABLE stock_adjustments
    MODIFY COLUMN status ENUM('pending', 'approved', 'cancelled') NOT NULL DEFAULT 'pending',
    ADD COLUMN approved_by INT UNSIGNED NULL AFTER created_by,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD KEY idx_stock_adjustments_approved_by (approved_by),
    ADD CONSTRAINT fk_stock_adjustments_approved_by
        FOREIGN KEY (approved_by) REFERENCES users(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

UPDATE stock_adjustments
SET status = 'approved'
WHERE status = 'posted';
