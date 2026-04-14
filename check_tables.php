<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "Connected successfully\n";

// Check what tables exist
$result = $mysqli->query("SHOW TABLES");
echo "Tables in spamsdb:\n";
while ($row = $result->fetch_row()) {
    echo "  - " . $row[0] . "\n";
}

$mysqli->close();
?>
