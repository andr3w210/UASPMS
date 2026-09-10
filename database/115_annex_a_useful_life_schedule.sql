USE `spamsdb`;

-- Annex A useful-life schedule: Sports Equipment = 10 years;
-- Other Property, Plant and Equipment = 5 years.
-- Classification-specific values remain authoritative when a more specific
-- asset type or lease term is available.

UPDATE `account_codes`
SET `default_useful_life_years` = 10,
    `updated_at` = NOW()
WHERE `account_code` = '1.06.05.130.00'
  AND `account_group` = 'asset';

UPDATE `account_codes`
SET `default_useful_life_years` = 5,
    `updated_at` = NOW()
WHERE `account_code` = '1.06.99.990.00'
  AND `account_group` = 'asset';

UPDATE `classifications` c
INNER JOIN `account_codes` ac ON ac.id = c.account_code_id
SET c.useful_life_years = 10
WHERE c.classification_group = 'asset'
  AND ac.account_code = '1.06.05.130.00';

UPDATE `classifications` c
INNER JOIN `account_codes` ac ON ac.id = c.account_code_id
SET c.useful_life_years = 5
WHERE c.classification_group = 'asset'
  AND ac.account_code = '1.06.99.990.00';
