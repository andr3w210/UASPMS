<?php

require_once __DIR__ . '/../bootstrap.php';

$db = tools_db();
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

$sql = <<<SQL
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
SQL;

$db->begin_transaction();
try {
    if (!$db->multi_query($sql)) {
        throw new RuntimeException('Unable to run property-number series backfill: ' . $db->error);
    }

    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());

    $verify = $db->query("SELECT COUNT(*) AS total_rows FROM series_numbers WHERE module_key LIKE 'property_number|%'");
    $totalRows = 0;
    if ($verify) {
        $verifyRow = $verify->fetch_assoc();
        $totalRows = (int) ($verifyRow['total_rows'] ?? 0);
    }

    $sampleRows = [];
    $sampleRes = $db->query("SELECT module_key, prefix, current_value FROM series_numbers WHERE module_key LIKE 'property_number|%' ORDER BY module_key LIMIT 10");
    if ($sampleRes) {
        while ($row = $sampleRes->fetch_assoc()) {
            $sampleRows[] = $row;
        }
    }

    $db->commit();

    echo "Property-number series backfill applied successfully\n";
    echo "Rebuilt series rows: {$totalRows}\n";
    foreach ($sampleRows as $row) {
        echo "- {$row['module_key']} | prefix={$row['prefix']} | current_value={$row['current_value']}\n";
    }
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$db->close();
