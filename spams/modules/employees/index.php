<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

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

$db = db_connect();
$page_title = 'Employees';
$flash = get_flash();
$errors = [];
$employees = [];
$offices = [];
$responsibilityCodes = [];
$form = ['id'=>0,'employee_no'=>'','first_name'=>'','middle_name'=>'','last_name'=>'','suffix_name'=>'','email'=>'','office_id'=>'','responsibility_code_id'=>'','position_title'=>'','employment_status'=>'','is_unit_head'=>'0','is_active'=>'1'];

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
            $form['first_name']=old($_POST,'first_name');
            $form['middle_name']=old($_POST,'middle_name');
            $form['last_name']=old($_POST,'last_name');
            $form['suffix_name']=old($_POST,'suffix_name');
            $form['email']=old($_POST,'email');
            $form['office_id']=old($_POST,'office_id');
            $form['responsibility_code_id']=old($_POST,'responsibility_code_id');
            $form['position_title']=old($_POST,'position_title');
            $form['employment_status']=old($_POST,'employment_status');
            $form['is_unit_head']=isset($_POST['is_unit_head'])?'1':'0';
            $form['is_active']=isset($_POST['is_active'])?'1':'0';

            if ($form['first_name'] === '') $errors[]='First name is required.';
            if ($form['last_name'] === '') $errors[]='Last name is required.';

            $recordId=(int)$form['id'];
            $duplicateStmt=$db->prepare("SELECT id FROM employees WHERE (employee_no = ? OR (email = ? AND ? <> '')) AND id != ? LIMIT 1");
            if($duplicateStmt){
                $duplicateStmt->bind_param('sssi',$form['employee_no'],$form['email'],$form['email'],$recordId);
                $duplicateStmt->execute();
                if($duplicateStmt->get_result()->fetch_assoc()) $errors[]='Employee number or email already exists.';
                $duplicateStmt->close();
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

            if(!$errors){
                $isActive=(int)$form['is_active'];
                $isUnitHead=(int)$form['is_unit_head'];
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
                    $stmt=$db->prepare("UPDATE employees SET employee_no = ?, first_name = ?, middle_name = ?, last_name = ?, suffix_name = ?, email = ?, department_id = NULL, office_id = ?, responsibility_code_id = ?, position_title = ?, employment_status = ?, is_unit_head = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if($stmt){
                        $stmt->bind_param('ssssssiissiiii',$form['employee_no'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isActive,$userId,$recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
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
                                    'first_name' => $form['first_name'],
                                    'middle_name' => $form['middle_name'],
                                    'last_name' => $form['last_name'],
                                    'suffix_name' => $form['suffix_name'],
                                    'email' => $form['email'],
                                    'office_id' => $officeId,
                                    'responsibility_code_id' => $responsibilityCodeId,
                                    'position_title' => $form['position_title'],
                                    'employment_status' => $form['employment_status'],
                                    'is_unit_head' => $isUnitHead,
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success','Employee updated successfully.');
                            redirect('modules/employees/index.php');
                        }
                    }
                } else {
                    $form['employee_no']=next_module_code($db,'employees');
                    $stmt=$db->prepare("INSERT INTO employees (employee_no, first_name, middle_name, last_name, suffix_name, email, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)");
                    if($stmt){
                        $stmt->bind_param('ssssssiissiii',$form['employee_no'],$form['first_name'],$form['middle_name'],$form['last_name'],$form['suffix_name'],$form['email'],$officeId,$responsibilityCodeId,$form['position_title'],$form['employment_status'],$isUnitHead,$isActive,$userId);
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
                                    'first_name' => $form['first_name'],
                                    'middle_name' => $form['middle_name'],
                                    'last_name' => $form['last_name'],
                                    'suffix_name' => $form['suffix_name'],
                                    'email' => $form['email'],
                                    'office_id' => $officeId,
                                    'responsibility_code_id' => $responsibilityCodeId,
                                    'position_title' => $form['position_title'],
                                    'employment_status' => $form['employment_status'],
                                    'is_unit_head' => $isUnitHead,
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success','Employee created successfully.');
                            redirect('modules/employees/index.php');
                        }
                    }
                }
                $errors[]='Unable to save the employee.';
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
            $auditStmt = $db->prepare("SELECT employee_no, first_name, middle_name, last_name, suffix_name FROM employees WHERE id = ? LIMIT 1");
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
        $stmt=$db->prepare("SELECT id, employee_no, first_name, middle_name, last_name, suffix_name, email, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active FROM employees WHERE id = ? LIMIT 1");
        if($stmt){
            $stmt->bind_param('i',$recordId);
            $stmt->execute();
            $record=$stmt->get_result()->fetch_assoc();
            $stmt->close();
            if($record){
                $form=['id'=>(int)$record['id'],'employee_no'=>$record['employee_no'],'first_name'=>$record['first_name'],'middle_name'=>$record['middle_name']??'','last_name'=>$record['last_name'],'suffix_name'=>$record['suffix_name']??'','email'=>$record['email']??'','office_id'=>(string)($record['office_id']??''),'responsibility_code_id'=>(string)($record['responsibility_code_id']??''),'position_title'=>$record['position_title']??'','employment_status'=>$record['employment_status']??'','is_unit_head'=>(string)(int)$record['is_unit_head'],'is_active'=>(string)(int)$record['is_active']];
            }
        }
    }

    $listResult=$db->query("SELECT e.id, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.position_title, e.employment_status, e.is_unit_head, e.is_active, e.created_at, o.office_name, rc.code AS responsibility_code FROM employees e LEFT JOIN offices o ON o.id = e.office_id LEFT JOIN responsibility_codes rc ON rc.id = e.responsibility_code_id ORDER BY e.last_name ASC, e.first_name ASC");
    if($listResult){
        $employees=$listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h5 class="card-title mb-0"><?php echo $form['id']>0?'Edit Employee':'Add New Employee'; ?></h5><div class="text-muted small">Maintain employee records, office assignments, and responsibility codes.</div></div><div class="d-flex gap-2"><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id']>0?'Edit Employee':'Add New'; ?></button><?php if($form['id']>0): ?><a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div><div class="collapse <?php echo $form['id']>0?'show':''; ?>" id="formCollapse"><div class="card-body p-4"><?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?><?php if($flash): ?><div class="alert alert-<?php echo $flash['type']==='success'?'success':'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Employee No.</label><input type="text" class="form-control" name="employee_no" value="<?php echo h($form['id']>0?$form['employee_no']:$generatedCode); ?>" readonly><div class="form-text">Generated automatically using `EMP-YYYY-0001` format.</div></div><div class="col-md-4"><label class="form-label">First Name</label><input type="text" class="form-control" name="first_name" value="<?php echo h($form['first_name']); ?>" required></div><div class="col-md-4"><label class="form-label">Middle Name</label><input type="text" class="form-control" name="middle_name" value="<?php echo h($form['middle_name']); ?>"></div><div class="col-md-4"><label class="form-label">Last Name</label><input type="text" class="form-control" name="last_name" value="<?php echo h($form['last_name']); ?>" required></div><div class="col-md-2"><label class="form-label">Suffix</label><input type="text" class="form-control" name="suffix_name" value="<?php echo h($form['suffix_name']); ?>"></div><div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>"></div><div class="col-md-6"><label class="form-label">Office</label><select class="form-select" id="office_id" name="office_id" data-placeholder="Select office"><option value="">Select office</option><?php foreach($offices as $office): ?><option value="<?php echo (int)$office['id']; ?>" <?php echo $form['office_id']===(string)$office['id']?'selected':''; ?>><?php echo h($office['office_name'].' ('.$office['office_code'].')'); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Responsibility Code</label><select class="form-select" id="responsibility_code_id" name="responsibility_code_id" data-placeholder="Select responsibility code"><option value="">Select responsibility code</option><?php foreach($responsibilityCodes as $code): ?><option value="<?php echo (int)$code['id']; ?>" data-office-id="<?php echo (int)$code['office_id']; ?>" <?php echo $form['responsibility_code_id']===(string)$code['id']?'selected':''; ?>><?php echo h($code['code'].' - '.$code['office_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">Position Title</label><input type="text" class="form-control" name="position_title" value="<?php echo h($form['position_title']); ?>"></div><div class="col-md-3"><label class="form-label">Employment Status</label><input type="text" class="form-control" name="employment_status" value="<?php echo h($form['employment_status']); ?>" placeholder="Regular, Contractual, Job Order"></div><div class="col-md-6 d-flex flex-column justify-content-end gap-2"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_unit_head" value="1" <?php echo $form['is_unit_head']==='1'?'checked':''; ?>><label class="form-check-label">This employee is the unit head for the selected office</label></div><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active']==='1'?'checked':''; ?>><label class="form-check-label">Active employee</label></div></div><div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary"><?php echo $form['id']>0?'Update':'Save'; ?></button><?php if($form['id']>0): ?><a href="<?php echo base_url('modules/employees/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div></form></div></div></div></div><div class="col-12"><div class="card"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="card-title mb-0">Employee List</h5><span id="recordCount" class="text-muted small">Showing <?php echo count($employees); ?> of <?php echo count($employees); ?> records</span></div><button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i>Add New</button></div><div class="d-flex flex-wrap gap-2 align-items-center mb-3"><input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search employees..." style="max-width:300px;"><select id="statusFilter" class="form-select form-select-sm" style="max-width:140px;"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="table-responsive"><table class="table align-middle" id="dataTable"><thead><tr><th data-sort="employee">Employee <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="responsibility">Responsibility Code <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="unit">Unit Head <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead><tbody><?php if($employees): foreach($employees as $employee): ?><tr data-status="<?php echo (int)$employee['is_active']?'active':'inactive'; ?>"><td><div class="fw-semibold"><?php echo h(employee_display_name($employee)); ?></div><small class="text-muted"><?php echo h($employee['employee_no'].($employee['position_title']?' - '.$employee['position_title']:'')); ?></small></td><td><?php echo h($employee['office_name'] ?? ''); ?></td><td><?php echo h($employee['responsibility_code'] ?? ''); ?></td><td><?php echo (int)$employee['is_unit_head']===1?'<span class="badge text-bg-primary">Yes</span>':'<span class="text-muted">No</span>'; ?></td><td><span class="badge <?php echo (int)$employee['is_active']===1?'text-bg-success':'text-bg-secondary'; ?>"><?php echo (int)$employee['is_active']===1?'Active':'Inactive'; ?></span></td><td><?php echo h(date('M d, Y', strtotime($employee['created_at']))); ?></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/employees/index.php?edit='.(int)$employee['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if((int)$employee['is_active']===1): ?><form method="post" onsubmit="return confirm('Deactivate this employee?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$employee['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if(($_SESSION['user_role']??'')==='Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int)$employee['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="7" class="text-center text-muted py-4">No employees found yet.</td></tr><?php endif; ?></tbody></table></div><div class="d-flex align-items-center gap-3 mt-2 flex-wrap"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button><select id="perPageSelect" class="form-select form-select-sm" style="width:auto;"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div></div></div></div></section>
<script>(function(){var perPage=25,currentPage=1,sortCol=-1,sortDir='asc';function getRows(){return Array.from(document.querySelectorAll('#dataTable tbody tr'));}function updateRecordCount(total,overall){var node=document.getElementById('recordCount');if(node)node.textContent='Showing '+total+' of '+overall+' records';}function renderPage(){var allRows=getRows(),rows=allRows.filter(function(row){return row.dataset.visible!=='0';});var total=rows.length,pages=Math.max(1,Math.ceil(total/perPage));currentPage=Math.min(currentPage,pages);var start=(currentPage-1)*perPage,end=start+perPage;allRows.forEach(function(row){row.style.display='none';});rows.slice(start,end).forEach(function(row){row.style.display='';});updateRecordCount(total,allRows.length);document.getElementById('pageInfo').textContent='Page '+currentPage+' of '+pages+' ('+total+' records)';document.getElementById('prevPage').disabled=currentPage<=1;document.getElementById('nextPage').disabled=currentPage>=pages;}function applyFilters(){var term=((document.getElementById('tableSearch')||{}).value||'').toLowerCase();var status=((document.getElementById('statusFilter')||{}).value||'');getRows().forEach(function(row){row.dataset.visible=((!term||row.textContent.toLowerCase().includes(term))&&(!status||row.dataset.status===status))?'1':'0';});currentPage=1;renderPage();}function refreshSharedSelect(select){if(window.jQuery&&jQuery.fn.select2)jQuery(select).trigger('change.select2');}function filterResponsibilityCodes(){var officeSelect=document.getElementById('office_id');var codeSelect=document.getElementById('responsibility_code_id');if(!officeSelect||!codeSelect)return;var officeId=officeSelect.value;var preferredCodeId='';Array.from(codeSelect.options).forEach(function(option,index){if(index===0){option.hidden=false;return;}var matches=!officeId||option.getAttribute('data-office-id')===officeId;option.hidden=!matches;if(matches&&officeId!==''&&!preferredCodeId)preferredCodeId=option.value;if(!matches&&option.selected)codeSelect.value='';});var selectedOption=codeSelect.selectedOptions.length?codeSelect.selectedOptions[0]:null;if(officeId!==''&&(!codeSelect.value||!selectedOption||selectedOption.hidden)&&preferredCodeId!=='')codeSelect.value=preferredCodeId;refreshSharedSelect(codeSelect);}document.getElementById('tableSearch')?.addEventListener('input',applyFilters);document.getElementById('statusFilter')?.addEventListener('change',applyFilters);document.getElementById('prevPage')?.addEventListener('click',function(){currentPage--;renderPage();});document.getElementById('nextPage')?.addEventListener('click',function(){currentPage++;renderPage();});document.getElementById('perPageSelect')?.addEventListener('change',function(){perPage=parseInt(this.value,10)||25;currentPage=1;renderPage();});document.querySelectorAll('#dataTable th[data-sort]').forEach(function(th,idx){th.style.cursor='pointer';th.addEventListener('click',function(){var tbody=document.querySelector('#dataTable tbody');var rows=Array.from(tbody.querySelectorAll('tr'));var dir=(sortCol===idx&&sortDir==='asc')?'desc':'asc';sortCol=idx;sortDir=dir;rows.sort(function(a,b){var at=a.cells[idx]?a.cells[idx].textContent.trim().toLowerCase():'';var bt=b.cells[idx]?b.cells[idx].textContent.trim().toLowerCase():'';return dir==='asc'?at.localeCompare(bt):bt.localeCompare(at);});rows.forEach(function(row){tbody.appendChild(row);});document.querySelectorAll('#dataTable th[data-sort] i').forEach(function(icon){icon.className='bi bi-arrow-down-up text-muted small';});var icon=th.querySelector('i');if(icon)icon.className='bi bi-arrow-'+(dir==='asc'?'up':'down')+' text-primary small';renderPage();});});document.getElementById('office_id')?.addEventListener('change',filterResponsibilityCodes);if(window.jQuery&&jQuery.fn.select2){jQuery('#office_id, #responsibility_code_id').select2({width:'100%'});}filterResponsibilityCodes();applyFilters();})();</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
