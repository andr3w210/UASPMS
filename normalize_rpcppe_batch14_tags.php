<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$batchId = 14;
$updates = [];

$oeSql = "UPDATE rpcppe_batch_items
          SET fund_code = 'GAA-AEP',
              fund_source = '01',
              fund_number = '1',
              updated_at = NOW()
          WHERE batch_id = 14
            AND remarks LIKE '%RPCPPE_2025_LIST%'
            AND account_code = '1.06.05.020.00'
            AND (COALESCE(fund_code, '') <> 'GAA-AEP'
                 OR COALESCE(fund_source, '') <> '01'
                 OR COALESCE(fund_number, '') <> '1')";
$updates[] = ['label' => 'Normalize OE authoritative list fund fields', 'sql' => $oeSql];

$ictSql = "UPDATE rpcppe_batch_items
           SET remarks = CASE
                WHEN remarks IS NULL OR remarks = '' THEN 'RPCPPE_2025_ICT_FUND01_LIST'
                WHEN remarks LIKE '%RPCPPE_2025_ICT_FUND01_LIST%' THEN remarks
                ELSE CONCAT(remarks, ' | RPCPPE_2025_ICT_FUND01_LIST')
           END,
           updated_at = NOW()
           WHERE batch_id = 14
             AND remarks LIKE '%RCPPEE_2025_ICT_FUND01_LIST%'
             AND remarks NOT LIKE '%RPCPPE_2025_ICT_FUND01_LIST%'";
$updates[] = ['label' => 'Append canonical ICT tag alongside legacy RCPPEE tag', 'sql' => $ictSql];

$commSql = "UPDATE rpcppe_batch_items
            SET remarks = CASE
                 WHEN remarks IS NULL OR remarks = '' THEN 'RPCPPE_2025_COMM_FUND01_LIST'
                 WHEN remarks LIKE '%RPCPPE_2025_COMM_FUND01_LIST%' THEN remarks
                 ELSE CONCAT(remarks, ' | RPCPPE_2025_COMM_FUND01_LIST')
            END,
            updated_at = NOW()
            WHERE batch_id = 14
              AND remarks LIKE '%RCPPEE_2025_COMM_FUND01_LIST%'
              AND remarks NOT LIKE '%RPCPPE_2025_COMM_FUND01_LIST%'";
$updates[] = ['label' => 'Append canonical COMM tag alongside legacy RCPPEE tag', 'sql' => $commSql];

$m->begin_transaction();
try {
    foreach ($updates as &$update) {
        $m->query($update['sql']);
        if ($m->errno) {
            throw new RuntimeException($m->error);
        }
        $update['affected_rows'] = $m->affected_rows;
    }
    unset($update);

    $oe = $m->query("SELECT COUNT(*) AS row_count,
                            ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                     FROM rpcppe_batch_items
                     WHERE batch_id = 14
                       AND remarks LIKE '%RPCPPE_2025_LIST%'")->fetch_assoc();

    $ict = $m->query("SELECT COUNT(*) AS row_count,
                             ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                      FROM rpcppe_batch_items
                      WHERE batch_id = 14
                        AND remarks LIKE '%RPCPPE_2025_ICT_FUND01_LIST%'")->fetch_assoc();

    $comm = $m->query("SELECT COUNT(*) AS row_count,
                              ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
                       FROM rpcppe_batch_items
                       WHERE batch_id = 14
                         AND remarks LIKE '%RPCPPE_2025_COMM_FUND01_LIST%'")->fetch_assoc();

    $m->commit();

    echo "Applied updates:\n";
    foreach ($updates as $update) {
        echo "- {$update['label']}: {$update['affected_rows']} row(s)\n";
        echo "  SQL: {$update['sql']}\n";
    }

    echo "\nVerification snapshots:\n";
    echo "- RPCPPE_2025_LIST rows: {$oe['row_count']} | total: " . number_format((float)$oe['total'], 2) . "\n";
    echo "- RPCPPE_2025_ICT_FUND01_LIST rows: {$ict['row_count']} | total: " . number_format((float)$ict['total'], 2) . "\n";
    echo "- RPCPPE_2025_COMM_FUND01_LIST rows: {$comm['row_count']} | total: " . number_format((float)$comm['total'], 2) . "\n";
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

$m->close();
