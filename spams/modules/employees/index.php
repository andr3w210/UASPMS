<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_login();
require_role('Administrator', 'Transport Officer');

$db = db();
$page_title = 'Employees';
$errors = [];
$flash = get_flash();

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'toggle_active' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
            'make_active' => ['type' => 'int', 'min' => 0, 'max' => 1, 'required' => true],
        ],
    ], 'action', false, 'toggle_active');
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $recordId = (int) $_POST['id'];
        $makeActive = (int) $_POST['make_active'];
        $before = employees_audit_snapshot($db, $recordId);
        $stmt = $db->prepare("UPDATE employees SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
        $userId = current_user_id();
        if ($before && $stmt) {
            $stmt->bind_param('iii', $makeActive, $userId, $recordId);
            $stmt->execute();
            $stmt->close();
            write_audit_log($db, [
                'action' => 'update',
                'table_name' => 'employees',
                'record_id' => $recordId,
                'module_name' => 'employees',
                'record_type' => 'employee',
                'action_name' => $makeActive ? 'reactivate_employee' : 'deactivate_employee',
                'description' => $makeActive ? 'Reactivated employee record.' : 'Deactivated employee record.',
                'old_values' => $before,
                'new_values' => ['is_active' => $makeActive],
            ]);
            set_flash('success', $makeActive ? 'Employee reactivated.' : 'Employee deactivated.');
        } else {
            set_flash('error', 'Employee not found.');
        }
        redirect('modules/employees/index.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$officeFilter = (int) ($_GET['office_id'] ?? 0);
$designationFilter = trim((string) ($_GET['designation'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'active');
$statusFilter = in_array($statusFilter, ['active', 'inactive', 'all'], true) ? $statusFilter : 'active';
$flagFilter = (string) ($_GET['flag'] ?? '');
$flagFilter = in_array($flagFilter, ['driver', 'unit_head'], true) ? $flagFilter : '';
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$offices = [];
$designations = [];
$employees = [];
$total = 0;
$employeeUserMap = [];

if ($db) {
    $offices = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC")->fetch_all(MYSQLI_ASSOC);
    $designations = array_column($db->query("SELECT DISTINCT role_title FROM employee_assignments WHERE is_active = 1 AND role_title <> '' ORDER BY role_title ASC")->fetch_all(MYSQLI_ASSOC), 'role_title');

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($statusFilter === 'active') {
        $where[] = 'e.is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $where[] = 'e.is_active = 0';
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(e.employee_no LIKE ? OR e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.email LIKE ? OR e.contact_no LIKE ? OR e.position_title LIKE ?)";
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($officeFilter > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments a WHERE a.employee_id = e.id AND a.is_active = 1 AND a.office_id = ?)';
        $types .= 'i';
        $params[] = $officeFilter;
    }
    if ($designationFilter !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments a WHERE a.employee_id = e.id AND a.is_active = 1 AND a.role_title = ?)';
        $types .= 's';
        $params[] = $designationFilter;
    }
    if ($flagFilter === 'driver') {
        $where[] = 'e.is_driver = 1';
    } elseif ($flagFilter === 'unit_head') {
        $where[] = 'EXISTS (SELECT 1 FROM employee_assignments a WHERE a.employee_id = e.id AND a.is_active = 1 AND a.is_unit_head = 1)';
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM employees e WHERE {$whereSql}");
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT e.id, e.employee_no, e.name_prefix, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.email, e.contact_no, e.photo_path, e.employment_status, e.is_driver, e.is_active,
            (SELECT COUNT(*) FROM employee_assignments a WHERE a.employee_id = e.id AND a.is_active = 1) AS designation_count
        FROM employees e WHERE {$whereSql} ORDER BY e.last_name ASC, e.first_name ASC LIMIT ? OFFSET ?");
    $listTypes = $types . 'ii';
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($employees as &$employee) {
        $employee['assignments'] = employee_fetch_assignments($db, (int) $employee['id'], true);
    }
    unset($employee);

    $userResult = $db->query("SELECT employee_id, username FROM users WHERE employee_id IS NOT NULL");
    foreach ($userResult->fetch_all(MYSQLI_ASSOC) as $userRow) {
        $employeeUserMap[(int) $userRow['employee_id']] = (string) $userRow['username'];
    }
}
$pages = $pages ?? 1;

$queryBase = array_filter(['q' => $q, 'office_id' => $officeFilter ?: '', 'designation' => $designationFilter, 'status' => $statusFilter, 'flag' => $flagFilter, 'per_page' => $perPage], static fn($v) => $v !== '');
$isAdministrator = current_user_role() === 'Administrator';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Employees</h4>
            <div class="text-muted"><?php echo (int) $total; ?> employee<?php echo $total === 1 ? '' : 's'; ?> found</div>
        </div>
        <a href="<?php echo base_url('modules/employees/form.php'); ?>" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i> Add Employee</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type'] === 'error' ? 'danger' : $flash['type']); ?>"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="get" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label class="form-label small mb-1">Search</label>
                    <input type="search" name="q" class="form-control" placeholder="Name, no., email, contact" value="<?php echo h($q); ?>">
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small mb-1">Office</label>
                    <select name="office_id" class="form-select">
                        <option value="">All offices</option>
                        <?php foreach ($offices as $office): ?>
                            <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeFilter === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small mb-1">Designation</label>
                    <select name="designation" class="form-select">
                        <option value="">All designations</option>
                        <?php foreach ($designations as $title): ?>
                            <option value="<?php echo h($title); ?>" <?php echo $designationFilter === $title ? 'selected' : ''; ?>><?php echo h($title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-lg-2 col-md-3 col-6">
                    <label class="form-label small mb-1">Role</label>
                    <select name="flag" class="form-select">
                        <option value="">Any</option>
                        <option value="driver" <?php echo $flagFilter === 'driver' ? 'selected' : ''; ?>>Drivers</option>
                        <option value="unit_head" <?php echo $flagFilter === 'unit_head' ? 'selected' : ''; ?>>Unit heads</option>
                    </select>
                </div>
                <div class="col-lg-1 col-12 d-flex gap-1">
                    <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i></button>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/employees/index.php'); ?>" title="Reset"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Designation</th>
                        <th>Office</th>
                        <th>Contact</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No employees match the filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($employees as $employee):
                    $primary = $employee['assignments'][0] ?? [];
                    $extra = max(0, (int) $employee['designation_count'] - 1);
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php echo employees_avatar_html($employee, 40); ?>
                                <div>
                                    <a class="fw-semibold text-reset" href="<?php echo base_url('modules/employees/view.php?id=' . (int) $employee['id']); ?>"><?php echo h(employee_display_name($employee)); ?></a>
                                    <div class="text-muted small"><?php echo h($employee['employee_no']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($primary): ?>
                                <div><?php echo h(employee_format_title_with_oic((string) $primary['role_title'], !empty($primary['is_oic']))); ?></div>
                                <?php if ($extra > 0): ?><span class="badge bg-blue-lt">+<?php echo $extra; ?> more</span><?php endif; ?>
                                <?php if (!empty($primary['is_unit_head'])): ?><span class="badge bg-green-lt">Unit Head</span><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">No designation</span>
                            <?php endif; ?>
                            <?php if (!empty($employee['is_driver'])): ?><span class="badge bg-azure-lt">Driver</span><?php endif; ?>
                        </td>
                        <td><?php echo $primary ? h($primary['office_name']) : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <div class="small"><?php echo h($employee['contact_no'] ?: '—'); ?></div>
                            <div class="text-muted small"><?php echo h($employee['email'] ?: ''); ?></div>
                        </td>
                        <td>
                            <span class="badge <?php echo $employee['is_active'] ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $employee['is_active'] ? 'Active' : 'Inactive'; ?></span>
                            <?php if ($employee['employment_status'] !== ''): ?><div class="text-muted small"><?php echo h($employee['employment_status']); ?></div><?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/employees/view.php?id=' . (int) $employee['id']); ?>"><i class="bi bi-person-lines-fill me-2"></i>View Profile</a></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/employees/form.php?id=' . (int) $employee['id']); ?>"><i class="bi bi-pencil-square me-2"></i>Edit</a></li>
                                    <li>
                                        <form method="post" class="m-0" onsubmit="return confirm('<?php echo $employee['is_active'] ? 'Deactivate' : 'Reactivate'; ?> this employee?');">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?php echo (int) $employee['id']; ?>">
                                            <input type="hidden" name="make_active" value="<?php echo $employee['is_active'] ? 0 : 1; ?>">
                                            <button type="submit" class="dropdown-item"><i class="bi bi-<?php echo $employee['is_active'] ? 'person-dash' : 'person-check'; ?> me-2"></i><?php echo $employee['is_active'] ? 'Deactivate' : 'Reactivate'; ?></button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div class="text-muted small">Page <?php echo $page; ?> of <?php echo $pages; ?></div>
                <ul class="pagination m-0">
                    <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo h(http_build_query($queryBase + ['page' => $p])); ?>"><?php echo $p; ?></a></li>
                    <?php endfor; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
