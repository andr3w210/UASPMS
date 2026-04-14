<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
$result = $mysqli->query('DESCRIBE account_codes');
echo "Columns in account_codes:\n";
while ($row = $result->fetch_assoc()) {
    echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
}

// Also check a sample row
echo "\nSample account_codes row:\n";
$result = $mysqli->query("SELECT * FROM account_codes LIMIT 1");
$row = $result->fetch_assoc();
print_r($row);

$mysqli->close();
?>
