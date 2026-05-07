<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$patterns = ['K2406','DTBLNSP','CN8AAD','CNL9CAN01G','A960278700076','NXB18SP00D5232','X6N705','X6N706','XN19CQ00045'];
foreach($patterns as $p){
    $like='%'.$p.'%';
    $stmt=$m->prepare("SELECT COUNT(*) c FROM rpcppe_batch_items WHERE batch_id=14 AND (serial_no LIKE ? OR item_description LIKE ? OR property_number LIKE ?)");
    $stmt->bind_param('sss',$like,$like,$like);
    $stmt->execute();
    $c=$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo "$p => $c\n";
}

echo "\nCurrent tagged totals by account:\n";
$sql="SELECT COALESCE(account_code,'(blank)') account_code, COALESCE(account_name,'(blank)') account_name,
             COUNT(*) AS row_count, ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) AS total
      FROM rpcppe_batch_items
      WHERE batch_id=14 AND remarks LIKE '%RCPPEE_2025_ICT_FUND01_LIST%'
      GROUP BY account_code, account_name
      ORDER BY account_code";
$res=$m->query($sql);
while($r=$res->fetch_assoc()){
    echo "{$r['account_code']} | {$r['row_count']} | " . number_format((float)$r['total'],2) . "\n";
}
$m->close();
