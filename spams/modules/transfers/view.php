<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$batchId = (int) ($_GET['id'] ?? 0);
$flash = get_flash();
$errors = [];
$canCancelBatch = current_user_role() === 'Administrator';

if (!$db || $batchId <= 0) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

function transfer_batch_person_name(array $row, string $prefix): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'cancel_batch') {
    if (!$canCancelBatch) {
        $errors[] = 'Only administrators can cancel a transfer document.';
    } elseif (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $itemRows = [];
        $stmt = $db->prepare("
            SELECT
                at.id,
                at.property_number,
                at.source_type,
                at.distribution_item_detail_id,
                at.legacy_asset_id,
                at.from_office_id,
                at.from_employee_id,
                at.from_responsibility_code_id
            FROM asset_transfers at
            WHERE at.batch_id = ?
              AND at.status = 'posted'
            ORDER BY at.id ASC
        ");
        if ($stmt) {
            $stmt->bind_param('i', $batchId);
            $stmt->execute();
            $itemRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        if (!$itemRows) {
            $errors[] = 'No posted transfers were found for this document.';
        } else {
            $blockingAssets = [];
            foreach ($itemRows as $itemRow) {
                $stmt = null;
                if (($itemRow['source_type'] ?? '') === 'system' && (int) ($itemRow['distribution_item_detail_id'] ?? 0) > 0) {
                    $detailId = (int) $itemRow['distribution_item_detail_id'];
                    $transferId = (int) $itemRow['id'];
                    $stmt = $db->prepare("
                        SELECT id
                        FROM asset_transfers
                        WHERE status = 'posted'
                          AND distribution_item_detail_id = ?
                          AND id > ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ii', $detailId, $transferId);
                    }
                } elseif (($itemRow['source_type'] ?? '') === 'legacy' && (int) ($itemRow['legacy_asset_id'] ?? 0) > 0) {
                    $legacyId = (int) $itemRow['legacy_asset_id'];
                    $transferId = (int) $itemRow['id'];
                    $stmt = $db->prepare("
                        SELECT id
                        FROM asset_transfers
                        WHERE status = 'posted'
                          AND legacy_asset_id = ?
                          AND id > ?
                        LIMIT 1
                    ");
                    if ($stmt) {
                        $stmt->bind_param('ii', $legacyId, $transferId);
                    }
                }

                if ($stmt) {
                    $stmt->execute();
                    $blocked = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($blocked) {
                        $blockingAssets[] = (string) ($itemRow['property_number'] ?? ('Transfer #' . $itemRow['id']));
                    }
                }
            }

            if ($blockingAssets) {
                $preview = array_slice($blockingAssets, 0, 5);
                $errors[] = 'This document cannot be cancelled because newer transfers already exist for: ' . implode(', ', $preview) . (count($blockingAssets) > 5 ? ' and more.' : '.');
            } else {
                $db->begin_transaction();
                try {
                    foreach ($itemRows as $itemRow) {
                        if (($itemRow['source_type'] ?? '') === 'system') {
                            $detailId = (int) ($itemRow['distribution_item_detail_id'] ?? 0);
                            $stmt = $db->prepare("
                                UPDATE distribution_item_details
                                SET current_office_id = NULLIF(?,0),
                                    current_employee_id = NULLIF(?,0),
                                    current_responsibility_code_id = NULLIF(?,0)
                                WHERE id = ?
                            ");
                            if (!$stmt) {
                                throw new RuntimeException('Unable to prepare system accountability rollback.');
                            }
                            $fromOfficeId = (int) ($itemRow['from_office_id'] ?? 0);
                            $fromEmployeeId = (int) ($itemRow['from_employee_id'] ?? 0);
                            $fromRcId = (int) ($itemRow['from_responsibility_code_id'] ?? 0);
                            $stmt->bind_param('iiii', $fromOfficeId, $fromEmployeeId, $fromRcId, $detailId);
                        } else {
                            $legacyId = (int) ($itemRow['legacy_asset_id'] ?? 0);
                            $stmt = $db->prepare("
                                UPDATE legacy_assets
                                SET office_id = NULLIF(?,0),
                                    employee_id = NULLIF(?,0),
                                    responsibility_code_id = NULLIF(?,0)
                                WHERE id = ?
                            ");
                            if (!$stmt) {
                                throw new RuntimeException('Unable to prepare legacy accountability rollback.');
                            }
                            $fromOfficeId = (int) ($itemRow['from_office_id'] ?? 0);
                            $fromEmployeeId = (int) ($itemRow['from_employee_id'] ?? 0);
                            $fromRcId = (int) ($itemRow['from_responsibility_code_id'] ?? 0);
                            $stmt->bind_param('iiii', $fromOfficeId, $fromEmployeeId, $fromRcId, $legacyId);
                        }
                        if (!$stmt->execute()) {
                            $err = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException('Unable to rollback asset accountability: ' . $err);
                        }
                        $stmt->close();

                        $stmt = $db->prepare("UPDATE asset_transfers SET status = 'cancelled' WHERE id = ? AND status = 'posted'");
                        if (!$stmt) {
                            throw new RuntimeException('Unable to prepare transfer cancellation.');
                        }
                        $transferId = (int) $itemRow['id'];
                        $stmt->bind_param('i', $transferId);
                        if (!$stmt->execute()) {
                            $err = $stmt->error;
                            $stmt->close();
                            throw new RuntimeException('Unable to cancel transfer row: ' . $err);
                        }
                        $stmt->close();
                    }

                    $stmt = $db->prepare("UPDATE transfer_batches SET status = 'cancelled' WHERE id = ? AND status = 'posted'");
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare transfer document cancellation.');
                    }
                    $stmt->bind_param('i', $batchId);
                    if (!$stmt->execute()) {
                        $err = $stmt->error;
                        $stmt->close();
                        throw new RuntimeException('Unable to cancel transfer document: ' . $err);
                    }
                    $stmt->close();

                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'transfer_batches',
                        'record_id' => $batchId,
                        'module_name' => 'transfers',
                        'record_type' => 'transfer_batch',
                        'action_name' => 'cancel_transfer_batch',
                        'description' => 'Cancelled transfer document and restored previous accountability.',
                        'new_values' => [
                            'status' => 'cancelled',
                            'cancelled_items' => count($itemRows),
                        ],
                    ]);

                    $db->commit();
                    set_flash('success', 'Transfer document cancelled and previous accountability restored.');
                    redirect('modules/transfers/index.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to cancel transfer document: ' . $e->getMessage();
                }
            }
        }
    }
}

$stmt = $db->prepare("
    SELECT
        tb.*,
        from_o.office_name AS from_office_name,
        to_o.office_name AS to_office_name,
        from_e.first_name AS from_first_name,
        from_e.middle_name AS from_middle_name,
        from_e.last_name AS from_last_name,
        from_e.suffix_name AS from_suffix_name,
        from_e.position_title AS from_position_title,
        to_e.first_name AS to_first_name,
        to_e.middle_name AS to_middle_name,
        to_e.last_name AS to_last_name,
        to_e.suffix_name AS to_suffix_name,
        to_e.position_title AS to_position_title,
        COUNT(tbi.id) AS item_count
    FROM transfer_batches tb
    LEFT JOIN offices from_o ON from_o.id = tb.source_office_id
    LEFT JOIN offices to_o ON to_o.id = tb.to_office_id
    LEFT JOIN employees from_e ON from_e.id = tb.source_employee_id
    LEFT JOIN employees to_e ON to_e.id = tb.to_employee_id
    LEFT JOIN transfer_batch_items tbi ON tbi.batch_id = tb.id
    WHERE tb.id = ?
      AND tb.status = 'posted'
    GROUP BY tb.id
    LIMIT 1
");
$batch = null;
if ($stmt) {
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}

if (!$batch) {
    http_response_code(404);
    echo 'Record not found.';
    exit;
}

$stmt = $db->prepare("
    SELECT
        at.id AS asset_transfer_id,
        at.system_reference,
        at.property_number,
        at.source_type,
        CASE WHEN at.source_type = 'system' THEN poi.item_description ELSE la.item_description END AS item_description,
        CASE WHEN at.source_type = 'system' THEN poi.item_type ELSE la.item_type END AS item_type,
        CASE WHEN at.source_type = 'system' THEN did.brand ELSE la.brand END AS brand,
        CASE WHEN at.source_type = 'system' THEN did.model ELSE la.model END AS model,
        CASE WHEN at.source_type = 'system' THEN did.serial_no ELSE la.serial_no END AS serial_no,
        CASE WHEN at.source_type = 'system' THEN ri.unit_cost ELSE la.unit_cost END AS amount,
        CASE WHEN at.source_type = 'system' THEN c.classification_name ELSE lc.classification_name END AS classification_name,
        CASE WHEN at.source_type = 'system' THEN c.classification_family ELSE lc.classification_family END AS classification_family,
        from_o.office_name AS from_office_name,
        to_o.office_name AS to_office_name,
        from_e.first_name AS from_first_name,
        from_e.middle_name AS from_middle_name,
        from_e.last_name AS from_last_name,
        from_e.suffix_name AS from_suffix_name,
        to_e.first_name AS to_first_name,
        to_e.middle_name AS to_middle_name,
        to_e.last_name AS to_last_name,
        to_e.suffix_name AS to_suffix_name
    FROM transfer_batch_items tbi
    INNER JOIN asset_transfers at ON at.id = tbi.asset_transfer_id
    LEFT JOIN offices from_o ON from_o.id = at.from_office_id
    LEFT JOIN offices to_o ON to_o.id = at.to_office_id
    LEFT JOIN employees from_e ON from_e.id = at.from_employee_id
    LEFT JOIN employees to_e ON to_e.id = at.to_employee_id
    LEFT JOIN distribution_item_details did ON did.id = at.distribution_item_detail_id
    LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
    LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
    LEFT JOIN classifications c ON c.id = poi.classification_id
    LEFT JOIN legacy_assets la ON la.id = at.legacy_asset_id
    LEFT JOIN classifications lc ON lc.id = la.classification_id
    WHERE tbi.batch_id = ?
    ORDER BY at.property_number ASC, at.id ASC
");
$items = [];
if ($stmt) {
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$page_title = strtoupper((string) $batch['document_type']) . ' Document ' . $batch['system_reference'];
$fromOfficer = trim(transfer_batch_person_name($batch, 'from_') . (!empty($batch['from_office_name']) ? ' / ' . $batch['from_office_name'] : ''));
$toOfficer = trim(transfer_batch_person_name($batch, 'to_') . (!empty($batch['to_office_name']) ? ' / ' . $batch['to_office_name'] : ''));
$totalAmount = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $items));

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                            <h4 class="mb-0"><?php echo h($batch['system_reference']); ?></h4>
                            <span class="badge text-bg-primary"><?php echo h(strtoupper((string) $batch['document_type'])); ?></span>
                            <span class="badge text-bg-light"><?php echo h(number_format((int) ($batch['item_count'] ?? 0))); ?> item(s)</span>
                        </div>
                        <div class="text-muted small">Transfer document preview for bulk accountability changes.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?php echo base_url('modules/transfers/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to Transfers</a>
                        <?php if ($canCancelBatch): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this transfer document and restore the previous accountability of all included assets?');">
                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                <input type="hidden" name="action" value="cancel_batch">
                                <button type="submit" class="btn btn-outline-danger btn-sm">Cancel Document</button>
                            </form>
                        <?php endif; ?>
                        <?php if (($batch['document_type'] ?? '') === 'itr'): ?>
                            <a href="<?php echo base_url('modules/transfers/itr.php?batch_id=' . (int) $batch['id']); ?>" class="btn btn-primary btn-sm" target="_blank">Print ITR</a>
                        <?php else: ?>
                            <a href="<?php echo base_url('modules/transfers/ptr.php?batch_id=' . (int) $batch['id']); ?>" class="btn btn-primary btn-sm" target="_blank">Print PTR</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($flash): ?><div class="alert alert-success"><?php echo h($flash['message']); ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="border rounded-4 p-3 h-100">
                            <div class="small text-muted mb-1">Transfer Date</div>
                            <div class="fw-semibold"><?php echo h(!empty($batch['transfer_date']) ? date('M d, Y', strtotime((string) $batch['transfer_date'])) : ''); ?></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="border rounded-4 p-3 h-100">
                            <div class="small text-muted mb-1">From</div>
                            <div class="fw-semibold"><?php echo h($fromOfficer !== '' ? $fromOfficer : 'Various'); ?></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="border rounded-4 p-3 h-100">
                            <div class="small text-muted mb-1">To</div>
                            <div class="fw-semibold"><?php echo h($toOfficer !== '' ? $toOfficer : 'Various'); ?></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="border rounded-4 p-3 h-100">
                            <div class="small text-muted mb-1">Total Amount</div>
                            <div class="fw-semibold"><?php echo h(number_format($totalAmount, 2)); ?></div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <div class="small text-muted mb-1">Reason</div>
                    <div class="fw-semibold"><?php echo h($batch['reason'] ?? ''); ?></div>
                    <?php if (!empty($batch['remarks'])): ?>
                        <div class="text-muted mt-1"><?php echo nl2br(h((string) $batch['remarks'])); ?></div>
                    <?php endif; ?>
                </div>

                <div class="table-responsive mobile-table-frame">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Property No.</th>
                                <th>Asset</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Source</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($items): foreach ($items as $item): ?>
                                <?php
                                $itemMeta = trim(implode(' | ', array_filter([
                                    trim((string) ($item['classification_name'] ?? '') ?: (string) ($item['classification_family'] ?? '')),
                                    trim(trim((string) ($item['brand'] ?? '')) . ' ' . trim((string) ($item['model'] ?? ''))),
                                    !empty($item['serial_no']) ? 'SN ' . $item['serial_no'] : null,
                                ])));
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h($item['property_number'] ?? ''); ?></td>
                                    <td>
                                        <div><?php echo h($item['item_description'] ?? ''); ?></div>
                                        <?php if ($itemMeta !== ''): ?><div class="small text-muted"><?php echo h($itemMeta); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo h($item['from_office_name'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo h(transfer_batch_person_name($item, 'from_')); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo h($item['to_office_name'] ?? ''); ?></div>
                                        <div class="small text-muted"><?php echo h(transfer_batch_person_name($item, 'to_')); ?></div>
                                    </td>
                                    <td><?php echo h(($item['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($item['amount'] ?? 0), 2)); ?></td>
                                    <td class="text-end">
                                        <?php if (($item['item_type'] ?? '') === 'semi_expendable'): ?>
                                            <a href="<?php echo base_url('modules/transfers/itr.php?id=' . (int) ($item['asset_transfer_id'] ?? 0)); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Single ITR</a>
                                        <?php else: ?>
                                            <a href="<?php echo base_url('modules/transfers/ptr.php?id=' . (int) ($item['asset_transfer_id'] ?? 0)); ?>" class="btn btn-sm btn-outline-secondary" target="_blank">Single PTR</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No batch items found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
