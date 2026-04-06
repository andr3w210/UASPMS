<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';

require_login();

function mode_of_procurements_has_reference(mysqli $db, int $recordId): bool
{
    $stmt = $db->prepare('SELECT 1 FROM purchase_orders WHERE mode_of_procurement_id = ? LIMIT 1');
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
$page_title = 'Mode of Procurement';
$flash = get_flash();
$errors = [];
$procurementModes = [];
$form = [
    'id' => 0,
    'mode_code' => '',
    'mode_name' => '',
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
            $form['mode_code'] = strtoupper(old($_POST, 'mode_code'));
            $form['mode_name'] = old($_POST, 'mode_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['mode_code'] === '') {
                $errors[] = 'Mode code is required.';
            }
            if ($form['mode_name'] === '') {
                $errors[] = 'Mode of procurement name is required.';
            }

            $duplicateStmt = $db->prepare('SELECT id FROM mode_of_procurements WHERE (mode_code = ? OR mode_name = ?) AND id != ? LIMIT 1');
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['mode_code'], $form['mode_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Code or mode name already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare('UPDATE mode_of_procurements SET mode_code = ?, mode_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssiii', $form['mode_code'], $form['mode_name'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();

                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'mode_of_procurements',
                                'record_id' => $recordId,
                                'module_name' => 'mode_of_procurements',
                                'record_type' => 'mode_of_procurement',
                                'action_name' => 'update_mode_of_procurement',
                                'description' => 'Updated mode of procurement.',
                                'new_values' => [
                                    'mode_code' => $form['mode_code'],
                                    'mode_name' => $form['mode_name'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Mode of procurement updated successfully.');
                            redirect('modules/mode_of_procurements/index.php');
                        }
                    }
                } else {
                    $stmt = $db->prepare('INSERT INTO mode_of_procurements (mode_code, mode_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('sssii', $form['mode_code'], $form['mode_name'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newId = (int) $stmt->insert_id;
                        $stmt->close();

                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'mode_of_procurements',
                                'record_id' => $newId,
                                'module_name' => 'mode_of_procurements',
                                'record_type' => 'mode_of_procurement',
                                'action_name' => 'create_mode_of_procurement',
                                'description' => 'Created mode of procurement.',
                                'new_values' => [
                                    'mode_code' => $form['mode_code'],
                                    'mode_name' => $form['mode_name'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Mode of procurement created successfully.');
                            redirect('modules/mode_of_procurements/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the mode of procurement.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();

            $stmt = $db->prepare('UPDATE mode_of_procurements SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'mode_of_procurements',
                        'record_id' => $recordId,
                        'module_name' => 'mode_of_procurements',
                        'record_type' => 'mode_of_procurement',
                        'action_name' => 'deactivate_mode_of_procurement',
                        'description' => 'Deactivated mode of procurement.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Mode of procurement deactivated successfully.');
                    redirect('modules/mode_of_procurements/index.php');
                }
            }

            $errors[] = 'Unable to deactivate the mode of procurement.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/mode_of_procurements/index.php');
            }

            $recordId = (int) ($_POST['id'] ?? 0);
            if (mode_of_procurements_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/mode_of_procurements/index.php');
            }

            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare('SELECT mode_code, mode_name FROM mode_of_procurements WHERE id = ? LIMIT 1');
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }

            $stmt = $db->prepare('DELETE FROM mode_of_procurements WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'mode_of_procurements',
                        'record_id' => $recordId,
                        'module_name' => 'mode_of_procurements',
                        'record_type' => 'mode_of_procurement',
                        'action_name' => 'hard_delete_mode_of_procurement',
                        'description' => 'Permanently deleted mode of procurement.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/mode_of_procurements/index.php');
                }
            }

            $errors[] = 'Unable to permanently delete the mode of procurement.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare('SELECT id, mode_code, mode_name, description, is_active FROM mode_of_procurements WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'mode_code' => $record['mode_code'],
                    'mode_name' => $record['mode_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query('SELECT mop.id, mop.mode_code, mop.mode_name, mop.description, mop.is_active, mop.created_at, creator.full_name AS creator_name FROM mode_of_procurements mop LEFT JOIN users creator ON creator.id = mop.created_by ORDER BY mop.mode_name ASC');
    if ($result) {
        $procurementModes = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Modes of Procurement</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($procurementModes); ?> of <?php echo count($procurementModes); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/mode_of_procurements/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Mode'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Mode of Procurement' : 'New Mode of Procurement'; ?></h5><div class="text-muted small">Maintain procurement modes used on purchase orders.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Mode Code</label><input type="text" class="form-control" name="mode_code" value="<?php echo h($form['mode_code']); ?>" placeholder="Enter mode code" required><div class="form-text">Enter the short mode code manually.</div></div><div class="col-md-8"><label class="form-label">Mode Name</label><input type="text" class="form-control" name="mode_name" value="<?php echo h($form['mode_name']); ?>" required></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active mode of procurement</label></div></div><div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/mode_of_procurements/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Mode' : 'Save Mode'; ?></button></div></div></form></div></div>
                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label class="form-label">Search</label>
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search modes...">
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
                                <th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="mode">Mode <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($procurementModes): ?>
                                <?php foreach ($procurementModes as $procurementMode): ?>
                                    <tr data-status="<?php echo (int) $procurementMode['is_active'] ? 'active' : 'inactive'; ?>">
                                        <td class="fw-semibold"><?php echo h($procurementMode['mode_code']); ?></td>
                                        <td>
                                            <div><?php echo h($procurementMode['mode_name']); ?></div>
                                            <small class="text-muted"><?php echo h($procurementMode['description'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo (int) $procurementMode['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                                <?php echo (int) $procurementMode['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo h(date('M d, Y', strtotime($procurementMode['created_at']))); ?></div>
                                            <small class="text-muted"><?php echo h($procurementMode['creator_name'] ?: 'System'); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                <a href="<?php echo base_url('modules/mode_of_procurements/index.php?edit=' . (int) $procurementMode['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>

                                                <?php if ((int) $procurementMode['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this mode of procurement?');" class="d-inline">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $procurementMode['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                                            <i class="bi bi-slash-circle"></i> Deactivate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                    <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="hard_delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $procurementMode['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash3"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr data-status="inactive">
                                    <td colspan="5" class="text-center text-muted py-4">No mode of procurements found yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

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
</div></div>
</section>

<script>
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
        emptyMessage: 'No procurement modes matched your search or status filter.'
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
