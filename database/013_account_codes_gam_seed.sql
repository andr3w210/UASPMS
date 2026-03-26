USE `spamsdb`;

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.020.00', 'Office Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.020.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.030.00', 'Information and Communications Technology Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.030.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.040.00', 'Agricultural and Forestry Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.040.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.050.00', 'Marine and Fishery Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.050.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.060.00', 'Airport Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.060.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.070.00', 'Communication Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.070.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.080.00', 'Construction and Heavy Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.080.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.090.00', 'Disaster Response and Rescue Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.090.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.100.00', 'Military, Police and Security Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.100.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.110.00', 'Medical Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.110.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.120.00', 'Printing Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.120.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.130.00', 'Sports Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.130.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.140.00', 'Technical and Scientific Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.140.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.05.990.00', 'Other Machinery and Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.05.990.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.06.990.00', 'Other Transportation Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.06.990.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '1.06.99.990.00', 'Other Property, Plant and Equipment', 'asset', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '1.06.99.990.00');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.01', 'Semi-Expendable ME - Machinery', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.01');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.02', 'Semi-Expendable ME - Office Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.02');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.03', 'Semi-Expendable ME - Information & Communications Technology Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.03');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.04', 'Semi-Expendable ME - Agricultural and Forestry Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.04');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.05', 'Semi-Expendable ME - Marine and Fishery Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.05');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.07', 'Semi-Expendable ME - Communications Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.07');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.08', 'Semi-Expendable ME - Disaster Response and Rescue Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.08');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.10', 'Semi-Expendable ME - Medical Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.10');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.12', 'Semi-Expendable ME - Sports Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.12');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.13', 'Semi-Expendable ME - Technical and Scientific Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.13');

INSERT INTO `account_codes` (`account_code`, `account_name`, `account_group`, `description`)
SELECT '5.02.03.210.99', 'Semi-Expendable ME - Other Machinery and Equipment', 'semi_expendable', 'GAM seeded account code from provided chart screenshot'
WHERE NOT EXISTS (SELECT 1 FROM `account_codes` WHERE `account_code` = '5.02.03.210.99');
