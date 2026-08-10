<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_once __DIR__ . '/../../app/helpers/employee_assignments.php';
require_role('Administrator');

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
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
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

function users_audit_snapshot(mysqli $db, int $userId): array
{
    return audit_fetch_row_snapshot($db, 'users', $userId, [
        'username',
        'email',
        'full_name',
        'role_id',
        'employee_id',
        'office_id',
        'is_active',
    ]);
}

function users_generate_initial_password(int $length = 12): string
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%*?';
    $all = $upper . $lower . $digits . $symbols;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
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
$resetPasswordModal = $_SESSION['user_reset_password_modal'] ?? null;
unset($_SESSION['user_reset_password_modal']);
$errors = [];
$users = [];
$roles = [];
$employees = [];
$offices = [];
$employeeOfficeMap = [];
$employeeLoginRoleId = 0;
$form = ['id'=>0,'username'=>'','email'=>'','full_name'=>'','role_id'=>'','employee_id'=>'','office_id'=>'','password'=>users_generate_initial_password(),'is_active'=>'1'];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $userHasForcePasswordColumn = function_exists('schema_has_column')
        ? schema_has_column($db, 'users', 'must_change_password')
        : false;
    $roleNameColumn = roles_name_column($db);
    $roleNameExpr = roles_name_expression($db, 'r');
    $roleActiveClause = roles_active_clause($db);
    $employeeLoginRoleId = rbac_employee_account_role_id($db);

    $roleResult = $db->query("SELECT id, {$roleNameColumn} AS name FROM roles WHERE {$roleActiveClause} ORDER BY {$roleNameColumn} ASC");
    if ($roleResult) {
        $roles = $roleResult->fetch_all(MYSQLI_ASSOC);
    }

    $assignmentsEnabled = employee_assignments_enabled($db);

    $employeeResult = $db->query("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, email, office_id, is_unit_head, position_title FROM employees WHERE is_active = 1 ORDER BY office_id ASC, is_unit_head DESC, last_name ASC, first_name ASC");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($assignmentsEnabled) {
        $assignmentResult = $db->query("SELECT employee_id, office_id, is_primary, is_unit_head
                                        FROM employee_assignments
                                        WHERE is_active = 1
                                        ORDER BY employee_id ASC, is_primary DESC, id ASC");
        if ($assignmentResult) {
            foreach ($assignmentResult->fetch_all(MYSQLI_ASSOC) as $assignmentRow) {
                $employeeId = (int) ($assignmentRow['employee_id'] ?? 0);
                $officeId = (int) ($assignmentRow['office_id'] ?? 0);
                if ($employeeId <= 0 || $officeId <= 0) {
                    continue;
                }
                if (!isset($employeeOfficeMap[$employeeId])) {
                    $employeeOfficeMap[$employeeId] = [
                        'office_ids' => [],
                        'primary_office_id' => 0,
                        'unit_head_office_ids' => [],
                    ];
                }
                if (!in_array($officeId, $employeeOfficeMap[$employeeId]['office_ids'], true)) {
                    $employeeOfficeMap[$employeeId]['office_ids'][] = $officeId;
                }
                if ((int) ($assignmentRow['is_primary'] ?? 0) === 1 && $employeeOfficeMap[$employeeId]['primary_office_id'] === 0) {
                    $employeeOfficeMap[$employeeId]['primary_office_id'] = $officeId;
                }
                if ((int) ($assignmentRow['is_unit_head'] ?? 0) === 1 && !in_array($officeId, $employeeOfficeMap[$employeeId]['unit_head_office_ids'], true)) {
                    $employeeOfficeMap[$employeeId]['unit_head_office_ids'][] = $officeId;
                }
            }
        }
    }

    $officeResult = $db->query("SELECT id, office_name, office_code FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'ensure_employee_role') {
            $newRoleId = rbac_ensure_employee_account_role($db, (int) current_user_id());
            if ($newRoleId > 0) {
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'roles',
                    'record_id' => $newRoleId,
                    'module_name' => 'users',
                    'record_type' => 'role',
                    'action_name' => 'ensure_employee_role',
                    'description' => 'Created baseline Employee login role.',
                    'new_values' => ['role_name' => 'Employee', 'is_active' => 1],
                ]);
                set_flash('success', 'Employee login role created. You can now create linked employee accounts safely.');
            } else {
                set_flash('error', 'Unable to create the Employee login role. Please check the roles table setup.');
            }
            redirect('modules/users/index.php');
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
                        $employeeOfficeIds = [];
                        if (!empty($employeeOfficeMap[$employeeId]['office_ids'])) {
                            $employeeOfficeIds = array_map('intval', (array) $employeeOfficeMap[$employeeId]['office_ids']);
                        } elseif (!empty($employeeRow['office_id'])) {
                            $employeeOfficeIds = [(int) $employeeRow['office_id']];
                        }

                        $preferredOfficeId = 0;
                        if (!empty($employeeOfficeMap[$employeeId]['primary_office_id'])) {
                            $preferredOfficeId = (int) $employeeOfficeMap[$employeeId]['primary_office_id'];
                        } elseif (!empty($employeeRow['office_id'])) {
                            $preferredOfficeId = (int) $employeeRow['office_id'];
                        } elseif (!empty($employeeOfficeIds)) {
                            $preferredOfficeId = (int) $employeeOfficeIds[0];
                        }

                        if (!$officeId && $preferredOfficeId > 0) {
                            $officeId = $preferredOfficeId;
                            $form['office_id'] = (string) $officeId;
                        } elseif ($officeId && $employeeOfficeIds && !in_array((int) $officeId, $employeeOfficeIds, true)) {
                            $errors[]='Selected user office does not match any of the employee assignments.';
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
                    $auditBefore = users_audit_snapshot($db, $recordId);
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
                                    'old_values' => $auditBefore,
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
                                    'old_values' => $auditBefore,
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
            $auditBefore = users_audit_snapshot($db, $recordId);
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
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success','User deactivated successfully.');
                    redirect('modules/users/index.php');
                }
            }
            $errors[]='Unable to deactivate the user.';
        } elseif($action==='reactivate'){
            $recordId=(int)($_POST['id']??0);
            $auditBefore = users_audit_snapshot($db, $recordId);
            $stmt=$db->prepare("UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = ?");
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
                        'action_name' => 'reactivate_user',
                        'description' => 'Reactivated user account.',
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success','User reactivated successfully.');
                    redirect('modules/users/index.php');
                }
            }
            $errors[]='Unable to reactivate the user.';
        } elseif($action==='hard_delete'){
            if(($_SESSION['user_role']??'')!=='Administrator'){
                set_flash('error','Only administrators can permanently delete records.');
                redirect('modules/users/index.php');
            }
            $recordId=(int)($_POST['id']??0);
            if($recordId <= 0){
                set_flash('error','Invalid user record.');
                redirect('modules/users/index.php');
            }
            if((int) (current_user_id() ?? 0) === $recordId){
                set_flash('error','You cannot permanently delete the account you are currently using.');
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
            $db->begin_transaction();
            try {
                $auditDetachStmt = $db->prepare("UPDATE audit_logs SET user_id = NULL WHERE user_id = ?");
                if ($auditDetachStmt) {
                    $auditDetachStmt->bind_param('i', $recordId);
                    $auditDetachStmt->execute();
                    $auditDetachStmt->close();
                }

                $stmt=$db->prepare("DELETE FROM users WHERE id = ? LIMIT 1");
                if(!$stmt){
                    throw new RuntimeException('Unable to prepare user delete.');
                }
                $stmt->bind_param('i',$recordId);
                $saved = $stmt->execute();
                $deleteError = $stmt->error;
                $stmt->close();

                if (!$saved) {
                    throw new RuntimeException($deleteError !== '' ? $deleteError : 'Unable to permanently delete the user.');
                }

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

                $db->commit();
                set_flash('success','Record permanently deleted.');
                redirect('modules/users/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to permanently delete the user.';
                $errors[] = $e->getMessage();
            }
        } elseif($action==='reset_password'){
            $recordId = (int) ($_POST['id'] ?? 0);
            if (!$userHasForcePasswordColumn) {
                $errors[] = 'Password reset is not available in this database.';
            } elseif ($recordId <= 0) {
                $errors[] = 'Invalid user record.';
            } else {
                $resetSnapshot = [
                    'username' => '',
                    'full_name' => '',
                ];
                $snapshotStmt = $db->prepare("SELECT username, full_name FROM users WHERE id = ? LIMIT 1");
                if ($snapshotStmt) {
                    $snapshotStmt->bind_param('i', $recordId);
                    $snapshotStmt->execute();
                    $snapshotRow = $snapshotStmt->get_result()->fetch_assoc();
                    $snapshotStmt->close();
                    if ($snapshotRow) {
                        $resetSnapshot = $snapshotRow;
                    }
                }

                $temporaryPassword = users_generate_initial_password();
                $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('si', $passwordHash, $recordId);
                    $saved = $stmt->execute();
                    $stmt->close();
                    if ($saved) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'users',
                            'record_id' => $recordId,
                            'module_name' => 'users',
                            'record_type' => 'user',
                            'action_name' => 'reset_password',
                            'description' => 'Reset the user password and required change on next login.',
                            'new_values' => [
                                'must_change_password' => 1,
                                'password_changed' => true,
                            ],
                        ]);
                        $_SESSION['user_reset_password_modal'] = [
                            'password' => $temporaryPassword,
                            'record_id' => $recordId,
                            'username' => $resetSnapshot['username'] ?? '',
                            'full_name' => $resetSnapshot['full_name'] ?? '',
                        ];
                        set_flash('success', 'Temporary password generated. Give it to the user and they will be required to change it on next login.');
                        redirect('modules/users/index.php');
                    }
                }
                $errors[] = 'Unable to reset the user password.';
            }
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

    $listResult=$db->query("SELECT u.id, u.username, u.email, u.full_name, u.is_active, u.created_at, " . ($userHasForcePasswordColumn ? "COALESCE(u.must_change_password, 0)" : "0") . " AS must_change_password, {$roleNameExpr} AS role_name, o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name FROM users u LEFT JOIN roles r ON r.id = u.role_id LEFT JOIN offices o ON o.id = u.office_id LEFT JOIN employees e ON e.id = u.employee_id ORDER BY u.full_name ASC");
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

            <?php if ($employeeLoginRoleId <= 0): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="fw-semibold">Employee login role is missing.</div>
                        <div>Create a baseline <strong>Employee</strong> role before generating employee login accounts. This keeps job titles separate from system permissions.</div>
                    </div>
                    
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="ensure_employee_role">
                        <button type="submit" class="btn btn-sm btn-warning">Create Employee Role</button>
                    </form>
                </div>
            <?php endif; ?>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit User' : 'New User'; ?></h5>
                            <div class="text-muted small">Manage login credentials, role assignment, and linked employee access.</div>
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
                                                    <?php foreach ($roles as $role): ?>
                                                        <?php
                                                        $roleId = (int) ($role['id'] ?? 0);
                                                        $isEmployeeLoginRole = $employeeLoginRoleId > 0 && $roleId === $employeeLoginRoleId;
                                                        ?>
                                                        <option value="<?php echo $roleId; ?>" <?php echo $form['role_id'] === (string) $roleId ? 'selected' : ''; ?>>
                                                            <?php echo h((string) ($role['name'] ?? '') . ($isEmployeeLoginRole ? ' (Employee Login)' : '')); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="form-text">For ordinary employee logins, use Employee or User. Job titles like Budget Officer belong in the employee designation, not the app role.</div>
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
                                                    <?php foreach ($employees as $employee): ?>
                                                        <?php
                                                        $employeeId = (int) ($employee['id'] ?? 0);
                                                        $officeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
                                                        if (!$officeIds && !empty($employee['office_id'])) {
                                                            $officeIds = [(int) $employee['office_id']];
                                                        }
                                                        $primaryOfficeId = (int) ($employeeOfficeMap[$employeeId]['primary_office_id'] ?? 0);
                                                        if ($primaryOfficeId <= 0 && !empty($employee['office_id'])) {
                                                            $primaryOfficeId = (int) $employee['office_id'];
                                                        }
                                                        $unitHeadOfficeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['unit_head_office_ids'] ?? []));
                                                        $isAnyUnitHead = !empty($unitHeadOfficeIds) || (int) ($employee['is_unit_head'] ?? 0) === 1;
                                                        ?>
                                                        <option value="<?php echo $employeeId; ?>"
                                                                data-office-ids="<?php echo h(implode(',', $officeIds)); ?>"
                                                                data-primary-office-id="<?php echo (int) $primaryOfficeId; ?>"
                                                                data-unit-head-office-ids="<?php echo h(implode(',', $unitHeadOfficeIds)); ?>"
                                                                data-is-unit-head="<?php echo $isAnyUnitHead ? '1' : '0'; ?>"
                                                                data-email="<?php echo h($employee['email'] ?? ''); ?>"
                                                                data-full-name="<?php echo h(employee_display_name($employee)); ?>"
                                                                <?php echo $form['employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>><?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no']); ?></option>
                                                    <?php endforeach; ?>
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
                    <thead><tr><th data-sort="user">User <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="role">Role <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="employee">Employee <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php if ($users): foreach ($users as $user): ?>
                            <tr data-status="<?php echo (int) $user['is_active'] ? 'active' : 'inactive'; ?>">
                                <td><div class="fw-semibold"><?php echo h($user['full_name']); ?></div><small class="text-muted"><?php echo h($user['username'] . ' - ' . $user['email']); ?></small></td>
                                <td><?php echo h($user['role_name'] ?? ''); ?></td>
                                <td><?php echo h(!empty($user['employee_no']) ? employee_display_name($user) . ' - ' . $user['employee_no'] : ''); ?></td>
                                <td><?php echo h($user['office_name'] ?? ''); ?></td>
                                <td>
                                    <span class="badge <?php echo (int) $user['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $user['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span>
                                    <?php if (!empty($userHasForcePasswordColumn) && (int) ($user['must_change_password'] ?? 0) === 1): ?>
                                        <div class="mt-1"><span class="badge text-bg-warning">Password Change Required</span></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h(date('M d, Y', strtotime($user['created_at']))); ?></td>
                                <td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/users/index.php?edit=' . (int) $user['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if (!empty($userHasForcePasswordColumn) && (int) $user['is_active'] === 1): ?><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-key"></i> Reset Password</button></form><?php endif; ?><?php if ((int) $user['is_active'] === 1): ?><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php else: ?><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button></form><?php endif; ?><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form></div></td>
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
    var employeeDirectory = <?php echo json_encode(array_reduce($employees, function ($carry, $employee) use ($employeeOfficeMap) {
        $employeeId = (int) ($employee['id'] ?? 0);
        $officeIds = array_map('intval', (array) ($employeeOfficeMap[$employeeId]['office_ids'] ?? []));
        if (!$officeIds && !empty($employee['office_id'])) {
            $officeIds = [(int) $employee['office_id']];
        }
        $primaryOfficeId = (int) ($employeeOfficeMap[$employeeId]['primary_office_id'] ?? 0);
        if ($primaryOfficeId <= 0 && !empty($employee['office_id'])) {
            $primaryOfficeId = (int) $employee['office_id'];
        }
        $carry[(string) $employeeId] = [
            'full_name' => employee_display_name($employee),
            'email' => (string) ($employee['email'] ?? ''),
            'office_ids' => $officeIds,
            'primary_office_id' => $primaryOfficeId > 0 ? (string) $primaryOfficeId : '',
        ];
        return $carry;
    }, []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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
            var optionOfficeIds = (option.getAttribute('data-office-ids') || '').split(',').map(function (value) {
                return value.trim();
            }).filter(Boolean);
            var matches = !officeId || optionOfficeIds.indexOf(officeId) !== -1;
            option.hidden = !matches;
            if (matches && officeId !== '' && !preferredEmployeeId) {
                var unitHeadOfficeIds = (option.getAttribute('data-unit-head-office-ids') || '').split(',').map(function (value) {
                    return value.trim();
                }).filter(Boolean);
                if (unitHeadOfficeIds.indexOf(officeId) !== -1) {
                    preferredEmployeeId = option.value;
                }
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
        var employeeRecord = employeeId && employeeDirectory[employeeId] ? employeeDirectory[employeeId] : null;
        var employeeOfficeIds = employeeRecord && Array.isArray(employeeRecord.office_ids)
            ? employeeRecord.office_ids.map(function (value) { return String(value); })
            : [];
        var primaryOfficeId = employeeRecord ? (employeeRecord.primary_office_id || '') : '';
        var currentOfficeId = officeSelect.value || '';

        var shouldSetOffice = false;
        var nextOfficeId = '';
        if (employeeRecord) {
            if (!currentOfficeId) {
                nextOfficeId = primaryOfficeId || (employeeOfficeIds[0] || '');
                shouldSetOffice = nextOfficeId !== '';
            } else if (employeeOfficeIds.length > 0 && employeeOfficeIds.indexOf(currentOfficeId) === -1) {
                nextOfficeId = primaryOfficeId || employeeOfficeIds[0] || '';
                shouldSetOffice = nextOfficeId !== '' && nextOfficeId !== currentOfficeId;
            }
        }

        if (shouldSetOffice) {
            officeSelect.value = nextOfficeId;
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
<?php if (is_array($resetPasswordModal) && !empty($resetPasswordModal['password'])): ?>
<div class="modal fade" id="resetPasswordResultModal" tabindex="-1" aria-labelledby="resetPasswordResultLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="resetPasswordResultLabel">Temporary Password Ready</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    Give this temporary password to
                    <strong><?php echo h(trim((string) (($resetPasswordModal['full_name'] ?? '') !== '' ? $resetPasswordModal['full_name'] : ($resetPasswordModal['username'] ?? 'the user')))); ?></strong>.
                </p>
                <p class="text-muted small mb-3">It is shown once only. The user will be required to change it on next login.</p>
                <div class="input-group">
                    <input type="text" class="form-control" id="resetPasswordValue" value="<?php echo h((string) $resetPasswordModal['password']); ?>" readonly>
                    <button type="button" class="btn btn-outline-secondary" id="copyResetPasswordBtn">Copy</button>
                </div>
                <div class="small text-muted mt-2" id="copyResetPasswordFeedback">Copy this now before closing.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('resetPasswordResultModal');
    var passwordInput = document.getElementById('resetPasswordValue');
    var copyButton = document.getElementById('copyResetPasswordBtn');
    var feedback = document.getElementById('copyResetPasswordFeedback');

    if (modalElement && window.bootstrap) {
        new bootstrap.Modal(modalElement).show();
    }

    if (!copyButton || !passwordInput) {
        return;
    }

    copyButton.addEventListener('click', function () {
        var onSuccess = function () {
            if (feedback) {
                feedback.textContent = 'Temporary password copied.';
                feedback.className = 'small text-success mt-2';
            }
        };
        var onFailure = function () {
            if (feedback) {
                feedback.textContent = 'Copy failed. Select the password manually and copy it.';
                feedback.className = 'small text-danger mt-2';
            }
        };

        passwordInput.removeAttribute('readonly');
        passwordInput.focus();
        passwordInput.select();
        passwordInput.setSelectionRange(0, passwordInput.value.length);
        passwordInput.setAttribute('readonly', 'readonly');

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(passwordInput.value).then(onSuccess).catch(onFailure);
            return;
        }

        try {
            if (document.execCommand('copy')) {
                onSuccess();
            } else {
                onFailure();
            }
        } catch (error) {
            onFailure();
        }
    });
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>











