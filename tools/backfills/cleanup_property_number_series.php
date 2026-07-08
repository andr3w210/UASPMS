<?php

require_once __DIR__ . '/../bootstrap.php';

$apply = in_array('--apply', $argv, true);

$db = tools_db();
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

$before = 0;
$after = 0;
$deleted = 0;

$countRes = $db->query("SELECT COUNT(*) AS total FROM series_numbers WHERE module_key LIKE 'property_number|%'");
if ($countRes) {
    $row = $countRes->fetch_assoc();
    $before = (int) ($row['total'] ?? 0);
}

$malformedRes = $db->query("SELECT COUNT(*) AS total FROM series_numbers WHERE module_key LIKE 'property_number|%' AND prefix NOT REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+$'");
if ($malformedRes) {
    $row = $malformedRes->fetch_assoc();
    $deleted = (int) ($row['total'] ?? 0);
}

if ($apply && $deleted > 0) {
    $stmt = $db->prepare("DELETE FROM series_numbers WHERE module_key LIKE 'property_number|%' AND prefix NOT REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+$'");
    if (!$stmt) {
        fwrite(STDERR, "Unable to prepare cleanup statement: {$db->error}\n");
        exit(1);
    }

    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();
}

$countRes = $db->query("SELECT COUNT(*) AS total FROM series_numbers WHERE module_key LIKE 'property_number|%' AND prefix REGEXP '^[0-9]{4}-[0-9]{2}-[A-Za-z0-9.]+$'");
if ($countRes) {
    $row = $countRes->fetch_assoc();
    $after = (int) ($row['total'] ?? 0);
}

echo $apply ? "Property-number series cleanup completed\n" : "Dry-run only. Re-run with --apply to delete malformed property-number series rows.\n";
echo "Before: {$before}\n";
echo ($apply ? "Deleted malformed rows: " : "Malformed rows that would be deleted: ") . "{$deleted}\n";
echo "Remaining canonical rows: {$after}\n";

$db->close();
