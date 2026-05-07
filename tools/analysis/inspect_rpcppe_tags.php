<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$res = $m->query("SELECT remarks, COUNT(*) c,
                        ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) t
                 FROM rpcppe_batch_items
                 WHERE batch_id=14
                   AND remarks IS NOT NULL
                   AND (remarks LIKE '%RPCPPE%' OR remarks LIKE '%RCPPEE%')
                 GROUP BY remarks
                 ORDER BY c DESC");
while($r=$res->fetch_assoc()){
    echo $r['c'].' | '.number_format((float)$r['t'],2).' | '.$r['remarks'].PHP_EOL;
}

$m->close();
