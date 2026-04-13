-- RPCPPE 2025 - Other Land Improvements (1.06.02.990.00)
-- account_code_id = 37  (Other Land Improvements)
-- fund_id 3 = fund_source 01 (GAA-GAS)
-- fund_id 4 = fund_source 05 (TFCF)
-- Strategy: UPDATE by property_number if exists; INSERT if not exists.
--           Rows with blank property number: match by item_description + account_code_id.

USE `spamsdb`;

-- ─────────────────────────────────────────────
-- UPDATE existing rows (by property_number)
-- ─────────────────────────────────────────────
UPDATE `legacy_assets` SET `item_description` = 'Fence - 70 Span 3 x 3 meters (repaired 325.64 sq. m. 5/10/2024)', `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 1789757.50, `acquisition_cost` = 1789757.50, `acquisition_date` = '2004-01-01', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-004';
UPDATE `legacy_assets` SET `item_description` = 'Fence - 81 x 156 sq. m.',                                          `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 898000.00,   `acquisition_cost` = 898000.00,   `acquisition_date` = '2004-01-01', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-005';
UPDATE `legacy_assets` SET `item_description` = 'Roadway',                                                          `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 847560.00,   `acquisition_cost` = 847560.00,   `acquisition_date` = '2004-01-01', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-006';
UPDATE `legacy_assets` SET `item_description` = 'Physical Health & Wellness Facility - 6,048 sq.m',                `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 8851483.33, `acquisition_cost` = 8851483.33, `acquisition_date` = '2018-06-26', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-007';
UPDATE `legacy_assets` SET `item_description` = 'Entrance of Physical Health Wellness Facility - 0.072 sq.m',      `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 148466.98,  `acquisition_cost` = 148466.98,  `acquisition_date` = '2018-06-26', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-008';
UPDATE `legacy_assets` SET `item_description` = 'Sports Training Center (Swimming pool Including contiguous) - 1250 sq.m.', `account_code_id` = 37, `fund_id` = 3, `unit_cost` = 24999784.35, `acquisition_cost` = 24999784.35, `acquisition_date` = '2024-12-02', `is_active` = 1 WHERE `property_number` = '1.06.02.990.00-009';

-- ─────────────────────────────────────────────
-- UPDATE blank-property-number rows (by description + account_code_id)
-- ─────────────────────────────────────────────
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 1834126.13,  `acquisition_cost` = 1834126.13,  `acquisition_date` = '2005-08-07', `is_active` = 1 WHERE `item_description` = 'Drainage System'                                                                                                        AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 1316688.58,  `acquisition_cost` = 1316688.58,  `acquisition_date` = '2018-01-01', `is_active` = 1 WHERE `item_description` = 'Rehab of UA drainage (side of Covered Gym) 2nd payment'                                                                   AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 2031404.31,  `acquisition_cost` = 2031404.31,  `acquisition_date` = '2018-09-21', `is_active` = 1 WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=230 sq.m.; front of Supply Office (9/21/18)'                               AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 0.00,        `acquisition_cost` = 0.00,                                           `is_active` = 1 WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=320 sq.m.; front of UA Canteen (9/21/18)'                                AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 0.00,        `acquisition_cost` = 0.00,                                           `is_active` = 1 WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=962 sq.m.; front of old GEB to Gate-2'                                  AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 375945.29,   `acquisition_cost` = 375945.29,   `acquisition_date` = '2020-06-05', `is_active` = 1 WHERE `item_description` = 'Land Improvement form Architecture Building to Gate 3 (Provision for Pathwalk)'                                        AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 1909677.54,  `acquisition_cost` = 1909677.54,  `acquisition_date` = '2023-11-22', `is_active` = 1 WHERE `item_description` = 'Landscaping of Old GEB Lawn - A=1,026.42 sq.m.'                                                                        AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 188393.42,   `acquisition_cost` = 188393.42,   `acquisition_date` = '2024-02-12', `is_active` = 1 WHERE `item_description` = 'Landscaping at the front of perimeter fence along national road (carabao grass front of center gate) - 327 sq.m.'     AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 2803675.72,  `acquisition_cost` = 2803675.72,  `acquisition_date` = '2024-11-11', `is_active` = 1 WHERE `item_description` = 'Construction of road network (pool area) - 749 sq.m. w/ slope protection'                                             AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 995668.66,   `acquisition_cost` = 995668.66,   `acquisition_date` = '2024-11-11', `is_active` = 1 WHERE `item_description` = 'Construction of sports training center phase 2 (retaining wall north side) - 299.3 sq. m.'                            AND `account_code_id` = 37;
UPDATE `legacy_assets` SET `fund_id` = 4, `unit_cost` = 795891.77,   `acquisition_cost` = 795891.77,   `acquisition_date` = '2024-08-15', `is_active` = 1 WHERE `item_description` = 'Perimeter fence and drainage system along national road phase IV (shed extension and walk rays)-Repair - 274.434 sq.m.; front gate' AND `account_code_id` = 37;

-- ─────────────────────────────────────────────
-- INSERT rows with property numbers (if not exists)
-- ─────────────────────────────────────────────
INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-004', 'equipment', 'Fence - 70 Span 3 x 3 meters (repaired 325.64 sq. m. 5/10/2024)',              37, 3, 1789757.50, 1789757.50, '2004-01-01', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-004');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-005', 'equipment', 'Fence - 81 x 156 sq. m.',                                                      37, 3, 898000.00,   898000.00,   '2004-01-01', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-005');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-006', 'equipment', 'Roadway',                                                                       37, 3, 847560.00,   847560.00,   '2004-01-01', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-006');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-007', 'equipment', 'Physical Health & Wellness Facility - 6,048 sq.m',                              37, 3, 8851483.33, 8851483.33, '2018-06-26', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-007');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-008', 'equipment', 'Entrance of Physical Health Wellness Facility - 0.072 sq.m',                    37, 3, 148466.98,  148466.98,  '2018-06-26', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-008');

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '1.06.02.990.00-009', 'equipment', 'Sports Training Center (Swimming pool Including contiguous) - 1250 sq.m.',       37, 3, 24999784.35, 24999784.35, '2024-12-02', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `property_number` = '1.06.02.990.00-009');

-- ─────────────────────────────────────────────
-- INSERT blank-property-number rows (if not exists by description + account)
-- ─────────────────────────────────────────────
INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Drainage System',                                                                                                                                         37, 4, 1834126.13, 1834126.13, '2005-08-07', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Drainage System' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Rehab of UA drainage (side of Covered Gym) 2nd payment',                                                                                                  37, 4, 1316688.58, 1316688.58, '2018-01-01', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Rehab of UA drainage (side of Covered Gym) 2nd payment' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `remarks`, `is_active`)
SELECT '', 'equipment', 'Const of pavement, pathwalk & drainage - A=230 sq.m.; front of Supply Office (9/21/18)',                                                                  37, 4, 2031404.31, 2031404.31, '2018-09-21', 1, 'Construction', 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=230 sq.m.; front of Supply Office (9/21/18)' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `remarks`, `is_active`)
SELECT '', 'equipment', 'Const of pavement, pathwalk & drainage - A=320 sq.m.; front of UA Canteen (9/21/18)',                                                                     37, 4, 0.00,       0.00,       1, 'Reconstruction', 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=320 sq.m.; front of UA Canteen (9/21/18)' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `quantity`, `remarks`, `is_active`)
SELECT '', 'equipment', 'Const of pavement, pathwalk & drainage - A=962 sq.m.; front of old GEB to Gate-2',                                                                        37, 4, 0.00,       0.00,       1, 'Construction', 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Const of pavement, pathwalk & drainage - A=962 sq.m.; front of old GEB to Gate-2' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Land Improvement form Architecture Building to Gate 3 (Provision for Pathwalk)',                                                                            37, 4, 375945.29,  375945.29,  '2020-06-05', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Land Improvement form Architecture Building to Gate 3 (Provision for Pathwalk)' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Landscaping of Old GEB Lawn - A=1,026.42 sq.m.',                                                                                                          37, 4, 1909677.54, 1909677.54, '2023-11-22', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Landscaping of Old GEB Lawn - A=1,026.42 sq.m.' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Landscaping at the front of perimeter fence along national road (carabao grass front of center gate) - 327 sq.m.',                                         37, 4, 188393.42,  188393.42,  '2024-02-12', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Landscaping at the front of perimeter fence along national road (carabao grass front of center gate) - 327 sq.m.' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Construction of road network (pool area) - 749 sq.m. w/ slope protection',                                                                                37, 4, 2803675.72, 2803675.72, '2024-11-11', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Construction of road network (pool area) - 749 sq.m. w/ slope protection' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Construction of sports training center phase 2 (retaining wall north side) - 299.3 sq. m.',                                                               37, 4, 995668.66,  995668.66,  '2024-11-11', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Construction of sports training center phase 2 (retaining wall north side) - 299.3 sq. m.' AND `account_code_id` = 37);

INSERT INTO `legacy_assets` (`property_number`, `item_type`, `item_description`, `account_code_id`, `fund_id`, `unit_cost`, `acquisition_cost`, `acquisition_date`, `quantity`, `is_active`)
SELECT '', 'equipment', 'Perimeter fence and drainage system along national road phase IV (shed extension and walk rays)-Repair - 274.434 sq.m.; front gate',                       37, 4, 795891.77,  795891.77,  '2024-08-15', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM `legacy_assets` WHERE `item_description` = 'Perimeter fence and drainage system along national road phase IV (shed extension and walk rays)-Repair - 274.434 sq.m.; front gate' AND `account_code_id` = 37);
