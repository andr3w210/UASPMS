<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Property Officer');

$db = db();
$page_title = 'PAR/ICS Reconciliation';
$errors = [];
$flash = get_flash();
$reconciliationId = (int) ($_GET['id'] ?? 0);

function pr_norm(string $value): string
{
    $value = strtolower(trim($value));
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function pr_decimal(string $value): ?float
{
    $value = str_replace([',', ' '], '', trim($value));
    return $value === '' || !is_numeric($value) ? null : (float) $value;
}

function pr_col_index(string $letters): int
{
    $result = 0;
    foreach (str_split(strtoupper($letters)) as $letter) {
        $result = ($result * 26) + ord($letter) - 64;
    }
    return $result - 1;
}

function pr_parse_upload(array $file): array
{
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $handle = fopen((string) $file['tmp_name'], 'r');
        if (!$handle) throw new RuntimeException('Unable to open the CSV file.');
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) $rows[] = array_map(static fn($v) => trim((string) $v), $row);
        fclose($handle);
        return $rows;
    }
    if ($ext !== 'xlsx') throw new RuntimeException('The item list must be CSV or XLSX.');
    if (!class_exists('ZipArchive')) throw new RuntimeException('XLSX requires PHP ZipArchive. Use CSV or enable PHP zip.');
    $zip = new ZipArchive();
    if ($zip->open((string) $file['tmp_name']) !== true) throw new RuntimeException('Unable to open the XLSX file.');
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false && ($doc = simplexml_load_string($xml))) {
        foreach ($doc->si as $item) $strings[] = isset($item->t) ? trim((string) $item->t) : trim(implode('', array_map(static fn($r) => (string) $r->t, iterator_to_array($item->r))));
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close();
    if ($sheetXml === false || !($sheet = simplexml_load_string($sheetXml))) throw new RuntimeException('Unable to read the first XLSX worksheet.');
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            preg_match('/([A-Z]+)/', (string) $cell['r'], $m); $index = isset($m[1]) ? pr_col_index($m[1]) : count($values);
            $type = (string) $cell['t'];
            $values[$index] = $type === 's' ? ($strings[(int) $cell->v] ?? '') : ($type === 'inlineStr' ? trim((string) $cell->is->t) : trim((string) ($cell->v ?? '')));
        }
        if ($values) { ksort($values); $rows[] = array_values($values); }
    }
    return $rows;
}

function pr_store_evidence(array $file, array &$errors): ?string
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { $errors[] = 'Upload a PDF, JPG, or PNG hard-copy document.'; return null; }
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 15728640) { $errors[] = 'The hard-copy document must be 15 MB or smaller.'; return null; }
    $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime = $finfo ? (string) finfo_file($finfo, (string) $file['tmp_name']) : ''; if ($finfo) finfo_close($finfo);
    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) { $errors[] = 'Only PDF, JPG, and PNG hard-copy documents are allowed.'; return null; }
    $dir = ensure_upload_directory('reconciliations/' . date('Y'));
    if ($dir === null) { $errors[] = 'The upload folder is not writable.'; return null; }
    $name = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) { $errors[] = 'Unable to save the hard-copy document.'; return null; }
    return 'reconciliations/' . date('Y') . '/' . $name;
}

function pr_distribution_items(mysqli $db): array
{
    $sql = "SELECT di.id, did.id AS detail_id, d.document_type, d.document_no, di.quantity_distributed, di.unit_cost, di.line_total, di.reconciled_item_description, poi.item_description, COALESCE(u.abbreviation, u.uom_name, '') AS unit,
        CONCAT_WS(' ', NULLIF(did.brand,''), NULLIF(did.model,''), NULLIF(did.serial_no,'')) AS details,
        COALESCE(did.current_office_id, d.office_id) AS office_id, COALESCE(did.current_employee_id, d.employee_id) AS employee_id,
        o.office_name, CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name, e.suffix_name) AS employee_name
        FROM distribution_items di INNER JOIN receiving_items ri ON ri.id=di.receiving_item_id INNER JOIN purchase_order_items poi ON poi.id=ri.purchase_order_item_id
        INNER JOIN distributions d ON d.id=di.distribution_id LEFT JOIN unit_of_measures u ON u.id=poi.unit_of_measure_id LEFT JOIN distribution_item_details did ON did.distribution_item_id=di.id
        LEFT JOIN offices o ON o.id=COALESCE(did.current_office_id, d.office_id) LEFT JOIN employees e ON e.id=COALESCE(did.current_employee_id, d.employee_id)
        WHERE d.status='posted' ORDER BY d.distribution_date DESC, di.id DESC, did.id DESC";
    $result = $db->query($sql); return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function pr_find_match(array $hardcopy, array $systemItems): ?array
{
    $needle = pr_norm((string) $hardcopy['description']); $tokens = array_filter(explode(' ', $needle), static fn($v) => strlen($v) >= 4);
    $best = null; $bestScore = 0;
    foreach ($systemItems as $item) {
        $haystack = pr_norm(trim((string) ($item['reconciled_item_description'] ?: $item['item_description']) . ' ' . (string) $item['details']));
        $score = 0; foreach ($tokens as $token) if (str_contains($haystack, $token)) $score++;
        if ($score > $bestScore) { $best = $item; $bestScore = $score; }
    }
    return $bestScore >= 2 ? $best : null;
}

if (isset($_GET['download_template'])) {
    $path = dirname(__DIR__, 3) . '/database/templates/par_ics_reconciliation_template.csv';
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="par_ics_reconciliation_template.csv"'); readfile($path); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) $errors[] = 'Invalid CSRF token.';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && str_starts_with((string) ($_POST['action'] ?? ''), 'quick_add_')) {
    $action = (string) $_POST['action'];
    $userId = (int) current_user_id();
    try {
        if ($action === 'quick_add_office') {
            $officeCode = strtoupper(trim((string) ($_POST['office_code'] ?? '')));
            $officeName = trim((string) ($_POST['office_name'] ?? ''));
            if ($officeCode === '' || $officeName === '') throw new RuntimeException('Office code and office name are required.');
            $stmt = $db->prepare('INSERT INTO offices (office_code,office_name,is_active,created_by,updated_by) VALUES (?, ?, 1, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare the office record.');
            $stmt->bind_param('ssii', $officeCode, $officeName, $userId, $userId);
            if (!$stmt->execute()) throw new RuntimeException('Office code or name already exists.');
            $stmt->close();
            write_audit_log($db, ['action'=>'create','table_name'=>'offices','module_name'=>'par_ics_reconciliation','record_type'=>'Office','action_name'=>'quick_add_office','description'=>'Added an office from PAR/ICS Reconciliation.']);
            set_flash('success', 'Office added. You can now select it.');
        } elseif ($action === 'quick_add_responsibility_code') {
            $officeId = (int) ($_POST['rc_office_id'] ?? 0);
            $code = strtoupper(trim((string) ($_POST['responsibility_code'] ?? '')));
            $description = trim((string) ($_POST['responsibility_description'] ?? ''));
            if ($officeId <= 0 || $code === '') throw new RuntimeException('Select an office and enter a responsibility code.');
            $stmt = $db->prepare('INSERT INTO responsibility_codes (office_id,code,description,is_active,created_by,updated_by) VALUES (?, ?, ?, 1, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare the responsibility code.');
            $stmt->bind_param('issii', $officeId, $code, $description, $userId, $userId);
            if (!$stmt->execute()) throw new RuntimeException('That responsibility code already exists for the office.');
            $stmt->close();
            write_audit_log($db, ['action'=>'create','table_name'=>'responsibility_codes','module_name'=>'par_ics_reconciliation','record_type'=>'Responsibility Code','action_name'=>'quick_add_responsibility_code','description'=>'Added a responsibility code from PAR/ICS Reconciliation.']);
            set_flash('success', 'Responsibility code added.');
        } elseif ($action === 'quick_add_employee') {
            $employeeNo = trim((string) ($_POST['employee_no'] ?? ''));
            $firstName = trim((string) ($_POST['first_name'] ?? ''));
            $lastName = trim((string) ($_POST['last_name'] ?? ''));
            $officeId = (int) ($_POST['employee_office_id'] ?? 0);
            $responsibilityCodeId = (int) ($_POST['employee_responsibility_code_id'] ?? 0);
            $roleTitle = trim((string) ($_POST['role_title'] ?? 'Employee'));
            if ($employeeNo === '' || $firstName === '' || $lastName === '' || $officeId <= 0) throw new RuntimeException('Employee no., first name, last name, and office are required.');
            if ($responsibilityCodeId > 0) { $check=$db->prepare('SELECT id FROM responsibility_codes WHERE id=? AND office_id=? AND is_active=1');$check->bind_param('ii',$responsibilityCodeId,$officeId);$check->execute();$valid=(bool)$check->get_result()->fetch_assoc();$check->close();if(!$valid) throw new RuntimeException('The responsibility code must belong to the selected office.'); }
            $stmt = $db->prepare('INSERT INTO employees (employee_no,first_name,last_name,office_id,responsibility_code_id,position_title,is_active,created_by,updated_by) VALUES (?, ?, ?, ?, NULLIF(?,0), ?, 1, ?, ?)');
            if (!$stmt) throw new RuntimeException('Unable to prepare the employee record.');
            $stmt->bind_param('sssiisii', $employeeNo, $firstName, $lastName, $officeId, $responsibilityCodeId, $roleTitle, $userId, $userId);
            if (!$stmt->execute()) throw new RuntimeException('Employee number already exists.');
            $employeeId=(int)$stmt->insert_id; $stmt->close();
            if (!employee_save_assignments($db, $employeeId, [['office_id'=>(string)$officeId,'responsibility_code_id'=>(string)$responsibilityCodeId,'role_title'=>$roleTitle,'is_primary'=>'1','is_active'=>'1']], $userId)) throw new RuntimeException('Employee was created but its office assignment could not be saved.');
            write_audit_log($db, ['action'=>'create','table_name'=>'employees','record_id'=>$employeeId,'module_name'=>'par_ics_reconciliation','record_type'=>'Employee','action_name'=>'quick_add_employee','description'=>'Added an employee and office assignment from PAR/ICS Reconciliation.']);
            set_flash('success', 'Employee and office assignment added.');
        } else { throw new RuntimeException('Unknown Quick Add action.'); }
        redirect('modules/property/par_ics_reconciliation.php');
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && ($_POST['action'] ?? '') === 'create') {
    $hardcopyOfficeId = (int) ($_POST['hardcopy_office_id'] ?? 0); $hardcopyEmployeeId = (int) ($_POST['hardcopy_employee_id'] ?? 0); $hardcopy = $_FILES['hardcopy'] ?? []; $import = $_FILES['item_list'] ?? []; $hardcopyResponsibilityCodeId = 0;
    if ($hardcopyOfficeId <= 0 || $hardcopyEmployeeId <= 0) $errors[] = 'Select the office and accountable person stated on the hard copy.';
    if (!$errors) { $assignmentStmt=$db->prepare('SELECT responsibility_code_id FROM employee_assignments WHERE employee_id=? AND office_id=? AND is_active=1 LIMIT 1'); if(!$assignmentStmt){$errors[]='Unable to verify the employee office assignment.';}else{$assignmentStmt->bind_param('ii',$hardcopyEmployeeId,$hardcopyOfficeId);$assignmentStmt->execute();$assignment=$assignmentStmt->get_result()->fetch_assoc()?:[];$assignmentStmt->close();$hardcopyResponsibilityCodeId=(int)($assignment['responsibility_code_id']??0);if(!$assignment)$errors[]='The selected employee has no active assignment to the selected office.';} }
    if ((int) ($import['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Upload the CSV or XLSX item list.';
    $evidencePath = !$errors ? pr_store_evidence($hardcopy, $errors) : null;
    try { $rawRows = !$errors ? pr_parse_upload($import) : []; } catch (Throwable $e) { $errors[] = $e->getMessage(); $rawRows = []; }
    if (!$errors) {
        $headers = array_map('pr_norm', array_shift($rawRows) ?: []); $columns = array_flip($headers); $required = ['description','unit','quantity','unit cost','total cost'];
        foreach ($required as $requiredColumn) if (!isset($columns[$requiredColumn])) $errors[] = 'The item list is missing the ' . $requiredColumn . ' column.';
        $items = [];
        foreach ($rawRows as $row) { $description = trim((string) ($row[$columns['description'] ?? -1] ?? '')); if ($description !== '') $items[] = ['description'=>$description,'unit'=>trim((string)($row[$columns['unit'] ?? -1] ?? '')),'quantity'=>pr_decimal((string)($row[$columns['quantity'] ?? -1] ?? '')),'unit_cost'=>pr_decimal((string)($row[$columns['unit cost'] ?? -1] ?? '')),'total_cost'=>pr_decimal((string)($row[$columns['total cost'] ?? -1] ?? ''))]; }
        if (!$items) $errors[] = 'The item list has no usable rows.';
    }
    if (!$errors) {
        $systemItems = pr_distribution_items($db); if (!$systemItems) $errors[] = 'There are no posted PAR/ICS items to search.';
    }
    if (!$errors) {
        $userId = (int) current_user_id(); $docName = substr(basename((string) ($hardcopy['name'] ?? '')), 0, 255); $importName = substr(basename((string) ($import['name'] ?? '')), 0, 255);
        $db->begin_transaction();
        try {
            $nullDistributionId = null; $stmt = $db->prepare('INSERT INTO par_ics_reconciliations (distribution_id,hardcopy_office_id,hardcopy_employee_id,hardcopy_responsibility_code_id,evidence_path,evidence_original_name,import_original_name,created_by) VALUES (?,?,?,?,?,?,?,?)'); $stmt->bind_param('iiiisssi',$nullDistributionId,$hardcopyOfficeId,$hardcopyEmployeeId,$hardcopyResponsibilityCodeId,$evidencePath,$docName,$importName,$userId); $stmt->execute(); $newId=(int)$stmt->insert_id; $stmt->close();
            $insert = $db->prepare('INSERT INTO par_ics_reconciliation_items (reconciliation_id,distribution_item_id,distribution_item_detail_id,description,unit,quantity,unit_cost,total_cost,comparison_status) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach ($items as $item) { $match=pr_find_match($item,$systemItems); $itemId=$match ? (int)$match['id'] : null; $detailId=$match ? (int)($match['detail_id'] ?? 0) : null; $different=!$match || abs((float)$item['quantity']-(float)$match['quantity_distributed'])>.009 || abs((float)$item['unit_cost']-(float)$match['unit_cost'])>.009 || abs((float)$item['total_cost']-(float)$match['line_total'])>.009 || pr_norm($item['unit'])!=='' && pr_norm($item['unit'])!==pr_norm((string)$match['unit']); $status=$match?($different?'different':'matched'):'not_found'; $insert->bind_param('iiissddds',$newId,$itemId,$detailId,$item['description'],$item['unit'],$item['quantity'],$item['unit_cost'],$item['total_cost'],$status); $insert->execute(); }
            $insert->close(); $db->commit(); write_audit_log($db,['action'=>'create','table_name'=>'par_ics_reconciliations','record_id'=>$newId,'module_name'=>'par_ics_reconciliation','record_type'=>'PAR/ICS Reconciliation','action_name'=>'create_reconciliation','description'=>'Created a PAR/ICS reconciliation with hard-copy evidence.']); redirect('modules/property/par_ics_reconciliation.php?id='.$newId);
        } catch (Throwable $e) { $db->rollback(); if ($evidencePath) delete_uploaded_file($evidencePath); $errors[]='Unable to create reconciliation: '.$e->getMessage(); }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && ($_POST['action'] ?? '') === 'resolve_item') {
    $itemId=(int)($_POST['item_id']??0); $resolution=$_POST['resolution']??''; $notes=trim((string)($_POST['notes']??''));
    $stmt=$db->prepare('SELECT pri.*, pr.hardcopy_office_id, pr.hardcopy_employee_id, pr.hardcopy_responsibility_code_id FROM par_ics_reconciliation_items pri INNER JOIN par_ics_reconciliations pr ON pr.id=pri.reconciliation_id WHERE pri.id=?'); $stmt->bind_param('i',$itemId); $stmt->execute(); $item=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$item || !in_array($resolution,['updated','no_action'],true)) $errors[]='Invalid reconciliation resolution.';
    if (!$errors) { $db->begin_transaction(); try { if ($resolution==='updated') { if (!(int)$item['distribution_item_id']) throw new RuntimeException('No system item was matched. Choose no action and resolve it manually.'); $qty=(float)$item['quantity'];$cost=(float)$item['unit_cost'];$total=(float)$item['total_cost'];$description=(string)$item['description']; $update=$db->prepare('UPDATE distribution_items SET quantity_distributed=?,unit_cost=?,line_total=?,reconciled_item_description=? WHERE id=?'); $update->bind_param('dddsi',$qty,$cost,$total,$description,$item['distribution_item_id']); $update->execute();$update->close(); if ((int)$item['distribution_item_detail_id']) { $officeId=(int)$item['hardcopy_office_id']; $employeeId=(int)$item['hardcopy_employee_id']; $responsibilityCodeId=(int)$item['hardcopy_responsibility_code_id']; $update=$db->prepare('UPDATE distribution_item_details SET current_office_id=?, current_employee_id=?, current_responsibility_code_id=NULLIF(?,0) WHERE id=?'); $update->bind_param('iiii',$officeId,$employeeId,$responsibilityCodeId,$item['distribution_item_detail_id']); $update->execute(); $update->close(); } } $userId=(int)current_user_id(); $update=$db->prepare("UPDATE par_ics_reconciliation_items SET resolution_status=?, resolution_notes=?, resolved_by=?, resolved_at=NOW() WHERE id=?");$update->bind_param('ssii',$resolution,$notes,$userId,$itemId);$update->execute();$update->close();$db->commit();write_audit_log($db,['action'=>'update','table_name'=>'par_ics_reconciliation_items','record_id'=>$itemId,'module_name'=>'par_ics_reconciliation','record_type'=>'PAR/ICS Reconciliation Item','action_name'=>$resolution==='updated'?'update_distribution_from_hardcopy':'record_no_action','description'=>$resolution==='updated'?'Updated a matched PAR/ICS line and its accountable office, person, and responsibility code from hard-copy values.':'Recorded no action for a PAR/ICS discrepancy.']); redirect('modules/property/par_ics_reconciliation.php?id='.(int)$item['reconciliation_id']); } catch(Throwable $e){$db->rollback();$errors[]='Unable to save resolution: '.$e->getMessage();} }
}

$offices=[];$assignments=[]; if($db){$result=$db->query("SELECT id,office_name FROM offices WHERE is_active=1 ORDER BY office_name");if($result)$offices=$result->fetch_all(MYSQLI_ASSOC);$result=$db->query("SELECT ea.office_id,ea.employee_id,ea.responsibility_code_id,CONCAT_WS(' ',e.first_name,e.middle_name,e.last_name,e.suffix_name) AS employee_name,rc.code AS responsibility_code FROM employee_assignments ea INNER JOIN employees e ON e.id=ea.employee_id LEFT JOIN responsibility_codes rc ON rc.id=ea.responsibility_code_id WHERE ea.is_active=1 AND e.is_active=1 ORDER BY e.last_name,e.first_name");if($result)$assignments=$result->fetch_all(MYSQLI_ASSOC);}
$reconciliation=null;$rows=[];
if($db&&$reconciliationId>0){$stmt=$db->prepare("SELECT pr.*,ho.office_name AS hardcopy_office_name,CONCAT_WS(' ',he.first_name,he.middle_name,he.last_name,he.suffix_name) AS hardcopy_employee_name,hrc.code AS hardcopy_responsibility_code FROM par_ics_reconciliations pr LEFT JOIN offices ho ON ho.id=pr.hardcopy_office_id LEFT JOIN employees he ON he.id=pr.hardcopy_employee_id LEFT JOIN responsibility_codes hrc ON hrc.id=pr.hardcopy_responsibility_code_id WHERE pr.id=?");$stmt->bind_param('i',$reconciliationId);$stmt->execute();$reconciliation=$stmt->get_result()->fetch_assoc();$stmt->close();if($reconciliation){$stmt=$db->prepare("SELECT pri.*,di.quantity_distributed AS system_quantity,di.unit_cost AS system_unit_cost,di.line_total AS system_total,di.reconciled_item_description,poi.item_description,COALESCE(u.abbreviation,u.uom_name,'') AS system_unit,d.document_type,d.document_no,COALESCE(did.current_office_id,d.office_id) AS system_office_id,COALESCE(did.current_employee_id,d.employee_id) AS system_employee_id,did.current_responsibility_code_id AS system_responsibility_code_id,so.office_name AS system_office_name,CONCAT_WS(' ',se.first_name,se.middle_name,se.last_name,se.suffix_name) AS system_employee_name,src.code AS system_responsibility_code FROM par_ics_reconciliation_items pri LEFT JOIN distribution_items di ON di.id=pri.distribution_item_id LEFT JOIN distribution_item_details did ON did.id=pri.distribution_item_detail_id LEFT JOIN distributions d ON d.id=di.distribution_id LEFT JOIN receiving_items ri ON ri.id=di.receiving_item_id LEFT JOIN purchase_order_items poi ON poi.id=ri.purchase_order_item_id LEFT JOIN unit_of_measures u ON u.id=poi.unit_of_measure_id LEFT JOIN offices so ON so.id=COALESCE(did.current_office_id,d.office_id) LEFT JOIN employees se ON se.id=COALESCE(did.current_employee_id,d.employee_id) LEFT JOIN responsibility_codes src ON src.id=did.current_responsibility_code_id WHERE pri.reconciliation_id=? ORDER BY pri.id");$stmt->bind_param('i',$reconciliationId);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();}}
require_once __DIR__ . '/../../includes/header.php'; require_once __DIR__ . '/../../includes/sidebar.php'; require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
 <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4"><div><h1 class="page-title mb-1">PAR/ICS Reconciliation</h1><p class="text-muted mb-0">Compare a hard-copy PAR/ICS item list with the posted system document, then approve each change explicitly.</p></div><?php if(!$reconciliation): ?><a class="btn btn-outline-primary" href="?download_template=1"><i class="bi bi-download me-1"></i>CSV Template</a><?php endif; ?></div>
 <?php if($flash): ?><div class="alert alert-<?php echo h($flash['type']); ?>"><?php echo h($flash['message']); ?></div><?php endif; ?><?php if($errors): ?><div class="alert alert-danger"><?php foreach($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
 <?php if(!$reconciliation): ?>
 <div class="card"><div class="card-body"><h5 class="card-title">Start Reconciliation</h5><p class="small text-muted">The imported items are searched against every posted PAR/ICS. Select an office first; only its actively assigned employees can be selected, and their responsibility code is recorded automatically.</p><form method="post" enctype="multipart/form-data" class="row g-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="create"><div class="col-md-4"><label class="form-label">Office on hard copy</label><select required id="hardcopy_office_id" name="hardcopy_office_id" class="form-select"><option value="">Select office</option><?php foreach($offices as $office): ?><option value="<?php echo (int)$office['id']; ?>"><?php echo h($office['office_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Accountable person on hard copy</label><select required id="hardcopy_employee_id" name="hardcopy_employee_id" class="form-select" disabled><option value="">Select office first</option><?php foreach($assignments as $assignment): ?><option value="<?php echo (int)$assignment['employee_id']; ?>" data-office-id="<?php echo (int)$assignment['office_id']; ?>" hidden disabled><?php echo h($assignment['employee_name'].' · RC: '.($assignment['responsibility_code'] ?: 'None')); ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Hard-copy evidence</label><input required type="file" name="hardcopy" class="form-control" accept=".pdf,.jpg,.jpeg,.png"></div><div class="col-md-2"><label class="form-label">Item list</label><input required type="file" name="item_list" class="form-control" accept=".csv,.xlsx"></div><div class="col-12"><button class="btn btn-primary">Upload and Search System PAR/ICS</button></div></form></div></div>
 <?php else: ?>
 <div class="card mb-3"><div class="card-body d-flex justify-content-between flex-wrap gap-2"><div><div class="small text-muted">Accountability stated on hard copy</div><div class="fw-semibold"><?php echo h($reconciliation['hardcopy_office_name'].' · '.$reconciliation['hardcopy_employee_name']); ?></div></div><a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="<?php echo h(upload_url($reconciliation['evidence_path'])); ?>">View Hard Copy</a></div></div>
 <div class="card"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Hard-copy item</th><th>Hard-copy values</th><th>Matched system PAR/ICS</th><th>Current accountability</th><th>Status / Resolution</th></tr></thead><tbody><?php foreach($rows as $row): ?><tr><td><?php echo h($row['description']); ?></td><td class="small">Qty: <?php echo h($row['quantity']); ?><br>Unit: <?php echo h($row['unit']); ?><br>Cost: <?php echo h(format_currency((float)$row['unit_cost'])); ?><br>Total: <?php echo h(format_currency((float)$row['total_cost'])); ?></td><td class="small"><?php if($row['distribution_item_id']): ?><strong><?php echo h(strtoupper($row['document_type']).' '.$row['document_no']); ?></strong><br><?php echo h($row['reconciled_item_description'] ?: $row['item_description']); ?><br>Qty: <?php echo h($row['system_quantity']); ?> · <?php echo h($row['system_unit']); ?><br><?php echo h(format_currency((float)$row['system_unit_cost'])); ?> / <?php echo h(format_currency((float)$row['system_total'])); ?><?php else: ?><span class="text-muted">Not found in system</span><?php endif; ?></td><td class="small"><?php if($row['distribution_item_id']): ?><?php echo h($row['system_office_name'] ?: 'No office'); ?><br><?php echo h($row['system_employee_name'] ?: 'No accountable person'); ?><?php if((int)$row['system_office_id'] !== (int)$reconciliation['hardcopy_office_id'] || (int)$row['system_employee_id'] !== (int)$reconciliation['hardcopy_employee_id']): ?><div class="text-warning mt-1">Differs from hard copy</div><?php endif; ?><?php endif; ?></td><td><span class="badge <?php echo $row['comparison_status']==='matched'?'text-bg-success':($row['comparison_status']==='different'?'text-bg-warning':'text-bg-danger'); ?>"><?php echo h(ucfirst(str_replace('_',' ',$row['comparison_status']))); ?></span><?php if($row['resolution_status']!=='pending'): ?><div class="mt-1"><span class="badge text-bg-secondary"><?php echo h(ucfirst(str_replace('_',' ',$row['resolution_status']))); ?></span></div><?php if($row['resolution_notes']): ?><div class="small mt-1"><?php echo h($row['resolution_notes']); ?></div><?php endif; ?><?php else: ?><form method="post" class="d-grid gap-1 mt-2"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="resolve_item"><input type="hidden" name="item_id" value="<?php echo (int)$row['id']; ?>"><input name="notes" class="form-control form-control-sm" placeholder="Resolution notes"><button name="resolution" value="updated" class="btn btn-sm btn-primary" <?php echo !$row['distribution_item_id']?'disabled':''; ?> onclick="return confirm('Update this matched system PAR/ICS line and its accountable office/person using the hard copy?');">Apply correction</button><button name="resolution" value="no_action" class="btn btn-sm btn-outline-secondary">No action</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">No imported items found.</td></tr><?php endif; ?></tbody></table></div></div></div>
 <?php endif; ?>
</section>
<?php if (!$reconciliation): ?>
<div class="position-fixed bottom-0 end-0 p-4 d-flex flex-column gap-2" style="z-index: 1040;">
    <button type="button" class="btn btn-primary rounded-pill shadow" data-bs-toggle="modal" data-bs-target="#quickAddEmployee"><i class="bi bi-person-plus me-1"></i>Add Employee</button>
    <button type="button" class="btn btn-outline-primary bg-white rounded-pill shadow" data-bs-toggle="modal" data-bs-target="#quickAddOffice"><i class="bi bi-building-add me-1"></i>Add Office</button>
    <button type="button" class="btn btn-outline-primary bg-white rounded-pill shadow" data-bs-toggle="modal" data-bs-target="#quickAddResponsibility"><i class="bi bi-plus-circle me-1"></i>Add Responsibility Code</button>
</div>
<div class="modal fade" id="quickAddOffice" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Quick Add Office</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_add_office"><label class="form-label">Office Code</label><input required name="office_code" class="form-control mb-3" maxlength="50"><label class="form-label">Office Name</label><input required name="office_name" class="form-control" maxlength="150"></div><div class="modal-footer"><button class="btn btn-primary">Save Office</button></div></form></div></div>
<div class="modal fade" id="quickAddResponsibility" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Quick Add Responsibility Code</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_add_responsibility_code"><label class="form-label">Office</label><select required name="rc_office_id" class="form-select mb-3"><option value="">Select office</option><?php foreach($offices as $office): ?><option value="<?php echo (int)$office['id']; ?>"><?php echo h($office['office_name']); ?></option><?php endforeach; ?></select><label class="form-label">Code</label><input required name="responsibility_code" class="form-control mb-3" maxlength="50"><label class="form-label">Description</label><input name="responsibility_description" class="form-control" maxlength="255"></div><div class="modal-footer"><button class="btn btn-primary">Save Code</button></div></form></div></div>
<div class="modal fade" id="quickAddEmployee" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h5 class="modal-title">Quick Add Employee</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="quick_add_employee"><label class="form-label">Employee No.</label><input required name="employee_no" class="form-control mb-2" maxlength="50"><label class="form-label">First Name</label><input required name="first_name" class="form-control mb-2" maxlength="100"><label class="form-label">Last Name</label><input required name="last_name" class="form-control mb-2" maxlength="100"><label class="form-label">Office</label><select required name="employee_office_id" class="form-select mb-2"><option value="">Select office</option><?php foreach($offices as $office): ?><option value="<?php echo (int)$office['id']; ?>"><?php echo h($office['office_name']); ?></option><?php endforeach; ?></select><label class="form-label">Responsibility Code</label><select name="employee_responsibility_code_id" class="form-select mb-2"><option value="0">No code yet</option><?php $codes=$db->query("SELECT rc.id,rc.office_id,rc.code,o.office_name FROM responsibility_codes rc INNER JOIN offices o ON o.id=rc.office_id WHERE rc.is_active=1 ORDER BY o.office_name,rc.code"); foreach(($codes?$codes->fetch_all(MYSQLI_ASSOC):[]) as $code): ?><option value="<?php echo (int)$code['id']; ?>"><?php echo h($code['office_name'].' · '.$code['code']); ?></option><?php endforeach; ?></select><label class="form-label">Role Title</label><input name="role_title" class="form-control" value="Employee" maxlength="255"></div><div class="modal-footer"><button class="btn btn-primary">Save Employee</button></div></form></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const office = document.getElementById('hardcopy_office_id');
    const employee = document.getElementById('hardcopy_employee_id');
    if (!office || !employee) return;
    office.addEventListener('change', function () {
        const officeId = office.value;
        employee.value = '';
        employee.disabled = !officeId;
        employee.options[0].text = officeId ? 'Select accountable employee' : 'Select office first';
        Array.from(employee.options).slice(1).forEach(function (option) {
            const allowed = option.dataset.officeId === officeId;
            option.hidden = !allowed;
            option.disabled = !allowed;
        });
    });
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
