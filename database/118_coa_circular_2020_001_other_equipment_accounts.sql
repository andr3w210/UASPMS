USE `spamsdb`;

-- COA Circular No. 2020-001, Annex D: add missing Other Equipment accounts.
-- Existing account IDs and historical transaction references are preserved.
INSERT INTO `account_codes`
    (`account_code`, `account_name`, `account_group`, `description`, `is_active`)
VALUES
    ('1.04.05.990.00', 'Semi-Expendable Other Equipment', 'semi_expendable', 'COA Circular No. 2020-001, Annex D item 25', 1),
    ('1.06.05.991.00', 'Accumulated Depreciation - Other Equipment', 'asset', 'COA Circular No. 2020-001, Annex D item 34', 1),
    ('1.06.05.992.00', 'Accumulated Impairment Losses - Other Equipment', 'asset', 'COA Circular No. 2020-001, Annex D item 35', 1),
    ('1.06.99.991.00', 'Accumulated Depreciation - Other Property, Plant and Equipment', 'asset', 'COA Circular No. 2020-001, Annex D item 93', 1),
    ('1.06.99.992.00', 'Accumulated Impairment Losses - Other Property, Plant and Equipment', 'asset', 'COA Circular No. 2020-001, Annex D item 94', 1),
    ('5.05.01.050.99', 'Depreciation - Other Equipment', 'expense', 'COA Circular No. 2020-001, Annex D item 144', 1)
ON DUPLICATE KEY UPDATE
    `account_name` = VALUES(`account_name`),
    `account_group` = VALUES(`account_group`),
    `description` = VALUES(`description`),
    `is_active` = 1,
    `updated_at` = NOW();
