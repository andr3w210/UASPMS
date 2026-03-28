<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

function po_status_badge(string $status): string {
    // Normalize missing or empty status to 'encoded'
    $status = ($status ?? '') === '' ? 'encoded' : $status;
    $map = [
        'encoded'   => ['text-bg-secondary', 'Encoded'],
        'partial'   => ['text-bg-warning',   'Partial'],
        'completed' => ['text-bg-success',   'Completed'],
        'cancelled' => ['text-bg-danger',    'Cancelled'],
    ];
    [$class, $label] = $map[$status] ?? ['text-bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $class . '">' . h($label) . '</span>';
}

$db = db_connect();
$page_title = 'Purchase Orders';
$flash = get_flash();
$errors = [];
$purchaseOrders = [];
$suppliers = [];
$funds = [];
$procurementModes = [];
$accountCodes = [];
$catalogItems = [];
$classifications = [];
$unitOfMeasures = [];
$defaultRows = [
    ['item_type' => 'supply', 'stock_catalog_id' => '', 'account_code_id' => '', 'classification_id' => '', 'item_description' => '', 'quantity' => '1', 'unit_of_measure_id' => '', 'unit_cost' => '0.00'],
];
$form = [
    'system_reference' => '',
    'po_number' => '',
    'po_date' => date('Y-m-d'),
    'supplier_id' => '',
    'fund_id' => '',
    'supplier_address' => '',
    'mode_of_procurement_id' => '',
    'place_of_delivery' => 'University of Antique',
    'delivery_term_days' => '',
    'expected_delivery_date' => '',
];
$itemRows = $defaultRows;

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $form['system_reference'] = preview_module_code($db, 'purchase_orders');

    $supplierResult = $db->query("SELECT id, supplier_name, supplier_code, address FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($supplierResult) {
        $suppliers = $supplierResult->fetch_all(MYSQLI_ASSOC);
    }

    $fundResult = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($fundResult) {
        $funds = $fundResult->fetch_all(MYSQLI_ASSOC);
        if ($form['fund_id'] === '' && count($funds) === 1) {
            $form['fund_id'] = (string) $funds[0]['id'];
        }
    }

    $procurementModes = [];
    $colRes = $db->query("SHOW COLUMNS FROM mode_of_procurements LIKE 'mode_code'");
    if ($colRes && $colRes->num_rows > 0) {
        $procurementModeResult = $db->query("SELECT id, mode_code, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    } else {
        $procurementModeResult = $db->query("SELECT id, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    }
    if ($procurementModeResult) {
        $procurementModes = $procurementModeResult->fetch_all(MYSQLI_ASSOC);
    }

    $classificationResult = $db->query(
        "SELECT id, classification_code, classification_name,
                classification_group, useful_life_years,
                account_code_id
         FROM classifications
         WHERE is_active = 1
         ORDER BY classification_name ASC"
    );
    if ($classificationResult) {
        $classifications = $classificationResult->fetch_all(MYSQLI_ASSOC);
    }

    $accountCodeResult = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($accountCodeResult) {
        $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);
    }

    $catalogResult = $db->query("
        SELECT sc.id, sc.stock_no, sc.item_name, sc.item_description,
               sc.item_type, sc.account_code_id, sc.classification_id,
               sc.unit_of_measure_id,
               ac.account_code, ac.account_name,
               c.classification_name,
               u.abbreviation AS uom_abbr
        FROM stock_catalog sc
        LEFT JOIN account_codes ac ON ac.id = sc.account_code_id
        LEFT JOIN classifications c ON c.id = sc.classification_id
        LEFT JOIN unit_of_measures u ON u.id = sc.unit_of_measure_id
        WHERE sc.is_active = 1
        ORDER BY sc.item_type ASC, sc.stock_no ASC
    ");
    if ($catalogResult) {
        $catalogItems = $catalogResult->fetch_all(MYSQLI_ASSOC);
    }

    $uomResult = $db->query("SELECT id, uom_name, abbreviation FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
    if ($uomResult) {
        $unitOfMeasures = $uomResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'cancel_po') {
            $cancelId = (int)($_POST['cancel_id'] ?? 0);
            if ($cancelId > 0) {
                $checkStmt = $db->prepare("\n        SELECT id, status FROM purchase_orders\n        WHERE id = ? AND status = 'encoded'\n        LIMIT 1\n      ");
                if ($checkStmt) {
                    $checkStmt->bind_param('i', $cancelId);
                    $checkStmt->execute();
                    $poToCancel = $checkStmt->get_result()->fetch_assoc();
                    $checkStmt->close();

                    if (!$poToCancel) {
                        set_flash('error', 'PO cannot be cancelled. It may already be received or cancelled.');
                    } else {
                        $recvStmt = $db->prepare("\n            SELECT COUNT(*) AS cnt FROM receivings\n            WHERE purchase_order_id = ? AND status != 'cancelled'\n          ");
                        if ($recvStmt) {
                            $recvStmt->bind_param('i', $cancelId);
                            $recvStmt->execute();
                            $recvRow = $recvStmt->get_result()->fetch_assoc();
                            $recvStmt->close();

                            if ((int)($recvRow['cnt'] ?? 0) > 0) {
                                set_flash('error', 'Cannot cancel this PO — it already has receiving records.');
                            } else {
                                $cancelStmt = $db->prepare("\n              UPDATE purchase_orders\n              SET status = 'cancelled', updated_by = ?, updated_at = NOW()\n              WHERE id = ? AND status = 'encoded'\n            ");
                                if ($cancelStmt) {
                                    $userId = current_user_id();
                                    $cancelStmt->bind_param('ii', $userId, $cancelId);
                                    $cancelStmt->execute();
                                    $cancelStmt->close();
                                    set_flash('success', 'Purchase order cancelled successfully.');
                                } else {
                                    set_flash('error', 'Unable to cancel the purchase order.');
                                }
                            }
                        } else {
                            set_flash('error', 'Unable to verify receivings for this PO.');
                        }
                    }
                } else {
                    set_flash('error', 'Unable to verify PO status.');
                }
            }
            redirect('modules/purchase_orders/index.php');
        }

        if ($action === 'save') {
            $form['system_reference'] = preview_module_code($db, 'purchase_orders');
            $form['po_number'] = old($_POST, 'po_number');
            $form['po_date'] = old($_POST, 'po_date', date('Y-m-d'));
            $form['supplier_id'] = old($_POST, 'supplier_id');
            $form['fund_id'] = old($_POST, 'fund_id');
            $form['supplier_address'] = old($_POST, 'supplier_address');
            $form['mode_of_procurement_id'] = old($_POST, 'mode_of_procurement_id');
            $form['place_of_delivery'] = old($_POST, 'place_of_delivery', 'University of Antique');
            $form['delivery_term_days'] = old($_POST, 'delivery_term_days');
            $form['expected_delivery_date'] = old($_POST, 'expected_delivery_date');

            $postedRows = $_POST['items'] ?? [];
            $itemRows = [];

            if ($form['po_number'] === '') {
                $errors[] = 'PO number from the hard copy is required.';
            }
            if ($form['po_date'] === '') {
                $errors[] = 'PO date is required.';
            }
            if ($form['supplier_id'] === '') {
                $errors[] = 'Supplier is required.';
            }
            if ($form['fund_id'] === '') {
                $errors[] = 'Fund is required.';
            }
            if ($form['supplier_address'] === '') {
                $errors[] = 'Supplier address is required.';
            }
            if ($form['mode_of_procurement_id'] === '') {
                $errors[] = 'Mode of procurement is required.';
            }
            if ($form['place_of_delivery'] === '') {
                $errors[] = 'Place of delivery is required.';
            }
            if ($form['delivery_term_days'] !== '' && (!ctype_digit($form['delivery_term_days']) || (int) $form['delivery_term_days'] < 0)) {
                $errors[] = 'Delivery term (days) must be a non-negative whole number.';
            }

            $selectedProcurementMode = null;
            foreach ($procurementModes as $procurementMode) {
                if ((string) $procurementMode['id'] === $form['mode_of_procurement_id']) {
                    $selectedProcurementMode = $procurementMode;
                    break;
                }
            }
            if (!$selectedProcurementMode) {
                $errors[] = 'Selected mode of procurement is invalid.';
            }

            $selectedFund = null;
            foreach ($funds as $fund) {
                if ((string) $fund['id'] === $form['fund_id']) {
                    $selectedFund = $fund;
                    break;
                }
            }
            if (!$selectedFund) {
                $errors[] = 'Selected fund is invalid.';
            }

            if ($form['po_date'] !== '') {
                try {
                    $baseDate = new DateTimeImmutable($form['po_date']);
                    $daysToAdd = $form['delivery_term_days'] !== '' ? (int) $form['delivery_term_days'] : 0;
                    $form['expected_delivery_date'] = $baseDate->modify('+' . $daysToAdd . ' days')->format('Y-m-d');
                } catch (Exception $e) {
                    $errors[] = 'PO date is invalid.';
                }
            }

            $duplicateStmt = $db->prepare("SELECT id FROM purchase_orders WHERE po_number = ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('s', $form['po_number']);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'PO number already exists.';
                }
                $duplicateStmt->close();
            }

            $lineNo = 0;
            $totalAmount = 0.00;

            foreach ($postedRows as $row) {
                $description = trim((string) ($row['item_description'] ?? ''));
                $itemType = trim((string) ($row['item_type'] ?? 'supply'));
                $stockCatalogId = trim((string) ($row['stock_catalog_id'] ?? ''));
                $accountCodeId = trim((string) ($row['account_code_id'] ?? ''));
                $classificationId = trim((string) ($row['classification_id'] ?? ''));
                $quantity = (float) ($row['quantity'] ?? 0);
                $unitOfMeasureId = trim((string) ($row['unit_of_measure_id'] ?? ''));
                $unitCost = (float) ($row['unit_cost'] ?? 0);

                if ($description === '' && $quantity <= 0 && $unitCost <= 0) {
                    continue;
                }

                $lineNo++;
                $lineErrors = [];

                if ($description === '') {
                    $lineErrors[] = 'Description is required on line ' . $lineNo . '.';
                }
                if (!in_array($itemType, ['supply', 'semi_expendable', 'equipment'], true)) {
                    $lineErrors[] = 'Invalid item type on line ' . $lineNo . '.';
                }
                if ($quantity <= 0) {
                    $lineErrors[] = 'Quantity must be greater than zero on line ' . $lineNo . '.';
                }
                if ($accountCodeId === '') {
                    $lineErrors[] = 'Account code is required on line ' . $lineNo . '.';
                }
                if ($unitOfMeasureId === '') {
                    $lineErrors[] = 'Unit is required on line ' . $lineNo . '.';
                }
                if ($unitCost < 0) {
                    $lineErrors[] = 'Unit cost cannot be negative on line ' . $lineNo . '.';
                }

                $accountCodeValue = $accountCodeId !== '' ? (int) $accountCodeId : null;
                $selectedAccountCode = null;
                if ($accountCodeValue) {
                    foreach ($accountCodes as $accountCode) {
                        if ((int) $accountCode['id'] === $accountCodeValue) {
                            $selectedAccountCode = $accountCode;
                            $expectedGroup = $itemType === 'equipment' ? 'asset' : $itemType;
                            if ($accountCode['account_group'] !== $expectedGroup) {
                                $lineErrors[] = 'Account code does not match item type on line ' . $lineNo . '.';
                            }
                            break;
                        }
                    }
                }

                $classificationValue = $classificationId !== '' ? (int) $classificationId : null;
                if ($classificationValue) {
                    $matchedClassification = null;
                    foreach ($classifications as $classification) {
                        if ((int) $classification['id'] === $classificationValue) {
                            $matchedClassification = $classification;
                            break;
                        }
                    }

                    if ($matchedClassification) {
                        // Inventory class is optional; skip classification_group validation in this environment.
                    } else {
                        $classificationId = '';
                    }
                }

                foreach ($lineErrors as $lineError) {
                    $errors[] = $lineError;
                }

                $lineTotal = round($quantity * $unitCost, 2);
                $totalAmount += $lineTotal;

                $itemRows[] = [
                    'item_type' => $itemType,
                    'stock_catalog_id' => $stockCatalogId,
                    'account_code_id' => $accountCodeId,
                    'classification_id' => $classificationId,
                    'item_description' => $description,
                    'quantity' => number_format($quantity, 2, '.', ''),
                    'unit_of_measure_id' => $unitOfMeasureId,
                    'unit_cost' => number_format($unitCost, 2, '.', ''),
                ];
            }

            if (empty($itemRows)) {
                $errors[] = 'At least one PO item is required.';
                $itemRows = $defaultRows;
            }

            if (empty($errors)) {
                $supplierId = (int) $form['supplier_id'];
                $fundId = (int) $form['fund_id'];
                $modeOfProcurementId = (int) $form['mode_of_procurement_id'];
                $deliveryTermDays = $form['delivery_term_days'] !== '' ? (int) $form['delivery_term_days'] : null;
                $expectedDeliveryDate = $form['expected_delivery_date'] !== '' ? $form['expected_delivery_date'] : null;
                $userId = current_user_id();
                $systemReference = next_module_code($db, 'purchase_orders');
                $status = 'encoded';
                $purpose = null;
                $remarks = null;

                $db->begin_transaction();

                try {
                    $stmt = $db->prepare("INSERT INTO purchase_orders (system_reference, po_number, po_date, supplier_id, fund_id, supplier_address, mode_of_procurement_id, place_of_delivery, delivery_term_days, expected_delivery_date, status, purpose, remarks, total_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) {
                        throw new RuntimeException('Unable to prepare PO header insert.');
                    }

                    $stmt->bind_param('sssiisisissssdi', $systemReference, $form['po_number'], $form['po_date'], $supplierId, $fundId, $form['supplier_address'], $modeOfProcurementId, $form['place_of_delivery'], $deliveryTermDays, $expectedDeliveryDate, $status, $purpose, $remarks, $totalAmount, $userId);
                    $stmt->execute();
                    $purchaseOrderId = (int) $stmt->insert_id;
                    $stmt->close();

                    $itemStmt = $db->prepare("INSERT INTO purchase_order_items (purchase_order_id, stock_catalog_id, line_no, item_type, account_code_id, classification_id, item_description, quantity, unit_of_measure_id, unit_cost, line_total) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$itemStmt) {
                        throw new RuntimeException('Unable to prepare PO item insert.');
                    }

                    foreach ($itemRows as $index => $row) {
                        $lineNo = $index + 1;
                        $stockCatalogId = $row['stock_catalog_id'] !== '' ? (int) $row['stock_catalog_id'] : 0;
                        $accountCodeId = $row['account_code_id'] !== '' ? (int) $row['account_code_id'] : null;
                        $classificationId = $row['classification_id'] !== '' ? (int) $row['classification_id'] : null;
                        $unitOfMeasureId = $row['unit_of_measure_id'] !== '' ? (int) $row['unit_of_measure_id'] : null;
                        $quantity = (float) $row['quantity'];
                        $unitCost = (float) $row['unit_cost'];
                        $lineTotal = round($quantity * $unitCost, 2);
                        $itemStmt->bind_param('iiisiisdidd', $purchaseOrderId, $stockCatalogId, $lineNo, $row['item_type'], $accountCodeId, $classificationId, $row['item_description'], $quantity, $unitOfMeasureId, $unitCost, $lineTotal);
                        $itemStmt->execute();
                    }
                    $itemStmt->close();

                    $db->commit();
                    set_flash('success', 'Purchase order encoded successfully.');
                    redirect('modules/purchase_orders/index.php');
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to save the purchase order.';
                }
            }
        }
    }

    // build filters from GET
    $filterStatus = $_GET['status'] ?? '';
    $filterSupplierId = isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '' ? (int) $_GET['supplier_id'] : null;
    $filterDateFrom = $_GET['date_from'] ?? '';
    $filterDateTo = $_GET['date_to'] ?? '';
    $filterQ = trim($_GET['q'] ?? '');

    $where = [];
    $params = [];
    $types = '';

    if ($filterStatus !== '' && $filterStatus !== 'all') {
        $where[] = 'po.status = ?';
        $params[] = $filterStatus;
        $types .= 's';
    }
    if ($filterSupplierId !== null) {
        $where[] = 'po.supplier_id = ?';
        $params[] = $filterSupplierId;
        $types .= 'i';
    }
    if ($filterDateFrom !== '') {
        $where[] = 'po.po_date >= ?';
        $params[] = $filterDateFrom;
        $types .= 's';
    }
    if ($filterDateTo !== '') {
        $where[] = 'po.po_date <= ?';
        $params[] = $filterDateTo;
        $types .= 's';
    }
    if ($filterQ !== '') {
        $where[] = '(po.po_number LIKE ? OR po.system_reference LIKE ?)';
        $like = '%' . $filterQ . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

        $sql = "SELECT po.id, po.system_reference, po.po_number, po.po_date,\n               po.expected_delivery_date, po.status, po.total_amount,\n               po.place_of_delivery,\n               s.supplier_name, f.fund_name,\n               mop.mode_name AS mode_of_procurement_name,\n               COUNT(DISTINCT poi.id) AS total_lines,\n               COALESCE(SUM(poi.quantity), 0) AS total_qty,\n               COALESCE((\n                   SELECT SUM(ri.quantity_accepted)\n                   FROM receiving_items ri\n                   INNER JOIN receivings r ON r.id = ri.receiving_id\n                       AND r.status != 'cancelled'\n                   WHERE ri.purchase_order_item_id IN (\n                       SELECT id FROM purchase_order_items\n                       WHERE purchase_order_id = po.id\n                   )\n               ), 0) AS total_received_qty\n        FROM purchase_orders po\n        INNER JOIN suppliers s ON s.id = po.supplier_id\n        INNER JOIN funds f ON f.id = po.fund_id\n        LEFT JOIN mode_of_procurements mop ON mop.id = po.mode_of_procurement_id\n        LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id\n        " . $whereSql . "\n        GROUP BY po.id, po.system_reference, po.po_number, po.po_date,\n                 po.expected_delivery_date, po.status, po.total_amount,\n                 po.place_of_delivery, s.supplier_name, f.fund_name,\n                 mop.mode_name\n        ORDER BY po.po_date DESC, po.id DESC";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $bindParams = array_merge([$types], $params);
            $refs = [];
            foreach ($bindParams as $k => $v) {
                $refs[$k] = &$bindParams[$k];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $purchaseOrders = $res->fetch_all(MYSQLI_ASSOC);
        }
        $stmt->close();
    } else {
            // fallback: run without filters
            $poResult = $db->query("SELECT po.id, po.system_reference, po.po_number, po.po_date, po.status, po.total_amount, s.supplier_name, f.fund_name, mop.mode_name AS mode_of_procurement_name, po.place_of_delivery FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id INNER JOIN funds f ON f.id = po.fund_id LEFT JOIN mode_of_procurements mop ON mop.id = po.mode_of_procurement_id ORDER BY po.po_date DESC, po.id DESC");
            if ($poResult) {
                $purchaseOrders = $poResult->fetch_all(MYSQLI_ASSOC);
            }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0">Encoded Purchase Orders</h5>
            <div class="d-flex gap-2 align-items-center">
                <a href="<?php echo base_url('modules/purchase_orders/create.php'); ?>" class="btn btn-primary btn-sm">Encode New PO</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-3 p-lg-4">
                <div class="mb-4">
                    <form method="get" class="row g-2 g-lg-3 align-items-end">
                        <div class="col-12 col-md-2 col-lg-1">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php $filterStatus = $_GET['status'] ?? 'all'; ?>
                                <option value="all">All</option>
                                <option value="encoded" <?php echo $filterStatus === 'encoded' ? 'selected' : ''; ?>>Encoded</option>
                                <option value="partial" <?php echo $filterStatus === 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4 col-lg-3">
                            <label class="form-label small mb-1">Supplier</label>
                            <select name="supplier_id" class="form-select form-select-sm">
                                <?php $filterSupplierId = $_GET['supplier_id'] ?? ''; ?>
                                <option value="">All</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $filterSupplierId !== '' && (string) $filterSupplierId === (string) $s['id'] ? 'selected' : ''; ?>><?php echo h($s['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1">Date from</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo h($_GET['date_from'] ?? ''); ?>">
                        </div>

                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1">Date to</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo h($_GET['date_to'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-md-4 col-lg-3">
                            <label class="form-label small mb-1">Search</label>
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="PO number or system ref" value="<?php echo h($_GET['q'] ?? ''); ?>">
                        </div>

                        <div class="col-12 col-lg-auto">
                            <button class="btn btn-sm btn-outline-secondary px-3">Filter</button>
                            <a href="<?php echo base_url('modules/purchase_orders/index.php'); ?>" class="btn btn-sm btn-link">Clear</a>
                        </div>
                    </form>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>System Ref</th>
                                <th>PO Number</th>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Fund</th>
                                <th>Mode</th>
                                <th>Receiving</th>
                                <th>End Date</th>
                                <th>Place of Delivery</th>
                                <th>Status</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($purchaseOrders): ?>
                                <?php foreach ($purchaseOrders as $po): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($po['system_reference']); ?></td>
                                        <td><?php echo h($po['po_number']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($po['po_date']))); ?></td>
                                        <td style="min-width:220px;"><?php echo h($po['supplier_name']); ?></td>
                                        <td style="min-width:105px;"><?php echo h($po['fund_name']); ?></td>
                                        <td style="min-width:260px;"><?php echo h($po['mode_of_procurement_name'] ?? ''); ?></td>
                                        <?php
                                            $pct = $po['total_qty'] > 0
                                                ? min(100, (int) round(($po['total_received_qty'] / $po['total_qty']) * 100))
                                                : 0;
                                            $barClass = $pct >= 100 ? 'bg-success' : ($pct > 0 ? 'bg-warning' : 'bg-secondary');
                                        ?>
                                        <td style="min-width:120px;">
                                            <div class="progress" style="height:8px;" title="<?php echo $pct; ?>% received">
                                                <div class="progress-bar <?php echo $barClass; ?>" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                            <div class="small text-muted mt-1 fw-semibold"><?php echo $pct; ?>%</div>
                                        </td>
                                        <td><?php echo $po['expected_delivery_date'] ? h(date('M d, Y', strtotime($po['expected_delivery_date']))) : ''; ?></td>
                                        <td style="min-width:130px;"><?php echo h($po['place_of_delivery'] ?? ''); ?></td>
                                        <td class="text-center"><?php echo po_status_badge($po['status']); ?></td>
                                        <td class="text-end fw-semibold"><?php echo h(number_format((float) $po['total_amount'], 2)); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                                <a href="<?php echo base_url('modules/purchase_orders/view.php?id=' . (int) $po['id']); ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                                                    View / Print
                                                </a>
                                                <?php if ($po['status'] === 'encoded'): ?>
                                                    <a href="<?php echo base_url('modules/purchase_orders/edit.php?id=' . (int) $po['id']); ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="confirmCancelPO(<?php echo (int)$po['id']; ?>, '<?php echo h(addslashes($po['po_number'])); ?>')">
                                                        Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="12" class="text-center text-muted py-4">No purchase orders encoded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

    <form id="cancelPoForm" method="post" style="display:none;">
        <input type="hidden" name="action" value="cancel_po">
        <input type="hidden" name="cancel_id" id="cancelPoId" value="">
    </form>

    <script>
    function confirmCancelPO(id, poNumber) {
        if (!confirm(
            'Cancel PO No. ' + poNumber + '?\n\n' +
            'This cannot be undone. The PO will be marked as Cancelled ' +
            'and can no longer be received or edited.'
        )) return;
        document.getElementById('cancelPoId').value = id;
        document.getElementById('cancelPoForm').submit();
    }
    </script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
