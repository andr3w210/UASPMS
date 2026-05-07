<?php
require_once __DIR__ . '/../bootstrap.php';
/*
Apply authoritative Other Machinery and Equipment (Fund 01) set for batch 14
and target an exact total of 14,859,918.75.
*/

$m = tools_db();
if ($m->connect_error) {
    die("Connection failed\n");
}

$target = 14859918.75;

$ids = [];
for ($i = 19395; $i <= 19450; $i++) {
    $ids[] = $i;
}
$ids[] = 19453;

$idCsv = implode(',', $ids);

$m->begin_transaction();
try {
    // 1) Demote current Fund 01 OME rows not in the authoritative set.
    $demoteSql = "UPDATE rpcppe_batch_items
                  SET account_code = NULL,
                      account_code_id = NULL,
                      account_name = NULL,
                      fund_code = NULL,
                      fund_source = NULL,
                      fund_number = NULL,
                      updated_at = NOW()
                  WHERE batch_id = 14
                    AND account_code = '1.06.05.990.00'
                    AND fund_source = '01'
                    AND fund_number = '1'
                    AND id NOT IN ($idCsv)";
    $m->query($demoteSql);
    $demoted = $m->affected_rows;

    // 2) Promote authoritative list rows to Fund 01 OME.
    $promoteSql = "UPDATE rpcppe_batch_items
                   SET account_code = '1.06.05.990.00',
                       account_code_id = 30,
                       account_name = 'Other Machinery and Equipment',
                       fund_code = 'GAA-AEP',
                       fund_source = '01',
                       fund_number = '1',
                       updated_at = NOW()
                   WHERE batch_id = 14
                     AND id IN ($idCsv)";
    $m->query($promoteSql);
    $promoted = $m->affected_rows;

    // 3) Verify exact scope total.
    $verify = $m->query("SELECT COUNT(*) cnt,
                                COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items
                         WHERE batch_id=14
                           AND account_code='1.06.05.990.00'
                           AND fund_source='01'
                           AND fund_number='1'")->fetch_assoc();

    $finalTotal = (float)$verify['total'];
    $delta = $finalTotal - $target;
    if (abs($delta) > 0.0001) {
        throw new RuntimeException('Verification failed: target mismatch after update.');
    }

    $m->commit();

    echo "Demoted rows: $demoted\n";
    echo "Promoted rows: $promoted\n";
    echo "Final Fund01 OME rows: {$verify['cnt']}\n";
    echo "Final Fund01 OME total: " . number_format($finalTotal, 2) . "\n";
    echo "Expected total: " . number_format($target, 2) . "\n";
    echo "Delta: " . number_format($delta, 2) . "\n";
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

$m->close();
