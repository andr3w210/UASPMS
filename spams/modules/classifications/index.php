<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

function classifications_has_reference(mysqli $db, int $recordId): bool
{
    $checks = [
        "SELECT 1 FROM purchase_order_items WHERE classification_id = ? LIMIT 1",
        "SELECT 1 FROM stock_catalog WHERE classification_id = ? LIMIT 1",
    ];
    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql);
        if (!$stmt) continue;
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) return true;
    }
    return false;
}

$db = db();
$page_title = 'Item Classifications';
$flash = get_flash();
$errors = [];
$classifications = [];
$accountCodes = [];
$form = ['id'=>0,'classification_code'=>'','classification_name'=>'','classification_family'=>'','useful_life_years'=>'','classification_group'=>'asset','account_code_id'=>'','description'=>'','is_active'=>'1'];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'classifications');
    $accountCodeResult = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($accountCodeResult) $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['classification_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'classification_code')) : $generatedCode;
            $form['classification_name'] = old($_POST, 'classification_name');
            $form['classification_family'] = old($_POST, 'classification_family');
            $form['useful_life_years'] = old($_POST, 'useful_life_years');
            $form['classification_group'] = old($_POST, 'classification_group', 'asset');
            $form['account_code_id'] = old($_POST, 'account_code_id');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            if ($form['classification_name'] === '') $errors[] = 'Classification name is required.';
            if (!in_array($form['classification_group'], ['supply', 'asset', 'semi_expendable'], true)) $errors[] = 'Invalid classification group.';
            $selectedAccountCode = null; foreach ($accountCodes as $accountCode) { if ((string) $accountCode['id'] === $form['account_code_id']) { $selectedAccountCode = $accountCode; break; } }
            if ($form['account_code_id'] !== '' && !$selectedAccountCode) $errors[] = 'Selected account code is invalid.';
            $duplicateStmt = $db->prepare("SELECT id FROM classifications WHERE (classification_code = ? OR classification_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) { $recordId = (int) $form['id']; $duplicateStmt->bind_param('ssi', $form['classification_code'], $form['classification_name'], $recordId); $duplicateStmt->execute(); if ($duplicateStmt->get_result()->fetch_assoc()) $errors[] = 'Classification code or name already exists.'; $duplicateStmt->close(); }
            if (!$errors) {
                $isActive = (int) $form['is_active']; $userId = current_user_id(); $accountCodeId = $form['account_code_id'] !== '' ? (int) $form['account_code_id'] : null;
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE classifications SET classification_code = ?, classification_name = ?, classification_family = NULLIF(?, ''), useful_life_years = NULLIF(?,0), classification_group = ?, account_code_id = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) { $recordId = (int) $form['id']; $stmt->bind_param('sssisisiii', $form['classification_code'], $form['classification_name'], $form['classification_family'], $form['useful_life_years'], $form['classification_group'], $accountCodeId, $form['description'], $isActive, $userId, $recordId); $saved = $stmt->execute(); $stmt->close(); if ($saved) { write_audit_log($db, ['action' => 'update', 'table_name' => 'classifications', 'record_id' => $recordId, 'module_name' => 'classifications', 'record_type' => 'classification', 'action_name' => 'update_classification', 'description' => 'Updated item classification.', 'new_values' => ['classification_code' => $form['classification_code'], 'classification_name' => $form['classification_name'], 'classification_family' => $form['classification_family'], 'useful_life_years' => $form['useful_life_years'], 'classification_group' => $form['classification_group'], 'account_code_id' => $accountCodeId, 'is_active' => $isActive]]); set_flash('success', 'Classification updated successfully.'); redirect('modules/classifications/index.php'); } }
                } else {
                    $form['classification_code'] = next_module_code($db, 'classifications');
                    $stmt = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_family, useful_life_years, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, NULLIF(?, ''), NULLIF(?,0), ?, ?, ?, ?, ?)");
                    if ($stmt) { $stmt->bind_param('sssisisii', $form['classification_code'], $form['classification_name'], $form['classification_family'], $form['useful_life_years'], $form['classification_group'], $accountCodeId, $form['description'], $isActive, $userId); $saved = $stmt->execute(); $newId = (int) $stmt->insert_id; $stmt->close(); if ($saved) { write_audit_log($db, ['action' => 'insert', 'table_name' => 'classifications', 'record_id' => $newId, 'module_name' => 'classifications', 'record_type' => 'classification', 'action_name' => 'create_classification', 'description' => 'Created item classification.', 'new_values' => ['classification_code' => $form['classification_code'], 'classification_name' => $form['classification_name'], 'classification_family' => $form['classification_family'], 'useful_life_years' => $form['useful_life_years'], 'classification_group' => $form['classification_group'], 'account_code_id' => $accountCodeId, 'is_active' => $isActive]]); set_flash('success', 'Classification created successfully.'); redirect('modules/classifications/index.php'); } }
                }
                $errors[] = 'Unable to save the classification.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0); $userId = current_user_id();
            $stmt = $db->prepare("UPDATE classifications SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) { $stmt->bind_param('ii', $userId, $recordId); $saved = $stmt->execute(); $stmt->close(); if ($saved) { write_audit_log($db, ['action' => 'update', 'table_name' => 'classifications', 'record_id' => $recordId, 'module_name' => 'classifications', 'record_type' => 'classification', 'action_name' => 'deactivate_classification', 'description' => 'Deactivated item classification.', 'new_values' => ['is_active' => 0]]); set_flash('success', 'Classification deactivated successfully.'); redirect('modules/classifications/index.php'); } }
            $errors[] = 'Unable to deactivate the classification.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') { set_flash('error', 'Only administrators can permanently delete records.'); redirect('modules/classifications/index.php'); }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (classifications_has_reference($db, $recordId)) { set_flash('error', 'Cannot delete: record is used in existing transactions.'); redirect('modules/classifications/index.php'); }
            $auditSnapshot = ['id' => $recordId]; $auditStmt = $db->prepare("SELECT classification_code, classification_name, classification_group FROM classifications WHERE id = ? LIMIT 1"); if ($auditStmt) { $auditStmt->bind_param('i', $recordId); $auditStmt->execute(); $auditRow = $auditStmt->get_result()->fetch_assoc(); $auditStmt->close(); if ($auditRow) $auditSnapshot = $auditRow; }
            $stmt = $db->prepare("DELETE FROM classifications WHERE id = ? LIMIT 1");
            if ($stmt) { $stmt->bind_param('i', $recordId); $saved = $stmt->execute(); $stmt->close(); if ($saved) { write_audit_log($db, ['action' => 'delete', 'table_name' => 'classifications', 'record_id' => $recordId, 'module_name' => 'classifications', 'record_type' => 'classification', 'action_name' => 'hard_delete_classification', 'description' => 'Permanently deleted item classification.', 'old_values' => $auditSnapshot]); set_flash('success', 'Record permanently deleted.'); redirect('modules/classifications/index.php'); } }
            $errors[] = 'Unable to permanently delete the classification.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, classification_code, classification_name, classification_family, useful_life_years, classification_group, account_code_id, description, is_active FROM classifications WHERE id = ? LIMIT 1");
        if ($stmt) { $stmt->bind_param('i', $recordId); $stmt->execute(); $record = $stmt->get_result()->fetch_assoc(); $stmt->close(); if ($record) $form = ['id'=>(int)$record['id'],'classification_code'=>$record['classification_code'],'classification_name'=>$record['classification_name'],'classification_family'=>$record['classification_family'] ?? '','useful_life_years'=>(string)($record['useful_life_years'] ?? ''),'classification_group'=>$record['classification_group'],'account_code_id'=>(string)($record['account_code_id'] ?? ''),'description'=>$record['description'] ?? '','is_active'=>(string)(int)$record['is_active']]; }
    }

    $result = $db->query("SELECT c.id, c.classification_code, c.classification_name, c.classification_family, c.useful_life_years, c.classification_group, c.account_code_id, c.description, c.is_active, c.created_at, ac.account_code, ac.account_name, creator.full_name AS creator_name FROM classifications c LEFT JOIN account_codes ac ON ac.id = c.account_code_id LEFT JOIN users creator ON creator.id = c.created_by ORDER BY COALESCE(c.classification_family, ''), c.classification_name ASC");
    if ($result) $classifications = $result->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Item Classifications</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($classifications); ?> of <?php echo count($classifications); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/classifications/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Classification'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Item Classification' : 'New Item Classification'; ?></h5><div class="text-muted small">Maintain item families and their default account-code mappings.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-3"><label class="form-label">Classification Code</label><input type="text" class="form-control" name="classification_code" value="<?php echo h($form['id'] > 0 ? $form['classification_code'] : $generatedCode); ?>" readonly></div><div class="col-md-5"><label class="form-label">Item Classification Name</label><input type="text" class="form-control" name="classification_name" value="<?php echo h($form['classification_name']); ?>" required></div><div class="col-md-4"><label class="form-label">Classification Family</label><input type="text" class="form-control" name="classification_family" value="<?php echo h($form['classification_family']); ?>" placeholder="e.g. IT Supplies"></div><div class="col-md-3"><label class="form-label">Type Bucket</label><select class="form-select" id="classification_group" name="classification_group"><option value="asset" <?php echo $form['classification_group'] === 'asset' ? 'selected' : ''; ?>>Asset</option><option value="semi_expendable" <?php echo $form['classification_group'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option><option value="supply" <?php echo $form['classification_group'] === 'supply' ? 'selected' : ''; ?>>Supply</option></select></div><div class="col-md-3"><label class="form-label">Useful Life</label><input type="number" class="form-control" name="useful_life_years" value="<?php echo h($form['useful_life_years']); ?>" min="0"></div><div class="col-md-6"><label class="form-label">Default Account Code</label><select class="form-select" id="account_code_id" name="account_code_id"><option value="">No default account code</option><?php foreach ($accountCodes as $accountCode): ?><option value="<?php echo (int) $accountCode['id']; ?>" data-account-group="<?php echo h($accountCode['account_group']); ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?>><?php echo h($accountCode['account_code'] . ' - ' . $accountCode['account_name']); ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active item classification</label></div></div><div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/classifications/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Classification' : 'Save Classification'; ?></button></div></div></form></div></div>
<div class="master-data-toolbar mb-3"><div class="row g-3 align-items-end"><div class="col-md-8 col-lg-9"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search classification, family, account code, or description"></div><div class="col-md-4 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div>
<div class="table-responsive mobile-table-frame"><table class="table align-middle" id="dataTable"><thead><tr><th>Code</th><th>Item Classification</th><th>Family</th><th>Useful Life</th><th>Account Code</th><th>Type Bucket</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($classifications): foreach ($classifications as $classification): ?><tr data-status="<?php echo (int) $classification['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($classification['classification_code']); ?></td><td><div class="fw-semibold"><?php echo h($classification['classification_name']); ?></div><small class="text-muted"><?php echo h($classification['description'] ?? ''); ?></small></td><td><?php echo h($classification['classification_family'] ?: '-'); ?></td><td><?php echo $classification['useful_life_years'] ? h($classification['useful_life_years']) . ' yr(s)' : '-'; ?></td><td><div><?php echo h($classification['account_code'] ?? ''); ?></div><small class="text-muted"><?php echo h($classification['account_name'] ?? ''); ?></small></td><td><span class="badge text-bg-light text-uppercase"><?php echo h(str_replace('_', ' ', $classification['classification_group'])); ?></span></td><td><span class="badge <?php echo (int) $classification['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $classification['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($classification['created_at']))); ?></div><small class="text-muted"><?php echo h($classification['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/classifications/index.php?edit=' . (int) $classification['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $classification['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this classification?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $classification['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $classification['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="9" class="text-center text-muted py-4">No item classifications found yet.</td></tr><?php endif; ?></tbody></table></div>
<div class="d-flex align-items-center gap-3 mt-3 flex-wrap"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button><select id="perPageSelect" class="form-select form-select-sm" style="width:auto;"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div>
</div></div></section><script>
document.addEventListener('DOMContentLoaded', function () {
    window.initMasterDataList('dataTable');
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>




