<?php
require_once __DIR__ . '/../spams/app/config/db.php';
$db = db_connect();
if (!$db) {
    echo "DB connection failed\n";
    exit(1);
}
$res = $db->query("SHOW COLUMNS FROM receiving_item_details LIKE 'is_distributed'");
if ($res && $res->num_rows > 0) {
    echo "Column is_distributed already exists.\n";
    exit(0);
}
$sql = "ALTER TABLE receiving_item_details ADD COLUMN is_distributed TINYINT(1) NOT NULL DEFAULT 0 AFTER remarks";
if ($db->query($sql) === TRUE) {
    echo "Added is_distributed column successfully.\n";
    exit(0);
} else {
    echo "Failed to add column: " . $db->error . "\n";
    exit(1);
}
