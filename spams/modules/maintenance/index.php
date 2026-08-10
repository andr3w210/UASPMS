<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Maintenance Log';
$flash = get_flash();
$errors = [];
$records = [];
$distributedItems = [];
$repairInventoryItems = [];
$maintenanceHasInventoryLink = false;
$maintenanceHasCompletionColumns = false;
$inventoryStatusResolutionOptions = [
    'found' => 'Found',
    'pending' => 'Pending',
    'missing' => 'Missing',
    'for_disposal' => 'For Disposal',
    'wrong_office' => 'Wrong Office',
    'wrong_accountable' => 'Wrong Accountable',
];
$stats = [
    'total' => 0,
    'posted' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'cost' => 0.00,
];
$form = [
    'maintenance_date' => date('Y-m-d'),
    'distribution_item_detail_id' => '',
    'inventory_count_item_id' => '',
    'work_description' => '',
    'performed_by' => '',
    'cost' => '',
    'remarks' => '',
];
$referencePreview = '';
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);

if (!$db) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['maintenance_date'] = old($_POST, 'maintenance_date', date('Y-m-d'));
        $form['distribution_item_detail_id'] = old($_POST, 'distribution_item_detail_id');
        $form['inventory_count_item_id'] = old($_POST, 'inventory_count_item_id');
        $form['work_description'] = old($_POST, 'work_description');
        $form['performed_by'] = old($_POST, 'performed_by');
        $form['cost'] = old($_POST, 'cost');
        $form['remarks'] = old($_POST, 'remarks');
        $action = trim((string) ($_POST['action'] ?? 'save'));
        if (!is_allowed_value($action, ['save', 'cancel', 'complete'])) {
            $action = 'save';
        }

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        if (empty($errors) && $action === 'cancel') {
            $recordId = (int) ($_POST['id'] ?? 0);

            if ($recordId <= 0) {
                $errors[] = 'Invalid maintenance record.';
            } else {
                $cancelSql = "UPDATE maintenance_logs SET status = 'cancelled' WHERE id = ? AND status = 'posted'";
                if ($maintenanceHasCompletionColumns) {
                    $cancelSql .= ' AND completed_at IS NULL';
                }
                $cancelStmt = $db->prepare($cancelSql);
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

        if (empty($errors) && $action === 'complete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $inventoryStatusTarget = trim((string) ($_POST['inventory_status_target'] ?? ''));

            if (!$maintenanceHasCompletionColumns) {
                $errors[] = 'Database schema is outdated: maintenance completion fields are missing. Apply latest migrations before continuing.';
            }
            if ($recordId <= 0) {
                $errors[] = 'Invalid maintenance record.';
            }
            if ($inventoryStatusTarget !== '' && !array_key_exists($inventoryStatusTarget, $inventoryStatusResolutionOptions)) {
                $errors[] = 'Selected inventory status target is invalid.';
            }

            if (empty($errors)) {
                $recordSelectFields = "ml.id, ml.system_reference, ml.status, ml.completed_at";
                if ($maintenanceHasInventoryLink) {
                    $recordSelectFields .= ', ml.inventory_count_item_id';
                } else {
                    $recordSelectFields .= ', NULL AS inventory_count_item_id';
                }

                $recordLookupStmt = $db->prepare("SELECT {$recordSelectFields} FROM maintenance_logs ml WHERE ml.id = ? LIMIT 1");
                $maintenanceRow = null;
                if ($recordLookupStmt) {
                    $recordLookupStmt->bind_param('i', $recordId);
                    $recordLookupStmt->execute();
                    $maintenanceRow = $recordLookupStmt->get_result()->fetch_assoc() ?: null;
                    $recordLookupStmt->close();
                }

                if (!$maintenanceRow) {
                    $errors[] = 'Maintenance record not found.';
                } elseif (($maintenanceRow['status'] ?? '') !== 'posted') {
                    $errors[] = 'Only posted maintenance records can be marked complete.';
                } elseif (!empty($maintenanceRow['completed_at'])) {
                    $errors[] = 'This maintenance record is already marked complete.';
                } else {
                    $linkedInventoryCountItemId = (int) ($maintenanceRow['inventory_count_item_id'] ?? 0);

                    if ($inventoryStatusTarget !== '') {
                        if ($linkedInventoryCountItemId <= 0) {
                            $errors[] = 'No linked for-repair inventory item is attached to this maintenance record.';
                        } else {
                            $linkedLookupStmt = $db->prepare("\n                                SELECT\n                                    ici.id,\n                                    ici.status,\n                                    ics.status AS session_status\n                                FROM inventory_count_items ici\n                                INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id\n                                WHERE ici.id = ?\n                                LIMIT 1\n                            ");
                            $linkedInventoryRow = null;
                            if ($linkedLookupStmt) {
                                $linkedLookupStmt->bind_param('i', $linkedInventoryCountItemId);
                                $linkedLookupStmt->execute();
                                $linkedInventoryRow = $linkedLookupStmt->get_result()->fetch_assoc() ?: null;
                                $linkedLookupStmt->close();
                            }

                            if (!$linkedInventoryRow) {
                                $errors[] = 'Linked inventory count item was not found.';
                            } elseif (($linkedInventoryRow['session_status'] ?? '') !== 'open') {
                                $errors[] = 'Linked inventory count item can only be updated while its session is open.';
                            } elseif (($linkedInventoryRow['status'] ?? '') !== 'for_repair') {
                                $errors[] = 'Linked inventory count item is no longer tagged as for repair.';
                            }
                        }
                    }

                    if (empty($errors)) {
                        $db->begin_transaction();
                        try {
                            $completedBy = (int) current_user_id();

                            $completeStmt = $db->prepare("\n                                UPDATE maintenance_logs\n                                SET completed_at = NOW(),\n                                    completed_by = ?,\n                                    status = 'posted'\n                                WHERE id = ?\n                                  AND status = 'posted'\n                                  AND completed_at IS NULL\n                            ");
                            if (!$completeStmt) {
                                throw new RuntimeException('Unable to prepare maintenance completion update.');
                            }
                            $completeStmt->bind_param('ii', $completedBy, $recordId);
                            $completeStmt->execute();
                            if ($completeStmt->affected_rows <= 0) {
                                $completeStmt->close();
                                throw new RuntimeException('Maintenance record was not updated.');
                            }
                            $completeStmt->close();

                            if ($inventoryStatusTarget !== '') {
                                $linkedInventoryCountItemId = (int) ($maintenanceRow['inventory_count_item_id'] ?? 0);
                                $inventoryUpdateStmt = $db->prepare("\n                                    UPDATE inventory_count_items\n                                    SET status = ?, checked_at = NOW(), checked_by = ?\n                                    WHERE id = ?\n                                      AND status = 'for_repair'\n                                ");
                                if (!$inventoryUpdateStmt) {
                                    throw new RuntimeException('Unable to prepare linked inventory update.');
                                }
                                $inventoryUpdateStmt->bind_param('sii', $inventoryStatusTarget, $completedBy, $linkedInventoryCountItemId);
                                $inventoryUpdateStmt->execute();
                                if ($inventoryUpdateStmt->affected_rows <= 0) {
                                    $inventoryUpdateStmt->close();
                                    throw new RuntimeException('Linked inventory item could not be updated.');
                                }
                                $inventoryUpdateStmt->close();

                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'inventory_count_items',
                                    'record_id' => $linkedInventoryCountItemId,
                                    'module_name' => 'maintenance',
                                    'record_type' => 'inventory_count_item',
                                    'action_name' => 'resolve_for_repair_from_maintenance',
                                    'old_values' => ['status' => 'for_repair'],
                                    'new_values' => ['status' => $inventoryStatusTarget, 'maintenance_log_id' => $recordId],
                                    'description' => 'Updated linked inventory count item status when maintenance was marked complete.',
                                ]);
                            }

                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'maintenance_logs',
                                'record_id' => $recordId,
                                'module_name' => 'maintenance',
                                'record_type' => 'maintenance',
                                'action_name' => 'complete_maintenance_record',
                                'old_values' => ['completed_at' => null],
                                'new_values' => [
                                    'completed_at' => date('Y-m-d H:i:s'),
                                    'completed_by' => $completedBy,
                                    'linked_inventory_status_target' => $inventoryStatusTarget !== '' ? $inventoryStatusTarget : null,
                                ],
                                'description' => 'Marked maintenance record complete.',
                            ]);

                            $db->commit();
                            if ($inventoryStatusTarget !== '') {
                                set_flash('success', 'Maintenance record marked complete and linked inventory item updated to ' . $inventoryStatusResolutionOptions[$inventoryStatusTarget] . '.');
                            } else {
                                set_flash('success', 'Maintenance record marked complete.');
                            }
                            redirect('modules/maintenance/index.php');
                        } catch (Throwable $e) {
                            $db->rollback();
                            $errors[] = 'Unable to mark the maintenance record complete.';
                        }
                    }
                }
            }
        }

        $detailId = (int) ($form['distribution_item_detail_id'] !== '' ? $form['distribution_item_detail_id'] : 0);
        $inventoryCountItemId = (int) ($form['inventory_count_item_id'] !== '' ? $form['inventory_count_item_id'] : 0);
        $maintenanceDate = trim($form['maintenance_date']);
        $workDescription = trim($form['work_description']);
        $performedBy = trim($form['performed_by']);
        $cost = trim($form['cost']);
        $remarks = trim($form['remarks']);

        if ($action === 'save') {
            if ($maintenanceDate === '') {
                add_validation_error($errors, 'Maintenance date is required.');
            } elseif (!is_valid_date_string($maintenanceDate)) {
                add_validation_error($errors, 'Maintenance date format is invalid.');
            }
            if ($detailId <= 0) {
                add_validation_error($errors, 'Select a distributed item.');
            }
            if ($workDescription === '') {
                add_validation_error($errors, 'Description of work is required.');
            }
        }

        $costValue = null;
        if ($action === 'save' && $cost !== '') {
            if (!is_numeric($cost)) {
                $errors[] = 'Cost must be a valid number.';
            } else {
                $costValue = (float) $cost;
                if ($costValue < 0) {
                    $errors[] = 'Cost cannot be negative.';
                }
            }
        }

        if ($action === 'save' && empty($errors)) {
            $itemCheckStmt = $db->prepare("\n                SELECT id\n                FROM distribution_item_details\n                WHERE id = ?\n                  AND is_distributed = 1\n                  AND (is_disposed IS NULL OR is_disposed = 0)\n                LIMIT 1\n            ");
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

        if ($action === 'save' && $inventoryCountItemId > 0 && empty($errors)) {
            if (!$maintenanceHasInventoryLink) {
                $errors[] = 'Database schema is outdated: maintenance inventory link field is missing. Apply latest migrations before continuing.';
            } else {
                $linkCheckStmt = $db->prepare("\n                    SELECT ici.id\n                    FROM inventory_count_items ici\n                    INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id\n                    WHERE ici.id = ?\n                      AND ici.source_type = 'system'\n                      AND ici.distribution_item_detail_id = ?\n                      AND ici.status = 'for_repair'\n                      AND ics.status = 'open'\n                    LIMIT 1\n                ");
                if ($linkCheckStmt) {
                    $linkCheckStmt->bind_param('ii', $inventoryCountItemId, $detailId);
                    $linkCheckStmt->execute();
                    $linkExists = (bool) $linkCheckStmt->get_result()->fetch_assoc();
                    $linkCheckStmt->close();
                    if (!$linkExists) {
                        $errors[] = 'Selected linked inventory item is invalid or no longer open/for repair for this asset.';
                    }
                }
            }
        }

        if ($action === 'save' && empty($errors)) {
            $systemReference = next_module_code($db, 'maintenance');
            $userId = current_user_id();
            $costToSave = $costValue !== null ? number_format($costValue, 2, '.', '') : null;

            if ($maintenanceHasInventoryLink) {
                $insertStmt = $db->prepare("\n                    INSERT INTO maintenance_logs\n                        (system_reference, maintenance_date, distribution_item_detail_id, inventory_count_item_id, work_description, performed_by, cost, remarks, created_by)\n                    VALUES (?, ?, ?, NULLIF(?, 0), ?, ?, COALESCE(?, 0.00), ?, ?)\n                ");
            } else {
                $insertStmt = $db->prepare("\n                    INSERT INTO maintenance_logs\n                        (system_reference, maintenance_date, distribution_item_detail_id, work_description, performed_by, cost, remarks, created_by)\n                    VALUES (?, ?, ?, ?, ?, COALESCE(?, 0.00), ?, ?)\n                ");
            }

            if ($insertStmt) {
                if ($maintenanceHasInventoryLink) {
                    $insertStmt->bind_param(
                        'ssiissssi',
                        $systemReference,
                        $maintenanceDate,
                        $detailId,
                        $inventoryCountItemId,
                        $workDescription,
                        $performedBy,
                        $costToSave,
                        $remarks,
                        $userId
                    );
                } else {
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
                }
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
                        'inventory_count_item_id' => $maintenanceHasInventoryLink ? ($inventoryCountItemId > 0 ? $inventoryCountItemId : null) : null,
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

    if ($maintenanceHasInventoryLink) {
        $repairLinkStmt = $db->prepare("\n            SELECT\n                ici.id,\n                ici.distribution_item_detail_id,\n                ici.property_number,\n                ici.item_description,\n                ici.status,\n                ics.system_reference AS session_reference,\n                ics.count_date\n            FROM inventory_count_items ici\n            INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id\n            WHERE ici.source_type = 'system'\n              AND ici.status = 'for_repair'\n              AND ici.distribution_item_detail_id IS NOT NULL\n              AND ics.status = 'open'\n            ORDER BY ics.count_date DESC, ici.id DESC\n        ");
        if ($repairLinkStmt) {
            $repairLinkStmt->execute();
            $repairInventoryItems = $repairLinkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $repairLinkStmt->close();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedDetailId > 0) {
        foreach ($distributedItems as $assetRow) {
            if ((int) ($assetRow['id'] ?? 0) === $preselectedDetailId) {
                $form['distribution_item_detail_id'] = (string) $preselectedDetailId;
                break;
            }
        }
    }

    $inventoryLinkSelect = $maintenanceHasInventoryLink
        ? "ml.inventory_count_item_id, ici.status AS inventory_item_status, ics.system_reference AS inventory_session_reference"
        : "NULL AS inventory_count_item_id, NULL AS inventory_item_status, NULL AS inventory_session_reference";
    $completionSelect = $maintenanceHasCompletionColumns
        ? 'ml.completed_at, ml.completed_by'
        : 'NULL AS completed_at, NULL AS completed_by';

    $inventoryLinkJoins = $maintenanceHasInventoryLink
        ? "
        LEFT JOIN inventory_count_items ici ON ici.id = ml.inventory_count_item_id
        LEFT JOIN inventory_count_sessions ics ON ics.id = ici.session_id"
        : '';

    $listStmt = $db->prepare("\n        SELECT\n            ml.id,\n            ml.system_reference,\n            ml.maintenance_date,\n            ml.work_description,\n            ml.performed_by,\n            ml.cost,\n            ml.remarks,\n            ml.status,\n            {$completionSelect},\n            {$inventoryLinkSelect},\n            ml.created_at,\n            did.property_number,\n            did.brand,\n            did.model,\n            did.serial_no,\n            poi.item_type,\n            poi.item_description,\n            c.classification_name,\n            COALESCE(curr_o.office_name, base_o.office_name) AS office_name,\n            COALESCE(curr_e.first_name, base_e.first_name) AS first_name,\n            COALESCE(curr_e.middle_name, base_e.middle_name) AS middle_name,\n            COALESCE(curr_e.last_name, base_e.last_name) AS last_name,\n            COALESCE(curr_e.suffix_name, base_e.suffix_name) AS suffix_name\n        FROM maintenance_logs ml\n        LEFT JOIN distribution_item_details did ON did.id = ml.distribution_item_detail_id\n        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id\n        LEFT JOIN distributions d ON d.id = di.distribution_id\n        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id\n        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id\n        LEFT JOIN classifications c ON c.id = poi.classification_id\n        LEFT JOIN offices base_o ON base_o.id = d.office_id\n        LEFT JOIN employees base_e ON base_e.id = d.employee_id\n        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id\n        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id{$inventoryLinkJoins}\n        ORDER BY ml.created_at DESC, ml.id DESC\n    ");
    if ($listStmt) {
        $listStmt->execute();
        $records = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
    }

    foreach ($records as $record) {
        $stats['total']++;
        if (($record['status'] ?? '') === 'cancelled') {
            $stats['cancelled']++;
        } elseif (!empty($record['completed_at'])) {
            $stats['completed']++;
            $stats['cost'] += (float) ($record['cost'] ?? 0);
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
                        <div class="text-muted small">Completed</div>
                        <div class="fs-4 fw-semibold text-primary"><?php echo h((string) $stats['completed']); ?></div>
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
                        <div class="text-muted small">Total Posted/Completed Cost</div>
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

                            <div class="col-md-12">
                                <label for="inventory_count_item_id" class="form-label">Linked Inventory Count Item (Optional)</label>
                                <select class="form-select" id="inventory_count_item_id" name="inventory_count_item_id" data-placeholder="Select open for-repair item">
                                    <option value="">No linked inventory item</option>
                                    <?php foreach ($repairInventoryItems as $repairItem): ?>
                                        <?php
                                        $repairLabel = trim(implode(' | ', array_filter([
                                            $repairItem['session_reference'] ?? '',
                                            !empty($repairItem['count_date']) ? date('M d, Y', strtotime((string) $repairItem['count_date'])) : '',
                                            $repairItem['property_number'] ?? '',
                                            $repairItem['item_description'] ?? '',
                                        ])));
                                        ?>
                                        <option
                                            value="<?php echo (int) ($repairItem['id'] ?? 0); ?>"
                                            data-detail-id="<?php echo (int) ($repairItem['distribution_item_detail_id'] ?? 0); ?>"
                                            <?php echo $form['inventory_count_item_id'] === (string) ($repairItem['id'] ?? 0) ? 'selected' : ''; ?>
                                        >
                                            <?php echo h($repairLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Shows only open inventory count entries tagged as For Repair for the selected distributed item.</div>
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

                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-5">
                            <label class="form-label">Search</label>
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search maintenance logs...">
                        </div>
                        <div class="col-sm-6 col-lg-2">
                            <label class="form-label">Rows Per Page</label>
                            <select id="perPageSelect" class="form-select">
                                <option value="25" selected>25 rows</option>
                                <option value="50">50 rows</option>
                                <option value="100">100 rows</option>
                                <option value="250">250 rows</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-auto d-flex align-items-end">
                            <button class="btn btn-outline-secondary" type="button" id="clearFilters"><i class="bi bi-arrow-counterclockwise me-1"></i>Clear</button>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label">Asset Type</label>
                            <select id="typeFilter" class="form-select">
                                <option value="">All asset types</option>
                                <option value="equipment">Equipment</option>
                                <option value="semi_expendable">Semi-Expendable</option>
                            </select>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label">Office</label>
                            <select id="officeFilter" class="form-select">
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
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <label class="form-label">Status</label>
                            <select id="statusFilter" class="form-select">
                                <option value="">All statuses</option>
                                <option value="posted">Posted</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="master-data-table-shell">
                <div class="table-responsive mobile-table-frame master-data-table-scroll">
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
                                <th>Linked Inventory Item</th>
                                <th data-sort="created">Created At <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($records): ?>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                    $displayStatus = 'posted';
                                    if (($record['status'] ?? '') === 'cancelled') {
                                        $displayStatus = 'cancelled';
                                    } elseif (!empty($record['completed_at'])) {
                                        $displayStatus = 'completed';
                                    }
                                    ?>
                                    <tr
                                        data-status="<?php echo h($displayStatus); ?>"
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
                                            <span class="badge <?php echo $displayStatus === 'cancelled' ? 'text-bg-secondary' : ($displayStatus === 'completed' ? 'text-bg-primary' : 'text-bg-success'); ?>">
                                                <?php echo h(ucfirst($displayStatus)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($record['inventory_count_item_id'])): ?>
                                                <div class="small fw-semibold">#<?php echo (int) $record['inventory_count_item_id']; ?></div>
                                                <div class="small text-muted"><?php echo h((string) ($record['inventory_session_reference'] ?? 'Inventory session')); ?></div>
                                                <div class="small text-muted">Current status: <?php echo h((string) ($record['inventory_item_status'] ?? 'n/a')); ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small">No link</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo h(date('M d, Y h:i A', strtotime($record['created_at']))); ?></td>
                                        <td class="text-end">
                                            <?php if (($record['status'] ?? '') === 'posted' && empty($record['completed_at'])): ?>
                                                <?php if ($maintenanceHasCompletionColumns): ?>
                                                    <form method="post" class="d-inline-flex align-items-center gap-2 mb-1" onsubmit="return confirm('Mark this maintenance record complete?');">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="complete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $record['id']; ?>">
                                                        <?php if (!empty($record['inventory_count_item_id'])): ?>
                                                            <select name="inventory_status_target" class="form-select form-select-sm" style="min-width: 210px;">
                                                                <option value="">Keep linked item as for_repair</option>
                                                                <?php foreach ($inventoryStatusResolutionOptions as $statusKey => $statusLabel): ?>
                                                                    <option value="<?php echo h($statusKey); ?>"><?php echo h('Set linked item to ' . $statusLabel); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php endif; ?>
                                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                                            <i class="bi bi-check2-circle"></i> Complete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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
                                    <td colspan="11" class="text-center text-muted py-4">No maintenance records found yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>
                <div class="master-data-pagination">
                    <div id="recordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div>
                    <div class="master-data-pagination-controls">
                        <button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button>
                        <span id="pageInfo" class="small text-muted">Page 1 of 1</span>
                        <button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button>
                    </div>
                </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var itemSelect = document.getElementById('distribution_item_detail_id');
    var inventoryCountItemSelect = document.getElementById('inventory_count_item_id');

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

    function updateInventoryLinkOptions() {
        if (!itemSelect || !inventoryCountItemSelect) {
            return;
        }

        var detailId = parseInt(itemSelect.value || '0', 10);
        var hasVisibleOption = false;
        var selectedStillValid = false;

        Array.from(inventoryCountItemSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            var optionDetailId = parseInt(option.dataset.detailId || '0', 10);
            var visible = detailId > 0 && optionDetailId === detailId;
            option.hidden = !visible;
            if (visible) {
                hasVisibleOption = true;
                if (option.selected) {
                    selectedStillValid = true;
                }
            }
        });

        if (!selectedStillValid) {
            inventoryCountItemSelect.value = '';
        }

        inventoryCountItemSelect.disabled = detailId <= 0 || !hasVisibleOption;
    }

    if (window.jQuery && jQuery.fn.select2) {
        jQuery(itemSelect).select2({
            width: '100%',
            placeholder: 'Select property number'
        });
        jQuery(itemSelect).on('change', function () {
            updateAssetPreview();
            updateInventoryLinkOptions();
        });

        if (inventoryCountItemSelect) {
            jQuery(inventoryCountItemSelect).select2({
                width: '100%',
                placeholder: 'Select open for-repair item'
            });
        }
    }

    itemSelect?.addEventListener('change', function () {
        updateAssetPreview();
        updateInventoryLinkOptions();
    });
    updateAssetPreview();
    updateInventoryLinkOptions();

    if (window.jQuery && jQuery.fn.select2 && inventoryCountItemSelect) {
        jQuery(inventoryCountItemSelect).trigger('change.select2');
    }

    initDataTable('dataTable', {
        clearButtonId: 'clearFilters',
        extraFilterIds: ['typeFilter', 'officeFilter', 'statusFilter'],
        recordCountFormatter: function (state) {
            var text = 'Showing ' + state.totalVisible + ' of ' + state.totalOverall + ' records';
            var mob = document.getElementById('recordCountMobile');
            if (mob) mob.textContent = text;
            return text;
        },
        pageInfoFormatter: function (state) {
            return 'Page ' + state.currentPage + ' of ' + state.totalPages + ' (' + state.totalVisible + ' matches)';
        },
        rowFilter: function (row, state) {
            var itemType = state.extraFilters.typeFilter || '';
            var office = (state.extraFilters.officeFilter || '').toLowerCase();
            var status = state.extraFilters.statusFilter || '';
            var typeMatch = !itemType || row.dataset.itemType === itemType;
            var officeMatch = !office || (row.dataset.office || '').toLowerCase() === office;
            var statusMatch = !status || (row.dataset.status || '') === status;
            return typeMatch && officeMatch && statusMatch;
        }
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
