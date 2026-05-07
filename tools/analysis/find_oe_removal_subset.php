<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Find subset of current OE rows to remove so OE total hits expected.
Uses rows currently in OE that are NOT matched by authoritative SN tokens.
Meet-in-the-middle exact subset-sum on cents.
*/

$m = tools_db();
$expected = 12344704.00;

$oe = $m->query("SELECT id, serial_no, brand, model, item_description,
                       ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1),2) AS total
                FROM rpcppe_batch_items
                WHERE batch_id=14 AND account_code='1.06.05.020.00'
                ORDER BY id");
$oeRows = [];
$oeTotal = 0.0;
while ($r = $oe->fetch_assoc()) {
    $oeRows[] = $r;
    $oeTotal += (float)$r['total'];
}
$targetRemove = round(($oeTotal - $expected) * 100); // in cents

echo "Current OE total: " . number_format($oeTotal,2) . "\n";
echo "Expected total: " . number_format($expected,2) . "\n";
echo "Need remove: " . number_format($targetRemove/100,2) . "\n\n";

// Build the same token-matched ID set as reconciliation script.
$tokens = include dirname(__DIR__, 2) . '/tmp_authority_tokens.php';
$matched = [];
$stmt = $m->prepare("SELECT id FROM rpcppe_batch_items WHERE batch_id=14 AND (serial_no LIKE ? OR item_description LIKE ?)");
foreach ($tokens as $t) {
    $like = '%' . $t . '%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $matched[(int)$row['id']] = true;
    }
}
$stmt->close();

$extras = [];
foreach ($oeRows as $r) {
    if (!isset($matched[(int)$r['id']])) {
        $extras[] = [
            'id' => (int)$r['id'],
            'serial_no' => (string)$r['serial_no'],
            'brand' => (string)$r['brand'],
            'model' => (string)$r['model'],
            'total_cents' => (int)round(((float)$r['total']) * 100),
            'total' => (float)$r['total'],
        ];
    }
}

echo "Extra candidate rows: " . count($extras) . "\n";

$n = count($extras);
$mid = intdiv($n, 2);
$a = array_slice($extras, 0, $mid);
$b = array_slice($extras, $mid);

function buildSums(array $rows): array {
    $sums = [];
    $count = count($rows);
    $limit = 1 << $count;
    for ($mask = 0; $mask < $limit; $mask++) {
        $sum = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($mask & (1 << $i)) {
                $sum += $rows[$i]['total_cents'];
            }
        }
        if (!isset($sums[$sum])) {
            $sums[$sum] = $mask;
        }
    }
    return $sums;
}

$sumA = buildSums($a);
$sumB = buildSums($b);

$bestDiff = PHP_INT_MAX;
$bestA = 0;
$bestB = 0;
$bestSum = 0;

foreach ($sumA as $sa => $maskA) {
    $need = $targetRemove - $sa;
    if (isset($sumB[$need])) {
        $bestA = $maskA;
        $bestB = $sumB[$need];
        $bestSum = $targetRemove;
        $bestDiff = 0;
        break;
    }
}

if ($bestDiff !== 0) {
    // nearest fallback
    $keysB = array_keys($sumB);
    sort($keysB);
    foreach ($sumA as $sa => $maskA) {
        $need = $targetRemove - $sa;
        // binary search nearest in keysB
        $lo = 0; $hi = count($keysB) - 1;
        while ($lo <= $hi) {
            $md = intdiv($lo + $hi, 2);
            if ($keysB[$md] < $need) $lo = $md + 1; else $hi = $md - 1;
        }
        foreach ([$lo, $lo - 1] as $idx) {
            if ($idx >= 0 && $idx < count($keysB)) {
                $sb = $keysB[$idx];
                $sum = $sa + $sb;
                $diff = abs($targetRemove - $sum);
                if ($diff < $bestDiff) {
                    $bestDiff = $diff;
                    $bestA = $maskA;
                    $bestB = $sumB[$sb];
                    $bestSum = $sum;
                }
            }
        }
    }
}

$removeIds = [];
$removeTotal = 0;
for ($i = 0; $i < count($a); $i++) {
    if ($bestA & (1 << $i)) {
        $removeIds[] = $a[$i]['id'];
        $removeTotal += $a[$i]['total_cents'];
    }
}
for ($i = 0; $i < count($b); $i++) {
    if ($bestB & (1 << $i)) {
        $removeIds[] = $b[$i]['id'];
        $removeTotal += $b[$i]['total_cents'];
    }
}

sort($removeIds);
echo "Best removal total: " . number_format($removeTotal/100,2) . " | diff " . number_format(($targetRemove - $removeTotal)/100,2) . "\n";
echo "IDs to remove: " . implode(',', $removeIds) . "\n\n";

if (!empty($removeIds)) {
    $idCsv = implode(',', $removeIds);
    $res = $m->query("SELECT id, serial_no, brand, model,
                             ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1),2) AS total
                      FROM rpcppe_batch_items WHERE id IN ($idCsv) ORDER BY id");
    while ($r = $res->fetch_assoc()) {
        echo "ID {$r['id']} | {$r['serial_no']} | {$r['brand']} {$r['model']} | {$r['total']}\n";
    }
}

$m->close();
