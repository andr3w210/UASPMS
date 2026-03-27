<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

$db = db_connect();
$page_title = 'Encode Purchase Order';
$flash = get_flash();
$errors = [];
$suppliers = [];
$funds = [];
$procurementModes = [];
$accountCodes = [];
$catalogItems = [];
$classifications = [];
$unitOfMeasures = [];
$activeThreshold = ['equipment_min' => 50000.00, 'semi_hv_min' => 5000.01];
$poItemSupportsSemiType = false;
$defaultRows = [
    ['item_type' => 'supply', 'semi_expendable_type' => '', 'stock_catalog_id' => '', 'account_code_id' => '', 'classification_id' => '', 'item_description' => '', 'quantity' => '1', 'unit_of_measure_id' => '', 'unit_cost' => '0.00'],
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

if ($db) {
    // load picklists
    $supplierResult = $db->query("SELECT id, supplier_name, supplier_code, address FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($supplierResult) $suppliers = $supplierResult->fetch_all(MYSQLI_ASSOC);

    $fundResult = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($fundResult) $funds = $fundResult->fetch_all(MYSQLI_ASSOC);

    $procurementModes = [];
    $colRes = $db->query("SHOW COLUMNS FROM mode_of_procurements LIKE 'mode_code'");
    if ($colRes && $colRes->num_rows > 0) {
        $procurementModeResult = $db->query("SELECT id, mode_code, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    } else {
        $procurementModeResult = $db->query("SELECT id, mode_name FROM mode_of_procurements WHERE is_active = 1 ORDER BY mode_name ASC");
    }
    if ($procurementModeResult) $procurementModes = $procurementModeResult->fetch_all(MYSQLI_ASSOC);

    $classificationResult = $db->query("SELECT id, classification_code, classification_name, classification_group, account_code_id FROM classifications WHERE is_active = 1 ORDER BY classification_name ASC");
    if ($classificationResult) $classifications = $classificationResult->fetch_all(MYSQLI_ASSOC);

    $accountCodeResult = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($accountCodeResult) $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);

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
    if ($catalogResult) $catalogItems = $catalogResult->fetch_all(MYSQLI_ASSOC);

    $uomResult = $db->query("SELECT id, uom_name, abbreviation FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
    if ($uomResult) $unitOfMeasures = $uomResult->fetch_all(MYSQLI_ASSOC);
    $activeThreshold = get_active_threshold($db);
    $poItemSupportsSemiType = function_exists('schema_has_column')
        ? schema_has_column($db, 'purchase_order_items', 'semi_expendable_type')
        : false;

    // preview system reference for new PO
    $form['system_reference'] = preview_module_code($db, 'purchase_orders');

    // load any posted rows (server-side processing remains unchanged)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if ($action === 'save') {
            if (!csrf_verify()) {
                $errors[] = 'Invalid CSRF token.';
            }
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
            if ($postedRows && is_array($postedRows)) {
                $itemRows = $postedRows;
            }

                        $form['system_reference'] = preview_module_code($db, 'purchase_orders');

                        if ($form['po_number'] === '') $errors[] = 'PO number from the hard copy is required.';
                        if ($form['po_date'] === '')   $errors[] = 'PO date is required.';
                        if ($form['supplier_id'] === '') $errors[] = 'Supplier is required.';
                        if ($form['fund_id'] === '')   $errors[] = 'Fund is required.';
                        if ($form['supplier_address'] === '') $errors[] = 'Supplier address is required.';
                        if ($form['mode_of_procurement_id'] === '') $errors[] = 'Mode of procurement is required.';
                        if ($form['place_of_delivery'] === '') $errors[] = 'Place of delivery is required.';
                        if ($form['delivery_term_days'] !== '' &&
                                (!ctype_digit($form['delivery_term_days']) ||
                                 (int)$form['delivery_term_days'] < 0)) {
                            $errors[] = 'Delivery term must be a non-negative whole number.';
                        }

                        // Check duplicate PO number
                        $dupStmt = $db->prepare("SELECT id FROM purchase_orders WHERE po_number = ? LIMIT 1");
                        if ($dupStmt) {
                            $dupStmt->bind_param('s', $form['po_number']);
                            $dupStmt->execute();
                            if ($dupStmt->get_result()->fetch_assoc()) {
                                $errors[] = 'PO number already exists.';
                            }
                            $dupStmt->close();
                        }

                        // Calculate expected delivery date
                        if ($form['po_date'] !== '') {
                            try {
                                $baseDate  = new DateTimeImmutable($form['po_date']);
                                $daysToAdd = $form['delivery_term_days'] !== ''
                                    ? (int)$form['delivery_term_days'] : 0;
                                $form['expected_delivery_date'] = $baseDate
                                    ->modify('+' . $daysToAdd . ' days')
                                    ->format('Y-m-d');
                            } catch (Exception $e) {
                                $errors[] = 'PO date is invalid.';
                            }
                        }

                        // Validate and build item rows
                        $validatedItems = [];
                        $totalAmount    = 0.00;
                        $lineNo         = 0;

                        foreach ($postedRows as $row) {
                            $description      = trim((string)($row['item_description'] ?? ''));
                            $itemType         = trim((string)($row['item_type'] ?? 'supply'));
                            $semiExpendableType = '';
                            $stockCatalogId   = trim((string)($row['stock_catalog_id'] ?? ''));
                            $accountCodeId    = trim((string)($row['account_code_id'] ?? ''));
                            $classificationId = trim((string)($row['classification_id'] ?? ''));
                            $quantity         = (float)($row['quantity'] ?? 0);
                            $unitOfMeasureId  = trim((string)($row['unit_of_measure_id'] ?? ''));
                            $unitCost         = (float)($row['unit_cost'] ?? 0);

                            if ($description === '' && $quantity <= 0 && $unitCost <= 0) continue;

                            $lineNo++;

                            if ($description === '')
                                $errors[] = 'Description is required on line ' . $lineNo . '.';
                            if (!in_array($itemType, ['supply','semi_expendable','equipment'], true))
                                $errors[] = 'Invalid item type on line ' . $lineNo . '.';
                            if ($quantity <= 0)
                                $errors[] = 'Quantity must be greater than zero on line ' . $lineNo . '.';
                            if ($accountCodeId === '')
                                $errors[] = 'Account code is required on line ' . $lineNo . '.';
                            if ($unitOfMeasureId === '')
                                $errors[] = 'Unit is required on line ' . $lineNo . '.';

                            if ($itemType === 'semi_expendable') {
                                $semiExpendableType = $unitCost >= (float) $activeThreshold['semi_hv_min']
                                    ? 'high_value'
                                    : 'low_value';
                            }

                            $lineTotal     = round($quantity * $unitCost, 2);
                            $totalAmount  += $lineTotal;
                            $validatedItems[] = [
                                'item_type'          => $itemType,
                                'semi_expendable_type' => $semiExpendableType,
                                'stock_catalog_id'   => $stockCatalogId !== '' ? (int)$stockCatalogId : null,
                                'account_code_id'    => $accountCodeId !== '' ? (int)$accountCodeId : null,
                                'classification_id'  => $classificationId !== '' ? (int)$classificationId : null,
                                'item_description'   => $description,
                                'quantity'           => $quantity,
                                'unit_of_measure_id' => $unitOfMeasureId !== '' ? (int)$unitOfMeasureId : null,
                                'unit_cost'          => $unitCost,
                                'line_total'         => $lineTotal,
                            ];
                        }

                        if (empty($validatedItems)) $errors[] = 'At least one PO item is required.';

                        // DB INSERT — only runs if no errors
                        if (empty($errors)) {
                            $supplierId          = (int)$form['supplier_id'];
                            $fundId              = (int)$form['fund_id'];
                            $modeId              = (int)$form['mode_of_procurement_id'];
                            $deliveryTermDays    = $form['delivery_term_days'] !== ''
                                ? (int)$form['delivery_term_days'] : null;
                            $expectedDelivery    = $form['expected_delivery_date'] !== ''
                                ? $form['expected_delivery_date'] : null;
                            $userId              = current_user_id();
                            $systemReference     = next_module_code($db, 'purchase_orders');
                            $status              = 'encoded';

                            $db->begin_transaction();
                            try {
                                $headerStmt = $db->prepare("\n        INSERT INTO purchase_orders\n          (system_reference, po_number, po_date, supplier_id, fund_id,\n           supplier_address, mode_of_procurement_id, place_of_delivery,\n           delivery_term_days, expected_delivery_date, status,\n           purpose, remarks, total_amount, created_by)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)\n      ");
                                if (!$headerStmt) throw new RuntimeException('Prepare failed: header');

                                $headerStmt->bind_param(
                                    'sssiisissssdi',
                                    $systemReference,
                                    $form['po_number'],
                                    $form['po_date'],
                                    $supplierId,
                                    $fundId,
                                    $form['supplier_address'],
                                    $modeId,
                                    $form['place_of_delivery'],
                                    $deliveryTermDays,
                                    $expectedDelivery,
                                    $status,
                                    $totalAmount,
                                    $userId
                                );
                                $headerStmt->execute();
                                $purchaseOrderId = (int)$headerStmt->insert_id;
                                $headerStmt->close();

                                if ($poItemSupportsSemiType) {
                                    $itemStmt = $db->prepare("\n        INSERT INTO purchase_order_items\n          (purchase_order_id, stock_catalog_id, line_no, item_type, semi_expendable_type, account_code_id,\n           classification_id, item_description, quantity,\n           unit_of_measure_id, unit_cost, line_total)\n        VALUES (?, NULLIF(?,0), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?)\n      ");
                                } else {
                                    $itemStmt = $db->prepare("\n        INSERT INTO purchase_order_items\n          (purchase_order_id, stock_catalog_id, line_no, item_type, account_code_id,\n           classification_id, item_description, quantity,\n           unit_of_measure_id, unit_cost, line_total)\n        VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, ?, ?)\n      ");
                                }
                                if (!$itemStmt) throw new RuntimeException('Prepare failed: items');

                                foreach ($validatedItems as $index => $item) {
                                    $ln = $index + 1;
                                    if ($poItemSupportsSemiType) {
                                        $itemStmt->bind_param(
                                            'iiissiisdidd',
                                            $purchaseOrderId,
                                            $item['stock_catalog_id'],
                                            $ln,
                                            $item['item_type'],
                                            $item['semi_expendable_type'],
                                            $item['account_code_id'],
                                            $item['classification_id'],
                                            $item['item_description'],
                                            $item['quantity'],
                                            $item['unit_of_measure_id'],
                                            $item['unit_cost'],
                                            $item['line_total']
                                        );
                                    } else {
                                        $itemStmt->bind_param(
                                            'iiisiisdidd',
                                            $purchaseOrderId,
                                            $item['stock_catalog_id'],
                                            $ln,
                                            $item['item_type'],
                                            $item['account_code_id'],
                                            $item['classification_id'],
                                            $item['item_description'],
                                            $item['quantity'],
                                            $item['unit_of_measure_id'],
                                            $item['unit_cost'],
                                            $item['line_total']
                                        );
                                    }
                                    $itemStmt->execute();
                                }
                                $itemStmt->close();

                                $db->commit();
                                set_flash('success', 'Purchase order encoded successfully.');
                                redirect('modules/purchase_orders/index.php');

                            } catch (Throwable $e) {
                                $db->rollback();
                                $errors[] = 'Unable to save the purchase order. Please try again.';
                            }
                        }
        }
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
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                                <form id="purchaseOrderForm" method="post">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">

                                        <div class="card mb-4" style="border-color: var(--bs-primary-border-subtle);">
                                            <div class="card-body p-3">
                                                <div class="d-flex align-items-start gap-3 flex-wrap">
                                                    <div style="flex:1; min-width:200px;">
                                                        <div class="fw-semibold mb-1" style="font-size:13px;">Scan Hard Copy PO</div>
                                                        <div class="text-muted" style="font-size:12px; line-height:1.5;">Upload a clear photo or scanned PDF of your physical PO. Gemini AI will read it and fill in the form automatically. Always review before saving.</div>
                                                    </div>
                                                    <div class="d-flex align-items-center gap-2 flex-wrap" style="flex-shrink:0;">
                                                        <input type="file" id="poScanFile" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf" class="form-control form-control-sm" style="max-width:220px; font-size:12px;">
                                                        <button type="button" id="poScanBtn" class="btn btn-outline-primary btn-sm">Read PO</button>
                                                    </div>
                                                </div>
                                                <div id="poScanStatus" style="display:none; margin-top:10px; font-size:12px; padding:8px 12px; border-radius:6px;"></div>
                                                <div class="text-muted mt-2" style="font-size:11px; line-height:1.6;">Tips for best results: take photo in good lighting · keep PO flat with no folds · make sure all text is readable · avoid glare on laminated copies · PDF scans work best</div>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                        <div class="col-md-4">
                            <label for="po_number" class="form-label">Hard Copy PO Number</label>
                            <input type="text" class="form-control" id="po_number" name="po_number" value="<?php echo h($form['po_number']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="po_date" class="form-label">PO Date</label>
                            <input type="date" class="form-control" id="po_date" name="po_date" value="<?php echo h($form['po_date']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Supplier</label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>" data-address="<?php echo h($supplier['address'] ?? ''); ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name'] . ' (' . $supplier['supplier_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="fund_id" class="form-label">Fund</label>
                            <select class="form-select" id="fund_id" name="fund_id" required>
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>" <?php echo $form['fund_id'] === (string) $fund['id'] ? 'selected' : ''; ?>><?php echo h($fund['fund_code'] . ' - ' . $fund['fund_name'] . ($fund['fund_source'] !== null && $fund['fund_source'] !== '' ? ' - ' . $fund['fund_source'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label for="supplier_address" class="form-label">Supplier Address</label>
                            <input type="text" class="form-control" id="supplier_address" name="supplier_address" value="<?php echo h($form['supplier_address']); ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="mode_of_procurement" class="form-label">Mode of Procurement</label>
                            <select class="form-select" id="mode_of_procurement" name="mode_of_procurement_id">
                                <option value="">Select mode</option>
                                <?php foreach ($procurementModes as $procurementMode): ?>
                                    <option value="<?php echo (int) $procurementMode['id']; ?>" <?php echo $form['mode_of_procurement_id'] === (string) $procurementMode['id'] ? 'selected' : ''; ?>><?php echo h($procurementMode['mode_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label for="place_of_delivery" class="form-label">Place of Delivery</label>
                            <input type="text" class="form-control" id="place_of_delivery" name="place_of_delivery" value="<?php echo h($form['place_of_delivery']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="delivery_term_days" class="form-label">Delivery Term (Days)</label>
                            <input type="number" class="form-control" id="delivery_term_days" name="delivery_term_days" min="0" step="1" value="<?php echo h($form['delivery_term_days']); ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="expected_delivery_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="expected_delivery_date" name="expected_delivery_date" value="<?php echo h($form['expected_delivery_date']); ?>" readonly>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3 mt-4">
                        <h6 class="mb-0">PO Items</h6>
                        <div class="dropdown">
                            <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">Add Item</button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="supply">Supply</button></li>
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="semi_expendable">Semi-Expendable</button></li>
                                <li><button class="dropdown-item add-line-btn" type="button" data-type="equipment">Equipment</button></li>
                            </ul>
                        </div>
                    </div>

                    <div class="row g-3 mb-4" id="poSplitPanel">
                        <div class="col-lg-4">
                            <div class="card h-100">
                                <div class="card-body p-3 d-flex flex-column" style="gap:10px;">
                                    <div class="d-flex gap-2 flex-wrap">
                                        <div class="input-group input-group-sm" style="max-width:160px;">
                                            <input type="text" class="form-control form-control-sm" id="lineSearchInput" placeholder="Search lines...">
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn active" data-filter="all" type="button">All</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="done" type="button">Done</button>
                                        <button class="btn btn-sm btn-outline-secondary po-filter-btn" data-filter="empty" type="button">Empty</button>
                                    </div>

                                    <div id="poLineListScroll" style="flex:1; overflow-y:auto; max-height:380px; display:flex; flex-direction:column; gap:2px;"></div>

                                    <div style="border-top:0.5px solid var(--bs-border-color); padding-top:8px; font-size:12px;">
                                        <div class="d-flex justify-content-between mb-1">
                                            <span class="text-muted">Completed</span>
                                            <span id="lineCompletedCount" class="text-success fw-semibold">0 / 0</span>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <span class="text-muted">Total so far</span>
                                            <span id="lineTotalSoFar" class="fw-semibold">0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-8">
                            <div class="card h-100">
                                <div class="card-body p-3" id="poLineEditor">
                                    <div id="poEditorEmpty" class="text-center text-muted py-5">
                                        <div class="mb-2">No lines yet.</div>
                                        <div class="small">Use "Add Item" to add your first PO line.</div>
                                    </div>
                                    <div id="poEditorContent" style="display:none;">
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="fw-semibold" id="editorLineLabel">Line 1</span>
                                            <span class="badge" id="editorTypeBadge">Supply</span>
                                            <span class="badge text-bg-secondary" id="editorSemiTypeBadge" style="display:none;">LV</span>
                                            <div class="flex-fill"></div>
                                            <span class="small text-muted" id="editorLineCounter">1 of 1</span>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:12px;">
                                                Select from Stock Catalog
                                                <span class="text-muted fw-normal">(optional — or fill manually below)</span>
                                            </label>
                                            <select class="form-select form-select-sm" id="editorCatalogSearch" data-placeholder="Search stock no. or item name...">
                                                <option value="">-- Type to search catalog --</option>
                                            </select>
                                            <div id="editorCatalogHint" class="small text-muted mt-1" style="display:none;">
                                                Fields below auto-filled from catalog. You can still edit them.
                                            </div>
                                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" id="openCatalogQuickAdd" style="font-size:12px;">
                                                    Add to Catalog
                                                </button>
                                                <a href="<?php echo base_url('modules/stock_catalog/index.php?mode=create'); ?>" target="_blank" rel="noopener" class="btn btn-outline-light border btn-sm" style="font-size:12px;">
                                                    Open Full Catalog Form
                                                </a>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Account Code <span class="text-danger">*</span></label>
                                            <select class="form-select form-select-sm" id="editorAccountCode" name="_editor_account_code" style="font-size:13px;"></select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Inventory Class <span class="text-muted" style="font-size:10px;">(optional)</span></label>
                                            <select class="form-select form-select-sm" id="editorClassification" name="_editor_classification" style="font-size:13px;"></select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" style="font-size:11px;">Description <span class="text-danger">*</span></label>
                                            <textarea class="form-control form-control-sm" id="editorDescription" rows="3" placeholder="Item description from hard copy PO" style="font-size:13px; border-left:3px solid var(--bs-primary-border-subtle); border-radius:0 4px 4px 0;"></textarea>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-3">
                                                <label class="form-label" style="font-size:11px;">Quantity <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control form-control-sm text-center" id="editorQty" min="0.01" step="0.01" value="1" style="font-size:13px;">
                                            </div>
                                            <div class="col-5">
                                                <label class="form-label" style="font-size:11px;">Unit</label>
                                                <select class="form-select form-select-sm" id="editorUom" style="font-size:13px;"></select>
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label" style="font-size:11px;">Unit Cost</label>
                                                <input type="number" class="form-control form-control-sm text-end" id="editorUnitCost" min="0" step="0.01" value="0.00" style="font-size:13px;">
                                            </div>
                                        </div>

                                        <div class="d-flex justify-content-end align-items-baseline gap-2 mb-3">
                                            <span class="text-muted small">Amount:</span>
                                            <span id="editorAmount" class="fw-semibold" style="font-size:16px;">0.00</span>
                                        </div>

                                        <div style="border-top:0.5px solid var(--bs-border-color); padding-top:10px;">
                                            <div class="progress mb-2" style="height:4px;">
                                                <div class="progress-bar" id="editorProgress" style="width:0%; transition:width .3s;"></div>
                                            </div>
                                            <div class="d-flex gap-2 align-items-center">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorPrev">← Prev</button>
                                                <div class="flex-fill text-center small text-muted" id="editorProgressLabel">0 / 0 completed</div>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" id="editorNext">Next →</button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" id="editorDeleteLine">Remove</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="poHiddenInputs"></div>

                    <div style="position:sticky; bottom:0; z-index:10; background:var(--bs-body-bg); border-top:0.5px solid var(--bs-border-color); padding:10px 0; margin-top:4px;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small" id="footerLineCount">0 line(s)</span>
                            <span class="fw-semibold">Total: <span id="poGrandTotal">0.00</span></span>
                            <button type="submit" class="btn btn-primary btn-sm">Save Purchase Order</button>
                        </div>
                    </div>

                </form>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="catalogQuickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Catalog Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 px-3" id="catalogQuickAddError" style="display:none; font-size:13px;"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="quickAddItemName" class="form-label">Item Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="quickAddItemName">
                    </div>
                    <div class="col-md-6">
                        <label for="quickAddItemType" class="form-label">Item Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="quickAddItemType">
                            <option value="supply">Supply</option>
                            <option value="semi_expendable">Semi-Expendable</option>
                            <option value="equipment">Equipment</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label for="quickAddDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="quickAddDescription" rows="3"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="quickAddAccountCode" class="form-label">Account Code <span class="text-danger">*</span></label>
                        <select class="form-select" id="quickAddAccountCode"></select>
                    </div>
                    <div class="col-md-6">
                        <label for="quickAddClassification" class="form-label">Classification</label>
                        <select class="form-select" id="quickAddClassification"></select>
                    </div>
                    <div class="col-md-6">
                        <label for="quickAddUom" class="form-label">Unit of Measure</label>
                        <select class="form-select" id="quickAddUom"></select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveCatalogQuickAdd">Save to Catalog</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    function formatNumber(n) { return parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    var accountCodes = <?php echo json_encode($accountCodes, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var catalogItems = <?php echo json_encode($catalogItems, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var classifications = <?php echo json_encode($classifications, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var units = <?php echo json_encode($unitOfMeasures, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var semiHvMin = <?php echo json_encode((float) ($activeThreshold['semi_hv_min'] ?? 5000.01)); ?>;

    var poLinesFromPhp = <?php echo json_encode(array_values($itemRows), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?> || [];
    var poLines = [];
    var activeIndex = -1;
    var currentFilter = 'all';
    var searchTerm = '';

    var el = {
        lineListScroll: document.getElementById('poLineListScroll'),
        lineSearchInput: document.getElementById('lineSearchInput'),
        editorEmpty: document.getElementById('poEditorEmpty'),
        editorContent: document.getElementById('poEditorContent'),
        editorLineLabel: document.getElementById('editorLineLabel'),
        editorTypeBadge: document.getElementById('editorTypeBadge'),
        editorSemiTypeBadge: document.getElementById('editorSemiTypeBadge'),
        editorLineCounter: document.getElementById('editorLineCounter'),
        editorCatalogSearch: document.getElementById('editorCatalogSearch'),
        editorCatalogHint: document.getElementById('editorCatalogHint'),
        editorAccountCode: document.getElementById('editorAccountCode'),
        editorClassification: document.getElementById('editorClassification'),
        editorDescription: document.getElementById('editorDescription'),
        editorQty: document.getElementById('editorQty'),
        editorUom: document.getElementById('editorUom'),
        editorUnitCost: document.getElementById('editorUnitCost'),
        editorAmount: document.getElementById('editorAmount'),
        editorProgress: document.getElementById('editorProgress'),
        editorProgressLabel: document.getElementById('editorProgressLabel'),
        editorPrev: document.getElementById('editorPrev'),
        editorNext: document.getElementById('editorNext'),
        
        editorDeleteLine: document.getElementById('editorDeleteLine'),
        lineCompletedCount: document.getElementById('lineCompletedCount'),
        lineTotalSoFar: document.getElementById('lineTotalSoFar'),
        footerLineCount: document.getElementById('footerLineCount'),
        poGrandTotal: document.getElementById('poGrandTotal'),
        poHiddenInputs: document.getElementById('poHiddenInputs'),
        openCatalogQuickAdd: document.getElementById('openCatalogQuickAdd'),
        catalogQuickAddModal: document.getElementById('catalogQuickAddModal'),
        catalogQuickAddError: document.getElementById('catalogQuickAddError'),
        quickAddItemName: document.getElementById('quickAddItemName'),
        quickAddDescription: document.getElementById('quickAddDescription'),
        quickAddItemType: document.getElementById('quickAddItemType'),
        quickAddAccountCode: document.getElementById('quickAddAccountCode'),
        quickAddClassification: document.getElementById('quickAddClassification'),
        quickAddUom: document.getElementById('quickAddUom'),
        saveCatalogQuickAdd: document.getElementById('saveCatalogQuickAdd')
    };
    var quickAddModal = (window.bootstrap && el.catalogQuickAddModal) ? new bootstrap.Modal(el.catalogQuickAddModal) : null;

    function lineIsComplete(line) { return (line.account_code_id || '') !== '' && (String(line.item_description || '').trim() !== '') && (parseFloat(line.quantity || 0) > 0); }
    function typeBadgeClass(t) { if (t === 'equipment') return 'bg-warning text-dark'; if (t === 'semi_expendable') return 'bg-primary'; if (t === 'supply') return 'bg-success'; return 'bg-secondary'; }
    function typeLabel(t) { if (t === 'equipment') return 'Equipment'; if (t === 'semi_expendable') return 'Semi-Expendable'; return 'Supply'; }
    function typeShortLabel(t) { if (t === 'equipment') return 'Equip'; if (t === 'semi_expendable') return 'Semi'; return 'Supply'; }
    function expectedAccountGroup(itemType) { return itemType === 'equipment' ? 'asset' : itemType; }
    function getSemiType(unitCost) { return parseFloat(unitCost || 0) >= semiHvMin ? 'high_value' : 'low_value'; }
    function lineNeedsSemiType(line) { return !!line && line.item_type === 'semi_expendable'; }
    function classificationMatchesType(classification, itemType) { return !classification || !classification.classification_group || classification.classification_group === expectedAccountGroup(itemType); }
    function accountCodeMatchesType(accountCode, itemType) { return !accountCode || !accountCode.account_group || accountCode.account_group === expectedAccountGroup(itemType); }
    function updateSemiTypeBadge(line) {
        if (!el.editorSemiTypeBadge) return;
        if (!lineNeedsSemiType(line)) {
            el.editorSemiTypeBadge.style.display = 'none';
            el.editorSemiTypeBadge.textContent = '';
            el.editorSemiTypeBadge.className = 'badge text-bg-secondary';
            return;
        }
        line.semi_expendable_type = getSemiType(line.unit_cost || 0);
        el.editorSemiTypeBadge.style.display = '';
        el.editorSemiTypeBadge.textContent = line.semi_expendable_type === 'high_value' ? 'HV' : 'LV';
        el.editorSemiTypeBadge.className = line.semi_expendable_type === 'high_value' ? 'badge text-bg-danger' : 'badge text-bg-info';
    }
    function upsertCatalogItem(item) {
        if (!item || !item.id) return;
        var idx = -1;
        for (var i = 0; i < catalogItems.length; i++) {
            if (String(catalogItems[i].id) === String(item.id)) {
                idx = i;
                break;
            }
        }
        if (idx >= 0) catalogItems[idx] = item;
        else catalogItems.push(item);
    }

    function populateCatalogSelect(selectedId) {
        var sel = el.editorCatalogSearch;
        if (!sel) return;
        sel.innerHTML = '<option value="">-- Search catalog --</option>';
        catalogItems.forEach(function(ci) {
            var opt = document.createElement('option');
            opt.value = ci.id;
            opt.setAttribute('data-item', JSON.stringify(ci));
            opt.textContent = (ci.stock_no || 'NO-STOCK-NO') + ' - ' + ci.item_name + ' [' + typeLabel(ci.item_type) + ']';
            if (String(ci.id) === String(selectedId)) opt.selected = true;
            sel.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(sel);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Search stock no. or item name...', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) });
        }
    }

    function rebuildAccountCodeSelect(itemType, selectedId) {
        var sel = el.editorAccountCode; if (!sel) return; sel.innerHTML = '<option value="">Select account code</option>';
        accountCodes.forEach(function(ac){ if (!accountCodeMatchesType(ac, itemType)) return; var opt = document.createElement('option'); opt.value = ac.id; opt.textContent = ac.account_code + ' - ' + ac.account_name; if (String(ac.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(sel);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({
                placeholder: 'Select account code',
                allowClear: true,
                width: '100%',
                dropdownParent: window.jQuery(document.body)
            });
        }
    }

    function rebuildClassificationSelect(itemType, selectedId) {
        var sel = el.editorClassification;
        if (!sel) return;
        sel.innerHTML = '<option value="">Select inventory class</option>';
        classifications.forEach(function(cl){
            if (!classificationMatchesType(cl, itemType)) return;
            var opt = document.createElement('option');
            opt.value = cl.id;
            opt.textContent = cl.classification_name;
            opt.setAttribute('data-item-type', cl.classification_group === 'asset' ? 'equipment' : (cl.classification_group || ''));
            if (String(cl.id) === String(selectedId)) opt.selected = true;
            sel.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(sel);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Select inventory class', allowClear: true, width: '100%', dropdownParent: window.jQuery(document.body) });
        }
    }

    function rebuildUomSelect(selectedId) {
        var sel = el.editorUom; if (!sel) return; sel.innerHTML = '<option value="">Select unit</option>';
        units.forEach(function(u){ var opt = document.createElement('option'); opt.value = u.id; opt.textContent = u.uom_name + ' (' + u.abbreviation + ')'; if (String(u.id) === String(selectedId)) opt.selected = true; sel.appendChild(opt); });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(sel);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({
                placeholder: 'Select unit',
                allowClear: true,
                width: '100%',
                dropdownParent: window.jQuery(document.body)
            });
        }
    }

    function escapeHtml(s) { return String(s || '').replace(/[&<>\"]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;'}[c];}); }

    function renderLineList() {
        var container = el.lineListScroll; if (!container) return; container.innerHTML = '';
        var done = 0; var total = poLines.length; var sum = 0;
        for (var i = 0; i < poLines.length; i++) {
            var ln = poLines[i];
            ln.semi_expendable_type = lineNeedsSemiType(ln) ? getSemiType(ln.unit_cost || 0) : '';
            ln.is_complete = lineIsComplete(ln); if (ln.is_complete) done++; sum += parseFloat(ln.line_total || 0);
            if (currentFilter === 'done' && !ln.is_complete) continue; if (currentFilter === 'empty' && ln.is_complete) continue; if (searchTerm && !(ln.item_description || '').toLowerCase().includes(searchTerm)) continue;
            var dotColor = (i === activeIndex) ? '#0d6efd' : (ln.is_complete ? '#198754' : '#adb5bd');
            var badgeClass = (ln.item_type === 'equipment') ? 'text-bg-warning-subtle' : (ln.item_type === 'semi_expendable' ? 'text-bg-primary-subtle' : 'text-bg-success-subtle');
            var shortType = typeShortLabel(ln.item_type); var desc = (ln.item_description || 'New item'); var amt = (parseFloat(ln.line_total || 0) !== 0) ? formatNumber(ln.line_total) : '—';
            var row = document.createElement('div'); row.className = 'po-line-list-item'; row.setAttribute('data-index', i);
            row.style.cssText = 'display:flex; align-items:center; gap:6px; padding:6px 8px; border-radius:6px; cursor:pointer; font-size:12px; border:0.5px solid transparent;';
            row.innerHTML = '<span style="width:20px; text-align:center; color:var(--bs-body-color); opacity:0.5; font-size:11px;">' + (i+1) + '</span>' +
                '<span class="po-line-status-dot" style="width:8px; height:8px; border-radius:50%; flex-shrink:0; background:' + dotColor + ';"></span>' +
                '<span class="badge ' + badgeClass + '" style="font-size:9px; padding:1px 5px; flex-shrink:0;">' + shortType + '</span>' +
                '<span style="flex:1; overflow:hidden; white-space:nowrap; text-overflow:ellipsis; margin-left:6px; color:var(--bs-body-color);">' + escapeHtml(desc) + '</span>' +
                '<span style="font-size:11px; color:var(--bs-body-color); opacity:0.65; flex-shrink:0; margin-left:8px;">' + amt + '</span>';
            (function(index){ row.addEventListener('click', function(){ loadLineEditor(index); }); })(i);
            if (i === activeIndex) { row.style.background = 'var(--bs-primary-bg-subtle)'; row.style.borderColor = 'var(--bs-primary-border-subtle)'; }
            container.appendChild(row);
        }
        el.lineCompletedCount && (el.lineCompletedCount.textContent = done + ' / ' + total);
        el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(sum));
        el.footerLineCount && (el.footerLineCount.textContent = total + ' line(s)');
        el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(sum));
    }

    function loadLineEditor(index) {
        if (poLines.length === 0) { el.editorEmpty.style.display = ''; el.editorContent.style.display = 'none'; activeIndex = -1; renderLineList(); return; }
        activeIndex = index; var line = poLines[index]; el.editorEmpty.style.display = 'none'; el.editorContent.style.display = '';
        el.editorLineLabel.textContent = 'Line ' + (index + 1); el.editorTypeBadge.className = 'badge ' + typeBadgeClass(line.item_type); el.editorTypeBadge.textContent = typeLabel(line.item_type);
        updateSemiTypeBadge(line);
        el.editorLineCounter.textContent = (index + 1) + ' of ' + poLines.length;
        populateCatalogSelect(line.stock_catalog_id || '');
        rebuildAccountCodeSelect(line.item_type, line.account_code_id);
        rebuildClassificationSelect(line.item_type, line.classification_id);
        rebuildUomSelect(line.unit_of_measure_id);
        el.editorDescription.value = line.item_description || ''; el.editorQty.value = line.quantity || '1'; el.editorUnitCost.value = line.unit_cost || '0.00'; el.editorAmount.textContent = formatNumber(line.line_total || 0);
        el.editorPrev.disabled = (index === 0); el.editorNext.disabled = (index === poLines.length - 1);
        var done = poLines.filter(lineIsComplete).length; var pct = poLines.length ? Math.round((done / poLines.length) * 100) : 0;
        el.editorProgress.style.width = pct + '%'; el.editorProgressLabel.textContent = done + ' / ' + poLines.length + ' completed';
        renderLineList();
        if (window.SPAMS && typeof window.SPAMS.initSelect2 === 'function') window.SPAMS.initSelect2(document.getElementById('poLineEditor'));
        if (window.SPAMS && typeof window.SPAMS.refreshSelect2 === 'function') {
            window.SPAMS.refreshSelect2(document.getElementById('editorAccountCode'));
            window.SPAMS.refreshSelect2(document.getElementById('editorClassification'));
            window.SPAMS.refreshSelect2(document.getElementById('editorUom'));
        }
    }

    function saveCurrentLine() {
        if (activeIndex < 0 || activeIndex >= poLines.length) return;
        var ln = poLines[activeIndex];
        ln.stock_catalog_id = el.editorCatalogSearch ? (el.editorCatalogSearch.value || '') : '';
        ln.account_code_id = el.editorAccountCode.value || '';

        // If account code changed, clear classification if it no longer matches
        var currentClassOpt = el.editorClassification
            ? el.editorClassification.options[el.editorClassification.selectedIndex]
            : null;
        var currentClassType = currentClassOpt
            ? currentClassOpt.getAttribute('data-item-type') : '';
        if (currentClassType && currentClassType !== ln.item_type) {
            el.editorClassification.value = '';
        }
        ln.classification_id = el.editorClassification
            ? (el.editorClassification.value || '') : '';

        ln.item_description = (el.editorDescription.value || '').trim();
        ln.quantity = el.editorQty.value || '0';
        ln.unit_of_measure_id = el.editorUom.value || '';
        ln.unit_cost = el.editorUnitCost.value || '0';
        ln.semi_expendable_type = lineNeedsSemiType(ln) ? getSemiType(ln.unit_cost) : '';
        ln.line_total = Math.round((parseFloat(ln.quantity || 0) * parseFloat(ln.unit_cost || 0)) * 100) / 100;
        ln.is_complete = lineIsComplete(ln);
        el.editorAmount.textContent = formatNumber(ln.line_total || 0);
        updateSemiTypeBadge(ln);
        renderLineList();
        updateGrandTotal();
    }

    function updateEditorAmount() { var q = parseFloat(el.editorQty.value || 0) || 0; var c = parseFloat(el.editorUnitCost.value || 0) || 0; el.editorAmount.textContent = formatNumber(Math.round(q * c * 100) / 100); }

    function deleteLine(idx) { if (poLines.length <= 1) { alert('At least one line is required.'); return; } poLines.splice(idx,1); poLines.forEach(function(l,i){ l.index = i; }); var nextIndex = Math.min(idx, poLines.length-1); renderLineList(); loadLineEditor(nextIndex); }

    function buildHiddenInputs() { var container = el.poHiddenInputs; if (!container) return; container.innerHTML = ''; poLines.forEach(function(ln,i){ var fields = { item_type: ln.item_type, semi_expendable_type: ln.semi_expendable_type, stock_catalog_id: ln.stock_catalog_id, account_code_id: ln.account_code_id, classification_id: ln.classification_id, item_description: ln.item_description, quantity: ln.quantity, unit_of_measure_id: ln.unit_of_measure_id, unit_cost: ln.unit_cost }; Object.keys(fields).forEach(function(k){ var inp = document.createElement('input'); inp.type='hidden'; inp.name='items['+i+']['+k+']'; inp.value = fields[k] || ''; container.appendChild(inp); }); }); }

    function addLine(itemType) {
        var validTypes = ['supply', 'semi_expendable', 'equipment'];
        if (validTypes.indexOf(itemType) === -1) itemType = 'supply';
        poLines.push({
            index:              poLines.length,
            item_type:          itemType,
            semi_expendable_type: itemType === 'semi_expendable' ? 'low_value' : '',
            stock_catalog_id:   '',
            account_code_id:    '',
            classification_id:  '',
            item_description:   '',
            quantity:           '1',
            unit_of_measure_id: '',
            unit_cost:          '0.00',
            line_total:         0,
            is_complete:        false
        });
        renderLineList();
        loadLineEditor(poLines.length - 1);
    }

    function updateGrandTotal() { var total = poLines.reduce(function(acc,ln){ return acc + (parseFloat(ln.line_total||0)); },0); el.poGrandTotal && (el.poGrandTotal.textContent = formatNumber(total)); el.lineTotalSoFar && (el.lineTotalSoFar.textContent = formatNumber(total)); }

    // events
    Array.from(document.querySelectorAll('.add-line-btn')).forEach(function(b){ b.addEventListener('click', function(){ addLine(b.dataset.type || 'supply'); }); });
    el.lineSearchInput && el.lineSearchInput.addEventListener('input', function(){ searchTerm = (this.value||'').trim().toLowerCase(); renderLineList(); });
    Array.from(document.querySelectorAll('.po-filter-btn')).forEach(function(b){ b.addEventListener('click', function(){ document.querySelectorAll('.po-filter-btn').forEach(function(bb){ bb.classList.remove('active'); }); b.classList.add('active'); currentFilter = b.dataset.filter || 'all'; renderLineList(); }); });

    el.editorPrev && el.editorPrev.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex>0) loadLineEditor(activeIndex-1); });
    el.editorNext && el.editorNext.addEventListener('click', function(){ saveCurrentLine(); if (activeIndex < poLines.length-1) loadLineEditor(activeIndex+1); });
    
    el.editorDeleteLine && el.editorDeleteLine.addEventListener('click', function(){ if (activeIndex>=0) deleteLine(activeIndex); });

        ['editorCatalogSearch','editorAccountCode','editorClassification','editorDescription','editorQty','editorUom','editorUnitCost'].forEach(function(id){ var node = document.getElementById(id); if (!node) return; node.addEventListener('change', saveCurrentLine); node.addEventListener('input', function(){ if (id==='editorQty' || id==='editorUnitCost') updateEditorAmount(); saveCurrentLine(); }); });

    if (window.jQuery) {
                window.jQuery(document)
                    .on('select2:select select2:clear',
                            '#editorCatalogSearch, #editorAccountCode, #editorClassification, #editorUom',
                            function() {
                                updateEditorAmount();
                                saveCurrentLine();
                            }
                    );
        }

    if (el.editorCatalogSearch) {
        el.editorCatalogSearch.addEventListener('change', function() {
            if (activeIndex < 0 || activeIndex >= poLines.length) return;
            var opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) {
                if (el.editorCatalogHint) el.editorCatalogHint.style.display = 'none';
                poLines[activeIndex].stock_catalog_id = '';
                saveCurrentLine();
                return;
            }
            var ci = {};
            try {
                ci = JSON.parse(opt.getAttribute('data-item') || '{}');
            } catch (e) {
                ci = {};
            }
            if (!ci.id) return;

            if (el.editorDescription) {
                el.editorDescription.value = ci.item_description || ci.item_name || '';
            }

            poLines[activeIndex].stock_catalog_id = String(ci.id);
            poLines[activeIndex].item_type = ci.item_type || poLines[activeIndex].item_type;
            poLines[activeIndex].account_code_id = ci.account_code_id || '';
            poLines[activeIndex].classification_id = ci.classification_id || '';
            poLines[activeIndex].unit_of_measure_id = ci.unit_of_measure_id || '';

            rebuildAccountCodeSelect(poLines[activeIndex].item_type, ci.account_code_id || '');
            rebuildClassificationSelect(poLines[activeIndex].item_type, ci.classification_id || '');
            rebuildUomSelect(ci.unit_of_measure_id || '');

            if (el.editorTypeBadge) {
                el.editorTypeBadge.className = 'badge ' + typeBadgeClass(poLines[activeIndex].item_type);
                el.editorTypeBadge.textContent = typeLabel(poLines[activeIndex].item_type);
            }
            updateSemiTypeBadge(poLines[activeIndex]);
            if (el.editorCatalogHint) el.editorCatalogHint.style.display = '';
            saveCurrentLine();
        });
    }

    var form = document.getElementById('purchaseOrderForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            saveCurrentLine();
            buildHiddenInputs();

            // Check if there are any lines at all
            if (poLines.length === 0) {
                e.preventDefault();
                alert('Please add at least one PO line before saving.');
                return;
            }

            // Warn if lines have no description — but do not block
            // (PHP validation will catch missing required fields)
            var emptyLines = poLines.filter(function(ln) {
                return !ln.item_description || ln.item_description.trim() === '';
            });
            if (emptyLines.length > 0) {
                e.preventDefault();
                alert('Line ' + (emptyLines[0].index + 1) + ' has no description. Please fill in all lines before saving.');
                loadLineEditor(emptyLines[0].index);
                return;
            }

            // Allow submit — PHP handles all other validation
        });
    }

    // init state
    if (typeof poLinesFromPhp !== 'undefined' && poLinesFromPhp.length > 0) {
        poLines = poLinesFromPhp.slice();
        renderLineList(); loadLineEditor(0);
    } else if (poLines.length === 0) {
        addLine('supply');
    }

    // Initialize Select2 on static editor selects
    if (window.SPAMS && window.SPAMS.initSelect2) {
        window.SPAMS.initSelect2(document.getElementById('poLineEditor'));
    }

    function rebuildQuickAddAccountCodes(itemType, selectedId) {
        if (!el.quickAddAccountCode) return;
        el.quickAddAccountCode.innerHTML = '<option value="">Select account code</option>';
        accountCodes.forEach(function(ac) {
            if (!accountCodeMatchesType(ac, itemType)) return;
            var opt = document.createElement('option');
            opt.value = ac.id;
            opt.textContent = ac.account_code + ' - ' + ac.account_name;
            if (String(ac.id) === String(selectedId)) opt.selected = true;
            el.quickAddAccountCode.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(el.quickAddAccountCode);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Select account code', allowClear: true, width: '100%', dropdownParent: window.jQuery(el.catalogQuickAddModal) });
        }
    }

    function rebuildQuickAddClassifications(itemType, selectedId) {
        if (!el.quickAddClassification) return;
        el.quickAddClassification.innerHTML = '<option value="">Select classification</option>';
        classifications.forEach(function(cl) {
            if (!classificationMatchesType(cl, itemType)) return;
            var opt = document.createElement('option');
            opt.value = cl.id;
            opt.textContent = cl.classification_name;
            if (String(cl.id) === String(selectedId)) opt.selected = true;
            el.quickAddClassification.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(el.quickAddClassification);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Select classification', allowClear: true, width: '100%', dropdownParent: window.jQuery(el.catalogQuickAddModal) });
        }
    }

    function rebuildQuickAddUom(selectedId) {
        if (!el.quickAddUom) return;
        el.quickAddUom.innerHTML = '<option value="">Select unit</option>';
        units.forEach(function(u) {
            var opt = document.createElement('option');
            opt.value = u.id;
            opt.textContent = u.uom_name + ' (' + u.abbreviation + ')';
            if (String(u.id) === String(selectedId)) opt.selected = true;
            el.quickAddUom.appendChild(opt);
        });
        if (window.jQuery && jQuery.fn.select2) {
            var $sel = window.jQuery(el.quickAddUom);
            if ($sel.hasClass('select2-hidden-accessible')) $sel.select2('destroy');
            $sel.select2({ placeholder: 'Select unit', allowClear: true, width: '100%', dropdownParent: window.jQuery(el.catalogQuickAddModal) });
        }
    }

    function showQuickAddError(message) {
        if (!el.catalogQuickAddError) return;
        el.catalogQuickAddError.textContent = message || '';
        el.catalogQuickAddError.style.display = message ? '' : 'none';
    }

    function seedQuickAddFromLine() {
        var line = (activeIndex >= 0 && activeIndex < poLines.length) ? poLines[activeIndex] : null;
        if (el.quickAddItemName) el.quickAddItemName.value = '';
        if (el.quickAddDescription) el.quickAddDescription.value = line ? (line.item_description || '') : '';
        if (el.quickAddItemType) el.quickAddItemType.value = line ? (line.item_type || 'supply') : 'supply';
        rebuildQuickAddAccountCodes(el.quickAddItemType ? el.quickAddItemType.value : 'supply', line ? line.account_code_id : '');
        rebuildQuickAddClassifications(el.quickAddItemType ? el.quickAddItemType.value : 'supply', line ? line.classification_id : '');
        rebuildQuickAddUom(line ? line.unit_of_measure_id : '');
        showQuickAddError('');
    }

    if (el.quickAddItemType) {
        el.quickAddItemType.addEventListener('change', function() {
            rebuildQuickAddAccountCodes(this.value || 'supply', '');
            rebuildQuickAddClassifications(this.value || 'supply', '');
        });
    }

    if (el.openCatalogQuickAdd) {
        el.openCatalogQuickAdd.addEventListener('click', function() {
            seedQuickAddFromLine();
            if (quickAddModal) quickAddModal.show();
        });
    }

    if (el.saveCatalogQuickAdd) {
        el.saveCatalogQuickAdd.addEventListener('click', async function() {
            var payload = new FormData();
            payload.append('_csrf', <?php echo json_encode(csrf_token(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>);
            payload.append('item_name', el.quickAddItemName ? el.quickAddItemName.value.trim() : '');
            payload.append('item_description', el.quickAddDescription ? el.quickAddDescription.value.trim() : '');
            payload.append('item_type', el.quickAddItemType ? el.quickAddItemType.value : 'supply');
            payload.append('account_code_id', el.quickAddAccountCode ? (el.quickAddAccountCode.value || '') : '');
            payload.append('classification_id', el.quickAddClassification ? (el.quickAddClassification.value || '') : '');
            payload.append('unit_of_measure_id', el.quickAddUom ? (el.quickAddUom.value || '') : '');

            showQuickAddError('');
            el.saveCatalogQuickAdd.disabled = true;

            try {
                var response = await fetch('<?php echo base_url('modules/purchase_orders/catalog_quick_add.php'); ?>', {
                    method: 'POST',
                    body: payload
                });
                var result = await response.json();
                if (!response.ok || !result.ok) {
                    throw new Error(result.error || 'Unable to save catalog item.');
                }

                upsertCatalogItem(result.item);
                if (el.editorCatalogSearch) {
                    populateCatalogSelect(String(result.item.id));
                    if (window.jQuery && jQuery.fn.select2) {
                        window.jQuery(el.editorCatalogSearch).val(String(result.item.id)).trigger('change');
                    } else {
                        el.editorCatalogSearch.value = String(result.item.id);
                        el.editorCatalogSearch.dispatchEvent(new Event('change'));
                    }
                }

                if (quickAddModal) quickAddModal.hide();
            } catch (err) {
                showQuickAddError(err.message || 'Unable to save catalog item.');
            } finally {
                el.saveCatalogQuickAdd.disabled = false;
            }
        });
    }

    // Auto-calculate Expected Delivery Date = PO Date + Delivery Term (days)
    var poDateInput = document.getElementById('po_date');
    var deliveryTermInput = document.getElementById('delivery_term_days');
    var expectedDeliveryInput = document.getElementById('expected_delivery_date');

    function computeExpectedDate() {
        if (!expectedDeliveryInput) return;
        var pdVal = poDateInput && poDateInput.value ? poDateInput.value : '';
        var days = parseInt(deliveryTermInput && deliveryTermInput.value, 10);
        days = isNaN(days) ? 0 : days;
        if (!pdVal) { expectedDeliveryInput.value = ''; return; }
        var parts = pdVal.split('-');
        if (parts.length !== 3) { expectedDeliveryInput.value = ''; return; }
        var d = new Date(parts[0], parseInt(parts[1],10) - 1, parts[2]);
        d.setDate(d.getDate() + days);
        var yyyy = d.getFullYear();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        expectedDeliveryInput.value = yyyy + '-' + mm + '-' + dd;
    }

    if (poDateInput) poDateInput.addEventListener('change', computeExpectedDate);
    if (deliveryTermInput) deliveryTermInput.addEventListener('input', computeExpectedDate);

    // compute once at init
    computeExpectedDate();

    var supplierSelect       = document.getElementById('supplier_id');
    var supplierAddressInput = document.getElementById('supplier_address');

    function syncSupplierAddress() {
        if (!supplierSelect || !supplierAddressInput) return;
        var selectedValue = supplierSelect.value;
        var addr = '';
        Array.from(supplierSelect.options).forEach(function(opt) {
            if (opt.value === selectedValue) {
                addr = (opt.getAttribute('data-address') || '').trim();
            }
        });
        supplierAddressInput.value = addr;
        supplierAddressInput.placeholder = addr
            ? ''
            : 'No address on file — type manually';
    }

    if (supplierSelect && supplierAddressInput) {
        supplierSelect.addEventListener('change', syncSupplierAddress);
        setTimeout(function() {
            if (window.jQuery && jQuery.fn.select2) {
                window.jQuery(supplierSelect)
                    .off('select2:select select2:clear')
                    .on('select2:select select2:clear', syncSupplierAddress);
            }
            syncSupplierAddress();
        }, 400);
    }

        // ---- SCAN FEATURE ----

        var scanBtn    = document.getElementById('poScanBtn');
        var scanFile   = document.getElementById('poScanFile');
        var scanStatus = document.getElementById('poScanStatus');

        function showScanStatus(type, message) {
            if (!scanStatus) return;
            var bg = {
                loading: 'var(--bs-info-bg-subtle)',
                success: 'var(--bs-success-bg-subtle)',
                error:   'var(--bs-danger-bg-subtle)',
            };
            scanStatus.style.display    = 'block';
            scanStatus.style.background = bg[type] || bg.loading;
            scanStatus.style.color      = 'inherit';
            scanStatus.textContent      = message;
        }

        function matchSelectByText(selectId, searchText) {
            var select = document.getElementById(selectId);
            if (!select || !searchText) return false;
            var search = searchText.toLowerCase().trim();
            var bestOption = null;
            var bestScore  = 0;
            Array.from(select.options).forEach(function(opt) {
                if (!opt.value) return;
                var text  = opt.textContent.toLowerCase().trim();
                var score = 0;
                if (text === search) score = 100;
                else if (text.startsWith(search)) score = 80;
                else if (text.includes(search)) score = 60;
                else {
                    var words = search.split(/\s+/).filter(function(w) { return w.length > 2; });
                    var hits  = words.filter(function(w) { return text.includes(w); }).length;
                    score = hits > 0 ? (hits / words.length) * 50 : 0;
                }
                if (score > bestScore) { bestScore = score; bestOption = opt; }
            });
            if (bestOption && bestScore >= 40) {
                select.value = bestOption.value;
                if (window.jQuery && jQuery(select).hasClass('select2-hidden-accessible')) {
                    jQuery(select).trigger('change.select2');
                }
                select.dispatchEvent(new Event('change'));
                return true;
            }
            return false;
        }

        function matchUomByText(text) {
            if (!text) return '';
            var search = text.toLowerCase().trim();
            var match = units.find(function(u) {
                var label = (u.uom_name || '').toLowerCase();
                var abbr  = (u.abbreviation || '').toLowerCase();
                return label.includes(search) || abbr === search || search.includes(abbr) || abbr.includes(search);
            });
            return match ? String(match.id) : '';
        }

        function populateFormFromScan(data) {
            if (!data) return;
            var fields = {
                'po_number':          data.po_number,
                'po_date':            data.po_date,
                'supplier_address':   data.supplier_address,
                'place_of_delivery':  data.place_of_delivery,
                'delivery_term_days': data.delivery_term_days,
            };
            Object.keys(fields).forEach(function(id) {
                var eln  = document.getElementById(id);
                var val = fields[id];
                if (eln && val) {
                    eln.value = val;
                    eln.dispatchEvent(new Event('change'));
                    eln.dispatchEvent(new Event('input'));
                }
            });

            if (data.supplier_name)       matchSelectByText('supplier_id', data.supplier_name);
            if (data.mode_of_procurement) matchSelectByText('mode_of_procurement', data.mode_of_procurement);
            if (data.fund)                matchSelectByText('fund_id', data.fund);

            var supplierSel = document.getElementById('supplier_id');
            if (supplierSel) supplierSel.dispatchEvent(new Event('change'));

            var termField = document.getElementById('delivery_term_days');
            if (termField) termField.dispatchEvent(new Event('input'));

            if (!data.items || data.items.length === 0) return;

            poLines = [];

            data.items.forEach(function(item, i) {
                var uomId    = matchUomByText(item.unit || '');
                var qty      = parseFloat(item.quantity  || '1') || 1;
                var cost     = parseFloat(item.unit_cost || '0') || 0;
                var total    = Math.round(qty * cost * 100) / 100;
                var itemType = ['supply','semi_expendable','equipment'].includes(item.item_type_guess) ? item.item_type_guess : 'supply';
                poLines.push({
                    index:              i,
                    item_type:          itemType,
                    stock_catalog_id:   '',
                    account_code_id:    '',
                    classification_id:  '',
                    item_description:   item.item_description || '',
                    quantity:           qty.toFixed(2),
                    unit_of_measure_id: uomId,
                    unit_cost:          cost.toFixed(2),
                    line_total:         total,
                    is_complete:        false,
                });
            });

            renderLineList();
            loadLineEditor(0);

            if (window.SPAMS && window.SPAMS.refreshSelect2) {
                ['editorCatalogSearch','editorAccountCode','editorClassification','editorUom'].forEach(function(id) {
                    var elc = document.getElementById(id);
                    if (elc) window.SPAMS.refreshSelect2(elc);
                });
            }
        }

        if (scanBtn) {
            scanBtn.addEventListener('click', async function() {
                var file = scanFile && scanFile.files && scanFile.files[0];
                if (!file) { showScanStatus('error', 'Please select an image or PDF first.'); return; }
                if (file.size > 5 * 1024 * 1024) { showScanStatus('error', 'File is too large. Please use an image under 5MB.'); return; }

                showScanStatus('loading', 'Reading your PO... this may take a few seconds.');
                scanBtn.disabled = true; scanBtn.textContent = 'Reading...';

                try {
                    var formData = new FormData(); formData.append('po_image', file);
                    var response = await fetch('<?php echo BASE_URL; ?>/modules/purchase_orders/scan_proxy.php', { method: 'POST', body: formData });
                    var result = await response.json();
                    if (!response.ok) {
                        if (response.status === 429) {
                            throw new Error('AI quota exhausted. Check API billing/quotas or provide a key with quota.');
                        } else if (response.status === 404 && result.error && /not found/i.test(result.error)) {
                            throw new Error('AI model not available for this API/version. Check model configuration.');
                        } else {
                            throw new Error(result.error || result.message || 'Server error ' + response.status);
                        }
                    }
                    populateFormFromScan(result);
                    var lineCount = (result.items || []).length;
                    showScanStatus('success', 'PO read successfully — ' + lineCount + ' line(s) found. Please review all fields and select Account Codes before saving.');
                } catch (err) {
                    console.error('Scan error:', err);
                    var friendly = err.message || 'Unknown error';
                    if (/quota|insufficient_quota|RESOURCE_EXHAUSTED/i.test(friendly)) {
                        showScanStatus('error', 'Could not read the PO: AI quota exhausted. Please check your API key billing or provide a key with quota.');
                    } else if (/model not available|not available|not supported|model not found|not found/i.test(friendly)) {
                        showScanStatus('error', 'Could not read the PO: AI model unavailable. Check the configured model or API key.');
                    } else {
                        showScanStatus('error', 'Could not read the PO: ' + friendly + '. Try a clearer photo with good lighting. You can also encode manually below.');
                    }
                } finally {
                    scanBtn.disabled = false; scanBtn.textContent = 'Read PO';
                }
            });
        }

        // ---- END SCAN FEATURE ----
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
