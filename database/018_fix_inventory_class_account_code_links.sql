USE `spamsdb`;

UPDATE `classifications` c
INNER JOIN `account_codes` ac ON ac.`account_code` = '1.06.05.030.00'
SET c.`account_code_id` = ac.`id`
WHERE c.`classification_code` = 'CLS-2026-0001';

UPDATE `classifications` c
INNER JOIN `account_codes` ac ON ac.`account_code` = '1.06.05.020.00'
SET c.`account_code_id` = ac.`id`
WHERE c.`classification_code` = 'CLS-2026-0002';
