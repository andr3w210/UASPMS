USE `spamsdb`;

INSERT INTO `series_numbers` (`module_key`, `prefix`, `year_value`, `current_value`, `padding_length`)
SELECT 'classifications', 'CLS', YEAR(CURDATE()), 0, 4
WHERE NOT EXISTS (SELECT 1 FROM `series_numbers` WHERE `module_key` = 'classifications');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-001', 'CLS-2026-0001', 'Office Supplies', 'supply', 'OS', 0, NULL, ac.id, 1, 'General office consumables and stationery'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.010.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-001' OR `classification_name` = 'Office Supplies');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-002', 'CLS-2026-0002', 'Accountable Forms', 'supply', 'AF', 0, NULL, ac.id, 1, 'Official accountable forms and controlled documents'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.020.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-002' OR `classification_name` = 'Accountable Forms');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-003', 'CLS-2026-0003', 'Janitorial and Cleaning Supplies', 'supply', 'JS', 0, NULL, ac.id, 1, 'Cleaning and sanitation materials'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.990.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-003' OR `classification_name` = 'Janitorial and Cleaning Supplies');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-004', 'CLS-2026-0004', 'Fuel, Oil and Lubricants', 'supply', 'FL', 0, NULL, ac.id, 1, 'Fuel, oil, and lubricants inventory items'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.090.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-004' OR `classification_name` = 'Fuel, Oil and Lubricants');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-005', 'CLS-2026-0005', 'Medical, Dental and Laboratory Supplies', 'supply', 'ML', 0, NULL, ac.id, 1, 'Medical, dental, and laboratory consumables'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.080.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-005' OR `classification_name` = 'Medical, Dental and Laboratory Supplies');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-006', 'CLS-2026-0006', 'Drugs and Medicines', 'supply', 'DM', 0, NULL, ac.id, 1, 'Drugs, medicines, and pharmaceutical supplies'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.070.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-006' OR `classification_name` = 'Drugs and Medicines');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-007', 'CLS-2026-0007', 'Agricultural and Marine Supplies', 'supply', 'AM', 0, NULL, ac.id, 1, 'Agricultural, marine, and fishery supplies'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.100.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-007' OR `classification_name` = 'Agricultural and Marine Supplies');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SUP-008', 'CLS-2026-0008', 'Textbooks and Instructional Materials', 'supply', 'TI', 0, NULL, ac.id, 1, 'Textbooks, manuals, and instructional materials'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.110.01'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SUP-008' OR `classification_name` = 'Textbooks and Instructional Materials');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-001', 'CLS-2026-0009', 'Office Equipment', 'asset', 'OE', 1, 5, ac.id, 1, 'Movable office equipment for institutional use'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.020.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-001' OR `classification_name` = 'Office Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-002', 'CLS-2026-0010', 'Information and Communication Technology Equipment', 'asset', 'IT', 1, 5, ac.id, 1, 'Computers, peripherals, and ICT devices'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.030.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-002' OR `classification_name` = 'Information and Communication Technology Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-003', 'CLS-2026-0011', 'Communication Equipment', 'asset', 'CE', 1, 10, ac.id, 1, 'Communication and telecommunication equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.070.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-003' OR `classification_name` = 'Communication Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-004', 'CLS-2026-0012', 'Printing Equipment', 'asset', 'PE', 1, 5, ac.id, 1, 'Printers, copiers, and reproduction equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.120.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-004' OR `classification_name` = 'Printing Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-005', 'CLS-2026-0013', 'Furniture and Fixtures', 'asset', 'FF', 0, 10, ac.id, 1, 'Furniture, fixtures, and fabricated furnishings'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.07.010.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-005' OR `classification_name` = 'Furniture and Fixtures');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-006', 'CLS-2026-0014', 'Medical Equipment', 'asset', 'ME', 1, 10, ac.id, 1, 'Medical equipment and devices'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.110.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-006' OR `classification_name` = 'Medical Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-007', 'CLS-2026-0015', 'Technical and Scientific Equipment', 'asset', 'TS', 1, 10, ac.id, 1, 'Technical, scientific, and specialized instruments'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.140.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-007' OR `classification_name` = 'Technical and Scientific Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-008', 'CLS-2026-0016', 'Sports Equipment', 'asset', 'SE', 1, 5, ac.id, 1, 'Sports and athletics equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.130.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-008' OR `classification_name` = 'Sports Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-009', 'CLS-2026-0017', 'Other Machinery and Equipment', 'asset', 'OM', 1, 10, ac.id, 1, 'Other specialized machinery and equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.990.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-009' OR `classification_name` = 'Other Machinery and Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-EQP-010', 'CLS-2026-0018', 'Motor Vehicles', 'asset', 'MV', 1, 7, ac.id, 1, 'Motor vehicles and transport equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.06.010.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-EQP-010' OR `classification_name` = 'Motor Vehicles');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-001', 'CLS-2026-0019', 'Semi-Expendable Office Equipment', 'semi_expendable', 'SO', 1, 3, ac.id, 1, 'Semi-expendable office equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.02'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-001' OR `classification_name` = 'Semi-Expendable Office Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-002', 'CLS-2026-0020', 'Semi-Expendable ICT Equipment', 'semi_expendable', 'SI', 1, 3, ac.id, 1, 'Semi-expendable ICT and computer equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.03'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-002' OR `classification_name` = 'Semi-Expendable ICT Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-003', 'CLS-2026-0021', 'Semi-Expendable Communication Equipment', 'semi_expendable', 'SC', 1, 3, ac.id, 1, 'Semi-expendable communication equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.07'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-003' OR `classification_name` = 'Semi-Expendable Communication Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-004', 'CLS-2026-0022', 'Semi-Expendable Printing Equipment', 'semi_expendable', 'SP', 1, 3, ac.id, 1, 'Semi-expendable printing and reproduction equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.11'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-004' OR `classification_name` = 'Semi-Expendable Printing Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-005', 'CLS-2026-0023', 'Semi-Expendable Medical Equipment', 'semi_expendable', 'SM', 1, 3, ac.id, 1, 'Semi-expendable medical equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.10'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-005' OR `classification_name` = 'Semi-Expendable Medical Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-006', 'CLS-2026-0024', 'Semi-Expendable Technical and Scientific Equipment', 'semi_expendable', 'ST', 1, 3, ac.id, 1, 'Semi-expendable technical and scientific equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.13'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-006' OR `classification_name` = 'Semi-Expendable Technical and Scientific Equipment');

INSERT INTO `classifications`
(`classification_code`, `system_reference`, `classification_name`, `classification_group`, `abbreviation`, `requires_serial`, `useful_life_years`, `account_code_id`, `is_active`, `description`)
SELECT 'CLS-SEMI-007', 'CLS-2026-0025', 'Semi-Expendable Other Machinery and Equipment', 'semi_expendable', 'SX', 1, 3, ac.id, 1, 'Semi-expendable items under other machinery and equipment'
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.99'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-SEMI-007' OR `classification_name` = 'Semi-Expendable Other Machinery and Equipment');
