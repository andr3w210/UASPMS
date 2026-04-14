<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$target = 7000000.00;
$targetCents = (int)round($target * 100);

$sql = "SELECT id,
               ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) AS total,
               item_description,
               serial_no
        FROM rpcppe_batch_items
        WHERE batch_id=14
          AND account_code='1.06.05.990.00'
          AND fund_source='01'
          AND fund_number='1'
        ORDER BY id";

$res = $m->query($sql);
$rows = [];
while ($r = $res->fetch_assoc()) {
    $rows[] = [
        'id' => (int)$r['id'],
        'cents' => (int)round((float)$r['total'] * 100),
        'total' => (float)$r['total'],
        'item_description' => (string)$r['item_description'],
        'serial_no' => (string)$r['serial_no'],
    ];
}

$n = count($rows);
$half = intdiv($n, 2);
$left = array_slice($rows, 0, $half);
$right = array_slice($rows, $half);

$leftSubs = [];
$leftCount = count($left);
$leftLimit = 1 << $leftCount;
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
    if (!isset($leftSubs[$sum]) || count($ids) < count($leftSubs[$sum])) {
        $leftSubs[$sum] = $ids;
    }
}

$rightBest = [];
$rightCount = count($right);
$rightLimit = 1 << $rightCount;
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
    if (!isset($rightBest[$sum]) || count($ids) < count($rightBest[$sum])) {
        $rightBest[$sum] = $ids;
    }
}

$solutionIds = null;
foreach ($leftSubs as $sumL => $idsL) {
    $need = $targetCents - (int)$sumL;
    if (isset($rightBest[$need])) {
        $solutionIds = array_values(array_unique(array_merge($idsL, $rightBest[$need])));
        sort($solutionIds);
        break;
    }
}

$currentTotal = 0;
foreach ($rows as $r) {
    $currentTotal += $r['cents'];
}

echo 'Current rows: '.$n.PHP_EOL;
echo 'Current total: '.number_format($currentTotal / 100, 2).PHP_EOL;
echo 'Target total: '.number_format($target, 2).PHP_EOL;

if ($solutionIds === null) {
    echo 'No exact subset found for target.'.PHP_EOL;
    $m->close();
    exit(1);
}

$idSet = array_fill_keys($solutionIds, true);
$selTotal = 0;
$selectedRows = [];
foreach ($rows as $r) {
    if (isset($idSet[$r['id']])) {
        $selTotal += $r['cents'];
        $selectedRows[] = $r;
    }
}

echo 'Selected rows: '.count($selectedRows).PHP_EOL;
echo 'Selected total: '.number_format($selTotal / 100, 2).PHP_EOL;
echo 'Delta: '.number_format(($selTotal - $targetCents) / 100, 2).PHP_EOL;

echo PHP_EOL.'Selected IDs and totals:'.PHP_EOL;
foreach ($selectedRows as $r) {
    $desc = trim(str_replace(["\r", "\n"], ' ', $r['item_description']));
    echo $r['id'].' | '.number_format($r['total'], 2).' | SN '.$r['serial_no'].' | '.substr($desc, 0, 140).PHP_EOL;
}

$csvPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'ome_fund01_subset_7000000_ids.csv';
$fh = fopen($csvPath, 'w');
fputcsv($fh, ['id']);
foreach ($solutionIds as $id) {
    fputcsv($fh, [$id]);
}
fclose($fh);

echo PHP_EOL.'Exported IDs: '.$csvPath.PHP_EOL;

$m->close();
