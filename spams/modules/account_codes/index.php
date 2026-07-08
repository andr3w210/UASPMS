<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function account_codes_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'account_codes', $recordId, [
        "SELECT 1 FROM purchase_order_items WHERE account_code_id = ? LIMIT 1",
        "SELECT 1 FROM stock_catalog WHERE account_code_id = ? LIMIT 1",
    ]);
}

$db = db();
$page_title = 'Account Codes';
$flash = get_flash();
$errors = [];
$accountCodes = [];
$form = ['id'=>0,'account_code'=>'','account_name'=>'','account_group'=>'asset','default_useful_life_years'=>'','description'=>'','is_active'=>'1'];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if (schema_has_column($db, 'account_codes', 'account_group') && !schema_has_column($db, 'account_codes', 'default_useful_life_years')) {
        $errors[] = 'Database schema is outdated: account_codes.default_useful_life_years is missing. Apply latest migrations before continuing.';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['account_code'] = old($_POST, 'account_code');
            $form['account_name'] = old($_POST, 'account_name');
            $form['account_group'] = old($_POST, 'account_group', 'asset');
            $form['default_useful_life_years'] = old($_POST, 'default_useful_life_years');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            if ($form['account_code'] === '') $errors[] = 'Account code is required.';
            if ($form['account_name'] === '') $errors[] = 'Account name is required.';
            if (!in_array($form['account_group'], ['supply', 'asset', 'fixed_asset', 'semi_expendable'], true)) $errors[] = 'Invalid account group.';
            if ($form['default_useful_life_years'] !== '' && (!ctype_digit($form['default_useful_life_years']) || (int) $form['default_useful_life_years'] <= 0)) $errors[] = 'Default useful life must be a whole number greater than zero, or left blank.';
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
                $defaultUsefulLifeYears = $form['default_useful_life_years'] !== '' ? (int) $form['default_useful_life_years'] : null;
                if ($form['account_group'] === 'supply' || $form['account_code'] === '1.06.01.010.00') {
                    $defaultUsefulLifeYears = null;
                } elseif ($defaultUsefulLifeYears === null) {
                    $defaultUsefulLifeYears = account_code_default_useful_life_years($form['account_code'], $form['account_group']);
                }
                $userId = current_user_id();
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE account_codes SET account_code = ?, account_name = ?, account_group = ?, default_useful_life_years = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssisiii', $form['account_code'], $form['account_name'], $form['account_group'], $defaultUsefulLifeYears, $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'account_codes',
                                'record_id' => $recordId,
                                'module_name' => 'account_codes',
                                'record_type' => 'account_code',
                                'action_name' => 'update_account_code',
                                'description' => 'Updated account code record.',
                                'new_values' => [
                                    'account_code' => $form['account_code'],
                                    'account_name' => $form['account_name'],
                                    'account_group' => $form['account_group'],
                                    'default_useful_life_years' => $defaultUsefulLifeYears,
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Account code updated successfully.');
                            redirect('modules/account_codes/index.php');
                        }
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO account_codes (account_code, account_name, account_group, default_useful_life_years, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssisii', $form['account_code'], $form['account_name'], $form['account_group'], $defaultUsefulLifeYears, $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newAccountCodeId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'account_codes',
                                'record_id' => $newAccountCodeId,
                                'module_name' => 'account_codes',
                                'record_type' => 'account_code',
                                'action_name' => 'create_account_code',
                                'description' => 'Created account code record.',
                                'new_values' => [
                                    'account_code' => $form['account_code'],
                                    'account_name' => $form['account_name'],
                                    'account_group' => $form['account_group'],
                                    'default_useful_life_years' => $defaultUsefulLifeYears,
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Account code created successfully.');
                            redirect('modules/account_codes/index.php');
                        }
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
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'account_codes',
                        'record_id' => $recordId,
                        'module_name' => 'account_codes',
                        'record_type' => 'account_code',
                        'action_name' => 'deactivate_account_code',
                        'description' => 'Deactivated account code record.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Account code deactivated successfully.');
                    redirect('modules/account_codes/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the account code.';
        } elseif ($action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE account_codes SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'account_codes',
                        'record_id' => $recordId,
                        'module_name' => 'account_codes',
                        'record_type' => 'account_code',
                        'action_name' => 'reactivate_account_code',
                        'description' => 'Reactivated account code record.',
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success', 'Account code reactivated successfully.');
                    redirect('modules/account_codes/index.php');
                }
            }
            $errors[] = 'Unable to reactivate the account code.';
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
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT account_code, account_name, account_group FROM account_codes WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt = $db->prepare("DELETE FROM account_codes WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'account_codes',
                        'record_id' => $recordId,
                        'module_name' => 'account_codes',
                        'record_type' => 'account_code',
                        'action_name' => 'hard_delete_account_code',
                        'description' => 'Permanently deleted account code record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/account_codes/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the account code.';
        }
    }
    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, account_code, account_name, account_group, default_useful_life_years, description, is_active FROM account_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = ['id'=>(int)$record['id'],'account_code'=>$record['account_code'],'account_name'=>$record['account_name'],'account_group'=>$record['account_group'],'default_useful_life_years'=>(string)($record['default_useful_life_years'] ?? ''),'description'=>$record['description'] ?? '','is_active'=>(string)(int)$record['is_active']];
            }
        }
    }
    $result = $db->query("SELECT ac.id, ac.account_code, ac.account_name, ac.account_group, ac.default_useful_life_years, ac.description, ac.is_active, ac.created_at, creator.full_name AS creator_name FROM account_codes ac LEFT JOIN users creator ON creator.id = ac.created_by ORDER BY ac.account_code ASC");
    if ($result) $accountCodes = $result->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Account Codes</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($accountCodes); ?> of <?php echo count($accountCodes); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/account_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Account Code'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Account Code' : 'New Account Code'; ?></h5><div class="text-muted small">Maintain COA mappings and item grouping used across the system.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Account Code</label><input type="text" class="form-control" name="account_code" value="<?php echo h($form['account_code']); ?>" required></div><div class="col-md-5"><label class="form-label">Account Name</label><input type="text" class="form-control" name="account_name" value="<?php echo h($form['account_name']); ?>" required></div><div class="col-md-3"><label class="form-label">Group</label><select class="form-select" name="account_group"><option value="asset" <?php echo $form['account_group'] === 'asset' ? 'selected' : ''; ?>>Asset</option><option value="fixed_asset" <?php echo $form['account_group'] === 'fixed_asset' ? 'selected' : ''; ?>>Fixed Asset</option><option value="semi_expendable" <?php echo $form['account_group'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option><option value="supply" <?php echo $form['account_group'] === 'supply' ? 'selected' : ''; ?>>Supply</option></select></div><div class="col-md-3"><label class="form-label">Default Useful Life</label><input type="number" class="form-control" name="default_useful_life_years" value="<?php echo h($form['default_useful_life_years']); ?>" min="1" step="1"><div class="form-text">Leave blank for land and supplies.</div></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active account code</label></div></div><div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/account_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Account Code' : 'Save Account Code'; ?></button></div></div></form></div></div>
<div class="master-data-toolbar mb-3"><div class="row g-3 align-items-end"><div class="col-lg-6"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search account code, title, description, or group"></div><div class="col-sm-6 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label">Rows Per Page</label><select id="perPageSelect" class="form-select"><option value="25" selected>25 rows</option><option value="50">50 rows</option><option value="100">100 rows</option><option value="250">250 rows</option></select></div></div></div>
<div class="master-data-table-shell"><div class="table-responsive mobile-table-frame master-data-table-scroll"><table class="table align-middle" id="dataTable"><thead><tr><th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="title">Account Title <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="group">Group <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="life">Useful Life <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($accountCodes): foreach ($accountCodes as $accountCode): ?><tr data-status="<?php echo (int) $accountCode['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($accountCode['account_code']); ?></td><td><div class="fw-semibold"><?php echo h($accountCode['account_name']); ?></div><small class="text-muted"><?php echo h($accountCode['description'] ?? ''); ?></small></td><td><span class="badge text-bg-light text-uppercase"><?php echo h(str_replace('_', ' ', $accountCode['account_group'])); ?></span></td><td class="text-nowrap"><?php echo !empty($accountCode['default_useful_life_years']) ? h((string) $accountCode['default_useful_life_years']) . ' yr(s)' : '-'; ?></td><td><span class="badge <?php echo (int) $accountCode['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $accountCode['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($accountCode['created_at']))); ?></div><small class="text-muted"><?php echo h($accountCode['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/account_codes/index.php?edit=' . (int) $accountCode['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $accountCode['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this account code?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php else: ?><form method="post" onsubmit="return confirm('Reactivate this account code?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="7" class="text-center text-muted py-4">No account codes found yet.</td></tr><?php endif; ?></tbody></table></div>
<div class="master-data-pagination"><div id="recordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div><div class="master-data-pagination-controls"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button></div></div></div>
</div></div></section><script>
document.addEventListener('DOMContentLoaded', function () {
    var recordCountMobile = document.getElementById('recordCountMobile');
    var options = {
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
        emptyMessage: 'No account codes matched your search or status filter.'
    };
    if (typeof window.initMasterDataList === 'function') {
        window.initMasterDataList('dataTable', options);
        return;
    }
    window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];
    window.__spamsPendingMasterDataLists.push(['dataTable', options]);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
