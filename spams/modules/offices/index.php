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

function offices_merge_employee_assignments(mysqli $db, int $fromOfficeId, int $toOfficeId, int $userId, array &$pendingCodes = []): int
{
    if (!employee_assignments_enabled($db)) {
        return 0;
    }

    $headStmt = $db->prepare('SELECT 1 FROM employee_assignments WHERE office_id = ? AND is_active = 1 AND is_unit_head = 1 LIMIT 1');
    if (!$headStmt) {
        throw new RuntimeException('Unable to inspect target office assignments.');
    }
    $headStmt->bind_param('i', $toOfficeId);
    $headStmt->execute();
    $targetHasHead = (bool) $headStmt->get_result()->fetch_assoc();
    $headStmt->close();

    $sourceStmt = $db->prepare('SELECT id, employee_id, responsibility_code_id, role_title, is_unit_head, is_oic, is_primary FROM employee_assignments WHERE office_id = ? AND is_active = 1 ORDER BY id ASC');
    if (!$sourceStmt) {
        throw new RuntimeException('Unable to inspect source office assignments.');
    }
    $sourceStmt->bind_param('i', $fromOfficeId);
    $sourceStmt->execute();
    $sourceAssignments = $sourceStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sourceStmt->close();

    $changed = 0;
    foreach ($sourceAssignments as $source) {
        $sourceId = (int) ($source['id'] ?? 0);
        $employeeId = (int) ($source['employee_id'] ?? 0);
        $roleTitle = (string) ($source['role_title'] ?? '');
        $sourceRcId = (int) ($source['responsibility_code_id'] ?? 0);
        $targetStmt = $db->prepare('SELECT id, is_unit_head, is_oic, is_primary FROM employee_assignments WHERE employee_id = ? AND office_id = ? AND role_title = ? AND is_active = 1 LIMIT 1');
        if (!$targetStmt) {
            throw new RuntimeException('Unable to inspect duplicate employee assignment.');
        }
        $targetStmt->bind_param('iis', $employeeId, $toOfficeId, $roleTitle);
        $targetStmt->execute();
        $target = $targetStmt->get_result()->fetch_assoc() ?: null;
        $targetStmt->close();

        if ($target) {
            $targetId = (int) ($target['id'] ?? 0);
            $targetIsHead = (int) ($target['is_unit_head'] ?? 0) === 1;
            $targetIsPrimary = (int) ($target['is_primary'] ?? 0) === 1;
            $mergedHead = $targetIsHead || (!$targetHasHead && (int) ($source['is_unit_head'] ?? 0) === 1) ? 1 : 0;
            $mergedOic = ((int) ($target['is_oic'] ?? 0) === 1 || (int) ($source['is_oic'] ?? 0) === 1) ? 1 : 0;
            $mergedPrimary = $targetIsPrimary || (int) ($source['is_primary'] ?? 0) === 1 ? 1 : 0;

            $updateTarget = $db->prepare('UPDATE employee_assignments SET is_unit_head = ?, is_oic = ?, is_primary = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if (!$updateTarget) {
                throw new RuntimeException('Unable to consolidate duplicate employee assignment.');
            }
            $updateTarget->bind_param('iiiii', $mergedHead, $mergedOic, $mergedPrimary, $userId, $targetId);
            $updateTarget->execute();
            $updateTarget->close();

            $deactivate = $db->prepare('UPDATE employee_assignments SET is_active = 0, is_primary = 0, is_unit_head = 0, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if (!$deactivate) {
                throw new RuntimeException('Unable to deactivate duplicate employee assignment.');
            }
            $deactivate->bind_param('ii', $userId, $sourceId);
            $deactivate->execute();
            $changed += max(0, $deactivate->affected_rows);
            $deactivate->close();
            if ($mergedHead === 1) {
                $targetHasHead = true;
            }
            continue;
        }

        $targetRcId = 0;
        if ($sourceRcId > 0) {
            $rcStmt = $db->prepare('SELECT rc.code, target.id AS target_id FROM responsibility_codes rc LEFT JOIN responsibility_codes target ON target.office_id = ? AND target.code = rc.code AND target.is_active = 1 WHERE rc.id = ? LIMIT 1');
            if (!$rcStmt) {
                throw new RuntimeException('Unable to inspect assignment responsibility code.');
            }
            $rcStmt->bind_param('ii', $toOfficeId, $sourceRcId);
            $rcStmt->execute();
            $rcRow = $rcStmt->get_result()->fetch_assoc() ?: [];
            $rcStmt->close();
            $targetRcId = (int) ($rcRow['target_id'] ?? 0);
        }

        $sourceIsHead = (int) ($source['is_unit_head'] ?? 0) === 1;
        $newHead = $sourceIsHead && !$targetHasHead ? 1 : 0;
        if ($sourceRcId > 0 && $targetRcId <= 0) {
            $pendingCodes[$sourceId] = $sourceRcId;
        }
        $move = $db->prepare('UPDATE employee_assignments SET office_id = ?, responsibility_code_id = NULLIF(?, 0), is_unit_head = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');
        if (!$move) {
            throw new RuntimeException('Unable to move employee assignment to target office.');
        }
        $move->bind_param('iiiii', $toOfficeId, $targetRcId, $newHead, $userId, $sourceId);
        $move->execute();
        $changed += max(0, $move->affected_rows);
        $move->close();
        if ($newHead === 1) {
            $targetHasHead = true;
        }
    }

    foreach ($sourceAssignments as $source) {
        employee_sync_legacy_assignment_fields($db, (int) ($source['employee_id'] ?? 0));
    }
    employee_sync_office_head_cache($db, $toOfficeId);
    return $changed;
}

function offices_restore_assignment_responsibility_codes(mysqli $db, array $pendingCodes, int $userId): int
{
    $restored = 0;
    foreach ($pendingCodes as $assignmentId => $responsibilityCodeId) {
        $stmt = $db->prepare('UPDATE employee_assignments SET responsibility_code_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to restore assignment responsibility code.');
        }
        $stmt->bind_param('iii', $responsibilityCodeId, $userId, $assignmentId);
        $stmt->execute();
        $restored += max(0, $stmt->affected_rows);
        $stmt->close();
    }
    return $restored;
}

function offices_merge_into(mysqli $db, int $fromOfficeId, int $toOfficeId, int $userId): array
{
    $changes = [];
    $changes['office_location_pins'] = offices_merge_location_pin($db, $fromOfficeId, $toOfficeId, $userId);
    $pendingCodes = [];
    $changes['employee_assignments'] = offices_merge_employee_assignments($db, $fromOfficeId, $toOfficeId, $userId, $pendingCodes);
    $changes['responsibility_codes'] = offices_merge_responsibility_codes($db, $fromOfficeId, $toOfficeId, $userId);
    $changes['assignment_responsibility_codes'] = offices_restore_assignment_responsibility_codes($db, $pendingCodes, $userId);

    $references = [
        ['users', 'office_id'],
        ['employees', 'office_id'],
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
$unitHeads = [];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
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

    $listQ = trim((string) ($_GET['q'] ?? ''));
    $listStatus = (string) ($_GET['status'] ?? 'active');
    $listStatus = in_array($listStatus, ['active', 'inactive', 'all'], true) ? $listStatus : 'active';
    $listWhere = ['1=1'];
    $listTypes = '';
    $listParams = [];
    if ($listStatus !== 'all') {
        $listWhere[] = 'o.is_active = ?';
        $listTypes .= 'i';
        $listParams[] = $listStatus === 'active' ? 1 : 0;
    }
    if ($listQ !== '') {
        $like = '%' . $listQ . '%';
        $listWhere[] = '(o.office_code LIKE ? OR o.office_name LIKE ? OR o.description LIKE ?)';
        $listTypes .= 'sss';
        array_push($listParams, $like, $like, $like);
    }
    $mergeTargets = [];
    $mergeResult = $db->query("SELECT id, office_name FROM offices ORDER BY office_name ASC");
    if ($mergeResult) {
        $mergeTargets = $mergeResult->fetch_all(MYSQLI_ASSOC);
    }
    $listStmt = $db->prepare("
        SELECT o.id, o.office_code, o.office_name, o.description, o.is_active, o.created_at,
               e.first_name, e.middle_name, e.last_name, e.suffix_name,
               (SELECT COUNT(DISTINCT ea.employee_id) FROM employee_assignments ea WHERE ea.office_id = o.id AND ea.is_active = 1) AS employee_count
        FROM offices o
        LEFT JOIN employees e ON e.id = o.office_head_employee_id
        WHERE " . implode(' AND ', $listWhere) . "
        ORDER BY o.office_name ASC
    ");
    if ($listStmt) {
        if ($listTypes !== '') {
            $listStmt->bind_param($listTypes, ...$listParams);
        }
        $listStmt->execute();
        $offices = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
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
<section class="section">
    <div>
        <div>
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

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h4 class="mb-1">Offices</h4>
                    <div class="text-muted"><?php echo count($offices); ?> office<?php echo count($offices) === 1 ? '' : 's'; ?> found</div>
                </div>
                <a href="<?php echo base_url('modules/offices/form.php'); ?>" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Office</a>
            </div>
            <div class="card shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <label class="form-label small mb-1">Search</label>
                        <input type="search" name="q" class="form-control" placeholder="Code, office name, office head, description" value="<?php echo h($listQ); ?>">
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?php echo $listStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $listStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="all" <?php echo $listStatus === 'all' ? 'selected' : ''; ?>>All</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-6 d-flex gap-1">
                        <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i></button>
                        <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/offices/index.php'); ?>" title="Reset"><i class="bi bi-x-lg"></i></a>
                    </div>
                </form>
            </div></div>
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                        <thead>
                            <tr>
                                <th>Office</th>
                                <th>Office Head</th>
                                <th class="text-center">Employees</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$offices): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No offices match the filters.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($offices as $office): $oid = (int) $office['id']; $displayHead = $unitHeads[$oid] ?? trim(employee_display_name($office)); ?>
                            <tr>
                                <td>
                                    <a class="fw-semibold text-reset" href="<?php echo base_url('modules/offices/view.php?id=' . $oid); ?>"><?php echo h($office['office_name']); ?></a>
                                    <div class="text-muted small"><?php echo h($office['office_code']); ?><?php echo !empty($office['description']) ? ' · ' . h($office['description']) : ''; ?></div>
                                </td>
                                <td><?php echo $displayHead !== '' ? h($displayHead) : '<span class="text-muted">Not assigned</span>'; ?></td>
                                <td class="text-center"><?php echo (int) ($office['employee_count'] ?? 0); ?></td>
                                <td><span class="badge <?php echo (int) $office['is_active'] === 1 ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo (int) $office['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/offices/view.php?id=' . $oid); ?>"><i class="bi bi-building me-2"></i>View Profile</a></li>
                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/offices/form.php?id=' . $oid); ?>"><i class="bi bi-pencil-square me-2"></i>Edit</a></li>
                                            <li>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="<?php echo (int) $office['is_active'] === 1 ? 'delete' : 'reactivate'; ?>">
                                                    <input type="hidden" name="id" value="<?php echo $oid; ?>">
                                                    <button type="submit" class="dropdown-item"><i class="bi bi-<?php echo (int) $office['is_active'] === 1 ? 'slash-circle' : 'arrow-counterclockwise'; ?> me-2"></i><?php echo (int) $office['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?></button>
                                                </form>
                                            </li>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mergeModal" data-office-id="<?php echo $oid; ?>" data-office-name="<?php echo h($office['office_name']); ?>"><i class="bi bi-intersect me-2"></i>Merge into…</button></li>
                                                <li>
                                                    <form method="post" class="m-0" onsubmit="return confirm('Permanently delete this office? This cannot be undone.');">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="hard_delete">
                                                        <input type="hidden" name="id" value="<?php echo $oid; ?>">
                                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3 me-2"></i>Delete permanently</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
            <div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog"><form method="post" class="modal-content">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="id" id="mergeOfficeId" value="">
                    <div class="modal-header"><h5 class="modal-title">Merge office</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p>Merge <strong id="mergeOfficeName"></strong> into another office. Employees, transactions and responsibility codes move to the target.</p>
                        <label class="form-label">Target office</label>
                        <select name="target_office_id" class="form-select" required data-no-select2>
                            <option value="">Select target office</option>
                            <?php foreach ($mergeTargets as $targetOffice): ?>
                                <option value="<?php echo (int) $targetOffice['id']; ?>"><?php echo h($targetOffice['office_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Merge</button></div>
                </form></div>
            </div>
            <script>
            document.getElementById('mergeModal').addEventListener('show.bs.modal', function (e) {
                var b = e.relatedTarget;
                document.getElementById('mergeOfficeId').value = b.getAttribute('data-office-id');
                document.getElementById('mergeOfficeName').textContent = b.getAttribute('data-office-name');
                var sel = this.querySelector('select[name=target_office_id]');
                sel.value = '';
                Array.prototype.forEach.call(sel.options, function (o) { o.hidden = o.value === b.getAttribute('data-office-id'); });
            });
            </script>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


