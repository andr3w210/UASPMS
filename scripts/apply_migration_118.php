<?php
require_once __DIR__ . '/../spams/app/config/init.php';

$db = db();
$sql = file_get_contents(__DIR__ . '/../database/118_coa_circular_2020_001_other_equipment_accounts.sql');
if (!$db || $sql === false || !$db->multi_query($sql)) {
    fwrite(STDERR, "Unable to apply migration 118.\n");
    exit(1);
}
do {
    if ($result = $db->store_result()) {
        $result->free();
    }
} while ($db->more_results() && $db->next_result());

echo "Migration 118 applied.\n";
