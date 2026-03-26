ALTER TABLE purchase_orders
    ADD COLUMN IF NOT EXISTS expected_delivery_date DATE NULL DEFAULT NULL
        AFTER delivery_term_days;