USE `spamsdb`;

-- Format required:
-- YEAR-FUND-ACCOUNT(2nd and 3rd)-SERIES(per year)-OFFICE
-- Implemented as:
-- YYYY-FF-AA.BBB-SSSS-OOO
-- where:
--   YYYY = YEAR(acquisition_date), default 2025
--   FF   = fund_source (01/05/06/07), default 00
--   AA.BBB = account code segments 3 and 4 from 1.06.AA.BBB.00
--   SSSS = running series per (year,fund,AA.BBB,office)
--   OOO  = office_id zero-padded (or 000 if null)

DROP TEMPORARY TABLE IF EXISTS tmp_rpcppe_prop_seq;
CREATE TEMPORARY TABLE tmp_rpcppe_prop_seq AS
SELECT
    t.id,
    t.yr,
    t.fund,
    t.acc_part,
    t.office_part,
    ROW_NUMBER() OVER (
        PARTITION BY t.yr, t.fund, t.acc_part, t.office_part
        ORDER BY t.id
    ) AS seq_no
FROM (
    SELECT
        la.id,
        COALESCE(YEAR(la.acquisition_date), 2025) AS yr,
        COALESCE(f.fund_source, '00') AS fund,
        CONCAT(
            SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 3), '.', -1),
            '.',
            SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 4), '.', -1)
        ) AS acc_part,
        LPAD(COALESCE(la.office_id, 0), 3, '0') AS office_part
    FROM legacy_assets la
    INNER JOIN account_codes ac ON ac.id = la.account_code_id
    LEFT JOIN funds f ON f.id = la.fund_id
    WHERE la.system_reference = 'RPCPPE2025-ACCT-SUB'
      AND la.item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %'
) t;

UPDATE legacy_assets la
INNER JOIN tmp_rpcppe_prop_seq s ON s.id = la.id
SET la.property_number = CONCAT(
    s.yr,
    '-',
    s.fund,
    '-',
    s.acc_part,
    '-',
    LPAD(s.seq_no, 4, '0'),
    '-',
    s.office_part
)
WHERE la.system_reference = 'RPCPPE2025-ACCT-SUB'
  AND la.item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %';

SELECT ROW_COUNT() AS rows_standardized;

SELECT
    la.id,
    la.property_number,
    ac.account_code,
    f.fund_source,
    la.office_id,
    LEFT(la.item_description, 70) AS sample_item
FROM legacy_assets la
INNER JOIN account_codes ac ON ac.id = la.account_code_id
LEFT JOIN funds f ON f.id = la.fund_id
WHERE la.system_reference = 'RPCPPE2025-ACCT-SUB'
  AND la.item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %'
ORDER BY la.id DESC
LIMIT 25;
