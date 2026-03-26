<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Unit of Measure';
$flash = get_flash();
$errors = [];
$units = [];
$form = [
    'id' => 0,
    'uom_code' => '',
    'uom_name' => '',
    'abbreviation' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'unit_of_measures');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['uom_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'uom_code')) : $generatedCode;
            $form['uom_name'] = old($_POST, 'uom_name');
            $form['abbreviation'] = strtolower(old($_POST, 'abbreviation'));
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['uom_name'] === '') {
                $errors[] = 'Unit name is required.';
            }

            if ($form['abbreviation'] === '') {
                $errors[] = 'Abbreviation is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM unit_of_measures WHERE (uom_code = ? OR uom_name = ? OR abbreviation = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('sssi', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Code, unit name, or abbreviation already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE unit_of_measures SET uom_code = ?, uom_name = ?, abbreviation = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Unit of measure updated successfully.');
                        redirect('modules/unit_of_measures/index.php');
                    }
                } else {
                    $form['uom_code'] = next_module_code($db, 'unit_of_measures');
                    $stmt = $db->prepare("INSERT INTO unit_of_measures (uom_code, uom_name, abbreviation, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['uom_code'], $form['uom_name'], $form['abbreviation'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Unit of measure created successfully.');
                        redirect('modules/unit_of_measures/index.php');
                    }
                }

                $errors[] = 'Unable to save the unit of measure.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE unit_of_measures SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Unit of measure deactivated successfully.');
                redirect('modules/unit_of_measures/index.php');
            }
            $errors[] = 'Unable to deactivate the unit of measure.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, uom_code, uom_name, abbreviation, description, is_active FROM unit_of_measures WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'uom_code' => $record['uom_code'],
                    'uom_name' => $record['uom_name'],
                    'abbreviation' => $record['abbreviation'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT u.id, u.uom_code, u.uom_name, u.abbreviation, u.description, u.is_active, u.created_at,
               creator.full_name AS creator_name
        FROM unit_of_measures u
        LEFT JOIN users creator ON creator.id = u.created_by
        ORDER BY u.uom_name ASC
    ");
    if ($result) {
        $units = $result->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Unit of Measure' : 'Add Unit of Measure'; ?></h5>

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
                        <label for="uom_code" class="form-label">UOM Code</label>
                        <input type="text" class="form-control" id="uom_code" name="uom_code" value="<?php echo h($form['id'] > 0 ? $form['uom_code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `UOM-YYYY-0001` format.</div>
                    </div>

                    <div class="mb-3">
                        <label for="uom_name" class="form-label">Unit Name</label>
                        <input type="text" class="form-control" id="uom_name" name="uom_name" maxlength="100" value="<?php echo h($form['uom_name']); ?>" placeholder="Piece, Set, Box" required>
                    </div>

                    <div class="mb-3">
                        <label for="abbreviation" class="form-label">Abbreviation</label>
                        <input type="text" class="form-control" id="abbreviation" name="abbreviation" maxlength="20" value="<?php echo h($form['abbreviation']); ?>" placeholder="pcs, set, box" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active unit of measure</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/unit_of_measures/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Unit of Measure List</h5>
                    <span class="badge text-bg-light"><?php echo count($units); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Unit</th>
                                <th>Abbreviation</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($units): ?>
                                <?php foreach ($units as $unit): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($unit['uom_code']); ?></td>
                                        <td>
                                            <div><?php echo h($unit['uom_name']); ?></div>
                                            <small class="text-muted"><?php echo h($unit['description'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo h($unit['abbreviation']); ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $unit['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $unit['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td><div><?php echo h(date('M d, Y', strtotime($unit['created_at']))); ?></div><small class="text-muted"><?php echo h($unit['creator_name'] ?: 'System'); ?></small></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/unit_of_measures/index.php?edit=' . (int) $unit['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $unit['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this unit of measure?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $unit['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No unit of measures found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
