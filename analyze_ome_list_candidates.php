<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) die("Connection failed\n");

// Inspect the likely contiguous block where the provided list maps.
$res = $m->query("SELECT id, account_code, fund_code, fund_source, fund_number,
                        ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                        serial_no,
                        LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),140) item_description
                 FROM rpcppe_batch_items
                 WHERE batch_id=14 AND id BETWEEN 19390 AND 19460
                 ORDER BY id");

$rows = [];
while($r = $res->fetch_assoc()) {
    $rows[] = $r;
    echo $r['id']." | acct ".$r['account_code']." | fund ".$r['fund_code']."/".$r['fund_source']."/".$r['fund_number'].
         " | ".number_format((float)$r['total'],2)." | SN ".$r['serial_no']." | ".$r['item_description'].PHP_EOL;
}

$sets = [
    'block_19396_19453' => range(19396,19453),
    'block_19396_19453_plus_18840' => array_merge(range(19396,19453), [18840]),
    'block_19396_19453_plus_18840_20612' => array_merge(range(19396,19453), [18840,20612]),
    'probe_50_hit_ids' => [
        18705,18828,18991,19023,19035,19036,19037,19039,19056,19396,19397,19398,19400,19401,19403,19406,19407,19410,
        19416,19418,19420,19421,19422,19423,19424,19427,19429,19433,19434,19435,19436,19437,19438,19439,19440,19441,
        19442,19443,19446,19447,19448,19449,19450,19453,19458,19463,19464,19554,19841,20599
    ],
];

echo PHP_EOL."--- SET SUMS ---".PHP_EOL;
foreach ($sets as $name => $ids) {
    $ids = array_values(array_unique($ids));
    $idCsv = implode(',', $ids);
    $q = "SELECT COUNT(*) cnt,
                 COALESCE(SUM(ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2)),0) total
          FROM rpcppe_batch_items
          WHERE batch_id=14 AND id IN ($idCsv)";
    $r = $m->query($q)->fetch_assoc();
    echo $name." | rows=".$r['cnt']." | total=".number_format((float)$r['total'],2).PHP_EOL;
}

$target = 14859918.75;
$block = $m->query("SELECT id, account_code,
                           ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total
                    FROM rpcppe_batch_items
                    WHERE batch_id=14 AND id BETWEEN 19396 AND 19453");
$sumBlank = 0.0;
$sumNotBlank = 0.0;
while($b = $block->fetch_assoc()){
    if ($b['account_code'] === null || $b['account_code'] === '') $sumBlank += (float)$b['total'];
    else $sumNotBlank += (float)$b['total'];
}
echo "blank_in_block_19396_19453 | total=".number_format($sumBlank,2).PHP_EOL;
echo "notblank_in_block_19396_19453 | total=".number_format($sumNotBlank,2).PHP_EOL;
echo "delta(block-total minus target)=".number_format(15361948.75 - $target,2).PHP_EOL;

// Build candidate pool from this block + known linked rows and solve exact 14,859,918.75.
$candidateIds = array_values(array_unique(array_merge(range(19396,19453), [18840,20612,19414,19415,19419,19424,19433,19434,19435,19436,19437,19438,19440,19441,19443,19446,19447,19448])));
$idCsv2 = implode(',', $candidateIds);
$res2 = $m->query("SELECT id, ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total, LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),100) item_description
                  FROM rpcppe_batch_items
                  WHERE batch_id=14 AND id IN ($idCsv2)
                  ORDER BY id");

$pool = [];
while($r = $res2->fetch_assoc()){
    $pool[] = [
        'id'=>(int)$r['id'],
        'cents'=>(int)round((float)$r['total']*100),
        'total'=>(float)$r['total'],
        'desc'=>$r['item_description'],
    ];
}

$target = 14859918.75;
$targetCents = (int)round($target*100);
$n = count($pool);
$half = intdiv($n,2);
$left = array_slice($pool,0,$half);
$right = array_slice($pool,$half);

$leftMap = [];
$leftLimit = 1 << count($left);
for($mask=0;$mask<$leftLimit;$mask++){
    $sum=0; $ids=[]; $k=0; $bits=$mask;
    while($bits>0){
        if($bits&1){ $sum += $left[$k]['cents']; $ids[]=$left[$k]['id']; }
        $bits >>= 1; $k++;
    }
    if(!isset($leftMap[$sum]) || count($ids) < count($leftMap[$sum])) $leftMap[$sum]=$ids;
}

$rightMap = [];
$rightLimit = 1 << count($right);
for($mask=0;$mask<$rightLimit;$mask++){
    $sum=0; $ids=[]; $k=0; $bits=$mask;
    while($bits>0){
        if($bits&1){ $sum += $right[$k]['cents']; $ids[]=$right[$k]['id']; }
        $bits >>= 1; $k++;
    }
    if(!isset($rightMap[$sum]) || count($ids) < count($rightMap[$sum])) $rightMap[$sum]=$ids;
}

$sol = null;
foreach($leftMap as $sumL=>$idsL){
    $need = $targetCents - (int)$sumL;
    if(isset($rightMap[$need])){ $sol = array_values(array_unique(array_merge($idsL,$rightMap[$need]))); sort($sol); break; }
}

if($sol){
    $idSet = array_fill_keys($sol,true);
    $sum=0;
    echo PHP_EOL."Exact solution from candidate pool (rows=".count($sol).")".PHP_EOL;
    foreach($pool as $p){
        if(isset($idSet[$p['id']])){
            $sum += $p['cents'];
            echo $p['id']." | ".number_format($p['total'],2)." | ".$p['desc'].PHP_EOL;
        }
    }
    echo "Solution total: ".number_format($sum/100,2).PHP_EOL;
} else {
    echo PHP_EOL."No exact solution found in candidate pool.".PHP_EOL;
}

$m->close();
