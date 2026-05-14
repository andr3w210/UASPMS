START TRANSACTION;

DELETE FROM series_numbers
WHERE module_key LIKE 'property_number|%';

INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
SELECT
    CONCAT('property_number|', src.prefix) AS module_key,
    src.prefix AS prefix,
    NULL AS year_value,
    MAX(src.seq_no) AS current_value,
    4 AS padding_length
FROM (
    SELECT
        SUBSTRING_INDEX(did.property_number, '-', 3) AS prefix,
        CAST(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(did.property_number, '-', 4),
                '-',
                -1
            ) AS UNSIGNED
        ) AS seq_no
    FROM distribution_item_details did
    WHERE COALESCE(did.property_number, '') <> ''

    UNION ALL

    SELECT
        SUBSTRING_INDEX(la.property_number, '-', 3) AS prefix,
        CAST(
            SUBSTRING_INDEX(
                SUBSTRING_INDEX(la.property_number, '-', 4),
                '-',
                -1
            ) AS UNSIGNED
        ) AS seq_no
    FROM legacy_assets la
    WHERE COALESCE(la.property_number, '') <> ''
) AS src
WHERE COALESCE(src.prefix, '') <> ''
    AND src.prefix REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+$'
  AND COALESCE(src.seq_no, 0) > 0
GROUP BY src.prefix;

COMMIT;