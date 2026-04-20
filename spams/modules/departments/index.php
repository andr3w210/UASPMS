<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db();
$page_title = 'Departments';
$flash = get_flash();
$errors = [];
$departments = [];
$generatedCode = '';
$form = [
    'id' => 0,
    'code' => '',
    'name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database. Check your database settings in the configuration.';
} else {
    $generatedCode = preview_module_code($db, 'departments');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'code')) : $generatedCode;
            $form['name'] = old($_POST, 'name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['name'] === '') {
                $errors[] = 'Department name is required.';
            }

            $duplicateSql = "SELECT id FROM departments WHERE (code = ? OR name = ?) AND id != ? LIMIT 1";
            $duplicateStmt = $db->prepare($duplicateSql);
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('ssi', $form['code'], $form['name'], $form['id']);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Department code or name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $sql = "UPDATE departments
                            SET code = ?, name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW()
                            WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    if ($stmt) {
                        $isActive = (int) $form['is_active'];
                        $stmt->bind_param('sssiii', $form['code'], $form['name'], $form['description'], $isActive, $userId, $form['id']);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Department updated successfully.');
                        redirect('modules/departments/index.php');
                    }
                } else {
                    $form['code'] = next_module_code($db, 'departments');
                    $sql = "INSERT INTO departments (code, name, description, is_active, created_by)
                            VALUES (?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    if ($stmt) {
                        $isActive = (int) $form['is_active'];
                        $stmt->bind_param('sssii', $form['code'], $form['name'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Department created successfully.');
                        redirect('modules/departments/index.php');
                    }
                }

                $errors[] = 'Unable to save the department.';
            }
        }

        if ($action === 'delete') {
            $departmentId = (int) ($_POST['id'] ?? 0);
            if ($departmentId > 0) {
                $userId = current_user_id();
                $stmt = $db->prepare("UPDATE departments SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $departmentId);
                    $stmt->execute();
                    $stmt->close();
                    set_flash('success', 'Department deactivated successfully.');
                    redirect('modules/departments/index.php');
                }
            }

            $errors[] = 'Unable to deactivate the department.';
        }
    }

    if (isset($_GET['edit'])) {
        $departmentId = (int) $_GET['edit'];
        if ($departmentId > 0) {
            $stmt = $db->prepare("SELECT id, code, name, description, is_active FROM departments WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $departmentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $record = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if ($record) {
                    $form = [
                        'id' => (int) $record['id'],
                        'code' => $record['code'],
                        'name' => $record['name'],
                        'description' => $record['description'] ?? '',
                        'is_active' => (string) (int) $record['is_active'],
                    ];
                }
            }
        }
    }

    $sql = "SELECT d.id, d.code, d.name, d.description, d.is_active, d.created_at,
                   creator.full_name AS creator_name
            FROM departments d
            LEFT JOIN users creator ON creator.id = d.created_by
            ORDER BY d.name ASC";
    $result = $db->query($sql);
    if ($result) {
        $departments = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if (!empty($errors)): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Departments</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($departments); ?> of <?php echo count($departments); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/departments/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Department'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Department' : 'New Department'; ?></h5><div class="text-muted small">Maintain department records used across offices and transactions.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="master-data-form-layout"><div class="master-data-form-main"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Identity</div><h6 class="mb-1">Department Details</h6><div class="text-muted small">Use clear department names so offices and reports can be grouped consistently.</div></div></div><div class="master-data-panel-body"><div class="row g-3"><div class="col-md-4"><label for="code" class="form-label">Department Code</label><input type="text" class="form-control" id="code" name="code" maxlength="50" value="<?php echo h($form['id'] > 0 ? $form['code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using DEP-YYYY-0001 format.</div></div><div class="col-md-8"><label for="name" class="form-label">Department Name</label><input type="text" class="form-control" id="name" name="name" maxlength="150" value="<?php echo h($form['name']); ?>" required></div><div class="col-12"><label for="description" class="form-label">Description</label><textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea></div></div></div></div><div class="master-data-form-actions"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/departments/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Department' : 'Save Department'; ?></button></div></div><div class="master-data-form-side"><div class="master-data-panel"><div class="master-data-panel-header"><div><div class="master-data-panel-kicker">Status</div><h6 class="mb-1">Department Controls</h6></div></div><div class="master-data-panel-body"><div class="master-data-helper mb-3">Recommendation: keep departments broad and stable; use offices for more specific organizational units.</div><div class="master-data-side-list"><div class="master-data-side-item"><span>Record state</span><span class="badge <?php echo $form['is_active'] === '1' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $form['is_active'] === '1' ? 'Active' : 'Inactive'; ?></span></div></div><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="is_active">Active department</label></div></div></div></div></div></form></div></div>
<div class="master-data-toolbar mb-3"><div class="row g-3 align-items-end"><div class="col-lg-6"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search code, name, description, creator"></div><div class="col-sm-6 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div><div class="col-sm-6 col-lg-3"><label class="form-label">Rows Per Page</label><select id="perPageSelect" class="form-select"><option value="25" selected>25 rows</option><option value="50">50 rows</option><option value="100">100 rows</option><option value="250">250 rows</option></select></div></div></div>
<div class="master-data-table-shell"><div class="table-responsive mobile-table-frame master-data-table-scroll"><table class="table align-middle" id="dataTable"><thead><tr><th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="name">Department <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th><th data-sort="created">Created <i class="bi bi-arrow-down-up text-muted small"></i></th><th class="text-end">Actions</th></tr></thead><tbody><?php if (!empty($departments)): foreach ($departments as $department): ?><tr data-status="<?php echo (int) $department['is_active'] ? 'active' : 'inactive'; ?>"><td class="fw-semibold"><?php echo h($department['code']); ?></td><td><div class="fw-semibold"><?php echo h($department['name']); ?></div><?php if (!empty($department['description'])): ?><small class="text-muted"><?php echo h($department['description']); ?></small><?php else: ?><small class="text-muted">No description provided</small><?php endif; ?></td><td><span class="badge <?php echo (int) $department['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $department['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td><div><?php echo h(date('M d, Y', strtotime($department['created_at']))); ?></div><small class="text-muted"><?php echo h($department['creator_name'] ?: 'System'); ?></small></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/departments/index.php?edit=' . (int) $department['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $department['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this department?');" class="d-inline"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $department['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No departments found yet.</td></tr><?php endif; ?></tbody></table></div><div class="master-data-pagination"><div id="recordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div><div class="master-data-pagination-controls"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button></div></div></div>
</div></div></section>

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
        emptyMessage: 'No departments matched your search or status filter.'
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
