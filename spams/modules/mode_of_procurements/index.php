<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Mode of Procurement';
$flash = get_flash();
$errors = [];
$procurementModes = [];
$form = [
    'id' => 0,
    'mode_code' => '',
    'mode_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'mode_of_procurements');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['mode_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'mode_code')) : $generatedCode;
            $form['mode_name'] = old($_POST, 'mode_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['mode_name'] === '') {
                $errors[] = 'Mode of procurement name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM mode_of_procurements WHERE (mode_code = ? OR mode_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['mode_code'], $form['mode_name'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Code or mode name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE mode_of_procurements SET mode_code = ?, mode_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssiii', $form['mode_code'], $form['mode_name'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Mode of procurement updated successfully.');
                        redirect('modules/mode_of_procurements/index.php');
                    }
                } else {
                    $form['mode_code'] = next_module_code($db, 'mode_of_procurements');
                    $stmt = $db->prepare("INSERT INTO mode_of_procurements (mode_code, mode_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssii', $form['mode_code'], $form['mode_name'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Mode of procurement created successfully.');
                        redirect('modules/mode_of_procurements/index.php');
                    }
                }

                $errors[] = 'Unable to save the mode of procurement.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE mode_of_procurements SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Mode of procurement deactivated successfully.');
                redirect('modules/mode_of_procurements/index.php');
            }
            $errors[] = 'Unable to deactivate the mode of procurement.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, mode_code, mode_name, description, is_active FROM mode_of_procurements WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'mode_code' => $record['mode_code'],
                    'mode_name' => $record['mode_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT mop.id, mop.mode_code, mop.mode_name, mop.description, mop.is_active, mop.created_at,
               creator.full_name AS creator_name
        FROM mode_of_procurements mop
        LEFT JOIN users creator ON creator.id = mop.created_by
        ORDER BY mop.mode_name ASC
    ");
    if ($result) {
        $procurementModes = $result->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Mode of Procurement' : 'Add Mode of Procurement'; ?></h5>

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
                        <label for="mode_code" class="form-label">Mode Code</label>
                        <input type="text" class="form-control" id="mode_code" name="mode_code" value="<?php echo h($form['id'] > 0 ? $form['mode_code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `MOP-YYYY-0001` format.</div>
                    </div>

                    <div class="mb-3">
                        <label for="mode_name" class="form-label">Mode Name</label>
                        <input type="text" class="form-control" id="mode_name" name="mode_name" maxlength="150" value="<?php echo h($form['mode_name']); ?>" placeholder="Public Bidding, Shopping" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active mode of procurement</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/mode_of_procurements/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Mode of Procurement List</h5>
                    <span class="badge text-bg-light"><?php echo count($procurementModes); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Mode</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($procurementModes): ?>
                                <?php foreach ($procurementModes as $procurementMode): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($procurementMode['mode_code']); ?></td>
                                        <td>
                                            <div><?php echo h($procurementMode['mode_name']); ?></div>
                                            <small class="text-muted"><?php echo h($procurementMode['description'] ?? ''); ?></small>
                                        </td>
                                        <td><span class="badge rounded-pill <?php echo (int) $procurementMode['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $procurementMode['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td><div><?php echo h(date('M d, Y', strtotime($procurementMode['created_at']))); ?></div><small class="text-muted"><?php echo h($procurementMode['creator_name'] ?: 'System'); ?></small></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/mode_of_procurements/index.php?edit=' . (int) $procurementMode['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $procurementMode['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this mode of procurement?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $procurementMode['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No mode of procurements found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
