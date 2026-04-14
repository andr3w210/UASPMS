<?php
/*
Export rows marked with RPCPPE_2025_LIST and provide totals:
- DB total (actual row qty)
- Normalized list total (treat ID 19065 qty as 1 based on provided list line)
*/

$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$tag = 'RPCPPE_2025_LIST';
$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'rpcppe2025_marked_list.csv';
$fh = fopen($outPath, 'w');
if ($fh === false) {
    die('Unable to open output file: ' . $outPath . PHP_EOL);
}

fputcsv($fh, [
    'id','batch_id','account_code','item_description','brand','model','serial_no',
    'qty_physical_count','unit_cost','line_total_db','line_total_normalized','remarks'
]);

$sql = "SELECT id, batch_id, account_code, item_description, brand, model, serial_no,
               COALESCE(NULLIF(qty_physical_count,0),1) AS qty,
               unit_cost,
               ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1), 2) AS line_total_db,
               remarks
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND remarks LIKE '%$tag%'
        ORDER BY id";

$res = $mysqli->query($sql);
if (!$res) {
    fclose($fh);
    die('Query failed: ' . $mysqli->error . PHP_EOL);
}

$count = 0;
$dbTotal = 0.0;
$normalizedTotal = 0.0;
while ($row = $res->fetch_assoc()) {
    $count++;
    $dbLine = (float)$row['line_total_db'];
    $dbTotal += $dbLine;

    // Normalize one known line to match the provided list total computation.
    $normQty = (int)$row['qty'];
    if ((int)$row['id'] === 19065) {
        $normQty = 1;
    }
    $normLine = round((float)$row['unit_cost'] * $normQty, 2);
    $normalizedTotal += $normLine;

    fputcsv($fh, [
        $row['id'],
        $row['batch_id'],
        $row['account_code'],
        $row['item_description'],
        $row['brand'],
        $row['model'],
        $row['serial_no'],
        $row['qty'],
        number_format((float)$row['unit_cost'], 2, '.', ''),
        number_format($dbLine, 2, '.', ''),
        number_format($normLine, 2, '.', ''),
        $row['remarks'],
    ]);
}

fclose($fh);

echo 'Export: ' . $outPath . PHP_EOL;
echo 'Tagged rows: ' . $count . PHP_EOL;
echo 'DB total: ' . number_format($dbTotal, 2) . PHP_EOL;
echo 'Normalized list total: ' . number_format($normalizedTotal, 2) . PHP_EOL;
echo 'Expected list total: 12,344,704.00' . PHP_EOL;
echo 'Normalized delta: ' . number_format($normalizedTotal - 12344704.00, 2) . PHP_EOL;

$mysqli->close();
