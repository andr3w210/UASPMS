<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'Assignment Cleanup';
$flash = get_flash();
$errors = [];

function assignment_cleanup_employee_name(array $row): string
{
    return employee_display_name([
        'name_prefix' => $row['name_prefix'] ?? '',
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix_name' => $row['suffix_name'] ?? '',
    ]);
}

function assignment_cleanup_assignment_snapshot(mysqli $db, int $assignmentId): array
{
    $stmt = $db->prepare(
        "SELECT ea.*, o.office_name, rc.code AS responsibility_code,
                e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name
         FROM employee_assignments ea
         LEFT JOIN offices o ON o.id = ea.office_id
         LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
         LEFT JOIN employees e ON e.id = ea.employee_id
         WHERE ea.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $assignmentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function assignment_cleanup_set_primary(mysqli $db, int $assignmentId, int $userId, array &$errors): bool
{
    $assignment = assignment_cleanup_assignment_snapshot($db, $assignmentId);
    if (!$assignment || empty($assignment['employee_id']) || empty($assignment['is_active'])) {
        $errors[] = 'Choose an active assignment to keep as primary.';
        return false;
    }

    $employeeId = (int) $assignment['employee_id'];
    $db->begin_transaction();
    try {
        $clearStmt = $db->prepare("UPDATE employee_assignments SET is_primary = 0, updated_by = ?, updated_at = NOW() WHERE employee_id = ? AND is_active = 1");
        if (!$clearStmt) {
            throw new RuntimeException('Unable to prepare primary cleanup.');
        }
        $clearStmt->bind_param('ii', $userId, $employeeId);
        $clearStmt->execute();
        $clearStmt->close();

        $setStmt = $db->prepare("UPDATE employee_assignments SET is_primary = 1, is_active = 1, end_date = NULL, updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ?");
        if (!$setStmt) {
            throw new RuntimeException('Unable to prepare primary assignment update.');
        }
        $setStmt->bind_param('iii', $userId, $assignmentId, $employeeId);
        $setStmt->execute();
        $setStmt->close();

        employee_sync_legacy_assignment_fields($db, $employeeId);
        write_audit_log($db, [
            'action' => 'update',
            'table_name' => 'employee_assignments',
            'record_id' => $assignmentId,
            'module_name' => 'settings',
            'record_type' => 'employee_assignment',
            'action_name' => 'assignment_cleanup_set_primary',
            'old_values' => $assignment,
            'new_values' => ['employee_id' => $employeeId, 'primary_assignment_id' => $assignmentId],
            'description' => 'Set one active primary assignment for an employee.',
        ]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        $errors[] = $e->getMessage();
        return false;
    }
}

function assignment_cleanup_set_unit_head(mysqli $db, int $assignmentId, int $userId, array &$errors): bool
{
    $assignment = assignment_cleanup_assignment_snapshot($db, $assignmentId);
    if (!$assignment || empty($assignment['employee_id']) || empty($assignment['office_id']) || empty($assignment['is_active'])) {
        $errors[] = 'Choose an active assignment to keep as unit head.';
        return false;
    }

    $employeeId = (int) $assignment['employee_id'];
    $officeId = (int) $assignment['office_id'];
    $db->begin_transaction();
    try {
        $clearStmt = $db->prepare("UPDATE employee_assignments SET is_unit_head = 0, updated_by = ?, updated_at = NOW() WHERE office_id = ? AND is_active = 1");
        if (!$clearStmt) {
            throw new RuntimeException('Unable to prepare unit head cleanup.');
        }
        $clearStmt->bind_param('ii', $userId, $officeId);
        $clearStmt->execute();
        $clearStmt->close();

        $setStmt = $db->prepare("UPDATE employee_assignments SET is_unit_head = 1, is_active = 1, end_date = NULL, updated_by = ?, updated_at = NOW() WHERE id = ? AND employee_id = ? AND office_id = ?");
        if (!$setStmt) {
            throw new RuntimeException('Unable to prepare unit head assignment update.');
        }
        $setStmt->bind_param('iiii', $userId, $assignmentId, $employeeId, $officeId);
        $setStmt->execute();
        $setStmt->close();

        $officeStmt = $db->prepare("UPDATE offices SET office_head_employee_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        if (!$officeStmt) {
            throw new RuntimeException('Unable to prepare office head update.');
        }
        $officeStmt->bind_param('iii', $employeeId, $userId, $officeId);
        $officeStmt->execute();
        $officeStmt->close();

        employee_sync_legacy_assignment_fields($db, $employeeId);
        write_audit_log($db, [
            'action' => 'update',
            'table_name' => 'employee_assignments',
            'record_id' => $assignmentId,
            'module_name' => 'settings',
            'record_type' => 'employee_assignment',
            'action_name' => 'assignment_cleanup_set_unit_head',
            'old_values' => $assignment,
            'new_values' => ['office_id' => $officeId, 'office_head_employee_id' => $employeeId],
            'description' => 'Set one active unit head for an office and synced the office head field.',
        ]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        $errors[] = $e->getMessage();
        return false;
    }
}

function assignment_cleanup_ensure_office_head_assignment(mysqli $db, int $officeId, int $employeeId, int $userId, array &$errors): bool
{
    if ($officeId <= 0 || $employeeId <= 0) {
        $errors[] = 'Choose an office and employee.';
        return false;
    }

    $employeeStmt = $db->prepare("SELECT position_title FROM employees WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$employeeStmt) {
        $errors[] = 'Unable to verify the selected employee.';
        return false;
    }
    $employeeStmt->bind_param('i', $employeeId);
    $employeeStmt->execute();
    $employee = $employeeStmt->get_result()->fetch_assoc() ?: [];
    $employeeStmt->close();
    if (!$employee) {
        $errors[] = 'Selected employee is not active.';
        return false;
    }

    $roleTitle = trim((string) ($employee['position_title'] ?? ''));
    if ($roleTitle === '') {
        $roleTitle = 'Office Head';
    }

    if (!employee_ensure_office_assignment($db, $employeeId, $officeId, $roleTitle, true, $userId)) {
        $errors[] = 'Unable to create or update the employee office assignment.';
        return false;
    }

    $assignmentStmt = $db->prepare("SELECT id FROM employee_assignments WHERE employee_id = ? AND office_id = ? AND is_active = 1 ORDER BY is_unit_head DESC, id ASC LIMIT 1");
    if (!$assignmentStmt) {
        $errors[] = 'Unable to reload the employee assignment.';
        return false;
    }
    $assignmentStmt->bind_param('ii', $employeeId, $officeId);
    $assignmentStmt->execute();
    $assignment = $assignmentStmt->get_result()->fetch_assoc() ?: [];
    $assignmentStmt->close();

    $assignmentId = (int) ($assignment['id'] ?? 0);
    if ($assignmentId <= 0) {
        $errors[] = 'No active assignment was found after saving.';
        return false;
    }

    if (!assignment_cleanup_set_unit_head($db, $assignmentId, $userId, $errors)) {
        return false;
    }

    write_audit_log($db, [
        'action' => 'update',
        'table_name' => 'offices',
        'record_id' => $officeId,
        'module_name' => 'settings',
        'record_type' => 'office',
        'action_name' => 'assignment_cleanup_sync_office_head',
        'new_values' => ['office_id' => $officeId, 'office_head_employee_id' => $employeeId],
        'description' => 'Synced office head with an active unit head assignment.',
    ]);

    return true;
}

function assignment_cleanup_deactivate_assignment(mysqli $db, int $assignmentId, int $userId, array &$errors): bool
{
    $assignment = assignment_cleanup_assignment_snapshot($db, $assignmentId);
    if (!$assignment || empty($assignment['employee_id'])) {
        $errors[] = 'Assignment was not found.';
        return false;
    }

    $employeeId = (int) $assignment['employee_id'];
    $officeId = (int) ($assignment['office_id'] ?? 0);
    $db->begin_transaction();
    try {
        $stmt = $db->prepare("UPDATE employee_assignments SET is_active = 0, is_primary = 0, is_unit_head = 0, end_date = COALESCE(end_date, CURDATE()), updated_by = ?, updated_at = NOW() WHERE id = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare assignment deactivation.');
        }
        $stmt->bind_param('ii', $userId, $assignmentId);
        $stmt->execute();
        $stmt->close();

        if ($officeId > 0) {
            $headStmt = $db->prepare("UPDATE offices SET office_head_employee_id = NULL, updated_by = ?, updated_at = NOW() WHERE id = ? AND office_head_employee_id = ?");
            if ($headStmt) {
                $headStmt->bind_param('iii', $userId, $officeId, $employeeId);
                $headStmt->execute();
                $headStmt->close();
            }
        }

        employee_sync_legacy_assignment_fields($db, $employeeId);
        write_audit_log($db, [
            'action' => 'update',
            'table_name' => 'employee_assignments',
            'record_id' => $assignmentId,
            'module_name' => 'settings',
            'record_type' => 'employee_assignment',
            'action_name' => 'assignment_cleanup_deactivate_assignment',
            'old_values' => $assignment,
            'new_values' => ['is_active' => 0, 'is_primary' => 0, 'is_unit_head' => 0],
            'description' => 'Deactivated an old employee office assignment during cleanup.',
        ]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        $errors[] = $e->getMessage();
        return false;
    }
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} elseif (!employee_assignments_enabled($db)) {
    $errors[] = 'Employee assignments table is not available.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) current_user_id();
        $ok = false;

        if ($action === 'set_primary') {
            $ok = assignment_cleanup_set_primary($db, (int) ($_POST['assignment_id'] ?? 0), $userId, $errors);
        } elseif ($action === 'set_unit_head') {
            $ok = assignment_cleanup_set_unit_head($db, (int) ($_POST['assignment_id'] ?? 0), $userId, $errors);
        } elseif ($action === 'sync_office_head') {
            $ok = assignment_cleanup_ensure_office_head_assignment($db, (int) ($_POST['office_id'] ?? 0), (int) ($_POST['employee_id'] ?? 0), $userId, $errors);
        } elseif ($action === 'deactivate_assignment') {
            $ok = assignment_cleanup_deactivate_assignment($db, (int) ($_POST['assignment_id'] ?? 0), $userId, $errors);
        } else {
            $errors[] = 'Unknown cleanup action.';
        }

        if ($ok) {
            set_flash('success', 'Assignment cleanup action completed.');
            redirect('modules/settings/assignment_cleanup.php');
        }
    }
}

$multiplePrimary = [];
$multipleUnitHeads = [];
$officeHeadIssues = [];
$employees = [];

if ($db && employee_assignments_enabled($db)) {
    $result = $db->query(
        "SELECT ea.id, ea.employee_id, ea.office_id, ea.role_title, ea.is_primary, ea.is_unit_head,
                o.office_name, o.office_code, rc.code AS responsibility_code,
                e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name
         FROM employee_assignments ea
         INNER JOIN (
             SELECT employee_id
             FROM employee_assignments
             WHERE is_active = 1 AND is_primary = 1
             GROUP BY employee_id
             HAVING COUNT(*) > 1
         ) dup ON dup.employee_id = ea.employee_id
         LEFT JOIN offices o ON o.id = ea.office_id
         LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
         LEFT JOIN employees e ON e.id = ea.employee_id
         WHERE ea.is_active = 1 AND ea.is_primary = 1
         ORDER BY e.last_name ASC, e.first_name ASC, o.office_name ASC"
    );
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $multiplePrimary[(int) $row['employee_id']][] = $row;
        }
        $result->free();
    }

    $result = $db->query(
        "SELECT ea.id, ea.employee_id, ea.office_id, ea.role_title, ea.is_primary, ea.is_unit_head,
                o.office_name, o.office_code, rc.code AS responsibility_code,
                e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name
         FROM employee_assignments ea
         INNER JOIN (
             SELECT office_id
             FROM employee_assignments
             WHERE is_active = 1 AND is_unit_head = 1
             GROUP BY office_id
             HAVING COUNT(*) > 1
         ) dup ON dup.office_id = ea.office_id
         LEFT JOIN offices o ON o.id = ea.office_id
         LEFT JOIN responsibility_codes rc ON rc.id = ea.responsibility_code_id
         LEFT JOIN employees e ON e.id = ea.employee_id
         WHERE ea.is_active = 1 AND ea.is_unit_head = 1
         ORDER BY o.office_name ASC, e.last_name ASC, e.first_name ASC"
    );
    if ($result) {
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $multipleUnitHeads[(int) $row['office_id']][] = $row;
        }
        $result->free();
    }

    $result = $db->query(
        "SELECT o.id AS office_id, o.office_name, o.office_code, o.office_head_employee_id,
                head.name_prefix, head.first_name, head.middle_name, head.last_name, head.suffix_name,
                ea.id AS assignment_id, ea.is_unit_head, ea.role_title
         FROM offices o
         INNER JOIN employees head ON head.id = o.office_head_employee_id
         LEFT JOIN employee_assignments ea
           ON ea.office_id = o.id
          AND ea.employee_id = o.office_head_employee_id
          AND ea.is_active = 1
         WHERE o.is_active = 1
           AND o.office_head_employee_id IS NOT NULL
           AND (ea.id IS NULL OR ea.is_unit_head = 0)
         ORDER BY o.office_name ASC"
    );
    if ($result) {
        $officeHeadIssues = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }

    $result = $db->query("SELECT id, name_prefix, first_name, middle_name, last_name, suffix_name, employee_no FROM employees WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC");
    if ($result) {
        $employees = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    }
}

$issueCount = count($multiplePrimary) + count($multipleUnitHeads) + count($officeHeadIssues);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-12 col-xxl-10">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">Master Data Cleanup</div>
                            <h4 class="mb-2">Office and Employee Assignment Cleanup</h4>
                            <p class="text-muted mb-0">Resolve current assignment conflicts while keeping old assignments available as inactive history.</p>
                        </div>
                        <div class="text-end">
                            <div class="fs-4 fw-semibold"><?php echo (int) $issueCount; ?></div>
                            <div class="text-muted small">issue group(s)</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h5 class="mb-1">Employees With Multiple Primary Assignments</h5>
                    <p class="text-muted small mb-3">Keep the employee's current main office as primary. Other active assignments can stay active, but only one should be primary.</p>
                    <?php if (!$multiplePrimary): ?>
                        <div class="alert alert-success mb-0">No multiple-primary issues found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Primary Assignments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($multiplePrimary as $rows): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h(assignment_cleanup_employee_name($rows[0])); ?></td>
                                            <td>
                                                <div class="d-grid gap-2">
                                                    <?php foreach ($rows as $row): ?>
                                                        <div class="border rounded p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                            <div>
                                                                <div class="fw-semibold"><?php echo h($row['office_name'] ?? ''); ?></div>
                                                                <div class="text-muted small"><?php echo h(trim(($row['role_title'] ?? '') . ' ' . (($row['responsibility_code'] ?? '') !== '' ? '| ' . $row['responsibility_code'] : ''))); ?></div>
                                                            </div>
                                                            <form method="post" class="m-0" data-confirm="Set this as the only primary assignment for this employee?">
                                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="set_primary">
                                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-primary">Keep as Primary</button>
                                                            </form>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h5 class="mb-1">Offices With Multiple Unit Heads</h5>
                    <p class="text-muted small mb-3">Choose the current office head. The selected employee will also be saved into the Office Head field.</p>
                    <?php if (!$multipleUnitHeads): ?>
                        <div class="alert alert-success mb-0">No multiple-unit-head issues found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Office</th>
                                        <th>Unit Head Assignments</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($multipleUnitHeads as $rows): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h($rows[0]['office_name'] ?? ''); ?></td>
                                            <td>
                                                <div class="d-grid gap-2">
                                                    <?php foreach ($rows as $row): ?>
                                                        <div class="border rounded p-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                            <div>
                                                                <div class="fw-semibold"><?php echo h(assignment_cleanup_employee_name($row)); ?></div>
                                                                <div class="text-muted small"><?php echo h($row['role_title'] ?? ''); ?></div>
                                                            </div>
                                                            <form method="post" class="m-0" data-confirm="Set this employee as the only active unit head for this office?">
                                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                                <input type="hidden" name="action" value="set_unit_head">
                                                                <input type="hidden" name="assignment_id" value="<?php echo (int) $row['id']; ?>">
                                                                <button type="submit" class="btn btn-sm btn-primary">Keep as Unit Head</button>
                                                            </form>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body p-4">
                    <h5 class="mb-1">Office Head Field Needs Assignment Sync</h5>
                    <p class="text-muted small mb-3">These offices have an Office Head selected, but that employee is missing an active unit-head assignment for the same office.</p>
                    <?php if (!$officeHeadIssues): ?>
                        <div class="alert alert-success mb-0">No office-head sync issues found.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Office</th>
                                        <th>Selected Office Head</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($officeHeadIssues as $row): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($row['office_name'] ?? ''); ?></div>
                                                <div class="text-muted small"><?php echo h($row['office_code'] ?? ''); ?></div>
                                            </td>
                                            <td><?php echo h(assignment_cleanup_employee_name($row)); ?></td>
                                            <td class="text-end">
                                                <form method="post" class="d-inline" data-confirm="Create or update this employee's active unit-head assignment for this office?">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="sync_office_head">
                                                    <input type="hidden" name="office_id" value="<?php echo (int) $row['office_id']; ?>">
                                                    <input type="hidden" name="employee_id" value="<?php echo (int) $row['office_head_employee_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">Sync Assignment</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h5 class="mb-1">Set Current Office Head Manually</h5>
                    <p class="text-muted small mb-3">Use this when an office has changed heads, such as moving Data Privacy from the old head to the new one.</p>
                    <form method="post" class="row g-3 align-items-end" data-confirm="Set this employee as current unit head and office head?">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="sync_office_head">
                        <div class="col-md-5">
                            <label class="form-label">Office</label>
                            <select name="office_id" class="form-select" required>
                                <option value="">Select office</option>
                                <?php
                                $officeList = $db ? ($db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC") ?: false) : false;
                                $offices = $officeList ? $officeList->fetch_all(MYSQLI_ASSOC) : [];
                                ?>
                                <?php foreach ($offices as $office): ?>
                                    <option value="<?php echo (int) $office['id']; ?>"><?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select employee</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo (int) $employee['id']; ?>"><?php echo h(assignment_cleanup_employee_name($employee) . (($employee['employee_no'] ?? '') !== '' ? ' - ' . $employee['employee_no'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">Set Head</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
            </div>
        </div>
    </div>
</section>

<script>
document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!window.confirmAction) {
            form.submit();
            return;
        }
        window.confirmAction({
            title: 'Confirm action',
            message: form.getAttribute('data-confirm'),
            confirmText: 'Confirm',
            onConfirm: function () {
                form.submit();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
