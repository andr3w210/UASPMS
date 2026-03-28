<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

function offices_has_reference(mysqli $db, int $recordId): bool
{
    $checks = [
        "SELECT 1 FROM employees WHERE office_id = ? LIMIT 1",
        "SELECT 1 FROM distributions WHERE office_id = ? LIMIT 1",
    ];

    foreach ($checks as $sql) {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('i', $recordId);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }

    return false;
}

$db = db();
$page_title = 'Offices';
$flash = get_flash();
$errors = [];
$offices = [];
$employees = [];
$form = [
    'id' => 0,
    'office_code' => '',
    'office_name' => '',
    'office_head_employee_id' => '',
    'description' => '',
    'is_active' => '1',
];
$unitHeads = [];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'offices');
    $employeeResult = $db->query("SELECT id, first_name, middle_name, last_name, suffix_name, employee_no FROM employees WHERE is_active = 1 ORDER BY last_name, first_name");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['office_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'office_code')) : $generatedCode;
            $form['office_name'] = old($_POST, 'office_name');
            $form['office_head_employee_id'] = old($_POST, 'office_head_employee_id');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['office_name'] === '') {
                $errors[] = 'Office name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM offices WHERE (office_code = ? OR office_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $officeId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['office_code'], $form['office_name'], $officeId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Office code or office name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $officeHeadId = $form['office_head_employee_id'] !== '' ? (int) $form['office_head_employee_id'] : null;
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE offices SET office_code = ?, office_name = ?, department_id = NULL, office_head_employee_id = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $officeId = (int) $form['id'];
                        $stmt->bind_param('ssisiii', $form['office_code'], $form['office_name'], $officeHeadId, $form['description'], $isActive, $userId, $officeId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'offices',
                                'record_id' => $officeId,
                                'module_name' => 'offices',
                                'record_type' => 'office',
                                'action_name' => 'update_office',
                                'description' => 'Updated office record.',
                                'new_values' => [
                                    'office_code' => $form['office_code'],
                                    'office_name' => $form['office_name'],
                                    'office_head_employee_id' => $officeHeadId,
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Office updated successfully.');
                            redirect('modules/offices/index.php');
                        }
                    }
                } else {
                    $form['office_code'] = next_module_code($db, 'offices');
                    $stmt = $db->prepare("INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssisii', $form['office_code'], $form['office_name'], $officeHeadId, $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newOfficeId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'offices',
                                'record_id' => $newOfficeId,
                                'module_name' => 'offices',
                                'record_type' => 'office',
                                'action_name' => 'create_office',
                                'description' => 'Created office record.',
                                'new_values' => [
                                    'office_code' => $form['office_code'],
                                    'office_name' => $form['office_name'],
                                    'office_head_employee_id' => $officeHeadId,
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Office created successfully.');
                            redirect('modules/offices/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the office.';
            }
        } elseif ($action === 'delete') {
            $officeId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE offices SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $officeId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'offices',
                        'record_id' => $officeId,
                        'module_name' => 'offices',
                        'record_type' => 'office',
                        'action_name' => 'deactivate_office',
                        'description' => 'Deactivated office record.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Office deactivated successfully.');
                    redirect('modules/offices/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the office.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/offices/index.php');
            }

            $officeId = (int) ($_POST['id'] ?? 0);
            if (offices_has_reference($db, $officeId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/offices/index.php');
            }
            $auditSnapshot = ['id' => $officeId];
            $auditStmt = $db->prepare("SELECT office_code, office_name FROM offices WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $officeId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }

            $stmt = $db->prepare("DELETE FROM offices WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $officeId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'offices',
                        'record_id' => $officeId,
                        'module_name' => 'offices',
                        'record_type' => 'office',
                        'action_name' => 'hard_delete_office',
                        'description' => 'Permanently deleted office record.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/offices/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the office.';
        }
    }

    if (isset($_GET['edit'])) {
        $officeId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, office_code, office_name, office_head_employee_id, description, is_active FROM offices WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $officeId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'office_code' => $record['office_code'],
                    'office_name' => $record['office_name'],
                    'office_head_employee_id' => (string) ($record['office_head_employee_id'] ?? ''),
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $unitHeadWhere = "is_active = 1 AND office_id IS NOT NULL";
    if (schema_has_column($db, 'employees', 'is_unit_head')) {
        $unitHeadWhere .= " AND is_unit_head = 1";
    }
    $unitHeadResult = $db->query("SELECT office_id, first_name, middle_name, last_name, suffix_name FROM employees WHERE {$unitHeadWhere}");
    if ($unitHeadResult) {
        foreach ($unitHeadResult->fetch_all(MYSQLI_ASSOC) as $unitHeadRow) {
            $unitHeads[(int) $unitHeadRow['office_id']] = employee_display_name($unitHeadRow);
        }
    }

    $listResult = $db->query("
        SELECT o.id, o.office_code, o.office_name, o.description, o.is_active, o.created_at,
               e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM offices o
        LEFT JOIN employees e ON e.id = o.office_head_employee_id
        ORDER BY o.office_name ASC
    ");
    if ($listResult) {
        $offices = $listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0"><?php echo $form['id'] > 0 ? 'Edit Office' : 'Add New Office'; ?></h5>
                    <div class="text-muted small">Maintain office records and their assigned office heads.</div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Edit Office' : 'Add New'; ?>
                    </button>
                    <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
            </div>
            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?>" id="formCollapse">
                <div class="card-body p-4">
                    <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                    <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Office Code</label>
                                <input type="text" class="form-control" name="office_code" value="<?php echo h($form['id'] > 0 ? $form['office_code'] : $generatedCode); ?>" readonly>
                                <div class="form-text">Generated automatically using `OFF-YYYY-0001` format.</div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Office Name</label>
                                <input type="text" class="form-control" name="office_name" value="<?php echo h($form['office_name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Office Head</label>
                                <select class="form-select" name="office_head_employee_id" data-placeholder="Select employee">
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['office_head_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Unit head is now assigned from the Employees module.</div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label">Active office</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                                <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Office List</h5>
                        <span id="recordCount" class="text-muted small">Showing <?php echo count($offices); ?> of <?php echo count($offices); ?> records</span>
                    </div>
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i>Add New</button>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search offices..." style="max-width:300px;">
                    <select id="statusFilter" class="form-select form-select-sm" style="max-width:140px;">
                        <option value="">All statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle" id="dataTable">
                        <thead>
                            <tr>
                                <th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="head">Office Head <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="unit">Unit Head <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($offices): foreach ($offices as $office): ?>
                                <tr data-status="<?php echo (int) $office['is_active'] ? 'active' : 'inactive'; ?>">
                                    <td class="fw-semibold"><?php echo h($office['office_code']); ?></td>
                                    <td><div><?php echo h($office['office_name']); ?></div><small class="text-muted"><?php echo h($office['description'] ?? ''); ?></small></td>
                                    <td><?php echo h(trim(employee_display_name($office))); ?></td>
                                    <td><?php echo h($unitHeads[(int) $office['id']] ?? ''); ?></td>
                                    <td><span class="badge <?php echo (int) $office['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $office['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a href="<?php echo base_url('modules/offices/index.php?edit=' . (int) $office['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                            <?php if ((int) $office['is_active'] === 1): ?>
                                                <form method="post" onsubmit="return confirm('Deactivate this office?');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="hard_delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr data-status="inactive"><td colspan="6" class="text-center text-muted py-4">No offices found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex align-items-center gap-3 mt-2 flex-wrap">
                    <button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button>
                    <span id="pageInfo" class="small text-muted">Page 1 of 1</span>
                    <button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button>
                    <select id="perPageSelect" class="form-select form-select-sm" style="width:auto;">
                        <option value="25">25 per page</option>
                        <option value="50">50 per page</option>
                        <option value="100">100 per page</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
(function() {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('select[data-placeholder]').select2({ width: '100%' });
    }
    var perPage = 25, currentPage = 1, sortCol = -1, sortDir = 'asc';
    function getRows() { return Array.from(document.querySelectorAll('#dataTable tbody tr')); }
    function updateRecordCount(total, overall) {
        var node = document.getElementById('recordCount');
        if (node) node.textContent = 'Showing ' + total + ' of ' + overall + ' records';
    }
    function renderPage() {
        var allRows = getRows();
        var rows = allRows.filter(function(row) { return row.dataset.visible !== '0'; });
        var total = rows.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        currentPage = Math.min(currentPage, pages);
        var start = (currentPage - 1) * perPage;
        var end = start + perPage;
        allRows.forEach(function(row) { row.style.display = 'none'; });
        rows.slice(start, end).forEach(function(row) { row.style.display = ''; });
        updateRecordCount(total, allRows.length);
        document.getElementById('pageInfo').textContent = 'Page ' + currentPage + ' of ' + pages + ' (' + total + ' records)';
        document.getElementById('prevPage').disabled = currentPage <= 1;
        document.getElementById('nextPage').disabled = currentPage >= pages;
    }
    function applyFilters() {
        var term = ((document.getElementById('tableSearch') || {}).value || '').toLowerCase();
        var status = ((document.getElementById('statusFilter') || {}).value || '');
        getRows().forEach(function(row) {
            var textMatch = !term || row.textContent.toLowerCase().includes(term);
            var statusMatch = !status || row.dataset.status === status;
            row.dataset.visible = (textMatch && statusMatch) ? '1' : '0';
        });
        currentPage = 1;
        renderPage();
    }
    document.getElementById('tableSearch')?.addEventListener('input', applyFilters);
    document.getElementById('statusFilter')?.addEventListener('change', applyFilters);
    document.getElementById('prevPage')?.addEventListener('click', function() { currentPage--; renderPage(); });
    document.getElementById('nextPage')?.addEventListener('click', function() { currentPage++; renderPage(); });
    document.getElementById('perPageSelect')?.addEventListener('change', function() { perPage = parseInt(this.value, 10) || 25; currentPage = 1; renderPage(); });
    document.querySelectorAll('#dataTable th[data-sort]').forEach(function(th, idx) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            var tbody = document.querySelector('#dataTable tbody');
            var rows = Array.from(tbody.querySelectorAll('tr'));
            var dir = (sortCol === idx && sortDir === 'asc') ? 'desc' : 'asc';
            sortCol = idx;
            sortDir = dir;
            rows.sort(function(a, b) {
                var at = a.cells[idx] ? a.cells[idx].textContent.trim().toLowerCase() : '';
                var bt = b.cells[idx] ? b.cells[idx].textContent.trim().toLowerCase() : '';
                return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
            });
            rows.forEach(function(row) { tbody.appendChild(row); });
            document.querySelectorAll('#dataTable th[data-sort] i').forEach(function(icon) { icon.className = 'bi bi-arrow-down-up text-muted small'; });
            var icon = th.querySelector('i');
            if (icon) icon.className = 'bi bi-arrow-' + (dir === 'asc' ? 'up' : 'down') + ' text-primary small';
            renderPage();
        });
    });
    applyFilters();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
