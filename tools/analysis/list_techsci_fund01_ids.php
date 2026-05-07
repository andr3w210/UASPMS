<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$res = $m->query("SELECT id, ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total, serial_no, item_description
                  FROM rpcppe_batch_items
                  WHERE batch_id=14 AND fund_source='01' AND fund_number='1' AND account_code='1.06.05.140.00'
                  ORDER BY id");
while($r=$res->fetch_assoc()){
    $desc = preg_replace('/\s+/', ' ', $r['item_description']);
    if (strlen($desc) > 120) $desc = substr($desc,0,120) . '...';
    echo "ID {$r['id']} | " . number_format((float)$r['total'],2) . " | SN {$r['serial_no']} | {$desc}\n";
}
$m->close();
