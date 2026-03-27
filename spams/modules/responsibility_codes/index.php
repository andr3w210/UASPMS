<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function responsibility_codes_has_reference(mysqli $db, int $recordId): bool
{
    $code = '';
    $codeStmt = $db->prepare("SELECT code FROM responsibility_codes WHERE id = ? LIMIT 1");
    if ($codeStmt) {
        $codeStmt->bind_param('i', $recordId);
        $codeStmt->execute();
        $row = $codeStmt->get_result()->fetch_assoc();
        $codeStmt->close();
        $code = (string) ($row['code'] ?? '');
    }

    $checks = [];
    if ($code !== '') {
        $checks[] = ["SELECT 1 FROM distribution_item_details WHERE property_number LIKE CONCAT(?, '%') LIMIT 1", 's', $code];
    }
    $checks[] = ["SELECT 1 FROM employees WHERE responsibility_code_id = ? LIMIT 1", 'i', $recordId];

    foreach ($checks as $check) {
        [$sql, $type, $value] = $check;
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($type, $value);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }

    return false;
}

$db = db_connect();
$page_title = 'Responsibility Codes';
$flash = get_flash();
$errors = [];
$codes = [];
$offices = [];
$form = [
    'id' => 0,
    'office_id' => '',
    'code' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $officeResult = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['office_id'] = old($_POST, 'office_id');
            $form['code'] = strtoupper(old($_POST, 'code'));
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['office_id'] === '') {
                $errors[] = 'Office is required.';
            }
            $officeId = (int) ($form['office_id'] ?: 0);
            $recordId = (int) $form['id'];
            $officeCode = '';
            if ($officeId > 0) {
                foreach ($offices as $office) {
                    if ((int) $office['id'] === $officeId) {
                        $officeCode = $office['office_code'];
                        break;
                    }
                }
            }
            if ($recordId === 0 && $officeCode === '') {
                $errors[] = 'Office is required.';
            }
            if ($recordId === 0 && $officeCode !== '') {
                $form['code'] = preview_module_code($db, 'responsibility_codes_' . $officeCode, 'RSP-' . $officeCode, null);
            }

            $duplicateStmt = $db->prepare("SELECT id FROM responsibility_codes WHERE office_id = ? AND code = ? AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('isi', $officeId, $form['code'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'This responsibility code already exists for the selected office.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($recordId > 0) {
                    $stmt = $db->prepare("UPDATE responsibility_codes SET office_id = ?, code = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param('issiii', $officeId, $form['code'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Responsibility code updated successfully.');
                        redirect('modules/responsibility_codes/index.php');
                    }
                } else {
                    $form['code'] = next_module_code($db, 'responsibility_codes_' . $officeCode);
                    $stmt = $db->prepare("INSERT INTO responsibility_codes (office_id, code, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('issii', $officeId, $form['code'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Responsibility code created successfully.');
                        redirect('modules/responsibility_codes/index.php');
                    }
                }

                $errors[] = 'Unable to save the responsibility code.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE responsibility_codes SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Responsibility code deactivated successfully.');
                redirect('modules/responsibility_codes/index.php');
            }
            $errors[] = 'Unable to deactivate the responsibility code.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/responsibility_codes/index.php');
            }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (responsibility_codes_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/responsibility_codes/index.php');
            }
            $stmt = $db->prepare("DELETE FROM responsibility_codes WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Record permanently deleted.');
                redirect('modules/responsibility_codes/index.php');
            }
            $errors[] = 'Unable to permanently delete the responsibility code.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, office_id, code, description, is_active FROM responsibility_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'office_id' => (string) $record['office_id'],
                    'code' => $record['code'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $listResult = $db->query("
        SELECT rc.id, rc.code, rc.description, rc.is_active, o.office_name, o.office_code
        FROM responsibility_codes rc
        LEFT JOIN offices o ON o.id = rc.office_id
        ORDER BY COALESCE(o.office_name, 'ZZZ Unassigned'), rc.code ASC
    ");
    if ($listResult) {
        $codes = $listResult->fetch_all(MYSQLI_ASSOC);
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
                    <h5 class="card-title mb-0"><?php echo $form['id'] > 0 ? 'Edit Responsibility Code' : 'Add New Responsibility Code'; ?></h5>
                    <div class="text-muted small">Generate and manage office-specific responsibility codes.</div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Edit Code' : 'Add New'; ?>
                    </button>
                    <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/responsibility_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
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
                            <div class="col-md-6">
                                <label class="form-label">Office</label>
                                <select class="form-select" name="office_id" required data-placeholder="Select office">
                                    <option value="">Select office</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Responsibility Code</label>
                                <input type="text" class="form-control" name="code" value="<?php echo h($form['id'] > 0 ? $form['code'] : ($form['code'] ?: 'Select an office to generate a code')); ?>" readonly>
                                <div class="form-text">Generated automatically using `RSP-{OFFICECODE}-0001` format.</div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label">Active code</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                                <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/responsibility_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
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
                        <h5 class="card-title mb-0">Responsibility Code List</h5>
                        <span id="recordCount" class="text-muted small">Showing <?php echo count($codes); ?> of <?php echo count($codes); ?> records</span>
                    </div>
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse"><i class="bi bi-plus-circle me-1"></i>Add New</button>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search responsibility codes..." style="max-width:300px;">
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
                                <th data-sort="office">Office <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="description">Description <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($codes): foreach ($codes as $code): ?>
                                <tr data-status="<?php echo (int) $code['is_active'] ? 'active' : 'inactive'; ?>">
                                    <td>
                                        <?php if (!empty($code['office_name'])): ?>
                                            <?php echo h($code['office_name'] . ' (' . $code['office_code'] . ')'); ?>
                                        <?php else: ?>
                                            <span class="text-warning">Unassigned Office</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold"><?php echo h($code['code']); ?></td>
                                    <td><?php echo h($code['description'] ?? ''); ?></td>
                                    <td><span class="badge <?php echo (int) $code['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $code['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a href="<?php echo base_url('modules/responsibility_codes/index.php?edit=' . (int) $code['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                            <?php if ((int) $code['is_active'] === 1): ?>
                                                <form method="post" onsubmit="return confirm('Deactivate this responsibility code?');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $code['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?>
                                                <form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="hard_delete">
                                                    <input type="hidden" name="id" value="<?php echo (int) $code['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No responsibility codes found yet.</td></tr>
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
        var allRows = getRows(), rows = allRows.filter(function(row) { return row.dataset.visible !== '0'; });
        var total = rows.length, pages = Math.max(1, Math.ceil(total / perPage));
        currentPage = Math.min(currentPage, pages);
        var start = (currentPage - 1) * perPage, end = start + perPage;
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
