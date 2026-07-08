<?php
require_once __DIR__ . '/../bootstrap.php';
$apply = in_array('--apply', $argv, true);

$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to persist batch 14 fund-number normalization." . PHP_EOL;
    $countSql = "SELECT COUNT(*) AS row_count
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND fund_source REGEXP '^[0-9]+$'
          AND (fund_number IS NULL OR fund_number = '' OR fund_number REGEXP '^0+[0-9]+$')";
    $countRes = $m->query($countSql);
    $countRow = $countRes ? $countRes->fetch_assoc() : ['row_count' => 0];
    echo 'rows_that_would_normalize=' . (int) ($countRow['row_count'] ?? 0) . PHP_EOL;
    $m->close();
    exit(0);
}

$batchId = 14;

$sql = "UPDATE rpcppe_batch_items
        SET fund_number = CAST(CAST(fund_source AS UNSIGNED) AS CHAR),
            updated_at = NOW()
        WHERE batch_id = $batchId
          AND fund_source REGEXP '^[0-9]+$'
          AND (fund_number IS NULL OR fund_number = '' OR fund_number REGEXP '^0+[0-9]+$')";
$m->query($sql);
echo 'normalized_rows=' . $m->affected_rows . PHP_EOL;

$m->close();
