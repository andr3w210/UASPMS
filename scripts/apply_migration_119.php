<?php
require_once __DIR__ . '/../spams/app/config/init.php';

$db = db();
$sql = file_get_contents(__DIR__ . '/../database/119_annex_a_useful_life_other_equipment.sql');
if (!$db || $sql === false || !$db->multi_query($sql)) {
    fwrite(STDERR, "Unable to apply migration 119.\n");
    exit(1);
}
do {
    if ($result = $db->store_result()) {
        $result->free();
    }
} while ($db->more_results() && $db->next_result());

echo "Migration 119 applied.\n";
