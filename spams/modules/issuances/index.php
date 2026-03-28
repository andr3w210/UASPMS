<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$db = db_connect();
$page_title = 'Issuances';
$flash = get_flash();
$errors = [];
$issuances = [];
$stockItems = [];
$offices = [];
$employees = [];
$purchaseOrders = [];
$selectedPoId = 0;
$stockLotCount = 0;
$stockOnHandTotal = 0.00;
$stockValueTotal = 0.00;
$groupedStockItems = [];
$form = [
    'system_reference' => '',
    'issuance_date' => date('Y-m-d'),
    'office_id' => '',
    'employee_id' => '',
    'purpose' => '',
    'remarks' => '',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $selectedPoId = (int) ($_POST['po_id'] ?? ($_GET['po_id'] ?? 0));
    $form['system_reference'] = preview_module_code($db, 'issuances');

    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    $employeeResult = $db->query("SELECT id, office_id, employee_no, first_name, middle_name, last_name, suffix_name, position_title, is_unit_head FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    $poResult = $db->query("
        SELECT DISTINCT po.id, po.po_number, po.po_date
        FROM stock_items si
        INNER JOIN purchase_order_items poi ON poi.id = si.purchase_order_item_id
        INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
        WHERE si.item_type = 'supply'
          AND si.quantity_on_hand > 0
        ORDER BY po.po_date DESC, po.id DESC
    ");
    if ($poResult) {
        $purchaseOrders = $poResult->fetch_all(MYSQLI_ASSOC);
    }

    $stockSql = "
     SELECT si.id, si.system_reference, si.item_type, si.item_description, si.unit_cost, si.quantity_received, si.quantity_issued, si.quantity_on_hand,
         si.account_code_id, si.classification_id, si.unit_of_measure_id, ac.account_code, ac.account_name, c.classification_name,
         u.uom_name, u.abbreviation, r.system_reference AS receiving_reference, po.po_number, po.id AS purchase_order_id, po.po_date
        FROM stock_items si
        LEFT JOIN account_codes ac ON ac.id = si.account_code_id
        LEFT JOIN classifications c ON c.id = si.classification_id
        LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id
        LEFT JOIN receivings r ON r.id = si.receiving_id
        LEFT JOIN purchase_order_items poi ON poi.id = si.purchase_order_item_id
        LEFT JOIN purchase_orders po ON po.id = poi.purchase_order_id
        WHERE si.item_type = 'supply' AND si.quantity_on_hand > 0
    ";
    $stockTypes = '';
    $stockParams = [];
    if ($selectedPoId > 0) {
        $stockSql .= " AND po.id = ?";
        $stockTypes .= 'i';
        $stockParams[] = $selectedPoId;
    }
    $stockSql .= " ORDER BY po.po_date DESC, po.po_number DESC, si.created_at DESC, si.id DESC";
    $stockStmt = $db->prepare($stockSql);
    if ($stockStmt) {
        if ($stockTypes !== '') {
            $stockStmt->bind_param($stockTypes, ...$stockParams);
        }
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        if ($stockResult) {
            $stockItems = $stockResult->fetch_all(MYSQLI_ASSOC);
        }
        $stockStmt->close();
    }

    foreach ($stockItems as $stockItem) {
        $stockLotCount++;
        $stockOnHandTotal += (float) ($stockItem['quantity_on_hand'] ?? 0);
        $stockValueTotal += ((float) ($stockItem['quantity_on_hand'] ?? 0) * (float) ($stockItem['unit_cost'] ?? 0));

        $groupKey = strtolower(trim((string) ($stockItem['classification_name'] ?? ''))) . '|' . strtolower(trim((string) ($stockItem['item_description'] ?? '')));
        if (!isset($groupedStockItems[$groupKey])) {
            $groupedStockItems[$groupKey] = [
                'classification_name' => $stockItem['classification_name'] ?? '',
                'item_description' => $stockItem['item_description'] ?? '',
                'lots' => [],
                'lot_count' => 0,
                'total_on_hand' => 0.00,
            ];
        }
        $groupedStockItems[$groupKey]['lots'][] = $stockItem;
        $groupedStockItems[$groupKey]['lot_count']++;
        $groupedStockItems[$groupKey]['total_on_hand'] += (float) ($stockItem['quantity_on_hand'] ?? 0);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['system_reference'] = preview_module_code($db, 'issuances');
        $form['issuance_date'] = old($_POST, 'issuance_date', date('Y-m-d'));
        $form['office_id'] = old($_POST, 'office_id');
        $form['employee_id'] = old($_POST, 'employee_id');
        $form['purpose'] = old($_POST, 'purpose');
        $form['remarks'] = old($_POST, 'remarks');

        if ($form['issuance_date'] === '') {
            $errors[] = 'Issuance date is required.';
        }
        if ($form['office_id'] === '') {
            $errors[] = 'Office is required.';
        }

        $officeId = (int) ($form['office_id'] !== '' ? $form['office_id'] : 0);
        $employeeId = (int) ($form['employee_id'] !== '' ? $form['employee_id'] : 0);

        if ($employeeId > 0) {
            $employeeValid = false;
            foreach ($employees as $employee) {
                if ((int) $employee['id'] === $employeeId) {
                    $employeeValid = (int) ($employee['office_id'] ?? 0) === $officeId;
                    break;
                }
            }
            if (!$employeeValid) {
                $errors[] = 'Selected employee does not belong to the chosen office.';
            }
        }

        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];
        $totalAmount = 0.00;

        foreach ($stockItems as $stockItem) {
            $stockId = (int) $stockItem['id'];
            $posted = isset($postedItems[$stockId]) && is_array($postedItems[$stockId]) ? $postedItems[$stockId] : [];
            $issueQty = isset($posted['issue_quantity']) ? (float) $posted['issue_quantity'] : 0;
            $lineRemarks = trim((string) ($posted['remarks'] ?? ''));

            if ($issueQty <= 0) {
                continue;
            }

            $onHand = (float) $stockItem['quantity_on_hand'];
            if ($issueQty > $onHand) {
                $errors[] = 'Issued quantity cannot exceed stock on hand for stock reference ' . $stockItem['system_reference'] . '.';
                continue;
            }

            $unitCost = (float) $stockItem['unit_cost'];
            $lineTotal = round($issueQty * $unitCost, 2);
            $totalAmount += $lineTotal;

            $validatedItems[] = [
                'stock_item_id' => $stockId,
                'issue_quantity' => $issueQty,
                'unit_cost' => $unitCost,
                'line_total' => $lineTotal,
                'remarks' => $lineRemarks,
                'balance_after' => round($onHand - $issueQty, 2),
            ];
        }

        if (!$validatedItems) {
            $errors[] = 'Enter at least one quantity to issue.';
        }

        if (!$errors) {
            $db->begin_transaction();
            try {
                $systemReference = next_module_code($db, 'issuances');
                $userId = current_user_id();
                $headerStmt = $db->prepare("INSERT INTO issuances (system_reference, issuance_date, office_id, employee_id, purpose, remarks, status, total_amount, created_by) VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, 'posted', ?, ?)");
                $itemStmt = $db->prepare("INSERT INTO issuance_items (issuance_id, stock_item_id, quantity_issued, unit_cost, line_total, remarks) VALUES (?, ?, ?, ?, ?, ?)");
                $stockUpdateStmt = $db->prepare("UPDATE stock_items SET quantity_issued = quantity_issued + ?, quantity_on_hand = quantity_on_hand - ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                $movementStmt = $db->prepare("INSERT INTO stock_movements (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by) VALUES (?, 'issue', ?, 'issuance', ?, 0.00, ?, ?, ?, ?)");
                if (!$headerStmt || !$itemStmt || !$stockUpdateStmt || !$movementStmt) {
                    throw new RuntimeException('Unable to prepare issuance statements.');
                }

                $headerStmt->bind_param('ssiissdi', $systemReference, $form['issuance_date'], $officeId, $employeeId, $form['purpose'], $form['remarks'], $totalAmount, $userId);
                $headerStmt->execute();
                $issuanceId = (int) $headerStmt->insert_id;
                $headerStmt->close();

                foreach ($validatedItems as $item) {
                    $itemStmt->bind_param('iiddds', $issuanceId, $item['stock_item_id'], $item['issue_quantity'], $item['unit_cost'], $item['line_total'], $item['remarks']);
                    $itemStmt->execute();

                    $stockUpdateStmt->bind_param('ddii', $item['issue_quantity'], $item['issue_quantity'], $userId, $item['stock_item_id']);
                    $stockUpdateStmt->execute();

                    $movementRemarks = $item['remarks'] !== '' ? $item['remarks'] : ('Issued through ' . $systemReference);
                    $movementStmt->bind_param('isiddsi', $item['stock_item_id'], $form['issuance_date'], $issuanceId, $item['issue_quantity'], $item['balance_after'], $movementRemarks, $userId);
                    $movementStmt->execute();
                }

                $movementStmt->close();
                $stockUpdateStmt->close();
                $itemStmt->close();
                $db->commit();
                set_flash('success', 'Issuance posted successfully.');
                redirect('modules/issuances/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to save the issuance.';
            }
        }
    }

    $issuanceResult = $db->query("
        SELECT i.id, i.system_reference, i.issuance_date, i.total_amount, i.status,
               o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM issuances i
        INNER JOIN offices o ON o.id = i.office_id
        LEFT JOIN employees e ON e.id = i.employee_id
        ORDER BY i.issuance_date DESC, i.id DESC
    ");
    if ($issuanceResult) {
        $issuances = $issuanceResult->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3">Encode Issuance</h5>
                <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                <form method="post" id="issuanceForm">
                    <input type="hidden" name="po_id" value="<?php echo (int) $selectedPoId; ?>">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label">System Reference</label>
                            <input type="text" class="form-control" value="<?php echo h($form['system_reference']); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label for="issuance_date" class="form-label">Issuance Date</label>
                            <input type="date" class="form-control" id="issuance_date" name="issuance_date" value="<?php echo h($form['issuance_date']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="office_id" class="form-label">Office</label>
                            <select class="form-select" id="office_id" name="office_id" required data-placeholder="Select office">
                                <option value="">Select office</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($office['office_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="employee_id" class="form-label">Employee / End User</label>
                            <select class="form-select" id="employee_id" name="employee_id" data-placeholder="Select employee">
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>"
                                            data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>"
                                            data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>"
                                            <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no'] . (!empty($employee['position_title']) ? ' (' . $employee['position_title'] . ')' : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="purpose" class="form-label">Purpose</label>
                            <textarea class="form-control" id="purpose" name="purpose" rows="2"><?php echo h($form['purpose']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="remarks" class="form-label">Remarks</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="2"><?php echo h($form['remarks']); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h6 class="mb-0">Available Supply Stock</h6>
                            <div class="small text-muted">Issue directly from supply lots on hand, optionally narrowed to one PO.</div>
                        </div>
                        <span class="small text-muted"><?php echo count($stockItems); ?> stock lot(s) loaded</span>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                <div class="text-muted small">Loaded Stock Lots</div>
                                <div class="fs-5 fw-semibold"><?php echo h(number_format($stockLotCount)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                <div class="text-muted small">Total On Hand</div>
                                <div class="fs-5 fw-semibold"><?php echo h(number_format($stockOnHandTotal, 2)); ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="border rounded-3 p-3 bg-light-subtle h-100">
                                <div class="text-muted small">Estimated Stock Value</div>
                                <div class="fs-5 fw-semibold"><?php echo h(number_format($stockValueTotal, 2)); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label for="poFilter" class="form-label mb-0">Purchase Order</label>
                            <select id="poFilter" class="form-select form-select-sm">
                                <option value="">All POs</option>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <option value="<?php echo (int) $po['id']; ?>" <?php echo $selectedPoId === (int) $po['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(($po['po_number'] ?: ('PO #' . (int) $po['id'])) . (!empty($po['po_date']) ? ' - ' . date('M d, Y', strtotime($po['po_date'])) : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="stockSearch" class="form-label mb-0">Search Supply Stock</label>
                            <input type="search" id="stockSearch" class="form-control form-control-sm" placeholder="Search stock ref, description, class, account, PO...">
                        </div>
                        <div class="col-md-2">
                            <label for="stockPerPage" class="form-label mb-0">Rows</label>
                            <select id="stockPerPage" class="form-select form-select-sm">
                                <option value="15">15</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <div class="col-md-3 text-md-end ms-auto">
                            <div id="stockPageInfo" class="small text-muted mb-1"></div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" id="stockPrevPage" class="btn btn-outline-secondary">Prev</button>
                                <button type="button" id="stockNextPage" class="btn btn-outline-secondary">Next</button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-sm align-middle" data-no-table-search id="stockTable">
                            <thead>
                                <tr>
                                    <th>Stock Ref</th>
                                    <th>Type</th>
                                    <th>Account / Class / Description</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Issued</th>
                                    <th class="text-end">On Hand</th>
                                    <th style="width: 130px;">Issue Qty</th>
                                    <th style="width: 220px;">Line Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($stockItems): ?>
                                    <?php foreach ($groupedStockItems as $group): ?>
                                        <tr class="table-light" data-group-header="1">
                                            <td colspan="8">
                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                    <div>
                                                        <span class="fw-semibold"><?php echo h($group['classification_name'] ?: 'No inventory class'); ?></span>
                                                        <span class="text-muted"> - <?php echo h($group['item_description']); ?></span>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <?php echo h(number_format((float) $group['total_on_hand'], 2)); ?> on hand across <?php echo h((string) $group['lot_count']); ?> lot(s)
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php foreach ($group['lots'] as $stockItem): ?>
                                        <?php
                                        $uomLabel = trim((string) ($stockItem['uom_name'] ?? ''));
                                        if ($uomLabel === '' && !empty($stockItem['abbreviation'])) {
                                            $uomLabel = $stockItem['abbreviation'];
                                        } elseif (!empty($stockItem['abbreviation'])) {
                                            $uomLabel .= ' (' . $stockItem['abbreviation'] . ')';
                                        }
                                        ?>
                                        <tr data-po-id="<?php echo (int) ($stockItem['purchase_order_id'] ?? 0); ?>">
                                            <td>
                                                <div class="fw-semibold"><?php echo h($stockItem['system_reference']); ?></div>
                                                <div class="small text-muted"><?php echo h($stockItem['receiving_reference'] ?? ''); ?><?php echo !empty($stockItem['po_number']) ? ' | PO ' . h($stockItem['po_number']) : ''; ?></div>
                                            </td>
                                            <td><span class="badge text-bg-success-subtle">Supplies</span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($stockItem['classification_name'] ?: 'No inventory class'); ?></div>
                                                <div><?php echo h($stockItem['item_description']); ?></div>
                                                <div class="small text-muted">
                                                    <?php echo h($stockItem['account_code'] ?: ''); ?><?php echo $stockItem['account_name'] ? ' - ' . h($stockItem['account_name']) : ''; ?>
                                                    <?php echo $uomLabel ? ' | ' . h($uomLabel) : ''; ?>
                                                    <?php echo $stockItem['po_number'] ? ' | PO ' . h($stockItem['po_number']) : ''; ?>
                                                </div>
                                            </td>
                                            <td class="text-end"><?php echo h(number_format((float) $stockItem['quantity_received'], 2)); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float) $stockItem['quantity_issued'], 2)); ?></td>
                                            <td class="text-end fw-semibold"><?php echo h(number_format((float) $stockItem['quantity_on_hand'], 2)); ?></td>
                                            <td>
                                                <input type="number" class="form-control form-control-sm" step="0.01" min="0" max="<?php echo h((string) $stockItem['quantity_on_hand']); ?>" name="items[<?php echo (int) $stockItem['id']; ?>][issue_quantity]" value="0.00">
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="items[<?php echo (int) $stockItem['id']; ?>][remarks]" value="">
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">No supply stock is available yet. Receive accepted supply items first.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary" <?php echo $stockItems ? '' : 'disabled'; ?>>Post Issuance</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Issued Records</h5>
                    <span class="badge text-bg-light"><?php echo count($issuances); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Office</th>
                                <th>Employee</th>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($issuances): ?>
                                <?php foreach ($issuances as $issuance): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($issuance['system_reference']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($issuance['issuance_date']))); ?></td>
                                        <td><?php echo h($issuance['office_name']); ?></td>
                                        <td><?php echo $issuance['employee_no'] ? h(employee_display_name($issuance)) . ' - ' . h($issuance['employee_no']) : '<span class="text-muted">Not specified</span>'; ?></td>
                                        <td><span class="badge text-bg-light text-uppercase"><?php echo h($issuance['status']); ?></span></td>
                                        <td class="text-end"><?php echo h(number_format((float) $issuance['total_amount'], 2)); ?></td>
                                        <td>
                                            <a href="<?php echo base_url('modules/issuances/ris.php?id=' . (int)$issuance['id']); ?>" class="btn btn-sm btn-outline-primary" target="_blank">Print RIS</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No issuance records yet.</td></tr>
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
    var officeSelect = document.getElementById('office_id');
    var employeeSelect = document.getElementById('employee_id');
    var stockSearch = document.getElementById('stockSearch');
    var stockPerPage = document.getElementById('stockPerPage');
    var stockPrevPage = document.getElementById('stockPrevPage');
    var stockNextPage = document.getElementById('stockNextPage');
    var stockPageInfo = document.getElementById('stockPageInfo');
    var poFilter = document.getElementById('poFilter');
    var stockCurrentPage = 1;
    var stockPageSize = parseInt(stockPerPage?.value || '25', 10);

    function findUnitHeadOption(officeId) {
        if (!officeId || !employeeSelect) return null;
        return Array.prototype.find.call(employeeSelect.options, function (option) {
            return option.value &&
                option.getAttribute('data-office-id') === officeId &&
                option.getAttribute('data-is-unit-head') === '1';
        }) || null;
    }

    function filterEmployees(autoSelectHead) {
        if (!officeSelect || !employeeSelect) return;
        var selectedOffice = officeSelect.value;
        var currentEmployeeStillValid = false;

        Array.prototype.forEach.call(employeeSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var matches = !selectedOffice || option.getAttribute('data-office-id') === selectedOffice;
            option.hidden = !matches;
            if (matches && option.value === employeeSelect.value) {
                currentEmployeeStillValid = true;
            }
        });

        if (!currentEmployeeStillValid && employeeSelect.value) {
            employeeSelect.value = '';
        }

        if (autoSelectHead && selectedOffice) {
            var unitHead = findUnitHeadOption(selectedOffice);
            if (unitHead) {
                employeeSelect.value = unitHead.value;
            }
        }

        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(employeeSelect);
        }
    }

    function getStockRows() {
        return Array.prototype.slice.call(document.querySelectorAll('#stockTable tbody tr')).filter(function (row) {
            return row.cells.length > 1 && row.dataset.groupHeader !== '1';
        });
    }

    function renderStockTable() {
        var allRows = getStockRows();
        var visibleRows = allRows.filter(function (row) { return row.dataset.visible !== '0'; });
        var total = visibleRows.length;
        var pages = Math.max(1, Math.ceil(total / stockPageSize));
        stockCurrentPage = Math.min(stockCurrentPage, pages);

        allRows.forEach(function (row) { row.style.display = 'none'; });
        var start = (stockCurrentPage - 1) * stockPageSize;
        visibleRows.slice(start, start + stockPageSize).forEach(function (row) { row.style.display = ''; });

        if (stockPageInfo) {
            stockPageInfo.textContent = 'Page ' + stockCurrentPage + ' of ' + pages + ' (' + total + ' lots)';
        }
        if (stockPrevPage) stockPrevPage.disabled = stockCurrentPage <= 1;
        if (stockNextPage) stockNextPage.disabled = stockCurrentPage >= pages;
    }

    function applyStockFilter() {
        var term = (stockSearch?.value || '').toLowerCase();
        getStockRows().forEach(function (row) {
            row.dataset.visible = (!term || row.textContent.toLowerCase().includes(term)) ? '1' : '0';
        });
        stockCurrentPage = 1;
        renderStockTable();
    }

    if (officeSelect) {
        officeSelect.addEventListener('change', function () { filterEmployees(true); });
        if (window.jQuery) {
            window.jQuery(officeSelect).on('select2:select select2:clear', function () { filterEmployees(true); });
        }
        filterEmployees(true);
    }

    if (stockSearch) {
        stockSearch.addEventListener('input', applyStockFilter);
    }
    if (poFilter) {
        poFilter.addEventListener('change', function () {
            var url = new URL(window.location.href);
            if (this.value) {
                url.searchParams.set('po_id', this.value);
            } else {
                url.searchParams.delete('po_id');
            }
            window.location.href = url.toString();
        });
    }
    if (stockPerPage) {
        stockPerPage.addEventListener('change', function () {
            stockPageSize = parseInt(this.value || '25', 10);
            stockCurrentPage = 1;
            renderStockTable();
        });
    }
    if (stockPrevPage) {
        stockPrevPage.addEventListener('click', function () {
            stockCurrentPage--;
            renderStockTable();
        });
    }
    if (stockNextPage) {
        stockNextPage.addEventListener('click', function () {
            stockCurrentPage++;
            renderStockTable();
        });
    }

    applyStockFilter();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
