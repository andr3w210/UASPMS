<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

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
