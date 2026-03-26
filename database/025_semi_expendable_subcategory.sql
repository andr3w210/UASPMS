USE spamsdb;

-- Add sub-classification to stock_items to distinguish HV vs LV semi
ALTER TABLE stock_items
  ADD COLUMN semi_expendable_type ENUM('high_value', 'low_value') NULL DEFAULT NULL
  COMMENT 'NULL for supply and equipment. high_value = above 5000, low_value = 5000 and below'
  AFTER item_type;

-- Add sub-classification to distributions
ALTER TABLE distributions
  ADD COLUMN semi_expendable_type ENUM('high_value', 'low_value') NULL DEFAULT NULL
  AFTER document_type;
