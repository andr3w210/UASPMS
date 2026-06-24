ALTER TABLE receiving_items
    ADD COLUMN IF NOT EXISTS actual_item_description TEXT NULL AFTER purchase_order_item_id,
    ADD COLUMN IF NOT EXISTS variance_type VARCHAR(40) NOT NULL DEFAULT 'none' AFTER actual_item_description,
    ADD COLUMN IF NOT EXISTS variance_note TEXT NULL AFTER variance_type,
    ADD COLUMN IF NOT EXISTS accepted_no_additional_cost TINYINT(1) NOT NULL DEFAULT 0 AFTER variance_note;
