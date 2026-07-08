<?php
require_once __DIR__ . '/../../spams/app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$res = $db->query('SHOW COLUMNS FROM rpcppe_batch_items');
if (!$res instanceof mysqli_result) {
    fwrite(STDERR, "Unable to describe rpcppe_batch_items.\n");
    exit(1);
}

while ($row = $res->fetch_assoc()) {
    echo ($row['Field'] ?? '') . '|' . ($row['Type'] ?? '') . '|' . ($row['Null'] ?? '') . '|' . ($row['Key'] ?? '') . PHP_EOL;
}
