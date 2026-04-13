USE `spamsdb`;

-- Water Supply Systems from uploaded WATER sheet
-- account_code_id = 41 (1.06.03.040.00)
-- Keep blank property numbers as-is for now; we will normalize later.

-- Update existing by property number (non-blank rows)
UPDATE `legacy_assets`
SET `item_description` = 'Realignment of Waterline System',
    `account_code_id` = 41,
    `fund_id` = 4,
    `unit_cost` = 984618.47,
    `acquisition_cost` = 984618.47,
    `is_active` = 1
WHERE `property_number` = '2015-05-03.040-001';

UPDATE `legacy_assets`
SET `item_description` = 'Construction of Water Tank and Looping of Existing Waterline in the University',
    `account_code_id` = 41,
    `fund_id` = 4,
    `unit_cost` = 991658.40,
    `acquisition_cost` = 991658.40,
    `is_active` = 1
WHERE `property_number` = '2023-05-03.040-002';

UPDATE `legacy_assets`
SET `item_description` = 'Repair of water systen',
    `account_code_id` = 41,
    `fund_id` = 4,
    `unit_cost` = 249854.74,
    `acquisition_cost` = 249854.74,
    `is_active` = 1
WHERE `property_number` = '2023-05-03.040-003';

-- Update blank-property-number row by description
UPDATE `legacy_assets`
SET `account_code_id` = 41,
    `fund_id` = 3,
    `unit_cost` = 559659.66,
    `acquisition_cost` = 559659.66,
    `is_active` = 1
WHERE `item_description` = 'Construction of Water System of UA-Caluya Extension'
  AND `account_code_id` = 41;

-- Insert if missing
INSERT INTO `legacy_assets`
    (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '2015-05-03.040-001', 'equipment', 'Realignment of Waterline System', 41, 4, 984618.47, 984618.47, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `legacy_assets` WHERE `property_number` = '2015-05-03.040-001'
);

INSERT INTO `legacy_assets`
    (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '2023-05-03.040-002', 'equipment', 'Construction of Water Tank and Looping of Existing Waterline in the University', 41, 4, 991658.40, 991658.40, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `legacy_assets` WHERE `property_number` = '2023-05-03.040-002'
);

INSERT INTO `legacy_assets`
    (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '2023-05-03.040-003', 'equipment', 'Repair of water systen', 41, 4, 249854.74, 249854.74, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `legacy_assets` WHERE `property_number` = '2023-05-03.040-003'
);

INSERT INTO `legacy_assets`
    (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Construction of Water System of UA-Caluya Extension', 41, 3, 559659.66, 559659.66, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `legacy_assets`
    WHERE `item_description` = 'Construction of Water System of UA-Caluya Extension'
      AND `account_code_id` = 41
);
