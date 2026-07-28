ALTER TABLE inventory_count_items
    ADD COLUMN IF NOT EXISTS proof_photo_path VARCHAR(255) NULL AFTER checked_by;
