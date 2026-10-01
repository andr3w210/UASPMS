USE `spamsdb`;

-- Annex A useful-life alignment for Annex D Other Equipment accounts.
UPDATE `account_codes`
SET `default_useful_life_years` = 3
WHERE `account_code` = '1.04.05.990.00';

UPDATE `account_codes`
SET `default_useful_life_years` = 10
WHERE `account_code` = '1.06.05.990.00';

UPDATE `account_codes`
SET `default_useful_life_years` = 5
WHERE `account_code` = '1.06.99.990.00';

UPDATE `account_codes`
SET `default_useful_life_years` = NULL
WHERE `account_code` IN (
    '1.06.05.991.00',
    '1.06.05.992.00',
    '1.06.99.991.00',
    '1.06.99.992.00',
    '5.05.01.050.99'
);
