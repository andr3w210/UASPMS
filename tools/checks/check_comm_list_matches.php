<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();

$patterns = [
    'HCQO01012',
    'HCQ',
    'EETL01522',
    'EETP01344',
    'ZA55630',
    'EMX512SC',
    'EMX 5014C',
    '600i',
    '600s',
    'Audio System (Sound System)',
    'Public Address System'
];

echo "Potential matches in batch 14:\n";
echo str_repeat('=', 120) . "\n";

$stmt = $m->prepare("SELECT id, fund_code, fund_source, fund_number, account_code, account_name, item_description, serial_no,
                           unit_cost, qty_physical_count,
                           ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total
                    FROM rpcppe_batch_items
                    WHERE batch_id=14
                      AND (item_description LIKE ? OR serial_no LIKE ?)
                    ORDER BY id");

$seen = [];
foreach ($patterns as $p) {
    $like = '%' . $p . '%';
    $stmt->bind_param('ss', $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        echo "ID {$r['id']} | acct {$r['account_code']} | fund {$r['fund_code']}/{$r['fund_source']}/{$r['fund_number']} | total " . number_format((float)$r['total'],2) . "\n";
        echo "  SN: {$r['serial_no']}\n";
        echo "  DESC: {$r['item_description']}\n";
    }
}

$stmt->close();

// Current communication equipment fund 01 total
$r = $m->query("SELECT COUNT(*) c, ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) t
                FROM rpcppe_batch_items
                WHERE batch_id=14 AND fund_source='01' AND fund_number='1' AND account_code='1.06.05.070.00'")->fetch_assoc();

echo "\nCurrent Fund01 Communication Equipment total: rows={$r['c']} total=" . number_format((float)$r['t'],2) . "\n";

$m->close();
