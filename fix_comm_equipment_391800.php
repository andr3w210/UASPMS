<?php
$m = new mysqli('127.0.0.1','root','','spamsdb');
if ($m->connect_error) {
    die('Connection failed: ' . $m->connect_error . PHP_EOL);
}

$tag = 'RCPPEE_2025_COMM_FUND01_LIST';
$ids = [18576,18577,18578,18579,18580];
$idCsv = implode(',', $ids);

// Resolve account metadata
$acc = $m->query("SELECT id, account_name FROM account_codes WHERE account_code='1.06.05.070.00' LIMIT 1")->fetch_assoc();
$accountId = $acc ? (int)$acc['id'] : null;
$accountName = $acc ? $acc['account_name'] : 'Communication Equipment';

$m->begin_transaction();
try {
    // Assign all 5 rows to Fund 01 Communication Equipment (only id 18580 needs this, but keeping deterministic).
    $stmt = $m->prepare("UPDATE rpcppe_batch_items
                         SET account_code_id = ?,
                             account_code = '1.06.05.070.00',
                             account_name = ?,
                             fund_code = 'GAA-AEP',
                             fund_source = '01',
                             fund_number = '1',
                             updated_at = NOW()
                         WHERE id IN ($idCsv)");
    $stmt->bind_param('is', $accountId, $accountName);
    $stmt->execute();
    $stmt->close();

    // Tag these rows for reporting.
    $m->query("UPDATE rpcppe_batch_items
               SET remarks = CASE
                    WHEN remarks IS NULL OR remarks = '' THEN '$tag'
                    WHEN remarks LIKE '%$tag%' THEN remarks
                    ELSE CONCAT(remarks, ' | $tag')
               END,
               is_included = 1,
               updated_at = NOW()
               WHERE id IN ($idCsv)");

    // Verify Fund 01 Communication Equipment total.
    $r = $m->query("SELECT COUNT(*) c,
                           ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) t
                    FROM rpcppe_batch_items
                    WHERE batch_id=14
                      AND fund_source='01'
                      AND fund_number='1'
                      AND account_code='1.06.05.070.00'")->fetch_assoc();

    // Verify tagged-list subtotal (should be 391,800)
    $t = $m->query("SELECT COUNT(*) c,
                           ROUND(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),2) t
                    FROM rpcppe_batch_items
                    WHERE id IN ($idCsv)")->fetch_assoc();

    $m->commit();

    echo "Updated IDs: " . implode(',', $ids) . PHP_EOL;
    echo "List rows count: {$t['c']} | list total: " . number_format((float)$t['t'],2) . PHP_EOL;
    echo "Fund01 Comm Equipment rows: {$r['c']} | total: " . number_format((float)$r['t'],2) . PHP_EOL;
    echo "Target total: 391,800.00 | delta: " . number_format((float)$t['t'] - 391800.00,2) . PHP_EOL;
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

$m->close();
