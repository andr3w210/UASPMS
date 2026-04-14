<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die('Connection failed: ' . $mysqli->connect_error . PHP_EOL);
}

$tag = 'RPCPPE_2025_LIST';

$sql = "SELECT
            COALESCE(NULLIF(fund_code, ''), '(blank)') AS fund_code,
            COALESCE(NULLIF(fund_source, ''), '(blank)') AS fund_source,
            COALESCE(NULLIF(fund_number, ''), '(blank)') AS fund_number,
            COALESCE(NULLIF(account_code, ''), '(blank)') AS account_code,
            COALESCE(NULLIF(account_name, ''), '(blank)') AS account_name,
            COUNT(*) AS row_count,
            ROUND(SUM(
                unit_cost * CASE
                    WHEN id = 19065 THEN 1
                    ELSE COALESCE(NULLIF(qty_physical_count,0),1)
                END
            ), 2) AS normalized_total
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND remarks LIKE CONCAT('%', ?, '%')
        GROUP BY fund_code, fund_source, fund_number, account_code, account_name
        ORDER BY fund_code, account_code";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('s', $tag);
$stmt->execute();
$res = $stmt->get_result();

$grandRows = 0;
$grandTotal = 0.0;

echo "RPCPPE 2025 Tagged Rows - Normalized Fund+Account Totals\n";
echo str_repeat('=', 118) . "\n";
echo "fund_code\tfund_source\tfund_number\taccount_code\taccount_name\trows\tnormalized_total\n";

while ($row = $res->fetch_assoc()) {
    $grandRows += (int)$row['row_count'];
    $grandTotal += (float)$row['normalized_total'];
    echo $row['fund_code'] . "\t" . $row['fund_source'] . "\t" . $row['fund_number'] . "\t" .
         $row['account_code'] . "\t" . $row['account_name'] . "\t" .
         $row['row_count'] . "\t" . number_format((float)$row['normalized_total'], 2, '.', ',') . "\n";
}

echo str_repeat('-', 118) . "\n";
echo "TOTAL\t-\t-\t-\t-\t" . $grandRows . "\t" . number_format($grandTotal, 2, '.', ',') . "\n";

$stmt->close();
$mysqli->close();
