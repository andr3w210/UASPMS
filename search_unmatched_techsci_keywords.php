<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
$keywords = [
 'Laboratory table',
 'Demonstration Table',
 'FM 100F',
 'FM02',
 'FM17',
 'FM08A',
 'FM03',
 'FM18',
 'FM23',
 'FM04',
 'DV-1+PRO',
 '54122000E8B45210170094',
 'AR VR Web Platform',
 'Brookfield',
 'TA-TPB',
 'Water Purification System',
 'BJPX-H88BK',
 'Stomacher',
 'Dessicator Cabinet',
 'Furnace Exhaust Hood',
 'DMF-03',
 'Interscience'
];

$stmt = $m->prepare("SELECT id, fund_code, fund_source, fund_number, account_code, property_number, serial_no,
                            ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                            item_description
                     FROM rpcppe_batch_items
                     WHERE batch_id=14 AND (item_description LIKE ? OR serial_no LIKE ?)
                     ORDER BY id");

$seen=[];
foreach($keywords as $k){
  $like='%'.$k.'%';
  $stmt->bind_param('ss',$like,$like);
  $stmt->execute();
  $res=$stmt->get_result();
  echo "\nKEYWORD: $k\n";
  $found=false;
  while($r=$res->fetch_assoc()){
    $found=true;
    $id=(int)$r['id'];
    $dup = isset($seen[$id]) ? '*' : ' ';
    $seen[$id]=true;
    echo "$dup ID {$r['id']} | acct {$r['account_code']} | fund {$r['fund_code']}/{$r['fund_source']}/{$r['fund_number']} | total {$r['total']} | SN {$r['serial_no']}\n";
  }
  if(!$found) echo "  (no match)\n";
}
$stmt->close();
$m->close();
