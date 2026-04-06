<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$errors = [];
$flash = get_flash();
$successMessage = '';
$rows = [];
$offices = [];
$employees = [];
$responsibilityCodes = [];
$classifications = [];
$accountCodes = [];
$brands = [];
$models = [];
$suppliers = [];
$funds = [];
$form = [
    'property_number' => '',
    'po_number' => '',
    'item_type' => 'equipment',
    'item_description' => '',
    'classification_id' => '',
    'account_code_id' => '',
    'fund_id' => '',
    'supplier_id' => '',
    'brand_id' => '',
    'model_id' => '',
    'serial_no' => '',
    'acquisition_date' => date('Y-m-d'),
    'quantity' => '1',
    'unit_cost' => '',
    'office_id' => '',
    'employee_id' => '',
    'responsibility_code_id' => '',
    'condition_status' => 'good',
    'remarks' => '',
];

if ($db) {
    ensure_legacy_assets_fund_column($db);

    $db->query("UPDATE legacy_assets SET item_type = 'equipment' WHERE item_type IS NULL OR item_type = ''");
    $db->query("UPDATE legacy_assets SET quantity = 1 WHERE quantity IS NULL OR quantity <= 0");
    $db->query("UPDATE legacy_assets SET unit_cost = acquisition_cost WHERE unit_cost IS NULL OR unit_cost = 0");

    $res = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res instanceof mysqli_result) { $offices = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, office_id, is_unit_head, position_title, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($res instanceof mysqli_result) { $employees = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC");
    if ($res instanceof mysqli_result) { $responsibilityCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, classification_name, classification_family FROM classifications WHERE is_active = 1 ORDER BY classification_family ASC, classification_name ASC");
    if ($res instanceof mysqli_result) { $classifications = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($res instanceof mysqli_result) { $accountCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($res instanceof mysqli_result) { $funds = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($res instanceof mysqli_result) { $suppliers = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($res instanceof mysqli_result) { $brands = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC");
    if ($res instanceof mysqli_result) { $models = $res->fetch_all(MYSQLI_ASSOC); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($form as $key => $value) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }
        if ($form['item_description'] === '') { $errors[] = 'Description is required.'; }
        if (!in_array($form['item_type'], ['semi_expendable', 'equipment'], true)) { $errors[] = 'Inventory type must be semi-expendable or equipment.'; }
        if ($form['quantity'] === '' || !ctype_digit($form['quantity']) || (int) $form['quantity'] <= 0) { $errors[] = 'Quantity is required.'; }
        if ($form['unit_cost'] === '' || !is_numeric($form['unit_cost'])) { $errors[] = 'Unit cost is required.'; }
        if ($form['fund_id'] === '') { $errors[] = 'Fund is required to generate the property number.'; }
        if ($form['account_code_id'] === '') { $errors[] = 'Account code is required to generate the property number.'; }

        $fundCodeValue = '';
        foreach ($funds as $fundRow) {
            if ($form['fund_id'] !== '' && (int) $fundRow['id'] === (int) $form['fund_id']) {
                $fundCodeValue = fund_number_from_source((string) ($fundRow['fund_code'] ?? ''), (string) ($fundRow['fund_source'] ?? ''));
                if ($fundCodeValue === '') {
                    $fundCodeValue = trim((string) ($fundRow['fund_code'] ?? ''));
                }
                break;
            }
        }
        if ($form['fund_id'] !== '' && $fundCodeValue === '') {
            $errors[] = 'Selected fund is invalid.';
        }

        $accountCodeValue = '';
        foreach ($accountCodes as $accountCodeRow) {
            if ($form['account_code_id'] !== '' && (int) $accountCodeRow['id'] === (int) $form['account_code_id']) {
                $accountCodeValue = trim((string) ($accountCodeRow['account_code'] ?? ''));
                break;
            }
        }
        if ($form['account_code_id'] !== '' && $accountCodeValue === '') {
            $errors[] = 'Selected account code is invalid.';
        }

        $officeCodeValue = '';
        foreach ($offices as $officeRow) {
            if ($form['office_id'] !== '' && (int) $officeRow['id'] === (int) $form['office_id']) {
                $officeCodeValue = trim((string) ($officeRow['office_code'] ?? ''));
                break;
            }
        }

        $yearValue = date('Y');
        if ($form['acquisition_date'] !== '') {
            $timestamp = strtotime($form['acquisition_date']);
            if ($timestamp !== false) {
                $yearValue = date('Y', $timestamp);
            }
        }

        if (!$errors) {
            $form['property_number'] = generate_property_number($db, $yearValue, $fundCodeValue, $accountCodeValue, $officeCodeValue);
        }

        if (!$errors) {
            $checkStmt = $db->prepare("SELECT id FROM legacy_assets WHERE property_number = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('s', $form['property_number']);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
                if ($exists) {
                    $errors[] = 'Property number already exists in beginning balance assets.';
                }
            }
        }

        if (!$errors) {
            $systemReference = next_module_code($db, 'stock_items');
            $userId = current_user_id();
            $classificationId = $form['classification_id'] !== '' ? (int) $form['classification_id'] : null;
            $accountCodeId = $form['account_code_id'] !== '' ? (int) $form['account_code_id'] : null;
            $fundId = $form['fund_id'] !== '' ? (int) $form['fund_id'] : null;
            $supplierId = $form['supplier_id'] !== '' ? (int) $form['supplier_id'] : null;
            $brandId = $form['brand_id'] !== '' ? (int) $form['brand_id'] : null;
            $modelId = $form['model_id'] !== '' ? (int) $form['model_id'] : null;
            $officeId = $form['office_id'] !== '' ? (int) $form['office_id'] : null;
            $employeeId = $form['employee_id'] !== '' ? (int) $form['employee_id'] : null;
            $rcId = $form['responsibility_code_id'] !== '' ? (int) $form['responsibility_code_id'] : null;
            $quantity = (int) $form['quantity'];
            $unitCost = (float) $form['unit_cost'];
            $acquisitionCost = round($quantity * $unitCost, 2);
            $brandName = '';
            $modelName = '';
            foreach ($brands as $brandRow) {
                if ((int) $brandRow['id'] === (int) $brandId) { $brandName = (string) $brandRow['brand_name']; break; }
            }
            foreach ($models as $modelRow) {
                if ((int) $modelRow['id'] === (int) $modelId) { $modelName = (string) $modelRow['model_name']; break; }
            }

                $stmt = $db->prepare("
                    INSERT INTO legacy_assets
                        (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt) {
                    $stmt->bind_param(
                    'sssssiiiiiissssiddiiissi',
                    $systemReference,
                    $form['po_number'],
                    $form['property_number'],
                    $form['item_type'],
                    $form['item_description'],
                    $classificationId,
                    $accountCodeId,
                    $fundId,
                    $supplierId,
                    $brandId,
                    $modelId,
                    $brandName,
                    $modelName,
                    $form['serial_no'],
                    $form['acquisition_date'],
                    $quantity,
                    $unitCost,
                    $acquisitionCost,
                    $officeId,
                    $employeeId,
                    $rcId,
                    $form['condition_status'],
                    $form['remarks'],
                    $userId
                );
                $stmt->execute();
                $legacyAssetId = (int) $stmt->insert_id;
                $stmt->close();

                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'legacy_assets',
                    'record_id' => $legacyAssetId,
                    'module_name' => 'property',
                    'record_type' => 'legacy_asset',
                    'action_name' => 'create_legacy_asset',
                    'new_values' => [
                        'system_reference' => $systemReference,
                        'property_number' => $form['property_number'],
                        'item_type' => $form['item_type'],
                        'item_description' => $form['item_description'],
                        'fund_id' => $fundId,
                        'office_id' => $officeId,
                        'employee_id' => $employeeId,
                        'responsibility_code_id' => $rcId,
                    ],
                    'description' => 'Created beginning balance asset.',
                ]);
                set_flash('success', 'Beginning balance asset recorded successfully.');
                redirect('modules/property/legacy_assets.php');
            } else {
                $errors[] = 'Unable to save the beginning balance asset.';
            }
        }
    }

    $listStmt = $db->prepare("
        SELECT la.*, c.classification_name, c.classification_family, ac.account_code, ac.account_name, f.fund_code, f.fund_name, f.fund_source, o.office_name,
               s.supplier_name, b.brand_name, m.model_name,
               e.first_name, e.middle_name, e.last_name, e.suffix_name, rc.code AS rc_code
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN suppliers s ON s.id = la.supplier_id
        LEFT JOIN brands b ON b.id = la.brand_id
        LEFT JOIN models m ON m.id = la.model_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
        WHERE la.is_active = 1
        ORDER BY la.created_at DESC, la.id DESC
    ");
    if ($listStmt) {
        $listStmt->execute();
        $rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
    }

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $filename = 'legacy_assets_export_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');
        if ($output !== false) {
            fputcsv($output, [
                'Legacy ID',
                'System Reference',
                'Property Number',
                'PO Number',
                'Item Type',
                'Description',
                'Classification',
                'Classification Family',
                'Account Code',
                'Account Name',
                'Fund',
                'Supplier',
                'Brand',
                'Model',
                'Serial No',
                'Acquisition Date',
                'Quantity',
                'Unit Cost',
                'Acquisition Cost',
                'Office',
                'Employee',
                'Responsibility Code',
                'Condition Status',
                'Remarks',
                'Created At',
            ]);

            foreach ($rows as $row) {
                $employeeName = trim(implode(' ', array_filter([
                    trim((string) ($row['first_name'] ?? '')),
                    trim((string) ($row['middle_name'] ?? '')),
                    trim((string) ($row['last_name'] ?? '')),
                    trim((string) ($row['suffix_name'] ?? '')),
                ])));

                fputcsv($output, [
                    $row['id'] ?? '',
                    $row['system_reference'] ?? '',
                    $row['property_number'] ?? '',
                    $row['po_number'] ?? '',
                    $row['item_type'] ?? '',
                    preg_replace('/\s+/', ' ', (string) ($row['item_description'] ?? '')),
                    $row['classification_name'] ?? '',
                    $row['classification_family'] ?? '',
                    $row['account_code'] ?? '',
                    $row['account_name'] ?? '',
                    trim(implode(' - ', array_filter([
                        trim((string) ($row['fund_code'] ?? '')),
                        trim((string) ($row['fund_name'] ?? '')),
                    ]))),
                    $row['supplier_name'] ?? '',
                    $row['brand'] ?? ($row['brand_name'] ?? ''),
                    $row['model'] ?? ($row['model_name'] ?? ''),
                    $row['serial_no'] ?? '',
                    $row['acquisition_date'] ?? '',
                    $row['quantity'] ?? '',
                    $row['unit_cost'] ?? '',
                    $row['acquisition_cost'] ?? '',
                    $row['office_name'] ?? '',
                    $employeeName,
                    $row['rc_code'] ?? '',
                    $row['condition_status'] ?? '',
                    preg_replace('/\s+/', ' ', (string) ($row['remarks'] ?? '')),
                    $row['created_at'] ?? '',
                ]);
            }

            fclose($output);
        }
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <h5 class="card-title mb-0">Beginning Balance Assets</h5>
                        <div class="small text-muted">Encode existing equipment already owned by the university without recreating old PO and receiving records.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?php echo h(base_url('modules/property/legacy_assets.php?export=csv')); ?>" class="btn btn-outline-success btn-sm">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if ($flash): ?>
                    <div class="alert alert-success"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Property Number</label>
                            <input type="text" class="form-control" name="property_number" value="<?php echo h($form['property_number']); ?>" readonly>
                            <div class="form-text">Auto-generated on save from acquisition year, account code, and responsibility code.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Inventory Type</label>
                            <select name="item_type" class="form-select">
                                <option value="equipment" <?php echo $form['item_type'] === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                <option value="semi_expendable" <?php echo $form['item_type'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">PO Number</label>
                            <input type="text" class="form-control" name="po_number" value="<?php echo h($form['po_number']); ?>" placeholder="Enter PO number if available">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Acquisition Date</label>
                            <input type="date" class="form-control" name="acquisition_date" value="<?php echo h($form['acquisition_date']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity</label>
                            <input type="number" min="1" step="1" class="form-control" name="quantity" value="<?php echo h($form['quantity']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" step="0.01" class="form-control" name="unit_cost" value="<?php echo h($form['unit_cost']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Fund</label>
                            <select name="fund_id" class="form-select">
                                <option value="">Select fund</option>
                                <?php foreach ($funds as $fund): ?>
                                    <option value="<?php echo (int) $fund['id']; ?>" <?php echo $form['fund_id'] === (string) $fund['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(($fund['fund_code'] ?? '') . ' - ' . ($fund['fund_name'] ?? '') . (($fund['fund_source'] ?? '') !== '' ? ' - ' . ($fund['fund_source'] ?? '') : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Select supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo (int) $supplier['id']; ?>" <?php echo $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : ''; ?>><?php echo h($supplier['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Equipment Description</label>
                            <textarea class="form-control" name="item_description" rows="3" required><?php echo h($form['item_description']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Item Classification</label>
                            <div class="input-group">
                            <select name="classification_id" class="form-select" id="classification_id">
                                <option value="">Select classification</option>
                                <?php foreach ($classifications as $classification): ?>
                                    <option value="<?php echo (int) $classification['id']; ?>" <?php echo $form['classification_id'] === (string) $classification['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(trim(($classification['classification_family'] ?? '') . ' / ' . ($classification['classification_name'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new classification" data-qa-modal="qaClassificationModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Code</label>
                            <div class="input-group">
                            <select name="account_code_id" class="form-select" id="account_code_id">
                                <option value="">Select account code</option>
                                <?php foreach ($accountCodes as $accountCode): ?>
                                    <option value="<?php echo (int) $accountCode['id']; ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?>>
                                        <?php echo h(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new account code" data-qa-modal="qaAccountCodeModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Brand</label>
                            <div class="input-group">
                            <select name="brand_id" class="form-select" id="brand_id" data-no-select2 data-placeholder="Select brand">
                                <option value="">Select brand</option>
                                <?php foreach ($brands as $brand): ?>
                                    <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new brand" data-qa-modal="qaBrandModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model</label>
                            <div class="input-group">
                            <select name="model_id" class="form-select" id="model_id" data-no-select2 data-placeholder="Select model">
                                <option value="">Select model</option>
                                <?php foreach ($models as $model): ?>
                                    <option value="<?php echo (int) $model['id']; ?>" <?php echo $form['model_id'] === (string) $model['id'] ? 'selected' : ''; ?> data-brand-id="<?php echo (int) ($model['brand_id'] ?? 0); ?>"><?php echo h($model['model_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new model" data-qa-modal="qaModelModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Serial No.</label>
                            <input type="text" class="form-control" name="serial_no" value="<?php echo h($form['serial_no']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Office</label>
                            <div class="input-group">
                            <select name="office_id" class="form-select" id="office_id" data-placeholder="Select office">
                                <option value="">Select office</option>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new office" data-qa-modal="qaOfficeModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Accountable Employee</label>
                            <div class="input-group">
                            <select name="employee_id" class="form-select" id="employee_id" data-placeholder="Select employee">
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?> data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>">
                                        <?php echo h(employee_display_name($employee)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="btn btn-outline-secondary" title="Add new employee" data-qa-modal="qaEmployeeModal">+</button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Responsibility Code</label>
                            <select name="responsibility_code_id" class="form-select" data-placeholder="Select RC">
                                <option value="">Select RC</option>
                                <?php foreach ($responsibilityCodes as $rc): ?>
                                    <option value="<?php echo (int) $rc['id']; ?>" <?php echo $form['responsibility_code_id'] === (string) $rc['id'] ? 'selected' : ''; ?> data-office-id="<?php echo (int) ($rc['office_id'] ?? 0); ?>">
                                        <?php echo h(($rc['code'] ?? '') . ' - ' . ($rc['description'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Condition</label>
                            <select name="condition_status" class="form-select">
                                <?php foreach (['good' => 'Good', 'serviceable' => 'Serviceable', 'repair_needed' => 'Needs Repair', 'unserviceable' => 'Unserviceable'] as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>" <?php echo $form['condition_status'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Remarks</label>
                            <input type="text" class="form-control" name="remarks" value="<?php echo h($form['remarks']); ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">Save Beginning Balance Asset</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Encoded Beginning Balance Assets</h5>
                <span class="small text-muted"><?php echo count($rows); ?> record(s)</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Property No.</th>
                                <th>PO No.</th>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Fund</th>
                                <th>Supplier</th>
                                <th>Brand / Model</th>
                                <th>Office</th>
                                <th>Accountable</th>
                                <th>Acquired</th>
                                <th>Qty</th>
                                <th>Unit Cost</th>
                                <th>Cost</th>
                                <th>Condition</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo h($row['property_number']); ?></td>
                                    <td><?php echo h($row['po_number'] ?? ''); ?></td>
                                    <td><?php echo h($row['item_description']); ?></td>
                                    <td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['item_type'] ?? '')))); ?></td>
                                    <td><?php echo h(trim(implode(' - ', array_filter([$row['fund_code'] ?? '', $row['fund_name'] ?? ''])))); ?></td>
                                    <td><?php echo h($row['supplier_name'] ?? ''); ?></td>
                                    <td><?php echo h(trim((($row['brand_name'] ?? '') ?: ($row['brand'] ?? '')) . ' ' . (($row['model_name'] ?? '') ?: ($row['model'] ?? '')))); ?></td>
                                    <td><?php echo h($row['office_name'] ?? ''); ?></td>
                                    <td><?php echo h(employee_display_name($row)); ?></td>
                                    <td><?php echo h(!empty($row['acquisition_date']) ? date('M d, Y', strtotime($row['acquisition_date'])) : ''); ?></td>
                                    <td><?php echo h(number_format((float) ($row['quantity'] ?? 0), 0)); ?></td>
                                    <td><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                    <td><?php echo h(number_format((float) ($row['acquisition_cost'] ?? 0), 2)); ?></td>
                                    <td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['condition_status'] ?? '')))); ?></td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr><td colspan="14" class="text-center text-muted py-4">No beginning balance assets recorded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
(function() {
    function initLocalSelect2(select) {
        if (!select || !window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            return;
        }
        var $select = jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }
        $select.select2({
            width: '100%',
            placeholder: select.getAttribute('data-placeholder') || 'Select an option',
            allowClear: true,
            dropdownParent: jQuery(select.parentElement || document.body)
        });
    }

    var modelOptions = <?php
        $modelDataset = array_map(static function ($model) {
            return [
                'value' => (string) ($model['id'] ?? ''),
                'text' => (string) ($model['model_name'] ?? ''),
                'brandId' => (string) ($model['brand_id'] ?? ''),
            ];
        }, $models);
        echo json_encode($modelDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    function setupBrandModelFilter() {
        var brandSelect = document.querySelector('select[name="brand_id"]');
        var modelSelect = document.querySelector('select[name="model_id"]');
        if (!brandSelect || !modelSelect) {
            return;
        }

        if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            window.setTimeout(setupBrandModelFilter, 150);
            return;
        }

        if (brandSelect.getAttribute('data-brand-model-wired') === '1') {
            return;
        }

        brandSelect.setAttribute('data-brand-model-wired', '1');

        function filterModels() {
        var brandId = brandSelect.value || '';
        var previousValue = modelSelect.value || '';
        modelSelect.innerHTML = '';

        modelSelect.add(new Option('Select model', '', false, false));
        modelOptions.forEach(function(optionData) {
            if (brandId !== '' && optionData.brandId !== '' && optionData.brandId !== brandId) {
                return;
            }
            var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
            option.setAttribute('data-brand-id', optionData.brandId);
            modelSelect.add(option);
        });

        if (previousValue !== '' && !Array.from(modelSelect.options).some(function(option) { return option.value === previousValue; })) {
            modelSelect.value = '';
        }

        initLocalSelect2(modelSelect);
        }

        initLocalSelect2(brandSelect);
        filterModels();

        jQuery(brandSelect).off('change.legacyBrandFilter').on('change.legacyBrandFilter', filterModels);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupBrandModelFilter);
    } else {
        setupBrandModelFilter();
    }

    function setupOfficeEmployeeFilter() {
        var officeSelect = document.querySelector('select[name="office_id"]');
        var employeeSelect = document.querySelector('select[name="employee_id"]');
        var rcSelect = document.querySelector('select[name="responsibility_code_id"]');
        if (!officeSelect || !employeeSelect) {
            return;
        }

        if (!window.jQuery || !jQuery.fn || !jQuery.fn.select2) {
            window.setTimeout(setupOfficeEmployeeFilter, 150);
            return;
        }

        if (officeSelect.getAttribute('data-office-employee-wired') === '1') {
            return;
        }

        officeSelect.setAttribute('data-office-employee-wired', '1');

        function refreshSharedSelect(select) {
            if (window.SPAMS && window.SPAMS.refreshSelect2) {
                window.SPAMS.refreshSelect2(select);
            }
        }

        function filterResponsibilityCodes() {
            if (!rcSelect) {
                return;
            }
            var officeId = officeSelect.value || '';
            var preferredRcId = '';
            Array.prototype.forEach.call(rcSelect.options, function(option) {
                if (!option.value) {
                    option.hidden = false;
                    return;
                }
                var optionOfficeId = option.getAttribute('data-office-id') || '';
                var matches = !officeId || optionOfficeId === officeId;
                option.hidden = !matches;
                if (matches && preferredRcId === '') {
                    preferredRcId = option.value;
                }
                if (!matches && option.selected) {
                    rcSelect.value = '';
                }
            });

            if (officeId !== '' && (!rcSelect.value || (rcSelect.selectedOptions.length && rcSelect.selectedOptions[0].hidden)) && preferredRcId !== '') {
                rcSelect.value = preferredRcId;
            }

            refreshSharedSelect(rcSelect);
        }

        function filterEmployees() {
            var officeId = officeSelect.value || '';
            var preferredEmployeeId = '';
            Array.prototype.forEach.call(employeeSelect.options, function(option) {
                if (!option.value) {
                    option.hidden = false;
                    return;
                }
                var optionOfficeId = option.getAttribute('data-office-id') || '';
                var matches = !officeId || optionOfficeId === officeId;
                option.hidden = !matches;
                if (matches && option.getAttribute('data-is-unit-head') === '1' && preferredEmployeeId === '') {
                    preferredEmployeeId = option.value;
                }
                if (!matches && option.selected) {
                    employeeSelect.value = '';
                }
            });

            if (officeId !== '' && (!employeeSelect.value || (employeeSelect.selectedOptions.length && employeeSelect.selectedOptions[0].hidden)) && preferredEmployeeId !== '') {
                employeeSelect.value = preferredEmployeeId;
            }
            refreshSharedSelect(employeeSelect);
            filterResponsibilityCodes();
        }

        refreshSharedSelect(officeSelect);
        filterEmployees();
        officeSelect.addEventListener('change', filterEmployees);
        if (window.jQuery) {
            jQuery(officeSelect).off('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter').on('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter', filterEmployees);
            jQuery(employeeSelect).off('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter').on('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter', syncOfficeFromEmployee);
        }

        function syncOfficeFromEmployee() {
            var selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                return;
            }
            var selectedOfficeId = selectedOption.getAttribute('data-office-id') || '';
            if (!selectedOfficeId) {
                return;
            }
            if (officeSelect.value !== selectedOfficeId) {
                officeSelect.value = selectedOfficeId;
                refreshSharedSelect(officeSelect);
                filterEmployees();
                employeeSelect.value = selectedOption.value;
                refreshSharedSelect(employeeSelect);
            }
        }

        employeeSelect.addEventListener('change', syncOfficeFromEmployee);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupOfficeEmployeeFilter);
    } else {
        setupOfficeEmployeeFilter();
    }
})();
</script>

<!-- Quick-Add Modals -->

<!-- Classification Modal -->
<div class="modal fade" id="qaClassificationModal" tabindex="-1" aria-labelledby="qaClassificationModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaClassificationModalLabel">Add Classification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaClassificationError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Classification Family <small class="text-muted">(optional)</small></label>
          <input type="text" class="form-control" id="qa_classification_family" placeholder="e.g. Furniture and Fixtures">
        </div>
        <div class="mb-3">
          <label class="form-label">Classification Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_classification_name" placeholder="e.g. Office Chair">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaClassificationSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Account Code Modal -->
<div class="modal fade" id="qaAccountCodeModal" tabindex="-1" aria-labelledby="qaAccountCodeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaAccountCodeModalLabel">Add Account Code</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaAccountCodeError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Account Code <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_account_code" placeholder="e.g. 10605010-00">
        </div>
        <div class="mb-3">
          <label class="form-label">Account Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_account_name" placeholder="e.g. Office Equipment">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaAccountCodeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Brand Modal -->
<div class="modal fade" id="qaBrandModal" tabindex="-1" aria-labelledby="qaBrandModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaBrandModalLabel">Add Brand</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaBrandError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Brand Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_brand_name" placeholder="e.g. Samsung">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaBrandSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Model Modal -->
<div class="modal fade" id="qaModelModal" tabindex="-1" aria-labelledby="qaModelModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaModelModalLabel">Add Model</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaModelError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <p class="mb-1 text-muted small">Brand: <strong id="qaModelBrandLabel">—</strong></p>
          <input type="hidden" id="qa_model_brand_id">
        </div>
        <div class="mb-3">
          <label class="form-label">Model Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_model_name" placeholder="e.g. Galaxy S24">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaModelSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Office Modal -->
<div class="modal fade" id="qaOfficeModal" tabindex="-1" aria-labelledby="qaOfficeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaOfficeModalLabel">Add Office</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaOfficeError" class="alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Office Code <span class="text-danger">*</span></label>
          <input type="text" class="form-control text-uppercase" id="qa_office_code" placeholder="e.g. HRMO">
        </div>
        <div class="mb-3">
          <label class="form-label">Office Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_office_name" placeholder="e.g. Human Resource Management Office">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaOfficeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Employee Modal -->
<div class="modal fade" id="qaEmployeeModal" tabindex="-1" aria-labelledby="qaEmployeeModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="qaEmployeeModalLabel">Add Employee</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="qaEmployeeError" class="alert alert-danger d-none"></div>
        <div class="row g-2 mb-3">
          <div class="col-5">
            <label class="form-label">First Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="qa_emp_first_name">
          </div>
          <div class="col-3">
            <label class="form-label">Middle Name</label>
            <input type="text" class="form-control" id="qa_emp_middle_name">
          </div>
          <div class="col-4">
            <label class="form-label">Last Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="qa_emp_last_name">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Position Title <small class="text-muted">(optional)</small></label>
          <input type="text" class="form-control" id="qa_emp_position_title">
        </div>
        <div class="mb-3">
          <label class="form-label">Office <small class="text-muted">(optional)</small></label>
          <select class="form-select" id="qa_emp_office_id">
            <option value="">— Select Office —</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="qaEmployeeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var qaEndpoint = <?php echo json_encode(base_url('modules/property/legacy_assets_quickadd.php')); ?>;

    /* Open modals via data-qa-modal attribute */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-qa-modal]');
        if (!btn) return;
        e.preventDefault();
        var modalId = btn.getAttribute('data-qa-modal');
        var modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    function getCsrf() {
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function showError(elId, msg) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.textContent = msg;
        el.classList.remove('d-none');
    }

    function clearError(elId) {
        var el = document.getElementById(elId);
        if (!el) return;
        el.textContent = '';
        el.classList.add('d-none');
    }

    function appendOption(selectId, id, label, extraData) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        var opt = document.createElement('option');
        opt.value = id;
        opt.textContent = label;
        if (extraData) {
            Object.keys(extraData).forEach(function (k) {
                opt.setAttribute('data-' + k.replace(/_/g, '-'), extraData[k]);
            });
        }
        sel.appendChild(opt);
        sel.value = id;
    }

    function postQA(payload, onSuccess, onError) {
        payload['_csrf'] = getCsrf();
        fetch(qaEndpoint, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(payload).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                onSuccess(data);
            } else {
                onError(data.error || 'An error occurred.');
            }
        })
        .catch(function () { onError('Network error. Please try again.'); });
    }

    /* ── Classification ── */
    document.getElementById('qaClassificationModal').addEventListener('show.bs.modal', function () {
        clearError('qaClassificationError');
        document.getElementById('qa_classification_family').value = '';
        document.getElementById('qa_classification_name').value = '';
    });
    document.getElementById('qaClassificationSaveBtn').addEventListener('click', function () {
        clearError('qaClassificationError');
        var name = document.getElementById('qa_classification_name').value.trim();
        if (!name) { showError('qaClassificationError', 'Classification Name is required.'); return; }
        var payload = { action: 'add_classification', classification_name: name,
                        classification_family: document.getElementById('qa_classification_family').value.trim() };
        postQA(payload, function (data) {
            appendOption('classification_id', data.id, data.label);
            bootstrap.Modal.getInstance(document.getElementById('qaClassificationModal')).hide();
        }, function (err) { showError('qaClassificationError', err); });
    });

    /* ── Account Code ── */
    document.getElementById('qaAccountCodeModal').addEventListener('show.bs.modal', function () {
        clearError('qaAccountCodeError');
        document.getElementById('qa_account_code').value = '';
        document.getElementById('qa_account_name').value = '';
    });
    document.getElementById('qaAccountCodeSaveBtn').addEventListener('click', function () {
        clearError('qaAccountCodeError');
        var code = document.getElementById('qa_account_code').value.trim();
        var name = document.getElementById('qa_account_name').value.trim();
        if (!code) { showError('qaAccountCodeError', 'Account Code is required.'); return; }
        if (!name) { showError('qaAccountCodeError', 'Account Name is required.'); return; }
        postQA({ action: 'add_account_code', account_code: code, account_name: name }, function (data) {
            appendOption('account_code_id', data.id, data.label);
            bootstrap.Modal.getInstance(document.getElementById('qaAccountCodeModal')).hide();
        }, function (err) { showError('qaAccountCodeError', err); });
    });

    /* ── Brand ── */
    document.getElementById('qaBrandModal').addEventListener('show.bs.modal', function () {
        clearError('qaBrandError');
        document.getElementById('qa_brand_name').value = '';
    });
    document.getElementById('qaBrandSaveBtn').addEventListener('click', function () {
        clearError('qaBrandError');
        var name = document.getElementById('qa_brand_name').value.trim();
        if (!name) { showError('qaBrandError', 'Brand Name is required.'); return; }
        postQA({ action: 'add_brand', brand_name: name }, function (data) {
            appendOption('brand_id', data.id, data.label);
            /* Also add to model select's brand filter data */
            var modelSel = document.getElementById('model_id');
            if (modelSel) {
                var opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '— Select Model —';
                opt.setAttribute('data-brand-id', '');
            }
            bootstrap.Modal.getInstance(document.getElementById('qaBrandModal')).hide();
        }, function (err) { showError('qaBrandError', err); });
    });

    /* ── Model ── */
    document.getElementById('qaModelModal').addEventListener('show.bs.modal', function () {
        clearError('qaModelError');
        document.getElementById('qa_model_name').value = '';
        var brandSel = document.getElementById('brand_id');
        var brandId = brandSel ? brandSel.value : '';
        var brandLabel = brandSel && brandSel.selectedIndex >= 0 ? brandSel.options[brandSel.selectedIndex].text : '—';
        document.getElementById('qa_model_brand_id').value = brandId;
        document.getElementById('qaModelBrandLabel').textContent = brandId ? brandLabel : '— (no brand selected)';
    });
    document.getElementById('qaModelSaveBtn').addEventListener('click', function () {
        clearError('qaModelError');
        var name = document.getElementById('qa_model_name').value.trim();
        var brandId = document.getElementById('qa_model_brand_id').value;
        if (!name) { showError('qaModelError', 'Model Name is required.'); return; }
        if (!brandId) { showError('qaModelError', 'Please select a Brand first, then open this dialog.'); return; }
        postQA({ action: 'add_model', model_name: name, brand_id: brandId }, function (data) {
            appendOption('model_id', data.id, data.label, { 'brand-id': data.brand_id });
            bootstrap.Modal.getInstance(document.getElementById('qaModelModal')).hide();
        }, function (err) { showError('qaModelError', err); });
    });

    /* ── Office ── */
    document.getElementById('qaOfficeModal').addEventListener('show.bs.modal', function () {
        clearError('qaOfficeError');
        document.getElementById('qa_office_code').value = '';
        document.getElementById('qa_office_name').value = '';
    });
    document.getElementById('qaOfficeSaveBtn').addEventListener('click', function () {
        clearError('qaOfficeError');
        var code = document.getElementById('qa_office_code').value.trim().toUpperCase();
        var name = document.getElementById('qa_office_name').value.trim();
        if (!code) { showError('qaOfficeError', 'Office Code is required.'); return; }
        if (!name) { showError('qaOfficeError', 'Office Name is required.'); return; }
        postQA({ action: 'add_office', office_code: code, office_name: name }, function (data) {
            appendOption('office_id', data.id, data.label);
            /* Also add to employee modal's office dropdown */
            var empOfficeSel = document.getElementById('qa_emp_office_id');
            if (empOfficeSel) {
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.label;
                empOfficeSel.appendChild(opt);
            }
            bootstrap.Modal.getInstance(document.getElementById('qaOfficeModal')).hide();
        }, function (err) { showError('qaOfficeError', err); });
    });

    /* ── Employee ── */
    document.getElementById('qaEmployeeModal').addEventListener('show.bs.modal', function () {
        clearError('qaEmployeeError');
        document.getElementById('qa_emp_first_name').value = '';
        document.getElementById('qa_emp_middle_name').value = '';
        document.getElementById('qa_emp_last_name').value = '';
        document.getElementById('qa_emp_position_title').value = '';
        /* Populate office dropdown from main office_id select */
        var empOfficeSel = document.getElementById('qa_emp_office_id');
        var mainOfficeSel = document.getElementById('office_id');
        empOfficeSel.innerHTML = '<option value="">— Select Office —</option>';
        if (mainOfficeSel) {
            Array.from(mainOfficeSel.options).forEach(function (opt) {
                if (!opt.value) return;
                var o = document.createElement('option');
                o.value = opt.value;
                o.textContent = opt.textContent;
                empOfficeSel.appendChild(o);
            });
            /* Mirror current selection */
            empOfficeSel.value = mainOfficeSel.value || '';
        }
    });
    document.getElementById('qaEmployeeSaveBtn').addEventListener('click', function () {
        clearError('qaEmployeeError');
        var first = document.getElementById('qa_emp_first_name').value.trim();
        var last  = document.getElementById('qa_emp_last_name').value.trim();
        if (!first) { showError('qaEmployeeError', 'First Name is required.'); return; }
        if (!last)  { showError('qaEmployeeError', 'Last Name is required.'); return; }
        var payload = {
            action: 'add_employee',
            first_name: first,
            middle_name: document.getElementById('qa_emp_middle_name').value.trim(),
            last_name: last,
            position_title: document.getElementById('qa_emp_position_title').value.trim(),
            office_id: document.getElementById('qa_emp_office_id').value
        };
        postQA(payload, function (data) {
            appendOption('employee_id', data.id, data.label, { 'office-id': data.office_id });
            /* Update employee filter state */
            if (typeof setupOfficeEmployeeFilter === 'function') {
                setupOfficeEmployeeFilter();
            }
            bootstrap.Modal.getInstance(document.getElementById('qaEmployeeModal')).hide();
        }, function (err) { showError('qaEmployeeError', err); });
    });

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>






