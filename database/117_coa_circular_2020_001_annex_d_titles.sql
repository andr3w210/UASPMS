USE `spamsdb`;

-- COA Circular No. 2020-001, Annex D: modified account titles.
-- Preserve account IDs and historical references; update titles only.
UPDATE `account_codes`
SET `account_name` = 'Other Equipment',
    `description` = 'COA Circular No. 2020-001, Annex D item 33'
WHERE `account_code` = '1.06.05.990.00';

UPDATE `account_codes`
SET `account_name` = 'Patents',
    `description` = 'COA Circular No. 2020-001, Annex D item 103'
WHERE `account_code` = '1.08.01.010.00';

UPDATE `account_codes`
SET `account_name` = 'Desilting, Drilling and Dredging Expenses',
    `description` = 'COA Circular No. 2020-001, Annex D item 133'
WHERE `account_code` = '5.02.08.020.00';

UPDATE `account_codes`
SET `account_name` = 'Advertising, Promotional and Marketing Expense',
    `description` = 'COA Circular No. 2020-001, Annex D item 140'
WHERE `account_code` = '5.02.99.010.00';
