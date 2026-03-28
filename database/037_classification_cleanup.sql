USE `spamsdb`;

-- Add additional item classifications needed for current catalog items

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-009', 'Notebook and Record Book', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Notebooks, record books, and similar writing materials', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Notebook and Record Book');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-010', 'Folder and Filing Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Supplies' LIMIT 1),
       'Folders, organizers, and filing supplies', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Folder and Filing Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-011', 'Diesel Fuel', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Fuel, Oil and Lubricants' LIMIT 1),
       'Diesel fuel supply', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Diesel Fuel');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-012', 'Antiseptic Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical Supplies' LIMIT 1),
       'Antiseptic and disinfectant consumable supplies', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Antiseptic Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SUP-013', 'Protective Medical Supplies', 'supply', NULL,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical Supplies' LIMIT 1),
       'Protective healthcare supplies such as masks and gloves', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Protective Medical Supplies');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-009', 'External Hard Drive', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'External data storage device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'External Hard Drive');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-010', 'Ergonomic Office Chair', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable ergonomic office chair', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Ergonomic Office Chair');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-011', 'Telephone Set', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Communication Equipment' LIMIT 1),
       'Corded or cordless telephone set', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Telephone Set');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-012', 'Webcam', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'Semi-expendable webcam device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Webcam');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-013', 'Barcode Scanner', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       'Semi-expendable barcode scanner', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Barcode Scanner');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-014', 'Laminating Machine', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Printing Equipment' LIMIT 1),
       'Semi-expendable laminating machine', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Laminating Machine');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-015', 'Blood Pressure Monitor', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Medical Equipment' LIMIT 1),
       'Semi-expendable blood pressure monitoring device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Blood Pressure Monitor');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-016', 'Office Table', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable office table', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Office Table');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-017', 'Steel Filing Cabinet', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       'Semi-expendable steel filing cabinet', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Steel Filing Cabinet');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-018', 'Digital Weighing Scale', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Technical and Scientific Equipment' LIMIT 1),
       'Semi-expendable weighing scale', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Digital Weighing Scale');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-SEMI-019', 'Portable Speaker', 'semi_expendable', 3,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Semi-Expendable Communication Equipment' LIMIT 1),
       'Semi-expendable portable speaker', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Portable Speaker');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-011', 'Timekeeping Device', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Office Equipment' LIMIT 1),
       'Biometric or electronic timekeeping device', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Timekeeping Device');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-012', 'Microscope', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Technical and Scientific Equipment' LIMIT 1),
       'Microscope and laboratory observation equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Microscope');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-013', 'DSLR Camera', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Digital single-lens reflex camera', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'DSLR Camera');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-014', 'Examination Bed', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Medical Equipment' LIMIT 1),
       'Medical examination bed', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Examination Bed');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-015', 'Smart TV', 'asset', 5,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       'Smart television and display equipment', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Smart TV');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-016', 'Bookshelf', 'asset', 10,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Furniture and Fixtures' LIMIT 1),
       'Bookshelf and shelving furniture', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Bookshelf');

INSERT INTO `classifications`
(`classification_code`, `classification_name`, `classification_group`, `useful_life_years`, `account_code_id`, `description`, `is_active`)
SELECT 'CLS-ITEM-EQP-017', 'Pickup Truck', 'asset', 7,
       (SELECT c.account_code_id FROM classifications c WHERE c.classification_name = 'Vehicle' LIMIT 1),
       'Pickup truck and field service transport vehicle', 1
WHERE NOT EXISTS (SELECT 1 FROM `classifications` WHERE `classification_name` = 'Pickup Truck');

-- Reclassify existing stock catalog rows to item-based classifications
UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Ballpen and Writing Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Ballpen, Blue';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Bond Paper'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Bond Paper A4 70gsm';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Diesel Fuel'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Diesel Fuel';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Cleaning Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name IN ('Dishwashing Liquid 500mL', 'Liquid Bleach 1 Liter');

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Folder and Filing Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Expandable Folder, Legal';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Notebook and Record Book'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Notebook, Composition';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Printer Ink and Toner'
SET sc.classification_id = c.id
WHERE sc.item_name IN ('Printer Ink, Epson 003 Black', 'Toner Cartridge, Brother TN-2360');

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Antiseptic Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Rubbing Alcohol 70% 500mL';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Protective Medical Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Surgical Face Mask 3-ply';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Ballpen and Writing Supplies'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Whiteboard Marker, Black';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Blood Pressure Monitor'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Automatic BP Monitor';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Barcode Scanner'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Barcode Scanner USB';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Telephone Set'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Corded Telephone Set';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Digital Weighing Scale'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Digital Weighing Scale';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Ergonomic Office Chair'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Ergonomic Office Chair';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'External Hard Drive'
SET sc.classification_id = c.id
WHERE sc.item_name = 'External Hard Drive 1TB';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Laminating Machine'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Laminating Machine A4';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Office Table'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Office Table 1200mm';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Portable Speaker'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Portable Speaker';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Steel Filing Cabinet'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Steel Filing Cabinet 4-Drawer';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'UPS'
SET sc.classification_id = c.id
WHERE sc.item_name = 'UPS 650VA';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Webcam'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Webcam Full HD';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Timekeeping Device'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Biometric Timekeeping Device';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Microscope'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Compound Microscope';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Desktop Computer'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Desktop Computer Set';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'DSLR Camera'
SET sc.classification_id = c.id
WHERE sc.item_name = 'DSLR Camera';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Examination Bed'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Examination Bed Stainless';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Laptop Computer'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Laptop Computer 14-inch';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Printer'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Laser Multifunction Printer';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Smart TV'
SET sc.classification_id = c.id
WHERE sc.item_name = 'LED Smart TV 55-inch';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Network Switch'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Network Switch 24-Port';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Air Conditioner'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Split Type Air Conditioner 2HP';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Bookshelf'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Steel Bookshelf 5-Layer';

UPDATE `stock_catalog` sc
JOIN `classifications` c ON c.classification_name = 'Pickup Truck'
SET sc.classification_id = c.id
WHERE sc.item_name = 'Toyota Hilux Pickup';

-- Remove old broad classifications once no longer referenced
DELETE c
FROM `classifications` c
WHERE c.classification_name IN (
    'Office Supplies',
    'Accountable Forms',
    'Janitorial and Cleaning Supplies',
    'Fuel, Oil and Lubricants',
    'Medical, Dental and Laboratory Supplies',
    'Medical Supplies',
    'Drugs and Medicines',
    'Agricultural and Marine Supplies',
    'Textbooks and Instructional Materials',
    'Office Equipment',
    'Information and Communication Technology Equipment',
    'Communication Equipment',
    'Printing Equipment',
    'Furniture and Fixtures',
    'Medical Equipment',
    'Technical and Scientific Equipment',
    'Sports Equipment',
    'Other Machinery and Equipment',
    'Motor Vehicles',
    'Vehicle',
    'Semi-Expendable Office Equipment',
    'Semi-Expendable ICT Equipment',
    'Semi-Expendable Communication Equipment',
    'Semi-Expendable Printing Equipment',
    'Semi-Expendable Medical Equipment',
    'Semi-Expendable Technical and Scientific Equipment',
    'Semi-Expendable Other Machinery and Equipment'
)
AND NOT EXISTS (SELECT 1 FROM `stock_catalog` sc WHERE sc.classification_id = c.id)
AND NOT EXISTS (SELECT 1 FROM `purchase_order_items` poi WHERE poi.classification_id = c.id);
