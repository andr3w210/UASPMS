<?php
require_once __DIR__ . '/../../app/config/init.php';

require_login();

$db = db_connect();
$page_title = 'Account Codes';
$flash = get_flash();
$errors = [];
$accountCodes = [];
$form = [
    'id' => 0,
    'account_code' => '',
    'account_name' => '',
    'account_group' => 'asset',
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
            $form['account_code'] = old($_POST, 'account_code');
            $form['account_name'] = old($_POST, 'account_name');
            $form['account_group'] = old($_POST, 'account_group', 'asset');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['account_code'] === '') {
                $errors[] = 'Account code is required.';
            }
            if ($form['account_name'] === '') {
                $errors[] = 'Account name is required.';
            }
            if (!in_array($form['account_group'], ['supply', 'asset', 'semi_expendable'], true)) {
                $errors[] = 'Invalid account group.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM account_codes WHERE (account_code = ? OR account_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['account_code'], $form['account_name'], $recordId);
                $duplicateStmt->execute();
                $duplicateResult = $duplicateStmt->get_result();
                if ($duplicateResult && $duplicateResult->fetch_assoc()) {
                    $errors[] = 'Account code or account name already exists.';
                }
                $duplicateStmt->close();
            }

            if (empty($errors)) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE account_codes SET account_code = ?, account_name = ?, account_group = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('ssssiii', $form['account_code'], $form['account_name'], $form['account_group'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Account code updated successfully.');
                        redirect('modules/account_codes/index.php');
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO account_codes (account_code, account_name, account_group, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('ssssii', $form['account_code'], $form['account_name'], $form['account_group'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Account code created successfully.');
                        redirect('modules/account_codes/index.php');
                    }
                }

                $errors[] = 'Unable to save the account code.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE account_codes SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Account code deactivated successfully.');
                redirect('modules/account_codes/index.php');
            }
            $errors[] = 'Unable to deactivate the account code.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, account_code, account_name, account_group, description, is_active FROM account_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $result = $stmt->get_result();
            $record = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'account_code' => $record['account_code'],
                    'account_name' => $record['account_name'],
                    'account_group' => $record['account_group'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT ac.id, ac.account_code, ac.account_name, ac.account_group, ac.description, ac.is_active, ac.created_at,
               creator.full_name AS creator_name
        FROM account_codes ac
        LEFT JOIN users creator ON creator.id = ac.created_by
        ORDER BY ac.account_code ASC
    ");
    if ($result) {
        $accountCodes = $result->fetch_all(MYSQLI_ASSOC);
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
                <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Account Code' : 'Add Account Code'; ?></h5>

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
                        <label for="account_code" class="form-label">Account Code</label>
                        <input type="text" class="form-control" id="account_code" name="account_code" maxlength="50" value="<?php echo h($form['account_code']); ?>" placeholder="1-07-05-030" required>
                    </div>

                    <div class="mb-3">
                        <label for="account_name" class="form-label">Account Name</label>
                        <input type="text" class="form-control" id="account_name" name="account_name" maxlength="150" value="<?php echo h($form['account_name']); ?>" placeholder="Information and Communication Technology Equipment" required>
                    </div>

                    <div class="mb-3">
                        <label for="account_group" class="form-label">Account Group</label>
                        <select class="form-select" id="account_group" name="account_group">
                            <option value="asset" <?php echo $form['account_group'] === 'asset' ? 'selected' : ''; ?>>Asset</option>
                            <option value="semi_expendable" <?php echo $form['account_group'] === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                            <option value="supply" <?php echo $form['account_group'] === 'supply' ? 'selected' : ''; ?>>Supply</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo h($form['description']); ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active account code</label>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button>
                        <?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/account_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Account Code List</h5>
                    <span class="badge text-bg-light"><?php echo count($accountCodes); ?> record(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Account Title</th>
                                <th>Group</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($accountCodes): ?>
                                <?php foreach ($accountCodes as $accountCode): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($accountCode['account_code']); ?></td>
                                        <td>
                                            <div><?php echo h($accountCode['account_name']); ?></div>
                                            <small class="text-muted"><?php echo h($accountCode['description'] ?? ''); ?></small>
                                        </td>
                                        <td><span class="badge text-bg-light text-uppercase"><?php echo h(str_replace('_', ' ', $accountCode['account_group'])); ?></span></td>
                                        <td><span class="badge rounded-pill <?php echo (int) $accountCode['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $accountCode['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                        <td><div><?php echo h(date('M d, Y', strtotime($accountCode['created_at']))); ?></div><small class="text-muted"><?php echo h($accountCode['creator_name'] ?: 'System'); ?></small></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="<?php echo base_url('modules/account_codes/index.php?edit=' . (int) $accountCode['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <?php if ((int) $accountCode['is_active'] === 1): ?>
                                                    <form method="post" onsubmit="return confirm('Deactivate this account code?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $accountCode['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No account codes found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
