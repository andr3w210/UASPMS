<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Transport Officer');

function employees_has_reference(mysqli $db, int $recordId): bool
{
    $fkTargets = [];
    $fkStmt = $db->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'employees'");
    if ($fkStmt) {
        $fkStmt->execute();
        $fkResult = $fkStmt->get_result();
        if ($fkResult) {
            $fkTargets = $fkResult->fetch_all(MYSQLI_ASSOC);
        }
        $fkStmt->close();
    }

    $checks = [];
    foreach ($fkTargets as $target) {
        $table = (string) ($target['TABLE_NAME'] ?? '');
        $column = (string) ($target['COLUMN_NAME'] ?? '');
        if ($table === '' || $column === '') {
            continue;
        }
        $safeTable = str_replace('`', '``', $table);
        $safeColumn = str_replace('`', '``', $column);
        $checks[] = "SELECT 1 FROM `{$safeTable}` WHERE `{$safeColumn}` = ? LIMIT 1";
    }

    // Fallback for older schemas that may not define FK constraints yet.
    if (!$checks) {
        $checks = [
            "SELECT 1 FROM distributions WHERE employee_id = ? LIMIT 1",
            "SELECT 1 FROM issuances WHERE employee_id = ? LIMIT 1",
        ];
    }

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

function employees_recommended_statuses(): array
{
    return [
        'Regular',
        'Permanent',
        'Contractual',
        'Job Order',
        'Part-Time',
        'Retired',
        'Retired - Part-Time',
        'Retired - Contractual',
        'Resigned',
        'Separated',
    ];
}

function employees_normalize_status(string $status): string
{
    return preg_replace('/\s+/', ' ', trim($status)) ?? '';
}

function employees_audit_snapshot(mysqli $db, int $employeeId): array
{
    $columns = [
        'employee_no',
        'name_prefix',
        'first_name',
        'middle_name',
        'last_name',
        'suffix_name',
        'email',
        'photo_path',
        'office_id',
        'responsibility_code_id',
        'position_title',
        'employment_status',
        'is_unit_head',
        'is_active',
    ];

    if (schema_has_column($db, 'employees', 'is_driver')) {
        $columns[] = 'is_driver';
    }

    return audit_fetch_row_snapshot($db, 'employees', $employeeId, $columns);
}

$db = db();
$page_title = 'Employees';
$flash = get_flash();
$errors = [];
$employees = [];
$offices = [];
$responsibilityCodes = [];
$hasDriverColumn = schema_has_column($db, 'employees', 'is_driver');
$assignmentSummaryMap = [];
$primaryAssignmentMap = [];
$assignmentsEnabled = employee_assignments_enabled($db);
$assignmentFormRows = $assignmentsEnabled ? [employee_assignment_empty_row()] : [];
$form = ['id'=>0,'employee_no'=>'','name_prefix'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','suffix_name'=>'','email'=>'','photo_path'=>'','office_id'=>'','responsibility_code_id'=>'','position_title'=>'','employment_status'=>'','is_unit_head'=>'0','is_driver'=>'0','is_active'=>'1'];
$employmentStatusOptions = employees_recommended_statuses();

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
    if ($form['employment_status'] !== '' && !in_array($form['employment_status'], $employmentStatusOptions, true)) {
        $employmentStatusOptions[] = $form['employment_status'];
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
            $form['employment_status']=employees_normalize_status((string) old($_POST,'employment_status'));
            $form['is_unit_head']=isset($_POST['is_unit_head'])?'1':'0';
            $form['is_driver']=isset($_POST['is_driver'])?'1':'0';
            $form['is_active']=isset($_POST['is_active'])?'1':'0';
            $removePhoto = isset($_POST['remove_photo']);
            if ($assignmentsEnabled) {
                $assignmentFormRows = employee_normalize_assignment_rows($_POST['assignments'] ?? []);
                $primaryAssignmentIndex = isset($_POST['assignment_primary_index']) ? (int) $_POST['assignment_primary_index'] : -1;
                foreach ($assignmentFormRows as $index => &$assignmentRow) {
                    $assignmentRow['is_primary'] = $index === $primaryAssignmentIndex ? '1' : '0';
                    $assignmentRow['is_active'] = $assignmentRow['is_active'] ?? '1';
                }
                unset($assignmentRow);
                $errors = array_merge($errors, employee_validate_assignment_rows($db, $assignmentFormRows));
                $primaryAssignment = [];
                foreach ($assignmentFormRows as $assignmentRow) {
                    if (($assignmentRow['is_primary'] ?? '0') === '1') {
                        $primaryAssignment = $assignmentRow;
                        break;
                    }
                }
                if (!$primaryAssignment && !empty($assignmentFormRows)) {
                    $primaryAssignment = $assignmentFormRows[0];
                }
                $form['office_id'] = (string) ($primaryAssignment['office_id'] ?? '');
                $form['responsibility_code_id'] = (string) ($primaryAssignment['responsibility_code_id'] ?? '');
                $form['position_title'] = (string) ($primaryAssignment['role_title'] ?? '');
                $form['is_unit_head'] = (string) ($primaryAssignment['is_unit_head'] ?? '0');
            }

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
            if(!$assignmentsEnabled){
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
                if($isUnitHead===1&&$officeId && !employee_assignments_enabled($db)){
                    $clearStmt=$db->prepare("UPDATE employees SET is_unit_head = 0 WHERE office_id = ? AND id != ?");
                    if($clearStmt){
                        $clearStmt->bind_param('ii',$officeId,$recordId);
                        $clearStmt->execute();
                        $clearStmt->close();
                    }
                }

                if($recordId>0){
                    $auditBefore = employees_audit_snapshot($db, $recordId);
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
                            if ($assignmentsEnabled) {
                                if (!employee_save_assignments($db, $recordId, $assignmentFormRows, $userId)) {
                                    $errors[] = 'Unable to save employee assignments.';
                                }
                            }
                        }
                        if ($saved && !$errors) {
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
                                'old_values' => $auditBefore,
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
                                if ($assignmentsEnabled) {
                                    if (!employee_save_assignments($db, $newEmployeeId, $assignmentFormRows, $userId)) {
                                        $errors[] = 'Unable to save employee assignments.';
                                    }
                                }
                            }
                            if ($saved && !$errors) {
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
            $auditBefore = employees_audit_snapshot($db, $recordId);
            $stmt=$db->prepare("UPDATE employees SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if($stmt){
                $stmt->bind_param('ii',$userId,$recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    if ($assignmentsEnabled) {
                        $deactivateAssignmentsStmt = $db->prepare("UPDATE employee_assignments SET is_active = 0, is_primary = 0, updated_by = ?, updated_at = NOW() WHERE employee_id = ?");
                        if ($deactivateAssignmentsStmt) {
                            $deactivateAssignmentsStmt->bind_param('ii', $userId, $recordId);
                            $deactivateAssignmentsStmt->execute();
                            $deactivateAssignmentsStmt->close();
                        }
                    }
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'employees',
                        'record_id' => $recordId,
                        'module_name' => 'employees',
                        'record_type' => 'employee',
                        'action_name' => 'deactivate_employee',
                        'description' => 'Deactivated employee record.',
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success','Employee deactivated successfully.');
                    redirect('modules/employees/index.php');
                }
            }
            $errors[]='Unable to deactivate the employee.';
        } elseif($action==='reactivate'){
            $recordId=(int)($_POST['id']??0);
            $userId=current_user_id();
            $auditBefore = employees_audit_snapshot($db, $recordId);
            $stmt=$db->prepare("UPDATE employees SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
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
                        'action_name' => 'reactivate_employee',
                        'description' => 'Reactivated employee record.',
                        'old_values' => $auditBefore,
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success','Employee reactivated successfully.');
                    redirect('modules/employees/index.php');
                }
            }
            $errors[]='Unable to reactivate the employee.';
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
                if ($assignmentsEnabled) {
                    $deleteAssignmentStmt = $db->prepare("DELETE FROM employee_assignments WHERE employee_id = ?");
                    if ($deleteAssignmentStmt) {
                        $deleteAssignmentStmt->bind_param('i', $recordId);
                        $deleteAssignmentStmt->execute();
                        $deleteAssignmentStmt->close();
                    }
                }
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
                if ($assignmentsEnabled) {
                    $assignmentFormRows = employee_fetch_assignments($db, $recordId, true);
                    if (!$assignmentFormRows) {
                        $assignmentFormRows = [employee_assignment_empty_row()];
                    } else {
                        foreach ($assignmentFormRows as &$assignmentRow) {
                            $assignmentRow['office_id'] = (string) ($assignmentRow['office_id'] ?? '');
                            $assignmentRow['responsibility_code_id'] = (string) ($assignmentRow['responsibility_code_id'] ?? '');
                            $assignmentRow['role_title'] = (string) ($assignmentRow['role_title'] ?? '');
                            $assignmentRow['is_unit_head'] = !empty($assignmentRow['is_unit_head']) ? '1' : '0';
                            $assignmentRow['is_oic'] = !empty($assignmentRow['is_oic']) ? '1' : '0';
                            $assignmentRow['is_primary'] = !empty($assignmentRow['is_primary']) ? '1' : '0';
                            $assignmentRow['is_active'] = !empty($assignmentRow['is_active']) ? '1' : '0';
                        }
                        unset($assignmentRow);
                    }
                    $primaryAssignment = employee_fetch_primary_assignment($db, $recordId);
                    if ($primaryAssignment) {
                        $form['office_id'] = (string) ($primaryAssignment['office_id'] ?? '');
                        $form['responsibility_code_id'] = (string) ($primaryAssignment['responsibility_code_id'] ?? '');
                        $form['position_title'] = (string) ($primaryAssignment['role_title'] ?? '');
                        $form['is_unit_head'] = !empty($primaryAssignment['is_unit_head']) ? '1' : '0';
                    }
                }
            }
        }
    }

    if ($form['employment_status'] !== '' && !in_array($form['employment_status'], $employmentStatusOptions, true)) {
        $employmentStatusOptions[] = $form['employment_status'];
    }

    $listSql = $hasDriverColumn
        ? "SELECT e.id, e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.photo_path, e.position_title, e.employment_status, e.is_unit_head, e.is_driver, e.is_active, e.created_at, o.office_name, rc.code AS responsibility_code FROM employees e LEFT JOIN offices o ON o.id = e.office_id LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id ORDER BY e.last_name ASC, e.first_name ASC"
        : "SELECT e.id, e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.photo_path, e.position_title, e.employment_status, e.is_unit_head, e.is_active, e.created_at, o.office_name, rc.code AS responsibility_code FROM employees e LEFT JOIN offices o ON o.id = e.office_id LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id ORDER BY e.last_name ASC, e.first_name ASC";
    $listResult=$db->query($listSql);
    if($listResult){
        $employees=$listResult->fetch_all(MYSQLI_ASSOC);
    }
    if ($assignmentsEnabled) {
        foreach ($employees as &$employeeRow) {
            $employeeId = (int) ($employeeRow['id'] ?? 0);
            $assignments = employee_fetch_assignments($db, $employeeId, true);
            $summary = employee_assignment_summary($assignments);
            $primaryAssignment = $assignments[0] ?? [];
            $assignmentSummaryMap[$employeeId] = $summary;
            $primaryAssignmentMap[$employeeId] = $primaryAssignment;
            if ($primaryAssignment) {
                $employeeRow['office_name'] = $primaryAssignment['office_name'] ?? ($employeeRow['office_name'] ?? '');
                $employeeRow['responsibility_code'] = $primaryAssignment['responsibility_code'] ?? ($employeeRow['responsibility_code'] ?? '');
                $employeeRow['position_title'] = $primaryAssignment['role_title'] ?? ($employeeRow['position_title'] ?? '');
                $employeeRow['is_unit_head'] = !empty($primaryAssignment['is_unit_head']) ? 1 : 0;
            }
        }
        unset($employeeRow);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<style>
.employee-encode-grid {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(280px, 0.95fr);
    gap: 1rem;
}

.employee-encode-main,
.employee-encode-side {
    display: grid;
    gap: 1rem;
}

.employee-panel {
    border: 1px solid var(--bs-border-color);
    border-radius: 1rem;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,249,250,0.98));
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
}

.employee-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1rem 0;
}

.employee-panel-body {
    padding: 1rem;
}

.employee-panel-kicker {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--bs-secondary-color);
    font-weight: 700;
}

.employee-profile-card {
    display: grid;
    justify-items: center;
    text-align: center;
    gap: 0.75rem;
}

.employee-profile-meta {
    width: 100%;
    display: grid;
    gap: 0.5rem;
}

.employee-profile-chip {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    padding: 0.65rem 0.8rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 0.85rem;
    background: rgba(255,255,255,0.86);
    font-size: 0.9rem;
}

.assignment-editor-shell {
    border: 1px dashed rgba(13, 110, 253, 0.28);
    border-radius: 1rem;
    padding: 1rem;
    background: linear-gradient(180deg, rgba(248,250,252,0.92), rgba(255,255,255,0.95));
}

.assignment-editor-tip {
    padding: 0.85rem 1rem;
    border-radius: 0.9rem;
    background: rgba(13, 110, 253, 0.08);
    border: 1px solid rgba(13, 110, 253, 0.16);
    color: #234b8f;
    font-size: 0.92rem;
}

.assignment-row {
    border: 1px solid rgba(15, 23, 42, 0.08) !important;
    border-radius: 1rem !important;
    background: #fff !important;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.05);
}

.employee-form-actions {
    position: sticky;
    bottom: 0;
    z-index: 5;
    padding-top: 0.75rem;
    margin-top: 0.5rem;
    background: linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,0.96) 35%);
}

@media (max-width: 991.98px) {
    .employee-encode-grid {
        grid-template-columns: 1fr;
    }
}
</style>
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
                            <div class="text-muted small">Maintain employee profile and designation assignments. Additional assignments are stored in the new assignment table.</div>
                        </div>
                    </div>
                    <form method="post" enctype="multipart/form-data" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
                        <input type="hidden" name="existing_photo_path" value="<?php echo h($form['photo_path']); ?>">

                        <div class="employee-encode-grid">
                            <div class="employee-encode-main">
                                <div class="employee-panel">
                                    <div class="employee-panel-header">
                                        <div>
                                            <div class="employee-panel-kicker">Identity</div>
                                            <h6 class="mb-1">Employee Profile</h6>
                                            <div class="text-muted small">Keep the personal record clean. Office-specific work should be encoded below as assignments.</div>
                                        </div>
                                        <span class="badge text-bg-light border">Master Record</span>
                                    </div>
                                    <div class="employee-panel-body">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Employee No.</label>
                                                <input type="text" class="form-control" name="employee_no" value="<?php echo h($form['id']>0?$form['employee_no']:$generatedCode); ?>" readonly>
                                                <div class="form-text">Generated automatically using `EMP-YYYY-0001` format.</div>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Prefix</label>
                                                <input type="text" class="form-control" name="name_prefix" value="<?php echo h($form['name_prefix']); ?>" placeholder="Dr., Atty.">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>" placeholder="name@domain.com">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">First Name</label>
                                                <input type="text" class="form-control" name="first_name" value="<?php echo h($form['first_name']); ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Middle Name</label>
                                                <input type="text" class="form-control" name="middle_name" value="<?php echo h($form['middle_name']); ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Last Name</label>
                                                <input type="text" class="form-control" name="last_name" value="<?php echo h($form['last_name']); ?>" required>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Suffix</label>
                                                <input type="text" class="form-control" name="suffix_name" value="<?php echo h($form['suffix_name']); ?>" placeholder="Jr., III">
                                            </div>
                                            <div class="col-md-5">
                                                <label class="form-label">Employment Status</label>
                                                <input type="text" class="form-control" list="employmentStatusOptions" name="employment_status" value="<?php echo h($form['employment_status']); ?>" placeholder="Regular, Retired - Part-Time, Contractual">
                                                <datalist id="employmentStatusOptions">
                                                    <?php foreach ($employmentStatusOptions as $statusOption): ?>
                                                        <option value="<?php echo h($statusOption); ?>"></option>
                                                    <?php endforeach; ?>
                                                </datalist>
                                                <div class="form-text">Use `Retired - Part-Time` or `Retired - Contractual` when the employee is retired from regular service but still has an active assignment.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="employee-panel">
                                    <div class="employee-panel-header">
                                        <div>
                                            <div class="employee-panel-kicker">Assignments</div>
                                            <h6 class="mb-1">Office and Designation Matrix</h6>
                                            <div class="text-muted small">Encode each office separately. Mark one primary assignment, then flag unit head or OIC only where applicable.</div>
                                        </div>
                                        <?php if ($assignmentsEnabled): ?>
                                            <button type="button" class="btn btn-sm btn-primary" id="addAssignmentRow">
                                                <i class="bi bi-plus-circle me-1"></i>Add Assignment
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="employee-panel-body">
                                        <div class="assignment-editor-tip mb-3">
                                            Recommendation: use this section for office-based designation and authority. Example: `College of Industrial Technology` + `Administrative Officer VI`, then turn on `OIC` if the employee is acting head. For retired but still serving personnel, keep the assignment active only while the person still holds that office role.
                                        </div>
                                        <?php if ($assignmentsEnabled): ?>
                                            <div class="assignment-editor-shell">
                                                <div id="assignmentRows" class="d-grid gap-3">
                                                    <?php foreach ($assignmentFormRows as $assignmentIndex => $assignmentRow): ?>
                                                        <div class="p-3 assignment-row" data-index="<?php echo (int) $assignmentIndex; ?>">
                                                            <input type="hidden" name="assignments[<?php echo (int) $assignmentIndex; ?>][id]" value="<?php echo (int) ($assignmentRow['id'] ?? 0); ?>">
                                                            <div class="row g-3">
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Designation / Position</label>
                                                                    <input type="text" class="form-control assignment-role-title" list="assignmentDesignationOptions" name="assignments[<?php echo (int) $assignmentIndex; ?>][role_title]" value="<?php echo h($assignmentRow['role_title'] ?? ''); ?>" placeholder="Administrative Officer VI">
                                                                    <div class="form-text">Encode the employee's position for this office assignment.</div>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Office</label>
                                                                    <select class="form-select assignment-office" name="assignments[<?php echo (int) $assignmentIndex; ?>][office_id]">
                                                                        <option value="">Select office</option>
                                                                        <?php foreach($offices as $office): ?>
                                                                            <option value="<?php echo (int)$office['id']; ?>" <?php echo (string) ($assignmentRow['office_id'] ?? '') === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name'].' ('.$office['office_code'].')'); ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label">Responsibility Code</label>
                                                                    <select class="form-select assignment-responsibility-code" name="assignments[<?php echo (int) $assignmentIndex; ?>][responsibility_code_id]">
                                                                        <option value="">Select responsibility code</option>
                                                                        <?php foreach($responsibilityCodes as $code): ?>
                                                                            <option value="<?php echo (int)$code['id']; ?>" data-office-id="<?php echo (int)$code['office_id']; ?>" <?php echo (string) ($assignmentRow['responsibility_code_id'] ?? '') === (string) $code['id'] ? 'selected' : ''; ?>><?php echo h($code['code'].' - '.$code['office_name']); ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="form-check form-switch pt-md-4 mt-md-2">
                                                                        <input class="form-check-input assignment-primary" type="radio" name="assignment_primary_index" value="<?php echo (int) $assignmentIndex; ?>" <?php echo ($assignmentRow['is_primary'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label">Primary assignment</label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="form-check form-switch pt-md-4 mt-md-2">
                                                                        <input class="form-check-input" type="checkbox" name="assignments[<?php echo (int) $assignmentIndex; ?>][is_unit_head]" value="1" <?php echo ($assignmentRow['is_unit_head'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label">Unit head</label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <div class="form-check form-switch pt-md-4 mt-md-2">
                                                                        <input class="form-check-input" type="checkbox" name="assignments[<?php echo (int) $assignmentIndex; ?>][is_oic]" value="1" <?php echo ($assignmentRow['is_oic'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label">OIC</label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2">
                                                                    <div class="form-check form-switch pt-md-4 mt-md-2">
                                                                        <input class="form-check-input" type="checkbox" name="assignments[<?php echo (int) $assignmentIndex; ?>][is_active]" value="1" <?php echo ($assignmentRow['is_active'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                                                        <label class="form-check-label">Active</label>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2 d-flex align-items-end justify-content-md-end">
                                                                    <button type="button" class="btn btn-sm btn-outline-danger remove-assignment-row">Remove</button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <datalist id="assignmentDesignationOptions">
                                                <option value="Administrative Aide VI"></option>
                                                <option value="Administrative Officer VI"></option>
                                                <option value="Cashier II"></option>
                                                <option value="Dean"></option>
                                                <option value="Dean/OIC"></option>
                                                <option value="Unit Head"></option>
                                            </datalist>
                                        <?php else: ?>
                                            <div class="row g-3">
                                                <div class="col-md-5">
                                                    <label class="form-label">Position Title</label>
                                                    <input type="text" class="form-control" name="position_title" value="<?php echo h($form['position_title']); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">Office</label>
                                                    <select class="form-select" id="office_id" name="office_id" data-placeholder="Select office">
                                                        <option value="">Select office</option>
                                                        <?php foreach($offices as $office): ?>
                                                            <option value="<?php echo (int)$office['id']; ?>" <?php echo $form['office_id']===(string)$office['id']?'selected':''; ?>><?php echo h($office['office_name'].' ('.$office['office_code'].')'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">Responsibility Code</label>
                                                    <select class="form-select" id="responsibility_code_id" name="responsibility_code_id" data-placeholder="Select responsibility code">
                                                        <option value="">Select responsibility code</option>
                                                        <?php foreach($responsibilityCodes as $code): ?>
                                                            <option value="<?php echo (int)$code['id']; ?>" data-office-id="<?php echo (int)$code['office_id']; ?>" <?php echo $form['responsibility_code_id']===(string)$code['id']?'selected':''; ?>><?php echo h($code['code'].' - '.$code['office_name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="employee-form-actions d-grid gap-2 d-sm-flex justify-content-sm-end">
                                    <?php if($form['id']>0): ?>
                                        <a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary px-4"><?php echo $form['id']>0?'Update Employee':'Save Employee'; ?></button>
                                </div>
                            </div>

                            <div class="employee-encode-side">
                                <div class="employee-panel">
                                    <div class="employee-panel-header">
                                        <div>
                                            <div class="employee-panel-kicker">Snapshot</div>
                                            <h6 class="mb-1">Profile Preview</h6>
                                        </div>
                                    </div>
                                    <div class="employee-panel-body">
                                        <div class="employee-profile-card">
                                            <?php if($form['photo_path']!==''): ?>
                                                <img src="<?php echo h(upload_url($form['photo_path'])); ?>" alt="Employee photo" class="employee-photo-thumb">
                                            <?php else: ?>
                                                <div class="employee-photo-thumb employee-photo-thumb-placeholder"><i class="bi bi-person"></i></div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?php echo h(trim(employee_display_name($form))); ?></div>
                                                <div class="text-muted small"><?php echo h($form['id']>0?$form['employee_no']:$generatedCode); ?></div>
                                            </div>
                                            <div class="employee-profile-meta">
                                                <div class="employee-profile-chip">
                                                    <span>Status</span>
                                                    <span class="badge <?php echo $form['is_active']==='1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active']==='1' ? 'Active' : 'Inactive'; ?></span>
                                                </div>
                                                <div class="employee-profile-chip">
                                                    <span>Assignments</span>
                                                    <strong><?php echo (int) count($assignmentFormRows); ?></strong>
                                                </div>
                                                <div class="employee-profile-chip">
                                                    <span>Driver</span>
                                                    <span><?php echo $form['is_driver']==='1' ? 'Yes' : 'No'; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="employee-panel">
                                    <div class="employee-panel-header">
                                        <div>
                                            <div class="employee-panel-kicker">Controls</div>
                                            <h6 class="mb-1">Status and Photo</h6>
                                        </div>
                                    </div>
                                    <div class="employee-panel-body">
                                        <div class="mb-3">
                                            <label class="form-label">Employee Photo</label>
                                            <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                            <div class="form-text">JPG, PNG, GIF, or WEBP up to 5 MB.</div>
                                        </div>
                                        <?php if($form['photo_path']!==''): ?>
                                            <div class="form-check mb-3">
                                                <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="remove_employee_photo">
                                                <label class="form-check-label" for="remove_employee_photo">Remove current photo</label>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$assignmentsEnabled): ?>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="is_unit_head" value="1" <?php echo $form['is_unit_head']==='1'?'checked':''; ?>>
                                            <label class="form-check-label">Unit head</label>
                                        </div>
                                        <?php endif; ?>
                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="is_driver" value="1" <?php echo $form['is_driver']==='1'?'checked':''; ?>>
                                            <label class="form-check-label">Driver</label>
                                        </div>
                                        <div class="employee-panel-note text-muted small mb-2">
                                            Keep `Active employee` on for retired personnel who still teach or serve part-time. Turn it off only when the person no longer has any active assignment.
                                        </div>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active']==='1'?'checked':''; ?>>
                                            <label class="form-check-label">Active employee</label>
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
                        <input type="search" id="tableSearch" class="form-control" placeholder="Search employee, office, responsibility code, or position">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Status</label>
                        <select id="statusFilter" class="form-select" data-no-select2>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Rows Per Page</label>
                        <select id="perPageSelect" class="form-select" data-no-select2>
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
                            <th data-sort="employee">Employee <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="rc">Responsibility Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="unithead">Unit Head <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="driver">Driver <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                            <th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th>
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
                                            <?php if (!empty($employee['employment_status'])): ?>
                                                <div><small class="text-muted"><?php echo h($employee['employment_status']); ?></small></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div><?php echo h($employee['office_name'] ?? ''); ?></div>
                                    <?php $assignmentSummary = $assignmentSummaryMap[(int) ($employee['id'] ?? 0)] ?? ''; ?>
                                    <?php if ($assignmentSummary !== ''): ?>
                                        <small class="text-muted"><?php echo h($assignmentSummary); ?></small>
                                    <?php endif; ?>
                                </td>
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
                                        <?php else: ?>
                                            <form method="post" onsubmit="return confirm('Reactivate this employee?');" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="reactivate">
                                                <input type="hidden" name="id" value="<?php echo (int)$employee['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button>
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
    var assignmentsEnabled = <?php echo $assignmentsEnabled ? 'true' : 'false'; ?>;
    var officeOptions = <?php echo json_encode(array_map(static function ($office) {
        return [
            'id' => (int) ($office['id'] ?? 0),
            'label' => ($office['office_name'] ?? '') . ' (' . ($office['office_code'] ?? '') . ')',
        ];
    }, $offices), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var responsibilityCodeOptions = <?php echo json_encode(array_map(static function ($code) {
        return [
            'id' => (int) ($code['id'] ?? 0),
            'office_id' => (int) ($code['office_id'] ?? 0),
            'label' => ($code['code'] ?? '') . ' - ' . ($code['office_name'] ?? ''),
        ];
    }, $responsibilityCodes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    function refreshSharedSelect(select) {
        if (window.jQuery && jQuery.fn.select2) {
            jQuery(select).trigger('change');
        }
    }

    function filterResponsibilityCodesForPair(officeSelect, codeSelect, forceAutoSelect) {
        if (!officeSelect || !codeSelect) return;

        var officeId = officeSelect.value;
        var preferredCodeId = '';
        var currentMatchesOffice = false;

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
            if (matches && option.value === codeSelect.value) {
                currentMatchesOffice = true;
            }
        });

        // Auto-select the office's default RC when:
        // - Office is selected AND
        // - Either forced (office just changed), or current RC doesn't belong to this office
        if (officeId !== '' && preferredCodeId !== '' && (forceAutoSelect || !currentMatchesOffice)) {
            codeSelect.value = preferredCodeId;
        } else if (officeId === '') {
            codeSelect.value = '';
        }

        refreshSharedSelect(codeSelect);
    }

    function filterResponsibilityCodes() {
        var officeSelect = document.getElementById('office_id');
        var codeSelect = document.getElementById('responsibility_code_id');
        filterResponsibilityCodesForPair(officeSelect, codeSelect, false);
    }

    function buildOptions(options, selectedValue, includeOfficeId) {
        return options.map(function (option) {
            var attrs = ' value="' + String(option.id) + '"';
            if (includeOfficeId) {
                attrs += ' data-office-id="' + String(option.office_id) + '"';
            }
            if (String(selectedValue || '') === String(option.id)) {
                attrs += ' selected';
            }
            return '<option' + attrs + '>' + option.label.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '</option>';
        }).join('');
    }

    function renumberAssignmentRows() {
        var rows = Array.from(document.querySelectorAll('.assignment-row'));
        rows.forEach(function (row, index) {
            row.dataset.index = String(index);
            row.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (!field.name) return;
                field.name = field.name.replace(/assignments\[\d+\]/, 'assignments[' + index + ']');
                if (field.classList.contains('assignment-primary')) {
                    field.value = String(index);
                }
            });
        });
        var checkedPrimary = document.querySelector('.assignment-primary:checked');
        if (!checkedPrimary && rows.length > 0) {
            var firstPrimary = rows[0].querySelector('.assignment-primary');
            if (firstPrimary) {
                firstPrimary.checked = true;
            }
        }
    }

    function initAssignmentRow(row) {
        var officeSelect = row.querySelector('.assignment-office');
        var codeSelect = row.querySelector('.assignment-responsibility-code');
        if (officeSelect && codeSelect) {
            officeSelect.addEventListener('change', function () {
                filterResponsibilityCodesForPair(officeSelect, codeSelect, true);
            });
            filterResponsibilityCodesForPair(officeSelect, codeSelect, false);
        }
    }

    function createAssignmentRow(index) {
        var wrapper = document.createElement('div');
        wrapper.className = 'border rounded-3 p-3 bg-white assignment-row';
        wrapper.dataset.index = String(index);
        wrapper.innerHTML =
            '<input type="hidden" name="assignments[' + index + '][id]" value="0">' +
            '<div class="row g-3">' +
                '<div class="col-md-4">' +
                    '<label class="form-label">Designation / Position</label>' +
                    '<input type="text" class="form-control assignment-role-title" list="assignmentDesignationOptions" name="assignments[' + index + '][role_title]" placeholder="Administrative Officer VI">' +
                    '<div class="form-text">Encode the employee\'s position for this office assignment.</div>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label class="form-label">Office</label>' +
                    '<select class="form-select assignment-office" name="assignments[' + index + '][office_id]">' +
                        '<option value="">Select office</option>' +
                        buildOptions(officeOptions, '', false) +
                    '</select>' +
                '</div>' +
                '<div class="col-md-4">' +
                    '<label class="form-label">Responsibility Code</label>' +
                    '<select class="form-select assignment-responsibility-code" name="assignments[' + index + '][responsibility_code_id]">' +
                        '<option value="">Select responsibility code</option>' +
                        buildOptions(responsibilityCodeOptions, '', true) +
                    '</select>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<div class="form-check form-switch pt-md-4 mt-md-2">' +
                        '<input class="form-check-input assignment-primary" type="radio" name="assignment_primary_index" value="' + index + '">' +
                        '<label class="form-check-label">Primary assignment</label>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-3">' +
                    '<div class="form-check form-switch pt-md-4 mt-md-2">' +
                        '<input class="form-check-input" type="checkbox" name="assignments[' + index + '][is_unit_head]" value="1">' +
                        '<label class="form-check-label">Unit head</label>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<div class="form-check form-switch pt-md-4 mt-md-2">' +
                        '<input class="form-check-input" type="checkbox" name="assignments[' + index + '][is_oic]" value="1">' +
                        '<label class="form-check-label">OIC</label>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-2">' +
                    '<div class="form-check form-switch pt-md-4 mt-md-2">' +
                        '<input class="form-check-input" type="checkbox" name="assignments[' + index + '][is_active]" value="1" checked>' +
                        '<label class="form-check-label">Active</label>' +
                    '</div>' +
                '</div>' +
                '<div class="col-md-2 d-flex align-items-end justify-content-md-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-assignment-row">Remove</button>' +
                '</div>' +
            '</div>';
        initAssignmentRow(wrapper);
        return wrapper;
    }

    function initAssignmentEditor() {
        if (!assignmentsEnabled) return;
        var rowsContainer = document.getElementById('assignmentRows');
        var addButton = document.getElementById('addAssignmentRow');
        if (!rowsContainer || !addButton) return;

        Array.from(rowsContainer.querySelectorAll('.assignment-row')).forEach(initAssignmentRow);
        renumberAssignmentRows();

        addButton.addEventListener('click', function () {
            var newIndex = rowsContainer.querySelectorAll('.assignment-row').length;
            var newRow = createAssignmentRow(newIndex);
            rowsContainer.appendChild(newRow);
            renumberAssignmentRows();
        });

        rowsContainer.addEventListener('click', function (event) {
            var trigger = event.target.closest('.remove-assignment-row');
            if (!trigger) return;
            var rows = rowsContainer.querySelectorAll('.assignment-row');
            if (rows.length <= 1) {
                var currentRow = trigger.closest('.assignment-row');
                if (!currentRow) return;
                currentRow.querySelectorAll('input[type="text"], input[type="hidden"], select').forEach(function (field) {
                    if (field.type === 'hidden') {
                        field.value = field.name.endsWith('[id]') ? '0' : '';
                    } else {
                        field.value = '';
                    }
                });
                currentRow.querySelectorAll('input[type="checkbox"]').forEach(function (field) {
                    field.checked = field.name.endsWith('[is_active]');
                });
                var primary = currentRow.querySelector('.assignment-primary');
                if (primary) primary.checked = true;
                initAssignmentRow(currentRow);
                return;
            }
            var row = trigger.closest('.assignment-row');
            if (row) {
                row.remove();
                renumberAssignmentRows();
                Array.from(rowsContainer.querySelectorAll('.assignment-row')).forEach(initAssignmentRow);
            }
        });
    }

    function initEmployeeTable() {
        if (typeof window.initDataTable !== 'function') {
            return;
        }

        window.initDataTable('dataTable', {
            searchInputId: 'tableSearch',
            statusFilterId: 'statusFilter',
            prevButtonId: 'prevPage',
            nextButtonId: 'nextPage',
            pageInfoId: 'pageInfo',
            perPageSelectId: 'perPageSelect',
            recordCountId: 'recordCount',
            recordCountFormatter: function (state) {
                var summary = state.totalVisible === 0
                    ? 'Showing 0 of ' + state.totalOverall + ' records'
                    : 'Showing ' + state.rangeStart + ' to ' + state.rangeEnd + ' of ' + state.totalVisible + ' matching records';
                var recordCountMobile = document.getElementById('recordCountMobile');
                if (recordCountMobile) {
                    recordCountMobile.textContent = summary;
                }
                return summary;
            },
            pageInfoFormatter: function (state) {
                return 'Page ' + state.currentPage + ' of ' + state.totalPages + ' (' + state.totalVisible + ' matches)';
            }
        });
    }

    document.getElementById('office_id')?.addEventListener('change', function () {
        var officeSelect = document.getElementById('office_id');
        var codeSelect = document.getElementById('responsibility_code_id');
        filterResponsibilityCodesForPair(officeSelect, codeSelect, true);
    });
    filterResponsibilityCodes();
    initAssignmentEditor();
    initEmployeeTable();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
