<?php
/*
Reclassify 73 misplaced items to Fund 01 Office Equipment (1.06.05.020.00)
*/

$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// IDs to reclassify to Office Equipment account
$idsToMove = [
    19105, 19106, 19107, 19108, 19109, 19110, 19111, 19112, 19113, 19114, 19118, 19115, 19116, 19117, 19119, 19121, 19122, 19123, 19124, 19125,
    19126, 19127, 19128, 19129, 19130, 19131, 19132, 19133, 19134, 19135, 19136, 19137, 19138, 19139, 19140, 19141, 19142, 19143, 19144, 19145,
    19146, 19147, 19148, 19149, 19150, 19151, 19152, 19153, 19154, 19155, 19156, 19157, 19158, 19159, 19160, 19161, 19162, 19163, 19164, 19165,
    19166, 19167, 19168, 19169, 19170, 19171, 19172, 19173, 19174, 19175, 19176, 19177, 19198
];

echo "Reclassifying " . count($idsToMove) . " items to Office Equipment (1.06.05.020.00)...\n";
echo str_repeat("=", 100) . "\n\n";

// First verify the target account exists
$query = "SELECT * FROM account_codes WHERE account_code = '1.06.05.020.00'";
$result = $mysqli->query($query);
if ($result->num_rows === 0) {
    die("ERROR: Office Equipment account code 1.06.05.020.00 not found!\n");
}
$acct = $result->fetch_assoc();
$acct_id = $acct['id'];
echo "Target Account: {$acct['account_code']} ({$acct['account_name']}) - ID: $acct_id\n\n";

// Update the items
$idCount = count($idsToMove);
$placeholders = implode(',', array_fill(0, $idCount, '?'));
$typeStr = str_repeat('i', $idCount);

$query = "UPDATE rpcppe_batch_items 
          SET account_code = '1.06.05.020.00', 
              account_code_id = $acct_id, 
              account_name = ?, 
              fund_code = ?,
              fund_source = ?,
              fund_number = ?,
              updated_at = NOW()
          WHERE id IN ($placeholders)";

$stmt = $mysqli->prepare($query);
if (!$stmt) {
    die("Error preparing query: " . $mysqli->error . "\n");
}

// Bind values
$acctName = $acct['name'];
$fundCode = '1';
$fundSource = '1';
$fundNumber = '01';
$params = [$acctName, $fundCode, $fundSource, $fundNumber];
foreach ($idsToMove as $id) {
    $params[] = $id;
}

// Bind parameters dynamically
$types = 'ssss' . $typeStr;
$stmt->bind_param($types, ...$params);
$result = $stmt->execute();

if ($result) {
    $affected = $stmt->affected_rows;
    echo "✓ Successfully reclassified $affected rows\n\n";
    
    // Verify the update
    $verifyQuery = "SELECT COUNT(*) as cnt, SUM(unit_cost * qty_physical_count) as total
                   FROM rpcppe_batch_items 
                   WHERE account_code = '1.06.05.020.00'";
    $verifyResult = $mysqli->query($verifyQuery);
    $row = $verifyResult->fetch_assoc();
    echo "Office Equipment (1.06.05.020.00) now has:\n";
    echo "  Rows: " . $row['cnt'] . "\n";
    echo "  Total: " . number_format($row['total'], 2) . " PHP\n";
} else {
    echo "✗ Error executing update: " . $stmt->error . "\n";
}

$stmt->close();
$mysqli->close();
?>
