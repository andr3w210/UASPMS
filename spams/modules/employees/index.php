<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

function employees_has_reference(mysqli $db, int $recordId): bool
{
    $checks = [
        "SELECT 1 FROM distributions WHERE employee_id = ? LIMIT 1",
        "SELECT 1 FROM issuances WHERE employee_id = ? LIMIT 1",
    ];
    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }
    return false;
}


function employees_email_exists(mysqli $db, string $email, int $excludeId = 0): bool
{
    if (trim($email) === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT id FROM employees WHERE email = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $email, $excludeId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function employees_employee_no_exists(mysqli $db, string $employeeNo, int $excludeId = 0): bool
{
    if (trim($employeeNo) === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT id FROM employees WHERE employee_no = ? AND id != ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $employeeNo, $excludeId);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}
$db = db();
$page_title = 'Employees';
$flash = get_flash();
$errors = [];
$employees = [];
$offices = [];
$responsibilityCodes = [];
$hasDriverColumn = schema_has_column($db, 'employees', 'is_driver');
$form = ['id'=>0,'employee_no'=>'','name_prefix'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','suffix_name'=>'','email'=>'','photo_path'=>'','office_id'=>'','responsibility_code_id'=>'','position_title'=>'','employment_status'=>'','is_unit_head'=>'0','is_driver'=>'0','is_active'=>'1'];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'employees');
    $officeResult = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }
    $codeResult = $db->query("SELECT rc.id, rc.office_id, rc.code, rc.description, o.office_name FROM responsibility_codes rc INNER JOIN offices o ON o.id = rc.office_id WHERE rc.is_active = 1 ORDER BY o.office_name ASC, rc.code ASC");
    if ($codeResult) {
        $responsibilityCodes = $codeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id']=(int)($_POST['id']??0);
            $form['employee_no']=$form['id']>0?strtoupper(old($_POST,'employee_no')):$generatedCode;
            $form['name_prefix']=old($_POST,'name_prefix');
            $form['first_name']=old($_POST,'first_name');
            $form['middle_name']=old($_POST,'middle_name');
            $form['last_name']=old($_POST,'last_name');
            $form['suffix_name']=old($_POST,'suffix_name');
            $form['email']=old($_POST,'email');
            $form['photo_path']=(string)($_POST['existing_photo_path'] ?? '');
            $form['office_id']=old($_POST,'office_id');
            $form['responsibility_code_id']=old($_POST,'responsibility_code_id');
            $form['position_title']=old($_POST,'position_title');
            $form['employment_status']=old($_POST,'employment_status');
            $form['is_unit_head']=isset($_POST['is_unit_head'])?'1':'0';
            $form['is_driver']=isset($_POST['is_driver'])?'1':'0';
            $form['is_active']=isset($_POST['is_active'])?'1':'0';
            $removePhoto = isset($_POST['remove_photo']);

            if ($form['first_name'] === '') $errors[]='First name is required.';
            if ($form['last_name'] === '') $errors[]='Last name is required.';

            $recordId=(int)$form['id'];
            if ($recordId > 0) {
                if (employees_employee_no_exists($db, $form['employee_no'], $recordId) || employees_email_exists($db, $form['email'], $recordId)) {
                    $errors[]='Employee number or email already exists.';
                }
            } else {
                if (employees_email_exists($db, $form['email'])) {
                    $errors[]='Email already exists.';
                }
            }

            $officeId=$form['office_id']!==''?(int)$form['office_id']:null;
            $responsibilityCodeId=$form['responsibility_code_id']!==''?(int)$form['responsibility_code_id']:null;
            if($responsibilityCodeId&&!$officeId) $errors[]='Select an office before assigning a responsibility code.';
            if($responsibilityCodeId&&$officeId){
                $stmt=$db->prepare("SELECT office_id FROM responsibility_codes WHERE id = ? LIMIT 1");
                if($stmt){
                    $stmt->bind_param('i',$responsibilityCodeId);
                    $stmt->execute();
                    $codeRow=$stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if(!$codeRow||(int)$codeRow['office_id']!==$officeId) $errors[]='Selected responsibility code does not belong to the chosen office.';
                }
            }

            if(!$errors && !empty($_FILES['photo']['name'])){
                $storedPhoto = store_uploaded_image($_FILES['photo'], 'employees', $errors);
                if($storedPhoto !== null){
                    $form['photo_path'] = $storedPhoto;
                }
            }

            if($removePhoto){
                $form['photo_path'] = '';
            }

            if(!$errors){
                $isActive=(int)$form['is_active'];
                $isUnitHead=(int)$form['is_unit_head'];
                $isDriver=(int)$form['is_driver'];
                $userId=current_user_id();
                if($isUnitHead===1&&$officeId){
                    $clearStmt=$db->prepare("UPDATE employees SET is_unit_head = 0 WHERE office_id = ? AND id != ?");
                    if($clearStmt){
                        $clearStmt->bind_param('ii',$officeId,$recordId);
                        $clearStmt->execute();
                        $clearStmt->close();
                    }
                }

                if($recordId>0){
                    $updateSql = $hasDriverColumn
                        ? "UPDATE employees SET employee_no = ?, name_prefix = ?, first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, email = ?, photo_path = ?, department_id = NULL, office_id = ?, responsibility_code_id = ?, position_title = ?, employment_status = ?, is_unit_head = ?, is_driver = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?"
                        : "UPDATE employees SET employee_no = ?, name_prefix = ?, first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, email = ?, photo_path = ?, department_id = NULL, office_id = ?, responsibility_code_id = ?, position_title = ?, employment_status = ?, is_unit_head = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?";
                    $stmt=$db->prepare($updateSql);
                    if($stmt){
                        if ($hasDriverColumn) {
                            $stmt->bind_param('ssssssssiissiiiii',$form['employee_no'],$form['name_prefix'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$form['photo_path'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isDriver,$isActive,$userId,$recordId);
                        } else {
                            $stmt->bind_param('ssssssssiissiiii',$form['employee_no'],$form['name_prefix'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$form['photo_path'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isActive,$userId,$recordId);
                        }
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            $previousPhoto = (string)($_POST['existing_photo_path'] ?? '');
                            if ($previousPhoto !== '' && $previousPhoto !== $form['photo_path']) {
                                delete_uploaded_file($previousPhoto);
                            }
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'employees',
                                'record_id' => $recordId,
                                'module_name' => 'employees',
                                'record_type' => 'employee',
                                'action_name' => 'update_employee',
                                'description' => 'Updated employee record.',
                                'new_values' => [
                                    'employee_no' => $form['employee_no'],
                                    'name_prefix' => $form['name_prefix'],
                                    'first_name' => $form['first_name'],
                                    'middle_name' => $form['middle_name'],
                                    'last_name' => $form['last_name'],
                                    'suffix_name' => $form['suffix_name'],
                                    'email' => $form['email'],
                                    'photo_path' => $form['photo_path'],
                                    'office_id' => $officeId,
                                    'responsibility_code_id' => $responsibilityCodeId,
                                    'position_title' => $form['position_title'],
                                    'employment_status' => $form['employment_status'],
                                    'is_unit_head' => $isUnitHead,
                                    'is_driver' => $isDriver,
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success','Employee updated successfully.');
                            redirect('modules/employees/index.php');
                        }
                    }
                } else {
                    $attempts = 0;
                    do {
                        $form['employee_no']=next_module_code($db,'employees');
                        $attempts++;
                    } while ($form['employee_no'] !== '' && employees_employee_no_exists($db, $form['employee_no']) && $attempts < 25);

                    if ($form['employee_no'] === '' || employees_employee_no_exists($db, $form['employee_no'])) {
                        $errors[]='Unable to generate a unique employee number.';
                    }

                    if (!$errors) {
                        $insertSql = $hasDriverColumn
                            ? "INSERT INTO employees (employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, email, photo_path, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_driver, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)"
                            : "INSERT INTO employees (employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, email, photo_path, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt=$db->prepare($insertSql);
                        if($stmt){
                            if ($hasDriverColumn) {
                                $stmt->bind_param('ssssssssiissiiii',$form['employee_no'],$form['name_prefix'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$form['photo_path'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isDriver,$isActive,$userId);
                            } else {
                                $stmt->bind_param('ssssssssiissiii',$form['employee_no'],$form['name_prefix'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$form['photo_path'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isActive,$userId);
                            }
                            $saved = $stmt->execute();
                            $newEmployeeId = (int) $stmt->insert_id;
                            $stmt->close();
                            if ($saved) {
                                write_audit_log($db, [
                                    'action' => 'insert',
                                    'table_name' => 'employees',
                                    'record_id' => $newEmployeeId,
                                    'module_name' => 'employees',
                                    'record_type' => 'employee',
                                    'action_name' => 'create_employee',
                                    'description' => 'Created employee record.',
                                    'new_values' => [
                                        'employee_no' => $form['employee_no'],
                                        'name_prefix' => $form['name_prefix'],
                                        'first_name' => $form['first_name'],
                                        'middle_name' => $form['middle_name'],
                                        'last_name' => $form['last_name'],
                                        'suffix_name' => $form['suffix_name'],
                                        'email' => $form['email'],
                                        'photo_path' => $form['photo_path'],
                                        'office_id' => $officeId,
                                        'responsibility_code_id' => $responsibilityCodeId,
                                        'position_title' => $form['position_title'],
                                        'employment_status' => $form['employment_status'],
                                        'is_unit_head' => $isUnitHead,
                                        'is_driver' => $isDriver,
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                set_flash('success','Employee created successfully.');
                                redirect('modules/employees/index.php');
                            }
                        }
                    }
                }
                if (!$errors) {
                    $errors[]='Unable to save the employee.';
                }
            }
        } elseif($action==='delete'){
            $recordId=(int)($_POST['id']??0);
            $userId=current_user_id();
            $stmt=$db->prepare("UPDATE employees SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if($stmt){
                $stmt->bind_param('ii',$userId,$recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'employees',
                        'record_id' => $recordId,
                        'module_name' => 'employees',
                        'record_type' => 'employee',
                        'action_name' => 'deactivate_employee',
                        'description' => 'Deactivated employee record.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success','Employee deactivated successfully.');
                    redirect('modules/employees/index.php');
                }
            }
            $errors[]='Unable to deactivate the employee.';
        } elseif($action==='hard_delete'){
            if(($_SESSION['user_role']??'')!=='Administrator'){
                set_flash('error','Only administrators can permanently delete records.');
                redirect('modules/employees/index.php');
            }
            $recordId=(int)($_POST['id']??0);
            if(employees_has_reference($db,$recordId)){
                set_flash('error','Cannot delete: record is used in existing transactions.');
                redirect('modules/employees/index.php');
            }
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, photo_path FROM employees WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt=$db->prepare("DELETE FROM employees WHERE id = ? LIMIT 1");
            if($stmt){
                $stmt->bind_param('i',$recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    if (!empty($auditSnapshot['photo_path'])) {
                        delete_uploaded_file((string) $auditSnapshot['photo_path']);
                    }
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'employees',
                        'record_id' => $recordId,
                        'module_name' => 'employees',
                        'record_type' => 'employee',
                        'action_name' => 'hard_delete_employee',
                        'description' => 'Permanently deleted employee record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success','Record permanently deleted.');
                    redirect('modules/employees/index.php');
                }
            }
            $errors[]='Unable to permanently delete the employee.';
        }
    }

    if(isset($_GET['edit'])){
        $recordId=(int)$_GET['edit'];
        $selectEditSql = $hasDriverColumn
            ? "SELECT id, employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, email, photo_path, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_driver, is_active FROM employees WHERE id = ? LIMIT 1"
            : "SELECT id, employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, email, photo_path, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active FROM employees WHERE id = ? LIMIT 1";
        $stmt=$db->prepare($selectEditSql);
        if($stmt){
            $stmt->bind_param('i',$recordId);
            $stmt->execute();
            $record=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if($record){
                $form=['id'=>(int)$record['id'],'employee_no'=>$record['employee_no'],'name_prefix'=>$record['name_prefix']??'','first_name'=>$record['first_name'],'middle_name'=>$record['middle_name']??'','last_name'=>$record['last_name'],'suffix_name'=>$record['suffix_name']??'','email'=>$record['email']??'','photo_path'=>$record['photo_path']??'','office_id'=>(string)($record['office_id']??''),'responsibility_code_id'=>(string)($record['responsibility_code_id']??''),'position_title'=>$record['position_title']??'','employment_status'=>$record['employment_status']??'','is_unit_head'=>(string)(int)$record['is_unit_head'],'is_driver'=>(string)(int)($record['is_driver']??0),'is_active'=>(string)(int)$record['is_active']];
            }
        }
    }

    $listSql = $hasDriverColumn
        ? "SELECT e.id, e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.photo_path, e.position_title, e.employment_status, e.is_unit_head, e.is_driver, e.is_active, e.created_at, o.office_name, rc.code AS responsibility_code FROM employees e LEFT JOIN offices o ON o.id = e.office_id LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id ORDER BY e.last_name ASC, e.first_name ASC"
        : "SELECT e.id, e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.photo_path, e.position_title, e.employment_status, e.is_unit_head, e.is_active, e.created_at, o.office_name, rc.code AS responsibility_code FROM employees e LEFT JOIN offices o ON o.id = e.office_id LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id ORDER BY e.last_name ASC, e.first_name ASC";
    $listResult=$db->query($listSql);
    if($listResult){
        $employees=$listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if($errors): ?>
                <div class="alert alert-danger mb-4">
                    <?php foreach($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if($flash): ?>
                <div class="alert alert-<?php echo $flash['type']==='success'?'success':'info'; ?> mb-4"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Master Data</div>
                    <h4 class="mb-1">Employee Directory</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($employees); ?> of <?php echo count($employees); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if($form['id']>0): ?>
                        <a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id']>0?'true':'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id']>0?'Continue Editing':'Add Employee'; ?>
                    </button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id']>0?'show':''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id']>0?'Edit Employee':'New Employee'; ?></h5>
                            <div class="text-muted small">Maintain employee profile, office assignment, and responsibility code details.</div>
                        </div>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
                        <input type="hidden" name="existing_photo_path" value="<?php echo h($form['photo_path']); ?>">

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Employee No.</label>
                                <input type="text" class="form-control" name="employee_no" value="<?php echo h($form['id']>0?$form['employee_no']:$generatedCode); ?>" readonly>
                                <div class="form-text">Generated automatically using `EMP-YYYY-0001` format.</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Prefix</label>
                                <input type="text" class="form-control" name="name_prefix" value="<?php echo h($form['name_prefix']); ?>" placeholder="Dr., Atty.">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">First Name</label>
                                <input type="text" class="form-control" name="first_name" value="<?php echo h($form['first_name']); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" class="form-control" name="middle_name" value="<?php echo h($form['middle_name']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" class="form-control" name="last_name" value="<?php echo h($form['last_name']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Suffix</label>
                                <input type="text" class="form-control" name="suffix_name" value="<?php echo h($form['suffix_name']); ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Position Title</label>
                                <input type="text" class="form-control" name="position_title" value="<?php echo h($form['position_title']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Office</label>
                                <select class="form-select" id="office_id" name="office_id" data-placeholder="Select office">
                                    <option value="">Select office</option>
                                    <?php foreach($offices as $office): ?>
                                        <option value="<?php echo (int)$office['id']; ?>" <?php echo $form['office_id']===(string)$office['id']?'selected':''; ?>><?php echo h($office['office_name'].' ('.$office['office_code'].')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Responsibility Code</label>
                                <select class="form-select" id="responsibility_code_id" name="responsibility_code_id" data-placeholder="Select responsibility code">
                                    <option value="">Select responsibility code</option>
                                    <?php foreach($responsibilityCodes as $code): ?>
                                        <option value="<?php echo (int)$code['id']; ?>" data-office-id="<?php echo (int)$code['office_id']; ?>" <?php echo $form['responsibility_code_id']===(string)$code['id']?'selected':''; ?>><?php echo h($code['code'].' - '.$code['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Employment Status</label>
                                <input type="text" class="form-control" name="employment_status" value="<?php echo h($form['employment_status']); ?>" placeholder="Regular, Contractual, Job Order">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Employee Photo</label>
                                <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                <div class="form-text">JPG, PNG, GIF, or WEBP up to 5 MB.</div>
                                <?php if($form['photo_path']!==''): ?>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="remove_employee_photo">
                                        <label class="form-check-label" for="remove_employee_photo">Remove current photo</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Preview</label>
                                <div>
                                    <?php if($form['photo_path']!==''): ?>
                                        <img src="<?php echo h(upload_url($form['photo_path'])); ?>" alt="Employee photo" class="employee-photo-thumb">
                                    <?php else: ?>
                                        <div class="employee-photo-thumb employee-photo-thumb-placeholder"><i class="bi bi-person"></i></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex flex-column justify-content-end gap-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_unit_head" value="1" <?php echo $form['is_unit_head']==='1'?'checked':''; ?>>
                                    <label class="form-check-label">Unit head</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_driver" value="1" <?php echo $form['is_driver']==='1'?'checked':''; ?>>
                                    <label class="form-check-label">Driver</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active']==='1'?'checked':''; ?>>
                                    <label class="form-check-label">Active employee</label>
                                </div>
                            </div>
                            <div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2">
                                <?php if($form['id']>0): ?>
                                    <a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary px-4"><?php echo $form['id']>0?'Update Employee':'Save Employee'; ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Search</label>
                        <input type="search" id="tableSearch" class="form-control" placeholder="Search employee, office, responsibility code, or position">
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
                            <th>Employee</th>
                            <th>Office</th>
                            <th>Responsibility Code</th>
                            <th>Unit Head</th>
                            <th>Driver</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($employees): foreach($employees as $employee): ?>
                            <tr data-status="<?php echo (int)$employee['is_active']?'active':'inactive'; ?>">
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div>
                                            <?php if(!empty($employee['photo_path'])): ?>
                                                <img src="<?php echo h(upload_url($employee['photo_path'])); ?>" alt="<?php echo h(employee_display_name($employee)); ?>" class="employee-photo-thumb">
                                            <?php else: ?>
                                                <div class="employee-photo-thumb employee-photo-thumb-placeholder"><i class="bi bi-person"></i></div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold"><?php echo h(employee_display_name($employee)); ?></div>
                                            <small class="text-muted"><?php echo h($employee['employee_no'].($employee['position_title']?' - '.$employee['position_title']:'')); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo h($employee['office_name'] ?? ''); ?></td>
                                <td><?php echo h($employee['responsibility_code'] ?? ''); ?></td>
                                <td><?php echo (int)$employee['is_unit_head']===1?'<span class="badge text-bg-primary">Yes</span>':'<span class="text-muted">No</span>'; ?></td>
                                <td><?php echo (int)($employee['is_driver'] ?? 0)===1?'<span class="badge text-bg-info">Yes</span>':'<span class="text-muted">No</span>'; ?></td>
                                <td><span class="badge <?php echo (int)$employee['is_active']===1?'text-bg-success':'text-bg-secondary'; ?>"><?php echo (int)$employee['is_active']===1?'Active':'Inactive'; ?></span></td>
                                <td><?php echo h(date('M d, Y', strtotime($employee['created_at']))); ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                        <a href="<?php echo base_url('modules/employees/index.php?edit='.(int)$employee['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php if((int)$employee['is_active']===1): ?>
                                            <form method="post" onsubmit="return confirm('Deactivate this employee?');" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$employee['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if(($_SESSION['user_role']??'')==='Administrator'): ?>
                                            <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="hard_delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$employee['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr data-status="inactive"><td colspan="8" class="text-center text-muted py-4">No employees found yet.</td></tr>
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
    function refreshSharedSelect(select) {
        if (window.jQuery && jQuery.fn.select2) {
            jQuery(select).trigger('change.select2');
        }
    }

    function filterResponsibilityCodes() {
        var officeSelect = document.getElementById('office_id');
        var codeSelect = document.getElementById('responsibility_code_id');
        if (!officeSelect || !codeSelect) return;

        var officeId = officeSelect.value;
        var preferredCodeId = '';
        Array.from(codeSelect.options).forEach(function (option, index) {
            if (index === 0) {
                option.hidden = false;
                return;
            }
            var matches = !officeId || option.getAttribute('data-office-id') === officeId;
            option.hidden = !matches;
            if (matches && officeId !== '' && !preferredCodeId) {
                preferredCodeId = option.value;
            }
            if (!matches && option.selected) {
                codeSelect.value = '';
            }
        });

        var selectedOption = codeSelect.selectedOptions.length ? codeSelect.selectedOptions[0] : null;
        if (officeId !== '' && (!codeSelect.value || !selectedOption || selectedOption.hidden) && preferredCodeId !== '') {
            codeSelect.value = preferredCodeId;
        }
        refreshSharedSelect(codeSelect);
    }

    function initEmployeeTable() {
        var table = document.getElementById('dataTable');
        var tbody = table ? table.querySelector('tbody') : null;
        if (!table || !tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
            return row.cells.length > 1;
        });
        var searchInput = document.getElementById('tableSearch');
        var statusFilter = document.getElementById('statusFilter');
        var prevButton = document.getElementById('prevPage');
        var nextButton = document.getElementById('nextPage');
        var pageInfo = document.getElementById('pageInfo');
        var perPageSelect = document.getElementById('perPageSelect');
        var recordCount = document.getElementById('recordCount');
        var currentPage = 1;
        var perPage = parseInt(perPageSelect && perPageSelect.value, 10) || 25;

        function getVisibleRows() {
            var term = ((searchInput && searchInput.value) || '').toLowerCase().trim();
            var status = (statusFilter && statusFilter.value) || '';

            return rows.filter(function (row) {
                var matchesTerm = !term || row.textContent.toLowerCase().indexOf(term) !== -1;
                var matchesStatus = !status || row.getAttribute('data-status') === status;
                return matchesTerm && matchesStatus;
            });
        }

        function render() {
            var visibleRows = getVisibleRows();
            var totalVisible = visibleRows.length;
            var totalOverall = rows.length;
            var totalPages = Math.max(1, Math.ceil(totalVisible / perPage));

            currentPage = Math.min(Math.max(currentPage, 1), totalPages);

            var start = totalVisible > 0 ? (currentPage - 1) * perPage : 0;
            var end = Math.min(start + perPage, totalVisible);

            rows.forEach(function (row) {
                row.style.display = 'none';
            });
            visibleRows.slice(start, end).forEach(function (row) {
                row.style.display = '';
            });

            if (recordCount) {
                recordCount.textContent = 'Showing ' + totalVisible + ' of ' + totalOverall + ' records';
            }
            var recordCountMobile = document.getElementById('recordCountMobile');
            if (recordCountMobile) {
                recordCountMobile.textContent = 'Showing ' + totalVisible + ' of ' + totalOverall + ' records';
            }
            if (pageInfo) {
                pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages + ' (' + totalVisible + ' matches)';
            }
            if (prevButton) {
                prevButton.disabled = currentPage <= 1;
            }
            if (nextButton) {
                nextButton.disabled = currentPage >= totalPages;
            }
        }

        function applyFilters() {
            currentPage = 1;
            render();
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (statusFilter) {
            statusFilter.addEventListener('change', applyFilters);
        }
        if (perPageSelect) {
            perPageSelect.addEventListener('change', function () {
                perPage = parseInt(this.value, 10) || 25;
                currentPage = 1;
                render();
            });
        }
        if (prevButton) {
            prevButton.addEventListener('click', function () {
                currentPage -= 1;
                render();
            });
        }
        if (nextButton) {
            nextButton.addEventListener('click', function () {
                currentPage += 1;
                render();
            });
        }

        render();
    }

    document.getElementById('office_id')?.addEventListener('change', filterResponsibilityCodes);
    filterResponsibilityCodes();
    initEmployeeTable();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>





