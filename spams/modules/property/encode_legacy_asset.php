<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$errors = [];
$offices = [];
$employees = [];
$responsibilityCodes = [];
$classifications = [];
$accountCodes = [];
$brands = [];
$models = [];
$suppliers = [];
$funds = [];
$unitOfMeasures = [];
$csrfToken = csrf_token();
$duplicateSource = null;
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
    'acquisition_date' => '',
    'quantity' => '1',
    'unit_of_measure_id' => '',
    'unit_cost' => '',
    'office_id' => '',
    'employee_id' => '',
    'responsibility_code_id' => '',
    'condition_status' => 'good',
    'remarks' => '',
];

function legacy_asset_temp_property_number(mysqli $db, string $officeCode = ''): string
{
    $officeCode = strtoupper(trim($officeCode));
    $officeCode = preg_replace('/[^A-Z0-9]/', '', $officeCode) ?? '';
    if ($officeCode === '') {
        $officeCode = 'GEN';
    }

    $prefix = 'TEMP-' . $officeCode . '-' . date('Y') . '-';
    $nextSeq = 1;

    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(property_number, '-', -1) AS UNSIGNED)), 0) AS current_value
         FROM legacy_assets
         WHERE property_number LIKE ?"
    );
    if ($stmt) {
        $pattern = $prefix . '%';
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $nextSeq = ((int) ($row['current_value'] ?? 0)) + 1;
    }

    do {
        $propertyNumber = $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
        $conflict = asset_identifier_conflict($db, 'property_number', $propertyNumber);
        $nextSeq++;
    } while ($conflict);

    return $propertyNumber;
}

if ($db) {
    ensure_legacy_assets_fund_column($db);
    ensure_legacy_assets_unit_of_measure_column($db);

    $res = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res instanceof mysqli_result) { $offices = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, office_id, responsibility_code_id, is_unit_head, position_title, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($res instanceof mysqli_result) { $employees = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, office_id, code, description FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC");
    if ($res instanceof mysqli_result) { $responsibilityCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, classification_name, classification_family, classification_group, account_code_id FROM classifications WHERE is_active = 1 ORDER BY classification_family ASC, classification_name ASC");
    if ($res instanceof mysqli_result) { $classifications = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($res instanceof mysqli_result) { $accountCodes = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, fund_code, fund_name, fund_source FROM funds WHERE is_active = 1 ORDER BY fund_code ASC, fund_name ASC");
    if ($res instanceof mysqli_result) { $funds = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, uom_name, abbreviation FROM unit_of_measures WHERE is_active = 1 ORDER BY uom_name ASC");
    if ($res instanceof mysqli_result) { $unitOfMeasures = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
    if ($res instanceof mysqli_result) { $suppliers = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($res instanceof mysqli_result) { $brands = $res->fetch_all(MYSQLI_ASSOC); }
    $res = $db->query("SELECT id, model_name, brand_id FROM models WHERE is_active = 1 ORDER BY model_name ASC");
    if ($res instanceof mysqli_result) { $models = $res->fetch_all(MYSQLI_ASSOC); }

    $duplicateId = (int) ($_GET['duplicate_id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $duplicateId > 0) {
        $duplicateStmt = $db->prepare("
            SELECT id, property_number, po_number, item_type, item_description, classification_id, account_code_id,
                   fund_id, supplier_id, brand_id, model_id, acquisition_date, quantity, unit_of_measure_id,
                   unit_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks
            FROM legacy_assets
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        if ($duplicateStmt) {
            $duplicateStmt->bind_param('i', $duplicateId);
            $duplicateStmt->execute();
            $duplicateSource = $duplicateStmt->get_result()->fetch_assoc() ?: null;
            $duplicateStmt->close();

            if ($duplicateSource) {
                foreach ($form as $key => $value) {
                    if (array_key_exists($key, $duplicateSource)) {
                        $form[$key] = (string) ($duplicateSource[$key] ?? '');
                    }
                }
                $form['property_number'] = '';
                $form['serial_no'] = '';
            } else {
                add_validation_error($errors, 'The asset selected for duplication was not found.');
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($form as $key => $value) {
            $form[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        if (!csrf_verify()) {
            add_validation_error($errors, 'Invalid CSRF token.');
        }
        if ($form['item_description'] === '') { add_validation_error($errors, 'Description is required.'); }
        if (!is_allowed_value($form['item_type'], ['semi_expendable', 'equipment'])) { add_validation_error($errors, 'Inventory type must be semi-expendable or equipment.'); }
        if ($form['quantity'] === '' || !ctype_digit($form['quantity']) || (int) $form['quantity'] <= 0) { add_validation_error($errors, 'Quantity is required.'); }
        if ($form['unit_of_measure_id'] !== '') {
            $unitExists = false;
            foreach ($unitOfMeasures as $unitRow) {
                if ((int) ($unitRow['id'] ?? 0) === (int) $form['unit_of_measure_id']) {
                    $unitExists = true;
                    break;
                }
            }
            if (!$unitExists) {
                add_validation_error($errors, 'Selected unit type is invalid.');
            }
        }
        if ($form['unit_cost'] !== '' && (!is_numeric($form['unit_cost']) || (float) $form['unit_cost'] < 0)) {
            add_validation_error($errors, 'Unit cost must be a valid amount or left blank if unknown.');
        }
        if ($form['acquisition_date'] !== '' && !is_valid_date_string($form['acquisition_date'])) {
            add_validation_error($errors, 'Acquisition date format is invalid.');
        }
        if ($form['account_code_id'] === '') { add_validation_error($errors, 'Account code is required to generate the property number.'); }
        if (!is_allowed_value($form['condition_status'], ['good', 'serviceable', 'repair_needed', 'unserviceable'])) {
            add_validation_error($errors, 'Condition status is invalid.');
        }

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
            add_validation_error($errors, 'Selected fund is invalid.');
        }

        $accountCodeValue = '';
        $expectedAccountGroups = $form['item_type'] === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
        $accountCodeGroup = '';
        foreach ($accountCodes as $accountCodeRow) {
            if ($form['account_code_id'] !== '' && (int) $accountCodeRow['id'] === (int) $form['account_code_id']) {
                $accountCodeValue = trim((string) ($accountCodeRow['account_code'] ?? ''));
                $accountCodeGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                break;
            }
        }
        if ($form['account_code_id'] !== '' && $accountCodeValue === '') {
            add_validation_error($errors, 'Selected account code is invalid.');
        }
        if ($form['account_code_id'] !== '' && $accountCodeValue !== '' && !in_array($accountCodeGroup, $expectedAccountGroups, true)) {
            add_validation_error($errors, 'Selected account code does not match the inventory type.');
        }
        if ($form['classification_id'] !== '') {
            $classificationGroup = '';
            $classificationAccountCodeId = 0;
            foreach ($classifications as $classificationRow) {
                if ((int) ($classificationRow['id'] ?? 0) === (int) $form['classification_id']) {
                    $classificationGroup = trim((string) ($classificationRow['classification_group'] ?? ''));
                    $classificationAccountCodeId = (int) ($classificationRow['account_code_id'] ?? 0);
                    break;
                }
            }
            if ($classificationGroup === '' && $classificationAccountCodeId > 0) {
                foreach ($accountCodes as $accountCodeRow) {
                    if ((int) ($accountCodeRow['id'] ?? 0) === $classificationAccountCodeId) {
                        $classificationGroup = trim((string) ($accountCodeRow['account_group'] ?? ''));
                        break;
                    }
                }
            }
            $expectedClassificationGroups = $form['item_type'] === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            if ($classificationGroup === '') {
                add_validation_error($errors, 'Selected item classification is invalid.');
            } elseif (!in_array($classificationGroup, $expectedClassificationGroups, true)) {
                add_validation_error($errors, 'Selected item classification does not match the inventory type.');
            }
        }

        $officeIdValue = $form['office_id'] !== '' ? (int) $form['office_id'] : 0;
        $employeeIdValue = $form['employee_id'] !== '' ? (int) $form['employee_id'] : 0;
        $responsibilityCodeIdValue = $form['responsibility_code_id'] !== '' ? (int) $form['responsibility_code_id'] : 0;

        if ($officeIdValue <= 0) {
            add_validation_error($errors, 'Office assignment is required for PAR/ICS printing.');
        }
        if ($employeeIdValue <= 0) {
            add_validation_error($errors, 'Accountable employee is required for PAR/ICS printing.');
        }

        if ($officeIdValue > 0) {
            $officeExists = false;
            foreach ($offices as $officeRow) {
                if ((int) ($officeRow['id'] ?? 0) === $officeIdValue) {
                    $officeExists = true;
                    break;
                }
            }
            if (!$officeExists) {
                add_validation_error($errors, 'Selected office is invalid.');
            }
        }

        if ($employeeIdValue > 0) {
            $employeeOfficeId = 0;
            foreach ($employees as $employeeRow) {
                if ((int) ($employeeRow['id'] ?? 0) === $employeeIdValue) {
                    $employeeOfficeId = (int) ($employeeRow['office_id'] ?? 0);
                    break;
                }
            }
            if ($employeeOfficeId <= 0) {
                add_validation_error($errors, 'Selected employee is invalid.');
            } elseif ($officeIdValue > 0 && $employeeOfficeId !== $officeIdValue) {
                add_validation_error($errors, 'Selected employee does not belong to the selected office.');
            }
        }

        if ($responsibilityCodeIdValue > 0) {
            $rcOfficeId = 0;
            foreach ($responsibilityCodes as $rcRow) {
                if ((int) ($rcRow['id'] ?? 0) === $responsibilityCodeIdValue) {
                    $rcOfficeId = (int) ($rcRow['office_id'] ?? 0);
                    break;
                }
            }
            if ($rcOfficeId <= 0) {
                add_validation_error($errors, 'Selected responsibility code is invalid.');
            } elseif ($officeIdValue > 0 && $rcOfficeId !== $officeIdValue) {
                add_validation_error($errors, 'Selected responsibility code does not belong to the selected office.');
            }
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
            $hasOfficialNumberInputs = $form['acquisition_date'] !== ''
                && $form['fund_id'] !== ''
                && $fundCodeValue !== ''
                && $accountCodeValue !== '';

            $form['property_number'] = $hasOfficialNumberInputs
                ? generate_property_number($db, $yearValue, $fundCodeValue, $accountCodeValue, $officeCodeValue)
                : legacy_asset_temp_property_number($db, $officeCodeValue);
        }

        if (!$errors) {
            $propertyConflict = asset_identifier_conflict($db, 'property_number', $form['property_number']);
            if ($propertyConflict) {
                add_validation_error($errors, 'Property number already exists in ' . $propertyConflict['label'] . ' #' . $propertyConflict['id'] . '.');
            }
        }

        if (!$errors && $form['serial_no'] !== '') {
            $serialConflict = asset_identifier_conflict($db, 'serial_no', $form['serial_no'], '', 0, true);
            if ($serialConflict) {
                add_validation_error($errors, 'Serial number already exists in ' . $serialConflict['label'] . ' #' . $serialConflict['id'] . '.');
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
            $unitOfMeasureId = $form['unit_of_measure_id'] !== '' ? (int) $form['unit_of_measure_id'] : null;
            $unitCost = $form['unit_cost'] !== '' ? (float) $form['unit_cost'] : 0.0;
            $acquisitionCost = round($quantity * $unitCost, 2);
            $itemName = '';
            $brandName = '';
            $modelName = '';
            foreach ($classifications as $classificationRow) {
                if ((int) ($classificationRow['id'] ?? 0) === (int) $classificationId) {
                    $itemName = trim((string) ($classificationRow['classification_name'] ?? ''));
                    break;
                }
            }
            if ($itemName === '') {
                foreach ($accountCodes as $accountCodeRow) {
                    if ((int) ($accountCodeRow['id'] ?? 0) === (int) $accountCodeId) {
                        $itemName = trim((string) ($accountCodeRow['account_name'] ?? ''));
                        break;
                    }
                }
            }
            foreach ($brands as $brandRow) {
                if ((int) $brandRow['id'] === (int) $brandId) { $brandName = (string) $brandRow['brand_name']; break; }
            }
            foreach ($models as $modelRow) {
                if ((int) $modelRow['id'] === (int) $modelId) { $modelName = (string) $modelRow['model_name']; break; }
            }

            $stmt = $db->prepare("
                INSERT INTO legacy_assets
                    (system_reference, po_number, property_number, item_type, item_description, classification_id, account_code_id, fund_id, supplier_id, brand_id, model_id, brand, model, serial_no, acquisition_date, quantity, unit_of_measure_id, unit_cost, acquisition_cost, office_id, employee_id, responsibility_code_id, condition_status, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if ($stmt) {
                $stmt->bind_param(
                    'sssssiiiiiissssiiddiiissi',
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
                    $unitOfMeasureId,
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

                if ($legacyAssetId > 0 && schema_has_column($db, 'legacy_assets', 'item_name')) {
                    $itemNameStmt = $db->prepare("UPDATE legacy_assets SET item_name = NULLIF(?, '') WHERE id = ?");
                    if ($itemNameStmt) {
                        $itemNameStmt->bind_param('si', $itemName, $legacyAssetId);
                        $itemNameStmt->execute();
                        $itemNameStmt->close();
                    }
                }

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
                        'item_name' => $itemName,
                        'item_description' => $form['item_description'],
                        'fund_id' => $fundId,
                        'acquisition_date' => $form['acquisition_date'],
                        'quantity' => $quantity,
                        'unit_of_measure_id' => $unitOfMeasureId,
                        'unit_cost' => $unitCost,
                        'acquisition_cost' => $acquisitionCost,
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
}

$page_title = 'Encode Beginning Balance Asset';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
<div class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pb-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold">Beginning Balance Encoding</div>
                        <h4 class="mb-1">Encode Legacy Asset</h4>
                        <div class="small text-muted">Record an existing asset already owned by the university. If date or fund is still unknown, the system saves a temporary property number first.</div>
                    </div>
                    <a href="<?php echo h(base_url('modules/property/legacy_assets.php')); ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($duplicateSource): ?>
                    <div class="alert alert-info">
                        Duplicating <?php echo h((string) ($duplicateSource['property_number'] ?? 'selected beginning balance asset')); ?>. Enter the new serial number, then save to generate a new property number.
                    </div>
                <?php endif; ?>

                <form method="post" class="workspace-form-section">
                    <input type="hidden" name="_csrf" id="legacy_asset_csrf_token" value="<?php echo h($csrfToken); ?>">
                    <div class="row g-3">

                        <!-- ── Asset Details ── -->
                        <div class="col-12">
                            <div class="small text-uppercase text-muted fw-semibold">Asset Details</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Property Number</label>
                            <input type="text" class="form-control" name="property_number" value="<?php echo h($form['property_number']); ?>" readonly>
                            <div class="form-text">Official number is generated when date and fund are complete. Otherwise a TEMP number is assigned.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Inventory Type</label>
                            <select name="item_type" class="form-select" id="item_type">
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
                            <div class="form-text">Leave blank if still for verification.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity</label>
                            <input type="number" min="1" step="1" class="form-control" name="quantity" value="<?php echo h($form['quantity']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit of Measure</label>
                            <select name="unit_of_measure_id" class="form-select">
                                <option value="">Select unit</option>
                                <?php foreach ($unitOfMeasures as $unit): ?>
                                    <?php
                                    $unitId = (int) ($unit['id'] ?? 0);
                                    $unitLabel = trim(($unit['uom_name'] ?? '') . (($unit['abbreviation'] ?? '') !== '' ? ' (' . $unit['abbreviation'] . ')' : ''));
                                    ?>
                                    <option value="<?php echo $unitId; ?>" <?php echo $form['unit_of_measure_id'] === (string) $unitId ? 'selected' : ''; ?>>
                                        <?php echo h($unitLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="unit_cost" value="<?php echo h($form['unit_cost']); ?>" placeholder="Leave blank if unknown">
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
                            <div class="form-text">Leave blank if still for verification.</div>
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
                            <label class="form-label">Equipment Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="item_description" rows="3" required><?php echo h($form['item_description']); ?></textarea>
                        </div>

                        <!-- ── Classification and Catalog ── -->
                        <div class="col-12 mt-2">
                            <div class="small text-uppercase text-muted fw-semibold">Classification and Catalog</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Item Classification</label>
                            <div class="input-group qa-field-group">
                                <select name="classification_id" class="form-select" id="classification_id">
                                    <option value="">Select classification</option>
                                    <?php foreach ($classifications as $classification): ?>
                                        <option value="<?php echo (int) $classification['id']; ?>" <?php echo $form['classification_id'] === (string) $classification['id'] ? 'selected' : ''; ?> data-account-code-id="<?php echo (int) ($classification['account_code_id'] ?? 0); ?>" data-classification-group="<?php echo h((string) ($classification['classification_group'] ?? '')); ?>">
                                            <?php echo h(trim(($classification['classification_family'] ?? '') . ' / ' . ($classification['classification_name'] ?? ''))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new classification" aria-label="Add new classification" data-qa-modal="qaClassificationModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                            <div class="form-text">Select the actual item classification, such as Airconditioner, Chair, or Printer.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account Code <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="account_code_id" class="form-select" id="account_code_id">
                                    <option value="">Select account code</option>
                                    <?php foreach ($accountCodes as $accountCode): ?>
                                        <option value="<?php echo (int) $accountCode['id']; ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?> data-account-group="<?php echo h((string) ($accountCode['account_group'] ?? '')); ?>">
                                            <?php echo h(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new account code" aria-label="Add new account code" data-qa-modal="qaAccountCodeModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Brand</label>
                            <div class="input-group qa-field-group">
                                <select name="brand_id" class="form-select" id="brand_id" data-placeholder="Select brand">
                                    <option value="">Select brand</option>
                                    <?php foreach ($brands as $brand): ?>
                                        <option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new brand" aria-label="Add new brand" data-qa-modal="qaBrandModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Model</label>
                            <div class="input-group qa-field-group">
                                <select name="model_id" class="form-select" id="model_id" data-placeholder="Select model">
                                    <option value="">Select model</option>
                                    <?php foreach ($models as $model): ?>
                                        <option value="<?php echo (int) $model['id']; ?>" <?php echo $form['model_id'] === (string) $model['id'] ? 'selected' : ''; ?> data-brand-id="<?php echo (int) ($model['brand_id'] ?? 0); ?>"><?php echo h($model['model_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new model" aria-label="Add new model" data-qa-modal="qaModelModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Serial No.</label>
                            <input type="text" class="form-control" name="serial_no" value="<?php echo h($form['serial_no']); ?>">
                        </div>

                        <!-- ── Assignment ── -->
                        <div class="col-12 mt-2">
                            <div class="small text-uppercase text-muted fw-semibold">Assignment</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Office <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="office_id" class="form-select" id="office_id" data-placeholder="Select office" required>
                                    <option value="">Select office</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new office" aria-label="Add new office" data-qa-modal="qaOfficeModal"><i class="bi bi-plus-lg"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Accountable Employee <span class="text-danger">*</span></label>
                            <div class="input-group qa-field-group">
                                <select name="employee_id" class="form-select" id="employee_id" data-placeholder="Select employee" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?> data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-responsibility-code-id="<?php echo (int) ($employee['responsibility_code_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>">
                                            <?php echo h(employee_display_name($employee)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary qa-trigger-btn" title="Add new employee" aria-label="Add new employee" data-qa-modal="qaEmployeeModal"><i class="bi bi-plus-lg"></i></button>
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

                        <div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2 border-top mt-2">
                            <a href="<?php echo h(base_url('modules/property/legacy_assets.php')); ?>" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-floppy me-1"></i>Save Encoded Asset
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</section>

<script>
(function() {
    function select2Ready() {
        return !!(window.jQuery && jQuery.fn && jQuery.fn.select2);
    }

    function initPageSelect2(select) {
        if (!select || select.hasAttribute('data-no-select2') || !select2Ready()) {
            return;
        }
        var $select = jQuery(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.select2('destroy');
        }
        var allowClear = Array.from(select.options || []).some(function (option) {
            return option.value === '';
        });
        $select.select2({
            width: '100%',
            placeholder: select.getAttribute('data-placeholder') || 'Select an option',
            allowClear: allowClear,
            minimumResultsForSearch: 0,
            dropdownParent: jQuery(document.body)
        });
        select.setAttribute('data-select2-initialized', 'true');
    }

    function refreshEnhancedSelect(select) {
        if (!select) { return; }
        if (!select2Ready()) {
            window.setTimeout(function () { refreshEnhancedSelect(select); }, 150);
            return;
        }
        initPageSelect2(select);
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
    var accountCodeOptions = window.legacyAssetAccountCodeOptions = <?php
        $accountCodeDataset = array_map(static function ($accountCode) {
            return [
                'value' => (string) ($accountCode['id'] ?? ''),
                'text' => trim((string) ($accountCode['account_code'] ?? '') . ' - ' . (string) ($accountCode['account_name'] ?? '')),
                'accountGroup' => (string) ($accountCode['account_group'] ?? ''),
            ];
        }, $accountCodes);
        echo json_encode($accountCodeDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;
    var classificationOptions = window.legacyAssetClassificationOptions = <?php
        $classificationDataset = array_map(static function ($classification) {
            return [
                'value' => (string) ($classification['id'] ?? ''),
                'text' => trim((string) ($classification['classification_family'] ?? '') . ' / ' . (string) ($classification['classification_name'] ?? '')),
                'accountCodeId' => (string) ($classification['account_code_id'] ?? ''),
                'classificationGroup' => (string) ($classification['classification_group'] ?? ''),
            ];
        }, $classifications);
        echo json_encode($classificationDataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ?>;

    function expectedAssetGroups(itemType) {
        return itemType === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
    }

    function classificationGroupMatches(optionData, expectedGroups) {
        if (expectedGroups.indexOf(optionData.classificationGroup) !== -1) {
            return true;
        }
        if (!optionData.classificationGroup && optionData.accountCodeId) {
            var accountCode = accountCodeOptions.find(function(accountData) {
                return accountData.value === optionData.accountCodeId;
            });
            return !!accountCode && expectedGroups.indexOf(accountCode.accountGroup) !== -1;
        }
        return false;
    }

    function filterClassificationsForType() {
        var itemTypeSelect = document.getElementById('item_type');
        var classificationSelect = document.getElementById('classification_id');
        if (!itemTypeSelect || !classificationSelect) { return; }
        var expectedGroups = expectedAssetGroups(itemTypeSelect.value);
        var previousValue = classificationSelect.value || '';
        classificationSelect.innerHTML = '';
        classificationSelect.add(new Option('Select classification', '', false, false));
        classificationOptions.forEach(function(optionData) {
            if (!classificationGroupMatches(optionData, expectedGroups)) { return; }
            var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
            option.setAttribute('data-account-code-id', optionData.accountCodeId || '0');
            option.setAttribute('data-classification-group', optionData.classificationGroup || '');
            classificationSelect.add(option);
        });
        if (previousValue !== '' && !Array.from(classificationSelect.options).some(function(option) { return option.value === previousValue; })) {
            classificationSelect.value = '';
        }
        refreshEnhancedSelect(classificationSelect);
    }
    window.legacyAssetFilterClassificationsForType = filterClassificationsForType;

    function setupAccountCodeTypeFilter() {
        var itemTypeSelect = document.getElementById('item_type');
        var accountCodeSelect = document.getElementById('account_code_id');
        if (!itemTypeSelect || !accountCodeSelect) { return; }
        var initialValue = accountCodeSelect.value || '';

        function filterAccountCodes() {
            var expectedGroups = itemTypeSelect.value === 'equipment' ? ['asset', 'fixed_asset'] : ['semi_expendable'];
            var previousValue = accountCodeSelect.value || initialValue;
            accountCodeSelect.innerHTML = '';
            accountCodeSelect.add(new Option('Select account code', '', false, false));
            accountCodeOptions.forEach(function(optionData) {
                if (expectedGroups.indexOf(optionData.accountGroup) === -1) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
                option.setAttribute('data-account-group', optionData.accountGroup);
                accountCodeSelect.add(option);
            });
            if (previousValue !== '' && !Array.from(accountCodeSelect.options).some(function(option) { return option.value === previousValue; })) {
                accountCodeSelect.value = '';
            }
            initialValue = '';
            refreshEnhancedSelect(accountCodeSelect);
            filterClassificationsForType();
        }

        filterAccountCodes();
        window.setTimeout(filterAccountCodes, 0);
        window.setTimeout(filterAccountCodes, 250);
        if (itemTypeSelect.getAttribute('data-account-filter-wired') !== '1') {
            itemTypeSelect.setAttribute('data-account-filter-wired', '1');
            itemTypeSelect.addEventListener('change', filterAccountCodes);
            if (window.jQuery) {
                jQuery(itemTypeSelect)
                    .off('select2:select.legacyAccountFilter select2:clear.legacyAccountFilter change.legacyAccountFilter')
                    .on('select2:select.legacyAccountFilter select2:clear.legacyAccountFilter change.legacyAccountFilter', filterAccountCodes);
            }
        }
    }
    window.legacyAssetSetupAccountCodeTypeFilter = setupAccountCodeTypeFilter;

    function setupBrandModelFilter() {
        var brandSelect = document.querySelector('select[name="brand_id"]');
        var modelSelect = document.querySelector('select[name="model_id"]');
        if (!brandSelect || !modelSelect) { return; }
        if (brandSelect.getAttribute('data-brand-model-wired') === '1') { return; }
        brandSelect.setAttribute('data-brand-model-wired', '1');

        function filterModels() {
            var brandId = brandSelect.value || '';
            var previousValue = modelSelect.value || '';
            modelSelect.innerHTML = '';
            modelSelect.add(new Option('Select model', '', false, false));
            modelOptions.forEach(function(optionData) {
                // When a brand is selected, only show models tied to that brand.
                if (brandId !== '' && optionData.brandId !== brandId) { return; }
                var option = new Option(optionData.text, optionData.value, false, optionData.value === previousValue);
                option.setAttribute('data-brand-id', optionData.brandId);
                modelSelect.add(option);
            });
            if (previousValue !== '' && !Array.from(modelSelect.options).some(function(o) { return o.value === previousValue; })) {
                modelSelect.value = '';
            }
            refreshEnhancedSelect(modelSelect);
        }

        refreshEnhancedSelect(brandSelect);
        filterModels();
        brandSelect.addEventListener('change', filterModels);
        jQuery(brandSelect).off('change.legacyBrandFilter').on('change.legacyBrandFilter', filterModels);
    }

    function setupOfficeEmployeeFilter() {
        var officeSelect = document.querySelector('select[name="office_id"]');
        var employeeSelect = document.querySelector('select[name="employee_id"]');
        var rcSelect = document.querySelector('select[name="responsibility_code_id"]');
        if (!officeSelect || !employeeSelect) { return; }
        if (!select2Ready()) {
            window.setTimeout(setupOfficeEmployeeFilter, 150);
            return;
        }
        if (officeSelect.getAttribute('data-office-employee-wired') === '1') { return; }
        officeSelect.setAttribute('data-office-employee-wired', '1');

        function refreshSharedSelect(select) {
            refreshEnhancedSelect(select);
        }

        function filterResponsibilityCodes() {
            if (!rcSelect) { return; }
            var officeId = officeSelect.value || '';
            var selectedEmployeeOption = employeeSelect.options[employeeSelect.selectedIndex];
            var employeeRcId = selectedEmployeeOption ? (selectedEmployeeOption.getAttribute('data-responsibility-code-id') || '') : '';
            var preferredRcId = '';
            Array.prototype.forEach.call(rcSelect.options, function(option) {
                if (!option.value) { option.hidden = false; return; }
                var optionOfficeId = option.getAttribute('data-office-id') || '';
                var matches = !officeId || optionOfficeId === officeId;
                option.hidden = !matches;
                if (matches && employeeRcId !== '' && option.value === employeeRcId) { preferredRcId = option.value; }
                if (matches && preferredRcId === '') { preferredRcId = option.value; }
                if (!matches && option.selected) { rcSelect.value = ''; }
            });
            if (officeId !== '' && (!rcSelect.value || (rcSelect.selectedOptions.length && rcSelect.selectedOptions[0].hidden)) && preferredRcId !== '') {
                rcSelect.value = preferredRcId;
            }
            refreshSharedSelect(rcSelect);
        }

        function filterEmployees() {
            var officeId = officeSelect.value || '';
            var preferredEmployeeId = '';
            var firstEmployeeId = '';
            Array.prototype.forEach.call(employeeSelect.options, function(option) {
                if (!option.value) { option.hidden = false; return; }
                var optionOfficeId = option.getAttribute('data-office-id') || '';
                var matches = !officeId || optionOfficeId === officeId;
                option.hidden = !matches;
                if (matches && firstEmployeeId === '') {
                    firstEmployeeId = option.value;
                }
                if (matches && option.getAttribute('data-is-unit-head') === '1' && preferredEmployeeId === '') {
                    preferredEmployeeId = option.value;
                }
                if (!matches && option.selected) { employeeSelect.value = ''; }
            });
            if (preferredEmployeeId === '') {
                preferredEmployeeId = firstEmployeeId;
            }
            if (officeId !== '' && (!employeeSelect.value || (employeeSelect.selectedOptions.length && employeeSelect.selectedOptions[0].hidden)) && preferredEmployeeId !== '') {
                employeeSelect.value = preferredEmployeeId;
            }
            refreshSharedSelect(employeeSelect);
            filterResponsibilityCodes();
        }

        function syncOfficeFromEmployee() {
            var selectedOption = employeeSelect.options[employeeSelect.selectedIndex];
            if (!selectedOption || !selectedOption.value) { return; }
            var selectedOfficeId = selectedOption.getAttribute('data-office-id') || '';
            if (!selectedOfficeId) { return; }
            if (officeSelect.value !== selectedOfficeId) {
                officeSelect.value = selectedOfficeId;
                refreshSharedSelect(officeSelect);
                filterEmployees();
                employeeSelect.value = selectedOption.value;
                refreshSharedSelect(employeeSelect);
            }
            filterResponsibilityCodes();
        }

        refreshSharedSelect(officeSelect);
        filterEmployees();
        officeSelect.addEventListener('change', filterEmployees);
        employeeSelect.addEventListener('change', syncOfficeFromEmployee);
        if (window.jQuery) {
            jQuery(officeSelect).off('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter change.legacyOfficeFilter').on('select2:select.legacyOfficeFilter select2:clear.legacyOfficeFilter change.legacyOfficeFilter', filterEmployees);
            jQuery(employeeSelect).off('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter change.legacyEmployeeFilter').on('select2:select.legacyEmployeeFilter select2:clear.legacyEmployeeFilter change.legacyEmployeeFilter', syncOfficeFromEmployee);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            [
                document.getElementById('classification_id'),
                document.getElementById('account_code_id'),
                document.getElementById('fund_id'),
                document.getElementById('supplier_id'),
                document.getElementById('brand_id'),
                document.getElementById('model_id'),
                document.getElementById('office_id'),
                document.getElementById('employee_id')
            ].forEach(refreshEnhancedSelect);
            setupAccountCodeTypeFilter();
            setupBrandModelFilter();
            setupOfficeEmployeeFilter();
        });
    } else {
        [
            document.getElementById('classification_id'),
            document.getElementById('account_code_id'),
            document.getElementById('fund_id'),
            document.getElementById('supplier_id'),
            document.getElementById('brand_id'),
            document.getElementById('model_id'),
            document.getElementById('office_id'),
            document.getElementById('employee_id')
        ].forEach(refreshEnhancedSelect);
        setupAccountCodeTypeFilter();
        setupBrandModelFilter();
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
          <label class="form-label">Classification Name <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="qa_classification_name" placeholder="e.g. Office Chair">
          <div class="form-text">Family will automatically copy the selected account code name.</div>
        </div>
      </div>
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaClassificationSaveBtn">Save</button>
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
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaAccountCodeSaveBtn">Save</button>
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
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaBrandSaveBtn">Save</button>
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
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaModelSaveBtn">Save</button>
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
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaOfficeSaveBtn">Save</button>
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
      <div class="modal-footer qa-modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary px-4" id="qaEmployeeSaveBtn">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    'use strict';

    var qaEndpoint = <?php echo json_encode(base_url('modules/property/legacy_assets_quickadd.php')); ?>;

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
        var el = document.getElementById('legacy_asset_csrf_token');
        if (!el) {
            el = document.querySelector('form.workspace-form-section input[name="_csrf"]');
        }
        if (!el) {
            el = document.querySelector('input[name="_csrf"]');
        }
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
        var stringId = String(id);
        var existing = Array.prototype.find.call(sel.options, function (option) {
            return String(option.value) === stringId;
        });
        if (existing) {
            existing.textContent = label;
            if (extraData) {
                Object.keys(extraData).forEach(function (k) {
                    existing.setAttribute('data-' + k.replace(/_/g, '-'), extraData[k]);
                });
            }
            sel.value = stringId;
            if (window.SPAMS && window.SPAMS.refreshSelect2) {
                window.SPAMS.refreshSelect2(sel);
            }
            return;
        }
        var opt = document.createElement('option');
        opt.value = stringId;
        opt.textContent = label;
        if (extraData) {
            Object.keys(extraData).forEach(function (k) {
                opt.setAttribute('data-' + k.replace(/_/g, '-'), extraData[k]);
            });
        }
        sel.appendChild(opt);
        sel.value = id;
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(sel);
        }
    }

    function postQA(payload, onSuccess, onError) {
        payload['_csrf'] = getCsrf();
        fetch(qaEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': getCsrf()
            },
            body: new URLSearchParams(payload).toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) { onSuccess(data); }
            else { onError(data.error || 'An error occurred.'); }
        })
        .catch(function () { onError('Network error. Please try again.'); });
    }

    function hideQAModal(modalId) {
        var modalEl = document.getElementById(modalId);
        if (!modalEl) return;
        var modal = bootstrap.Modal.getInstance(modalEl);
        if (modal) modal.hide();
    }

    function bindQAModal(config) {
        var modalEl = document.getElementById(config.modalId);
        var saveBtn = document.getElementById(config.saveBtnId);
        if (!modalEl || !saveBtn) return;

        modalEl.addEventListener('show.bs.modal', function () {
            clearError(config.errorId);
            if (typeof config.onShow === 'function') {
                config.onShow();
            }
        });

        saveBtn.addEventListener('click', function () {
            clearError(config.errorId);
            var payload = config.buildPayload();
            if (!payload || payload.error) {
                showError(config.errorId, (payload && payload.error) || 'Please complete required fields.');
                return;
            }

            postQA(payload, function (data) {
                config.onSuccess(data);
                hideQAModal(config.modalId);
            }, function (err) {
                showError(config.errorId, err);
            });
        });
    }

    bindQAModal({
        modalId: 'qaClassificationModal',
        saveBtnId: 'qaClassificationSaveBtn',
        errorId: 'qaClassificationError',
        onShow: function () {
            document.getElementById('qa_classification_name').value = '';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_classification_name').value.trim();
            var accountCodeSelect = document.getElementById('account_code_id');
            var accountCodeId = accountCodeSelect ? accountCodeSelect.value : '';
            if (!name) return { error: 'Classification Name is required.' };
            if (!accountCodeId) return { error: 'Select Account Code first before adding classification.' };
            return {
                action: 'add_classification',
                classification_name: name,
                account_code_id: accountCodeId
            };
        },
        onSuccess: function (data) {
            appendOption('classification_id', data.id, data.label, { account_code_id: data.account_code_id || 0, classification_group: data.classification_group || '' });
            if (window.legacyAssetClassificationOptions) {
                window.legacyAssetClassificationOptions.push({
                    value: String(data.id),
                    text: data.label,
                    accountCodeId: String(data.account_code_id || ''),
                    classificationGroup: data.classification_group || ''
                });
            }
            if (typeof window.legacyAssetFilterClassificationsForType === 'function') {
                window.legacyAssetFilterClassificationsForType();
                var classificationSelect = document.getElementById('classification_id');
                if (classificationSelect) {
                    classificationSelect.value = String(data.id);
                    if (window.SPAMS && window.SPAMS.refreshSelect2) {
                        window.SPAMS.refreshSelect2(classificationSelect);
                    }
                }
            }
        }
    });

    bindQAModal({
        modalId: 'qaAccountCodeModal',
        saveBtnId: 'qaAccountCodeSaveBtn',
        errorId: 'qaAccountCodeError',
        onShow: function () {
            document.getElementById('qa_account_code').value = '';
            document.getElementById('qa_account_name').value = '';
        },
        buildPayload: function () {
            var code = document.getElementById('qa_account_code').value.trim();
            var name = document.getElementById('qa_account_name').value.trim();
            if (!code) return { error: 'Account Code is required.' };
            if (!name) return { error: 'Account Name is required.' };
            var itemTypeSelect = document.getElementById('item_type');
            var accountGroup = itemTypeSelect && itemTypeSelect.value === 'equipment' ? 'asset' : 'semi_expendable';
            return { action: 'add_account_code', account_code: code, account_name: name, account_group: accountGroup };
        },
        onSuccess: function (data) {
            appendOption('account_code_id', data.id, data.label, { account_group: data.account_group || '' });
            if (window.legacyAssetAccountCodeOptions) {
                window.legacyAssetAccountCodeOptions.push({
                    value: String(data.id),
                    text: data.label,
                    accountGroup: data.account_group || ''
                });
            }
            if (typeof window.legacyAssetSetupAccountCodeTypeFilter === 'function') {
                window.legacyAssetSetupAccountCodeTypeFilter();
            }
        }
    });

    bindQAModal({
        modalId: 'qaBrandModal',
        saveBtnId: 'qaBrandSaveBtn',
        errorId: 'qaBrandError',
        onShow: function () {
            document.getElementById('qa_brand_name').value = '';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_brand_name').value.trim();
            if (!name) return { error: 'Brand Name is required.' };
            return { action: 'add_brand', brand_name: name };
        },
        onSuccess: function (data) {
            appendOption('brand_id', data.id, data.label);
        }
    });

    bindQAModal({
        modalId: 'qaModelModal',
        saveBtnId: 'qaModelSaveBtn',
        errorId: 'qaModelError',
        onShow: function () {
            document.getElementById('qa_model_name').value = '';
            var brandSel = document.getElementById('brand_id');
            var brandId = brandSel ? brandSel.value : '';
            var brandLabel = brandSel && brandSel.selectedIndex >= 0 ? brandSel.options[brandSel.selectedIndex].text : '—';
            document.getElementById('qa_model_brand_id').value = brandId;
            document.getElementById('qaModelBrandLabel').textContent = brandId ? brandLabel : '— (no brand selected)';
        },
        buildPayload: function () {
            var name = document.getElementById('qa_model_name').value.trim();
            var brandId = document.getElementById('qa_model_brand_id').value;
            if (!name) return { error: 'Model Name is required.' };
            if (!brandId) return { error: 'Please select a Brand first, then open this dialog.' };
            return { action: 'add_model', model_name: name, brand_id: brandId };
        },
        onSuccess: function (data) {
            appendOption('model_id', data.id, data.label, { 'brand-id': data.brand_id });
        }
    });

    bindQAModal({
        modalId: 'qaOfficeModal',
        saveBtnId: 'qaOfficeSaveBtn',
        errorId: 'qaOfficeError',
        onShow: function () {
            document.getElementById('qa_office_code').value = '';
            document.getElementById('qa_office_name').value = '';
        },
        buildPayload: function () {
            var code = document.getElementById('qa_office_code').value.trim().toUpperCase();
            var name = document.getElementById('qa_office_name').value.trim();
            if (!code) return { error: 'Office Code is required.' };
            if (!name) return { error: 'Office Name is required.' };
            return { action: 'add_office', office_code: code, office_name: name };
        },
        onSuccess: function (data) {
            appendOption('office_id', data.id, data.label);
            var empOfficeSel = document.getElementById('qa_emp_office_id');
            if (empOfficeSel) {
                var opt = document.createElement('option');
                opt.value = data.id;
                opt.textContent = data.label;
                empOfficeSel.appendChild(opt);
            }
        }
    });

    bindQAModal({
        modalId: 'qaEmployeeModal',
        saveBtnId: 'qaEmployeeSaveBtn',
        errorId: 'qaEmployeeError',
        onShow: function () {
            document.getElementById('qa_emp_first_name').value = '';
            document.getElementById('qa_emp_middle_name').value = '';
            document.getElementById('qa_emp_last_name').value = '';
            document.getElementById('qa_emp_position_title').value = '';

            var empOfficeSel = document.getElementById('qa_emp_office_id');
            var mainOfficeSel = document.getElementById('office_id');
            if (!empOfficeSel) return;

            empOfficeSel.innerHTML = '<option value="">— Select Office —</option>';
            if (mainOfficeSel) {
                Array.from(mainOfficeSel.options).forEach(function (opt) {
                    if (!opt.value) return;
                    var o = document.createElement('option');
                    o.value = opt.value;
                    o.textContent = opt.textContent;
                    empOfficeSel.appendChild(o);
                });
                empOfficeSel.value = mainOfficeSel.value || '';
            }
        },
        buildPayload: function () {
            var first = document.getElementById('qa_emp_first_name').value.trim();
            var last = document.getElementById('qa_emp_last_name').value.trim();
            if (!first) return { error: 'First Name is required.' };
            if (!last) return { error: 'Last Name is required.' };

            return {
                action: 'add_employee',
                first_name: first,
                middle_name: document.getElementById('qa_emp_middle_name').value.trim(),
                last_name: last,
                position_title: document.getElementById('qa_emp_position_title').value.trim(),
                office_id: document.getElementById('qa_emp_office_id').value
            };
        },
        onSuccess: function (data) {
            appendOption('employee_id', data.id, data.label, { 'office-id': data.office_id });
        }
    });

})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
