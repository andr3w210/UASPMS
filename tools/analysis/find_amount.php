<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$target = (float)($argv[1] ?? 0);
$sql = "SELECT id, account_code, serial_no, brand, model, unit_cost, qty_physical_count,
               (unit_cost * COALESCE(NULLIF(qty_physical_count,0),1)) AS total
        FROM rpcppe_batch_items
        WHERE batch_id = 14
        ORDER BY id";
$res = $m->query($sql);
while ($row = $res->fetch_assoc()) {
    if ((float)$row['total'] === $target) {
        echo "ID {$row['id']} | acct {$row['account_code']} | total {$row['total']} | SN {$row['serial_no']} | {$row['brand']} {$row['model']}\n";
    }
}
$m->close();
