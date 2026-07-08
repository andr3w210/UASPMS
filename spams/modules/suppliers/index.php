<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

function suppliers_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'suppliers', $recordId, [
        "SELECT 1 FROM purchase_orders WHERE supplier_id = ? LIMIT 1",
    ]);
}

$db = db();
$page_title = 'Suppliers';
$flash = get_flash();
$errors = [];
$suppliers = [];
$form = [
    'id' => 0,
    'supplier_code' => '',
    'supplier_name' => '',
    'contact_person' => '',
    'contact_no' => '',
    'email' => '',
    'address' => '',
    'tin_no' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'suppliers');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['supplier_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'supplier_code')) : $generatedCode;
            $form['supplier_name'] = old($_POST, 'supplier_name');
            $form['contact_person'] = old($_POST, 'contact_person');
            $form['contact_no'] = old($_POST, 'contact_no');
            $form['email'] = old($_POST, 'email');
            $form['address'] = old($_POST, 'address');
            $form['tin_no'] = old($_POST, 'tin_no');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
            if ($form['supplier_name'] === '') $errors[] = 'Supplier name is required.';
            $duplicateStmt = $db->prepare("SELECT id FROM suppliers WHERE (supplier_code = ? OR supplier_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['supplier_code'], $form['supplier_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) $errors[] = 'Supplier code or supplier name already exists.';
                $duplicateStmt->close();
            }
            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE suppliers SET supplier_code = ?, supplier_name = ?, contact_person = ?, contact_no = ?, email = ?, address = ?, tin_no = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssssssiii', $form['supplier_code'], $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'suppliers',
                                'record_id' => $recordId,
                                'module_name' => 'suppliers',
                                'record_type' => 'supplier',
                                'action_name' => 'update_supplier',
                                'description' => 'Updated supplier record.',
                                'new_values' => [
                                    'supplier_code' => $form['supplier_code'],
                                    'supplier_name' => $form['supplier_name'],
                                    'contact_person' => $form['contact_person'],
                                    'contact_no' => $form['contact_no'],
                                    'email' => $form['email'],
                                    'address' => $form['address'],
                                    'tin_no' => $form['tin_no'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Supplier updated successfully.');
                            redirect('modules/suppliers/index.php');
                        }
                    }
                } else {
                    $form['supplier_code'] = next_module_code($db, 'suppliers');
                    $stmt = $db->prepare("INSERT INTO suppliers (supplier_code, supplier_name, contact_person, contact_no, email, address, tin_no, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssssssii', $form['supplier_code'], $form['supplier_name'], $form['contact_person'], $form['contact_no'], $form['email'], $form['address'], $form['tin_no'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newSupplierId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'suppliers',
                                'record_id' => $newSupplierId,
                                'module_name' => 'suppliers',
                                'record_type' => 'supplier',
                                'action_name' => 'create_supplier',
                                'description' => 'Created supplier record.',
                                'new_values' => [
                                    'supplier_code' => $form['supplier_code'],
                                    'supplier_name' => $form['supplier_name'],
                                    'contact_person' => $form['contact_person'],
                                    'contact_no' => $form['contact_no'],
                                    'email' => $form['email'],
                                    'address' => $form['address'],
                                    'tin_no' => $form['tin_no'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Supplier created successfully.');
                            redirect('modules/suppliers/index.php');
                        }
                    }
                }
                $errors[] = 'Unable to save the supplier.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE suppliers SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'suppliers',
                        'record_id' => $recordId,
                        'module_name' => 'suppliers',
                        'record_type' => 'supplier',
                        'action_name' => 'deactivate_supplier',
                        'description' => 'Deactivated supplier record.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Supplier deactivated successfully.');
                    redirect('modules/suppliers/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the supplier.';
        } elseif ($action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE suppliers SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'suppliers',
                        'record_id' => $recordId,
                        'module_name' => 'suppliers',
                        'record_type' => 'supplier',
                        'action_name' => 'reactivate_supplier',
                        'description' => 'Reactivated supplier record.',
                        'new_values' => ['is_active' => 1],
                    ]);
                    set_flash('success', 'Supplier reactivated successfully.');
                    redirect('modules/suppliers/index.php');
                }
            }
            $errors[] = 'Unable to reactivate the supplier.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/suppliers/index.php');
            }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (suppliers_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/suppliers/index.php');
            }
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT supplier_code, supplier_name, contact_person FROM suppliers WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt = $db->prepare("DELETE FROM suppliers WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'suppliers',
                        'record_id' => $recordId,
                        'module_name' => 'suppliers',
                        'record_type' => 'supplier',
                        'action_name' => 'hard_delete_supplier',
                        'description' => 'Permanently deleted supplier record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/suppliers/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the supplier.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, supplier_code, supplier_name, contact_person, contact_no, email, address, tin_no, is_active FROM suppliers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'supplier_code' => $record['supplier_code'],
                    'supplier_name' => $record['supplier_name'],
                    'contact_person' => $record['contact_person'] ?? '',
                    'contact_no' => $record['contact_no'] ?? '',
                    'email' => $record['email'] ?? '',
                    'address' => $record['address'] ?? '',
                    'tin_no' => $record['tin_no'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("SELECT s.id, s.supplier_code, s.supplier_name, s.contact_person, s.contact_no, s.email, s.address, s.tin_no, s.is_active, s.created_at, creator.full_name AS creator_name FROM suppliers s LEFT JOIN users creator ON creator.id = s.created_by ORDER BY s.supplier_name ASC");
    if ($result) $suppliers = $result->fetch_all(MYSQLI_ASSOC);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Master Data</div>
                    <h4 class="mb-1">Supplier Directory</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($suppliers); ?> of <?php echo count($suppliers); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($form['id'] > 0): ?>
                        <a href="<?php echo base_url('modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Supplier'; ?>
                    </button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Supplier' : 'New Supplier'; ?></h5>
                            <div class="text-muted small">Keep supplier profiles complete so procurement and receiving records stay clean.</div>
                        </div>
                    </div>
                    <form method="post" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="master-data-form-layout">
                            <div class="master-data-form-main">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header">
                                        <div>
                                            <div class="master-data-panel-kicker">Identity</div>
                                            <h6 class="mb-1">Supplier Profile</h6>
                                            <div class="text-muted small">Keep supplier identity and legal contact information complete so purchase orders and receiving records stay clean.</div>
                                        </div>
                                    </div>
                                    <div class="master-data-panel-body">
                                        <div class="row g-3">
                                            <div class="col-md-3">
                                                <label class="form-label">Supplier Code</label>
                                                <input type="text" class="form-control" name="supplier_code" value="<?php echo h($form['id'] > 0 ? $form['supplier_code'] : $generatedCode); ?>" readonly>
                                            </div>
                                            <div class="col-md-9">
                                                <label class="form-label">Supplier Name</label>
                                                <input type="text" class="form-control" name="supplier_name" value="<?php echo h($form['supplier_name']); ?>" placeholder="Enter supplier or company name" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Contact Person</label>
                                                <input type="text" class="form-control" name="contact_person" value="<?php echo h($form['contact_person']); ?>" placeholder="Primary contact name">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Contact Number</label>
                                                <input type="text" class="form-control" name="contact_no" value="<?php echo h($form['contact_no']); ?>" placeholder="Phone or mobile number">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Email</label>
                                                <input type="email" class="form-control" name="email" value="<?php echo h($form['email']); ?>" placeholder="supplier@example.com">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">TIN No.</label>
                                                <input type="text" class="form-control" name="tin_no" value="<?php echo h($form['tin_no']); ?>" placeholder="Enter TIN number">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Address</label>
                                                <textarea class="form-control" name="address" rows="4" placeholder="Business address"><?php echo h($form['address']); ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="master-data-form-actions">
                                    <?php if ($form['id'] > 0): ?>
                                        <a href="<?php echo base_url('modules/suppliers/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Supplier' : 'Save Supplier'; ?></button>
                                </div>
                            </div>
                            <div class="master-data-form-side">
                                <div class="master-data-panel">
                                    <div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Status</div><h6 class="mb-1">Supplier Controls</h6></div></div>
                                    <div class="master-data-panel-body">
                                        <div class="master-data-helper mb-3">Recommendation: keep one clean supplier record per legal entity to avoid duplicate procurement history.</div>
                                        <div class="master-data-side-list"><div class="master-data-side-item"><span>Directory status</span><span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span></div></div>
                                        <div class="form-check form-switch mt-3">
                                            <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Active supplier</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Search</label>
                        <input type="search" id="tableSearch" class="form-control" placeholder="Search code, supplier name, contact person, email, or address">
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
                            <th>Code</th>
                            <th>Supplier</th>
                            <th>Contact</th>
                            <th>TIN</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($suppliers): foreach ($suppliers as $supplier): ?>
                            <tr data-status="<?php echo (int) $supplier['is_active'] ? 'active' : 'inactive'; ?>">
                                <td class="fw-semibold"><?php echo h($supplier['supplier_code']); ?></td>
                                <td><div class="fw-semibold"><?php echo h($supplier['supplier_name']); ?></div><small class="text-muted"><?php echo h($supplier['address'] ?? ''); ?></small></td>
                                <td><div><?php echo h($supplier['contact_person'] ?? ''); ?></div><small class="text-muted"><?php echo h(trim(($supplier['contact_no'] ?? '') . (($supplier['contact_no'] ?? '') && ($supplier['email'] ?? '') ? ' | ' : '') . ($supplier['email'] ?? ''))); ?></small></td>
                                <td><?php echo h($supplier['tin_no'] ?? ''); ?></td>
                                <td><span class="badge <?php echo (int) $supplier['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $supplier['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                <td><div><?php echo h(date('M d, Y', strtotime($supplier['created_at']))); ?></div><small class="text-muted"><?php echo h($supplier['creator_name'] ?: 'System'); ?></small></td>
                                <td class="text-end">
                                    <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                        <a href="<?php echo base_url('modules/suppliers/index.php?edit=' . (int) $supplier['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                        <?php if ((int) $supplier['is_active'] === 1): ?>
                                            <form method="post" onsubmit="return confirm('Deactivate this supplier?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $supplier['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form>
                                        <?php else: ?>
                                            <form method="post" onsubmit="return confirm('Reactivate this supplier?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="id" value="<?php echo (int) $supplier['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Reactivate</button></form>
                                        <?php endif; ?>
                                        <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                            <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $supplier['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr data-status="inactive"><td colspan="7" class="text-center text-muted py-4">No suppliers found yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
        emptyMessage: 'No suppliers matched your search or status filter.'
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
