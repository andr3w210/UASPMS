<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$batchId = 14;

$sql = "SELECT
            COALESCE(NULLIF(fund_code, ''), '(blank)') AS fund_code,
            COALESCE(NULLIF(fund_source, ''), '(blank)') AS fund_source,
            COALESCE(NULLIF(fund_number, ''), '(blank)') AS fund_number,
            COALESCE(NULLIF(account_code, ''), '(blank)') AS account_code,
            COALESCE(NULLIF(account_name, ''), '(blank)') AS account_name,
            COUNT(*) AS row_count,
            ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total_amount
        FROM rpcppe_batch_items
        WHERE batch_id = ?
        GROUP BY fund_code, fund_source, fund_number, account_code, account_name
        ORDER BY fund_code, account_code";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $batchId);
$stmt->execute();
$res = $stmt->get_result();

$outPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'rpcppe_batch14_fund_account_totals.csv';
$fh = fopen($outPath, 'w');
fputcsv($fh, ['fund_code','fund_source','fund_number','account_code','account_name','rows','total_amount']);

$grandRows = 0;
$grandTotal = 0.0;

echo "RPCPPE Batch 14 - Fund Code + Account Code Totals\n";
echo str_repeat('=', 130) . "\n";
echo "fund_code\tfund_source\tfund_number\taccount_code\taccount_name\trows\ttotal\n";

while ($row = $res->fetch_assoc()) {
    $grandRows += (int)$row['row_count'];
    $grandTotal += (float)$row['total_amount'];

    echo $row['fund_code'] . "\t" . $row['fund_source'] . "\t" . $row['fund_number'] . "\t" .
         $row['account_code'] . "\t" . $row['account_name'] . "\t" .
         $row['row_count'] . "\t" . number_format((float)$row['total_amount'], 2, '.', ',') . "\n";

    fputcsv($fh, [
        $row['fund_code'],
        $row['fund_source'],
        $row['fund_number'],
        $row['account_code'],
        $row['account_name'],
        $row['row_count'],
        number_format((float)$row['total_amount'], 2, '.', ''),
    ]);
}

echo str_repeat('-', 130) . "\n";
echo "TOTAL\t-\t-\t-\t-\t" . $grandRows . "\t" . number_format($grandTotal, 2, '.', ',') . "\n";

fputcsv($fh, []);
fputcsv($fh, ['TOTAL','','','','',$grandRows,number_format($grandTotal, 2, '.', '')]);

fclose($fh);

echo "\nExported CSV: " . $outPath . "\n";

$stmt->close();
$mysqli->close();
