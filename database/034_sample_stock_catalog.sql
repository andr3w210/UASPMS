USE spamsdb;

-- =====================================================
-- SAMPLE STOCK CATALOG DATA
-- Safe to re-run: all inserts are idempotent
-- =====================================================

-- Supplies
INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0001', 'Bond Paper A4 70gsm', 'A4 copy paper, 500 sheets per ream', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'ream' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0001');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0002', 'Ballpen, Blue', 'Fine point blue ballpen', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'pc' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0002');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0003', 'Notebook, Composition', 'Composition notebook, 80 leaves', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'book' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0003');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0004', 'Expandable Folder, Legal', 'Brown legal-size expandable folder', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'pc' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0004');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0005', 'Whiteboard Marker, Black', 'Whiteboard marker with bullet tip', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'pc' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0005');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0006', 'Printer Ink, Epson 003 Black', 'Ink bottle for Epson EcoTank printers', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'ink btl' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0006');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0007', 'Toner Cartridge, Brother TN-2360', 'Compatible toner cartridge for Brother laser printer', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Office Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'tnr' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0007');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0008', 'Rubbing Alcohol 70% 500mL', 'Antiseptic rubbing alcohol', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Drugs and Medicines' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Drugs and Medicines Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'btl' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0008');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0009', 'Surgical Face Mask 3-ply', 'Disposable face mask, 50 pieces per box', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Medical, Dental and Laboratory Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Medical, Dental and Laboratory Supplies Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'box' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0009');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0010', 'Liquid Bleach 1 Liter', 'Household bleach for cleaning and disinfection', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Janitorial and Cleaning Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Other Supplies and Materials Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'btl' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0010');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0011', 'Dishwashing Liquid 500mL', 'Liquid detergent for pantry use', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Janitorial and Cleaning Supplies' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Other Supplies and Materials Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'btl' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0011');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SUP-0012', 'Diesel Fuel', 'Diesel fuel for university service vehicles', 'supply',
       (SELECT id FROM classifications WHERE classification_name = 'Fuel, Oil and Lubricants' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Fuel, Oil and Lubricants Expenses' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'L' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SUP-0012');

-- Semi-expendable
INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0001', 'UPS 650VA', 'Line-interactive uninterruptible power supply', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Information & Communications Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0001');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0002', 'External Hard Drive 1TB', 'Portable USB 3.0 external hard drive', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Information & Communications Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0002');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0003', 'Webcam Full HD', '1080p USB webcam for online meetings', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Information & Communications Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0003');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0004', 'Ergonomic Office Chair', 'Mesh back office chair with armrest', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Office Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0004');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0005', 'Steel Filing Cabinet 4-Drawer', 'Vertical steel filing cabinet', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Office Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0005');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0006', 'Corded Telephone Set', 'Desk telephone for office communication', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Communication Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Communications Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0006');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0007', 'Portable Speaker', 'Rechargeable portable public address speaker', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Communication Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Communications Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0007');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0008', 'Laminating Machine A4', 'Office laminating machine for documents', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Printing Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Printing Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0008');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0009', 'Barcode Scanner USB', 'Handheld barcode scanner for inventory tagging', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable ICT Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Information & Communications Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0009');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0010', 'Digital Weighing Scale', 'Bench-top digital weighing scale for lab and supply use', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Technical and Scientific Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Technical and Scientific Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0010');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0011', 'Automatic BP Monitor', 'Digital blood pressure monitor', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Medical Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Medical Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0011');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-SE-0012', 'Office Table 1200mm', 'Melamine office table with metal legs', 'semi_expendable',
       (SELECT id FROM classifications WHERE classification_name = 'Semi-Expendable Office Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Semi-Expendable ME - Office Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-SE-0012');

-- Equipment
INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0001', 'Desktop Computer Set', 'Core i5 desktop set with monitor, keyboard, and mouse', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'set' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0001');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0002', 'Laptop Computer 14-inch', 'Business laptop for faculty and staff use', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0002');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0003', 'Network Switch 24-Port', 'Managed gigabit switch for campus networking', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Communication Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Communication Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0003');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0004', 'Laser Multifunction Printer', 'Print, scan, and copy office machine', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Printing Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Printing Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0004');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0005', 'Biometric Timekeeping Device', 'Fingerprint biometric attendance machine', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Office Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Office Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0005');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0006', 'Split Type Air Conditioner 2HP', 'Wall-mounted inverter air conditioner', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Other Machinery and Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Other Machinery and Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0006');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0007', 'LED Smart TV 55-inch', 'Smart television for conference room presentations', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0007');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0008', 'DSLR Camera', 'Digital camera for documentation and media coverage', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Information and Communication Technology Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0008');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0009', 'Examination Bed Stainless', 'Hospital examination bed for clinic use', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Medical Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Medical Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0009');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0010', 'Compound Microscope', 'Laboratory microscope for biology instruction', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Technical and Scientific Equipment' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Technical and Scientific Equipment' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0010');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0011', 'Steel Bookshelf 5-Layer', 'Heavy-duty steel bookshelf for library records', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Furniture and Fixtures' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Furniture and Fixtures' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0011');

INSERT INTO `stock_catalog` (`stock_no`, `item_name`, `item_description`, `item_type`, `classification_id`, `account_code_id`, `unit_of_measure_id`, `is_active`)
SELECT 'SC-EQP-0012', 'Toyota Hilux Pickup', 'Service vehicle for field operations and transport', 'equipment',
       (SELECT id FROM classifications WHERE classification_name = 'Motor Vehicles' LIMIT 1),
       (SELECT id FROM account_codes WHERE account_name = 'Motor Vehicles' LIMIT 1),
       (SELECT id FROM unit_of_measures WHERE abbreviation = 'unit' LIMIT 1),
       1
WHERE NOT EXISTS (SELECT 1 FROM stock_catalog WHERE stock_no = 'SC-EQP-0012');
