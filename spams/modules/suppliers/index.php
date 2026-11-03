<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_once __DIR__ . '/../../app/helpers/employees.php';
require_once __DIR__ . '/../../app/helpers/suppliers.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Suppliers';
$errors = [];
$flash = get_flash();
$isAdministrator = current_user_role() === 'Administrator';

if ($db && $_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'toggle_active' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
            'make_active' => ['type' => 'int', 'min' => 0, 'max' => 1, 'required' => true],
        ],
        'hard_delete' => [
            'id' => ['type' => 'int', 'min' => 1, 'required' => true],
        ],
    ], 'action', false, 'toggle_active');
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } elseif (($_POST['action'] ?? '') === 'hard_delete') {
        $recordId = (int) $_POST['id'];
        if (!$isAdministrator) {
            set_flash('error', 'Only administrators can permanently delete records.');
        } elseif (suppliers_has_reference($db, $recordId)) {
            set_flash('error', 'Cannot delete: supplier is used in existing purchase orders. Deactivate it instead.');
        } elseif (suppliers_hard_delete($db, $recordId)) {
            set_flash('success', 'Supplier permanently deleted.');
        } else {
            set_flash('error', 'Unable to delete the supplier.');
        }
        redirect('modules/suppliers/index.php');
    } else {
        $makeActive = (int) $_POST['make_active'];
        $ok = suppliers_set_active($db, (int) $_POST['id'], $makeActive);
        set_flash($ok ? 'success' : 'error', $ok ? ($makeActive ? 'Supplier reactivated.' : 'Supplier deactivated.') : 'Unable to update the supplier.');
        redirect('modules/suppliers/index.php');
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'active');
$statusFilter = in_array($statusFilter, ['active', 'inactive', 'all'], true) ? $statusFilter : 'active';
$perPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$suppliers = [];
$total = 0;
$pages = 1;

if ($db) {
    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($statusFilter === 'active') {
        $where[] = 's.is_active = 1';
    } elseif ($statusFilter === 'inactive') {
        $where[] = 's.is_active = 0';
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = '(s.supplier_code LIKE ? OR s.supplier_name LIKE ? OR s.contact_person LIKE ? OR s.contact_no LIKE ? OR s.email LIKE ? OR s.address LIKE ? OR s.tin_no LIKE ?)';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM suppliers s WHERE {$whereSql}");
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT s.id, s.supplier_code, s.supplier_name, s.contact_person, s.contact_no, s.email, s.address, s.tin_no, s.is_active,
            (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.id) AS po_count
        FROM suppliers s WHERE {$whereSql} ORDER BY s.supplier_name ASC LIMIT ? OFFSET ?");
    $listParams = array_merge($params, [$perPage, $offset]);
    $stmt->bind_param($types . 'ii', ...$listParams);
    $stmt->execute();
    $suppliers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$queryBase = array_filter(['q' => $q, 'status' => $statusFilter, 'per_page' => $perPage], static fn($v) => $v !== '');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="mb-1">Suppliers</h4>
            <div class="text-muted"><?php echo (int) $total; ?> supplier<?php echo $total === 1 ? '' : 's'; ?> found</div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('modules/suppliers/monitoring_calendar.php'); ?>" class="btn btn-outline-primary"><i class="bi bi-calendar3 me-1"></i>Delivery Calendar</a>
            <a href="<?php echo base_url('modules/suppliers/form.php'); ?>" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add Supplier</a>
        </div>
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
                <div class="col-lg-6 col-md-12">
                    <label class="form-label small mb-1">Search</label>
                    <input type="search" name="q" class="form-control" placeholder="Code, name, contact, email, TIN, address" value="<?php echo h($q); ?>">
                </div>
                <div class="col-lg-3 col-6">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-lg-2 col-6">
                    <label class="form-label small mb-1">Rows</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ([10, 25, 50, 100] as $n): ?>
                            <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-1 col-12 d-flex gap-1">
                    <button class="btn btn-primary flex-fill" type="submit"><i class="bi bi-funnel"></i></button>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/suppliers/index.php'); ?>" title="Reset"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" data-no-table-search="true">
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>TIN</th>
                        <th class="text-center">POs</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$suppliers): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No suppliers match the filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($suppliers as $supplier): $sid = (int) $supplier['id']; ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php echo employees_avatar_html(['first_name' => $supplier['supplier_name'], 'last_name' => '', 'photo_path' => ''], 40); ?>
                                <div>
                                    <a class="fw-semibold text-reset" href="<?php echo base_url('modules/suppliers/view.php?id=' . $sid); ?>"><?php echo h($supplier['supplier_name']); ?></a>
                                    <div class="text-muted small"><?php echo h($supplier['supplier_code']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="small"><?php echo h($supplier['contact_person'] ?: '—'); ?></div>
                            <div class="text-muted small"><?php echo h(trim(($supplier['contact_no'] ?? '') . (($supplier['contact_no'] ?? '') && ($supplier['email'] ?? '') ? ' | ' : '') . ($supplier['email'] ?? ''))); ?></div>
                        </td>
                        <td><?php echo h($supplier['tin_no'] ?: '—'); ?></td>
                        <td class="text-center"><?php echo (int) $supplier['po_count']; ?></td>
                        <td><span class="badge <?php echo $supplier['is_active'] ? 'bg-success-lt' : 'bg-secondary-lt'; ?>"><?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?></span></td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/suppliers/view.php?id=' . $sid); ?>"><i class="bi bi-building me-2"></i>View Profile</a></li>
                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/suppliers/form.php?id=' . $sid); ?>"><i class="bi bi-pencil-square me-2"></i>Edit</a></li>
                                    <li>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                            <input type="hidden" name="make_active" value="<?php echo $supplier['is_active'] ? 0 : 1; ?>">
                                            <button type="submit" class="dropdown-item"><i class="bi bi-<?php echo $supplier['is_active'] ? 'slash-circle' : 'arrow-counterclockwise'; ?> me-2"></i><?php echo $supplier['is_active'] ? 'Deactivate' : 'Reactivate'; ?></button>
                                        </form>
                                    </li>
                                    <?php if ($isAdministrator): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="post" class="m-0" onsubmit="return confirm('Permanently delete this supplier? This cannot be undone.');">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="hard_delete">
                                                <input type="hidden" name="id" value="<?php echo $sid; ?>">
                                                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash3 me-2"></i>Delete permanently</button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
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
