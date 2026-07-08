<?php
require_once __DIR__ . '/../spams/app/config/db.php';

$db = db_connect();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$res = $db->query("SHOW COLUMNS FROM receiving_item_details LIKE 'is_distributed'");
if ($res && $res->num_rows > 0) {
    echo "Column is_distributed already exists.\n";
    exit(0);
}

fwrite(STDERR, "Missing receiving_item_details.is_distributed. Apply database/024_distribution_unit_tracking.sql through the migration process before deployment.\n");
exit(1);
