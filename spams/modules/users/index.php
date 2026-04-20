<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_role('Administrator');

function users_has_reference(mysqli $db, int $recordId): bool
{
    $stmt = $db->prepare("SELECT 1 FROM audit_logs WHERE user_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $hasRow = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $hasRow;
}

function users_password_validation_errors(string $password): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $errors[] = 'Password must contain at least one letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    return $errors;
}

function users_value_exists(mysqli $db, string $column, string $value, int $excludeId = 0): bool
{
    if (!in_array($column, ['username', 'email'], true)) {
        return false;
    }

    $trimmedValue = trim($value);
    if ($trimmedValue === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT id FROM users WHERE {$column} = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $trimmedValue, $excludeId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function users_generate_initial_password(int $length = 12): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $all = $upper . $lower . $digits;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
    ];

    for ($i = count($password); $i < $length; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    shuffle($password);
    return implode('', $password);
}

$db = db();
$page_title = 'Users';
$flash = get_flash();
$errors = [];
$users = [];
$roles = [];
$employees = [];
$offices = [];
$form = ['id'=>0,'username'=>'','email'=>'','full_name'=>'','role_id'=>'','employee_id'=>'','office_id'=>'','password'=>users_generate_initial_password(),'is_active'=>'1'];

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

    $employeeResult = $db->query("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, email, office_id, is_unit_head, position_title FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    $officeResult = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id']=(int)($_POST['id']??0);
            $form['username']=old($_POST,'username');
            $form['email']=old($_POST,'email');
            $form['full_name']=old($_POST,'full_name');
            $form['role_id']=old($_POST,'role_id');
            $form['employee_id']=old($_POST,'employee_id');
            $form['office_id']=old($_POST,'office_id');
            $form['password']=$_POST['password']??'';
            $form['is_active']=isset($_POST['is_active'])?'1':'0';

            if($form['username']==='') $errors[]='Username is required.';
            if($form['role_id']==='') $errors[]='Role is required.';
            if($form['id']===0 && trim($form['password'])==='') $errors[]='Initial password is required for a new user.';
            if (trim($form['password']) !== '') {
                $errors = array_merge($errors, users_password_validation_errors($form['password']));
            }

            $recordId=(int)$form['id'];
            if (users_value_exists($db, 'username', $form['username'], $recordId)) {
                $errors[] = 'Username already exists. Please choose a different username.';
            }
            if (trim($form['email']) !== '' && users_value_exists($db, 'email', $form['email'], $recordId)) {
                $errors[] = 'Email address is already linked to another user account.';
            }

            $employeeId=$form['employee_id']!==''?(int)$form['employee_id']:null;
            $officeId=$form['office_id']!==''?(int)$form['office_id']:null;
            $roleId=(int)$form['role_id'];

            if($employeeId){
                $stmt=$db->prepare("SELECT office_id, email, first_name, middle_name, last_name, suffix_name FROM employees WHERE id = ? LIMIT 1");
                if($stmt){
                    $stmt->bind_param('i',$employeeId);
                    $stmt->execute();
                    $employeeRow=$stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if($employeeRow){
                        if(!$officeId && !empty($employeeRow['office_id'])){
                            $officeId=(int)$employeeRow['office_id'];
                            $form['office_id']=(string)$officeId;
                        } elseif($officeId && !empty($employeeRow['office_id']) && (int)$employeeRow['office_id']!==$officeId){
                            $errors[]='Selected user office does not match the employee office.';
                        }

                        $employeeFullName = trim(employee_display_name($employeeRow));
                        $employeeEmail = trim((string) ($employeeRow['email'] ?? ''));

                        if($employeeFullName !== ''){
                            $form['full_name'] = $employeeFullName;
                        }
                        if($employeeEmail !== ''){
                            $form['email'] = $employeeEmail;
                        }
                    }
                }
            }

            if($form['full_name']==='') $errors[]='Full name is required when no linked employee name is available.';

            if(!$errors){
                $isActive=(int)$form['is_active'];
                if($recordId>0){
                    if(trim($form['password'])!==''){
                        $passwordHash=password_hash($form['password'], PASSWORD_DEFAULT);
                        $stmt=$db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role_id = ?, employee_id = ?, office_id = ?, password_hash = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                        if($stmt){
                            $stmt->bind_param('sssiiisii',$form['username'],$form['email'],$form['full_name'],$roleId,$employeeId,$officeId,$passwordHash,$isActive,$recordId);
                            $saved = $stmt->execute();
                            $stmt->close();
                            if ($saved) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'users',
                                    'record_id' => $recordId,
                                    'module_name' => 'users',
                                    'record_type' => 'user',
                                    'action_name' => 'update_user',
                                    'description' => 'Updated user account.',
                                    'new_values' => [
                                        'username' => $form['username'],
                                        'email' => $form['email'],
                                        'full_name' => $form['full_name'],
                                        'role_id' => $roleId,
                                        'employee_id' => $employeeId,
                                        'office_id' => $officeId,
                                        'is_active' => $isActive,
                                        'password_changed' => true,
                                    ],
                                ]);
                                set_flash('success','User updated successfully.');
                                redirect('modules/users/index.php');
                            }
                        }
                    } else {
                        $stmt=$db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, role_id = ?, employee_id = ?, office_id = ?, is_active = ?, updated_at = NOW() WHERE id = ?");
                        if($stmt){
                            $stmt->bind_param('sssiiiii',$form['username'],$form['email'],$form['full_name'],$roleId,$employeeId,$officeId,$isActive,$recordId);
                            $saved = $stmt->execute();
                            $stmt->close();
                            if ($saved) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'users',
                                    'record_id' => $recordId,
                                    'module_name' => 'users',
                                    'record_type' => 'user',
                                    'action_name' => 'update_user',
                                    'description' => 'Updated user account.',
                                    'new_values' => [
                                        'username' => $form['username'],
                                        'email' => $form['email'],
                                        'full_name' => $form['full_name'],
                                        'role_id' => $roleId,
                                        'employee_id' => $employeeId,
                                        'office_id' => $officeId,
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                set_flash('success','User updated successfully.');
                                redirect('modules/users/index.php');
                            }
                        }
                    }
                } else {
                    $passwordHash=password_hash($form['password'], PASSWORD_DEFAULT);
                    $stmt=$db->prepare("INSERT INTO users (username, email, password_hash, full_name, role_id, employee_id, office_id, is_active, must_change_password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
                    if($stmt){
                        $stmt->bind_param('ssssiiii',$form['username'],$form['email'],$passwordHash,$form['full_name'],$roleId,$employeeId,$officeId,$isActive);
                        $saved = $stmt->execute();
                        $newUserId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'users',
                                'record_id' => $newUserId,
                                'module_name' => 'users',
                                'record_type' => 'user',
                                'action_name' => 'create_user',
                                'description' => 'Created user account.',
                                'new_values' => [
                                    'username' => $form['username'],
                                    'email' => $form['email'],
                                    'full_name' => $form['full_name'],
                                    'role_id' => $roleId,
                                    'employee_id' => $employeeId,
                                    'office_id' => $officeId,
                                    'is_active' => $isActive,
                                    'must_change_password' => 1,
                                ],
                            ]);
                            set_flash('success','User created successfully. The initial password is set and the user will be required to change it on first login.');
                            redirect('modules/users/index.php');
                        }
                    }
                }
                $errors[]='Unable to save the user.';
            }
        } elseif($action==='delete'){
            $recordId=(int)($_POST['id']??0);
            $stmt=$db->prepare("UPDATE users SET is_active = 0, updated_at = NOW() WHERE id = ?");
            if($stmt){
                $stmt->bind_param('i',$recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'users',
                        'record_id' => $recordId,
                        'module_name' => 'users',
                        'record_type' => 'user',
                        'action_name' => 'deactivate_user',
                        'description' => 'Deactivated user account.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success','User deactivated successfully.');
                    redirect('modules/users/index.php');
                }
            }
            $errors[]='Unable to deactivate the user.';
        } elseif($action==='hard_delete'){
            if(($_SESSION['user_role']??'')!=='Administrator'){
                set_flash('error','Only administrators can permanently delete records.');
                redirect('modules/users/index.php');
            }
            $recordId=(int)($_POST['id']??0);
            if(users_has_reference($db,$recordId)){
                set_flash('error','Cannot delete: record is used in existing transactions.');
                redirect('modules/users/index.php');
            }
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT username, email, full_name FROM users WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt=$db->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i',$recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'users',
                        'record_id' => $recordId,
                        'module_name' => 'users',
                        'record_type' => 'user',
                        'action_name' => 'hard_delete_user',
                        'description' => 'Permanently deleted user account.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success','Record permanently deleted.');
                    redirect('modules/users/index.php');
                }
            }
            $errors[]='Unable to permanently delete the user.';
        }
    }

    if(isset($_GET['edit'])){
        $recordId=(int)$_GET['edit'];
        $stmt=$db->prepare("SELECT id, username, email, full_name, role_id, employee_id, office_id, is_active FROM users WHERE id = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i',$recordId);
            $stmt->execute();
            $record=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if($record){
                $form=['id'=>(int)$record['id'],'username'=>$record['username'],'email'=>$record['email'],'full_name'=>$record['full_name'],'role_id'=>(string)($record['role_id']??''),'employee_id'=>(string)($record['employee_id']??''),'office_id'=>(string)($record['office_id']??''),'password'=>'','is_active'=>(string)(int)$record['is_active']];
            }
        }
    }

    $listResult=$db->query("SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.created_at, {$roleNameExpr} AS role_name, o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name FROM users u LEFT JOIN roles r ON r.id = u.role_id LEFT JOIN offices o ON o.id = u.office_id LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.full_name ASC");
    if($listResult){
        $users=$listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Access Control</div>
                    <h4 class="mb-1">User Accounts</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($users); ?> of <?php echo count($users); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($form['id'] > 0): ?>
                        <a href="<?php echo base_url('modules/users/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add User'; ?>
                    </button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit User' : 'New User'; ?></h5>
                            <div class="text-muted small">Manage login credentials, role assignment, and linked employee access.</div>
                        </div>
                    </div>
                    <form method="post" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="master-data-form-layout">
                            <div class="master-data-form-main">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Account</div>
                                            <h6 class="mb-1">Login Identity</h6>
                                            <div class="text-muted small">Create a clear login record first, then connect it to an employee and role context.</div>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Username</label>
                                                <input type="text" class="form-control" name="username" value="<?php echo h($form['username']); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?php echo h($form['email']); ?>">
                                                <div class="form-text">Auto-filled from linked employee when available.</div>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Full Name</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo h($form['full_name']); ?>">
                                                <div class="form-text">Auto-filled from linked employee when available.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Access</div>
                                            <h6 class="mb-1">Role and Employee Link</h6>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="master-data-helper mb-3">
                                            Recommendation: link the user to an employee whenever possible so office and contact details stay synchronized.
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Role</label>
                                                <select class="form-select" id="role_id" name="role_id" data-placeholder="Select role" required>
                                                    <option value="">Select role</option>
                                                    <?php foreach ($roles as $role): ?><option value="<?php echo (int) $role['id']; ?>" <?php echo $form['role_id'] === (string) $role['id'] ? 'selected' : ''; ?>><?php echo h($role['name']); ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Office</label>
                                                <select class="form-select" id="office_id" name="office_id" data-placeholder="Select office">
                                                    <option value="">Select office</option>
                                                    <?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Linked Employee</label>
                                                <select class="form-select" id="employee_id" name="employee_id" data-placeholder="Select employee" onchange="window.syncUserOfficeFromEmployee && window.syncUserOfficeFromEmployee();">
                                                    <option value="">Select employee</option>
                                                    <?php foreach ($employees as $employee): ?><option value="<?php echo (int) $employee['id']; ?>" data-office-id="<?php echo (int) ($employee['office_id'] ?? 0); ?>" data-is-unit-head="<?php echo (int) ($employee['is_unit_head'] ?? 0); ?>" data-email="<?php echo h($employee['email'] ?? ''); ?>" data-full-name="<?php echo h(employee_display_name($employee)); ?>" <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>><?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no']); ?></option><?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Security</div>
                                            <h6 class="mb-1">Password Setup</h6>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <label class="form-label" for="password"><?php echo $form['id'] > 0 ? 'New Password' : 'Initial Password'; ?></label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" value="<?php echo $form['id'] > 0 ? '' : h($form['password']); ?>" aria-describedby="passwordHelp passwordStrength passwordCopyFeedback" placeholder="<?php echo $form['id'] > 0 ? '' : 'Generated initial password'; ?>">
                                            <?php if ($form['id'] === 0): ?>
                                                <button type="button" class="btn btn-outline-dark" id="generatePasswordButton">Generate</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-outline-secondary" id="togglePasswordVisibility">Show</button>
                                            <button type="button" class="btn btn-outline-primary" id="copyPasswordButton">Copy</button>
                                        </div>
                                        <div id="passwordStrength" class="small mt-2 text-muted">Use at least 8 characters with letters and numbers.</div>
                                        <div id="passwordCopyFeedback" class="small mt-1 text-muted"></div>
                                        <div class="form-text" id="passwordHelp"><?php echo $form['id'] > 0 ? 'Leave blank to keep the current password.' : 'Set the initial password for the account. The user will be required to change it on first login.'; ?> Minimum: 8 characters, at least one letter, and at least one number.</div>
                                    </div>
                                </div>

                                <div class="master-data-form-actions">
                                    <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/users/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                                    <button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update User' : 'Save User'; ?></button>
                                </div>
                            </div>

                            <div class="master-data-form-side">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Status</div>
                                            <h6 class="mb-1">Account Controls</h6>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="master-data-side-list">
                                            <div class="master-data-side-item">
                                                <span>Account state</span>
                                                <span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span>
                                            </div>
                                            <div class="master-data-side-item">
                                                <span>Linked employee</span>
                                                <strong><?php echo $form['employee_id'] !== '' ? 'Yes' : 'No'; ?></strong>
                                            </div>
                                        </div>
                                        <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active user</label></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search full name, username, email, role, employee, or office"></div>
                    <div class="col-sm-6 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    <div class="col-sm-6 col-lg-3"><label class="form-label">Rows Per Page</label><select id="perPageSelect" class="form-select"><option value="25" selected>25 rows</option><option value="50">50 rows</option><option value="100">100 rows</option><option value="250">250 rows</option></select></div>
                </div>
            </div>

            <div class="master-data-table-shell">
            <div class="table-responsive mobile-table-frame master-data-table-scroll">
                <table class="table align-middle" id="dataTable">
                    <thead><tr><th>User</th><th>Role</th><th>Employee</th><th>Office</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php if ($users): foreach ($users as $user): ?>
                            <tr data-status="<?php echo (int) $user['is_active'] ? 'active' : 'inactive'; ?>">
                                <td><div class="fw-semibold"><?php echo h($user['full_name']); ?></div><small class="text-muted"><?php echo h($user['username'] . ' - ' . $user['email']); ?></small></td>
                                <td><?php echo h($user['role_name'] ?? ''); ?></td>
                                <td><?php echo h(!empty($user['employee_no']) ? employee_display_name($user) . ' - ' . $user['employee_no'] : ''); ?></td>
                                <td><?php echo h($user['office_name'] ?? ''); ?></td>
                                <td><span class="badge <?php echo (int) $user['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td><?php echo h(date('M d, Y', strtotime($user['created_at']))); ?></td>
                                <td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/users/index.php?edit=' . (int) $user['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $user['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this user?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form></div></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr data-status="inactive"><td colspan="7" class="text-center text-muted py-4">No users found yet.</td></tr>
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
    var employeeDirectory = <?php echo json_encode(array_reduce($employees, function ($carry, $employee) { $carry[(string) $employee['id']] = ['full_name' => employee_display_name($employee), 'email' => (string) ($employee['email'] ?? ''), 'office_id' => (string) ($employee['office_id'] ?? '')]; return $carry; }, []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    function getSelectedEmployeeId() {
        var employeeSelect = document.getElementById('employee_id');
        if (!employeeSelect) return '';
        if (window.jQuery) {
            var jqValue = jQuery(employeeSelect).val();
            if (Array.isArray(jqValue)) {
                return jqValue.length ? (jqValue[0] || '') : '';
            }
            if (jqValue) {
                return jqValue;
            }
        }
        return employeeSelect.value || '';
    }

    function refreshSharedSelect(select) {
        if (window.jQuery && jQuery.fn.select2) {
            jQuery(select).trigger('change.select2');
        }
    }

    function syncOfficeEmployee() {
        var employeeSelect = document.getElementById('employee_id');
        var officeSelect = document.getElementById('office_id');
        if (!employeeSelect || !officeSelect) return;

        var officeId = officeSelect.value;
        var preferredEmployeeId = '';
        Array.from(employeeSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            var optionOfficeId = option.getAttribute('data-office-id') || '';
            var matches = !officeId || optionOfficeId === officeId;
            option.hidden = !matches;
            if (matches && officeId !== '' && option.getAttribute('data-is-unit-head') === '1' && !preferredEmployeeId) {
                preferredEmployeeId = option.value;
            }
            if (!matches && option.selected) {
                employeeSelect.value = '';
            }
        });

        var selectedOption = employeeSelect.selectedOptions.length ? employeeSelect.selectedOptions[0] : null;
        if (officeId !== '' && (!employeeSelect.value || !selectedOption || selectedOption.hidden) && preferredEmployeeId !== '') {
            employeeSelect.value = preferredEmployeeId;
        }
        refreshSharedSelect(employeeSelect);
    }

    function syncOfficeFromEmployee() {
        var employeeSelect = document.getElementById('employee_id');
        var officeSelect = document.getElementById('office_id');
        if (!employeeSelect || !officeSelect) return;

        var employeeId = getSelectedEmployeeId();
        var employeeOfficeId = employeeId && employeeDirectory[employeeId] ? (employeeDirectory[employeeId].office_id || '') : '';
        if (employeeOfficeId && officeSelect.value !== employeeOfficeId) {
            officeSelect.value = employeeOfficeId;
            refreshSharedSelect(officeSelect);
        }
        syncEmployeeIdentity();
        syncOfficeEmployee();
    }

    function syncEmployeeIdentity() {
        var employeeSelect = document.getElementById('employee_id');
        var emailField = document.getElementById('email');
        var fullNameField = document.getElementById('full_name');
        if (!employeeSelect || !emailField || !fullNameField) return;

        var employeeId = getSelectedEmployeeId();
        var employeeRecord = employeeId && employeeDirectory[employeeId] ? employeeDirectory[employeeId] : null;
        var linked = !!employeeRecord;
        var employeeEmail = linked ? (employeeRecord.email || '') : '';
        var employeeFullName = linked ? (employeeRecord.full_name || '') : '';

        if (linked) {
            emailField.value = employeeEmail;
            fullNameField.value = employeeFullName;
            emailField.readOnly = employeeEmail !== '';
            fullNameField.readOnly = employeeFullName !== '';
            emailField.placeholder = employeeEmail === '' ? 'No employee email on file' : '';
            fullNameField.placeholder = employeeFullName === '' ? 'No employee name available' : '';
        } else {
            emailField.readOnly = false;
            fullNameField.readOnly = false;
            emailField.placeholder = '';
            fullNameField.placeholder = '';
        }
    }

    function updatePasswordStrength() {
        var passwordField = document.getElementById('password');
        var strengthNode = document.getElementById('passwordStrength');
        if (!passwordField || !strengthNode) return;

        var value = passwordField.value || '';
        if (value === '') {
            strengthNode.className = 'small mt-2 text-muted';
            strengthNode.textContent = 'Use at least 8 characters with letters and numbers.';
            return;
        }

        var hasLength = value.length >= 8;
        var hasLetter = /[A-Za-z]/.test(value);
        var hasNumber = /\d/.test(value);
        var passed = [hasLength, hasLetter, hasNumber].filter(Boolean).length;

        if (passed === 3) {
            strengthNode.className = 'small mt-2 text-success';
            strengthNode.textContent = 'Strong enough: meets the password rules.';
        } else if (passed === 2) {
            strengthNode.className = 'small mt-2 text-warning';
            strengthNode.textContent = 'Almost there: use at least 8 characters and include both letters and numbers.';
        } else {
            strengthNode.className = 'small mt-2 text-danger';
            strengthNode.textContent = 'Weak password: add at least 8 characters with one letter and one number.';
        }
    }

    function setPasswordCopyFeedback(message, stateClass) {
        var feedbackNode = document.getElementById('passwordCopyFeedback');
        if (!feedbackNode) return;
        feedbackNode.className = 'small mt-1 ' + stateClass;
        feedbackNode.textContent = message;
    }

    function generateRandomInitialPassword(length) {
        var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lower = 'abcdefghijkmnopqrstuvwxyz';
        var digits = '23456789';
        var all = upper + lower + digits;
        var password = [
            upper.charAt(Math.floor(Math.random() * upper.length)),
            lower.charAt(Math.floor(Math.random() * lower.length)),
            digits.charAt(Math.floor(Math.random() * digits.length))
        ];

        while (password.length < length) {
            password.push(all.charAt(Math.floor(Math.random() * all.length)));
        }

        for (var i = password.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var temp = password[i];
            password[i] = password[j];
            password[j] = temp;
        }

        return password.join('');
    }

    var recordCountMobile = document.getElementById('recordCountMobile');
    var masterDataOptions = {
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
        emptyMessage: 'No users matched your search or status filter.'
    };

    window.syncUserLinkedEmployee = syncEmployeeIdentity;
    window.syncUserOfficeFromEmployee = syncOfficeFromEmployee;

    if (typeof window.initMasterDataList === 'function') {
        window.initMasterDataList('dataTable', masterDataOptions);
    } else {
        window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];
        window.__spamsPendingMasterDataLists.push(['dataTable', masterDataOptions]);
    }
    var officeField = document.getElementById('office_id');
    var employeeField = document.getElementById('employee_id');
    var passwordField = document.getElementById('password');
    var generatePasswordButton = document.getElementById('generatePasswordButton');
    var togglePasswordButton = document.getElementById('togglePasswordVisibility');
    var copyPasswordButton = document.getElementById('copyPasswordButton');

    if (officeField) {
        officeField.addEventListener('change', syncOfficeEmployee);
    }
    if (employeeField) {
        employeeField.addEventListener('change', syncOfficeFromEmployee);
    }
    if (passwordField) {
        passwordField.addEventListener('input', updatePasswordStrength);
    }
    if (generatePasswordButton && passwordField) {
        generatePasswordButton.addEventListener('click', function () {
            passwordField.value = generateRandomInitialPassword(12);
            updatePasswordStrength();
            setPasswordCopyFeedback('A new random initial password was generated.', 'text-success');
        });
    }
    if (togglePasswordButton && passwordField) {
        togglePasswordButton.addEventListener('click', function () {
            var showing = passwordField.type === 'text';
            passwordField.type = showing ? 'password' : 'text';
            togglePasswordButton.textContent = showing ? 'Show' : 'Hide';
        });
    }
    if (copyPasswordButton && passwordField) {
        copyPasswordButton.addEventListener('click', function () {
            if (passwordField.value === '') {
                setPasswordCopyFeedback('Enter the initial password first before copying it.', 'text-warning');
                passwordField.focus();
                return;
            }

            var onCopySuccess = function () {
                setPasswordCopyFeedback('Initial password copied. You can now send it to the user.', 'text-success');
            };
            var onCopyFailure = function () {
                setPasswordCopyFeedback('Unable to copy automatically. Please copy the password manually.', 'text-danger');
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(passwordField.value).then(onCopySuccess).catch(function () {
                    try {
                        passwordField.focus();
                        passwordField.select();
                        passwordField.setSelectionRange(0, passwordField.value.length);
                        if (document.execCommand('copy')) {
                            onCopySuccess();
                        } else {
                            onCopyFailure();
                        }
                    } catch (error) {
                        onCopyFailure();
                    }
                });
                return;
            }

            try {
                passwordField.focus();
                passwordField.select();
                passwordField.setSelectionRange(0, passwordField.value.length);
                if (document.execCommand('copy')) {
                    onCopySuccess();
                } else {
                    onCopyFailure();
                }
            } catch (error) {
                onCopyFailure();
            }
        });
    }
    syncOfficeEmployee();
    updatePasswordStrength();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>












