ALTER TABLE `purchase_orders`
    ADD COLUMN IF NOT EXISTS `is_partial_entry` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Flag: 1 = PO items are still being encoded (partial entry), 0 = fully encoded'
    AFTER `status`;
