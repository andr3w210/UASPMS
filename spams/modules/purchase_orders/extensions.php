<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$page_title = 'Delivery Extensions';
$db = db();
$flash = get_flash();
$errors = [];
$extensions = [];
$eligiblePurchaseOrders = [];

$form = [
    'system_reference' => '',
    'purchase_order_id' => '',
    'old_expected_delivery_date' => '',
    'new_expected_delivery_date' => '',
    'requested_extension_days' => '',
    'reason' => '',
    'remarks' => '',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $form['system_reference'] = preview_module_code($db, 'po_delivery_extensions');

    $eligibleSql = "
        SELECT po.id, po.system_reference, po.po_number, po.po_date, po.expected_delivery_date,
               po.status, s.supplier_name
        FROM purchase_orders po
        INNER JOIN suppliers s ON s.id = po.supplier_id
        WHERE po.status NOT IN ('completed', 'cancelled')
          AND po.expected_delivery_date IS NOT NULL
        ORDER BY po.expected_delivery_date ASC, po.po_date DESC, po.id DESC
    ";
    $eligibleResult = $db->query($eligibleSql);
    if ($eligibleResult) {
        $eligiblePurchaseOrders = $eligibleResult->fetch_all(MYSQLI_ASSOC);
    }

    $requestedPoId = (int) ($_GET['po_id'] ?? 0);
    if ($requestedPoId > 0 && $form['purchase_order_id'] === '') {
        foreach ($eligiblePurchaseOrders as $po) {
            if ((int) $po['id'] === $requestedPoId) {
                $form['purchase_order_id'] = (string) $requestedPoId;
                $form['old_expected_delivery_date'] = (string) ($po['expected_delivery_date'] ?? '');
                $form['requested_extension_days'] = '';
                break;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $form['purchase_order_id'] = old($_POST, 'purchase_order_id');
            $form['old_expected_delivery_date'] = old($_POST, 'old_expected_delivery_date');
            $form['new_expected_delivery_date'] = old($_POST, 'new_expected_delivery_date');
            $form['requested_extension_days'] = old($_POST, 'requested_extension_days');
            $form['reason'] = old($_POST, 'reason');
            $form['remarks'] = old($_POST, 'remarks');

            $purchaseOrderId = (int) $form['purchase_order_id'];
            if ($purchaseOrderId <= 0) {
                $errors[] = 'Select a purchase order to extend.';
            }
            if ($form['old_expected_delivery_date'] === '' || $form['new_expected_delivery_date'] === '') {
                $errors[] = 'Both current and new delivery dates are required.';
            }
            if ($form['reason'] === '') {
                $errors[] = 'Reason for extension is required.';
            }

            $selectedPurchaseOrder = null;
            foreach ($eligiblePurchaseOrders as $po) {
                if ((int) $po['id'] === $purchaseOrderId) {
                    $selectedPurchaseOrder = $po;
                    break;
                }
            }

            if (!$selectedPurchaseOrder) {
                $errors[] = 'Selected purchase order is not eligible for extension.';
            } else {
                $form['old_expected_delivery_date'] = (string) ($selectedPurchaseOrder['expected_delivery_date'] ?? '');
            }

            if (!$errors) {
                try {
                    $oldDate = new DateTimeImmutable($form['old_expected_delivery_date']);
                    $newDate = new DateTimeImmutable($form['new_expected_delivery_date']);
                    $poDate = new DateTimeImmutable((string) ($selectedPurchaseOrder['po_date'] ?? $form['old_expected_delivery_date']));
                    if ($newDate <= $oldDate) {
                        $errors[] = 'New expected delivery date must be later than the current end date.';
                    }
                    if ($newDate < $poDate) {
                        $errors[] = 'New expected delivery date cannot be earlier than the PO date.';
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Invalid delivery date.';
                }
            }

            if (!$errors) {
                $systemReference = next_module_code($db, 'po_delivery_extensions');
                $userId = current_user_id();
                $deliveryTermDays = (int) $poDate->diff($newDate)->format('%a');
                $requestedExtensionDays = (int) $oldDate->diff($newDate)->format('%a');
                $form['requested_extension_days'] = (string) $requestedExtensionDays;

                $db->begin_transaction();
                try {
                    $insertStmt = $db->prepare(
                        "INSERT INTO purchase_order_delivery_extensions
                         (system_reference, purchase_order_id, old_expected_delivery_date, new_expected_delivery_date, requested_extension_days, reason, remarks, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $updateStmt = $db->prepare(
                        "UPDATE purchase_orders
                         SET expected_delivery_date = ?, delivery_term_days = ?, updated_by = ?, updated_at = NOW()
                         WHERE id = ?"
                    );

                    if (!$insertStmt || !$updateStmt) {
                        throw new RuntimeException('Unable to prepare delivery extension statements.');
                    }

                    $insertStmt->bind_param(
                        'siiisssi',
                        $systemReference,
                        $purchaseOrderId,
                        $form['old_expected_delivery_date'],
                        $form['new_expected_delivery_date'],
                        $requestedExtensionDays,
                        $form['reason'],
                        $form['remarks'],
                        $userId
                    );
                    $insertStmt->execute();
                    $insertStmt->close();

                    $updateStmt->bind_param(
                        'siii',
                        $form['new_expected_delivery_date'],
                        $deliveryTermDays,
                        $userId,
                        $purchaseOrderId
                    );
                    $updateStmt->execute();
                    $updateStmt->close();

                    $db->commit();
                    set_flash('success', 'Delivery end date extended successfully.');
                    redirect('modules/purchase_orders/extensions.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to save the delivery extension.';
                }
            }
        }
    }

    $historyResult = $db->query(
        "SELECT ext.system_reference, ext.old_expected_delivery_date, ext.new_expected_delivery_date,
                ext.requested_extension_days, ext.reason, ext.remarks, ext.created_at,
                po.po_number, s.supplier_name
                ,(SELECT COUNT(*)
                  FROM purchase_order_delivery_extensions ext2
                  WHERE ext2.purchase_order_id = ext.purchase_order_id
                    AND ext2.status = 'posted') AS extension_count
         FROM purchase_order_delivery_extensions ext
         INNER JOIN purchase_orders po ON po.id = ext.purchase_order_id
         INNER JOIN suppliers s ON s.id = po.supplier_id
         WHERE ext.status = 'posted'
         ORDER BY ext.created_at DESC, ext.id DESC"
    );
    if ($historyResult) {
        $extensions = $historyResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Extend Delivery End Date</h5>
                        <div class="text-muted small">Log approved delivery extensions and update the active PO end date with an audit trail.</div>
                    </div>
                    <span class="badge text-bg-light"><?php echo count($eligiblePurchaseOrders); ?> eligible PO(s)</span>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo h($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="row g-3">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <div class="col-md-6">
                        <label class="form-label">Purchase Order *</label>
                        <select class="form-select" id="purchase_order_id" name="purchase_order_id" data-placeholder="Select purchase order">
                            <option value="">Select purchase order</option>
                            <?php foreach ($eligiblePurchaseOrders as $po): ?>
                                <option value="<?php echo (int) $po['id']; ?>"
                                        data-current-end="<?php echo h((string) ($po['expected_delivery_date'] ?? '')); ?>"
                                        <?php echo $form['purchase_order_id'] === (string) ($po['id'] ?? '') ? 'selected' : ''; ?>>
                                    <?php echo h(($po['po_number'] ?? '') . ' - ' . ($po['supplier_name'] ?? '') . ' [' . ($po['status'] ?? '') . ']'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">System Reference</label>
                        <input type="text" class="form-control" value="<?php echo h($form['system_reference']); ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Current End Date</label>
                        <input type="date" class="form-control" id="old_expected_delivery_date" name="old_expected_delivery_date" value="<?php echo h($form['old_expected_delivery_date']); ?>" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New End Date *</label>
                        <input type="date" class="form-control" name="new_expected_delivery_date" value="<?php echo h($form['new_expected_delivery_date']); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Requested Days</label>
                        <input type="number" class="form-control" id="requested_extension_days" name="requested_extension_days" value="<?php echo h($form['requested_extension_days']); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Reason for Extension *</label>
                        <input type="text" class="form-control" name="reason" value="<?php echo h($form['reason']); ?>" required placeholder="Supplier request, delayed shipment, procurement issue, etc.">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2"><?php echo h($form['remarks']); ?></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">Save Extension</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Extension History</h5>
                    <span class="badge text-bg-light"><?php echo count($extensions); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Total Extensions</th>
                                <th>Old End Date</th>
                                <th>New End Date</th>
                                <th>Requested Days</th>
                                <th>Reason</th>
                                <th>Remarks</th>
                                <th>Applied On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($extensions): ?>
                                <?php foreach ($extensions as $extension): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($extension['system_reference']); ?></td>
                                        <td><?php echo h($extension['po_number']); ?></td>
                                        <td><?php echo h($extension['supplier_name']); ?></td>
                                        <td><span class="badge text-bg-light"><?php echo h((string) ($extension['extension_count'] ?? 0)); ?></span></td>
                                        <td><?php echo h(date('M d, Y', strtotime($extension['old_expected_delivery_date']))); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($extension['new_expected_delivery_date']))); ?></td>
                                        <td><?php echo h((string) ($extension['requested_extension_days'] ?? 0)); ?> day(s)</td>
                                        <td style="min-width: 220px;"><?php echo h($extension['reason']); ?></td>
                                        <td style="min-width: 180px;"><?php echo h($extension['remarks'] ?? ''); ?></td>
                                        <td><?php echo h(date('M d, Y g:i A', strtotime($extension['created_at']))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No delivery extensions recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var purchaseOrderSelect = document.getElementById('purchase_order_id');
    var currentEndInput = document.getElementById('old_expected_delivery_date');
    var newEndInput = document.querySelector('input[name="new_expected_delivery_date"]');
    var requestedDaysInput = document.getElementById('requested_extension_days');

    function syncCurrentEndDate() {
        if (!purchaseOrderSelect || !currentEndInput) {
            return;
        }
        var option = purchaseOrderSelect.options[purchaseOrderSelect.selectedIndex];
        currentEndInput.value = option ? (option.getAttribute('data-current-end') || '') : '';
        syncRequestedDays();
    }

    function syncRequestedDays() {
        if (!currentEndInput || !newEndInput || !requestedDaysInput || !currentEndInput.value || !newEndInput.value) {
            if (requestedDaysInput) {
                requestedDaysInput.value = '';
            }
            return;
        }

        var oldDate = new Date(currentEndInput.value + 'T00:00:00');
        var newDate = new Date(newEndInput.value + 'T00:00:00');
        var diffMs = newDate.getTime() - oldDate.getTime();
        var diffDays = Math.round(diffMs / 86400000);
        requestedDaysInput.value = diffDays > 0 ? String(diffDays) : '';
    }

    if (purchaseOrderSelect) {
        purchaseOrderSelect.addEventListener('change', syncCurrentEndDate);
        if (window.jQuery) {
            window.jQuery(purchaseOrderSelect).on('select2:select select2:clear', syncCurrentEndDate);
        }
    }
    if (newEndInput) {
        newEndInput.addEventListener('change', syncRequestedDays);
        newEndInput.addEventListener('input', syncRequestedDays);
    }

    syncCurrentEndDate();
    syncRequestedDays();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
