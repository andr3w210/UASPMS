<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Funds';
$flash = get_flash();
$errors = [];
$funds = [];
$form = [
    'id' => 0,
    'fund_code' => '',
    'fund_name' => '',
    'fund_source' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['fund_code'] = strtoupper(old($_POST, 'fund_code'));
            $form['fund_name'] = old($_POST, 'fund_name');
            $form['fund_source'] = old($_POST, 'fund_source');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['fund_code'] === '') {
                $errors[] = 'Fund code is required.';
            }
            if ($form['fund_name'] === '') {
                $errors[] = 'Fund name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM funds WHERE (fund_code = ? OR fund_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['fund_code'], $form['fund_name'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Fund code or fund name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE funds SET fund_code = ?, fund_name = ?, fund_source = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['fund_code'], $form['fund_name'], $form['fund_source'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Fund updated successfully.');
                        redirect('modules/funds/index.php');
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO funds (fund_code, fund_name, fund_source, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['fund_code'], $form['fund_name'], $form['fund_source'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Fund created successfully.');
                        redirect('modules/funds/index.php');
                    }
                }

                $errors[] = 'Unable to save the fund.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE funds SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Fund deactivated successfully.');
                redirect('modules/funds/index.php');
            }
            $errors[] = 'Unable to deactivate the fund.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, fund_code, fund_name, fund_source, description, is_active FROM funds WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'fund_code' => $record['fund_code'],
                    'fund_name' => $record['fund_name'],
                    'fund_source' => $record['fund_source'] ?? '',
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT f.id, f.fund_code, f.fund_name, f.fund_source, f.description, f.is_active, f.created_at,
               creator.full_name AS creator_name
        FROM funds f
        LEFT JOIN users creator ON creator.id = f.created_by
        ORDER BY f.fund_name ASC
    ");
    if ($result) {
        $funds = $result->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Fund' : 'Add Fund'; ?></h5>

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
                        <label for="fund_code" class="form-label">Fund Code</label>
                        <input type="text" class="form-control" id="fund_code" name="fund_code" maxlength="50" value="<?php echo h($form['fund_code']); ?>" placeholder="GAA-GAS, TICT, IGP" required>
                        <div class="form-text">Use the official fund code from your fund list.</div>
                    </div>

                    <div class="mb-3">
                        <label for="fund_name" class="form-label">Fund Name</label>
                        <input type="text" class="form-control" id="fund_name" name="fund_name" maxlength="150" value="<?php echo h($form['fund_name']); ?>" placeholder="General Fund, Trust Fund" required>
                    </div>

                    <div class="mb-3">
                        <label for="fund_source" class="form-label">Fund Source</label>
                        <input type="text" class="form-control" id="fund_source" name="fund_source" maxlength="150" value="<?php echo h($form['fund_source']); ?>" placeholder="Government Appropriations, Income, Grants">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active fund</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/funds/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Fund List</h5>
                    <span class="badge text-bg-light"><?php echo count($funds); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Fund</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($funds): ?>
                                <?php foreach ($funds as $fund): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($fund['fund_code']); ?></td>
                                        <td>
                                            <div><?php echo h($fund['fund_name']); ?></div>
                                            <small class="text-muted"><?php echo h($fund['description'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo h($fund['fund_source'] ?? ''); ?></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $fund['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $fund['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td><div><?php echo h(date('M d, Y', strtotime($fund['created_at']))); ?></div><small class="text-muted"><?php echo h($fund['creator_name'] ?: 'System'); ?></small></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/funds/index.php?edit=' . (int) $fund['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $fund['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this fund?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $fund['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No funds found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
