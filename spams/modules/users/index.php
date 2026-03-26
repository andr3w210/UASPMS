<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator');

$db = db_connect();
$page_title = 'Users';
$flash = get_flash();
$errors = [];
$users = [];
$roles = [];
$employees = [];
$offices = [];
$form = [
    'id' => 0,
    'username' => '',
    'email' => '',
    'full_name' => '',
    'role_id' => '',
    'employee_id' => '',
    'office_id' => '',
    'password' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $roleNameColumn = roles_name_column($db);
    $roleNameExpr = roles_name_expression($db, 'r');
    $roleActiveClause = roles_active_clause($db);

    $roleResult = $db->query("SELECT id, {$roleNameColumn} AS name FROM roles WHERE {$roleActiveClause} ORDER BY {$roleNameColumn} ASC");
    if ($roleResult) {
        $roles = $roleResult->fetch_all(MYSQLI_ASSOC);
    }

    $employeeResult = $db->query("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, office_id FROM employees WHERE is_active = 1 ORDER BY last_name, first_name");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    $officeResult = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['username'] = old($_POST, 'username');
            $form['email'] = old($_POST, 'email');
            $form['full_name'] = old($_POST, 'full_name');
            $form['role_id'] = old($_POST, 'role_id');
            $form['employee_id'] = old($_POST, 'employee_id');
            $form['office_id'] = old($_POST, 'office_id');
            $form['password'] = $_POST['password'] ?? '';
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['username'] === '') {
                $errors[] = 'Username is required.';
            }
            if ($form['email'] === '') {
                $errors[] = 'Email is required.';
            }
            if ($form['full_name'] === '') {
                $errors[] = 'Full name is required.';
            }
            if ($form['role_id'] === '') {
                $errors[] = 'Role is required.';
            }
            if ($form['id'] === 0 && trim($form['password']) === '') {
                $errors[] = 'Password is required for a new user.';
            }

            $recordId = (int) $form['id'];
            $duplicateStmt = $db->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('ssi', $form['username'], $form['email'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Username or email already exists.';
                }
                $duplicateStmt->close();
            }

            $employeeId = $form['employee_id'] !== '' ? (int) $form['employee_id'] : null;
            $officeId = $form['office_id'] !== '' ? (int) $form['office_id'] : null;
            $roleId = (int) $form['role_id'];

            if ($employeeId) {
                $stmt = $db->prepare("SELECT office_id FROM employees WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $employeeId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $employeeRow = $result ? $result->fetch_assoc() : null;
                    $stmt->close();

                    if ($employeeRow) {
                        if (!$officeId && !empty($employeeRow['office_id'])) {
                            $officeId = (int) $employeeRow['office_id'];
                            $form['office_id'] = (string) $officeId;
                        } elseif ($officeId && !empty($employeeRow['office_id']) && (int) $employeeRow['office_id'] !== $officeId) {
                            $errors[] = 'Selected user office does not match the employee office.';
                        }
                    }
                }
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];

                if ($recordId > 0) {
                    if (trim($form['password']) !== '') {
                        $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role_id = ?, employee_id = ?, office_id = ?, password_hash = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param('sssiiisii', $form['username'], $form['email'], $form['full_name'], $roleId, $employeeId, $officeId, $passwordHash, $isActive, $recordId);
                            $stmt->execute();
                            $stmt->close();
                            set_flash('success', 'User updated successfully.');
                            redirect('modules/users/index.php');
                        }
                    } else {
                        $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role_id = ?, employee_id = ?, office_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param('sssiiiii', $form['username'], $form['email'], $form['full_name'], $roleId, $employeeId, $officeId, $isActive, $recordId);
                            $stmt->execute();
                            $stmt->close();
                            set_flash('success', 'User updated successfully.');
                            redirect('modules/users/index.php');
                        }
                    }
                } else {
                    $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);
                    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, employee_id, office_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssiiii', $form['username'], $form['email'], $passwordHash, $form['full_name'], $roleId, $employeeId, $officeId, $isActive);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'User created successfully.');
                        redirect('modules/users/index.php');
                    }
                }

                $errors[] = 'Unable to save the user.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'User deactivated successfully.');
                redirect('modules/users/index.php');
            }
            $errors[] = 'Unable to deactivate the user.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, username, email, full_name, role_id, employee_id, office_id, is_active FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'username' => $record['username'],
                    'email' => $record['email'],
                    'full_name' => $record['full_name'],
                    'role_id' => (string) ($record['role_id'] ?? ''),
                    'employee_id' => (string) ($record['employee_id'] ?? ''),
                    'office_id' => (string) ($record['office_id'] ?? ''),
                    'password' => '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $listResult = $db->query("
        SELECT u.id, u.username, u.email, u.full_name, u.is_active,
               {$roleNameExpr} AS role_name,
               o.office_name,
               e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM users u
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN offices o ON o.id = u.office_id
        LEFT JOIN employees e ON e.id = u.employee_id
        ORDER BY u.full_name ASC
    ");
    if ($listResult) {
        $users = $listResult->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit User' : 'Add User'; ?></h5>
                <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                    <div class="mb-3"><label for="username" class="form-label">Username</label><input type="text" class="form-control" id="username" name="username" value="<?php echo h($form['username']); ?>" required></div>
                    <div class="mb-3"><label for="email" class="form-label">Email</label><input type="email" class="form-control" id="email" name="email" value="<?php echo h($form['email']); ?>" required></div>
                    <div class="mb-3"><label for="full_name" class="form-label">Full Name</label><input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo h($form['full_name']); ?>" required></div>
                    <div class="mb-3"><label for="role_id" class="form-label">Role</label><select class="form-select" id="role_id" name="role_id" required><option value="">Select role</option><?php foreach ($roles as $role): ?><option value="<?php echo (int) $role['id']; ?>" <?php echo $form['role_id'] === (string) $role['id'] ? 'selected' : ''; ?>><?php echo h($role['name']); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3">
                        <label for="employee_id" class="form-label">Linked Employee</label>
                        <select class="form-select" id="employee_id" name="employee_id">
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label for="office_id" class="form-label">Office</label><select class="form-select" id="office_id" name="office_id"><option value="">Select office</option><?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label for="password" class="form-label"><?php echo $form['id'] > 0 ? 'New Password' : 'Password'; ?></label><input type="password" class="form-control" id="password" name="password"><div class="form-text"><?php echo $form['id'] > 0 ? 'Leave blank to keep the current password.' : 'Set the initial password for the account.'; ?></div></div>
                    <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="is_active">Active user</label></div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/users/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">User List</h5>
                    <span class="badge text-bg-light"><?php echo count($users); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>User</th><th>Role</th><th>Employee</th><th>Office</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php if ($users): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?php echo h($user['full_name']); ?></div><small class="text-muted"><?php echo h($user['username'] . ' - ' . $user['email']); ?></small></td>
                                        <td><?php echo h($user['role_name'] ?? ''); ?></td>
                                        <td><?php echo h(!empty($user['employee_no']) ? employee_display_name($user) . ' - ' . $user['employee_no'] : ''); ?></td>
                                        <td><?php echo h($user['office_name'] ?? ''); ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $user['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="text-end"><div class="d-inline-flex gap-2"><a href="<?php echo base_url('modules/users/index.php?edit=' . (int) $user['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php if ((int) $user['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this user?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button></form><?php endif; ?></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No users found yet.</td></tr>
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
    var employeeSelect = document.getElementById('employee_id');
    var officeSelect = document.getElementById('office_id');
    if (!employeeSelect || !officeSelect) {
        return;
    }
    employeeSelect.addEventListener('change', function () {
        var option = employeeSelect.options[employeeSelect.selectedIndex];
        var employeeOfficeId = option ? option.getAttribute('data-office-id') : '';
        if (employeeOfficeId && !officeSelect.value) {
            officeSelect.value = employeeOfficeId;
        }
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
