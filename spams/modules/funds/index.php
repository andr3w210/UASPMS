<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

function funds_has_reference(mysqli $db, int $recordId): bool
{
    $stmt = $db->prepare("SELECT 1 FROM purchase_orders WHERE fund_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $recordId);
    $stmt->execute();
    $hasRow = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $hasRow;
}

$db = db();
$page_title = 'Funds';
$flash = get_flash();
$errors = [];
$funds = [];
$form = [
    'id' => 0,
    'fund_code' => '',
    'fund_name' => '',
    'fund_source' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['fund_code'] = strtoupper(old($_POST, 'fund_code'));
            $form['fund_name'] = old($_POST, 'fund_name');
            $form['fund_source'] = old($_POST, 'fund_source');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['fund_code'] === '') $errors[] = 'Fund code is required.';
            if ($form['fund_name'] === '') $errors[] = 'Fund name is required.';

            $duplicateStmt = $db->prepare("SELECT id FROM funds WHERE (fund_code = ? OR fund_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['fund_code'], $form['fund_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) $errors[] = 'Fund code or fund name already exists.';
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE funds SET fund_code = ?, fund_name = ?, fund_source = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['fund_code'], $form['fund_name'], $form['fund_source'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'funds',
                                'record_id' => $recordId,
                                'module_name' => 'funds',
                                'record_type' => 'fund',
                                'action_name' => 'update_fund',
                                'description' => 'Updated fund record.',
                                'new_values' => [
                                    'fund_code' => $form['fund_code'],
                                    'fund_name' => $form['fund_name'],
                                    'fund_source' => $form['fund_source'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Fund updated successfully.');
                            redirect('modules/funds/index.php');
                        }
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO funds (fund_code, fund_name, fund_source, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['fund_code'], $form['fund_name'], $form['fund_source'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newFundId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'funds',
                                'record_id' => $newFundId,
                                'module_name' => 'funds',
                                'record_type' => 'fund',
                                'action_name' => 'create_fund',
                                'description' => 'Created fund record.',
                                'new_values' => [
                                    'fund_code' => $form['fund_code'],
                                    'fund_name' => $form['fund_name'],
                                    'fund_source' => $form['fund_source'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Fund created successfully.');
                            redirect('modules/funds/index.php');
                        }
                    }
                }
                $errors[] = 'Unable to save the fund.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE funds SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'funds',
                        'record_id' => $recordId,
                        'module_name' => 'funds',
                        'record_type' => 'fund',
                        'action_name' => 'deactivate_fund',
                        'description' => 'Deactivated fund record.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Fund deactivated successfully.');
                    redirect('modules/funds/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the fund.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/funds/index.php');
            }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (funds_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/funds/index.php');
            }
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT fund_code, fund_name, fund_source FROM funds WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt = $db->prepare("DELETE FROM funds WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'funds',
                        'record_id' => $recordId,
                        'module_name' => 'funds',
                        'record_type' => 'fund',
                        'action_name' => 'hard_delete_fund',
                        'description' => 'Permanently deleted fund record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/funds/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the fund.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, fund_code, fund_name, fund_source, description, is_active FROM funds WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'fund_code' => $record['fund_code'],
                    'fund_name' => $record['fund_name'],
                    'fund_source' => $record['fund_source'] ?? '',
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT f.id, f.fund_code, f.fund_name, f.fund_source, f.description, f.is_active, f.created_at,
               creator.full_name AS creator_name
        FROM funds f
        LEFT JOIN users creator ON creator.id = f.created_by
        ORDER BY f.fund_name ASC
    ");
    if ($result) $funds = $result->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12"><div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h5 class="card-title mb-0"><?php echo $form['id'] > 0 ? 'Edit Fund' : 'Add New Fund'; ?></h5><div class="text-muted small">Maintain the funding sources available for purchase orders.</div></div><div class="d-flex gap-2"><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Edit Fund' : 'Add New'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/funds/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div><div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?>" id="formCollapse"><div class="card-body p-4"><?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?><?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Fund Code</label><input type="text" class="form-control" name="fund_code" value="<?php echo h($form['fund_code']); ?>" required></div><div class="col-md-4"><label class="form-label">Fund Name</label><input type="text" class="form-control" name="fund_name" value="<?php echo h($form['fund_name']); ?>" required></div><div class="col-md-4"><label class="form-label">Fund Source</label><input type="text" class="form-control" name="fund_source" value="<?php echo h($form['fund_source']); ?>"></div><div class="col-md-8"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active fund</label></div></div><div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/funds/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div></div></form></div></div></div></div>
    <div class="col-12"><div class="card"><div class="card-body p-4"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h5 class="card-title mb-0">Fund List</h5><span id="recordCount" class="text-muted small">Showing <?php echo count($funds); ?> of <?php echo count($funds); ?> records</span></div><button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i>Add New</button></div><div class="d-flex flex-wrap gap-2 align-items-center mb-3"><input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search funds..." style="max-width:300px;"><select id="statusFilter" class="form-select form-select-sm" style="max-width:140px;"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="table-responsive"><table class="table align-middle" id="dataTable"><thead><tr><th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="fund">Fund <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="source">Source <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($funds): foreach ($funds as $fund): ?><tr data-status="<?php echo (int) $fund['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($fund['fund_code']); ?></td><td><div><?php echo h($fund['fund_name']); ?></div><small class="text-muted"><?php echo h($fund['description'] ?? ''); ?></small></td><td><?php echo h($fund['fund_source'] ?? ''); ?></td><td><span class="badge <?php echo (int) $fund['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $fund['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($fund['created_at']))); ?></div><small class="text-muted"><?php echo h($fund['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/funds/index.php?edit=' . (int) $fund['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $fund['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this fund?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $fund['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $fund['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="6" class="text-center text-muted py-4">No funds found yet.</td></tr><?php endif; ?></tbody></table></div><div class="d-flex align-items-center gap-3 mt-2 flex-wrap"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button><select id="perPageSelect" class="form-select form-select-sm" style="width:auto;"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div></div></div></div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    initDataTable('dataTable');
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


