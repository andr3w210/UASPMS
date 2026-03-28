<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db();
$page_title = 'Departments';
$flash = get_flash();
$errors = [];
$departments = [];
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
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Department' : 'Add Department'; ?></h5>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo h($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>">
                        <?php echo h($flash['message']); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

                    <div class="mb-3">
                        <label for="code" class="form-label">Department Code</label>
                        <input type="text" class="form-control" id="code" name="code" maxlength="50" value="<?php echo h($form['id'] > 0 ? $form['code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `DEP-YYYY-0001` format.</div>
                    </div>

                    <div class="mb-3">
                        <label for="name" class="form-label">Department Name</label>
                        <input type="text" class="form-control" id="name" name="name" maxlength="150" value="<?php echo h($form['name']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active department</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?>
                            <a href="<?php echo base_url('modules/departments/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Department List</h5>
                    <span class="badge text-bg-light"><?php echo count($departments); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($departments)): ?>
                                <?php foreach ($departments as $department): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($department['code']); ?></td>
                                        <td><?php echo h($department['name']); ?></td>
                                        <td><?php echo h($department['description'] ?? ''); ?></td>
                                        <td>
                                            <span class="badge rounded-pill <?php echo (int) $department['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>">
                                                <?php echo (int) $department['is_active'] === 1 ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div><?php echo h(date('M d, Y', strtotime($department['created_at']))); ?></div>
                                            <small class="text-muted"><?php echo h($department['creator_name'] ?: 'System'); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/departments/index.php?edit=' . (int) $department['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $department['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this department?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $department['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No departments found yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
