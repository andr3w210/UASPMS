USE `spamsdb`;

ALTER TABLE `account_codes`
    ADD COLUMN IF NOT EXISTS `default_useful_life_years` TINYINT UNSIGNED NULL DEFAULT NULL
        AFTER `account_group`;

CREATE TABLE IF NOT EXISTS `account_codes_useful_life_backfill_20260630` AS
SELECT `id`, `account_code`, `account_name`, `account_group`, `default_useful_life_years`
FROM `account_codes`;

UPDATE `account_codes`
SET `default_useful_life_years` = CASE
    WHEN `account_group` = 'supply' THEN NULL
    WHEN `account_code` = '1.06.01.010.00' THEN NULL
    WHEN `account_code` IN ('1.06.10.010.00', '1.06.10.020.00', '1.06.10.030.00') THEN NULL
    WHEN `account_code` IN ('1.06.11.010.00', '1.06.11.020.00', '1.06.11.990.00') THEN NULL
    WHEN `account_group` = 'semi_expendable' THEN 3
    WHEN `account_code` IN ('1.06.02.010.00', '1.06.02.020.00', '1.06.02.990.00') THEN 20
    WHEN `account_code` IN ('1.06.03.010.00', '1.06.03.020.00', '1.06.03.030.00', '1.06.03.040.00', '1.06.03.050.00', '1.06.03.060.00', '1.06.03.070.00', '1.06.03.090.00', '1.06.03.100.00') THEN 20
    WHEN `account_code` IN ('1.06.04.010.00', '1.06.04.020.00', '1.06.04.030.00', '1.06.04.040.00', '1.06.04.050.00', '1.06.04.060.00') THEN 30
    WHEN `account_code` = '1.06.04.990.00' THEN 20
    WHEN `account_code` = '1.06.05.010.00' THEN 10
    WHEN `account_code` IN ('1.06.05.020.00', '1.06.05.030.00', '1.06.05.120.00', '1.06.05.130.00') THEN 5
    WHEN `account_code` IN ('1.06.05.040.00', '1.06.05.050.00', '1.06.05.060.00', '1.06.05.070.00', '1.06.05.080.00', '1.06.05.090.00', '1.06.05.100.00', '1.06.05.110.00', '1.06.05.140.00', '1.06.05.990.00') THEN 10
    WHEN `account_code` IN ('1.06.06.010.00', '1.06.06.990.00') THEN 7
    WHEN `account_code` = '1.06.07.010.00' THEN 10
    WHEN `account_code` = '1.06.07.020.00' THEN 5
    WHEN `account_code` = '1.06.08.010.00' THEN 10
    WHEN `account_code` = '1.06.99.990.00' THEN 10
    WHEN `account_code` = '1.08.01.020.00' THEN 10
    ELSE NULL
END
WHERE `account_group` IN ('supply', 'semi_expendable', 'asset', 'fixed_asset');
