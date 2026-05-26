<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

function models_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'models', $recordId, [
        "SELECT 1 FROM stock_items WHERE model_id = ? LIMIT 1",
        "SELECT 1 FROM receiving_item_details WHERE model_id = ? LIMIT 1",
    ]);
}

$db = db();
$page_title = 'Models';
$flash = get_flash();
$errors = [];
$models = [];
$brands = [];
$form = [
    'id' => 0,
    'brand_id' => '',
    'model_code' => '',
    'model_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'models');
    $brandResult = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($brandResult) {
        $brands = $brandResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['brand_id'] = old($_POST, 'brand_id');
            $form['model_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'model_code')) : $generatedCode;
            $form['model_name'] = old($_POST, 'model_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['brand_id'] === '') {
                $errors[] = 'Brand is required.';
            }
            if ($form['model_name'] === '') {
                $errors[] = 'Model name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM models WHERE (model_code = ? OR (brand_id = ? AND model_name = ?)) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $brandId = (int) $form['brand_id'];
                $duplicateStmt->bind_param('sisi', $form['model_code'], $brandId, $form['model_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Model code or brand/model combination already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $userId = current_user_id();
                $brandId = (int) $form['brand_id'];
                $isActive = (int) $form['is_active'];

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE models SET brand_id = ?, model_code = ?, model_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('isssiii', $brandId, $form['model_code'], $form['model_name'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, ['action' => 'update', 'table_name' => 'models', 'record_id' => $recordId, 'module_name' => 'models', 'record_type' => 'model', 'action_name' => 'update_model', 'description' => 'Updated model record.', 'new_values' => ['brand_id' => $brandId, 'model_code' => $form['model_code'], 'model_name' => $form['model_name'], 'description' => $form['description'], 'is_active' => $isActive]]);
                            set_flash('success', 'Model updated successfully.');
                            redirect('modules/models/index.php');
                        }
                    }
                } else {
                    $form['model_code'] = next_module_code($db, 'models');
                    $stmt = $db->prepare("INSERT INTO models (brand_id, model_code, model_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('isssii', $brandId, $form['model_code'], $form['model_name'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, ['action' => 'insert', 'table_name' => 'models', 'record_id' => $newId, 'module_name' => 'models', 'record_type' => 'model', 'action_name' => 'create_model', 'description' => 'Created model record.', 'new_values' => ['brand_id' => $brandId, 'model_code' => $form['model_code'], 'model_name' => $form['model_name'], 'description' => $form['description'], 'is_active' => $isActive]]);
                            set_flash('success', 'Model created successfully.');
                            redirect('modules/models/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the model.';
            }
        } elseif ($action === 'quick_add_brand') {
            $brandName = trim((string) ($_POST['brand_name'] ?? ''));
            $brandDescription = trim((string) ($_POST['description'] ?? ''));

            if ($brandName === '') {
                $errors[] = 'Brand name is required.';
            }

            if (!$errors) {
                $duplicateStmt = $db->prepare("SELECT id FROM brands WHERE brand_name = ? LIMIT 1");
                if ($duplicateStmt) {
                    $duplicateStmt->bind_param('s', $brandName);
                    $duplicateStmt->execute();
                    if ($duplicateStmt->get_result()->fetch_assoc()) {
                        $errors[] = 'Brand already exists.';
                    }
                    $duplicateStmt->close();
                }
            }

            if (!$errors) {
                $brandCode = next_module_code($db, 'brands');
                $userId = current_user_id();
                $stmt = $db->prepare("INSERT INTO brands (brand_code, brand_name, description, is_active, created_by) VALUES (?, ?, ?, 1, ?)");
                if ($stmt) {
                    $stmt->bind_param('sssi', $brandCode, $brandName, $brandDescription, $userId);
                    $saved = $stmt->execute();
                    $newId = (int) $stmt->insert_id;
                    $stmt->close();
                    if ($saved) {
                        write_audit_log($db, ['action' => 'insert', 'table_name' => 'brands', 'record_id' => $newId, 'module_name' => 'models', 'record_type' => 'brand', 'action_name' => 'quick_add_brand', 'description' => 'Quick-added brand from Models module.', 'new_values' => ['brand_code' => $brandCode, 'brand_name' => $brandName, 'description' => $brandDescription, 'is_active' => 1]]);
                        set_flash('success', 'Brand added. You can now select it for a model.');
                        redirect('modules/models/index.php?brand_id=' . $newId);
                    }
                }
                $errors[] = 'Unable to add the brand.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE models SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'update', 'table_name' => 'models', 'record_id' => $recordId, 'module_name' => 'models', 'record_type' => 'model', 'action_name' => 'deactivate_model', 'description' => 'Deactivated model record.', 'new_values' => ['is_active' => 0]]);
                    set_flash('success', 'Model deactivated successfully.');
                    redirect('modules/models/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the model.';
        } elseif ($action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE models SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'update', 'table_name' => 'models', 'record_id' => $recordId, 'module_name' => 'models', 'record_type' => 'model', 'action_name' => 'reactivate_model', 'description' => 'Reactivated model record.', 'new_values' => ['is_active' => 1]]);
                    set_flash('success', 'Model reactivated successfully.');
                    redirect('modules/models/index.php');
                }
            }
            $errors[] = 'Unable to reactivate the model.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/models/index.php');
            }

            $recordId = (int) ($_POST['id'] ?? 0);
            if (models_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/models/index.php');
            }
            $auditSnapshot = ['id' => $recordId]; $auditStmt = $db->prepare("SELECT brand_id, model_code, model_name FROM models WHERE id = ? LIMIT 1"); if ($auditStmt) { $auditStmt->bind_param('i', $recordId); $auditStmt->execute(); $auditRow = $auditStmt->get_result()->fetch_assoc(); $auditStmt->close(); if ($auditRow) $auditSnapshot = $auditRow; }

            $stmt = $db->prepare("DELETE FROM models WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'delete', 'table_name' => 'models', 'record_id' => $recordId, 'module_name' => 'models', 'record_type' => 'model', 'action_name' => 'hard_delete_model', 'description' => 'Permanently deleted model record.', 'old_values' => $auditSnapshot]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/models/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the model.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, brand_id, model_code, model_name, description, is_active FROM models WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'brand_id' => (string) $record['brand_id'],
                    'model_code' => $record['model_code'],
                    'model_name' => $record['model_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    } elseif ((int) ($_GET['brand_id'] ?? 0) > 0) {
        $requestedBrandId = (int) $_GET['brand_id'];
        foreach ($brands as $brand) {
            if ((int) ($brand['id'] ?? 0) === $requestedBrandId) {
                $form['brand_id'] = (string) $requestedBrandId;
                break;
            }
        }
    }

    $result = $db->query("
        SELECT m.id, m.model_code, m.model_name, m.description, m.is_active, m.created_at,
               b.brand_name, creator.full_name AS creator_name
        FROM models m
        INNER JOIN brands b ON b.id = m.brand_id
        LEFT JOIN users creator ON creator.id = m.created_by
        ORDER BY b.brand_name ASC, m.model_name ASC
    ");
    if ($result) {
        $models = $result->fetch_all(MYSQLI_ASSOC);
    }
}

$showForm = $form['id'] > 0 || $form['brand_id'] !== '';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Models</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($models); ?> of <?php echo count($models); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/models/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $showForm ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Model'; ?></button></div></div>
<div class="collapse <?php echo $showForm ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Model' : 'New Model'; ?></h5><div class="text-muted small">Manage model records and assign them to active brands.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="master-data-form-layout"><div class="master-data-form-main"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Identity</div><h6 class="mb-1">Brand and Model</h6><div class="text-muted small">Connect each model to its correct brand so downstream item records stay standardized.</div></div></div><div class="master-data-panel-body"><div class="row g-3"><div class="col-md-6"><label class="form-label">Brand</label><div class="input-group"><select class="form-select" name="brand_id" required data-placeholder="Select brand"><option value="">Select brand</option><?php foreach ($brands as $brand): ?><option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option><?php endforeach; ?></select><button class="btn btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#quickAddBrandModal"><i class="bi bi-plus-circle"></i> Brand</button></div></div><div class="col-md-6"><label class="form-label">Model Code</label><input type="text" class="form-control" name="model_code" value="<?php echo h($form['id'] > 0 ? $form['model_code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using `MDL-YYYY-0001` format.</div></div><div class="col-md-6"><label class="form-label">Model Name</label><input type="text" class="form-control" name="model_name" value="<?php echo h($form['model_name']); ?>" maxlength="150" required></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="4"><?php echo h($form['description']); ?></textarea></div></div></div></div><div class="master-data-form-actions"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/models/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Model' : 'Save Model'; ?></button></div></div><div class="master-data-form-side"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Status</div><h6 class="mb-1">Model Controls</h6></div></div><div class="master-data-panel-body"><div class="master-data-helper mb-3">Recommendation: keep one record per actual brand-model combination to avoid duplicate inventory references.</div><div class="master-data-side-list"><div class="master-data-side-item"><span>Record state</span><span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span></div></div><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active model</label></div></div></div></div></div></form></div></div>
                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label class="form-label">Search</label>
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search models...">
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
                                <th data-sort="model">Brand / Model <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($models): foreach ($models as $model): ?>
                                <tr data-status="<?php echo (int) $model['is_active'] ? 'active' : 'inactive'; ?>">
                                    <td class="fw-semibold"><?php echo h($model['model_code']); ?></td>
                                    <td><div><?php echo h($model['brand_name']); ?></div><div class="fw-semibold"><?php echo h($model['model_name']); ?></div><small class="text-muted"><?php echo h($model['description'] ?? ''); ?></small></td>
                                    <td><span class="badge <?php echo (int) $model['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $model['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                    <td><div><?php echo h(date('M d, Y', strtotime($model['created_at']))); ?></div><small class="text-muted"><?php echo h($model['creator_name'] ?: 'System'); ?></small></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a href="<?php echo base_url('modules/models/index.php?edit=' . (int) $model['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                            <?php if ((int) $model['is_active'] === 1): ?>
                                                <form method="post" onsubmit="return confirm('Deactivate this model?');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $model['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" onsubmit="return confirm('Reactivate this model?');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="id" value="<?php echo (int) $model['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="hard_delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $model['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No models found yet.</td></tr>
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
<div class="modal fade" id="quickAddBrandModal" tabindex="-1" aria-labelledby="quickAddBrandModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="quick_add_brand">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickAddBrandModalLabel">Add Brand</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Brand Name</label>
                        <input type="text" class="form-control" name="brand_name" maxlength="150" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Brand</button>
                </div>
            </form>
        </div>
    </div>
</div>
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
        emptyMessage: 'No models matched your search or status filter.'
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
