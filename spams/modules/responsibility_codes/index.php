<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

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
    $generatedCode = '';
    $officeResult = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
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
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
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
        }

        if ($action === 'delete') {
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
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, office_id, code, description, is_active FROM responsibility_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
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
        INNER JOIN offices o ON o.id = rc.office_id
        ORDER BY o.office_name ASC, rc.code ASC
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
    <div class="col-xl-4">
        <div class="card">
            <div class="card-body p-4">
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Responsibility Code' : 'Add Responsibility Code'; ?></h5>
                <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                    <div class="mb-3">
                        <label for="office_id" class="form-label">Office</label>
                        <select class="form-select" id="office_id" name="office_id" required>
                            <option value="">Select office</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="code" class="form-label">Responsibility Code</label>
                        <input type="text" class="form-control" id="code" name="code" value="<?php echo h($form['id'] > 0 ? $form['code'] : ($form['code'] ?: 'Select an office to generate a code')); ?>" readonly>
                        <div class="form-text">Generated automatically using `RSP-{OFFICECODE}-0001` format.</div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active code</label>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/responsibility_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Responsibility Code List</h5>
                    <span class="badge text-bg-light"><?php echo count($codes); ?> record(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Office</th><th>Code</th><th>Description</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php if ($codes): ?>
                                <?php foreach ($codes as $code): ?>
                                    <tr>
                                        <td><?php echo h($code['office_name'] . ' (' . $code['office_code'] . ')'); ?></td>
                                        <td class="fw-semibold"><?php echo h($code['code']); ?></td>
                                        <td><?php echo h($code['description'] ?? ''); ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $code['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $code['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/responsibility_codes/index.php?edit=' . (int) $code['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $code['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this responsibility code?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $code['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No responsibility codes found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
