<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Export final Office Equipment 01 audit after reconciliation.
Outputs CSV with 151 rows expected and total 12,344,704.00.
*/

$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'oe01_final_audit.csv';
$fh = fopen($outPath, 'w');
if ($fh === false) {
    die('Unable to open output file: ' . $outPath . PHP_EOL);
}

fputcsv($fh, [
    'id',
    'batch_id',
    'account_code',
    'account_name',
    'property_number',
    'classification_name',
    'item_description',
    'brand',
    'model',
    'serial_no',
    'qty_physical_count',
    'qty_property_card',
    'unit_cost',
    'line_total',
    'acquisition_date',
    'office_name',
]);

$sql = "SELECT id, batch_id, account_code, account_name, property_number, classification_name,
               item_description, brand, model, serial_no, qty_physical_count, qty_property_card,
               unit_cost,
               ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1), 2) AS line_total,
               acquisition_date, office_name
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND account_code = '1.06.05.020.00'
        ORDER BY id";

$res = $mysqli->query($sql);
if (!$res) {
    fclose($fh);
    die('Query failed: ' . $mysqli->error . PHP_EOL);
}

$rowCount = 0;
$grandTotal = 0.0;
while ($row = $res->fetch_assoc()) {
    $rowCount++;
    $grandTotal += (float)$row['line_total'];
    fputcsv($fh, [
        $row['id'],
        $row['batch_id'],
        $row['account_code'],
        $row['account_name'],
        $row['property_number'],
        $row['classification_name'],
        $row['item_description'],
        $row['brand'],
        $row['model'],
        $row['serial_no'],
        $row['qty_physical_count'],
        $row['qty_property_card'],
        number_format((float)$row['unit_cost'], 2, '.', ''),
        number_format((float)$row['line_total'], 2, '.', ''),
        $row['acquisition_date'],
        $row['office_name'],
    ]);
}

fclose($fh);

$expectedRows = 151;
$expectedTotal = 12344704.00;

echo 'Export: ' . $outPath . PHP_EOL;
echo 'Rows: ' . $rowCount . PHP_EOL;
echo 'Total: ' . number_format($grandTotal, 2) . PHP_EOL;
echo 'Expected Rows: ' . $expectedRows . PHP_EOL;
echo 'Expected Total: ' . number_format($expectedTotal, 2) . PHP_EOL;
echo 'Rows Delta: ' . ($rowCount - $expectedRows) . PHP_EOL;
echo 'Total Delta: ' . number_format($grandTotal - $expectedTotal, 2) . PHP_EOL;

$mysqli->close();
