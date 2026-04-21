<?php
require_once __DIR__ . '/../spams/app/config/db.php';
$path = __DIR__ . '/../database/093_qr_tag_codes.sql';
if (!file_exists($path)) {
    echo "Migration file not found: $path\n";
    exit(1);
}
$db = db_connect();
if (!$db) {
    echo "Failed to connect to DB\n";
    exit(1);
}
$sql = file_get_contents($path);
if ($sql === false) {
    echo "Unable to read migration file\n";
    exit(1);
}
if ($db->multi_query($sql)) {
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
    if ($db->errno) {
        echo "Migration finished with error: " . $db->error . "\n";
        exit(1);
    }
    echo "Migration applied successfully.\n";
    exit(0);
} else {
    echo "Migration failed: " . $db->error . "\n";
    exit(1);
}
