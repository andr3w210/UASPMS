<?php
/**
 * Renumber all system assets so property numbers are sequential
 * per account-code prefix (YYYY-FF-AA.AAA) across ALL offices,
 * ordered by distribution_item_details.id ASC within each bucket.
 *
 * Format kept: YYYY-FF-AA.AAA-NNNN-OFFICECODE
 * Counter shared across ALL offices for the same prefix.
 * Year reset is implicit (year is part of prefix).
 *
 * Also syncs rpcppe_batch_items and rebuilds series_numbers.
 */

require_once __DIR__ . '/../bootstrap.php';

$apply = in_array('--apply', $argv, true);

$db = tools_db();
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to update property numbers, sync RPCPPE batch items, and rebuild series numbers." . PHP_EOL;
}

// --- 1. Fetch all rows: prefix derived from FULL account code (not stored pn) -
// Segments 3+4+5 of account code (e.g. 5.02.03.210.02 → 03.210.02) are used
// so each sub-type account code gets its own independent series bucket.
$rowsRes = $db->query(
    "SELECT
         did.id,
         did.property_number,
         DATE_FORMAT(COALESCE(d.distribution_date, rec.received_date, NOW()), '%Y') AS yr,
         TRIM(COALESCE(f.fund_source, f.fund_code, ''))                           AS fund_raw,
         COALESCE(ac.account_code, '')                                             AS account_code,
         UPPER(TRIM(COALESCE(o.office_code, o2.office_code, 'GEN')))              AS office_short
     FROM distribution_item_details did
     LEFT JOIN distribution_items di    ON di.id   = did.distribution_item_id
     LEFT JOIN distributions d          ON d.id    = di.distribution_id
     LEFT JOIN offices o                ON o.id    = d.office_id
     LEFT JOIN offices o2               ON o2.id   = did.current_office_id
     LEFT JOIN receiving_items ri       ON ri.id   = di.receiving_item_id
     LEFT JOIN receivings rec           ON rec.id  = ri.receiving_id
     LEFT JOIN purchase_order_items poi ON poi.id  = ri.purchase_order_item_id
     LEFT JOIN account_codes ac         ON ac.id   = poi.account_code_id
     LEFT JOIN purchase_orders po       ON po.id   = poi.purchase_order_id
     LEFT JOIN funds f                  ON f.id    = po.fund_id
     WHERE COALESCE(poi.item_type, '') IN ('equipment', 'semi_expendable')
     ORDER BY did.id ASC"
);
if (!$rowsRes) {
    fwrite(STDERR, "Query error: {$db->error}\n");
    exit(1);
}

function build_acct_short(string $accountCode): string {
    // Semi-expendable (03.210.xx): use segments 3+4+5 → 03.210.01/02/03
    // Other equipment: use segments 3+4 → 05.030/140/990
    $p = explode('.', $accountCode);
    
    // Check if semi-expendable (contains 03.210)
    if (isset($p[2]) && isset($p[3]) && $p[2] === '03' && $p[3] === '210') {
        if (isset($p[4])) {
            return $p[2] . '.' . $p[3] . '.' . $p[4];
        }
    }
    
    // Default: use segments 3+4
    if (isset($p[2]) && isset($p[3])) {
        return $p[2] . '.' . $p[3];
    }
    
    return $accountCode !== '' ? $accountCode : 'GEN';
}

$byPrefix = []; // prefix => [ [id, old_pn, office], ... ]
while ($row = $rowsRes->fetch_assoc()) {
    $yr      = $row['yr'] ?? date('Y');
    $fundRaw = preg_replace('/[^0-9]/', '', $row['fund_raw'] ?? '');
    $fundSeg = $fundRaw !== '' ? str_pad(substr($fundRaw, -2), 2, '0', STR_PAD_LEFT) : 'GEN';
    $acctShort = build_acct_short((string) $row['account_code']);
    $prefix  = $yr . '-' . $fundSeg . '-' . $acctShort;
    $office  = trim($row['office_short']);
    if ($office === '') { $office = 'GEN'; }

    $byPrefix[$prefix][] = [
        'id'     => (int) $row['id'],
        'old'    => (string) $row['property_number'],
        'office' => $office,
    ];
}
$rowsRes->free();

echo 'Buckets found: ' . count($byPrefix) . PHP_EOL;

$totalChanged  = 0;
$totalBuckets  = 0;

$updateStmt = $db->prepare(
    "UPDATE distribution_item_details SET property_number = ? WHERE id = ?"
);
if (!$updateStmt) {
    fwrite(STDERR, "Prepare error: {$db->error}\n");
    exit(1);
}

// --- 2. Renumber: one shared sequence per prefix, office suffix kept -----
foreach ($byPrefix as $prefix => $items) {
    $seq           = 0;
    $bucketChanged = 0;
    foreach ($items as $item) {
        $seq++;
        $newPn = $prefix
               . '-' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)
               . '-' . $item['office'];
        if ($item['old'] === $newPn) {
            continue;
        }
        if ($apply) {
            $updateStmt->bind_param('si', $newPn, $item['id']);
            $updateStmt->execute();
        }
        $bucketChanged++;
    }
    if ($bucketChanged > 0) {
        $totalBuckets++;
        $totalChanged += $bucketChanged;
    }
}
$updateStmt->close();

echo ($apply ? 'Rows changed: ' : 'Rows that would change: ') . $totalChanged . PHP_EOL;
echo ($apply ? 'Buckets changed: ' : 'Buckets that would change: ') . $totalBuckets . PHP_EOL;

// --- 3. Sync rpcppe_batch_items ------------------------------------------
if ($apply) {
    $db->query(
        "UPDATE rpcppe_batch_items rbi
         INNER JOIN distribution_item_details did
             ON did.id = rbi.distribution_item_detail_id
         SET rbi.property_number = did.property_number
         WHERE rbi.source_type = 'system'"
    );
    echo 'rpcppe_batch_items synced: ' . $db->affected_rows . PHP_EOL;
} else {
    echo 'rpcppe_batch_items sync skipped in dry run.' . PHP_EOL;
}

// --- 4. Rebuild series_numbers per prefix --------------------------------
// seq_no is the 4th dash-segment (position between 3rd dash and office suffix)
if ($apply) {
    $db->query("DELETE FROM series_numbers WHERE module_key LIKE 'property_number|%'");

    $db->query(
        "INSERT INTO series_numbers (module_key, prefix, year_value, current_value, padding_length)
         SELECT
             CONCAT('property_number|', src.prefix) AS module_key,
             src.prefix                              AS prefix,
             NULL                                   AS year_value,
             MAX(src.seq_no)                        AS current_value,
             4                                      AS padding_length
         FROM (
             SELECT
                 SUBSTRING_INDEX(property_number, '-', 3) AS prefix,
                 CAST(
                     SUBSTRING_INDEX(SUBSTRING_INDEX(property_number, '-', 4), '-', -1)
                 AS UNSIGNED) AS seq_no
             FROM distribution_item_details
             WHERE property_number
                   REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+-[0-9]+-[A-Za-z0-9]+'
             UNION ALL
             SELECT
                 SUBSTRING_INDEX(property_number, '-', 3) AS prefix,
                 CAST(
                     SUBSTRING_INDEX(SUBSTRING_INDEX(property_number, '-', 4), '-', -1)
                 AS UNSIGNED) AS seq_no
             FROM legacy_assets
             WHERE property_number
                   REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+-[0-9]+-[A-Za-z0-9]+'
         ) AS src
         WHERE src.prefix REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+$'
           AND src.seq_no > 0
         GROUP BY src.prefix"
    );

    $cr = $db->query("SELECT COUNT(*) AS c FROM series_numbers WHERE module_key LIKE 'property_number|%'");
    $seriesRows = $cr ? (int) $cr->fetch_assoc()['c'] : 0;
    echo 'series_numbers rebuilt: ' . $seriesRows . PHP_EOL;
} else {
    echo 'series_numbers rebuild skipped in dry run.' . PHP_EOL;
}

// --- 5. Sample -----------------------------------------------------------
$sample = $db->query(
    "SELECT property_number FROM distribution_item_details
     WHERE property_number REGEXP '^[0-9]{4}-[0-9]{2}'
     ORDER BY property_number, id LIMIT 15"
);
echo PHP_EOL . 'Sample property numbers after renumber:' . PHP_EOL;
if ($sample) {
    while ($r = $sample->fetch_assoc()) {
        echo '  ' . $r['property_number'] . PHP_EOL;
    }
}

$db->close();
echo PHP_EOL . 'Done.' . PHP_EOL;
