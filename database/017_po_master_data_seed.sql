USE `spamsdb`;

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-2026-0001', 'Antique Office Depot', 'Sales Coordinator', '09171234567', 'sales@antiqueofficedepot.test', 'Sibalom, Antique', '000-111-222-000', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_code` = 'SUP-2026-0001');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-2026-0002', 'Panay ICT Trading', 'Account Executive', '09181234567', 'info@panayicttrading.test', 'San Jose, Antique', '000-111-222-001', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_code` = 'SUP-2026-0002');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-2026-0003', 'Visayan Medical and Laboratory Supplies', 'Customer Support', '09191234567', 'support@visayanmedlab.test', 'Iloilo City', '000-111-222-002', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_code` = 'SUP-2026-0003');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-2026-0004', 'Western Printing Solutions', 'Marketing Officer', '09201234567', 'orders@westernprinting.test', 'Kalibo, Aklan', '000-111-222-003', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_code` = 'SUP-2026-0004');

INSERT INTO `suppliers` (`supplier_code`, `supplier_name`, `contact_person`, `contact_no`, `email`, `address`, `tin_no`, `is_active`)
SELECT 'SUP-2026-0005', 'Libertad General Merchandise', 'Owner', '09211234567', 'sales@libertadgm.test', 'Libertad, Antique', '000-111-222-004', 1
WHERE NOT EXISTS (SELECT 1 FROM `suppliers` WHERE `supplier_code` = 'SUP-2026-0005');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`, `is_active`)
SELECT 'UOM-2026-0006', 'Ream', 'ream', 'Paper sold per ream', 1
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'ream');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`, `is_active`)
SELECT 'UOM-2026-0007', 'Pack', 'pack', 'Packed items', 1
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'pack');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`, `is_active`)
SELECT 'UOM-2026-0008', 'Bottle', 'btl', 'Liquid item container', 1
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'btl');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`, `is_active`)
SELECT 'UOM-2026-0009', 'Roll', 'roll', 'Items procured per roll', 1
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'roll');

INSERT INTO `unit_of_measures` (`uom_code`, `uom_name`, `abbreviation`, `description`, `is_active`)
SELECT 'UOM-2026-0010', 'Can', 'can', 'Items procured per can', 1
WHERE NOT EXISTS (SELECT 1 FROM `unit_of_measures` WHERE `abbreviation` = 'can');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0003', 'Laptop Computer', 'asset', ac.id, 'Portable computer equipment', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.030.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0003');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0004', 'Printer', 'asset', ac.id, 'Office and network printer equipment', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.120.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0004');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0005', 'Office Chair', 'asset', ac.id, 'Office seating and furniture-type equipment under office equipment', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.020.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0005');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0006', 'Steel Filing Cabinet', 'asset', ac.id, 'Records storage cabinet', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.020.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0006');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0007', 'Router and Network Device', 'asset', ac.id, 'Network routing and connectivity device', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.070.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0007');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0008', 'Medical Diagnostic Device', 'asset', ac.id, 'Medical diagnostic or clinic equipment', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '1.06.05.110.00'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0008');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0009', 'Bond Paper', 'supply', ac.id, 'Common office paper supply', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5-02-03-010'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0009');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0010', 'Printer Ink and Toner', 'supply', ac.id, 'Printing consumables', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5-02-03-010'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0010');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0011', 'Cleaning Supplies', 'supply', ac.id, 'Janitorial and sanitation supplies', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5-02-03-010'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0011');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0012', 'Semi-Expendable Office Equipment', 'semi_expendable', ac.id, 'Semi-expendable office equipment items', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.02'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0012');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0013', 'Semi-Expendable ICT Equipment', 'semi_expendable', ac.id, 'Semi-expendable information and communications equipment', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.03'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0013');

INSERT INTO `classifications` (`classification_code`, `classification_name`, `classification_group`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-2026-0014', 'Semi-Expendable Communications Equipment', 'semi_expendable', ac.id, 'Semi-expendable communication devices', 1
FROM `account_codes` ac
WHERE ac.`account_code` = '5.02.03.210.07'
  AND NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_code` = 'CLS-2026-0014');

UPDATE `series_numbers`
SET `current_value` = 5
WHERE `module_key` = 'suppliers'
  AND `current_value` < 5;

UPDATE `series_numbers`
SET `current_value` = 10
WHERE `module_key` = 'unit_of_measures'
  AND `current_value` < 10;

UPDATE `series_numbers`
SET `current_value` = 14
WHERE `module_key` = 'classifications'
  AND `current_value` < 14;
