USE `spamsdb`;

INSERT INTO `brands` (`brand_code`, `brand_name`, `description`, `is_active`)
SELECT * FROM (
    SELECT 'BRD-2026-0001' AS brand_code, 'Acer' AS brand_name, 'ICT equipment and laptop brand' AS description, 1 AS is_active
    UNION ALL SELECT 'BRD-2026-0002', 'Dell', 'ICT equipment and desktop/laptop brand', 1
    UNION ALL SELECT 'BRD-2026-0003', 'HP', 'Printers, desktops, laptops, and peripherals', 1
    UNION ALL SELECT 'BRD-2026-0004', 'Lenovo', 'Desktop and laptop brand', 1
    UNION ALL SELECT 'BRD-2026-0005', 'Epson', 'Printers and projectors', 1
    UNION ALL SELECT 'BRD-2026-0006', 'Canon', 'Printers and imaging equipment', 1
    UNION ALL SELECT 'BRD-2026-0007', 'Brother', 'Printers and office equipment', 1
    UNION ALL SELECT 'BRD-2026-0008', 'Asus', 'Laptop and ICT equipment brand', 1
    UNION ALL SELECT 'BRD-2026-0009', 'JBL', 'Audio and communication equipment', 1
    UNION ALL SELECT 'BRD-2026-0010', 'Samsung', 'Monitors, tablets, and ICT devices', 1
) AS seeded
WHERE NOT EXISTS (
    SELECT 1
    FROM `brands` b
    WHERE b.`brand_name` = seeded.`brand_name`
);

INSERT INTO `models` (`brand_id`, `model_code`, `model_name`, `description`, `is_active`)
SELECT b.id, seeded.model_code, seeded.model_name, seeded.description, 1
FROM (
    SELECT 'Acer' AS brand_name, 'MDL-2026-0001' AS model_code, 'Aspire 7' AS model_name, 'Laptop model' AS description
    UNION ALL SELECT 'Acer', 'MDL-2026-0002', 'Veriton', 'Desktop computer model'
    UNION ALL SELECT 'Dell', 'MDL-2026-0003', 'OptiPlex', 'Desktop computer model'
    UNION ALL SELECT 'Dell', 'MDL-2026-0004', 'Latitude', 'Laptop model'
    UNION ALL SELECT 'HP', 'MDL-2026-0005', 'LaserJet Pro', 'Printer model series'
    UNION ALL SELECT 'HP', 'MDL-2026-0006', 'ProBook', 'Laptop model'
    UNION ALL SELECT 'Lenovo', 'MDL-2026-0007', 'ThinkPad', 'Laptop model'
    UNION ALL SELECT 'Lenovo', 'MDL-2026-0008', 'ThinkCentre', 'Desktop computer model'
    UNION ALL SELECT 'Epson', 'MDL-2026-0009', 'L3210', 'Printer model'
    UNION ALL SELECT 'Epson', 'MDL-2026-0010', 'EB-X06', 'Projector model'
    UNION ALL SELECT 'Canon', 'MDL-2026-0011', 'PIXMA G2010', 'Printer model'
    UNION ALL SELECT 'Brother', 'MDL-2026-0012', 'DCP-T420W', 'Printer model'
    UNION ALL SELECT 'Asus', 'MDL-2026-0013', 'VivoBook', 'Laptop model'
    UNION ALL SELECT 'JBL', 'MDL-2026-0014', 'IRX108BT', 'Portable speaker model'
    UNION ALL SELECT 'Samsung', 'MDL-2026-0015', 'ViewFinity', 'Monitor model series'
    UNION ALL SELECT 'Samsung', 'MDL-2026-0016', 'Galaxy Tab', 'Tablet model series'
) AS seeded
INNER JOIN `brands` b ON b.`brand_name` = seeded.`brand_name`
WHERE NOT EXISTS (
    SELECT 1
    FROM `models` m
    WHERE m.`brand_id` = b.`id`
      AND m.`model_name` = seeded.`model_name`
);

UPDATE `series_numbers`
SET `current_value` = GREATEST(`current_value`, 10),
    `year_value` = 2026
WHERE `module_key` = 'brands';

UPDATE `series_numbers`
SET `current_value` = GREATEST(`current_value`, 16),
    `year_value` = 2026
WHERE `module_key` = 'models';
