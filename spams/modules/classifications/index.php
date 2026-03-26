<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Inventory Classes';
$flash = get_flash();
$errors = [];
$classifications = [];
$accountCodes = [];
$form = [
    'id' => 0,
    'classification_code' => '',
    'classification_name' => '',
    'useful_life_years' => '',
    'classification_group' => 'asset',
    'account_code_id' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'classifications');
    $accountCodeResult = $db->query("SELECT id, account_code, account_name, account_group FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC");
    if ($accountCodeResult) {
        $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['classification_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'classification_code')) : $generatedCode;
            $form['classification_name'] = old($_POST, 'classification_name');
            $form['useful_life_years'] = old($_POST, 'useful_life_years');
            $form['classification_group'] = old($_POST, 'classification_group', 'asset');
            $form['account_code_id'] = old($_POST, 'account_code_id');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['classification_name'] === '') {
                $errors[] = 'Classification name is required.';
            }

            if (!in_array($form['classification_group'], ['supply', 'asset', 'semi_expendable'], true)) {
                $errors[] = 'Invalid classification group.';
            }
            $selectedAccountCode = null;
            foreach ($accountCodes as $accountCode) {
                if ((string) $accountCode['id'] === $form['account_code_id']) {
                    $selectedAccountCode = $accountCode;
                    break;
                }
            }

            if ($form['account_code_id'] !== '' && !$selectedAccountCode) {
                $errors[] = 'Selected account code is invalid.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM classifications WHERE (classification_code = ? OR classification_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['classification_code'], $form['classification_name'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Classification code or name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();
                $accountCodeId = $form['account_code_id'] !== '' ? (int) $form['account_code_id'] : null;

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE classifications SET classification_code = ?, classification_name = ?, useful_life_years = NULLIF(?,0), classification_group = ?, account_code_id = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssisisiii', $form['classification_code'], $form['classification_name'], $form['useful_life_years'], $form['classification_group'], $accountCodeId, $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Classification updated successfully.');
                        redirect('modules/classifications/index.php');
                    }
                } else {
                    $form['classification_code'] = next_module_code($db, 'classifications');
                    $stmt = $db->prepare("INSERT INTO classifications (classification_code, classification_name, useful_life_years, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, NULLIF(?,0), ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssisisii', $form['classification_code'], $form['classification_name'], $form['useful_life_years'], $form['classification_group'], $accountCodeId, $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Classification created successfully.');
                        redirect('modules/classifications/index.php');
                    }
                }

                $errors[] = 'Unable to save the classification.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE classifications SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Classification deactivated successfully.');
                redirect('modules/classifications/index.php');
            }
            $errors[] = 'Unable to deactivate the classification.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, classification_code, classification_name, useful_life_years, classification_group, account_code_id, description, is_active FROM classifications WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'classification_code' => $record['classification_code'],
                    'classification_name' => $record['classification_name'],
                    'useful_life_years' => (string) ($record['useful_life_years'] ?? ''),
                    'classification_group' => $record['classification_group'],
                    'account_code_id' => (string) ($record['account_code_id'] ?? ''),
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query(" 
        SELECT c.id, c.classification_code, c.classification_name, c.useful_life_years, c.classification_group, c.account_code_id, c.description, c.is_active, c.created_at,
               ac.account_code, ac.account_name,
               creator.full_name AS creator_name
        FROM classifications c
        LEFT JOIN account_codes ac ON ac.id = c.account_code_id
        LEFT JOIN users creator ON creator.id = c.created_by
        ORDER BY c.classification_name ASC
    ");
    if ($result) {
        $classifications = $result->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Inventory Class' : 'Add Inventory Class'; ?></h5>

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
                        <label for="classification_code" class="form-label">Inventory Class Code</label>
                        <input type="text" class="form-control" id="classification_code" name="classification_code" value="<?php echo h($form['id'] > 0 ? $form['classification_code'] : $generatedCode); ?>" readonly>
                        <div class="form-text">Generated automatically using `CLS-YYYY-0001` format.</div>
                    </div>

                    <div class="mb-3">
                        <label for="classification_name" class="form-label">Inventory Class Name</label>
                        <input type="text" class="form-control" id="classification_name" name="classification_name" maxlength="150" value="<?php echo h($form['classification_name']); ?>" placeholder="Desktop Computer, Projector" required>
                    </div>

                    <div class="mb-3">
                        <label for="classification_group" class="form-label">Inventory Class Group</label>
                        <select class="form-select" id="classification_group" name="classification_group">
                            <option value="asset" <?php echo $form['classification_group'] === 'asset' ? 'selected' : ''; ?>>Asset</option>
                            <option value="semi_expendable" <?php echo $form['classification_group'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            <option value="supply" <?php echo $form['classification_group'] === 'supply' ? 'selected' : ''; ?>>Supply</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="account_code_id" class="form-label">Default Account Code</label>
                        <select class="form-select" id="account_code_id" name="account_code_id">
                            <option value="">No default account code</option>
                            <?php foreach ($accountCodes as $accountCode): ?>
                                <option value="<?php echo (int) $accountCode['id']; ?>" data-account-group="<?php echo h($accountCode['account_group']); ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($accountCode['account_code'] . ' - ' . $accountCode['account_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Optional. Use this only as a suggested/default mapping.</div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active inventory class</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/classifications/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Inventory Class List</h5>
                    <span class="badge text-bg-light"><?php echo count($classifications); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Inventory Class</th>
                                <th>Useful Life</th>
                                <th>Account Code</th>
                                <th>Group</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($classifications): ?>
                                <?php foreach ($classifications as $classification): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($classification['classification_code']); ?></td>
                                        <td>
                                            <div><?php echo h($classification['classification_name']); ?></div>
                                            <small class="text-muted"><?php echo h($classification['description'] ?? ''); ?></small>
                                        </td>
                                        <td><?php echo $classification['useful_life_years'] ? h($classification['useful_life_years']) . ' yr(s)' : '—'; ?></td>
                                        <td>
                                            <div><?php echo h($classification['account_code'] ?? ''); ?></div>
                                            <small class="text-muted"><?php echo h($classification['account_name'] ?? ''); ?></small>
                                        </td>
                                        <td><span class="badge text-bg-light text-uppercase"><?php echo h(str_replace('_', ' ', $classification['classification_group'])); ?></span></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $classification['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $classification['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td><div><?php echo h(date('M d, Y', strtotime($classification['created_at']))); ?></div><small class="text-muted"><?php echo h($classification['creator_name'] ?: 'System'); ?></small></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/classifications/index.php?edit=' . (int) $classification['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $classification['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this classification?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $classification['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No inventory classes found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var groupField = document.getElementById('classification_group');
    var accountCodeField = document.getElementById('account_code_id');

    function updateAccountCodeHint() {
        if (!groupField || !accountCodeField) {
            return;
        }

        Array.prototype.forEach.call(accountCodeField.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }

            option.hidden = false;
        });
    }

    if (groupField) {
        groupField.addEventListener('change', updateAccountCodeHint);
    }
    updateAccountCodeHint();
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
