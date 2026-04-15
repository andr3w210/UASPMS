<?php
/*
REVERT: Undo the reclassification - move items back to their original accounts
*/

$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');

// Get the IDs we moved and find their original account from legacy_assets
$idsToRevert = [
    19105, 19106, 19107, 19108, 19109, 19110, 19111, 19112, 19113, 19114, 19118, 19115, 19116, 19117, 19119, 19121, 19122, 19123, 19124, 19125,
    19126, 19127, 19128, 19129, 19130, 19131, 19132, 19133, 19134, 19135, 19136, 19137, 19138, 19139, 19140, 19141, 19142, 19143, 19144, 19145,
    19146, 19147, 19148, 19149, 19150, 19151, 19152, 19153, 19154, 19155, 19156, 19157, 19158, 19159, 19160, 19161, 19162, 19163, 19164, 19165,
    19166, 19167, 19168, 19169, 19170, 19171, 19172, 19173, 19174, 19175, 19176, 19177, 19198
];

echo "REVERTING reclassification...\n";
echo "Checking what's in Office Equipment now vs before:\n\n";

// Check account distribution
$query = "SELECT account_code, COUNT(*) as cnt, SUM(unit_cost * qty_physical_count) as total
         FROM rpcppe_batch_items 
         WHERE id IN (" . implode(',', $idsToRevert) . ")
         GROUP BY account_code";
$result = $mysqli->query($query);

echo "Moved items by account:\n";
$totalMoved = 0;
while ($row = $result->fetch_assoc()) {
    echo "  {$row['account_code']}: {$row['cnt']} items / " . number_format($row['total'], 2) . " PHP\n";
    $totalMoved += $row['total'];
}
echo "  Total moved: " . number_format($totalMoved, 2) . " PHP\n\n";

// The issue is that we moved items that are NOW in 1.06.05.020.00
// We need to move them back to 1.06.05.030.00 (Communications Equipment)
$query = "UPDATE rpcppe_batch_items 
          SET account_code = '1.06.05.030.00', 
              account_code_id = 27,
              account_name = 'Communications Equipment',
              fund_code = '1',
              fund_source = '1',
              fund_number = '01',
              updated_at = NOW()
          WHERE id IN (" . implode(',', $idsToRevert) . ")";

if ($mysqli->query($query)) {
    echo "✓ Reverted " . $mysqli->affected_rows . " rows back to Communications Equipment (1.06.05.030.00)\n\n";
    
    // Verify
    $verifyQuery = "SELECT COUNT(*) as cnt, SUM(unit_cost * qty_physical_count) as total
                   FROM rpcppe_batch_items 
                   WHERE account_code = '1.06.05.020.00' AND batch_id = 14";
    $verifyResult = $mysqli->query($verifyQuery);
    $row = $verifyResult->fetch_assoc();
    echo "Office Equipment (1.06.05.020.00) now has:\n";
    echo "  Rows: " . $row['cnt'] . "\n";
    echo "  Total: " . number_format($row['total'], 2) . " PHP\n";
} else {
    echo "✗ Error reverting: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
