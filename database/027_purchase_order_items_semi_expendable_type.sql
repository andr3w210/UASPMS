ALTER TABLE purchase_order_items
    ADD COLUMN IF NOT EXISTS semi_expendable_type
        ENUM('high_value','low_value') NULL DEFAULT NULL
        AFTER item_type;
