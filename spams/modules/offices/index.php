<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function offices_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'offices', $recordId, [
        "SELECT 1 FROM employee_assignments WHERE office_id = ? LIMIT 1",
        "SELECT 1 FROM employees WHERE office_id = ? LIMIT 1",
        "SELECT 1 FROM distributions WHERE office_id = ? LIMIT 1",
    ]);
}

function offices_fetch_merge_row(mysqli $db, int $officeId): ?array
{
    if ($officeId <= 0) {
        return null;
    }

    $stmt = $db->prepare("SELECT id, office_code, office_name, is_active FROM offices WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $officeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function offices_update_reference(mysqli $db, string $table, string $column, int $fromOfficeId, int $toOfficeId, int $userId): int
{
    if (!schema_has_table($db, $table) || !schema_has_column($db, $table, $column)) {
        return 0;
    }

    $safeTable = str_replace('`', '``', $table);
    $safeColumn = str_replace('`', '``', $column);
    $setUpdated = schema_has_column($db, $table, 'updated_by') ? ', `updated_by` = ?' : '';
    $setUpdatedAt = schema_has_column($db, $table, 'updated_at') ? ', `updated_at` = NOW()' : '';
    $stmt = $db->prepare("UPDATE `{$safeTable}` SET `{$safeColumn}` = ?{$setUpdated}{$setUpdatedAt} WHERE `{$safeColumn}` = ?");
    if (!$stmt) {
        return 0;
    }

    if ($setUpdated !== '') {
        $stmt->bind_param('iii', $toOfficeId, $userId, $fromOfficeId);
    } else {
        $stmt->bind_param('ii', $toOfficeId, $fromOfficeId);
    }
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    return max(0, $count);
}

function offices_merge_responsibility_codes(mysqli $db, int $fromOfficeId, int $toOfficeId, int $userId): int
{
    if (!schema_has_table($db, 'responsibility_codes')) {
        return 0;
    }

    $mergedCount = 0;
    $sourceCodes = [];
    $result = $db->query("SELECT id, code FROM responsibility_codes WHERE office_id = " . (int) $fromOfficeId);
    if ($result) {
        $sourceCodes = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    foreach ($sourceCodes as $sourceCode) {
        $sourceRcId = (int) ($sourceCode['id'] ?? 0);
        $code = (string) ($sourceCode['code'] ?? '');
        $targetRcId = 0;
        $targetStmt = $db->prepare("SELECT id FROM responsibility_codes WHERE office_id = ? AND code = ? LIMIT 1");
        if ($targetStmt) {
            $targetStmt->bind_param('is', $toOfficeId, $code);
            $targetStmt->execute();
            $targetRow = $targetStmt->get_result()->fetch_assoc();
            $targetStmt->close();
            $targetRcId = (int) ($targetRow['id'] ?? 0);
        }

        if ($targetRcId > 0) {
            foreach (['employees' => 'responsibility_code_id', 'employee_assignments' => 'responsibility_code_id'] as $table => $column) {
                if (schema_has_table($db, $table) && schema_has_column($db, $table, $column)) {
                    $safeTable = str_replace('`', '``', $table);
                    $safeColumn = str_replace('`', '``', $column);
                    $stmt = $db->prepare("UPDATE `{$safeTable}` SET `{$safeColumn}` = ? WHERE `{$safeColumn}` = ?");
                    if ($stmt) {
                        $stmt->bind_param('ii', $targetRcId, $sourceRcId);
                        $stmt->execute();
                        $mergedCount += max(0, $stmt->affected_rows);
                        $stmt->close();
                    }
                }
            }

            $stmt = $db->prepare("UPDATE responsibility_codes SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $sourceRcId);
                $stmt->execute();
                $stmt->close();
            }
            continue;
        }

        $stmt = $db->prepare("UPDATE responsibility_codes SET office_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('iii', $toOfficeId, $userId, $sourceRcId);
            $stmt->execute();
            $mergedCount += max(0, $stmt->affected_rows);
            $stmt->close();
        }
    }

    return $mergedCount;
}

function offices_merge_location_pin(mysqli $db, int $fromOfficeId, int $toOfficeId, int $userId): int
{
    if (!schema_has_table($db, 'office_location_pins') || !schema_has_column($db, 'office_location_pins', 'office_id')) {
        return 0;
    }

    $targetPin = get_office_location_pin($db, $toOfficeId);
    if ($targetPin) {
        $stmt = $db->prepare("DELETE FROM office_location_pins WHERE office_id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $fromOfficeId);
        $stmt->execute();
        $count = max(0, $stmt->affected_rows);
        $stmt->close();
        return $count;
    }

    return offices_update_reference($db, 'office_location_pins', 'office_id', $fromOfficeId, $toOfficeId, $userId);
}

function offices_merge_into(mysqli $db, int $fromOfficeId, int $toOfficeId, int $userId): array
{
    $changes = [];
    $changes['responsibility_codes'] = offices_merge_responsibility_codes($db, $fromOfficeId, $toOfficeId, $userId);
    $changes['office_location_pins'] = offices_merge_location_pin($db, $fromOfficeId, $toOfficeId, $userId);

    $references = [
        ['users', 'office_id'],
        ['employees', 'office_id'],
        ['employee_assignments', 'office_id'],
        ['purchase_orders', 'office_id'],
        ['issuances', 'office_id'],
        ['distributions', 'office_id'],
        ['legacy_assets', 'office_id'],
        ['distribution_item_details', 'current_office_id'],
        ['returns', 'office_id'],
        ['disposals', 'office_id'],
        ['inventory_count_sessions', 'office_id'],
        ['inventory_count_items', 'office_id'],
        ['asset_transfers', 'from_office_id'],
        ['asset_transfers', 'to_office_id'],
        ['transfer_batches', 'source_office_id'],
        ['transfer_batches', 'to_office_id'],
        ['rpcppe_batches', 'office_id'],
        ['trip_tickets', 'office_id'],
    ];

    foreach ($references as [$table, $column]) {
        $key = $table . '.' . $column;
        $changes[$key] = offices_update_reference($db, $table, $column, $fromOfficeId, $toOfficeId, $userId);
    }

    return $changes;
}

$db = db();
$page_title = 'Offices';
$flash = get_flash();
$errors = [];
$offices = [];
$employees = [];
$employeeAssignmentSummaryMap = [];
$assignmentsEnabled = employee_assignments_enabled($db);
$form = [
    'id' => 0,
    'office_code' => '',
    'office_name' => '',
    'office_head_employee_id' => '',
    'description' => '',
    'is_active' => '1',
];
$unitHeads = [];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'offices');
    $employeeResult = $db->query("SELECT id, first_name, middle_name, last_name, suffix_name, employee_no FROM employees WHERE is_active = 1 ORDER BY last_name, first_name");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }
    if (employee_assignments_enabled($db)) {
        foreach ($employees as $employeeRow) {
            $employeeAssignmentSummaryMap[(int) ($employeeRow['id'] ?? 0)] = employee_assignment_summary(employee_fetch_assignments($db, (int) ($employeeRow['id'] ?? 0), true));
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['office_code'] = strtoupper(old($_POST, 'office_code'));
            $form['office_name'] = old($_POST, 'office_name');
            if (!employee_assignments_enabled($db)) {
                $form['office_head_employee_id'] = old($_POST, 'office_head_employee_id');
            }
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['office_code'] === '') {
                $errors[] = 'Office code is required.';
            }
            if ($form['office_name'] === '') {
                $errors[] = 'Office name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM offices WHERE (office_code = ? OR office_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $officeId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['office_code'], $form['office_name'], $officeId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Office code or office name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $officeHeadId = !employee_assignments_enabled($db) && $form['office_head_employee_id'] !== '' ? (int) $form['office_head_employee_id'] : null;
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $officeId = (int) $form['id'];
                    $auditBefore = audit_fetch_row_snapshot($db, 'offices', $officeId, [
                        'office_code',
                        'office_name',
                        'office_head_employee_id',
                        'description',
                        'is_active',
                    ]);
                    $stmt = employee_assignments_enabled($db)
                        ? $db->prepare("UPDATE offices SET office_code = ?, office_name = ?, department_id = NULL, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                        : $db->prepare("UPDATE offices SET office_code = ?, office_name = ?, department_id = NULL, office_head_employee_id = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        if (employee_assignments_enabled($db)) {
                            $stmt->bind_param('sssiii', $form['office_code'], $form['office_name'], $form['description'], $isActive, $userId, $officeId);
                        } else {
                            $stmt->bind_param('ssisiii', $form['office_code'], $form['office_name'], $officeHeadId, $form['description'], $isActive, $userId, $officeId);
                        }
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            if ($officeHeadId && !employee_ensure_office_assignment($db, $officeHeadId, $officeId, 'Office Head', true, $userId)) {
                                $errors[] = 'Office was updated, but the office head assignment could not be saved.';
                            } else {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'offices',
                                    'record_id' => $officeId,
                                    'module_name' => 'offices',
                                    'record_type' => 'office',
                                    'action_name' => 'update_office',
                                    'description' => 'Updated office record.',
                                    'old_values' => $auditBefore,
                                    'new_values' => [
                                        'office_code' => $form['office_code'],
                                        'office_name' => $form['office_name'],
                                        'office_head_employee_id' => $officeHeadId,
                                        'description' => $form['description'],
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                set_flash('success', 'Office updated successfully.');
                                redirect('modules/offices/index.php');
                            }
                        }
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssisii', $form['office_code'], $form['office_name'], $officeHeadId, $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newOfficeId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            if ($officeHeadId && !employee_ensure_office_assignment($db, $officeHeadId, $newOfficeId, 'Office Head', true, $userId)) {
                                $errors[] = 'Office was created, but the office head assignment could not be saved.';
                            } else {
                                write_audit_log($db, [
                                    'action' => 'insert',
                                    'table_name' => 'offices',
                                    'record_id' => $newOfficeId,
                                    'module_name' => 'offices',
                                    'record_type' => 'office',
                                    'action_name' => 'create_office',
                                    'description' => 'Created office record.',
                                    'new_values' => [
                                        'office_code' => $form['office_code'],
                                        'office_name' => $form['office_name'],
                                        'office_head_employee_id' => $officeHeadId,
                                        'description' => $form['description'],
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                set_flash('success', 'Office created successfully.');
                                redirect('modules/offices/index.php');
                            }
                        }
                    }
                }

                $errors[] = 'Unable to save the office.';
            }
        } elseif ($action === 'delete') {
            $officeId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $auditBefore = audit_fetch_row_snapshot($db, 'offices', $officeId, [
                'office_code',
                'office_name',
                'office_head_employee_id',
                'description',
                'is_active',
            ]);
            $stmt = $db->prepare("UPDATE offices SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $officeId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'offices',
                        'record_id' => $officeId,
                        'module_name' => 'offices',
                        'record_type' => 'office',
                        'action_name' => 'deactivate_office',
                        'description' => 'Deactivated office record.',
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Office deactivated successfully.');
                    redirect('modules/offices/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the office.';
        } elseif ($action === 'reactivate') {
            $officeId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $auditBefore = audit_fetch_row_snapshot($db, 'offices', $officeId, [
                'office_code',
                'office_name',
                'office_head_employee_id',
                'description',
                'is_active',
            ]);
            $stmt = $db->prepare("UPDATE offices SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $officeId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'offices',
                        'record_id' => $officeId,
                        'module_name' => 'offices',
                        'record_type' => 'office',
                        'action_name' => 'reactivate_office',
                        'description' => 'Reactivated office record.',
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success', 'Office reactivated successfully.');
                    redirect('modules/offices/index.php');
                }
            }
            $errors[] = 'Unable to reactivate the office.';
        } elseif ($action === 'merge') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can merge office records.');
                redirect('modules/offices/index.php');
            }

            $fromOfficeId = (int) ($_POST['id'] ?? 0);
            $toOfficeId = (int) ($_POST['target_office_id'] ?? 0);
            $fromOffice = offices_fetch_merge_row($db, $fromOfficeId);
            $toOffice = offices_fetch_merge_row($db, $toOfficeId);

            if (!$fromOffice || !$toOffice || $fromOfficeId <= 0 || $toOfficeId <= 0) {
                set_flash('error', 'Choose a valid source office and target office.');
                redirect('modules/offices/index.php');
            }
            if ($fromOfficeId === $toOfficeId) {
                set_flash('error', 'Choose a different target office for the merge.');
                redirect('modules/offices/index.php');
            }

            $userId = current_user_id();
            $db->begin_transaction();
            try {
                $changes = offices_merge_into($db, $fromOfficeId, $toOfficeId, $userId);
                $auditBefore = audit_fetch_row_snapshot($db, 'offices', $fromOfficeId, [
                    'office_code',
                    'office_name',
                    'office_head_employee_id',
                    'description',
                    'is_active',
                ]);
                $stmt = $db->prepare("UPDATE offices SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if (!$stmt) {
                    throw new RuntimeException('Unable to prepare office deactivation.');
                }
                $stmt->bind_param('ii', $userId, $fromOfficeId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Unable to deactivate source office.');
                }
                $stmt->close();

                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'offices',
                    'record_id' => $fromOfficeId,
                    'module_name' => 'offices',
                    'record_type' => 'office',
                    'action_name' => 'merge_office',
                    'description' => 'Merged office record into another office.',
                    'old_values' => $auditBefore,
                    'new_values' => [
                        'merged_into_office_id' => $toOfficeId,
                        'merged_into_office_code' => $toOffice['office_code'] ?? '',
                        'merged_into_office_name' => $toOffice['office_name'] ?? '',
                        'source_office_code' => $fromOffice['office_code'] ?? '',
                        'source_office_name' => $fromOffice['office_name'] ?? '',
                        'reference_updates' => $changes,
                        'is_active' => 0,
                    ],
                ]);

                $db->commit();
                $moved = array_sum($changes);
                set_flash('success', 'Office merged successfully. ' . $moved . ' reference' . ($moved === 1 ? '' : 's') . ' moved to ' . ($toOffice['office_name'] ?? 'the target office') . '.');
                redirect('modules/offices/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                set_flash('error', 'Unable to merge office records: ' . $e->getMessage());
                redirect('modules/offices/index.php');
            }
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/offices/index.php');
            }

            $officeId = (int) ($_POST['id'] ?? 0);
            if (offices_has_reference($db, $officeId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/offices/index.php');
            }
            $auditSnapshot = ['id' => $officeId];
            $auditStmt = $db->prepare("SELECT office_code, office_name FROM offices WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $officeId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }

            $stmt = $db->prepare("DELETE FROM offices WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $officeId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'offices',
                        'record_id' => $officeId,
                        'module_name' => 'offices',
                        'record_type' => 'office',
                        'action_name' => 'hard_delete_office',
                        'description' => 'Permanently deleted office record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/offices/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the office.';
        }
    }

    if (isset($_GET['edit'])) {
        $officeId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, office_code, office_name, office_head_employee_id, description, is_active FROM offices WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $officeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'office_code' => $record['office_code'],
                    'office_name' => $record['office_name'],
                    'office_head_employee_id' => (string) ($record['office_head_employee_id'] ?? ''),
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $listResult = $db->query("
        SELECT o.id, o.office_code, o.office_name, o.description, o.is_active, o.created_at,
               e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM offices o
        LEFT JOIN employees e ON e.id = o.office_head_employee_id
        ORDER BY o.office_name ASC
    ");
    if ($listResult) {
        $offices = $listResult->fetch_all(MYSQLI_ASSOC);
    }

    if (employee_assignments_enabled($db)) {
        foreach ($offices as $officeRow) {
            $head = employee_resolve_office_head($db, (int) ($officeRow['id'] ?? 0));
            if ($head) {
                $unitHeads[(int) $officeRow['id']] = employee_display_name($head);
            }
        }
    } else {
        $unitHeadWhere = "is_active = 1 AND office_id IS NOT NULL";
        if (schema_has_column($db, 'employees', 'is_unit_head')) {
            $unitHeadWhere .= " AND is_unit_head = 1";
        }
        $unitHeadResult = $db->query("SELECT office_id, first_name, middle_name, last_name, suffix_name FROM employees WHERE {$unitHeadWhere}");
        if ($unitHeadResult) {
            foreach ($unitHeadResult->fetch_all(MYSQLI_ASSOC) as $unitHeadRow) {
                $unitHeads[(int) $unitHeadRow['office_id']] = employee_display_name($unitHeadRow);
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mb-4">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Master Data</div>
                    <h4 class="mb-1">Office Directory</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($offices); ?> of <?php echo count($offices); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($form['id'] > 0): ?>
                        <a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Office'; ?>
                    </button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Office' : 'New Office'; ?></h5>
                            <div class="text-muted small">Maintain office names, short codes, and the assigned office head.</div>
                        </div>
                    </div>
                    
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="master-data-form-layout">
                            <div class="master-data-form-main">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Identity</div>
                                            <h6 class="mb-1">Office Details</h6>
                                            <div class="text-muted small">Use a short code and a full office name that users can recognize quickly in transactions and reports.</div>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Office Code</label>
                                                <input type="text" class="form-control" name="office_code" value="<?php echo h($form['office_code']); ?>" placeholder="Enter office code" required>
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label">Office Name</label>
                                                <input type="text" class="form-control" name="office_name" value="<?php echo h($form['office_name']); ?>" placeholder="Enter full office name" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Description</label>
                                                <textarea class="form-control" name="description" rows="4" placeholder="Optional office notes or scope details"><?php echo h($form['description']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Leadership</div>
                                            <h6 class="mb-1">Office Head Assignment</h6>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <?php if ($assignmentsEnabled): ?>
                                            <div class="master-data-helper mb-0">Office head is derived automatically from the active employee assignment marked <strong>Unit head</strong>.</div>
                                        <?php else: ?>
                                            <div class="master-data-helper mb-3">Assign an employee only when the office has a clear accountable head.</div>
                                            <label class="form-label">Office Head</label>
                                            <select class="form-select" name="office_head_employee_id" data-placeholder="Select employee">
                                                <option value="">Select employee</option>
                                                <?php foreach ($employees as $employee): ?>
                                                    <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['office_head_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                                        <?php echo h(employee_choice_label($employee, $employeeAssignmentSummaryMap[(int) ($employee['id'] ?? 0)] ?? '')); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="master-data-form-actions">
                                    <?php if ($form['id'] > 0): ?>
                                        <a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Office' : 'Save Office'; ?></button>
                                </div>
                            </div>

                            <div class="master-data-form-side">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Status</div>
                                            <h6 class="mb-1">Office Controls</h6>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="master-data-side-list">
                                            <div class="master-data-side-item">
                                                <span>Directory status</span>
                                                <span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mt-3">
                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Active office</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Search</label>
                        <input type="search" id="tableSearch" class="form-control" placeholder="Search office code, office name, office head, or description">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Status</label>
                        <select id="statusFilter" class="form-select">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Rows Per Page</label>
                        <select id="perPageSelect" class="form-select">
                            <option value="25" selected>25 rows</option>
                            <option value="50">50 rows</option>
                            <option value="100">100 rows</option>
                            <option value="250">250 rows</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="master-data-table-shell">
            <div class="table-responsive mobile-table-frame master-data-table-scroll">
                <table class="table align-middle" id="dataTable">
                    <thead>
                        <tr>
                            <th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="head">Office Head <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($offices): foreach ($offices as $office): ?>
                            <?php $displayHead = $unitHeads[(int) $office['id']] ?? trim(employee_display_name($office)); ?>
                            <tr data-status="<?php echo (int) $office['is_active'] ? 'active' : 'inactive'; ?>">
                                <td class="fw-semibold"><?php echo h($office['office_code']); ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo h($office['office_name']); ?></div>
                                    <small class="text-muted"><?php echo h($office['description'] ?? ''); ?></small>
                                </td>
                                <td><?php echo $displayHead !== '' ? h($displayHead) : '<span class="text-muted">Not assigned</span>'; ?></td>
                                <td><span class="badge <?php echo (int) $office['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $office['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                        <a href="<?php echo base_url('modules/offices/index.php?edit=' . (int) $office['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php if ((int) $office['is_active'] === 1): ?>
                                            
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                            </form>
                                        <?php else: ?>
                                            
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="reactivate">
                                                <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                            
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="merge">
                                                <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                <select name="target_office_id" class="form-select form-select-sm" required aria-label="Merge target office">
                                                    <option value="">Merge into...</option>
                                                    <?php foreach ($offices as $targetOffice): ?>
                                                        <?php if ((int) $targetOffice['id'] === (int) $office['id']) continue; ?>
                                                        <option value="<?php echo (int) $targetOffice['id']; ?>"><?php echo h($targetOffice['office_name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-intersect"></i> Merge</button>
                                            </form>
                                            
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="hard_delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No offices found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
</section><script>
document.addEventListener('DOMContentLoaded', function () {
    var recordCountMobile = document.getElementById('recordCountMobile');
    var options = {
        recordCountFormatter: function (visible, total) {
            var text = 'Showing ' + visible + ' of ' + total + ' records';
            if (recordCountMobile) {
                recordCountMobile.textContent = text;
            }
            return text;
        },
        pageInfoFormatter: function (state) {
            return 'Page ' + state.currentPage + ' of ' + state.totalPages + ' (' + state.totalVisible + ' matches)';
        },
        rowMatcher: function (row, filters) {
            var status = (filters.status || '').trim();
            if (status && row.getAttribute('data-status') !== status) {
                return false;
            }

            var term = (filters.term || '').trim();
            if (!term) {
                return true;
            }

            var searchableCells = Array.from(row.cells).slice(0, 4);
            return searchableCells.some(function (cell) {
                return cell.textContent.toLowerCase().indexOf(term) !== -1;
            });
        },
        emptyMessage: 'No offices matched your search or status filter.'
    };

    if (typeof window.initMasterDataList === 'function') {
        window.initMasterDataList('dataTable', options);
        return;
    }
    window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];
    window.__spamsPendingMasterDataLists.push(['dataTable', options]);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


