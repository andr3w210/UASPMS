<?php
require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$batchId = 14;

$acct140 = $m->query("SELECT id, account_name FROM account_codes WHERE account_code='1.06.05.140.00' LIMIT 1")->fetch_assoc();
$acct990 = $m->query("SELECT id, account_name FROM account_codes WHERE account_code='1.06.05.990.00' LIMIT 1")->fetch_assoc();
if (!$acct140 || !$acct990) {
    die("Required account codes not found\n");
}

$m->begin_transaction();
try {
    // Map property numbers with 05-140 / 05.140 to Technical and Scientific Equipment.
    $stmt140 = $m->prepare("UPDATE rpcppe_batch_items
                            SET account_code_id = ?,
                                account_code = '1.06.05.140.00',
                                account_name = ?,
                                fund_code = 'GAA-AEP',
                                fund_source = '01',
                                fund_number = '1',
                                updated_at = NOW()
                            WHERE batch_id = ?
                              AND source_type = 'legacy'
                              AND (account_code = '' OR account_name = '' OR fund_code = '' OR fund_source = '' OR fund_number = '')
                              AND (property_number LIKE '%05-140%' OR property_number LIKE '%05.140%')");
    $id140 = (int)$acct140['id'];
    $name140 = (string)$acct140['account_name'];
    $stmt140->bind_param('isi', $id140, $name140, $batchId);
    $stmt140->execute();
    $fixed140 = $stmt140->affected_rows;
    $stmt140->close();

    // Map property numbers with 05-990 / 05.990 to Other Machinery and Equipment.
    $stmt990 = $m->prepare("UPDATE rpcppe_batch_items
                            SET account_code_id = ?,
                                account_code = '1.06.05.990.00',
                                account_name = ?,
                                fund_code = 'GAA-AEP',
                                fund_source = '01',
                                fund_number = '1',
                                updated_at = NOW()
                            WHERE batch_id = ?
                              AND source_type = 'legacy'
                              AND (account_code = '' OR account_name = '' OR fund_code = '' OR fund_source = '' OR fund_number = '')
                              AND (property_number LIKE '%05-990%' OR property_number LIKE '%05.990%')");
    $id990 = (int)$acct990['id'];
    $name990 = (string)$acct990['account_name'];
    $stmt990->bind_param('isi', $id990, $name990, $batchId);
    $stmt990->execute();
    $fixed990 = $stmt990->affected_rows;
    $stmt990->close();

    $left = $m->query("SELECT COUNT(*) c FROM rpcppe_batch_items
                       WHERE batch_id = $batchId
                         AND source_type = 'legacy'
                         AND (account_code = '' OR account_name = '' OR fund_code = '' OR fund_source = '' OR fund_number = '')")->fetch_assoc();

    $m->commit();

    echo 'fixed_05_140=' . (int)$fixed140 . PHP_EOL;
    echo 'fixed_05_990=' . (int)$fixed990 . PHP_EOL;
    echo 'blank_remaining=' . (int)$left['c'] . PHP_EOL;
} catch (Throwable $e) {
    $m->rollback();
    throw $e;
}

// Verify row snapshots for the repaired 206xx series.
$res = $m->query("SELECT id, property_number, account_code, fund_code, fund_source, fund_number,
                        ROUND(unit_cost*COALESCE(NULLIF(qty_physical_count,0),1),2) total,
                        LEFT(REPLACE(REPLACE(item_description,'\\r',' '),'\\n',' '),90) item_description
                 FROM rpcppe_batch_items
                 WHERE batch_id = $batchId
                   AND id BETWEEN 20600 AND 20625
                 ORDER BY id");
while($r = $res->fetch_assoc()) {
    echo $r['id'] . ' | ' . $r['account_code'] . ' | ' . $r['fund_code'] . '/' . $r['fund_source'] . '/' . $r['fund_number']
        . ' | ' . number_format((float)$r['total'],2) . ' | ' . $r['item_description'] . PHP_EOL;
}

$m->close();
