START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_property_number_fix_v2;

CREATE TEMPORARY TABLE tmp_property_number_fix_v2 AS
SELECT
    src.id,
    CONCAT(
        src.year_value, '-',
        src.fund_segment, '-',
        src.account_segment, '-',
        LPAD(src.seq_no, 4, '0'), '-',
        src.rc_short
    ) AS new_property_number
FROM (
    SELECT
        did.id,
        DATE_FORMAT(COALESCE(d.distribution_date, r.received_date, CURDATE()), '%Y') AS year_value,
        CASE
            WHEN TRIM(COALESCE(f.fund_source, '')) <> ''
                THEN LPAD(RIGHT(TRIM(f.fund_source), 2), 2, '0')
            ELSE 'GEN'
        END AS fund_segment,
        CASE
            WHEN COALESCE(ac.account_code, '') <> ''
                 AND (LENGTH(ac.account_code) - LENGTH(REPLACE(ac.account_code, '.', ''))) >= 3
            THEN CONCAT(
                SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 3), '.', -1),
                '.',
                SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 4), '.', -1)
            )
            WHEN COALESCE(ac.account_code, '') <> '' THEN ac.account_code
            ELSE '000.000'
        END AS account_segment,
        CASE
            WHEN TRIM(COALESCE(curr_rc.code, base_rc.code, '')) = '' THEN 'GEN'
            WHEN UPPER(TRIM(COALESCE(curr_rc.code, base_rc.code))) LIKE 'RC-%'
                THEN SUBSTRING(TRIM(COALESCE(curr_rc.code, base_rc.code)), 4)
            ELSE TRIM(COALESCE(curr_rc.code, base_rc.code))
        END AS rc_short,
        ROW_NUMBER() OVER (
            PARTITION BY
                DATE_FORMAT(COALESCE(d.distribution_date, r.received_date, CURDATE()), '%Y'),
                CASE
                    WHEN TRIM(COALESCE(f.fund_source, '')) <> ''
                        THEN LPAD(RIGHT(TRIM(f.fund_source), 2), 2, '0')
                    ELSE 'GEN'
                END,
                CASE
                    WHEN COALESCE(ac.account_code, '') <> ''
                         AND (LENGTH(ac.account_code) - LENGTH(REPLACE(ac.account_code, '.', ''))) >= 3
                    THEN CONCAT(
                        SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 3), '.', -1),
                        '.',
                        SUBSTRING_INDEX(SUBSTRING_INDEX(ac.account_code, '.', 4), '.', -1)
                    )
                    WHEN COALESCE(ac.account_code, '') <> '' THEN ac.account_code
                    ELSE '000.000'
                END,
                CASE
                    WHEN TRIM(COALESCE(curr_rc.code, base_rc.code, '')) = '' THEN 'GEN'
                    WHEN UPPER(TRIM(COALESCE(curr_rc.code, base_rc.code))) LIKE 'RC-%'
                        THEN SUBSTRING(TRIM(COALESCE(curr_rc.code, base_rc.code)), 4)
                    ELSE TRIM(COALESCE(curr_rc.code, base_rc.code))
                END
            ORDER BY did.id
        ) AS seq_no
    FROM distribution_item_details did
    INNER JOIN distribution_items di ON di.id = did.distribution_item_id
    INNER JOIN distributions d ON d.id = di.distribution_id
    LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
    LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
    LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
    LEFT JOIN receiving_items ri ON ri.id = COALESCE(rid.receiving_item_id, di.receiving_item_id)
    LEFT JOIN receivings r ON r.id = ri.receiving_id
    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
    WHERE COALESCE(poi.item_type, '') IN ('equipment', 'semi_expendable')
) AS src;

UPDATE distribution_item_details did
INNER JOIN tmp_property_number_fix_v2 t ON t.id = did.id
SET did.property_number = t.new_property_number
WHERE COALESCE(t.new_property_number, '') <> '';

DROP TEMPORARY TABLE IF EXISTS tmp_property_number_fix_v2;

DELETE FROM series_numbers
WHERE module_key LIKE 'property_number|%';

INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
SELECT
    CONCAT(
        'property_number|',
        SUBSTRING_INDEX(property_number, '-', 3),
        '|',
        SUBSTRING_INDEX(property_number, '-', -1)
    ) AS module_key,
    SUBSTRING_INDEX(property_number, '-', 3) AS prefix,
    NULL AS year_value,
    MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(property_number, '-', -2), '-', 1) AS UNSIGNED)) AS current_value,
    4 AS padding_length
FROM distribution_item_details
WHERE COALESCE(property_number, '') <> ''
GROUP BY
    SUBSTRING_INDEX(property_number, '-', 3),
    SUBSTRING_INDEX(property_number, '-', -1);

COMMIT;
