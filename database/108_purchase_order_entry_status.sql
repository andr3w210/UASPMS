ALTER TABLE `purchase_orders`
    ADD COLUMN IF NOT EXISTS `po_entry_status` ENUM('full', 'partial', 'property_items_complete') NOT NULL DEFAULT 'full'
        COMMENT 'Encoding scope: full PO, unfinished partial entry, or property-trackable items complete'
    AFTER `is_partial_entry`;

UPDATE `purchase_orders`
SET `po_entry_status` = CASE
    WHEN `is_partial_entry` = 1 THEN 'partial'
    ELSE 'full'
END
WHERE `po_entry_status` = 'full';

UPDATE `purchase_orders`
SET `po_entry_status` = 'property_items_complete',
    `is_partial_entry` = 1
WHERE `po_number` = 'NO-PO-POREC-2026-0023';
