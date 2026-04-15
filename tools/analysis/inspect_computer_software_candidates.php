<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) die("Connection failed\n");

$sql = "SELECT id,
               account_code,
               fund_code,
               fund_source,
               fund_number,
               serial_no,
               ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) AS total,
               LEFT(REPLACE(REPLACE(item_description, '\\r', ' '), '\\n', ' '), 160) AS item_description
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND (
              account_code = '1.08.01.020.00'
              OR item_description LIKE '%software%'
              OR item_description LIKE '%license%'
              OR item_description LIKE '%subscription%'
              OR item_description LIKE '%system%'
          )
        ORDER BY id";

$res = $m->query($sql);
$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
    echo $r['id'] . ' | acct ' . $r['account_code']
        . ' | fund ' . $r['fund_code'] . '/' . $r['fund_source'] . '/' . $r['fund_number']
        . ' | ' . number_format((float)$r['total'], 2)
        . ' | SN ' . $r['serial_no']
        . ' | ' . $r['item_description'] . PHP_EOL;
}

echo PHP_EOL;
$sumByScope = [];
foreach ($rows as $r) {
    $k = ($r['fund_code'] ?? '') . '|' . ($r['fund_source'] ?? '') . '|' . ($r['fund_number'] ?? '') . '|' . ($r['account_code'] ?? '');
    if (!isset($sumByScope[$k])) $sumByScope[$k] = ['rows' => 0, 'total' => 0.0];
    $sumByScope[$k]['rows']++;
    $sumByScope[$k]['total'] += (float)$r['total'];
}

echo "--- GROUPED TOTALS ---" . PHP_EOL;
foreach ($sumByScope as $k => $v) {
    echo $k . ' | rows=' . $v['rows'] . ' | total=' . number_format($v['total'], 2) . PHP_EOL;
}

$m->close();
