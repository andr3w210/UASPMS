<?php

$db = new mysqli('127.0.0.1', 'root', '', 'spamsdb');
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

$sourceBatchId = 14;
$batchYear = 2025;
$batchName = 'RPCPPEE 2025 Final Reconciled';
$asOfDate = '2025-12-31';
$notes = 'Cloned from batch 14 reconciled RPCPPEE 2025 rows for final printing.';
$eligibleFunds = ['01', '05', '06', '07'];

$db->begin_transaction();

try {
    $batchStmt = $db->prepare("
        SELECT id, created_by, finalized_by
        FROM rpcppe_batches
        WHERE batch_year = ?
          AND batch_name = ?
          AND as_of_date = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $batchStmt->bind_param('iss', $batchYear, $batchName, $asOfDate);
    $batchStmt->execute();
    $existingBatch = $batchStmt->get_result()->fetch_assoc();
    $batchStmt->close();

    if ($existingBatch) {
        $targetBatchId = (int) $existingBatch['id'];
        $clearStmt = $db->prepare("DELETE FROM rpcppe_batch_items WHERE batch_id = ?");
        $clearStmt->bind_param('i', $targetBatchId);
        $clearStmt->execute();
        $deletedRows = $clearStmt->affected_rows;
        $clearStmt->close();

        $updateBatchStmt = $db->prepare("
            UPDATE rpcppe_batches
            SET status = 'finalized',
                notes = ?,
                finalized_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateBatchStmt->bind_param('si', $notes, $targetBatchId);
        $updateBatchStmt->execute();
        $updateBatchStmt->close();
    } else {
        $sourceMetaStmt = $db->prepare("
            SELECT created_by, finalized_by
            FROM rpcppe_batches
            WHERE id = ?
            LIMIT 1
        ");
        $sourceMetaStmt->bind_param('i', $sourceBatchId);
        $sourceMetaStmt->execute();
        $sourceMeta = $sourceMetaStmt->get_result()->fetch_assoc() ?: ['created_by' => null, 'finalized_by' => null];
        $sourceMetaStmt->close();

        $insertBatchStmt = $db->prepare("
            INSERT INTO rpcppe_batches (
                batch_year, batch_name, as_of_date, status, notes,
                created_by, finalized_by, finalized_at
            ) VALUES (?, ?, ?, 'finalized', ?, ?, ?, NOW())
        ");
        $insertBatchStmt->bind_param(
            'isssii',
            $batchYear,
            $batchName,
            $asOfDate,
            $notes,
            $sourceMeta['created_by'],
            $sourceMeta['finalized_by']
        );
        $insertBatchStmt->execute();
        $targetBatchId = (int) $insertBatchStmt->insert_id;
        $insertBatchStmt->close();
        $deletedRows = 0;
    }

    $inList = "'" . implode("','", array_map([$db, 'real_escape_string'], $eligibleFunds)) . "'";

    $copySql = "
        INSERT INTO rpcppe_batch_items (
            batch_id, source_type, distribution_item_detail_id, legacy_asset_id,
            property_number, item_name, item_name_id, item_description, description_detail,
            classification_name, classification_family, uom_name, abbreviation,
            unit_cost, acquisition_date, qty_property_card, qty_physical_count,
            brand, model, serial_no, office_id, office_name, employee_id, employee_name,
            account_code_id, account_code, account_name, fund_code, fund_source, fund_number,
            remarks, is_included, reconciliation_status, submitted_to_accounting_at, reconciled_at,
            is_disposed, disposed_at, created_at, updated_at
        )
        SELECT
            {$targetBatchId}, source_type, distribution_item_detail_id, legacy_asset_id,
            property_number, item_name, item_name_id, item_description, description_detail,
            classification_name, classification_family, uom_name, abbreviation,
            unit_cost, acquisition_date, qty_property_card, qty_physical_count,
            brand, model, serial_no, office_id, office_name, employee_id, employee_name,
            account_code_id, account_code, account_name, fund_code, fund_source, fund_number,
            remarks, is_included, reconciliation_status, submitted_to_accounting_at, reconciled_at,
            is_disposed, disposed_at, NOW(), NOW()
        FROM rpcppe_batch_items
        WHERE batch_id = {$sourceBatchId}
          AND reconciliation_status = 'reconciled'
          AND fund_source IN ({$inList})
    ";

    if (!$db->query($copySql)) {
        throw new RuntimeException("Copy failed: {$db->error}");
    }

    $insertedRows = $db->affected_rows;

    $verifyStmt = $db->prepare("
        SELECT
            COUNT(*) AS row_count,
            ROUND(SUM(
                CASE
                    WHEN COALESCE(qty_property_card, 0) > 1 THEN qty_property_card * unit_cost
                    ELSE unit_cost
                END
            ), 2) AS grand_total
        FROM rpcppe_batch_items
        WHERE batch_id = ?
    ");
    $verifyStmt->bind_param('i', $targetBatchId);
    $verifyStmt->execute();
    $verify = $verifyStmt->get_result()->fetch_assoc();
    $verifyStmt->close();

    $db->commit();

    echo "RPCPPEE 2025 final batch ready\n";
    echo "Source batch: {$sourceBatchId}\n";
    echo "Target batch: {$targetBatchId}\n";
    echo "Batch name: {$batchName}\n";
    echo "Deleted existing cloned rows: {$deletedRows}\n";
    echo "Inserted rows: {$insertedRows}\n";
    echo "Grand total: " . number_format((float) ($verify['grand_total'] ?? 0), 2, '.', ',') . "\n";
    echo "Row count: " . (int) ($verify['row_count'] ?? 0) . "\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Failed: {$e->getMessage()}\n");
    exit(1);
}

$db->close();
