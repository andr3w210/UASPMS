<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$res = $m->query("SELECT id, ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                        LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),110) item_description
                 FROM rpcppe_batch_items
                 WHERE batch_id=14 AND id BETWEEN 19395 AND 19453
                 ORDER BY id");
$rows=[];
while($r=$res->fetch_assoc()){
    $rows[]=[
        'id'=>(int)$r['id'],
        'cents'=>(int)round((float)$r['total']*100),
        'total'=>(float)$r['total'],
        'desc'=>$r['item_description'],
    ];
}

$targets = [55500000, 50203000]; // cents

foreach($targets as $target){
    echo "=== TARGET EXCLUSION ".number_format($target/100,2)." ===".PHP_EOL;

    // DFS with pruning, looking for short combinations first.
    usort($rows, static fn($a,$b)=> $b['cents'] <=> $a['cents']);
    $n = count($rows);
    $found = 0;

    $dfs = function($start, $remain, $picked) use (&$dfs, &$rows, $n, &$found) {
        if ($remain === 0) {
            $found++;
            echo "Combo #$found | rows=".count($picked).PHP_EOL;
            $sum = 0;
            foreach($picked as $idx){
                $r = $rows[$idx];
                $sum += $r['cents'];
                echo "  ID {$r['id']} | ".number_format($r['total'],2)." | {$r['desc']}".PHP_EOL;
            }
            echo "  Combo total: ".number_format($sum/100,2).PHP_EOL.PHP_EOL;
            return;
        }
        if ($remain < 0) return;
        if ($start >= $n) return;
        if (count($picked) >= 6) return; // keep concise / plausible exclusions
        if ($found >= 10) return;

        for($i=$start; $i<$n; $i++){
            $c = $rows[$i]['cents'];
            if ($c > $remain) continue;
            $picked[] = $i;
            $dfs($i+1, $remain-$c, $picked);
            array_pop($picked);
            if ($found >= 10) return;
        }
    };

    $dfs(0, $target, []);
    if ($found === 0) echo "No combos found with <=6 exclusions.".PHP_EOL;
}

$m->close();
