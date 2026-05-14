<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$db = db();
$page_title = 'Correct Receiving';
$flash = get_flash();
$errors = [];
$receiving = null;
$items = [];
$receivingId = (int) ($_GET['id'] ?? $_POST['receiving_id'] ?? 0);

if (!$db) {
    set_flash('error', 'Unable to connect to the database.');
    redirect('modules/receivings/index.php');
}

if ($receivingId <= 0) {
    set_flash('error', 'Invalid receiving reference for correction.');
    redirect('modules/receivings/index.php');
}

$loadReceiving = static function (mysqli $dbConn, int $id): ?array {
    $stmt = $dbConn->prepare(
        "SELECT r.id, r.system_reference, r.ris_no, r.received_date, r.status, r.total_received_amount,
                r.purchase_order_id, po.po_number, s.supplier_name
         FROM receivings r
         INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
         INNER JOIN suppliers s ON s.id = po.supplier_id
         WHERE r.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
};

$refreshPoStatus = static function (mysqli $dbConn, int $purchaseOrderId): void {
    $remainingStmt = $dbConn->prepare(
        "SELECT
            COUNT(*) AS total_lines,
            SUM(CASE WHEN calc.remaining_qty <= 0.0001 THEN 1 ELSE 0 END) AS completed_lines,
            COALESCE(SUM(calc.accepted_qty), 0) AS accepted_qty
         FROM (
             SELECT poi.id,
                    COALESCE(SUM(ri.quantity_accepted), 0) AS accepted_qty,
                    GREATEST(poi.quantity - COALESCE(SUM(ri.quantity_accepted), 0), 0) AS remaining_qty
             FROM purchase_order_items poi
             LEFT JOIN receiving_items ri
                ON ri.purchase_order_item_id = poi.id
             LEFT JOIN receivings r
                ON r.id = ri.receiving_id
               AND r.status != 'cancelled'
             WHERE poi.purchase_order_id = ?
             GROUP BY poi.id, poi.quantity
         ) AS calc"
    );
    if (!$remainingStmt) {
        return;
    }

    $remainingStmt->bind_param('i', $purchaseOrderId);
    $remainingStmt->execute();
    $remaining = $remainingStmt->get_result()->fetch_assoc() ?: [];
    $remainingStmt->close();

    $totalLines = (int) ($remaining['total_lines'] ?? 0);
    $completedLines = (int) ($remaining['completed_lines'] ?? 0);
    $acceptedQty = (float) ($remaining['accepted_qty'] ?? 0);

    $status = 'encoded';
    if ($acceptedQty > 0.0001) {
        $status = ($totalLines > 0 && $completedLines >= $totalLines) ? 'completed' : 'partial';
    }

    $updatePo = $dbConn->prepare('UPDATE purchase_orders SET status = ? WHERE id = ?');
    if ($updatePo) {
        $updatePo->bind_param('si', $status, $purchaseOrderId);
        $updatePo->execute();
        $updatePo->close();
    }
};

$refreshReceivingStatus = static function (mysqli $dbConn, int $receivingRefId) use ($refreshPoStatus): void {
    $statusStmt = $dbConn->prepare(
        "SELECT
            r.purchase_order_id,
            COALESCE(SUM(ri.line_total), 0) AS total_amount
         FROM receivings r
         LEFT JOIN receiving_items ri ON ri.receiving_id = r.id
         WHERE r.id = ?
         GROUP BY r.id, r.purchase_order_id"
    );

    if (!$statusStmt) {
        return;
    }

    $statusStmt->bind_param('i', $receivingRefId);
    $statusStmt->execute();
    $data = $statusStmt->get_result()->fetch_assoc() ?: null;
    $statusStmt->close();
    if (!$data) {
        return;
    }

    $purchaseOrderId = (int) $data['purchase_order_id'];
    $totalAmount = (float) $data['total_amount'];

    $remainingStmt = $dbConn->prepare(
        "SELECT
            COUNT(*) AS total_lines,
            SUM(CASE WHEN calc.remaining_qty <= 0.0001 THEN 1 ELSE 0 END) AS completed_lines,
            COALESCE(SUM(calc.accepted_qty), 0) AS accepted_qty
         FROM (
             SELECT poi.id,
                    COALESCE(SUM(ri.quantity_accepted), 0) AS accepted_qty,
                    GREATEST(poi.quantity - COALESCE(SUM(ri.quantity_accepted), 0), 0) AS remaining_qty
             FROM purchase_order_items poi
             LEFT JOIN receiving_items ri
                ON ri.purchase_order_item_id = poi.id
             LEFT JOIN receivings r
                ON r.id = ri.receiving_id
               AND r.status != 'cancelled'
             WHERE poi.purchase_order_id = ?
             GROUP BY poi.id, poi.quantity
         ) AS calc"
    );
    if (!$remainingStmt) {
        return;
    }

    $remainingStmt->bind_param('i', $purchaseOrderId);
    $remainingStmt->execute();
    $remaining = $remainingStmt->get_result()->fetch_assoc() ?: [];
    $remainingStmt->close();

    $totalLines = (int) ($remaining['total_lines'] ?? 0);
    $completedLines = (int) ($remaining['completed_lines'] ?? 0);
    $acceptedQty = (float) ($remaining['accepted_qty'] ?? 0);

    $status = 'partial';
    if ($acceptedQty <= 0.0001) {
        $status = 'partial';
    } elseif ($totalLines > 0 && $completedLines >= $totalLines) {
        $status = 'completed';
    }

    $updateReceiving = $dbConn->prepare('UPDATE receivings SET total_received_amount = ?, status = ? WHERE id = ?');
    if ($updateReceiving) {
        $updateReceiving->bind_param('dsi', $totalAmount, $status, $receivingRefId);
        $updateReceiving->execute();
        $updateReceiving->close();
    }

    $refreshPoStatus($dbConn, $purchaseOrderId);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'zero_line') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    $itemId = (int) ($_POST['receiving_item_id'] ?? 0);
    if ($itemId <= 0) {
        $errors[] = 'Invalid receiving item selected.';
    }

    if (!$errors) {
        $db->begin_transaction();
        try {
            $itemStmt = $db->prepare(
                'SELECT id, receiving_id FROM receiving_items WHERE id = ? AND receiving_id = ? LIMIT 1 FOR UPDATE'
            );
            if (!$itemStmt) {
                throw new RuntimeException('Unable to lock receiving item.');
            }
            $itemStmt->bind_param('ii', $itemId, $receivingId);
            $itemStmt->execute();
            $lockedItem = $itemStmt->get_result()->fetch_assoc() ?: null;
            $itemStmt->close();
            if (!$lockedItem) {
                throw new RuntimeException('Receiving item not found.');
            }

            $distributionStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM distribution_items WHERE receiving_item_id = ?');
            if (!$distributionStmt) {
                throw new RuntimeException('Unable to check distribution dependencies.');
            }
            $distributionStmt->bind_param('i', $itemId);
            $distributionStmt->execute();
            $distributionCount = (int) (($distributionStmt->get_result()->fetch_assoc()['cnt'] ?? 0));
            $distributionStmt->close();

            $issuedStmt = $db->prepare('SELECT COALESCE(SUM(quantity_issued), 0) AS qty FROM stock_items WHERE receiving_item_id = ?');
            if (!$issuedStmt) {
                throw new RuntimeException('Unable to check issuance dependencies.');
            }
            $issuedStmt->bind_param('i', $itemId);
            $issuedStmt->execute();
            $issuedQty = (float) (($issuedStmt->get_result()->fetch_assoc()['qty'] ?? 0));
            $issuedStmt->close();

            if ($distributionCount > 0 || $issuedQty > 0.0001) {
                throw new RuntimeException('Cannot auto-correct this line because it is already used in distribution or issuance.');
            }

            $deleteDetails = $db->prepare('DELETE FROM receiving_item_details WHERE receiving_item_id = ?');
            if ($deleteDetails) {
                $deleteDetails->bind_param('i', $itemId);
                $deleteDetails->execute();
                $deleteDetails->close();
            }

            $stockIds = [];
            $stockResultStmt = $db->prepare('SELECT id FROM stock_items WHERE receiving_item_id = ?');
            if ($stockResultStmt) {
                $stockResultStmt->bind_param('i', $itemId);
                $stockResultStmt->execute();
                $stockResult = $stockResultStmt->get_result();
                while ($row = $stockResult ? $stockResult->fetch_assoc() : null) {
                    $stockIds[] = (int) $row['id'];
                }
                $stockResultStmt->close();
            }

            if ($stockIds) {
                $stockIdsSql = implode(',', array_map('intval', $stockIds));
                $db->query('DELETE FROM stock_movements WHERE stock_item_id IN (' . $stockIdsSql . ')');
            }

            $deleteStock = $db->prepare('DELETE FROM stock_items WHERE receiving_item_id = ?');
            if ($deleteStock) {
                $deleteStock->bind_param('i', $itemId);
                $deleteStock->execute();
                $deleteStock->close();
            }

            $zeroStmt = $db->prepare(
                'UPDATE receiving_items SET quantity_delivered = 0, quantity_accepted = 0, quantity_rejected = 0, line_total = 0 WHERE id = ?'
            );
            if (!$zeroStmt) {
                throw new RuntimeException('Unable to update receiving item quantities.');
            }
            $zeroStmt->bind_param('i', $itemId);
            $zeroStmt->execute();
            $zeroStmt->close();

            $refreshReceivingStatus($db, $receivingId);

            $db->commit();
            set_flash('success', 'Receiving line corrected: delivered/accepted/rejected set to 0 and linked stock snapshot removed.');
            redirect('modules/receivings/correct_receiving.php?id=' . $receivingId);
        } catch (Throwable $exception) {
            $db->rollback();
            $errors[] = $exception->getMessage();
        }
    }
}

$receiving = $loadReceiving($db, $receivingId);
if (!$receiving) {
    set_flash('error', 'Receiving record not found.');
    redirect('modules/receivings/index.php');
}

$itemStmt = $db->prepare(
    "SELECT
        ri.id,
        ri.quantity_delivered,
        ri.quantity_accepted,
        ri.quantity_rejected,
        ri.line_total,
        poi.item_no,
        poi.item_description,
        poi.unit,
        poi.unit_cost,
        COALESCE((SELECT COUNT(*) FROM distribution_items di WHERE di.receiving_item_id = ri.id), 0) AS distribution_count,
        COALESCE((SELECT SUM(si.quantity_issued) FROM stock_items si WHERE si.receiving_item_id = ri.id), 0) AS issued_qty,
        COALESCE((SELECT COUNT(*) FROM stock_items s2 WHERE s2.receiving_item_id = ri.id), 0) AS stock_count
     FROM receiving_items ri
     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
     WHERE ri.receiving_id = ?
     ORDER BY ri.id ASC"
);

if ($itemStmt) {
    $itemStmt->bind_param('i', $receivingId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();
    $items = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    if ($result) {
        $result->free();
    }
    $itemStmt->close();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="workspace-section py-4">
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h5 class="mb-1">Correct Receiving Line</h5>
                <div class="small text-muted">
                    Reference: <?php echo h($receiving['system_reference']); ?> |
                    RIS: <?php echo h($receiving['ris_no'] ?? 'N/A'); ?> |
                    PO: <?php echo h($receiving['po_number']); ?>
                </div>
                <div class="small text-muted">Supplier: <?php echo h($receiving['supplier_name']); ?></div>
            </div>
            <div>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo base_url('modules/receivings/index.php'); ?>">Back to Receivings</a>
            </div>
        </div>
    </div>

    <?php if (!empty($flash['success'])): ?>
        <div class="alert alert-success"><?php echo h($flash['success']); ?></div>
    <?php endif; ?>
    <?php if (!empty($flash['error'])): ?>
        <div class="alert alert-danger"><?php echo h($flash['error']); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="alert alert-warning">
        Use this only for wrong posted lines that should be zero. This action deletes the line's generated stock snapshot and sets delivered/accepted/rejected to 0.
        Auto-correction is blocked when the line is already used in issuance or distribution.
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Delivered</th>
                        <th class="text-end">Accepted</th>
                        <th class="text-end">Rejected</th>
                        <th class="text-end">Line Total</th>
                        <th class="text-end">Stock Rows</th>
                        <th class="text-end">Issued Qty</th>
                        <th class="text-end">Distributions</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items): ?>
                        <?php foreach ($items as $item): ?>
                            <?php $canCorrect = ((int) $item['distribution_count'] === 0) && ((float) $item['issued_qty'] <= 0.0001); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo h($item['item_no'] ?: '-'); ?></div>
                                    <div class="small text-muted"><?php echo h($item['item_description']); ?></div>
                                </td>
                                <td class="text-end"><?php echo h(number_format((float) $item['quantity_delivered'], 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $item['quantity_accepted'], 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $item['quantity_rejected'], 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $item['line_total'], 2)); ?></td>
                                <td class="text-end"><?php echo h((string) ((int) $item['stock_count'])); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $item['issued_qty'], 2)); ?></td>
                                <td class="text-end"><?php echo h((string) ((int) $item['distribution_count'])); ?></td>
                                <td class="text-end">
                                    <?php if ($canCorrect): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Set this line to zero and remove linked stock snapshot?');">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="zero_line">
                                            <input type="hidden" name="receiving_id" value="<?php echo (int) $receivingId; ?>">
                                            <input type="hidden" name="receiving_item_id" value="<?php echo (int) $item['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Set to 0</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Locked</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-3">No receiving lines found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
