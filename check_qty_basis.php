<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
$queries = [
    'range_qty_physical' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND id BETWEEN 19063 AND 19202",
    'range_qty_property' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_property_card,0),1)),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND id BETWEEN 19063 AND 19202",
    'range_unit_only' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND id BETWEEN 19063 AND 19202",
    'oe_qty_physical' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND account_code='1.06.05.020.00'",
    'oe_qty_property' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_property_card,0),1)),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND account_code='1.06.05.020.00'",
    'oe_unit_only' => "SELECT COUNT(*) cnt, COALESCE(SUM(unit_cost),0) total FROM rpcppe_batch_items WHERE batch_id=14 AND account_code='1.06.05.020.00'",
];
foreach ($queries as $k => $sql) {
    $r = $m->query($sql)->fetch_assoc();
    echo "$k => rows={$r['cnt']} total=" . number_format((float)$r['total'],2) . "\n";
}

echo "\nRows in 19063-19202 with qty != 1:\n";
$sql = "SELECT id, serial_no, unit_cost, qty_physical_count, qty_property_card,
               unit_cost*COALESCE(NULLIF(qty_physical_count,0),1) AS total_phys,
               unit_cost*COALESCE(NULLIF(qty_property_card,0),1) AS total_prop
        FROM rpcppe_batch_items
        WHERE batch_id=14 AND id BETWEEN 19063 AND 19202
          AND (COALESCE(NULLIF(qty_physical_count,0),1) <> 1 OR COALESCE(NULLIF(qty_property_card,0),1) <> 1)
        ORDER BY id";
$res = $m->query($sql);
while ($row = $res->fetch_assoc()) {
    echo "ID {$row['id']} | unit {$row['unit_cost']} | q_phys {$row['qty_physical_count']} | q_prop {$row['qty_property_card']} | total_phys {$row['total_phys']} | SN {$row['serial_no']}\n";
}
$m->close();
