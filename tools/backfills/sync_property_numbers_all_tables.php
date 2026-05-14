<?php
/**
 * Sync updated property numbers across all system tables
 * Updates: asset_transfers, inventory_count_items, transfer_batch_items, legacy_assets
 * Source of truth: distribution_item_details (already updated)
 */

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = tools_db();

$totalUpdated = 0;
$totalFailed = 0;

// --- 1. Update asset_transfers ---
echo "\n1. Updating asset_transfers (436 rows expected)...\n";
$updateRes = $db->query(
    "UPDATE asset_transfers at
     JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = at.property_number COLLATE utf8mb4_unicode_ci
     SET at.property_number = did.property_number
     WHERE (at.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)"
);
if (!$updateRes) {
    fwrite(STDERR, "   Error: {$db->error}\n");
    $totalFailed++;
} else {
    $changed = $db->affected_rows;
    echo "   Updated: $changed rows\n";
    $totalUpdated += $changed;
}

// --- 2. Update inventory_count_items ---
echo "2. Updating inventory_count_items (437 rows expected)...\n";
$updateRes = $db->query(
    "UPDATE inventory_count_items ici
     JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = ici.property_number COLLATE utf8mb4_unicode_ci
     SET ici.property_number = did.property_number
     WHERE (ici.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)"
);
if (!$updateRes) {
    fwrite(STDERR, "   Error: {$db->error}\n");
    $totalFailed++;
} else {
    $changed = $db->affected_rows;
    echo "   Updated: $changed rows\n";
    $totalUpdated += $changed;
}

// --- 3. Update transfer_batch_items ---
echo "3. Updating transfer_batch_items (436 rows expected)...\n";
$updateRes = $db->query(
    "UPDATE transfer_batch_items tbi
     JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = tbi.property_number COLLATE utf8mb4_unicode_ci
     SET tbi.property_number = did.property_number
     WHERE (tbi.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)"
);
if (!$updateRes) {
    fwrite(STDERR, "   Error: {$db->error}\n");
    $totalFailed++;
} else {
    $changed = $db->affected_rows;
    echo "   Updated: $changed rows\n";
    $totalUpdated += $changed;
}

// --- 4. Update legacy_assets ---
echo "4. Updating legacy_assets (4699 rows expected)...\n";
$updateRes = $db->query(
    "UPDATE legacy_assets la
     JOIN distribution_item_details did ON did.property_number COLLATE utf8mb4_unicode_ci = la.property_number COLLATE utf8mb4_unicode_ci
     SET la.property_number = did.property_number
     WHERE (la.property_number COLLATE utf8mb4_unicode_ci) != (did.property_number COLLATE utf8mb4_unicode_ci)"
);
if (!$updateRes) {
    fwrite(STDERR, "   Error: {$db->error}\n");
    $totalFailed++;
} else {
    $changed = $db->affected_rows;
    echo "   Updated: $changed rows\n";
    $totalUpdated += $changed;
}

// --- Verify final state ---
echo "\n--- Final Verification ---\n";
$tables = ['asset_transfers', 'inventory_count_items', 'transfer_batch_items', 'legacy_assets', 'distribution_item_details'];
foreach ($tables as $table) {
    $res = $db->query("SELECT COUNT(*) as cnt FROM $table WHERE property_number IS NOT NULL");
    $row = $res->fetch_assoc();
    echo "$table: {$row['cnt']} non-null property_numbers\n";
}

echo "\n=== Summary ===\n";
echo "Total rows updated: $totalUpdated\n";
echo "Errors encountered: $totalFailed\n";
if ($totalFailed === 0) {
    echo "✓ All system transactions synced successfully.\n";
} else {
    echo "✗ Some updates failed. Review errors above.\n";
}

$db->close();
?>

