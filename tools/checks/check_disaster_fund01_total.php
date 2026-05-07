<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) {
    die('Connection failed: ' . $m->connect_error . PHP_EOL);
}

$sql = "SELECT
            COUNT(*) AS row_count,
            ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND fund_source = '01'
          AND fund_number = '1'
          AND account_code = '1.06.05.090.00'";

$r = $m->query($sql)->fetch_assoc();

echo 'rows=' . $r['row_count'] . PHP_EOL;
echo 'total=' . number_format((float)$r['total'], 2) . PHP_EOL;
echo 'target=889,920.00' . PHP_EOL;
echo 'delta=' . number_format((float)$r['total'] - 889920.00, 2) . PHP_EOL;

// Show line items for quick audit
$q = "SELECT id, item_description, serial_no,
             ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1), 2) AS line_total
      FROM rpcppe_batch_items
      WHERE batch_id = 14
        AND fund_source = '01'
        AND fund_number = '1'
        AND account_code = '1.06.05.090.00'
      ORDER BY id";
$res = $m->query($q);
while ($row = $res->fetch_assoc()) {
    echo 'ID ' . $row['id'] . ' | ' . number_format((float)$row['line_total'], 2) . ' | SN ' . $row['serial_no'] . ' | ' . $row['item_description'] . PHP_EOL;
}

$m->close();
