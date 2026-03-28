<?php
require_once __DIR__ . '/../app/config/constants.php';
require_once __DIR__ . '/../app/config/db.php';

echo "Checking DB connection...\n";
$db = db();
if (!$db) {
    echo "Cannot connect to DB.\n";
    exit(1);
}

echo "Connected to " . DB_NAME . "\n";

$checks = [
    "mode_of_procurements.mode_code" => "SHOW COLUMNS FROM mode_of_procurements LIKE 'mode_code'",
    "classifications.classification_code" => "SHOW COLUMNS FROM classifications LIKE 'classification_code'",
    "distribution_item_details.is_disposed" => "SHOW COLUMNS FROM distribution_item_details LIKE 'is_disposed'",
    "table:returns" => "SHOW TABLES LIKE 'returns'",
    "table:disposals" => "SHOW TABLES LIKE 'disposals'",
];

foreach ($checks as $label => $sql) {
    echo "\nChecking: $label\n";
    $res = $db->query($sql);
    if ($res === false) {
        echo "  Query failed: " . $db->error . "\n";
        continue;
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    if (count($rows) === 0) {
        echo "  NOT FOUND\n";
    } else {
        echo "  FOUND\n";
        foreach ($rows as $r) {
            echo "    " . implode(' | ', $r) . "\n";
        }
    }
}

echo "\nCheck complete.\n";

?>