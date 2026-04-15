<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$target = 7000000.00;
$targetCents = (int) round($target * 100);

$sql = "SELECT id,
               account_code_id,
               account_name,
               ROUND(unit_cost * COALESCE(NULLIF(qty_physical_count,0),1), 2) AS total,
               serial_no,
               LEFT(REPLACE(REPLACE(item_description, '\\r', ' '), '\\n', ' '), 140) AS item_description
        FROM rpcppe_batch_items
        WHERE batch_id = 14
          AND account_code = '1.08.01.020.00'
          AND fund_source = '01'
          AND fund_number = '1'
        ORDER BY id";

$res = $m->query($sql);
$rows = [];
$accountCodeId = null;
$accountName = null;
while ($r = $res->fetch_assoc()) {
    $rows[] = [
        'id' => (int) $r['id'],
        'cents' => (int) round((float) $r['total'] * 100),
        'total' => (float) $r['total'],
        'serial_no' => (string) $r['serial_no'],
        'item_description' => (string) $r['item_description'],
    ];
    if ($accountCodeId === null && $r['account_code_id'] !== null) {
        $accountCodeId = (int) $r['account_code_id'];
        $accountName = (string) ($r['account_name'] ?? 'Computer Software');
    }
}

$currentCents = 0;
foreach ($rows as $r) {
    $currentCents += $r['cents'];
}

echo 'Current rows: ' . count($rows) . PHP_EOL;
echo 'Current total: ' . number_format($currentCents / 100, 2) . PHP_EOL;
echo 'Target total: ' . number_format($target, 2) . PHP_EOL;
echo 'Account code id sample: ' . ($accountCodeId ?? 0) . PHP_EOL;
echo 'Account name sample: ' . ($accountName ?? 'Computer Software') . PHP_EOL;

$n = count($rows);
$half = intdiv($n, 2);
$left = array_slice($rows, 0, $half);
$right = array_slice($rows, $half);

$leftMap = [];
$leftLimit = 1 << count($left);
for ($mask = 0; $mask < $leftLimit; $mask++) {
    $sum = 0;
    $ids = [];
    $k = 0;
    $bits = $mask;
    while ($bits > 0) {
        if ($bits & 1) {
            $sum += $left[$k]['cents'];
            $ids[] = $left[$k]['id'];
        }
        $bits >>= 1;
        $k++;
    }
    if (!isset($leftMap[$sum]) || count($ids) < count($leftMap[$sum])) {
        $leftMap[$sum] = $ids;
    }
}

$rightMap = [];
$rightLimit = 1 << count($right);
for ($mask = 0; $mask < $rightLimit; $mask++) {
    $sum = 0;
    $ids = [];
    $k = 0;
    $bits = $mask;
    while ($bits > 0) {
        if ($bits & 1) {
            $sum += $right[$k]['cents'];
            $ids[] = $right[$k]['id'];
        }
        $bits >>= 1;
        $k++;
    }
    if (!isset($rightMap[$sum]) || count($ids) < count($rightMap[$sum])) {
        $rightMap[$sum] = $ids;
    }
}

$solution = null;
foreach ($leftMap as $sumL => $idsL) {
    $need = $targetCents - (int) $sumL;
    if (isset($rightMap[$need])) {
        $solution = array_values(array_unique(array_merge($idsL, $rightMap[$need])));
        sort($solution);
        break;
    }
}

if ($solution === null) {
    echo 'No exact subset found in current Fund01 Computer Software rows.' . PHP_EOL;
    $m->close();
    exit(1);
}

$idSet = array_fill_keys($solution, true);
$selCents = 0;
echo PHP_EOL . 'Selected rows: ' . count($solution) . PHP_EOL;
echo 'Selected list:' . PHP_EOL;
foreach ($rows as $r) {
    if (isset($idSet[$r['id']])) {
        $selCents += $r['cents'];
        echo $r['id'] . ' | ' . number_format($r['total'], 2) . ' | SN ' . $r['serial_no'] . ' | ' . $r['item_description'] . PHP_EOL;
    }
}

echo 'Selected total: ' . number_format($selCents / 100, 2) . PHP_EOL;
echo 'Delta: ' . number_format(($selCents - $targetCents) / 100, 2) . PHP_EOL;

$csvPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'cs_fund01_subset_7000000_ids.csv';
$f = fopen($csvPath, 'w');
fputcsv($f, ['id']);
foreach ($solution as $id) {
    fputcsv($f, [$id]);
}
fclose($f);
echo 'Exported IDs: ' . $csvPath . PHP_EOL;

$m->close();
