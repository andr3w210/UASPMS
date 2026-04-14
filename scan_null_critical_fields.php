<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) die("Connection failed\n");

$q = "SELECT COUNT(*) c
      FROM rpcppe_batch_items
      WHERE batch_id=14
        AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)";
$r = $m->query($q)->fetch_assoc();
echo 'null_critical_rows=' . (int)$r['c'] . PHP_EOL;

$q2 = "SELECT id, property_number,
              account_code, account_name, fund_code, fund_source, fund_number,
              ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
              LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),120) item_description
       FROM rpcppe_batch_items
       WHERE batch_id=14
         AND (account_code IS NULL OR account_name IS NULL OR fund_code IS NULL OR fund_source IS NULL OR fund_number IS NULL)
       ORDER BY id";
$res = $m->query($q2);

$csv = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'batch14_null_critical_rows.csv';
$f = fopen($csv, 'w');
fputcsv($f, ['id','property_number','account_code','account_name','fund_code','fund_source','fund_number','total','item_description']);

$shown = 0;
while ($row = $res->fetch_assoc()) {
    fputcsv($f, [
        $row['id'],
        $row['property_number'],
        $row['account_code'],
        $row['account_name'],
        $row['fund_code'],
        $row['fund_source'],
        $row['fund_number'],
        $row['total'],
        $row['item_description'],
    ]);

    if ($shown < 60) {
        echo $row['id'] . ' | ' . ($row['account_code'] ?? '(null)')
            . ' | ' . ($row['fund_code'] ?? '') . '/' . ($row['fund_source'] ?? '') . '/' . ($row['fund_number'] ?? '')
            . ' | ' . number_format((float)$row['total'],2)
            . ' | ' . $row['item_description'] . PHP_EOL;
        $shown++;
    }
}
fclose($f);

echo 'Exported: ' . $csv . PHP_EOL;
$m->close();
