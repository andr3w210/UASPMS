-- RPCPPE 2025 Land assets upsert
-- account_code_id = 34 (Land, 1.06.01.010.00)
-- fund_id 3 = fund_source 01 (GAA-GAS), fund_id 4 = fund_source 05 (TFCF)
-- Strategy: UPDATE if property_number matches; otherwise INSERT if not exists.

USE `spamsdb`;

-- ─────────────────────────────────────────────
-- UPDATE existing rows (matched by property_number)
-- ─────────────────────────────────────────────
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4808 Lot No. 683 - 272 sq. meters @ P0.28/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 111520.00,   `acquisition_cost` = 111520.00,   `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-001';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4809 Lot No. 690 - 225 sq. meters @ P0.19/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 81000.00,    `acquisition_cost` = 81000.00,    `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-002';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4810 Lot No. 684 - 284 sq. meters @ P0.20',       `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 116440.00,   `acquisition_cost` = 116440.00,   `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-003';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4811 Lot No. 675 - 29,544 sq. meters @ P0.04',    `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 3610749.20, `acquisition_cost` = 3610749.20, `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-004';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4812 Lot No. 691 - 1767 sq. meters @ P0.20/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 636120.00,  `acquisition_cost` = 636120.00,  `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-005';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4813 Lot No. 680 - 1554 sq. meters @ P0.14/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 637140.00,  `acquisition_cost` = 637140.00,  `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-006';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4814 Lot No. 679 - 42,685 sq. meters @ P0.12/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 15386600.00, `acquisition_cost` = 15386600.00, `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-007';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4826 Lot No. 8863 - 6310 sq. meters @ P0.10/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 2587100.00, `acquisition_cost` = 2587100.00, `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-008';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4814 Lot No. 682 - 213 sq. meters @ P0.14/sq.m.',  `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 87330.00,   `acquisition_cost` = 87330.00,   `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-009';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-4826 Lot No. 8864 - 2142 sq. meters @ P0.10/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 771120.00,  `acquisition_cost` = 771120.00,  `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-010';
UPDATE `legacy_assets` SET `item_description` = 'Title No. N-5641 Lot No. 674 - 9577 sq. meters @ P0.30/sq.m.', `account_code_id` = 34, `fund_id` = 3, `unit_cost` = 517816.80,  `acquisition_cost` = 517816.80,  `is_active` = 1 WHERE `property_number` = '1.06.01.010.00-011';
-- Row 12: no property number; match by description
UPDATE `legacy_assets` SET `account_code_id` = 34, `fund_id` = 4, `unit_cost` = 12000000.00, `acquisition_cost` = 12000000.00, `acquisition_date` = '2015-07-20', `is_active` = 1
    WHERE `item_description` = 'Title No. N-4814 8,670 sq. m.' AND `account_code_id` = 34;

-- ─────────────────────────────────────────────
-- INSERT rows that do not yet exist
-- ─────────────────────────────────────────────
INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-001', 'equipment', 'Title No. N-4808 Lot No. 683 - 272 sq. meters @ P0.28/sq.m.',    34, 3, 111520.00,   111520.00,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-001');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-002', 'equipment', 'Title No. N-4809 Lot No. 690 - 225 sq. meters @ P0.19/sq.m.',    34, 3, 81000.00,    81000.00,    1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-002');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-003', 'equipment', 'Title No. N-4810 Lot No. 684 - 284 sq. meters @ P0.20',          34, 3, 116440.00,   116440.00,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-003');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-004', 'equipment', 'Title No. N-4811 Lot No. 675 - 29,544 sq. meters @ P0.04',       34, 3, 3610749.20,  3610749.20,  1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-004');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-005', 'equipment', 'Title No. N-4812 Lot No. 691 - 1767 sq. meters @ P0.20/sq.m.',   34, 3, 636120.00,   636120.00,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-005');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-006', 'equipment', 'Title No. N-4813 Lot No. 680 - 1554 sq. meters @ P0.14/sq.m.',   34, 3, 637140.00,   637140.00,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-006');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-007', 'equipment', 'Title No. N-4814 Lot No. 679 - 42,685 sq. meters @ P0.12/sq.m.', 34, 3, 15386600.00, 15386600.00, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-007');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-008', 'equipment', 'Title No. N-4826 Lot No. 8863 - 6310 sq. meters @ P0.10/sq.m.',  34, 3, 2587100.00,  2587100.00,  1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-008');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-009', 'equipment', 'Title No. N-4814 Lot No. 682 - 213 sq. meters @ P0.14/sq.m.',    34, 3, 87330.00,    87330.00,    1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-009');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-010', 'equipment', 'Title No. N-4826 Lot No. 8864 - 2142 sq. meters @ P0.10/sq.m.',  34, 3, 771120.00,   771120.00,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-010');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `is_active`)
SELECT '1.06.01.010.00-011', 'equipment', 'Title No. N-5641 Lot No. 674 - 9577 sq. meters @ P0.30/sq.m.',   34, 3, 517816.80,   517816.80,   1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.01.010.00-011');

-- Row 12: no property number; match by description+account_code
INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Title No. N-4814 8,670 sq. m.', 34, 4, 12000000.00, 12000000.00, '2015-07-20', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Title No. N-4814 8,670 sq. m.' AND `account_code_id` = 34);
