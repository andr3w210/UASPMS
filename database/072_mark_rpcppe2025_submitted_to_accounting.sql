USE `spamsdb`;

UPDATE `legacy_assets`
SET
    `system_reference` = 'RPCPPE2025-ACCT-SUB',
    `remarks` = CASE
        WHEN `remarks` IS NULL OR TRIM(`remarks`) = '' THEN 'Marked: RPCPPE 2025 submitted to Accounting (2026-04-13)'
        WHEN `remarks` LIKE '%RPCPPE 2025 submitted to Accounting%' THEN `remarks`
        ELSE CONCAT(`remarks`, ' | Marked: RPCPPE 2025 submitted to Accounting (2026-04-13)')
    END,
    `is_active` = 1
WHERE
    -- Land rows inserted from RPCPPE 2025
    `property_number` IN (
        '1.06.01.010.00-001','1.06.01.010.00-002','1.06.01.010.00-003','1.06.01.010.00-004','1.06.01.010.00-005','1.06.01.010.00-006','1.06.01.010.00-007','1.06.01.010.00-008','1.06.01.010.00-009','1.06.01.010.00-010','1.06.01.010.00-011'
    )
    OR (`account_code_id` = 34 AND `item_description` = 'Title No. N-4814 8,670 sq. m.')

    -- Other Land Improvements rows inserted from RPCPPE 2025
    OR `property_number` IN (
        '1.06.02.990.00-004','1.06.02.990.00-005','1.06.02.990.00-006','1.06.02.990.00-007','1.06.02.990.00-008','1.06.02.990.00-009'
    )
    OR (`account_code_id` = 37 AND `item_description` IN (
        'Drainage System',
        'Rehab of UA drainage (side of Covered Gym) 2nd payment',
        'Const of pavement, pathwalk & drainage - A=230 sq.m.; front of Supply Office (9/21/18)',
        'Const of pavement, pathwalk & drainage - A=320 sq.m.; front of UA Canteen (9/21/18)',
        'Const of pavement, pathwalk & drainage - A=962 sq.m.; front of old GEB to Gate-2',
        'Land Improvement form Architecture Building to Gate 3 (Provision for Pathwalk)',
        'Landscaping of Old GEB Lawn - A=1,026.42 sq.m.',
        'Landscaping at the front of perimeter fence along national road (carabao grass front of center gate) - 327 sq.m.',
        'Construction of road network (pool area) - 749 sq.m. w/ slope protection',
        'Construction of sports training center phase 2 (retaining wall north side) - 299.3 sq. m.',
        'Perimeter fence and drainage system along national road phase IV (shed extension and walk rays)-Repair - 274.434 sq.m.; front gate'
    ));

SELECT
    COUNT(*) AS marked_count
FROM `legacy_assets`
WHERE `system_reference` = 'RPCPPE2025-ACCT-SUB';
