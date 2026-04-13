USE `spamsdb`;

-- RPCPPE reconciliation template
-- Steps:
-- 1) Set @rpcppe_tag and @rpcppe_note for this batch/year.
-- 2) Fill tmp_rpcppe_targets with summary totals (fund_source, account_code, target_amount).
-- 3) Run this script.
-- 4) Check final verification section (remaining_diff should be 0.00 per line).

SET @rpcppe_tag = 'RPCPPE2025-ACCT-SUB';
SET @rpcppe_note = 'RPCPPE summary reconciliation auto-adjustment';

-- Remove prior adjustment rows for this tag only.
DELETE FROM legacy_assets
WHERE system_reference = @rpcppe_tag
  AND item_description LIKE 'RPCPPE Reconciliation Adjustment %';

DROP TEMPORARY TABLE IF EXISTS tmp_rpcppe_targets;
CREATE TEMPORARY TABLE tmp_rpcppe_targets (
    fund_source VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    account_code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    target_amount DECIMAL(18,2) NOT NULL
);

-- Paste summary totals here.
-- Example rows:
-- INSERT INTO tmp_rpcppe_targets (fund_source, account_code, target_amount) VALUES
-- ('01', '1.06.01.010.00', 24522936.00),
-- ('05', '1.06.01.010.00', 12000000.00);

-- Guard: abort if no targets were provided.
DO CASE
    WHEN (SELECT COUNT(*) FROM tmp_rpcppe_targets) = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'tmp_rpcppe_targets is empty. Paste summary totals first.';
END CASE;

DROP TEMPORARY TABLE IF EXISTS tmp_rpcppe_current;
CREATE TEMPORARY TABLE tmp_rpcppe_current AS
SELECT
    COALESCE(f.fund_source, '') COLLATE utf8mb4_unicode_ci AS fund_source,
    ac.account_code COLLATE utf8mb4_unicode_ci AS account_code,
    ROUND(SUM(la.unit_cost), 2) AS current_amount
FROM legacy_assets la
LEFT JOIN account_codes ac ON ac.id = la.account_code_id
LEFT JOIN funds f ON f.id = la.fund_id
WHERE la.system_reference = @rpcppe_tag
GROUP BY COALESCE(f.fund_source, ''), ac.account_code;

DROP TEMPORARY TABLE IF EXISTS tmp_rpcppe_diffs;
CREATE TEMPORARY TABLE tmp_rpcppe_diffs AS
SELECT
    t.fund_source,
    t.account_code,
    t.target_amount,
    COALESCE(c.current_amount, 0.00) AS current_amount,
    ROUND(t.target_amount - COALESCE(c.current_amount, 0.00), 2) AS diff_amount
FROM tmp_rpcppe_targets t
LEFT JOIN tmp_rpcppe_current c
    ON c.fund_source = t.fund_source
   AND c.account_code = t.account_code;

INSERT INTO legacy_assets (
    property_number,
    item_type,
    item_description,
    account_code_id,
    fund_id,
    unit_cost,
    acquisition_cost,
    quantity,
    remarks,
    is_active,
    system_reference
)
SELECT
    CONCAT('ADJ-', REPLACE(@rpcppe_tag, ' ', ''), '-', d.fund_source, '-', REPLACE(d.account_code, '.', '')),
    'equipment',
    CONCAT('RPCPPE Reconciliation Adjustment ', d.fund_source, ' ', d.account_code),
    ac.id,
    (SELECT f2.id FROM funds f2 WHERE f2.fund_source COLLATE utf8mb4_unicode_ci = d.fund_source COLLATE utf8mb4_unicode_ci ORDER BY f2.id ASC LIMIT 1),
    d.diff_amount,
    d.diff_amount,
    1,
    @rpcppe_note,
    1,
    @rpcppe_tag
FROM tmp_rpcppe_diffs d
INNER JOIN account_codes ac ON ac.account_code COLLATE utf8mb4_unicode_ci = d.account_code COLLATE utf8mb4_unicode_ci
WHERE ABS(d.diff_amount) >= 0.01;

-- Final verification. remaining_diff should be 0.00 for every line.
SELECT
    t.fund_source,
    t.account_code,
    t.target_amount,
    ROUND(COALESCE(SUM(CASE WHEN f.fund_source COLLATE utf8mb4_unicode_ci = t.fund_source COLLATE utf8mb4_unicode_ci THEN la.unit_cost ELSE 0 END), 0.00), 2) AS actual_after_adjustment,
    ROUND(t.target_amount - COALESCE(SUM(CASE WHEN f.fund_source COLLATE utf8mb4_unicode_ci = t.fund_source COLLATE utf8mb4_unicode_ci THEN la.unit_cost ELSE 0 END), 0.00), 2) AS remaining_diff
FROM tmp_rpcppe_targets t
LEFT JOIN account_codes ac ON ac.account_code COLLATE utf8mb4_unicode_ci = t.account_code COLLATE utf8mb4_unicode_ci
LEFT JOIN legacy_assets la ON la.account_code_id = ac.id
LEFT JOIN funds f ON f.id = la.fund_id
WHERE la.system_reference = @rpcppe_tag
GROUP BY t.fund_source, t.account_code, t.target_amount
ORDER BY t.fund_source, t.account_code;
