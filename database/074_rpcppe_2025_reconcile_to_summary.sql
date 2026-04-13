USE `spamsdb`;

-- Remove previous auto-adjustments, if any.
DELETE FROM `legacy_assets`
WHERE `system_reference` = 'RPCPPE2025-ACCT-SUB'
  AND `item_description` LIKE 'RPCPPE 2025 Reconciliation Adjustment %';

-- Target totals from submitted RPCPPE 2025 summary.
DROP TEMPORARY TABLE IF EXISTS `tmp_rpcppe_2025_targets`;
CREATE TEMPORARY TABLE `tmp_rpcppe_2025_targets` (
    `fund_source` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `account_code` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `target_amount` DECIMAL(18,2) NOT NULL
);

INSERT INTO `tmp_rpcppe_2025_targets` (`fund_source`, `account_code`, `target_amount`) VALUES
('01','1.06.01.010.00',24522936.00),
('01','1.06.02.990.00',37535052.26),
('01','1.06.03.040.00',559659.66),
('01','1.06.03.050.00',4688170.92),
('01','1.06.04.010.00',146379514.73),
('01','1.06.04.020.00',181058566.73),
('01','1.06.04.990.00',26862983.60),
('01','1.06.05.010.00',11853860.00),
('01','1.06.05.020.00',12344704.00),
('01','1.06.05.030.00',18343297.00),
('01','1.06.05.070.00',391800.00),
('01','1.06.05.090.00',889920.00),
('01','1.06.05.130.00',464600.00),
('01','1.06.05.140.00',19159114.48),
('01','1.06.05.990.00',14859918.75),
('01','1.06.06.010.00',1640000.00),
('01','1.06.07.010.00',923555.90),
('01','1.08.01.020.00',7000000.00),

('05','1.06.01.010.00',12000000.00),
('05','1.06.02.990.00',12251471.42),
('05','1.06.04.010.00',26027285.00),
('05','1.06.04.020.00',50839255.35),
('05','1.06.04.990.00',22198580.57),
('05','1.06.03.040.00',2226131.61),
('05','1.06.03.050.00',12710009.13),
('05','1.06.05.010.00',1856538.00),
('05','1.06.05.020.00',19961019.00),
('05','1.06.05.030.00',17402861.73),
('05','1.06.05.070.00',1520860.40),
('05','1.06.05.090.00',645000.00),
('05','1.06.05.130.00',264800.00),
('05','1.06.05.110.00',579249.67),
('05','1.06.05.140.00',47198308.98),
('05','1.06.05.990.00',9155563.28),
('05','1.06.06.010.00',12842000.00),
('05','1.06.07.010.00',14641371.80),
('05','1.08.01.020.00',9700937.00),

('07','1.06.04.010.00',9954264.93),
('07','1.06.05.140.00',90376646.00),
('07','1.06.05.990.00',58272.00),

('06','1.06.05.020.00',55000.00),
('06','1.06.05.990.00',347440.00);

DROP TEMPORARY TABLE IF EXISTS `tmp_rpcppe_2025_current`;
CREATE TEMPORARY TABLE `tmp_rpcppe_2025_current` AS
SELECT
    COALESCE(f.fund_source, '') COLLATE utf8mb4_unicode_ci AS fund_source,
    ac.account_code COLLATE utf8mb4_unicode_ci AS account_code,
    ROUND(SUM(la.unit_cost), 2) AS current_amount
FROM legacy_assets la
LEFT JOIN account_codes ac ON ac.id = la.account_code_id
LEFT JOIN funds f ON f.id = la.fund_id
WHERE la.system_reference = 'RPCPPE2025-ACCT-SUB'
GROUP BY COALESCE(f.fund_source, ''), ac.account_code;

DROP TEMPORARY TABLE IF EXISTS `tmp_rpcppe_2025_diffs`;
CREATE TEMPORARY TABLE `tmp_rpcppe_2025_diffs` AS
SELECT
    t.fund_source,
    t.account_code,
    t.target_amount,
    COALESCE(c.current_amount, 0.00) AS current_amount,
    ROUND(t.target_amount - COALESCE(c.current_amount, 0.00), 2) AS diff_amount
FROM tmp_rpcppe_2025_targets t
LEFT JOIN tmp_rpcppe_2025_current c
    ON c.fund_source = t.fund_source
   AND c.account_code = t.account_code;

-- Insert adjustment rows for any non-zero diff.
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
    CONCAT('ADJ-2025-', d.fund_source, '-', REPLACE(d.account_code, '.', '')),
    'equipment',
    CONCAT('RPCPPE 2025 Reconciliation Adjustment ', d.fund_source, ' ', d.account_code),
    ac.id,
    (SELECT f2.id FROM funds f2 WHERE f2.fund_source COLLATE utf8mb4_unicode_ci = d.fund_source COLLATE utf8mb4_unicode_ci ORDER BY f2.id ASC LIMIT 1),
    d.diff_amount,
    d.diff_amount,
    1,
    'RPCPPE 2025 Summary Reconciliation (auto-generated).',
    1,
    'RPCPPE2025-ACCT-SUB'
FROM tmp_rpcppe_2025_diffs d
INNER JOIN account_codes ac ON ac.account_code COLLATE utf8mb4_unicode_ci = d.account_code COLLATE utf8mb4_unicode_ci
WHERE ABS(d.diff_amount) >= 0.01;

-- Final check: target vs actual after adjustments.
SELECT
    t.fund_source,
    t.account_code,
    t.target_amount,
    ROUND(COALESCE(SUM(la.unit_cost), 0.00), 2) AS actual_after_adjustment,
    ROUND(t.target_amount - COALESCE(SUM(la.unit_cost), 0.00), 2) AS remaining_diff
FROM tmp_rpcppe_2025_targets t
LEFT JOIN account_codes ac ON ac.account_code COLLATE utf8mb4_unicode_ci = t.account_code COLLATE utf8mb4_unicode_ci
LEFT JOIN legacy_assets la ON la.account_code_id = ac.id
LEFT JOIN funds f ON f.id = la.fund_id
    AND f.fund_source COLLATE utf8mb4_unicode_ci = t.fund_source COLLATE utf8mb4_unicode_ci
WHERE la.system_reference = 'RPCPPE2025-ACCT-SUB'
GROUP BY t.fund_source, t.account_code, t.target_amount
ORDER BY t.fund_source, t.account_code;
