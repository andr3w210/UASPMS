<?php
/**
 * Remap transaction table property numbers to new format
 * Strategy: Match transaction items to distribution_item_details by office + other identifiers
 * Then update property_number to the new format
 */

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db = tools_db();

$totalUpdated = 0;

// --- 1. Analyze asset_transfers ---
echo "\n1. Analyzing asset_transfers (436 rows)...\n";
$transRes = $db->query(
    "SELECT id, property_number FROM asset_transfers 
     WHERE property_number IS NOT NULL 
     ORDER BY property_number LIMIT 10"
);
echo "   Sample property numbers in asset_transfers:\n";
while ($row = $transRes->fetch_assoc()) {
    echo "     - {$row['property_number']}\n";
}

// --- 2. Check if these can be matched to distribution_item_details ---
echo "\n2. Checking for matches in distribution_item_details...\n";
$matchRes = $db->query(
    "SELECT COUNT(DISTINCT at.id) as total_from_trans,
            COUNT(DISTINCT did.id) as matching_dids
     FROM asset_transfers at
     LEFT JOIN distribution_item_details did ON did.property_number = at.property_number
     WHERE at.property_number IS NOT NULL"
);
$matchRow = $matchRes->fetch_assoc();
echo "   Total in asset_transfers: {$matchRow['total_from_trans']}\n";
echo "   Matching in distribution_item_details: {$matchRow['matching_dids']}\n";

// --- 3. For now, show what CAN be updated with direct match ---
echo "\n3. Items that CAN be updated (direct property_number match):\n";
$canUpdateRes = $db->query(
    "SELECT at.id, at.property_number, did.property_number as new_property_number
     FROM asset_transfers at
     JOIN distribution_item_details did ON did.property_number = at.property_number
     WHERE at.property_number IS NOT NULL"
);
$canUpdateCount = 0;
while ($row = $canUpdateRes->fetch_assoc()) {
    echo "   {$row['property_number']} → {$row['new_property_number']}\n";
    $canUpdateCount++;
}
echo "   Total can be updated: $canUpdateCount\n";

// --- 4. Show unmatched items ---
echo "\n4. Items that CANNOT be matched (historical or different format):\n";
$unmatchRes = $db->query(
    "SELECT COUNT(*) as unmatched
     FROM asset_transfers at
     LEFT JOIN distribution_item_details did ON did.property_number = at.property_number
     WHERE at.property_number IS NOT NULL AND did.id IS NULL"
);
$unmatchRow = $unmatchRes->fetch_assoc();
echo "   Total unmatched: {$unmatchRow['unmatched']}\n";

echo "\n=== Summary ===\n";
echo "Current situation:\n";
echo "  - Only ~1 item per transaction table matches the new property numbers\n";
echo "  - 435+ items per table have old property numbers from 2022-2025\n";
echo "  - These old items cannot be matched to the renumbered assets\n";
echo "\nTo proceed, we need to know:\n";
echo "  1. Are the 435+ old items 'completed transactions' (keep old numbers)?\n";
echo "  2. Or are they 'orphaned asset references' (need to be cleaned up)?\n";
echo "  3. Or should we try to re-assign them to current assets by office/type?\n";

$db->close();
?>
