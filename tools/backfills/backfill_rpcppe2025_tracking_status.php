<?php
$db = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($db->connect_error) {
    die("Connection failed\n");
}

require_once dirname(__DIR__, 2) . '/spams/app/helpers/common.php';

ensure_legacy_assets_rpcppe_tracking_columns($db);
ensure_rpcppe_batch_tracking_columns($db);

$batchId = 14;
$sqlLog = [];

$db->begin_transaction();
try {
    $sql = "UPDATE rpcppe_batch_items
            SET reconciliation_status = CASE
                    WHEN is_included = 1 THEN 'included_draft'
                    ELSE 'excluded'
                END,
                submitted_to_accounting_at = NULL,
                reconciled_at = NULL
            WHERE batch_id = $batchId";
    $db->query($sql);
    $sqlLog[] = ['sql' => $sql, 'rows' => $db->affected_rows];

    $sql = "UPDATE rpcppe_batch_items
            SET reconciliation_status = 'submitted_to_accounting',
                submitted_to_accounting_at = COALESCE(submitted_to_accounting_at, NOW())
            WHERE batch_id = $batchId
              AND (
                    remarks LIKE '%Marked: RPCPPE 2025 submitted to Accounting%'
                    OR remarks LIKE '%RPCPPE2025-ACCT-SUB%'
                  )";
    $db->query($sql);
    $sqlLog[] = ['sql' => $sql, 'rows' => $db->affected_rows];

    $sql = "UPDATE rpcppe_batch_items
            SET reconciliation_status = 'reconciled',
                submitted_to_accounting_at = COALESCE(submitted_to_accounting_at, NOW()),
                reconciled_at = COALESCE(reconciled_at, NOW())
            WHERE batch_id = $batchId
              AND (
                    remarks LIKE '%RPCPPE_2025_LIST%'
                    OR remarks LIKE '%RPCPPE_2025_ICT_FUND01_LIST%'
                    OR remarks LIKE '%RPCPPE_2025_COMM_FUND01_LIST%'
                    OR (fund_source = '01' AND fund_number = '1' AND account_code IN (
                        '1.06.05.070.00',
                        '1.06.05.140.00',
                        '1.06.05.990.00',
                        '1.06.06.010.00',
                        '1.06.07.010.00',
                        '1.08.01.020.00'
                    ))
                  )";
    $db->query($sql);
    $sqlLog[] = ['sql' => $sql, 'rows' => $db->affected_rows];

    $sql = "UPDATE legacy_assets la
            INNER JOIN rpcppe_batch_items bi ON bi.legacy_asset_id = la.id
            SET la.is_rpcppe_candidate = bi.is_included,
                la.rpcppe_status = bi.reconciliation_status,
                la.rpcppe_batch_id = bi.batch_id,
                la.rpcppe_submitted_at = CASE
                    WHEN bi.reconciliation_status IN ('submitted_to_accounting', 'reconciled')
                        THEN COALESCE(la.rpcppe_submitted_at, bi.submitted_to_accounting_at, NOW())
                    ELSE NULL
                END,
                la.rpcppe_reconciled_at = CASE
                    WHEN bi.reconciliation_status = 'reconciled'
                        THEN COALESCE(la.rpcppe_reconciled_at, bi.reconciled_at, NOW())
                    ELSE NULL
                END
            WHERE bi.batch_id = $batchId";
    $db->query($sql);
    $sqlLog[] = ['sql' => $sql, 'rows' => $db->affected_rows];

    $verify = $db->query("SELECT reconciliation_status, COUNT(*) AS row_count
        FROM rpcppe_batch_items
        WHERE batch_id = $batchId
        GROUP BY reconciliation_status
        ORDER BY reconciliation_status")->fetch_all(MYSQLI_ASSOC);

    $db->commit();

    echo "Applied SQL updates:\n";
    foreach ($sqlLog as $entry) {
        echo "- rows={$entry['rows']}\n";
        echo $entry['sql'] . "\n\n";
    }

    echo "Batch $batchId reconciliation statuses:\n";
    foreach ($verify as $row) {
        echo "- {$row['reconciliation_status']}: {$row['row_count']}\n";
    }
} catch (Throwable $e) {
    $db->rollback();
    throw $e;
}

$db->close();
