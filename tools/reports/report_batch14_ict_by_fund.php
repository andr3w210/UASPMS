<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$sql = "SELECT
            COALESCE(NULLIF(fund_code,''),'(blank)') AS fund_code,
            COALESCE(NULLIF(fund_source,''),'(blank)') AS fund_source,
            COALESCE(NULLIF(fund_number,''),'(blank)') AS fund_number,
            account_code,
            account_name,
            COUNT(*) AS row_count,
            ROUND(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)), 2) AS total_amount
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND account_code = '1.06.05.030.00'
        GROUP BY fund_code, fund_source, fund_number, account_code, account_name
        ORDER BY fund_code";

$res = $mysqli->query($sql);

echo "Batch 14 ICT (1.06.05.030.00) by fund\n";
echo str_repeat('=', 110) . "\n";
echo "fund_code\tfund_source\tfund_number\trows\ttotal\n";

$grandRows = 0;
$grandTotal = 0.0;
while ($row = $res->fetch_assoc()) {
    $grandRows += (int)$row['row_count'];
    $grandTotal += (float)$row['total_amount'];
    echo $row['fund_code'] . "\t" . $row['fund_source'] . "\t" . $row['fund_number'] . "\t" .
         $row['row_count'] . "\t" . number_format((float)$row['total_amount'], 2, '.', ',') . "\n";
}

echo str_repeat('-', 110) . "\n";
echo "TOTAL\t-\t-\t" . $grandRows . "\t" . number_format($grandTotal, 2, '.', ',') . "\n";

$mysqli->close();
