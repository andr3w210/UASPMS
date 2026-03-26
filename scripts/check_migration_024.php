<?php
require_once __DIR__ . '/../spams/app/config/db.php';
$db = db_connect();
if (!$db) {
    echo "DB connection failed\n";
    exit(1);
}
$tables = ['distribution_item_details', 'receiving_item_details'];
foreach ($tables as $t) {
    echo "Columns in $t:\n";
    $res = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($t) . "`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            echo " - " . $row['Field'] . " (" . $row['Type'] . ")" . ($row['Null']==='NO' ? ' NOT NULL' : '') . "\n";
        }
    } else {
        echo "  (table not found)\n";
    }
    echo "\n";
}
exit(0);
