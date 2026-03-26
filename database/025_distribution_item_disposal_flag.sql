-- 025_distribution_item_disposal_flag.sql
-- Add disposal flag to distribution_items
ALTER TABLE distribution_items
ADD COLUMN is_disposed TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN disposed_at DATETIME NULL;
