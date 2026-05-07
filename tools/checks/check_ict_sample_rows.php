<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$tokens = ['CN8AAD6047','CNL9CAN01G','DTBLNSP003507004349600','K2406N0023758','A960278700076','464776'];
$stmt = $m->prepare("SELECT id,batch_id,account_code,fund_code,fund_source,fund_number,property_number,serial_no,item_description,
                           ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total
                    FROM rpcppe_batch_items
                    WHERE batch_id=14 AND (serial_no LIKE ? OR item_description LIKE ? OR property_number LIKE ?)
                    ORDER BY id");
foreach($tokens as $t){
    $like='%'.$t.'%';
    $stmt->bind_param('sss',$like,$like,$like);
    $stmt->execute();
    $res=$stmt->get_result();
    echo "\nTOKEN $t\n";
    $found=false;
    while($r=$res->fetch_assoc()){
        $found=true;
        echo "ID {$r['id']} | acct {$r['account_code']} | fund_code {$r['fund_code']} | fund_source {$r['fund_source']} | fund_number {$r['fund_number']} | prop {$r['property_number']} | SN {$r['serial_no']} | total {$r['total']}\n";
    }
    if(!$found){ echo "(no match)\n"; }
}
$stmt->close();
$m->close();
