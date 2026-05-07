<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Reconcile batch 14 Office Equipment against authoritative list.
- Build matched row IDs from serial tokens (serial_no or item_description contains token)
- Dry-run by default
- Apply mode updates account_code only for rows currently in OE but not matched
Usage:
  php reconcile_oe_authority_total.php
  php reconcile_oe_authority_total.php --apply
*/

$apply = in_array('--apply', $argv, true);

$mysqli = tools_db();
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error . "\n");
}

$expectedTotal = 12344704.00;
$batchId = 14;

$tokens = [
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

$matchedIds = [];
$tokenHits = 0;

$searchSql = "SELECT id FROM rpcppe_batch_items
              WHERE batch_id = ?
                AND (serial_no LIKE ? OR item_description LIKE ?)
              ORDER BY id";
$stmt = $mysqli->prepare($searchSql);

foreach ($tokens as $token) {
    $like = '%' . $token . '%';
    $stmt->bind_param('iss', $batchId, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $thisHit = 0;
    while ($r = $res->fetch_assoc()) {
        $matchedIds[(int)$r['id']] = true;
        $thisHit++;
    }
    if ($thisHit > 0) {
        $tokenHits++;
    }
}
$stmt->close();

$matchedIdList = array_keys($matchedIds);
sort($matchedIdList);

if (count($matchedIdList) === 0) {
    die("No rows matched tokens. Aborting.\n");
}

$idCsv = implode(',', $matchedIdList);

$sumSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)),0) AS total
           FROM rpcppe_batch_items
           WHERE id IN ($idCsv)";
$sumRes = $mysqli->query($sumSql);
$sumRow = $sumRes->fetch_assoc();

$currentOeSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)),0) AS total
                 FROM rpcppe_batch_items
                 WHERE batch_id = $batchId AND account_code = '1.06.05.020.00'";
$currentOeRes = $mysqli->query($currentOeSql);
$currentOe = $currentOeRes->fetch_assoc();

$extraSql = "SELECT id, serial_no, brand, model,
                    (unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)) AS total
             FROM rpcppe_batch_items
             WHERE batch_id = $batchId
               AND account_code = '1.06.05.020.00'
               AND id NOT IN ($idCsv)
             ORDER BY id";
$extraRes = $mysqli->query($extraSql);
$extras = [];
$extrasTotal = 0.0;
while ($r = $extraRes->fetch_assoc()) {
    $extras[] = $r;
    $extrasTotal += (float)$r['total'];
}

$missingInOeSql = "SELECT id, account_code, serial_no,
                          (unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)) AS total
                   FROM rpcppe_batch_items
                   WHERE id IN ($idCsv)
                     AND (account_code IS NULL OR account_code <> '1.06.05.020.00')
                   ORDER BY id";
$missingRes = $mysqli->query($missingInOeSql);
$toMove = [];
$toMoveTotal = 0.0;
while ($r = $missingRes->fetch_assoc()) {
    $toMove[] = $r;
    $toMoveTotal += (float)$r['total'];
}

echo "Matched tokens with at least one row: $tokenHits / " . count($tokens) . "\n";
echo "Matched unique DB rows: " . count($matchedIdList) . "\n";
echo "Matched rows total value: " . number_format((float)$sumRow['total'], 2) . "\n";
echo "Expected total: " . number_format($expectedTotal, 2) . "\n";
echo "Difference: " . number_format((float)$sumRow['total'] - $expectedTotal, 2) . "\n\n";

echo "Current OE rows: {$currentOe['cnt']} | total: " . number_format((float)$currentOe['total'], 2) . "\n";
echo "Rows currently in OE but NOT matched: " . count($extras) . " | total: " . number_format($extrasTotal, 2) . "\n";
echo "Rows matched but NOT in OE: " . count($toMove) . " | total: " . number_format($toMoveTotal, 2) . "\n\n";

if (count($extras) > 0) {
    echo "All extras currently in OE (not token-matched):\n";
    for ($i = 0; $i < count($extras); $i++) {
        $e = $extras[$i];
        echo "  ID {$e['id']} | SN {$e['serial_no']} | {$e['brand']} {$e['model']} | " . number_format((float)$e['total'], 2) . "\n";
    }
    echo "\n";
}

if (count($toMove) > 0) {
    echo "All token-matched rows not currently in OE (to move in):\n";
    for ($i = 0; $i < count($toMove); $i++) {
        $m = $toMove[$i];
        echo "  ID {$m['id']} | From {$m['account_code']} | SN {$m['serial_no']} | " . number_format((float)$m['total'], 2) . "\n";
    }
    echo "\n";
}

if (!$apply) {
    echo "Dry run only. Re-run with --apply to enforce OE membership from authoritative list.\n";
    $mysqli->close();
    exit(0);
}

$mysqli->begin_transaction();
try {
    if (count($extras) > 0) {
        $extraIds = array_map(static fn($x) => (int)$x['id'], $extras);
        $extraCsv = implode(',', $extraIds);
        $demoteSql = "UPDATE rpcppe_batch_items
                      SET account_code = NULL,
                          account_code_id = NULL,
                          account_name = NULL,
                          fund_code = NULL,
                          fund_source = NULL,
                          fund_number = NULL,
                          updated_at = NOW()
                      WHERE id IN ($extraCsv)";
        $mysqli->query($demoteSql);
    }

    if (count($toMove) > 0) {
        $moveIds = array_map(static fn($x) => (int)$x['id'], $toMove);
        $moveCsv = implode(',', $moveIds);
        $promoteSql = "UPDATE rpcppe_batch_items
                       SET account_code = '1.06.05.020.00',
                           account_code_id = 26,
                           account_name = 'Office Equipment',
                           fund_code = '1',
                           fund_source = '1',
                           fund_number = '01',
                           updated_at = NOW()
                       WHERE id IN ($moveCsv)";
        $mysqli->query($promoteSql);
    }

    $finalSql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)),0) AS total
                 FROM rpcppe_batch_items
                 WHERE batch_id = $batchId AND account_code = '1.06.05.020.00'";
    $finalRes = $mysqli->query($finalSql);
    $final = $finalRes->fetch_assoc();

    echo "After apply -> OE rows: {$final['cnt']} | total: " . number_format((float)$final['total'], 2) . "\n";
    echo "Expected: " . number_format($expectedTotal, 2) . " | delta: " . number_format((float)$final['total'] - $expectedTotal, 2) . "\n";

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    throw $e;
}

$mysqli->close();
