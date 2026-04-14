USE `spamsdb`;

ALTER TABLE `legacy_assets`
    ADD COLUMN IF NOT EXISTS `item_name` VARCHAR(255) NULL AFTER `item_description`;

ALTER TABLE `rpcppe_batch_items`
    ADD COLUMN IF NOT EXISTS `item_name` VARCHAR(255) NULL AFTER `property_number`;

UPDATE `legacy_assets` la
LEFT JOIN `classifications` c ON c.id = la.classification_id
SET la.item_name = TRIM(c.classification_name)
WHERE (la.item_name IS NULL OR la.item_name = '')
  AND c.classification_name IS NOT NULL
  AND TRIM(c.classification_name) <> '';

UPDATE `rpcppe_batch_items`
SET item_name = TRIM(classification_name)
WHERE (item_name IS NULL OR item_name = '')
  AND classification_name IS NOT NULL
  AND TRIM(classification_name) <> '';
