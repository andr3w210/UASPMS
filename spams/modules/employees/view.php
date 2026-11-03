<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_login();
require_role('Administrator', 'Transport Officer');

$db = db();
$employeeId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isAdministrator = current_user_role() === 'Administrator';
$errors = [];

function employee_load(mysqli $db, int $id): array
{
    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

$employee = $employeeId > 0 ? employee_load($db, $employeeId) : [];
if (!$employee) {
    set_flash('error', 'Employee not found.');
    redirect('modules/employees/index.php');
}
$selfUrl = 'modules/employees/view.php?id=' . $employeeId;
$activeTab = in_array($_GET['tab'] ?? 'overview', ['overview', 'designations', 'property'], true) ? (string) ($_GET['tab'] ?? 'overview') : 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $designationRules = [
        'id' => ['type' => 'int', 'min' => 1, 'required' => true],
        'assignment_id' => ['type' => 'int', 'min' => 0],
        'office_id' => ['type' => 'int', 'min' => 1, 'required' => true],
        'responsibility_code_id' => ['type' => 'int', 'min' => 0],
        'role_title' => ['type' => 'string', 'max' => 150, 'required' => true],
        'is_unit_head' => ['type' => 'bool'],
        'is_oic' => ['type' => 'bool'],
        'is_primary' => ['type' => 'bool'],
        'start_date' => ['type' => 'date'],
        'end_date' => ['type' => 'date'],
    ];
    $assignmentOnly = [
        'id' => ['type' => 'int', 'min' => 1, 'required' => true],
        'assignment_id' => ['type' => 'int', 'min' => 1, 'required' => true],
    ];
    request_schema_enforce([
        'save_designation' => $designationRules,
        'end_designation' => $assignmentOnly,
        'set_primary' => $assignmentOnly,
        'create_user_account' => ['id' => ['type' => 'int', 'min' => 1, 'required' => true]],
        'merge' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
            'target_employee_id' => ['type' => 'int', 'min' => 1, 'required' => true],
        ],
        'hard_delete' => ['id' => ['type' => 'int', 'min' => 1, 'required' => true]],
    ], 'action', false, 'save_designation');

    $action = (string) ($_POST['action'] ?? '');
    $userId = current_user_id();
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (in_array($action, ['create_user_account', 'merge', 'hard_delete'], true) && !$isAdministrator) {
        set_flash('error', 'Only administrators can do that.');
        redirect($selfUrl);
    } elseif ($action === 'save_designation') {
        $errors = employees_save_designation($db, $employeeId, $_POST, $userId);
        if (!$errors) {
            set_flash('success', 'Designation saved.');
            redirect($selfUrl . '&tab=designations');
        }
        $activeTab = 'designations';
    } elseif ($action === 'end_designation') {
        $ok = employees_end_designation($db, $employeeId, (int) $_POST['assignment_id'], $userId);
        set_flash($ok ? 'success' : 'error', $ok ? 'Designation ended and moved to history.' : 'Unable to end the designation.');
        redirect($selfUrl . '&tab=designations');
    } elseif ($action === 'set_primary') {
        $ok = employees_set_primary_designation($db, $employeeId, (int) $_POST['assignment_id'], $userId);
        set_flash($ok ? 'success' : 'error', $ok ? 'Primary designation updated.' : 'Unable to set the primary designation.');
        redirect($selfUrl . '&tab=designations');
    } elseif ($action === 'merge') {
        $target = (int) $_POST['target_employee_id'];
        if ($target === $employeeId || !employee_load($db, $target)) {
            set_flash('error', 'Choose a different, existing employee to merge into.');
            redirect($selfUrl);
        }
        if (employees_merge_records($db, $employeeId, $target, $userId)) {
            set_flash('success', 'Records merged. Designations and references were moved to this employee.');
            redirect('modules/employees/view.php?id=' . $target);
        }
        set_flash('error', 'Unable to merge employee records.');
        redirect($selfUrl);
    } elseif ($action === 'create_user_account') {
        $employeeRow = $employee;
        $exists = $db->prepare("SELECT username FROM users WHERE employee_id = ? LIMIT 1");
        $exists->bind_param('i', $employeeId);
        $exists->execute();
        $existingUser = $exists->get_result()->fetch_assoc();
        $exists->close();
        $roleId = rbac_employee_account_role_id($db);
        $email = trim((string) ($employee['email'] ?? ''));
        $emailTaken = false;
        if ($email !== '') {
            $es = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $es->bind_param('s', $email);
            $es->execute();
            $emailTaken = (bool) $es->get_result()->fetch_assoc();
            $es->close();
        }
        if (!$employee['is_active']) {
            $errors[] = 'Only active employees can be given accounts.';
        } elseif ($existingUser) {
            $errors[] = 'This employee already has an account: ' . $existingUser['username'];
        } elseif ($roleId <= 0) {
            $errors[] = 'No active employee login role is available. Create the Employee role in Users first.';
        } elseif ($emailTaken) {
            $errors[] = 'The employee email is already linked to another user.';
        } else {
            $primary = employee_fetch_primary_assignment($db, $employeeId);
            $officeId = !empty($primary['office_id']) ? (int) $primary['office_id'] : (!empty($employee['office_id']) ? (int) $employee['office_id'] : null);
            $username = employees_unique_username($db, employees_default_username($employee));
            $password = employees_generate_initial_password();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $fullName = employee_display_name($employee);
            $one = 1;
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, employee_id, office_id, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssiiiii', $username, $email, $hash, $fullName, $roleId, $employeeId, $officeId, $one, $one);
            $saved = $stmt->execute();
            $newUserId = (int) $stmt->insert_id;
            $stmt->close();
            if ($saved) {
                write_audit_log($db, ['action' => 'insert', 'table_name' => 'users', 'record_id' => $newUserId, 'module_name' => 'employees', 'record_type' => 'user', 'action_name' => 'create_user_from_employee', 'description' => 'Created user account from employee record.', 'new_values' => ['username' => $username, 'full_name' => $fullName, 'email' => $email, 'role_id' => $roleId, 'employee_id' => $employeeId, 'office_id' => $officeId]]);
                $_SESSION['employee_account_modal'] = ['name' => $fullName, 'username' => $username, 'password' => $password];
                set_flash('success', 'User account created. The employee must change the temporary password on first login.');
                redirect($selfUrl);
            }
            $errors[] = 'Unable to create the user account.';
        }
    } elseif ($action === 'hard_delete') {
        if (employees_has_reference($db, $employeeId)) {
            set_flash('error', 'Cannot delete: the employee is used in existing records. Deactivate instead.');
            redirect($selfUrl);
        }
        $snapshot = employees_audit_snapshot($db, $employeeId);
        $db->begin_transaction();
        try {
            employees_run_update($db, "DELETE FROM employee_assignments WHERE employee_id = ?", 'i', [$employeeId], 'Unable to delete designations');
            employees_run_update($db, "DELETE FROM employees WHERE id = ? LIMIT 1", 'i', [$employeeId], 'Unable to delete employee');
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            set_flash('error', 'Unable to permanently delete the employee.');
            redirect($selfUrl);
        }
        if (!empty($snapshot['photo_path'])) {
            delete_uploaded_file((string) $snapshot['photo_path']);
        }
        write_audit_log($db, ['action' => 'delete', 'table_name' => 'employees', 'record_id' => $employeeId, 'module_name' => 'employees', 'record_type' => 'employee', 'action_name' => 'hard_delete_employee', 'description' => 'Permanently deleted employee record.', 'old_values' => $snapshot]);
        set_flash('success', 'Employee permanently deleted.');
        redirect('modules/employees/index.php');
    }
}

$employee = employee_load($db, $employeeId);
$page_title = employee_display_name($employee);
$flash = get_flash();
$accountModal = $_SESSION['employee_account_modal'] ?? null;
unset($_SESSION['employee_account_modal']);

$assignments = employees_fetch_all_assignments($db, $employeeId);
$activeAssignments = array_values(array_filter($assignments, static fn($a) => (int) $a['is_active'] === 1));
$pastAssignments = array_values(array_filter($assignments, static fn($a) => (int) $a['is_active'] !== 1));
$documents = employees_fetch_accountability($db, $employeeId);
$offices = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC")->fetch_all(MYSQLI_ASSOC);
$codes = $db->query("SELECT id, office_id, code FROM responsibility_codes WHERE is_active = 1 ORDER BY code ASC")->fetch_all(MYSQLI_ASSOC);
$titles = array_column($db->query("SELECT DISTINCT role_title FROM employee_assignments WHERE role_title <> '' ORDER BY role_title ASC")->fetch_all(MYSQLI_ASSOC), 'role_title');
$mergeTargets = $isAdministrator ? $db->query("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 AND id <> " . (int) $employeeId . " ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC) : [];
$linkedUser = '';
$us = $db->prepare("SELECT username FROM users WHERE employee_id = ? LIMIT 1");
$us->bind_param('i', $employeeId);
$us->execute();
$linkedUser = (string) ($us->get_result()->fetch_assoc()['username'] ?? '');
$us->close();

function employee_field(string $label, ?string $value): string
{
    $value = trim((string) $value);
    return '<div class="col-sm-6"><div class="text-muted small">' . h($label) . '</div><div>' . ($value !== '' ? h($value) : '<span class="text-muted">—</span>') . '</div></div>';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <?php echo employees_avatar_html($employee, 96); ?>
                <div class="flex-fill">
                    <h3 class="mb-1"><?php echo h(employee_display_name($employee)); ?></h3>
                    <div class="text-muted mb-2"><?php echo h($employee['employee_no']); ?><?php echo $employee['employment_status'] !== '' ? ' · ' . h($employee['employment_status']) : ''; ?></div>
                    <span class="badge <?php echo $employee['is_active'] ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?></span>
                    <?php if (!empty($employee['is_driver'])): ?><span class="badge bg-azure-lt">Driver</span><?php endif; ?>
                    <?php foreach ($activeAssignments as $a): ?>
                        <span class="badge bg-blue-lt"><?php echo h(employee_format_title_with_oic((string) $a['role_title'], !empty($a['is_oic']))); ?></span>
                    <?php endforeach; ?>
                    <div class="mt-2 small text-muted">
                        <?php if ($employee['contact_no']): ?><i class="bi bi-telephone me-1"></i><?php echo h($employee['contact_no']); ?> &nbsp;<?php endif; ?>
                        <?php if ($employee['email']): ?><i class="bi bi-envelope me-1"></i><?php echo h($employee['email']); ?><?php endif; ?>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?php echo base_url('modules/employees/form.php?id=' . $employeeId); ?>" class="btn btn-primary"><i class="bi bi-pencil-square me-1"></i>Edit</a>
                    <a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Back</a>
                    <?php if ($isAdministrator): ?>
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button">Admin</button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php if ($linkedUser === '' && $employee['is_active']): ?>
                            <li>
                                <form method="post" class="m-0" onsubmit="return confirm('Create a login account for this employee?');">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="create_user_account"><input type="hidden" name="id" value="<?php echo $employeeId; ?>">
                                    <button class="dropdown-item" type="submit"><i class="bi bi-key me-2"></i>Create user account</button>
                                </form>
                            </li>
                            <?php endif; ?>
                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#mergeModal"><i class="bi bi-intersect me-2"></i>Merge into another…</button></li>
                            <li>
                                <form method="post" class="m-0" onsubmit="return confirm('Permanently delete this employee? This cannot be undone.');">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo $employeeId; ?>">
                                    <button class="dropdown-item text-danger" type="submit"><i class="bi bi-trash me-2"></i>Delete permanently</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?php echo $activeTab === 'overview' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tab-overview">Overview</a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeTab === 'designations' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tab-designations">Designations <span class="badge bg-blue-lt"><?php echo count($activeAssignments); ?></span></a></li>
        <li class="nav-item"><a class="nav-link <?php echo $activeTab === 'property' ? 'active' : ''; ?>" data-bs-toggle="tab" href="#tab-property">Property Accountability <span class="badge bg-blue-lt"><?php echo count($documents); ?></span></a></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade <?php echo $activeTab === 'overview' ? 'show active' : ''; ?>" id="tab-overview">
            <div class="row g-3">
                <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header"><h5 class="card-title mb-0">Personal</h5></div><div class="card-body"><div class="row g-3">
                    <?php echo employee_field('Sex', $employee['sex']); ?>
                    <?php echo employee_field('Birth date', $employee['birth_date'] ? format_date($employee['birth_date']) : ''); ?>
                    <?php echo employee_field('Address', $employee['address']); ?>
                    <?php echo employee_field('Emergency contact', trim(($employee['emergency_contact_name'] ?? '') . ' ' . ($employee['emergency_contact_no'] ?? ''))); ?>
                </div></div></div></div>
                <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header"><h5 class="card-title mb-0">Employment</h5></div><div class="card-body"><div class="row g-3">
                    <?php echo employee_field('Employee No.', $employee['employee_no']); ?>
                    <?php echo employee_field('Status', $employee['employment_status']); ?>
                    <?php echo employee_field('Date hired', $employee['date_hired'] ? format_date($employee['date_hired']) : ''); ?>
                    <?php echo employee_field('User account', $linkedUser); ?>
                </div></div></div></div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'designations' ? 'show active' : ''; ?>" id="tab-designations">
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Current designations</h5>
                    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#designationModal" data-assignment='{}'><i class="bi bi-plus-lg me-1"></i>Add designation</button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" data-no-table-search="true">
                        <thead><tr><th>Designation</th><th>Office</th><th>Period</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        <?php if (!$activeAssignments): ?><tr><td colspan="4" class="text-center text-muted py-3">No active designation.</td></tr><?php endif; ?>
                        <?php foreach ($activeAssignments as $a): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo h($a['role_title']); ?></div>
                                    <?php if ($a['is_primary']): ?><span class="badge bg-primary-lt">Primary</span><?php endif; ?>
                                    <?php if ($a['is_unit_head']): ?><span class="badge bg-green-lt">Unit Head</span><?php endif; ?>
                                    <?php if ($a['is_oic']): ?><span class="badge bg-orange-lt">OIC</span><?php endif; ?>
                                </td>
                                <td><?php echo h($a['office_name']); ?><?php echo $a['responsibility_code'] ? '<div class="text-muted small">RC ' . h($a['responsibility_code']) . '</div>' : ''; ?></td>
                                <td class="small"><?php echo $a['start_date'] ? h(format_date($a['start_date'])) : '—'; ?> – <?php echo $a['end_date'] ? h(format_date($a['end_date'])) : 'present'; ?></td>
                                <td class="text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button">Actions</button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#designationModal" data-assignment="<?php echo h(json_encode($a, JSON_HEX_APOS | JSON_HEX_QUOT)); ?>"><i class="bi bi-pencil me-2"></i>Edit</button></li>
                                            <?php if (!$a['is_primary']): ?>
                                            <li><form method="post" class="m-0"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="set_primary"><input type="hidden" name="id" value="<?php echo $employeeId; ?>"><input type="hidden" name="assignment_id" value="<?php echo (int) $a['id']; ?>"><button class="dropdown-item" type="submit"><i class="bi bi-star me-2"></i>Make primary</button></form></li>
                                            <?php endif; ?>
                                            <li><form method="post" class="m-0" onsubmit="return confirm('End this designation? It will move to history.');"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="end_designation"><input type="hidden" name="id" value="<?php echo $employeeId; ?>"><input type="hidden" name="assignment_id" value="<?php echo (int) $a['id']; ?>"><button class="dropdown-item text-danger" type="submit"><i class="bi bi-x-circle me-2"></i>End designation</button></form></li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($pastAssignments): ?>
            <div class="card shadow-sm">
                <div class="card-header"><h5 class="card-title mb-0">History</h5></div>
                <div class="table-responsive"><table class="table mb-0" data-no-table-search="true">
                    <thead><tr><th>Designation</th><th>Office</th><th>Period</th></tr></thead>
                    <tbody>
                    <?php foreach ($pastAssignments as $a): ?>
                        <tr class="text-muted"><td><?php echo h($a['role_title']); ?></td><td><?php echo h($a['office_name']); ?></td><td class="small"><?php echo $a['start_date'] ? h(format_date($a['start_date'])) : '—'; ?> – <?php echo $a['end_date'] ? h(format_date($a['end_date'])) : 'ended'; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="tab-pane fade <?php echo $activeTab === 'property' ? 'show active' : ''; ?>" id="tab-property">
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Document</th><th>Date</th><th>Office</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if (!$documents): ?><tr><td colspan="5" class="text-center text-muted py-3">No PAR or ICS issued to this employee.</td></tr><?php endif; ?>
                        <?php foreach ($documents as $d): ?>
                            <tr>
                                <td><a href="<?php echo base_url('modules/distributions/' . ($d['document_type'] === 'par' ? 'par' : 'ics') . '.php?id=' . (int) $d['id']); ?>"><span class="badge bg-blue-lt"><?php echo h(strtoupper($d['document_type'])); ?></span> <?php echo h($d['document_no']); ?></a></td>
                                <td><?php echo h(format_date($d['distribution_date'])); ?></td>
                                <td><?php echo h($d['office_name']); ?></td>
                                <td class="text-end"><?php echo number_format((float) $d['total_amount'], 2); ?></td>
                                <td><span class="badge <?php echo $d['status'] === 'posted' ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo h(ucfirst($d['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="designationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_designation">
            <input type="hidden" name="id" value="<?php echo $employeeId; ?>">
            <input type="hidden" name="assignment_id" id="d_assignment_id" value="0">
            <div class="modal-header"><h5 class="modal-title" id="d_title">Add designation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Office <span class="text-danger">*</span></label>
                        <select name="office_id" id="d_office" class="form-select" required>
                            <option value="">Select office</option>
                            <?php foreach ($offices as $o): ?><option value="<?php echo (int) $o['id']; ?>"><?php echo h($o['office_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Designation <span class="text-danger">*</span></label>
                        <input name="role_title" id="d_role" class="form-control" list="d_titles" maxlength="150" required>
                        <datalist id="d_titles"><?php foreach ($titles as $t): ?><option value="<?php echo h($t); ?>"></option><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Responsibility code</label>
                        <select name="responsibility_code_id" id="d_rc" class="form-select">
                            <option value="0">None</option>
                            <?php foreach ($codes as $c): ?><option value="<?php echo (int) $c['id']; ?>" data-office="<?php echo (int) $c['office_id']; ?>"><?php echo h($c['code']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Start date</label><input type="date" name="start_date" id="d_start" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label">End date</label><input type="date" name="end_date" id="d_end" class="form-control"></div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_primary" value="1" id="d_primary"><span class="form-check-label">Primary designation</span></label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_unit_head" value="1" id="d_head"><span class="form-check-label">Unit head</span></label>
                        <label class="form-check"><input class="form-check-input" type="checkbox" name="is_oic" value="1" id="d_oic"><span class="form-check-label">OIC</span></label>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save designation</button></div>
        </form>
    </div>
</div>

<?php if ($isAdministrator): ?>
<div class="modal fade" id="mergeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content" onsubmit="return confirm('Merge this record into the selected employee? This record will be deactivated.');">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="action" value="merge">
            <input type="hidden" name="id" value="<?php echo $employeeId; ?>">
            <div class="modal-header"><h5 class="modal-title">Merge duplicate</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="text-muted">Treat <strong><?php echo h(employee_display_name($employee)); ?></strong> as the duplicate. Its designations and records move to the employee selected below, and this record is deactivated.</p>
                <label class="form-label">Keep this employee</label>
                <select name="target_employee_id" class="form-select" required>
                    <option value="">Select employee</option>
                    <?php foreach ($mergeTargets as $m): ?><option value="<?php echo (int) $m['id']; ?>"><?php echo h(employee_display_name($m) . ' — ' . $m['employee_no']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-danger">Merge</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (is_array($accountModal) && !empty($accountModal['username'])): ?>
<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title">Employee account created</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="text-muted">Give these login details to <?php echo h((string) $accountModal['name']); ?>. The password is temporary.</p>
            <div class="mb-3"><label class="form-label">Username</label><input class="form-control" readonly value="<?php echo h($accountModal['username']); ?>"></div>
            <div><label class="form-label">Temporary password</label><input class="form-control" readonly value="<?php echo h($accountModal['password']); ?>"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button></div>
    </div></div>
</div>
<script>document.addEventListener('DOMContentLoaded', function () { new bootstrap.Modal(document.getElementById('accountModal')).show(); });</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('designationModal');
    var office = document.getElementById('d_office');
    var rc = document.getElementById('d_rc');
    function filterCodes() {
        Array.prototype.forEach.call(rc.options, function (opt) {
            var show = opt.value === '0' || opt.getAttribute('data-office') === office.value;
            opt.hidden = !show;
            if (!show && opt.selected) { rc.value = '0'; }
        });
    }
    office.addEventListener('change', filterCodes);
    modal.addEventListener('show.bs.modal', function (event) {
        var data = {};
        try { data = JSON.parse(event.relatedTarget.getAttribute('data-assignment') || '{}'); } catch (e) {}
        var editing = !!data.id;
        document.getElementById('d_title').textContent = editing ? 'Edit designation' : 'Add designation';
        document.getElementById('d_assignment_id').value = editing ? data.id : 0;
        office.value = editing ? data.office_id : '';
        filterCodes();
        rc.value = editing && data.responsibility_code_id ? data.responsibility_code_id : '0';
        document.getElementById('d_role').value = editing ? data.role_title : '';
        document.getElementById('d_start').value = editing && data.start_date ? data.start_date : '';
        document.getElementById('d_end').value = editing && data.end_date ? data.end_date : '';
        document.getElementById('d_primary').checked = editing ? data.is_primary == 1 : <?php echo $activeAssignments ? 'false' : 'true'; ?>;
        document.getElementById('d_head').checked = editing && data.is_unit_head == 1;
        document.getElementById('d_oic').checked = editing && data.is_oic == 1;
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
