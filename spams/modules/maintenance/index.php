<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$page_title = 'Maintenance Log';
$flash = get_flash();
$errors = [];
$records = [];
$distributedItems = [];
$stats = [
    'total' => 0,
    'posted' => 0,
    'cancelled' => 0,
    'cost' => 0.00,
];
$form = [
    'maintenance_date' => date('Y-m-d'),
    'distribution_item_detail_id' => '',
    'work_description' => '',
    'performed_by' => '',
    'cost' => '',
    'remarks' => '',
];
$referencePreview = '';
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $referencePreview = preview_module_code($db, 'maintenance');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['maintenance_date'] = old($_POST, 'maintenance_date', date('Y-m-d'));
        $form['distribution_item_detail_id'] = old($_POST, 'distribution_item_detail_id');
        $form['work_description'] = old($_POST, 'work_description');
        $form['performed_by'] = old($_POST, 'performed_by');
        $form['cost'] = old($_POST, 'cost');
        $form['remarks'] = old($_POST, 'remarks');
        $action = trim((string) ($_POST['action'] ?? 'save'));

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        if (empty($errors) && $action === 'cancel') {
            $recordId = (int) ($_POST['id'] ?? 0);

            if ($recordId <= 0) {
                $errors[] = 'Invalid maintenance record.';
            } else {
                $cancelStmt = $db->prepare("UPDATE maintenance_logs SET status = 'cancelled' WHERE id = ? AND status = 'posted'");
                if ($cancelStmt) {
                    $cancelStmt->bind_param('i', $recordId);
                    $cancelStmt->execute();
                    $cancelStmt->close();
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'maintenance_logs',
                        'record_id' => $recordId,
                        'module_name' => 'maintenance',
                        'record_type' => 'maintenance',
                        'action_name' => 'cancel_maintenance_record',
                        'old_values' => ['status' => 'posted'],
                        'new_values' => ['status' => 'cancelled'],
                        'description' => 'Cancelled maintenance record.',
                    ]);
                    set_flash('success', 'Maintenance record cancelled successfully.');
                    redirect('modules/maintenance/index.php');
                }

                $errors[] = 'Unable to cancel the maintenance record.';
            }
        }

        $detailId = (int) ($form['distribution_item_detail_id'] !== '' ? $form['distribution_item_detail_id'] : 0);
        $maintenanceDate = trim($form['maintenance_date']);
        $workDescription = trim($form['work_description']);
        $performedBy = trim($form['performed_by']);
        $cost = trim($form['cost']);
        $remarks = trim($form['remarks']);

        if ($action !== 'cancel') {
            if ($maintenanceDate === '') {
                $errors[] = 'Maintenance date is required.';
            }
            if ($detailId <= 0) {
                $errors[] = 'Select a distributed item.';
            }
            if ($workDescription === '') {
                $errors[] = 'Description of work is required.';
            }
        }

        $costValue = null;
        if ($action !== 'cancel' && $cost !== '') {
            if (!is_numeric($cost)) {
                $errors[] = 'Cost must be a valid number.';
            } else {
                $costValue = (float) $cost;
                if ($costValue < 0) {
                    $errors[] = 'Cost cannot be negative.';
                }
            }
        }

        if ($action !== 'cancel' && empty($errors)) {
            $itemCheckStmt = $db->prepare("
                SELECT id
                FROM distribution_item_details
                WHERE id = ?
                  AND is_distributed = 1
                  AND (is_disposed IS NULL OR is_disposed = 0)
                LIMIT 1
            ");
            if ($itemCheckStmt) {
                $itemCheckStmt->bind_param('i', $detailId);
                $itemCheckStmt->execute();
                $itemExists = (bool) $itemCheckStmt->get_result()->fetch_assoc();
                $itemCheckStmt->close();
                if (!$itemExists) {
                    $errors[] = 'Selected distributed item is no longer available.';
                }
            }
        }

        if ($action !== 'cancel' && empty($errors)) {
            $systemReference = next_module_code($db, 'maintenance');
            $userId = current_user_id();
            $costToSave = $costValue !== null ? number_format($costValue, 2, '.', '') : null;

            $insertStmt = $db->prepare("
                INSERT INTO maintenance_logs
                    (system_reference, maintenance_date, distribution_item_detail_id, work_description, performed_by, cost, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, COALESCE(?, 0.00), ?, ?)
            ");

            if ($insertStmt) {
                $insertStmt->bind_param(
                    'ssissssi',
                    $systemReference,
                    $maintenanceDate,
                    $detailId,
                    $workDescription,
                    $performedBy,
                    $costToSave,
                    $remarks,
                    $userId
                );
                $insertStmt->execute();
                $maintenanceId = (int) $insertStmt->insert_id;
                $insertStmt->close();

                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'maintenance_logs',
                    'record_id' => $maintenanceId,
                    'module_name' => 'maintenance',
                    'record_type' => 'maintenance',
                    'action_name' => 'create_maintenance_record',
                    'new_values' => [
                        'system_reference' => $systemReference,
                        'maintenance_date' => $maintenanceDate,
                        'distribution_item_detail_id' => $detailId,
                        'performed_by' => $performedBy,
                        'cost' => $costToSave,
                    ],
                    'description' => 'Created maintenance record.',
                ]);

                set_flash('success', 'Maintenance record saved successfully.');
                redirect('modules/maintenance/index.php');
            }

            $errors[] = 'Unable to save the maintenance record.';
        }
    }

    $itemsStmt = $db->prepare("
        SELECT
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            poi.item_type,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            COALESCE(curr_o.office_name, base_o.office_name) AS office_name,
            COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name,
            COALESCE(curr_e.position_title, base_e.position_title) AS position_title,
            COALESCE(curr_rc.code, base_rc.code) AS rc_code
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN responsibility_codes base_rc ON base_rc.office_id = d.office_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN responsibility_codes curr_rc ON curr_rc.id = did.current_responsibility_code_id
        WHERE did.is_distributed = 1
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
        ORDER BY poi.item_type ASC, poi.item_description ASC, did.property_number ASC, did.id ASC
    ");
    if ($itemsStmt) {
        $itemsStmt->execute();
        $distributedItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $itemsStmt->close();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedDetailId > 0) {
        foreach ($distributedItems as $assetRow) {
            if ((int) ($assetRow['id'] ?? 0) === $preselectedDetailId) {
                $form['distribution_item_detail_id'] = (string) $preselectedDetailId;
                break;
            }
        }
    }

    $listStmt = $db->prepare("
        SELECT
            ml.id,
            ml.system_reference,
            ml.maintenance_date,
            ml.work_description,
            ml.performed_by,
            ml.cost,
            ml.remarks,
            ml.status,
            ml.created_at,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            poi.item_type,
            poi.item_description,
            c.classification_name,
            COALESCE(curr_o.office_name, base_o.office_name) AS office_name,
            COALESCE(curr_e.first_name, base_e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, base_e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name
        FROM maintenance_logs ml
        LEFT JOIN distribution_item_details did ON did.id = ml.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        ORDER BY ml.created_at DESC, ml.id DESC
    ");
    if ($listStmt) {
        $listStmt->execute();
        $records = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
    }

    foreach ($records as $record) {
        $stats['total']++;
        if (($record['status'] ?? '') === 'cancelled') {
            $stats['cancelled']++;
        } else {
            $stats['posted']++;
            $stats['cost'] += (float) ($record['cost'] ?? 0);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Maintenance Records</div>
                        <div class="fs-4 fw-semibold"><?php echo h((string) $stats['total']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Posted</div>
                        <div class="fs-4 fw-semibold text-success"><?php echo h((string) $stats['posted']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Cancelled</div>
                        <div class="fs-4 fw-semibold text-secondary"><?php echo h((string) $stats['cancelled']); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total Posted Cost</div>
                        <div class="fs-4 fw-semibold"><?php echo h(number_format((float) $stats['cost'], 2)); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0">Add New Maintenance Record</h5>
                    <div class="text-muted small">Record maintenance work performed on distributed property items.</div>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge text-bg-light align-self-center"><?php echo h($referencePreview ?: 'MNT reference pending'); ?></span>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#maintenanceFormCollapse" aria-expanded="<?php echo !empty($errors) ? 'true' : 'false'; ?>" aria-controls="maintenanceFormCollapse">
                        <i class="bi bi-plus-circle me-1"></i>Add New
                    </button>
                </div>
            </div>
            <div class="collapse <?php echo !empty($errors) ? 'show' : ''; ?>" id="maintenanceFormCollapse">
                <div class="card-body p-4">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo h($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>">
                            <?php echo h($flash['message']); ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="maintenance_date" class="form-label">Maintenance Date</label>
                                <input
                                    type="date"
                                    class="form-control"
                                    id="maintenance_date"
                                    name="maintenance_date"
                                    value="<?php echo h($form['maintenance_date']); ?>"
                                    required
                                >
                            </div>

                            <div class="col-md-9">
                                <label for="distribution_item_detail_id" class="form-label">Distributed Item</label>
                                <select class="form-select" id="distribution_item_detail_id" name="distribution_item_detail_id" data-placeholder="Select property number" required>
                                    <option value="">Select property number</option>
                                    <?php foreach ($distributedItems as $item): ?>
                                        <?php
                                        $typeLabel = ($item['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment';
                                        $optionLabel = trim(implode(' | ', array_filter([
                                            $item['property_number'] ?? '',
                                            $typeLabel,
                                            $item['classification_name'] ?? '',
                                            $item['item_description'] ?? '',
                                            !empty($item['office_name']) ? $item['office_name'] : '',
                                            !empty($item['serial_no']) ? 'SN: ' . $item['serial_no'] : '',
                                        ])));
                                        ?>
                                        <option
                                            value="<?php echo (int) $item['id']; ?>"
                                            <?php echo $form['distribution_item_detail_id'] === (string) $item['id'] ? 'selected' : ''; ?>
                                            data-property-number="<?php echo h((string) ($item['property_number'] ?? '')); ?>"
                                            data-item-type="<?php echo h((string) ($item['item_type'] ?? '')); ?>"
                                            data-classification="<?php echo h(trim(implode(' / ', array_filter([
                                                trim((string) ($item['classification_family'] ?? '')),
                                                trim((string) ($item['classification_name'] ?? '')),
                                            ])))); ?>"
                                            data-description="<?php echo h((string) ($item['item_description'] ?? '')); ?>"
                                            data-brand-model="<?php echo h(trim(implode(' / ', array_filter([
                                                trim((string) ($item['brand'] ?? '')),
                                                trim((string) ($item['model'] ?? '')),
                                            ])))); ?>"
                                            data-serial="<?php echo h((string) ($item['serial_no'] ?? '')); ?>"
                                            data-office="<?php echo h((string) ($item['office_name'] ?? '')); ?>"
                                            data-accountable="<?php echo h(trim(implode(' ', array_filter([
                                                trim((string) ($item['first_name'] ?? '')),
                                                trim((string) ($item['middle_name'] ?? '')),
                                                trim((string) ($item['last_name'] ?? '')),
                                                trim((string) ($item['suffix_name'] ?? '')),
                                            ])))); ?>"
                                            data-position="<?php echo h((string) ($item['position_title'] ?? '')); ?>"
                                            data-rc="<?php echo h((string) ($item['rc_code'] ?? '')); ?>"
                                        >
                                            <?php echo h($optionLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="border rounded-3 bg-light-subtle p-3" id="assetPreviewCard">
                                    <div class="small text-muted mb-2">Current Asset Assignment</div>
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <div class="small text-muted">Property Number</div>
                                            <div class="fw-semibold" data-preview="property_number">Select an asset</div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Type / Classification</div>
                                            <div data-preview="type_classification">-</div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Brand / Model / Serial</div>
                                            <div data-preview="brand_model_serial">-</div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="small text-muted">Office / RC</div>
                                            <div data-preview="office_rc">-</div>
                                        </div>
                                        <div class="col-md-12">
                                            <div class="small text-muted">Description / Accountable</div>
                                            <div data-preview="description_accountable">-</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="work_description" class="form-label">Description of Work</label>
                                <textarea class="form-control" id="work_description" name="work_description" rows="3" required><?php echo h($form['work_description']); ?></textarea>
                            </div>

                            <div class="col-md-4">
                                <label for="performed_by" class="form-label">Performed By</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    id="performed_by"
                                    name="performed_by"
                                    value="<?php echo h($form['performed_by']); ?>"
                                >
                            </div>

                            <div class="col-md-3">
                                <label for="cost" class="form-label">Cost</label>
                                <input
                                    type="number"
                                    class="form-control"
                                    id="cost"
                                    name="cost"
                                    step="0.01"
                                    min="0"
                                    value="<?php echo h($form['cost']); ?>"
                                    placeholder="0.00"
                                >
                            </div>

                            <div class="col-md-5">
                                <label for="remarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="2"><?php echo h($form['remarks']); ?></textarea>
                            </div>

                            <div class="col-12 d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>Save Maintenance Record
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Maintenance Log</h5>
                        <span id="recordCount" class="text-muted small">Showing <?php echo count($records); ?> of <?php echo count($records); ?> records</span>
                    </div>
                    <span class="badge text-bg-light"><?php echo count($records); ?> record(s)</span>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search maintenance logs..." style="max-width:300px;">
                    <select id="typeFilter" class="form-select form-select-sm" style="max-width:170px;">
                        <option value="">All asset types</option>
                        <option value="equipment">Equipment</option>
                        <option value="semi_expendable">Semi-Expendable</option>
                    </select>
                    <select id="officeFilter" class="form-select form-select-sm" style="max-width:220px;">
                        <option value="">All offices</option>
                        <?php
                        $seenOffices = [];
                        foreach ($records as $record):
                            $officeName = trim((string) ($record['office_name'] ?? ''));
                            if ($officeName === '' || isset($seenOffices[strtolower($officeName)])) {
                                continue;
                            }
                            $seenOffices[strtolower($officeName)] = true;
                        ?>
                            <option value="<?php echo h($officeName); ?>"><?php echo h($officeName); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="statusFilter" class="form-select form-select-sm" style="max-width:140px;">
                        <option value="">All statuses</option>
                        <option value="posted">Posted</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="clearFilters">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle" id="dataTable">
                        <thead>
                            <tr>
                                <th data-sort="reference">System Reference <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="date">Maintenance Date <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="property">Property Number <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="asset">Asset</th>
                                <th data-sort="description">Description of Work <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="performed">Performed By <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Cost</th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="created">Created At <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($records): ?>
                                <?php foreach ($records as $record): ?>
                                    <tr
                                        data-status="<?php echo h((string) ($record['status'] ?? 'posted')); ?>"
                                        data-item-type="<?php echo h((string) ($record['item_type'] ?? '')); ?>"
                                        data-office="<?php echo h((string) ($record['office_name'] ?? '')); ?>"
                                    >
                                        <td class="fw-semibold"><?php echo h($record['system_reference']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($record['maintenance_date']))); ?></td>
                                        <td><?php echo h($record['property_number'] ?? ''); ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($record['item_description'] ?? ''); ?></div>
                                            <small class="text-muted">
                                                <?php echo h(trim(implode(' | ', array_filter([
                                                    ($record['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment',
                                                    $record['classification_name'] ?? '',
                                                    trim(implode(' / ', array_filter([
                                                        trim((string) ($record['brand'] ?? '')),
                                                        trim((string) ($record['model'] ?? '')),
                                                    ]))),
                                                    !empty($record['serial_no']) ? 'SN: ' . $record['serial_no'] : '',
                                                ])))); ?>
                                            </small>
                                            <div class="small text-muted">
                                                <?php echo h(trim(implode(' | ', array_filter([
                                                    $record['office_name'] ?? '',
                                                    trim(implode(' ', array_filter([
                                                        trim((string) ($record['first_name'] ?? '')),
                                                        trim((string) ($record['middle_name'] ?? '')),
                                                        trim((string) ($record['last_name'] ?? '')),
                                                        trim((string) ($record['suffix_name'] ?? '')),
                                                    ]))),
                                                ])))); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo h($record['work_description']); ?></div>
                                            <?php if (!empty($record['remarks'])): ?>
                                                <small class="text-muted"><?php echo h($record['remarks']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h($record['performed_by'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) ($record['cost'] ?? 0), 2)); ?></td>
                                        <td>
                                            <span class="badge <?php echo ($record['status'] ?? '') === 'cancelled' ? 'text-bg-secondary' : 'text-bg-success'; ?>">
                                                <?php echo h(ucfirst((string) ($record['status'] ?? 'posted'))); ?>
                                            </span>
                                        </td>
                                        <td><?php echo h(date('M d, Y h:i A', strtotime($record['created_at']))); ?></td>
                                        <td class="text-end">
                                            <?php if (($record['status'] ?? '') === 'posted'): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Cancel this maintenance record?');">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="cancel">
                                                    <input type="hidden" name="id" value="<?php echo (int) $record['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                                        <i class="bi bi-x-circle"></i> Cancel
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">No maintenance records found yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button>
                    <span id="pageInfo" class="small text-muted">Page 1 of 1</span>
                    <button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button>
                    <select id="perPageSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var itemSelect = document.getElementById('distribution_item_detail_id');

    function prettifyType(type) {
        if (type === 'semi_expendable') {
            return 'Semi-Expendable';
        }
        if (type === 'equipment') {
            return 'Equipment';
        }
        return type || '-';
    }

    function updateAssetPreview() {
        if (!itemSelect) {
            return;
        }

        var option = itemSelect.options[itemSelect.selectedIndex];
        var propertyNumber = option && option.value ? (option.dataset.propertyNumber || option.text || 'Selected asset') : 'Select an asset';
        var typeLabel = option && option.value ? prettifyType(option.dataset.itemType || '') : '-';
        var classification = option && option.value ? (option.dataset.classification || '-') : '-';
        var description = option && option.value ? (option.dataset.description || '-') : '-';
        var brandModel = option && option.value ? (option.dataset.brandModel || '-') : '-';
        var serial = option && option.value ? (option.dataset.serial || '') : '';
        var office = option && option.value ? (option.dataset.office || '-') : '-';
        var accountable = option && option.value ? (option.dataset.accountable || '-') : '-';
        var position = option && option.value ? (option.dataset.position || '') : '';
        var rc = option && option.value ? (option.dataset.rc || '-') : '-';

        var previewMap = {
            property_number: propertyNumber,
            type_classification: option && option.value ? [typeLabel, classification].filter(Boolean).join(' / ') : '-',
            brand_model_serial: option && option.value ? [brandModel, serial ? 'SN: ' + serial : ''].filter(Boolean).join(' | ') || '-' : '-',
            office_rc: option && option.value ? [office, rc].filter(Boolean).join(' | ') : '-',
            description_accountable: option && option.value ? [description, [accountable, position].filter(Boolean).join(' - ')].filter(Boolean).join(' | ') : '-'
        };

        Object.keys(previewMap).forEach(function (key) {
            var node = document.querySelector('[data-preview="' + key + '"]');
            if (node) {
                node.textContent = previewMap[key] || '-';
            }
        });
    }

    if (window.jQuery && jQuery.fn.select2) {
        jQuery(itemSelect).select2({
            width: '100%',
            placeholder: 'Select property number'
        });
        jQuery(itemSelect).on('change', updateAssetPreview);
    }

    itemSelect?.addEventListener('change', updateAssetPreview);
    updateAssetPreview();

    var perPage = 25;
    var currentPage = 1;
    var sortCol = -1;
    var sortDir = 'asc';

    function getRows() {
        return Array.from(document.querySelectorAll('#dataTable tbody tr'));
    }

    function updateRecordCount(total, overall) {
        var countNode = document.getElementById('recordCount');
        if (countNode) {
            countNode.textContent = 'Showing ' + total + ' of ' + overall + ' records';
        }
    }

    function renderPage() {
        var allRows = getRows();
        var rows = allRows.filter(function(row) { return row.dataset.visible !== '0'; });
        var total = rows.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        currentPage = Math.min(currentPage, pages);
        var start = (currentPage - 1) * perPage;
        var end = start + perPage;

        allRows.forEach(function(row) { row.style.display = 'none'; });
        rows.slice(start, end).forEach(function(row) { row.style.display = ''; });

        updateRecordCount(total, allRows.length);

        var pi = document.getElementById('pageInfo');
        if (pi) {
            pi.textContent = 'Page ' + currentPage + ' of ' + pages + ' (' + total + ' records)';
        }

        var prev = document.getElementById('prevPage');
        var next = document.getElementById('nextPage');
        if (prev) prev.disabled = currentPage <= 1;
        if (next) next.disabled = currentPage >= pages;
    }

    function applyFilters() {
        var term = ((document.getElementById('tableSearch') || {}).value || '').toLowerCase();
        var status = ((document.getElementById('statusFilter') || {}).value || '');
        var itemType = ((document.getElementById('typeFilter') || {}).value || '');
        var office = ((document.getElementById('officeFilter') || {}).value || '').toLowerCase();

        getRows().forEach(function(row) {
            var textMatch = !term || row.textContent.toLowerCase().includes(term);
            var statusMatch = !status || row.dataset.status === status;
            var typeMatch = !itemType || row.dataset.itemType === itemType;
            var officeMatch = !office || (row.dataset.office || '').toLowerCase() === office;
            row.dataset.visible = (textMatch && statusMatch && typeMatch && officeMatch) ? '1' : '0';
        });

        currentPage = 1;
        renderPage();
    }

    document.getElementById('tableSearch')?.addEventListener('input', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('typeFilter')?.addEventListener('change', applyFilters);
    document.getElementById('officeFilter')?.addEventListener('change', applyFilters);
    document.getElementById('clearFilters')?.addEventListener('click', function () {
        var search = document.getElementById('tableSearch');
        var status = document.getElementById('statusFilter');
        var itemType = document.getElementById('typeFilter');
        var office = document.getElementById('officeFilter');
        if (search) search.value = '';
        if (status) status.value = '';
        if (itemType) itemType.value = '';
        if (office) office.value = '';
        applyFilters();
    });
    document.getElementById('prevPage')?.addEventListener('click', function() { currentPage--; renderPage(); });
    document.getElementById('nextPage')?.addEventListener('click', function() { currentPage++; renderPage(); });
    document.getElementById('perPageSelect')?.addEventListener('change', function() {
        perPage = parseInt(this.value, 10) || 25;
        currentPage = 1;
        renderPage();
    });

    document.querySelectorAll('#dataTable th[data-sort]').forEach(function(th, idx) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            var tbody = document.querySelector('#dataTable tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var dir = (sortCol === idx && sortDir === 'asc') ? 'desc' : 'asc';
            sortCol = idx;
            sortDir = dir;

            rows.sort(function(a, b) {
                var at = a.cells[idx] ? a.cells[idx].textContent.trim().toLowerCase() : '';
                var bt = b.cells[idx] ? b.cells[idx].textContent.trim().toLowerCase() : '';
                return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
            });

            rows.forEach(function(row) { tbody.appendChild(row); });
            document.querySelectorAll('#dataTable th[data-sort] i').forEach(function(icon) {
                icon.className = 'bi bi-arrow-down-up text-muted small';
            });
            var icon = th.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-arrow-' + (dir === 'asc' ? 'up' : 'down') + ' text-primary small';
            }
            renderPage();
        });
    });

    applyFilters();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
