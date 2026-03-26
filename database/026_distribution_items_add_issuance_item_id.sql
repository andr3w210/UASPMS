USE `spamsdb`;

-- Add issuance_item_id to distribution_items so distributions can reference the originating issuance_item
ALTER TABLE `distribution_items`
  ADD COLUMN `issuance_item_id` BIGINT UNSIGNED DEFAULT NULL AFTER `receiving_item_id`,
  ADD KEY `idx_distribution_items_issuance_item_id` (`issuance_item_id`);

ALTER TABLE `distribution_items`
  ADD CONSTRAINT `fk_distribution_items_issuance_item_id`
    FOREIGN KEY (`issuance_item_id`) REFERENCES `issuance_items` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;
