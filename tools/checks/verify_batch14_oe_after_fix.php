<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$sql = "SELECT account_code, account_name, COUNT(*) AS rows_count,
               ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total_amount
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND account_code = '1.06.05.020.00'
        GROUP BY account_code, account_name";

$res = $mysqli->query($sql);
$row = $res->fetch_assoc();

echo 'account_code: ' . $row['account_code'] . PHP_EOL;
echo 'account_name: ' . $row['account_name'] . PHP_EOL;
echo 'rows: ' . $row['rows_count'] . PHP_EOL;
echo 'total: ' . number_format((float)$row['total_amount'], 2) . PHP_EOL;

echo 'expected_total: 12,344,704.00' . PHP_EOL;
echo 'delta: ' . number_format((float)$row['total_amount'] - 12344704.00, 2) . PHP_EOL;

$mysqli->close();
