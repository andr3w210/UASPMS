<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$targetDelta = 3828550.00; // target - current Fund01 OME
$targetCents = (int)round($targetDelta*100);

$res = $m->query("SELECT id, ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                        LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),120) item_description
                 FROM rpcppe_batch_items
                 WHERE batch_id=14 AND id BETWEEN 19396 AND 19453
                   AND (account_code IS NULL OR account_code='')
                 ORDER BY id");

$rows=[];
while($r=$res->fetch_assoc()){
    $rows[]=[
        'id'=>(int)$r['id'],
        'total'=>(float)$r['total'],
        'cents'=>(int)round((float)$r['total']*100),
        'desc'=>$r['item_description'],
    ];
}

$n=count($rows);
$best=null;
$limit=1<<$n;
for($mask=1;$mask<$limit;$mask++){
    $sum=0; $ids=[]; $count=0;
    for($i=0;$i<$n;$i++){
        if($mask & (1<<$i)){
            $sum += $rows[$i]['cents'];
            $ids[] = $rows[$i]['id'];
            $count++;
        }
    }
    if($sum === $targetCents){
        if($best===null || $count < $best['count']){
            $best=['ids'=>$ids,'count'=>$count];
        }
    }
}

if($best===null){
    echo "No exact subset for delta.".PHP_EOL;
    exit;
}

$idSet=array_fill_keys($best['ids'],true);
$sum=0;
echo "Exact subset rows=".$best['count'].PHP_EOL;
foreach($rows as $r){
    if(isset($idSet[$r['id']])){
        $sum += $r['cents'];
        echo $r['id']." | ".number_format($r['total'],2)." | ".$r['desc'].PHP_EOL;
    }
}
echo "Subset total: ".number_format($sum/100,2).PHP_EOL;

$m->close();
