<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Employees';
$flash = get_flash();
$errors = [];
$employees = [];
$offices = [];
$responsibilityCodes = [];
$form = [
    'id' => 0,
    'employee_no' => '',
    'first_name' => '',
    'middle_name' => '',
    'last_name' => '',
    'suffix_name' => '',
    'email' => '',
    'office_id' => '',
    'responsibility_code_id' => '',
    'position_title' => '',
    'employment_status' => '',
    'is_unit_head' => '0',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'employees');
    $officeResult = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    $codeResult = $db->query("
        SELECT rc.id, rc.office_id, rc.code, rc.description, o.office_name
        FROM responsibility_codes rc
        INNER JOIN offices o ON o.id = rc.office_id
        WHERE rc.is_active = 1
        ORDER BY o.office_name ASC, rc.code ASC
    ");
    if ($codeResult) {
        $responsibilityCodes = $codeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['employee_no'] = $form['id'] > 0 ? strtoupper(old($_POST, 'employee_no')) : $generatedCode;
            $form['first_name'] = old($_POST, 'first_name');
            $form['middle_name'] = old($_POST, 'middle_name');
            $form['last_name'] = old($_POST, 'last_name');
            $form['suffix_name'] = old($_POST, 'suffix_name');
            $form['email'] = old($_POST, 'email');
            $form['office_id'] = old($_POST, 'office_id');
            $form['responsibility_code_id'] = old($_POST, 'responsibility_code_id');
            $form['position_title'] = old($_POST, 'position_title');
            $form['employment_status'] = old($_POST, 'employment_status');
            $form['is_unit_head'] = isset($_POST['is_unit_head']) ? '1' : '0';
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['first_name'] === '') {
                $errors[] = 'First name is required.';
            }
            if ($form['last_name'] === '') {
                $errors[] = 'Last name is required.';
            }

            $recordId = (int) $form['id'];
            $duplicateStmt = $db->prepare("SELECT id FROM employees WHERE (employee_no = ? OR (email = ? AND ? <> '')) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('sssi', $form['employee_no'], $form['email'], $form['email'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Employee number or email already exists.';
                }
                $duplicateStmt->close();
            }

            $officeId = $form['office_id'] !== '' ? (int) $form['office_id'] : null;
            $responsibilityCodeId = $form['responsibility_code_id'] !== '' ? (int) $form['responsibility_code_id'] : null;

            if ($responsibilityCodeId && !$officeId) {
                $errors[] = 'Select an office before assigning a responsibility code.';
            }

            if ($responsibilityCodeId && $officeId) {
                $stmt = $db->prepare("SELECT office_id FROM responsibility_codes WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $responsibilityCodeId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $codeRow = $result ? $result->fetch_assoc() : null;
                    $stmt->close();
                    if (!$codeRow || (int) $codeRow['office_id'] !== $officeId) {
                        $errors[] = 'Selected responsibility code does not belong to the chosen office.';
                    }
                }
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $isUnitHead = (int) $form['is_unit_head'];
                $userId = current_user_id();

                if ($isUnitHead === 1 && $officeId) {
                    $clearStmt = $db->prepare("UPDATE employees SET is_unit_head = 0 WHERE office_id = ? AND id != ?");
                    if ($clearStmt) {
                        $clearStmt->bind_param('ii', $officeId, $recordId);
                        $clearStmt->execute();
                        $clearStmt->close();
                    }
                }

                if ($recordId > 0) {
                    $stmt = $db->prepare("UPDATE employees SET employee_no = ?, first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, email = ?, department_id = NULL, office_id = ?, responsibility_code_id = ?, position_title = ?, employment_status = ?, is_unit_head = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('ssssssiissiiii', $form['employee_no'], $form['first_name'], $form['middle_name'], $form['last_name'], $form['suffix_name'], $form['email'], $officeId, $responsibilityCodeId, $form['position_title'], $form['employment_status'], $isUnitHead, $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Employee updated successfully.');
                        redirect('modules/employees/index.php');
                    }
                } else {
                    $form['employee_no'] = next_module_code($db, 'employees');
                    $stmt = $db->prepare("INSERT INTO employees (employee_no, first_name, middle_name, last_name, suffix_name, email, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssssiissiii', $form['employee_no'], $form['first_name'], $form['middle_name'], $form['last_name'], $form['suffix_name'], $form['email'], $officeId, $responsibilityCodeId, $form['position_title'], $form['employment_status'], $isUnitHead, $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Employee created successfully.');
                        redirect('modules/employees/index.php');
                    }
                }

                $errors[] = 'Unable to save the employee.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE employees SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Employee deactivated successfully.');
                redirect('modules/employees/index.php');
            }

            $errors[] = 'Unable to deactivate the employee.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, email, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active FROM employees WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'employee_no' => $record['employee_no'],
                    'first_name' => $record['first_name'],
                    'middle_name' => $record['middle_name'] ?? '',
                    'last_name' => $record['last_name'],
                    'suffix_name' => $record['suffix_name'] ?? '',
                    'email' => $record['email'] ?? '',
                    'office_id' => (string) ($record['office_id'] ?? ''),
                    'responsibility_code_id' => (string) ($record['responsibility_code_id'] ?? ''),
                    'position_title' => $record['position_title'] ?? '',
                    'employment_status' => $record['employment_status'] ?? '',
                    'is_unit_head' => (string) (int) $record['is_unit_head'],
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $listResult = $db->query("
        SELECT e.id, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email,
               e.position_title, e.employment_status, e.is_unit_head, e.is_active,
               o.office_name,
               rc.code AS responsibility_code
        FROM employees e
        LEFT JOIN offices o ON o.id = e.office_id
        LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id
        ORDER BY e.last_name ASC, e.first_name ASC
    ");
    if ($listResult) {
        $employees = $listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Employee' : 'Add Employee'; ?></h5>
                <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                    <div class="mb-3"><label for="employee_no" class="form-label">Employee No.</label><input type="text" class="form-control" id="employee_no" name="employee_no" value="<?php echo h($form['id'] > 0 ? $form['employee_no'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using `EMP-YYYY-0001` format.</div></div>
                    <div class="mb-3"><label for="first_name" class="form-label">First Name</label><input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo h($form['first_name']); ?>" required></div>
                    <div class="mb-3"><label for="middle_name" class="form-label">Middle Name</label><input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo h($form['middle_name']); ?>"></div>
                    <div class="mb-3"><label for="last_name" class="form-label">Last Name</label><input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo h($form['last_name']); ?>" required></div>
                    <div class="mb-3"><label for="suffix_name" class="form-label">Suffix</label><input type="text" class="form-control" id="suffix_name" name="suffix_name" value="<?php echo h($form['suffix_name']); ?>"></div>
                    <div class="mb-3"><label for="email" class="form-label">Email</label><input type="email" class="form-control" id="email" name="email" value="<?php echo h($form['email']); ?>"></div>
                    <div class="mb-3"><label for="office_id" class="form-label">Office</label><select class="form-select" id="office_id" name="office_id"><option value="">Select office</option><?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3">
                        <label for="responsibility_code_id" class="form-label">Responsibility Code</label>
                        <select class="form-select" id="responsibility_code_id" name="responsibility_code_id">
                            <option value="">Select responsibility code</option>
                            <?php foreach ($responsibilityCodes as $code): ?>
                                <option value="<?php echo (int) $code['id']; ?>" data-office-id="<?php echo (int) $code['office_id']; ?>" <?php echo $form['responsibility_code_id'] === (string) $code['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($code['code'] . ' - ' . $code['office_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label for="position_title" class="form-label">Position Title</label><input type="text" class="form-control" id="position_title" name="position_title" value="<?php echo h($form['position_title']); ?>"></div>
                    <div class="mb-3"><label for="employment_status" class="form-label">Employment Status</label><input type="text" class="form-control" id="employment_status" name="employment_status" value="<?php echo h($form['employment_status']); ?>" placeholder="Regular, Contractual, Job Order"></div>
                    <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="is_unit_head" name="is_unit_head" value="1" <?php echo $form['is_unit_head'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="is_unit_head">This employee is the unit head for the selected office</label></div>
                    <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="is_active">Active employee</label></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Employee List</h5>
                    <span class="badge text-bg-light"><?php echo count($employees); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Employee</th><th>Office</th><th>Responsibility Code</th><th>Unit Head</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php if ($employees): ?>
                                <?php foreach ($employees as $employee): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?php echo h(employee_display_name($employee)); ?></div><small class="text-muted"><?php echo h($employee['employee_no'] . ($employee['position_title'] ? ' - ' . $employee['position_title'] : '')); ?></small></td>
                                        <td><?php echo h($employee['office_name'] ?? ''); ?></td>
                                        <td><?php echo h($employee['responsibility_code'] ?? ''); ?></td>
                                        <td><?php echo (int) $employee['is_unit_head'] === 1 ? '<span class="badge text-bg-primary">Yes</span>' : '<span class="text-muted">No</span>'; ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $employee['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $employee['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="text-end"><div class="d-inline-flex gap-2"><a href="<?php echo base_url('modules/employees/index.php?edit=' . (int) $employee['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php if ((int) $employee['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this employee?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $employee['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button></form><?php endif; ?></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No employees found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var officeSelect = document.getElementById('office_id');
    var codeSelect = document.getElementById('responsibility_code_id');
    function filterResponsibilityCodes() {
        if (!officeSelect || !codeSelect) {
            return;
        }
        var officeId = officeSelect.value;
        Array.from(codeSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (!matches && option.selected) {
                codeSelect.value = '';
            }
        });
    }
    officeSelect.addEventListener('change', filterResponsibilityCodes);
    filterResponsibilityCodes();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
