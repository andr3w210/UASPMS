<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function brands_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'brands', $recordId, [
        "SELECT 1 FROM stock_items WHERE brand_id = ? LIMIT 1",
        "SELECT 1 FROM receiving_item_details WHERE brand_id = ? LIMIT 1",
    ]);
}

$db = db();
$page_title = 'Brands';
$flash = get_flash();
$errors = [];
$brands = [];
$form = [
    'id' => 0,
    'brand_code' => '',
    'brand_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'brands');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['brand_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'brand_code')) : $generatedCode;
            $form['brand_name'] = old($_POST, 'brand_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['brand_name'] === '') {
                $errors[] = 'Brand name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM brands WHERE (brand_code = ? OR brand_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['brand_code'], $form['brand_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Brand code or brand name already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE brands SET brand_code = ?, brand_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssiii', $form['brand_code'], $form['brand_name'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, ['action' => 'update', 'table_name' => 'brands', 'record_id' => $recordId, 'module_name' => 'brands', 'record_type' => 'brand', 'action_name' => 'update_brand', 'description' => 'Updated brand record.', 'new_values' => ['brand_code' => $form['brand_code'], 'brand_name' => $form['brand_name'], 'description' => $form['description'], 'is_active' => $isActive]]);
                            set_flash('success', 'Brand updated successfully.');
                            redirect('modules/brands/index.php');
                        }
                    }
                } else {
                    $form['brand_code'] = next_module_code($db, 'brands');
                    $stmt = $db->prepare("INSERT INTO brands (brand_code, brand_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssii', $form['brand_code'], $form['brand_name'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, ['action' => 'insert', 'table_name' => 'brands', 'record_id' => $newId, 'module_name' => 'brands', 'record_type' => 'brand', 'action_name' => 'create_brand', 'description' => 'Created brand record.', 'new_values' => ['brand_code' => $form['brand_code'], 'brand_name' => $form['brand_name'], 'description' => $form['description'], 'is_active' => $isActive]]);
                            set_flash('success', 'Brand created successfully.');
                            redirect('modules/brands/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the brand.';
            }
        } elseif ($action === 'quick_add_model') {
            $brandId = (int) ($_POST['brand_id'] ?? 0);
            $modelName = trim((string) ($_POST['model_name'] ?? ''));
            $modelDescription = trim((string) ($_POST['description'] ?? ''));

            if ($brandId <= 0) {
                $errors[] = 'Brand is required.';
            }
            if ($modelName === '') {
                $errors[] = 'Model name is required.';
            }

            $brandName = '';
            if (!$errors) {
                $brandStmt = $db->prepare("SELECT brand_name FROM brands WHERE id = ? AND is_active = 1 LIMIT 1");
                if ($brandStmt) {
                    $brandStmt->bind_param('i', $brandId);
                    $brandStmt->execute();
                    $brandRow = $brandStmt->get_result()->fetch_assoc();
                    $brandStmt->close();
                    if ($brandRow) {
                        $brandName = (string) ($brandRow['brand_name'] ?? '');
                    }
                }
                if ($brandName === '') {
                    $errors[] = 'Selected brand is not active or was not found.';
                }
            }

            if (!$errors) {
                $duplicateStmt = $db->prepare("SELECT id FROM models WHERE brand_id = ? AND model_name = ? LIMIT 1");
                if ($duplicateStmt) {
                    $duplicateStmt->bind_param('is', $brandId, $modelName);
                    $duplicateStmt->execute();
                    if ($duplicateStmt->get_result()->fetch_assoc()) {
                        $errors[] = 'This model already exists for the selected brand.';
                    }
                    $duplicateStmt->close();
                }
            }

            if (!$errors) {
                $modelCode = next_module_code($db, 'models');
                $userId = current_user_id();
                $stmt = $db->prepare("INSERT INTO models (brand_id, model_code, model_name, description, is_active, created_by) VALUES (?, ?, ?, ?, 1, ?)");
                if ($stmt) {
                    $stmt->bind_param('isssi', $brandId, $modelCode, $modelName, $modelDescription, $userId);
                    $saved = $stmt->execute();
                    $newId = (int) $stmt->insert_id;
                    $stmt->close();
                    if ($saved) {
                        write_audit_log($db, ['action' => 'insert', 'table_name' => 'models', 'record_id' => $newId, 'module_name' => 'brands', 'record_type' => 'model', 'action_name' => 'quick_add_model', 'description' => 'Quick-added model from Brands module.', 'new_values' => ['brand_id' => $brandId, 'brand_name' => $brandName, 'model_code' => $modelCode, 'model_name' => $modelName, 'description' => $modelDescription, 'is_active' => 1]]);
                        set_flash('success', 'Model added for ' . $brandName . '.');
                        redirect('modules/brands/index.php');
                    }
                }
                $errors[] = 'Unable to add the model.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE brands SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'update', 'table_name' => 'brands', 'record_id' => $recordId, 'module_name' => 'brands', 'record_type' => 'brand', 'action_name' => 'deactivate_brand', 'description' => 'Deactivated brand record.', 'new_values' => ['is_active' => 0]]);
                    set_flash('success', 'Brand deactivated successfully.');
                    redirect('modules/brands/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the brand.';
        } elseif ($action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE brands SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'update', 'table_name' => 'brands', 'record_id' => $recordId, 'module_name' => 'brands', 'record_type' => 'brand', 'action_name' => 'reactivate_brand', 'description' => 'Reactivated brand record.', 'new_values' => ['is_active' => 1]]);
                    set_flash('success', 'Brand reactivated successfully.');
                    redirect('modules/brands/index.php');
                }
            }
            $errors[] = 'Unable to reactivate the brand.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/brands/index.php');
            }

            $recordId = (int) ($_POST['id'] ?? 0);
            if (brands_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/brands/index.php');
            }
            $auditSnapshot = ['id' => $recordId]; $auditStmt = $db->prepare("SELECT brand_code, brand_name FROM brands WHERE id = ? LIMIT 1"); if ($auditStmt) { $auditStmt->bind_param('i', $recordId); $auditStmt->execute(); $auditRow = $auditStmt->get_result()->fetch_assoc(); $auditStmt->close(); if ($auditRow) $auditSnapshot = $auditRow; }

            $stmt = $db->prepare("DELETE FROM brands WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, ['action' => 'delete', 'table_name' => 'brands', 'record_id' => $recordId, 'module_name' => 'brands', 'record_type' => 'brand', 'action_name' => 'hard_delete_brand', 'description' => 'Permanently deleted brand record.', 'old_values' => $auditSnapshot]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/brands/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the brand.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, brand_code, brand_name, description, is_active FROM brands WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'brand_code' => $record['brand_code'],
                    'brand_name' => $record['brand_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT b.id, b.brand_code, b.brand_name, b.description, b.is_active, b.created_at, creator.full_name AS creator_name
        FROM brands b
        LEFT JOIN users creator ON creator.id = b.created_by
        ORDER BY b.brand_name ASC
    ");
    if ($result) {
        $brands = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if ($errors): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Brands</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($brands); ?> of <?php echo count($brands); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/brands/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Brand'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Brand' : 'New Brand'; ?></h5><div class="text-muted small">Create or update brand records.</div></div></div><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-4"><label class="form-label">Brand Code</label><input type="text" class="form-control" name="brand_code" value="<?php echo h($form['id'] > 0 ? $form['brand_code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using `BRD-YYYY-0001` format.</div></div><div class="col-md-8"><label class="form-label">Brand Name</label><input type="text" class="form-control" name="brand_name" value="<?php echo h($form['brand_name']); ?>" maxlength="150" required></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active brand</label></div></div><div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/brands/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Brand' : 'Save Brand'; ?></button></div></div></form></div></div>
                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-6">
                            <label class="form-label">Search</label>
                            <input type="search" id="tableSearch" class="form-control" placeholder="Search brands...">
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
                                <th data-sort="brand">Brand <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($brands): foreach ($brands as $brand): ?>
                                <tr data-status="<?php echo (int) $brand['is_active'] ? 'active' : 'inactive'; ?>">
                                    <td class="fw-semibold"><?php echo h($brand['brand_code']); ?></td>
                                    <td><div><?php echo h($brand['brand_name']); ?></div><small class="text-muted"><?php echo h($brand['description'] ?? ''); ?></small></td>
                                    <td><span class="badge <?php echo (int) $brand['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $brand['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                    <td><div><?php echo h(date('M d, Y', strtotime($brand['created_at']))); ?></div><small class="text-muted"><?php echo h($brand['creator_name'] ?: 'System'); ?></small></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a href="<?php echo base_url('modules/brands/index.php?edit=' . (int) $brand['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </a>
                                            <?php if ((int) $brand['is_active'] === 1): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success quick-add-model-btn" data-bs-toggle="modal" data-bs-target="#quickAddModelModal" data-brand-id="<?php echo (int) $brand['id']; ?>" data-brand-name="<?php echo h($brand['brand_name']); ?>">
                                                    <i class="bi bi-plus-circle"></i> Model
                                                </button>
                                            <?php endif; ?>
                                            <?php if ((int) $brand['is_active'] === 1): ?>
                                                
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $brand['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                                        <i class="bi bi-slash-circle"></i> Deactivate
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="reactivate">
                                                    <input type="hidden" name="id" value="<?php echo (int) $brand['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="bi bi-arrow-counterclockwise"></i> Reactivate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="hard_delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $brand['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash3"></i> Delete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No brands found yet.</td></tr>
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
<div class="modal fade" id="quickAddModelModal" tabindex="-1" aria-labelledby="quickAddModelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            
                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="quick_add_model">
                <div class="modal-header">
                    <h5 class="modal-title" id="quickAddModelModalLabel">Add Model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Brand</label>
                        <select class="form-select" name="brand_id" id="quickAddModelBrand" required>
                            <option value="">Select brand</option>
                            <?php foreach ($brands as $brand): ?>
                                <?php if ((int) ($brand['is_active'] ?? 0) === 1): ?>
                                    <option value="<?php echo (int) $brand['id']; ?>"><?php echo h($brand['brand_name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Model Name</label>
                        <input type="text" class="form-control" name="model_name" maxlength="150" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Model</button>
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
        emptyMessage: 'No brands matched your search or status filter.'
    };
    if (typeof window.initMasterDataList === 'function') {
        window.initMasterDataList('dataTable', options);
    } else {
        window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];
        window.__spamsPendingMasterDataLists.push(['dataTable', options]);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.quick-add-model-btn');
        if (!button) {
            return;
        }
        var brandSelect = document.getElementById('quickAddModelBrand');
        if (brandSelect) {
            brandSelect.value = button.getAttribute('data-brand-id') || '';
        }
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

