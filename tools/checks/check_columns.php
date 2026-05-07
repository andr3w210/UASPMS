<?php
require_once __DIR__ . '/../bootstrap.php';
$mysqli = tools_db();

// Check columns
$result = $mysqli->query("DESCRIBE rpcppe_batch_items");
echo "Columns in rpcppe_batch_items:\n";
while ($row = $result->fetch_assoc()) {
    echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
}

$mysqli->close();
?>
