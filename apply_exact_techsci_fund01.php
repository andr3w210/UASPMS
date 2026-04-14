<?php
/*
Apply authoritative Technical & Scientific Equipment (Fund 01) set for batch 14
and target an exact total of 19,159,114.48.
*/

$m = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($m->connect_error) {
    die("Connection failed\n");
}

$target = 19159114.48;
$ids = [
    19604,19605,19606,19607,19608,19609,19610,19611,19612,19613,19614,19615,19616,19617,19618,
    19619,19620,19621,19622,19623,19624,19625,19626,19627,19628,19629,19630,19631,19632,19633,
    19634,19635,19636,19637,19638,19639,19643,19644,19787,19815,19817,19820,19821,19822,19824,
    19825,19831,19834,19835,19836,19841
];

$idCsv = implode(',', $ids);

$m->begin_transaction();
try {
    // 1) Demote current Fund 01 Technical & Scientific rows not in the authoritative set.
    $demoteSql = "UPDATE rpcppe_batch_items
                  SET account_code = NULL,
                      account_code_id = NULL,
                      account_name = NULL,
                      fund_code = NULL,
                      fund_source = NULL,
                      fund_number = NULL,
                      updated_at = NOW()
                  WHERE batch_id = 14
                    AND account_code = '1.06.05.140.00'
                    AND fund_source = '01'
                    AND fund_number = '1'
                    AND id NOT IN ($idCsv)";
    $m->query($demoteSql);
    $demoted = $m->affected_rows;

    // 2) Promote authoritative rows to Fund 01 Technical & Scientific.
    $promoteSql = "UPDATE rpcppe_batch_items
                   SET account_code = '1.06.05.140.00',
                       account_code_id = 62,
                       account_name = 'Technical and Scientific Equipment',
                       fund_code = 'GAA-AEP',
                       fund_source = '01',
                       fund_number = '1',
                       updated_at = NOW()
                   WHERE batch_id = 14
                     AND id IN ($idCsv)";
    $m->query($promoteSql);
    $promoted = $m->affected_rows;

    // 3) Correct incubator quantity to match the authoritative line item count.
    $fixQtySql = "UPDATE rpcppe_batch_items
                  SET qty_physical_count = 1,
                      qty_property_card = 1,
                      updated_at = NOW()
                  WHERE batch_id = 14
                    AND id = 19632";
    $m->query($fixQtySql);
    $qtyFixed = $m->affected_rows;

    // Verify final account total for Fund 01 scope.
    $verify = $m->query("SELECT COUNT(*) cnt,
                                COALESCE(SUM(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1)),0) total
                         FROM rpcppe_batch_items
                         WHERE batch_id=14
                           AND account_code='1.06.05.140.00'
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
    echo "Qty fixed rows: $qtyFixed\n";
    echo "Final Fund01 TechSci rows: {$verify['cnt']}\n";
    echo "Final Fund01 TechSci total: " . number_format($finalTotal, 2) . "\n";
    echo "Expected total: " . number_format($target, 2) . "\n";
    echo "Delta: " . number_format($delta, 2) . "\n";
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

$m->close();
