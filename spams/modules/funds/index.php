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
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if (!empty($errors)): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
            <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Master Data</div>
                    <h4 class="mb-1">Fund Directory</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($funds); ?> of <?php echo count($funds); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/funds/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Fund'; ?></button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Fund' : 'New Fund'; ?></h5><div class="text-muted small">Maintain funding sources used in procurement and reporting.</div></div></div>
                    <form method="post" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="master-data-form-layout">
                            <div class="master-data-form-main">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Identity</div><h6 class="mb-1">Fund Details</h6><div class="text-muted small">Keep the fund code stable and the name readable across procurement and reporting views.</div></div></div>
                                    <div class="master-data-panel-body">
                                        <div class="row g-3">
                                            <div class="col-md-4"><label class="form-label">Fund Code</label><input type="text" class="form-control" name="fund_code" value="<?php echo h($form['fund_code']); ?>" required></div>
                                            <div class="col-md-4"><label class="form-label">Fund Name</label><input type="text" class="form-control" name="fund_name" value="<?php echo h($form['fund_name']); ?>" required></div>
                                            <div class="col-md-4"><label class="form-label">Fund Source</label><input type="text" class="form-control" name="fund_source" value="<?php echo h($form['fund_source']); ?>" placeholder="General Fund, Trust Fund"></div>
                                            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"><?php echo h($form['description']); ?></textarea></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="master-data-form-actions"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/funds/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Fund' : 'Save Fund'; ?></button></div>
                            </div>
                            <div class="master-data-form-side">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Status</div><h6 class="mb-1">Fund Controls</h6></div></div>
                                    <div class="master-data-panel-body">
                                        <div class="master-data-helper mb-3">Recommendation: use one source label consistently so fund summaries and printed reports stay uniform.</div>
                                        <div class="master-data-side-list"><div class="master-data-side-item"><span>Directory status</span><span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span></div></div>
                                        <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active fund</label></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3"><div class="row g-3 align-items-end"><div class="col-lg-6"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search fund code, name, source, or description"></div><div class="col-sm-6 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label">Rows Per Page</label><select id="perPageSelect" class="form-select"><option value="25" selected>25 rows</option><option value="50">50 rows</option><option value="100">100 rows</option><option value="250">250 rows</option></select></div></div></div>

            <div class="master-data-table-shell"><div class="table-responsive mobile-table-frame master-data-table-scroll"><table class="table align-middle" id="dataTable"><thead><tr><th>Code</th><th>Fund</th><th>Source</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($funds): foreach ($funds as $fund): ?><tr data-status="<?php echo (int) $fund['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($fund['fund_code']); ?></td><td><div class="fw-semibold"><?php echo h($fund['fund_name']); ?></div><small class="text-muted"><?php echo h($fund['description'] ?? ''); ?></small></td><td><?php echo h($fund['fund_source'] ?? ''); ?></td><td><span class="badge <?php echo (int) $fund['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $fund['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($fund['created_at']))); ?></div><small class="text-muted"><?php echo h($fund['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/funds/index.php?edit=' . (int) $fund['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $fund['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this fund?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $fund['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $fund['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="6" class="text-center text-muted py-4">No funds found yet.</td></tr><?php endif; ?></tbody></table></div>
            <div class="master-data-pagination"><div id="recordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div><div class="master-data-pagination-controls"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button></div></div></div>
        </div>
    </div>
</section><script>
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
        emptyMessage: 'No funds matched your search or status filter.'
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



