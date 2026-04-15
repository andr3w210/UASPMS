<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');

$sql = "SELECT id, property_number, item_description, serial_no,
               ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total
        FROM rpcppe_batch_items
        WHERE batch_id=14
          AND fund_source='01'
          AND fund_number='1'
          AND account_code='1.06.05.140.00'
        ORDER BY id";
$res = $m->query($sql);

$out = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'techsci_fund01_rows.csv';
$f = fopen($out,'w');
fputcsv($f,['id','property_number','total','serial_no','item_description']);

while($r=$res->fetch_assoc()){
    fputcsv($f,[$r['id'],$r['property_number'],$r['total'],$r['serial_no'],$r['item_description']]);
}
fclose($f);

echo "Exported: $out\n";

$checks = [
    'Procurement of Maritime Virtual and Augmented Reality Laboratory',
    '54122000E8B45210170094',
    'Microwave Plasma',
    'MY24259001',
    'Brookfield Texture Analyzer CTX',
    'Water Purification System',
    'Electronic Microscope',
    'BJPX-H88BK',
    'Stomacher',
    'Dessicator Cabinet',
    'Furnace Exhaust Hood'
];

$stmt = $m->prepare("SELECT id, account_code, property_number, serial_no,
                           ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                           item_description
                    FROM rpcppe_batch_items
                    WHERE batch_id=14 AND (item_description LIKE ? OR serial_no LIKE ?)
                    ORDER BY id");

foreach($checks as $c){
    $like = '%' . $c . '%';
    $stmt->bind_param('ss',$like,$like);
    $stmt->execute();
    $rr = $stmt->get_result();
    echo "\nCHECK: $c\n";
    $found = false;
    while($row=$rr->fetch_assoc()){
        $found = true;
        echo "ID {$row['id']} | acct {$row['account_code']} | total {$row['total']} | SN {$row['serial_no']}\n";
        echo "  DESC: {$row['item_description']}\n";
    }
    if(!$found) echo "  (no match)\n";
}
$stmt->close();
$m->close();
