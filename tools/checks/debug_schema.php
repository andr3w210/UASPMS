<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// First, let's check what columns exist in rpcppe_batch_items
$result = $mysqli->query("DESCRIBE rpcppe_batch_items");
echo "Columns in rpcppe_batch_items:\n";
while ($row = $result->fetch_assoc()) {
    echo "  - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n";

// Try a simpler query
$query = "SELECT id, account_code, item_description, serial_no, total_amount, brand, model FROM rpcppe_batch_items LIMIT 3";
$result = $mysqli->query($query);
if ($result) {
    echo "Sample from rpcppe_batch_items:\n";
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "Query failed: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
