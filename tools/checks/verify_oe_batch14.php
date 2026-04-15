<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');

// Check Office Equipment total for batch 14 specifically
$query = "SELECT COUNT(*) as cnt, SUM(unit_cost * qty_physical_count) as total
         FROM rpcppe_batch_items 
         WHERE account_code = '1.06.05.020.00' AND batch_id = 14";
$result = $mysqli->query($query);
$row = $result->fetch_assoc();

echo "Fund 01 Office Equipment - Batch 14 Only:\n";
echo "  Rows: " . $row['cnt'] . "\n";
echo "  Total: " . number_format($row['total'], 2) . " PHP\n\n";

// Calculate gap to expected
$expected = 12344704.00;
$gap = $expected - $row['total'];
echo "Expected: " . number_format($expected, 2) . " PHP\n";
echo "Gap: " . number_format($gap, 2) . " PHP\n";
echo "Gap %: " . round(($gap / $expected) * 100, 2) . "%\n\n";

// Check batch info
$batchQuery = "SELECT * FROM rpcppe_batches WHERE id = 14";
$batchResult = $mysqli->query($batchQuery);
$batch = $batchResult->fetch_assoc();
echo "Batch 14 Info:\n";
echo "  Fund: " . $batch['fund_number'] . "\n";
echo "  Total Items: " . $batch['total_items'] . "\n";
echo "  Total Value: " . number_format($batch['total_value'], 2) . " PHP\n";

$mysqli->close();
?>
