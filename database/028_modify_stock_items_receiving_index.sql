-- Migration: allow multiple stock_items per receiving_item by replacing unique index with non-unique
-- Drop existing unique index if present
ALTER TABLE `stock_items` DROP INDEX `uk_stock_items_receiving_item_id`;

-- Add non-unique index for receiving_item_id
ALTER TABLE `stock_items` ADD INDEX `idx_stock_items_receiving_item_id` (`receiving_item_id`);
