<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Set Office Equipment (1.06.05.020.00) based on the authoritative list provided
- Move all items from the expected list to OE
- Remove all items not on the list from OE
*/

$mysqli = tools_db();
$apply = in_array('--apply', $argv, true);

if (!$apply) {
    echo "DRY RUN: no database changes will be committed. Re-run with --apply to update Office Equipment classification.\n\n";
}

// AUTHORITATIVE LIST - serial numbers that MUST be in Office Equipment
$authoritySerialNumbers = [
    'E343M850304', '3016-B', 'Q0E4PDBCA00033A', 'Q0PNPDCD900106W',
    '0469157', '0469068', '0469092', '0469097', '0469036',
    'G618M550097', 'G618M550098', 'G617M950034', 'G617M950386', 'G618M550094',
    'E346M550018', 'G617M750155',
    '20221805-14144', '20221805-14147', '20221805-13796',
    'E012583', 'E009159', 'E012502', 'E010708',
    'KL273089', '20211807-15269', '20211807-15282', '20211807-15277', '20211806-14244',
    'KL273088', 'LL323286',
    '807INJL4Z228', '805INGQ1D216', '805INDP3M210', '807INJL10021',
    '340624293018719060041', '340719813098C210160019', '340719813098C210160015', '340719813098C210160012',
    '340719813098C210160027', '340719813098C210160016', '340719813098C210160018',
    '2401248060163190160010',
    '2401ALY209160B02038', '2401ALY209160B02028', '2401ALY209160B02036', '2401ALY209179C00907',
    '2401ALY209179C00952', '2401ALY209179C00476', '2401ALY209179C00485', '2401ALY209179C00936',
    '2401ALY209160B02189', '2401ALY209160B02195', '2401ALY209160B02041', '2401ALY209179C00905',
    '2401ALY209179C00144', '2401ALY209179C00662', '2401ALY209179C00841', '2401ALY209179C00154',
    '2401ALY209179C00937', '2401ALY209179C00931', '2401ALY209179C00923', '2401ALY209160B02250',
    '2401ALY209160B02058', '2401ALY209160B02183', '2401ALY209160B02190', '2401ALY209160B02037',
    '2401ALY209160B02252', '2401ALY209160B02191', '2401ALY209160B02105', '2401ALY209179C00946',
    '2401ALY209179C00138', '2401ALY209179C00473', '2401ALY209160B02245', '2401ALY209179C00951',
    '2401ALY209160B02044', '2401ALY209160B02046', '2401ALY209160B02057', '2401ALY209160B02186',
    '2401ALY209179C00924', '2401ALY209179C00891', '2401ALY209179C00908', '2401ALY209179C00878',
    '2401ALY209179C00805', '2401ALY209179C00470', '2401ALY209179C00930', '2401ALY209179C00911',
    '2401ALY209179C00917', '2401ALY209179C00469', '2401ALY209179C00803', '2401ALY209160B02142',
    '2401ALY209179C00894', '2401ALY209179C00142', '2401ALY209179C00143', '2401ALY209160B02106',
    '2401ALY209179C00240', '2401ALY209179C00478', '2401ALY209160B02140', '2401ALY209160B02182',
    '2401ALY209160B02469', '2401ALY209179C00933', '2401ALY209160B02187', '2401ALY209160B02192',
    '2401ALY210070B00998', '2401ALY209160B02196', '2401ALY209160B02143', '2401ALY209160B02029',
    '2401ALY209179C00922', '2401ALY209160B02181', '2401ALY209160B02040', '2401ALY209160B02034',
    '2401ALY209160B02372', '2401ALY209160B02033', '2401ALY209179C00941', '2401ALY209179C00915',
    '2401ALY209160B02185', '2401ALY209160B02032', '2401ALY209160B02260', '2401ALY209160B02259',
    '2401ALY209179C00929', '0NWE3NNX100123', '0NWE3NNX100076', '0NWE3NNX100061',
    '0NWE3NNX100077', '0NWE3NNX100104', '0NWE3NNX100074', '0NWE3NNX100083',
    '0NWE3NNX100113', '0NWE3NNWB00080', '0NWE3NNX100075', '0NWE3NNX100080',
    '0NWE3NNX100072', '0NWE3NNX100110', 'AA00E8BPGT00045',
    '0U4A3NEX301051', '0U4A3NEX300587', '0U4A3NEX300583', '0U4A3NEX301052',
    '0U4A3NEX301056', '0U4A3NEX300668', '240062374015C140160054', '24006237015C140160030',
    '240062374015C140160029', '540N305440244170860006', 'BPQEP3CY700132D',
    '121202AHCNW16252D000028', '121201AHCMN25252A000115', '121201AHCMN25252A000098',
    '121201AHCMN25252A000056', '121201AHCMN25252A000046', '121201AHCMN25252A000182',
];

echo "STEP 1: REMOVE items from Office Equipment that are NOT on the authoritative list\n";
echo str_repeat("=", 120) . "\n";

// Get list of IDs currently in OE that are NOT in the authority list
$placeholders = implode(',', array_fill(0, count($authoritySerialNumbers), '?'));
$typeStr = str_repeat('s', count($authoritySerialNumbers));

$query = "SELECT id, serial_no, brand, model, item_description, unit_cost, qty_physical_count,
                 (unit_cost * qty_physical_count) as total_amount
         FROM rpcppe_batch_items 
         WHERE account_code = '1.06.05.020.00' 
           AND batch_id = 14
           AND serial_no NOT IN ($placeholders)
           AND serial_no != ''";

$stmt = $mysqli->prepare($query);
if (!$stmt) {
    die("Error: " . $mysqli->error);
}

// Build params and bind
$params = $authoritySerialNumbers;
$stmt->bind_param($typeStr, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$itemsToRemove = [];
$removeTotal = 0;
echo "Items to REMOVE from Office Equipment:\n";
while ($row = $result->fetch_assoc()) {
    echo "  ID: {$row['id']} | SN: {$row['serial_no']} | {$row['brand']} {$row['model']} | " . number_format($row['total_amount'], 2) . " PHP\n";
    $itemsToRemove[] = $row['id'];
    $removeTotal += $row['total_amount'];
}
echo "\nTotal to remove: " . count($itemsToRemove) . " items / " . number_format($removeTotal, 2) . " PHP\n\n";

$mysqli->begin_transaction();
try {
// Remove these items (move to null account or flag as unclassified)
if (count($itemsToRemove) > 0) {
    $removeIds = implode(',', $itemsToRemove);
    $query = "UPDATE rpcppe_batch_items 
              SET account_code = NULL, 
                  account_code_id = NULL,
                  account_name = NULL,
                  fund_code = NULL,
                  fund_source = NULL,
                  fund_number = NULL,
                  updated_at = NOW()
              WHERE id IN ($removeIds)";
    if ($mysqli->query($query)) {
        echo ($apply ? "Removed " : "Would remove ") . $mysqli->affected_rows . " incorrect items from Office Equipment\n\n";
    }
}

// STEP 2: Move items from the authority list that are in OTHER accounts into OE
echo "\nSTEP 2: MOVE items from other accounts INTO Office Equipment\n";
echo str_repeat("=", 120) . "\n";

$query = "SELECT id, account_code, serial_no, brand, model, unit_cost, qty_physical_count,
                 (unit_cost * qty_physical_count) as total_amount
         FROM rpcppe_batch_items 
         WHERE account_code != '1.06.05.020.00' 
           AND batch_id = 14
           AND serial_no IN ($placeholders)";

$stmt = $mysqli->prepare($query);
$stmt->bind_param($typeStr, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$itemsToMove = [];
$moveTotal = 0;
echo "Items to MOVE INTO Office Equipment:\n";
while ($row = $result->fetch_assoc()) {
    echo "  ID: {$row['id']} | From: {$row['account_code']} | SN: {$row['serial_no']} | " . number_format($row['total_amount'], 2) . " PHP\n";
    $itemsToMove[] = $row['id'];
    $moveTotal += $row['total_amount'];
}
echo "\nTotal to move: " . count($itemsToMove) . " items / " . number_format($moveTotal, 2) . " PHP\n\n";

// Move these items to Office Equipment
if (count($itemsToMove) > 0) {
    $moveIds = implode(',', $itemsToMove);
    $query = "UPDATE rpcppe_batch_items 
              SET account_code = '1.06.05.020.00', 
                  account_code_id = 26,
                  account_name = 'Office Equipment',
                  fund_code = '1',
                  fund_source = '1',
                  fund_number = '01',
                  updated_at = NOW()
              WHERE id IN ($moveIds)";
    if ($mysqli->query($query)) {
        echo ($apply ? "Moved " : "Would move ") . $mysqli->affected_rows . " items into Office Equipment\n\n";
    }
}

// FINAL VERIFICATION
echo "\nFINAL VERIFICATION:\n";
echo str_repeat("=", 120) . "\n";

$queryFinal = "SELECT COUNT(*) as cnt, SUM(unit_cost * qty_physical_count) as total
              FROM rpcppe_batch_items 
              WHERE account_code = '1.06.05.020.00' AND batch_id = 14";
$resultFinal = $mysqli->query($queryFinal);
$rowFinal = $resultFinal->fetch_assoc();

echo "Office Equipment (1.06.05.020.00) - Batch 14:\n";
echo "  Total Rows: " . $rowFinal['cnt'] . "\n";
echo "  Total Value: " . number_format($rowFinal['total'], 2) . " PHP\n\n";

echo "Expected from your list: 150 items (69 already there + 73 to move + 8 missing)\n";
echo "Found in database: " . $rowFinal['cnt'] . " items\n";
echo "Missing from database: " . (150 - $rowFinal['cnt']) . " items\n\n";

if ($rowFinal['total']) {
    $expected = 12344704.00;
    $gap = $expected - $rowFinal['total'];
    echo "Expected total (from list): " . number_format($expected, 2) . " PHP\n";
    echo "Actual total: " . number_format($rowFinal['total'], 2) . " PHP\n";
    echo "Gap: " . number_format($gap, 2) . " PHP (" . round(($gap / $expected) * 100, 2) . "%)\n";
}

if ($apply) {
    $mysqli->commit();
    echo "\nCOMMITTED. Database was updated.\n";
} else {
    $mysqli->rollback();
    echo "\nROLLED BACK. No database changes were committed.\n";
}
} catch (Throwable $e) {
    $mysqli->rollback();
    throw $e;
}

$mysqli->close();
?>
