<?php
require_once __DIR__ . '/../spams/app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "DB connection failed\n");
    exit(1);
}

$poNumber = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);

if ($poNumber === '') {
    fwrite(STDERR, "Usage: php tools/revert_latest_receiving_po.php <po_number> [--apply]\n");
    exit(1);
}

$poStmt = $db->prepare("SELECT id, po_number, status FROM purchase_orders WHERE po_number = ? LIMIT 1");
if (!$poStmt) {
    fwrite(STDERR, "Prepare failed for PO lookup\n");
    exit(1);
}
$poStmt->bind_param('s', $poNumber);
$poStmt->execute();
$po = $poStmt->get_result()->fetch_assoc();
$poStmt->close();

if (!$po) {
    fwrite(STDERR, "PO not found: {$poNumber}\n");
    exit(1);
}

$recvStmt = $db->prepare(
    "SELECT id, system_reference, received_date, status, total_received_amount
     FROM receivings
     WHERE purchase_order_id = ?
     ORDER BY id DESC
     LIMIT 1"
);
if (!$recvStmt) {
    fwrite(STDERR, "Prepare failed for receiving lookup\n");
    exit(1);
}
$poId = (int) $po['id'];
$recvStmt->bind_param('i', $poId);
$recvStmt->execute();
$receiving = $recvStmt->get_result()->fetch_assoc();
$recvStmt->close();

if (!$receiving) {
    fwrite(STDERR, "No receiving found for PO {$poNumber}\n");
    exit(1);
}

$receivingId = (int) $receiving['id'];

$countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM receiving_items WHERE receiving_id = ?");
$countStmt->bind_param('i', $receivingId);
$countStmt->execute();
$itemCount = (int) (($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0));
$countStmt->close();

$stockCountStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM stock_items WHERE receiving_id = ?");
$stockCountStmt->bind_param('i', $receivingId);
$stockCountStmt->execute();
$stockCount = (int) (($stockCountStmt->get_result()->fetch_assoc()['cnt'] ?? 0));
$stockCountStmt->close();

$depStmt = $db->prepare(
    "SELECT
        (SELECT COUNT(*) FROM issuance_items ii INNER JOIN stock_items si ON si.id = ii.stock_item_id WHERE si.receiving_id = ?) AS issuance_refs,
        (SELECT COUNT(*) FROM distribution_items di INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id WHERE ri.receiving_id = ?) AS distribution_refs"
);
$depStmt->bind_param('ii', $receivingId, $receivingId);
$depStmt->execute();
$deps = $depStmt->get_result()->fetch_assoc() ?: ['issuance_refs' => 0, 'distribution_refs' => 0];
$depStmt->close();

$issuanceRefs = (int) ($deps['issuance_refs'] ?? 0);
$distributionRefs = (int) ($deps['distribution_refs'] ?? 0);

printf("PO: %s (id=%d, status=%s)\n", $po['po_number'], $poId, (string) $po['status']);
printf("Target receiving: id=%d, ref=%s, date=%s, status=%s, amount=%0.2f\n", $receivingId, (string) $receiving['system_reference'], (string) $receiving['received_date'], (string) $receiving['status'], (float) $receiving['total_received_amount']);
printf("Receiving items: %d\n", $itemCount);
printf("Stock items: %d\n", $stockCount);
printf("Dependencies -> issuances: %d, distributions: %d\n", $issuanceRefs, $distributionRefs);

if ($issuanceRefs > 0 || $distributionRefs > 0) {
    fwrite(STDERR, "Abort: receiving has downstream transactions; cannot safely revert automatically.\n");
    exit(2);
}

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to execute revert.\n";
    exit(0);
}

$db->begin_transaction();

try {
    $nullStmt = $db->prepare(
        "UPDATE receiving_item_details rid
         INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
         SET rid.stock_item_id = NULL
         WHERE ri.receiving_id = ?"
    );
    $nullStmt->bind_param('i', $receivingId);
    $nullStmt->execute();
    $nullStmt->close();

    $delMovStmt = $db->prepare(
        "DELETE sm
         FROM stock_movements sm
         INNER JOIN stock_items si ON si.id = sm.stock_item_id
         WHERE si.receiving_id = ?"
    );
    $delMovStmt->bind_param('i', $receivingId);
    $delMovStmt->execute();
    $delMovStmt->close();

    $delDetailsStmt = $db->prepare(
        "DELETE rid
         FROM receiving_item_details rid
         INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
         WHERE ri.receiving_id = ?"
    );
    $delDetailsStmt->bind_param('i', $receivingId);
    $delDetailsStmt->execute();
    $delDetailsStmt->close();

    $delStockStmt = $db->prepare("DELETE FROM stock_items WHERE receiving_id = ?");
    $delStockStmt->bind_param('i', $receivingId);
    $delStockStmt->execute();
    $delStockStmt->close();

    $delItemsStmt = $db->prepare("DELETE FROM receiving_items WHERE receiving_id = ?");
    $delItemsStmt->bind_param('i', $receivingId);
    $delItemsStmt->execute();
    $delItemsStmt->close();

    $delRecvStmt = $db->prepare("DELETE FROM receivings WHERE id = ?");
    $delRecvStmt->bind_param('i', $receivingId);
    $delRecvStmt->execute();
    $delRecvStmt->close();

    $statusStmt = $db->prepare(
        "SELECT
            SUM(poi.quantity) AS total_ordered,
            COALESCE((
                SELECT SUM(ri2.quantity_delivered)
                FROM receiving_items ri2
                INNER JOIN receivings r2 ON r2.id = ri2.receiving_id
                WHERE r2.purchase_order_id = ?
                  AND r2.status != 'cancelled'
            ), 0) AS total_delivered
         FROM purchase_order_items poi
         WHERE poi.purchase_order_id = ?"
    );
    $statusStmt->bind_param('ii', $poId, $poId);
    $statusStmt->execute();
    $statusRow = $statusStmt->get_result()->fetch_assoc() ?: ['total_ordered' => 0, 'total_delivered' => 0];
    $statusStmt->close();

    $totalOrdered = (float) ($statusRow['total_ordered'] ?? 0);
    $totalDelivered = (float) ($statusRow['total_delivered'] ?? 0);

    if ($totalOrdered > 0 && $totalDelivered >= $totalOrdered) {
        $poStatus = 'completed';
    } elseif ($totalDelivered > 0) {
        $poStatus = 'partial';
    } else {
        $poStatus = 'encoded';
    }

    $updPoStmt = $db->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
    $updPoStmt->bind_param('si', $poStatus, $poId);
    $updPoStmt->execute();
    $updPoStmt->close();

    $db->commit();
    printf("Revert complete. Deleted receiving id=%d (%s). PO status is now %s.\n", $receivingId, (string) $receiving['system_reference'], $poStatus);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Revert failed: " . $e->getMessage() . "\n");
    exit(1);
}
