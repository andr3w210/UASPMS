<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function account_codes_has_reference(mysqli $db, int $recordId): bool
{
    $checks = [
        "SELECT 1 FROM purchase_order_items WHERE account_code_id = ? LIMIT 1",
        "SELECT 1 FROM stock_catalog WHERE account_code_id = ? LIMIT 1",
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

$db = db_connect();
$page_title = 'Account Codes';
$flash = get_flash();
$errors = [];
$accountCodes = [];
$form = ['id'=>0,'account_code'=>'','account_name'=>'','account_group'=>'asset','description'=>'','is_active'=>'1'];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['account_code'] = old($_POST, 'account_code');
            $form['account_name'] = old($_POST, 'account_name');
            $form['account_group'] = old($_POST, 'account_group', 'asset');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            if ($form['account_code'] === '') $errors[] = 'Account code is required.';
            if ($form['account_name'] === '') $errors[] = 'Account name is required.';
            if (!in_array($form['account_group'], ['supply', 'asset', 'semi_expendable'], true)) $errors[] = 'Invalid account group.';
            $duplicateStmt = $db->prepare("SELECT id FROM account_codes WHERE (account_code = ? OR account_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['account_code'], $form['account_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) $errors[] = 'Account code or account name already exists.';
                $duplicateStmt->close();
            }
            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE account_codes SET account_code = ?, account_name = ?, account_group = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['account_code'], $form['account_name'], $form['account_group'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Account code updated successfully.');
                        redirect('modules/account_codes/index.php');
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO account_codes (account_code, account_name, account_group, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['account_code'], $form['account_name'], $form['account_group'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Account code created successfully.');
                        redirect('modules/account_codes/index.php');
                    }
                }
                $errors[] = 'Unable to save the account code.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE account_codes SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Account code deactivated successfully.');
                redirect('modules/account_codes/index.php');
            }
            $errors[] = 'Unable to deactivate the account code.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/account_codes/index.php');
            }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (account_codes_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/account_codes/index.php');
            }
            $stmt = $db->prepare("DELETE FROM account_codes WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Record permanently deleted.');
                redirect('modules/account_codes/index.php');
            }
            $errors[] = 'Unable to permanently delete the account code.';
        }
    }
    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, account_code, account_name, account_group, description, is_active FROM account_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = ['id'=>(int)$record['id'],'account_code'=>$record['account_code'],'account_name'=>$record['account_name'],'account_group'=>$record['account_group'],'description'=>$record['description'] ?? '','is_active'=>(string)(int)$record['is_active']];
            }
        }
    }
    $result = $db->query("SELECT ac.id, ac.account_code, ac.account_name, ac.account_group, ac.description, ac.is_active, ac.created_at, creator.full_name AS creator_name FROM account_codes ac LEFT JOIN users creator ON creator.id = ac.created_by ORDER BY ac.account_code ASC");
    if ($result) $accountCodes = $result->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
<div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h5 class="card-title mb-0"><?php echo $form['id'] > 0 ? 'Edit Account Code' : 'Add New Account Code'; ?></h5><div class="text-muted small">Maintain COA mappings and safely remove unused entries.</div></div><div class="d-flex gap-2"><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Edit Account Code' : 'Add New'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/account_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div><div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?>" id="formCollapse"><div class="card-body p-4"><?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?><?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Account Code</label><input type="text" class="form-control" name="account_code" value="<?php echo h($form['account_code']); ?>" required></div><div class="col-md-5"><label class="form-label">Account Name</label><input type="text" class="form-control" name="account_name" value="<?php echo h($form['account_name']); ?>" required></div><div class="col-md-3"><label class="form-label">Group</label><select class="form-select" name="account_group"><option value="asset" <?php echo $form['account_group'] === 'asset' ? 'selected' : ''; ?>>Asset</option><option value="semi_expendable" <?php echo $form['account_group'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option><option value="supply" <?php echo $form['account_group'] === 'supply' ? 'selected' : ''; ?>>Supply</option></select></div><div class="col-md-9"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active account code</label></div></div><div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/account_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div></form></div></div></div></div>
<div class="col-12"><div class="card"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="card-title mb-0">Account Code List</h5><span id="recordCount" class="text-muted small">Showing <?php echo count($accountCodes); ?> of <?php echo count($accountCodes); ?> records</span></div><button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i>Add New</button></div><div class="d-flex flex-wrap gap-2 align-items-center mb-3"><input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search account codes..." style="max-width:300px;"><select id="statusFilter" class="form-select form-select-sm" style="max-width:140px;"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="table-responsive"><table class="table align-middle" id="dataTable"><thead><tr><th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="name">Account Title <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="group">Group <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($accountCodes): foreach ($accountCodes as $accountCode): ?><tr data-status="<?php echo (int) $accountCode['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($accountCode['account_code']); ?></td><td><div><?php echo h($accountCode['account_name']); ?></div><small class="text-muted"><?php echo h($accountCode['description'] ?? ''); ?></small></td><td><span class="badge text-bg-light text-uppercase"><?php echo h(str_replace('_', ' ', $accountCode['account_group'])); ?></span></td><td><span class="badge <?php echo (int) $accountCode['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $accountCode['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($accountCode['created_at']))); ?></div><small class="text-muted"><?php echo h($accountCode['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/account_codes/index.php?edit=' . (int) $accountCode['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $accountCode['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this account code?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="6" class="text-center text-muted py-4">No account codes found yet.</td></tr><?php endif; ?></tbody></table></div><div class="d-flex align-items-center gap-3 mt-2 flex-wrap"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button><select id="perPageSelect" class="form-select form-select-sm" style="width:auto;"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div></div></div></div>
</section>
<script>
(function() {
    var perPage = 25, currentPage = 1, sortCol = -1, sortDir = 'asc';
    function getRows() { return Array.from(document.querySelectorAll('#dataTable tbody tr')); }
    function updateRecordCount(total, overall) { var node = document.getElementById('recordCount'); if (node) node.textContent = 'Showing ' + total + ' of ' + overall + ' records'; }
    function renderPage() { var allRows = getRows(), rows = allRows.filter(function(row) { return row.dataset.visible !== '0'; }); var total = rows.length, pages = Math.max(1, Math.ceil(total / perPage)); currentPage = Math.min(currentPage, pages); var start = (currentPage - 1) * perPage, end = start + perPage; allRows.forEach(function(row) { row.style.display = 'none'; }); rows.slice(start, end).forEach(function(row) { row.style.display = ''; }); updateRecordCount(total, allRows.length); document.getElementById('pageInfo').textContent = 'Page ' + currentPage + ' of ' + pages + ' (' + total + ' records)'; document.getElementById('prevPage').disabled = currentPage <= 1; document.getElementById('nextPage').disabled = currentPage >= pages; }
    function applyFilters() { var term = ((document.getElementById('tableSearch') || {}).value || '').toLowerCase(); var status = ((document.getElementById('statusFilter') || {}).value || ''); getRows().forEach(function(row) { row.dataset.visible = ((!term || row.textContent.toLowerCase().includes(term)) && (!status || row.dataset.status === status)) ? '1' : '0'; }); currentPage = 1; renderPage(); }
    document.getElementById('tableSearch')?.addEventListener('input', applyFilters); document.getElementById('statusFilter')?.addEventListener('change', applyFilters); document.getElementById('prevPage')?.addEventListener('click', function() { currentPage--; renderPage(); }); document.getElementById('nextPage')?.addEventListener('click', function() { currentPage++; renderPage(); }); document.getElementById('perPageSelect')?.addEventListener('change', function() { perPage = parseInt(this.value, 10) || 25; currentPage = 1; renderPage(); }); document.querySelectorAll('#dataTable th[data-sort]').forEach(function(th, idx) { th.style.cursor = 'pointer'; th.addEventListener('click', function() { var tbody = document.querySelector('#dataTable tbody'); var rows = Array.from(tbody.querySelectorAll('tr')); var dir = (sortCol === idx && sortDir === 'asc') ? 'desc' : 'asc'; sortCol = idx; sortDir = dir; rows.sort(function(a, b) { var at = a.cells[idx] ? a.cells[idx].textContent.trim().toLowerCase() : ''; var bt = b.cells[idx] ? b.cells[idx].textContent.trim().toLowerCase() : ''; return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at); }); rows.forEach(function(row) { tbody.appendChild(row); }); document.querySelectorAll('#dataTable th[data-sort] i').forEach(function(icon) { icon.className = 'bi bi-arrow-down-up text-muted small'; }); var icon = th.querySelector('i'); if (icon) icon.className = 'bi bi-arrow-' + (dir === 'asc' ? 'up' : 'down') + ' text-primary small'; renderPage(); }); }); applyFilters();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
