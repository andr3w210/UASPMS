<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($mysqli->connect_error) {
    die("Connection failed\n");
}

$batchId = 14;

$queries = [
    'current_oe' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                     FROM rpcppe_batch_items WHERE batch_id=$batchId AND account_code='1.06.05.020.00'",
    'id_19063_19202' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items WHERE batch_id=$batchId AND id BETWEEN 19063 AND 19202",
    'id_19063_19199' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items WHERE batch_id=$batchId AND id BETWEEN 19063 AND 19199",
    'id_19063_19198' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items WHERE batch_id=$batchId AND id BETWEEN 19063 AND 19198",
    'id_19063_19177' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items WHERE batch_id=$batchId AND id BETWEEN 19063 AND 19177",
];

foreach ($queries as $name => $sql) {
    $r = $mysqli->query($sql)->fetch_assoc();
    echo "$name => rows={$r['cnt']} total=" . number_format((float)$r['total'],2) . "\n";
}

echo "\nPotential high-value list-like rows in batch 14:\n";
$sql = "SELECT id, account_code, serial_no, brand, model, item_description, unit_cost, qty_physical_count,
               (unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)) total
        FROM rpcppe_batch_items
        WHERE batch_id=$batchId
          AND (
            item_description LIKE '%LED SCREEN WITH CABINET%'
            OR item_description LIKE '%P 3.91 Indoor Full Color Led Screen%'
            OR item_description LIKE '%LED WALL%'
            OR item_description LIKE '%ABRAM%'
            OR serial_no LIKE '%2KUA03C07W0A10003277%'
            OR serial_no LIKE '%2KUA03C07W0A10003347%'
            OR item_description LIKE '%Defender%'
            OR item_description LIKE '%3016-B%'
          )
        ORDER BY id";
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    echo "ID {$row['id']} | acct {$row['account_code']} | total " . number_format((float)$row['total'],2) . " | SN {$row['serial_no']} | {$row['brand']} {$row['model']}\n";
}

$mysqli->close();
