<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) die("Connection failed\n");

// From the provided list image:
// - 22 IBM SPSS License rows
// - Philippine Consortium ... row
// - LMS Platform row
$ids = array_merge(range(18591, 18612), [18613, 18614]);
$idCsv = implode(',', $ids);

$res = $m->query("SELECT id,
                        ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                        LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),150) item_description
                 FROM rpcppe_batch_items
                 WHERE batch_id=14 AND id IN ($idCsv)
                 ORDER BY id");

$sum = 0.0;
$count = 0;
while ($r = $res->fetch_assoc()) {
    $count++;
    $sum += (float)$r['total'];
    echo $r['id'] . ' | ' . number_format((float)$r['total'],2) . ' | ' . $r['item_description'] . PHP_EOL;
}

echo PHP_EOL;
echo 'List rows: ' . $count . PHP_EOL;
echo 'List total: ' . number_format($sum,2) . PHP_EOL;
echo 'Target (earlier): 7,000,000.00' . PHP_EOL;
echo 'Delta vs 7,000,000.00: ' . number_format($sum - 7000000.00,2) . PHP_EOL;

$cur = $m->query("SELECT COUNT(*) c,
                         COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) t
                  FROM rpcppe_batch_items
                  WHERE batch_id=14
                    AND account_code='1.08.01.020.00'
                    AND fund_source='01'
                    AND fund_number='1'")->fetch_assoc();

echo 'Current Fund01 Computer Software rows: ' . $cur['c'] . PHP_EOL;
echo 'Current Fund01 Computer Software total: ' . number_format((float)$cur['t'],2) . PHP_EOL;

echo 'Delta current vs list total: ' . number_format((float)$cur['t'] - $sum,2) . PHP_EOL;

$m->close();
