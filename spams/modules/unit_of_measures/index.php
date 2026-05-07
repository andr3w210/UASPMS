<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';

require_login();

function unit_of_measures_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'unit_of_measures', $recordId, [
        'SELECT 1 FROM purchase_order_items WHERE unit_of_measure_id = ? LIMIT 1',
        'SELECT 1 FROM stock_catalog WHERE unit_of_measure_id = ? LIMIT 1',
    ]);
}

$db = db();
$page_title = 'Unit of Measure';
$flash = get_flash();
$errors = [];
$units = [];
$form = [
    'id' => 0,
    'uom_code' => '',
    'uom_name' => '',
    'abbreviation' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'unit_of_measures');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['uom_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'uom_code')) : $generatedCode;
            $form['uom_name'] = old($_POST, 'uom_name');
            $form['abbreviation'] = strtolower(old($_POST, 'abbreviation'));
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['uom_name'] === '') {
                $errors[] = 'Unit name is required.';
            }
            if ($form['abbreviation'] === '') {
                $errors[] = 'Abbreviation is required.';
            }

            $duplicateStmt = $db->prepare('SELECT id FROM unit_of_measures WHERE (uom_code = ? OR uom_name = ? OR abbreviation = ?) AND id != ? LIMIT 1');
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('sssi', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Code, unit name, or abbreviation already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare('UPDATE unit_of_measures SET uom_code = ?, uom_name = ?, abbreviation = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();

                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'unit_of_measures',
                                'record_id' => $recordId,
                                'module_name' => 'unit_of_measures',
                                'record_type' => 'unit_of_measure',
                                'action_name' => 'update_unit_of_measure',
                                'description' => 'Updated unit of measure.',
                                'new_values' => [
                                    'uom_code' => $form['uom_code'],
                                    'uom_name' => $form['uom_name'],
                                    'abbreviation' => $form['abbreviation'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Unit of measure updated successfully.');
                            redirect('modules/unit_of_measures/index.php');
                        }
                    }
                } else {
                    $form['uom_code'] = next_module_code($db, 'unit_of_measures');
                    $stmt = $db->prepare('INSERT INTO unit_of_measures (uom_code, uom_name, abbreviation, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newId = (int) $stmt->insert_id;
                        $stmt->close();

                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'unit_of_measures',
                                'record_id' => $newId,
                                'module_name' => 'unit_of_measures',
                                'record_type' => 'unit_of_measure',
                                'action_name' => 'create_unit_of_measure',
                                'description' => 'Created unit of measure.',
                                'new_values' => [
                                    'uom_code' => $form['uom_code'],
                                    'uom_name' => $form['uom_name'],
                                    'abbreviation' => $form['abbreviation'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Unit of measure created successfully.');
                            redirect('modules/unit_of_measures/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the unit of measure.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();

            $stmt = $db->prepare('UPDATE unit_of_measures SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'unit_of_measures',
                        'record_id' => $recordId,
                        'module_name' => 'unit_of_measures',
                        'record_type' => 'unit_of_measure',
                        'action_name' => 'deactivate_unit_of_measure',
                        'description' => 'Deactivated unit of measure.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Unit of measure deactivated successfully.');
                    redirect('modules/unit_of_measures/index.php');
                }
            }

            $errors[] = 'Unable to deactivate the unit of measure.';
        } elseif ($action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();

            $stmt = $db->prepare('UPDATE unit_of_measures SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'unit_of_measures',
                        'record_id' => $recordId,
                        'module_name' => 'unit_of_measures',
                        'record_type' => 'unit_of_measure',
                        'action_name' => 'reactivate_unit_of_measure',
                        'description' => 'Reactivated unit of measure.',
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success', 'Unit of measure reactivated successfully.');
                    redirect('modules/unit_of_measures/index.php');
                }
            }

            $errors[] = 'Unable to reactivate the unit of measure.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/unit_of_measures/index.php');
            }

            $recordId = (int) ($_POST['id'] ?? 0);
            if (unit_of_measures_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/unit_of_measures/index.php');
            }

            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare('SELECT uom_code, uom_name, abbreviation FROM unit_of_measures WHERE id = ? LIMIT 1');
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }

            $stmt = $db->prepare('DELETE FROM unit_of_measures WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'unit_of_measures',
                        'record_id' => $recordId,
                        'module_name' => 'unit_of_measures',
                        'record_type' => 'unit_of_measure',
                        'action_name' => 'hard_delete_unit_of_measure',
                        'description' => 'Permanently deleted unit of measure.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/unit_of_measures/index.php');
                }
            }

            $errors[] = 'Unable to permanently delete the unit of measure.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare('SELECT id, uom_code, uom_name, abbreviation, description, is_active FROM unit_of_measures WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'uom_code' => $record['uom_code'],
                    'uom_name' => $record['uom_name'],
                    'abbreviation' => $record['abbreviation'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query('SELECT u.id, u.uom_code, u.uom_name, u.abbreviation, u.description, u.is_active, u.created_at, creator.full_name AS creator_name FROM unit_of_measures u LEFT JOIN users creator ON creator.id = u.created_by ORDER BY u.uom_name ASC');
    if ($result) {
        $units = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Units of Measure</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($units); ?> of <?php echo count($units); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/unit_of_measures/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Unit'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Unit of Measure' : 'New Unit of Measure'; ?></h5><div class="text-muted small">Maintain units used across purchase orders and catalog records.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="master-data-form-layout"><div class="master-data-form-main"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Identity</div><h6 class="mb-1">Unit Details</h6><div class="text-muted small">Use concise names and abbreviations so item records and reports remain easy to scan.</div></div></div><div class="master-data-panel-body"><div class="row g-3"><div class="col-md-4"><label class="form-label">UOM Code</label><input type="text" class="form-control" name="uom_code" value="<?php echo h($form['id'] > 0 ? $form['uom_code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using UOM-YYYY-0001 format.</div></div><div class="col-md-4"><label class="form-label">Unit Name</label><input type="text" class="form-control" name="uom_name" value="<?php echo h($form['uom_name']); ?>" required></div><div class="col-md-4"><label class="form-label">Abbreviation</label><input type="text" class="form-control" name="abbreviation" value="<?php echo h($form['abbreviation']); ?>" required></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"><?php echo h($form['description']); ?></textarea></div></div></div></div><div class="master-data-form-actions"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/unit_of_measures/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Unit' : 'Save Unit'; ?></button></div></div><div class="master-data-form-side"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Status</div><h6 class="mb-1">Unit Controls</h6></div></div><div class="master-data-panel-body"><div class="master-data-helper mb-3">Recommendation: standardize abbreviations early so stock, receiving, and PO rows use the same unit labels everywhere.</div><div class="master-data-side-list"><div class="master-data-side-item"><span>Record state</span><span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span></div></div><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active unit of measure</label></div></div></div></div></div></form></div></div>
                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label class="form-label">Search</label>
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search units...">
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
                                <th data-sort="unit">Unit <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="abbr">Abbreviation <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($units): ?>
                                <?php foreach ($units as $unit): ?>
                                    <tr data-status="<?php echo (int) $unit['is_active'] ? 'active' : 'inactive'; ?>">
                                        <td class="fw-semibold"><?php echo h($unit['uom_code']); ?></td>
                                        <td>
                                            <div><?php echo h($unit['uom_name']); ?></div>
                                            <small class="text-muted"><?php echo h($unit['description'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo h($unit['abbreviation']); ?></td>
                                        <td>
                                            <span class="badge <?php echo (int) $unit['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                                <?php echo (int) $unit['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo h(date('M d, Y', strtotime($unit['created_at']))); ?></div>
                                            <small class="text-muted"><?php echo h($unit['creator_name'] ?: 'System'); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                                <a href="<?php echo base_url('modules/unit_of_measures/index.php?edit=' . (int) $unit['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>

                                                <?php if ((int) $unit['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this unit of measure?');" class="d-inline">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $unit['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                                            <i class="bi bi-slash-circle"></i> Deactivate
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" onsubmit="return confirm('Reactivate this unit of measure?');" class="d-inline">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="reactivate">
                                                        <input type="hidden" name="id" value="<?php echo (int) $unit['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-success">
                                                            <i class="bi bi-arrow-counterclockwise"></i> Reactivate
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                    <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="hard_delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $unit['id']; ?>">
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
                                    <td colspan="6" class="text-center text-muted py-4">No unit of measures found yet.</td>
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
        emptyMessage: 'No units of measure matched your search or status filter.'
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
