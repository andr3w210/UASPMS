START TRANSACTION;

DELETE FROM series_numbers
WHERE module_key LIKE 'property_number|%';

INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
SELECT
    CONCAT('property_number|', src.year_value) AS module_key,
    src.year_value AS prefix,
    CAST(src.year_value AS UNSIGNED) AS year_value,
    MAX(src.seq_no) AS current_value,
    4 AS padding_length
FROM (
    SELECT
        SUBSTRING_INDEX(did.property_number, '-', 1) AS year_value,
        CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(did.property_number, '-', 4), '-', -1) AS UNSIGNED) AS seq_no
    FROM distribution_item_details did
    WHERE did.property_number REGEXP '^[0-9]{4}-'
) AS src
WHERE src.year_value REGEXP '^[0-9]{4}$'
GROUP BY src.year_value;

COMMIT;
