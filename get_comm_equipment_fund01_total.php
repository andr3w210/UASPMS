<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');

$sql = "SELECT COUNT(*) AS rows_count,
               ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND fund_source = '01'
          AND fund_number = '1'
          AND account_code = '1.06.05.070.00'";

$r = $m->query($sql)->fetch_assoc();

echo 'rows=' . $r['rows_count'] . PHP_EOL;
echo 'total=' . number_format((float)$r['total'], 2) . PHP_EOL;

$m->close();
