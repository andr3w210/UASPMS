USE `spamsdb`;

-- Track which specific received unit (by serial) was assigned in each distribution
ALTER TABLE distribution_item_details
  ADD COLUMN receiving_item_detail_id BIGINT UNSIGNED NULL DEFAULT NULL
  AFTER distribution_item_id,
  ADD CONSTRAINT fk_did_receiving_item_detail_id
    FOREIGN KEY (receiving_item_detail_id)
    REFERENCES receiving_item_details (id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- Flag a received unit as already distributed so it won't appear again
ALTER TABLE receiving_item_details
  ADD COLUMN is_distributed TINYINT(1) NOT NULL DEFAULT 0
  AFTER remarks;
