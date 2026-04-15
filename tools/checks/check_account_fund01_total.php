<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die('Connection failed: ' . $m->connect_error . PHP_EOL);
}

$accountCode = $argv[1] ?? '1.06.05.140.00';

$sql = "SELECT COUNT(*) AS row_count,
               ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND fund_source = '01'
          AND fund_number = '1'
          AND account_code = ?";

$stmt = $m->prepare($sql);
$stmt->bind_param('s', $accountCode);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();

echo 'account_code=' . $accountCode . PHP_EOL;
echo 'rows=' . $r['row_count'] . PHP_EOL;
echo 'total=' . number_format((float)$r['total'], 2) . PHP_EOL;

$stmt->close();
$m->close();
