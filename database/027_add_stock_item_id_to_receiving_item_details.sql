-- Migration: add stock_item_id to receiving_item_details
ALTER TABLE `receiving_item_details`
  ADD COLUMN `stock_item_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `receiving_item_id`;

-- Add index and foreign key (if stock_items exists)
ALTER TABLE `receiving_item_details`
  ADD KEY `idx_receiving_item_details_stock_item_id` (`stock_item_id`);

ALTER TABLE `receiving_item_details`
  ADD CONSTRAINT `fk_receiving_item_details_stock_item_id`
    FOREIGN KEY (`stock_item_id`) REFERENCES `stock_items`(`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;
