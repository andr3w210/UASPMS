<?php
$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
$res = $m->query("SELECT id, account_code, item_description, serial_no, unit_cost, qty_physical_count,
                        (unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)) total
                 FROM rpcppe_batch_items
                 WHERE id IN (18943,18944,19104,19199)");
while ($r = $res->fetch_assoc()) {
    echo "\nID {$r['id']} | acct " . ($r['account_code'] ?? 'NULL') . " | total {$r['total']}\n";
    echo "SN: {$r['serial_no']}\n";
    echo "DESC: {$r['item_description']}\n";
}
$m->close();
