<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
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

        if ($action === 'save') {
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
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Office updated successfully.');
                        redirect('modules/offices/index.php');
                    }
                } else {
                    $form['office_code'] = next_module_code($db, 'offices');
                    $stmt = $db->prepare("INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssisii', $form['office_code'], $form['office_name'], $officeHeadId, $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Office created successfully.');
                        redirect('modules/offices/index.php');
                    }
                }

                $errors[] = 'Unable to save the office.';
            }
        }

        if ($action === 'delete') {
            $officeId = (int) ($_POST['id'] ?? 0);
            if ($officeId > 0) {
                $userId = current_user_id();
                $stmt = $db->prepare("UPDATE offices SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('ii', $userId, $officeId);
                    $stmt->execute();
                    $stmt->close();
                    set_flash('success', 'Office deactivated successfully.');
                    redirect('modules/offices/index.php');
                }
            }

            $errors[] = 'Unable to deactivate the office.';
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
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Office' : 'Add Office'; ?></h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

                    <div class="mb-3">
                        <label for="office_code" class="form-label">Office Code</label>
                        <input type="text" class="form-control" id="office_code" name="office_code" value="<?php echo h($form['id'] > 0 ? $form['office_code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `OFF-YYYY-0001` format.</div>
                    </div>
                    <div class="mb-3">
                        <label for="office_name" class="form-label">Office Name</label>
                        <input type="text" class="form-control" id="office_name" name="office_name" value="<?php echo h($form['office_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="office_head_employee_id" class="form-label">Office Head</label>
                        <select class="form-select" id="office_head_employee_id" name="office_head_employee_id">
                            <option value="">Select employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['office_head_employee_id'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(employee_display_name($employee) . ' - ' . $employee['employee_no']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Unit head is now assigned from the Employees module.</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active office</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/offices/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Office List</h5>
                    <span class="badge text-bg-light"><?php echo count($offices); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Office</th>
                                <th>Office Head</th>
                                <th>Unit Head</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($offices): ?>
                                <?php foreach ($offices as $office): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($office['office_code']); ?></td>
                                        <td>
                                            <div><?php echo h($office['office_name']); ?></div>
                                            <small class="text-muted"><?php echo h($office['description'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo h(trim(employee_display_name($office))); ?></td>
                                        <td><?php echo h($unitHeads[(int) $office['id']] ?? ''); ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $office['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $office['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/offices/index.php?edit=' . (int) $office['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $office['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this office?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $office['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No offices found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
