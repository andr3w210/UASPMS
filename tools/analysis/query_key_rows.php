<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
$res = $m->query("SELECT id, account_code, unit_cost, qty_physical_count,
                        (unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)) total,
                        serial_no
                 FROM rpcppe_batch_items
                 WHERE id IN (18943,18944,19064,19104,19199)
                 ORDER BY id");
while ($row = $res->fetch_assoc()) {
    $acct = $row['account_code'] ?? 'NULL';
    echo "ID {$row['id']} | acct {$acct} | total {$row['total']} | SN {$row['serial_no']}\n";
}
$m->close();
