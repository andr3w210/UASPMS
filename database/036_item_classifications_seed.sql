USE `spamsdb`;

-- Item classification seed for SPAMS
-- Safe to re-run: each row uses NOT EXISTS on classification_name

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-001', 'Bond Paper', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Paper supplies for office, records, and printing use', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Bond Paper');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-002', 'Ballpen and Writing Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Pens, markers, pencils, and similar writing materials', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Ballpen and Writing Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-003', 'Printer Ink and Toner', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Ink bottles, cartridges, and toner supplies', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Printer Ink and Toner');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-004', 'Cleaning Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Janitorial and Cleaning Supplies' LIMIT 1),
       'Janitorial and sanitation consumables', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Cleaning Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-005', 'Electrical Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Cables, plugs, outlets, and small electrical consumables', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Electrical Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-006', 'Medical Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical, Dental and Laboratory Supplies' LIMIT 1),
       'Clinic and healthcare consumable supplies', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Medical Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-006A', 'Antiseptic Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical Supplies' LIMIT 1),
       'Antiseptic and disinfectant consumable supplies', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Antiseptic Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-006B', 'Protective Medical Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical Supplies' LIMIT 1),
       'Protective healthcare supplies such as masks and gloves', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Protective Medical Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-007', 'Laboratory Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical, Dental and Laboratory Supplies' LIMIT 1),
       'Laboratory consumables and disposables', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Laboratory Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-008', 'Printed Forms', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Accountable Forms' LIMIT 1),
       'Printed government forms and controlled stationery', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Printed Forms');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-001', 'Desktop Computer', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Desktop workstation and computer set', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Desktop Computer');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-002', 'Laptop Computer', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Portable computer equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Laptop Computer');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-003', 'Printer', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Printing Equipment' LIMIT 1),
       'Network, office, and standalone printers', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Printer');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-004', 'Projector', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Equipment' LIMIT 1),
       'Projection equipment for classrooms and meetings', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Projector');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-005', 'Office Chair', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Furniture and Fixtures' LIMIT 1),
       'Office seating furniture', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Office Chair');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-006', 'Filing Cabinet', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Furniture and Fixtures' LIMIT 1),
       'Records and document storage cabinet', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Filing Cabinet');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-007', 'Router', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Routing and network connectivity equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Router');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-008', 'Network Switch', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Network switching equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Network Switch');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-009', 'Air Conditioner', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Other Machinery and Equipment' LIMIT 1),
       'Cooling and air conditioning equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Air Conditioner');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-010', 'Vehicle', 'asset', 7,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Motor Vehicles' LIMIT 1),
       'Motor vehicle and transport equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Vehicle');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-010A', 'Pickup Truck', 'asset', 7,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Vehicle' LIMIT 1),
       'Pickup truck and field service transport vehicle', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Pickup Truck');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-001', 'Monoblock Chair', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable plastic seating', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Monoblock Chair');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-002', 'Electric Fan', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable electric fan', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Electric Fan');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-003', 'UPS', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'Uninterruptible power supply', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'UPS');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-004', 'Tablet', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'Semi-expendable tablet device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Tablet');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-005', 'Mobile Phone', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Communication Equipment' LIMIT 1),
       'Semi-expendable mobile communication device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Mobile Phone');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-006', 'Portable Printer', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Printing Equipment' LIMIT 1),
       'Semi-expendable portable printing device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Portable Printer');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-007', 'Steel Cabinet', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable storage cabinet', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Steel Cabinet');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-008', 'Biometric Device', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'Semi-expendable biometric and attendance device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Biometric Device');
