<?php
require_once __DIR__ . '/../bootstrap.php';

$db = tools_db();

$map = [
    6508 => 'TEMP-VPAF-2026-0001',
    6509 => 'TEMP-VPAF-2026-0002',
    6510 => 'TEMP-VPAF-2026-0003',
];

$db->begin_transaction();

try {
    $stmtLa = $db->prepare('UPDATE legacy_assets SET property_number = ? WHERE id = ?');
    $stmtAt = $db->prepare('UPDATE asset_transfers SET property_number = ? WHERE legacy_asset_id = ?');
    $stmtTb = $db->prepare('UPDATE transfer_batch_items SET property_number = ? WHERE legacy_asset_id = ?');
    $stmtIc = $db->prepare('UPDATE inventory_count_items SET property_number = ? WHERE legacy_asset_id = ?');
    $stmtRp = $db->prepare('UPDATE rpcppe_batch_items SET property_number = ? WHERE legacy_asset_id = ?');

    if (!$stmtLa || !$stmtAt || !$stmtTb || !$stmtIc || !$stmtRp) {
        throw new RuntimeException('Failed to prepare one or more restore statements.');
    }

    foreach ($map as $id => $propertyNumber) {
        $legacyId = (int) $id;

        $stmtLa->bind_param('si', $propertyNumber, $legacyId);
        if (!$stmtLa->execute()) {
            throw new RuntimeException('legacy_assets update failed: ' . $stmtLa->error);
        }

        $stmtAt->bind_param('si', $propertyNumber, $legacyId);
        if (!$stmtAt->execute()) {
            throw new RuntimeException('asset_transfers update failed: ' . $stmtAt->error);
        }

        $stmtTb->bind_param('si', $propertyNumber, $legacyId);
        if (!$stmtTb->execute()) {
            throw new RuntimeException('transfer_batch_items update failed: ' . $stmtTb->error);
        }

        $stmtIc->bind_param('si', $propertyNumber, $legacyId);
        if (!$stmtIc->execute()) {
            throw new RuntimeException('inventory_count_items update failed: ' . $stmtIc->error);
        }

        $stmtRp->bind_param('si', $propertyNumber, $legacyId);
        if (!$stmtRp->execute()) {
            throw new RuntimeException('rpcppe_batch_items update failed: ' . $stmtRp->error);
        }
    }

    $stmtLa->close();
    $stmtAt->close();
    $stmtTb->close();
    $stmtIc->close();
    $stmtRp->close();

    $db->commit();

    echo 'temp_rows_restored=3' . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'restore_failed=' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$db->close();
