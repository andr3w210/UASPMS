<?php
/**
 * Sync updated property numbers across related system tables.
 *
 * Dry-run by default. Use --apply to persist changes.
 */

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$apply = in_array('--apply', $argv, true);
$db = tools_db();

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to persist property-number sync updates." . PHP_EOL;
}

$totalUpdated = 0;
$totalFailed = 0;

$updates = [
    [
        'label' => 'asset_transfers',
        'expected' => '436 rows expected',
        'sql' => "UPDATE asset_transfers at
            JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = at.property_number COLLATE utf8mb4_unicode_ci
            SET at.property_number = did.property_number
            WHERE (at.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)",
    ],
    [
        'label' => 'inventory_count_items',
        'expected' => '437 rows expected',
        'sql' => "UPDATE inventory_count_items ici
            JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = ici.property_number COLLATE utf8mb4_unicode_ci
            SET ici.property_number = did.property_number
            WHERE (ici.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)",
    ],
    [
        'label' => 'transfer_batch_items',
        'expected' => '436 rows expected',
        'sql' => "UPDATE transfer_batch_items tbi
            JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = tbi.property_number COLLATE utf8mb4_unicode_ci
            SET tbi.property_number = did.property_number
            WHERE (tbi.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)",
    ],
    [
        'label' => 'legacy_assets',
        'expected' => '4699 rows expected',
        'sql' => "UPDATE legacy_assets la
            JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = la.property_number COLLATE utf8mb4_unicode_ci
            SET la.property_number = did.property_number
            WHERE (la.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)",
    ],
];

$db->begin_transaction();

foreach ($updates as $index => $update) {
    echo PHP_EOL . ($index + 1) . ". Updating {$update['label']} ({$update['expected']})..." . PHP_EOL;
    $updateRes = $db->query($update['sql']);
    if (!$updateRes) {
        fwrite(STDERR, "   Error: {$db->error}" . PHP_EOL);
        $totalFailed++;
        continue;
    }

    $changed = $db->affected_rows;
    echo "   " . ($apply ? 'Updated' : 'Would update') . ": {$changed} rows" . PHP_EOL;
    $totalUpdated += $changed;
}

echo PHP_EOL . "--- Final Verification ---" . PHP_EOL;
$tables = ['asset_transfers', 'inventory_count_items', 'transfer_batch_items', 'legacy_assets', 'distribution_item_details'];
foreach ($tables as $table) {
    $res = $db->query("SELECT COUNT(*) as cnt FROM {$table} WHERE property_number IS NOT NULL");
    $row = $res ? $res->fetch_assoc() : ['cnt' => 0];
    echo "{$table}: {$row['cnt']} non-null property_numbers" . PHP_EOL;
}

echo PHP_EOL . "=== Summary ===" . PHP_EOL;
echo ($apply ? 'Total rows updated: ' : 'Total rows that would be updated: ') . $totalUpdated . PHP_EOL;
echo "Errors encountered: {$totalFailed}" . PHP_EOL;

if ($totalFailed === 0 && $apply) {
    $db->commit();
    echo "All system transactions synced successfully." . PHP_EOL;
} elseif ($totalFailed === 0) {
    $db->rollback();
    echo "Dry run complete; no changes were saved." . PHP_EOL;
} else {
    $db->rollback();
    echo "Some updates failed. Review errors above." . PHP_EOL;
}

$db->close();
