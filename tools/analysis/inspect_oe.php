<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();

// List all items currently in Office Equipment (batch 14)
$query = "SELECT id, serial_no, brand, model, qty_physical_count, unit_cost, 
                 (unit_cost * qty_physical_count) as total_amount, item_description
         FROM rpcppe_batch_items 
         WHERE account_code = '1.06.05.020.00' AND batch_id = 14
         ORDER BY id
         LIMIT 50";
$result = $mysqli->query($query);

echo "Sample of items in Office Equipment (1.06.05.020.00) - first 50:\n";
echo str_repeat("=", 150) . "\n";
$total = 0;
$count = 0;
while ($row = $result->fetch_assoc()) {
    $count++;
    $total += $row['total_amount'];
    $qty = $row['qty_physical_count'] ?? 1;
    echo "ID: {$row['id']} | QTY: $qty | Unit: " . number_format($row['unit_cost'], 2) . " | Total: " . number_format($row['total_amount'], 2) . " | SN: {$row['serial_no']}\n";
}
echo "\nShowing $count items\n";
echo "Subtotal: " . number_format($total, 2) . " PHP\n";

// Check item with very high unit_cost or qty
$queryHigh = "SELECT id, serial_no, brand, model, qty_physical_count, unit_cost,
                    (unit_cost * qty_physical_count) as total_amount
             FROM rpcppe_batch_items 
             WHERE account_code = '1.06.05.020.00' AND batch_id = 14
             ORDER BY total_amount DESC
             LIMIT 10";
$resultHigh = $mysqli->query($queryHigh);
echo "\n\nTop 10 highest value items in Office Equipment:\n";
echo str_repeat("=", 150) . "\n";
while ($row = $resultHigh->fetch_assoc()) {
    echo "ID: {$row['id']} | {$row['brand']} {$row['model']} | QTY: {$row['qty_physical_count']} x " . number_format($row['unit_cost'], 2) . " = " . number_format($row['total_amount'], 2) . "\n";
}

$mysqli->close();
?>
