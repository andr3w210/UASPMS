<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Models';
$flash = get_flash();
$errors = [];
$models = [];
$brands = [];
$form = [
    'id' => 0,
    'brand_id' => '',
    'model_code' => '',
    'model_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'models');
    $brandResult = $db->query("SELECT id, brand_name FROM brands WHERE is_active = 1 ORDER BY brand_name ASC");
    if ($brandResult) {
        $brands = $brandResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['brand_id'] = old($_POST, 'brand_id');
            $form['model_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'model_code')) : $generatedCode;
            $form['model_name'] = old($_POST, 'model_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['brand_id'] === '') {
                $errors[] = 'Brand is required.';
            }
            if ($form['model_name'] === '') {
                $errors[] = 'Model name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM models WHERE (model_code = ? OR (brand_id = ? AND model_name = ?)) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $brandId = (int) $form['brand_id'];
                $duplicateStmt->bind_param('sisi', $form['model_code'], $brandId, $form['model_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Model code or brand/model combination already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $userId = current_user_id();
                $brandId = (int) $form['brand_id'];
                $isActive = (int) $form['is_active'];

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE models SET brand_id = ?, model_code = ?, model_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('isssiii', $brandId, $form['model_code'], $form['model_name'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Model updated successfully.');
                        redirect('modules/models/index.php');
                    }
                } else {
                    $form['model_code'] = next_module_code($db, 'models');
                    $stmt = $db->prepare("INSERT INTO models (brand_id, model_code, model_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('isssii', $brandId, $form['model_code'], $form['model_name'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Model created successfully.');
                        redirect('modules/models/index.php');
                    }
                }

                $errors[] = 'Unable to save the model.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE models SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Model deactivated successfully.');
                redirect('modules/models/index.php');
            }
            $errors[] = 'Unable to deactivate the model.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, brand_id, model_code, model_name, description, is_active FROM models WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'brand_id' => (string) $record['brand_id'],
                    'model_code' => $record['model_code'],
                    'model_name' => $record['model_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT m.id, m.model_code, m.model_name, m.description, m.is_active, m.created_at,
               b.brand_name, creator.full_name AS creator_name
        FROM models m
        INNER JOIN brands b ON b.id = m.brand_id
        LEFT JOIN users creator ON creator.id = m.created_by
        ORDER BY b.brand_name ASC, m.model_name ASC
    ");
    if ($result) {
        $models = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card"><div class="card-body p-4">
            <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Model' : 'Add Model'; ?></h5>
            <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
            <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                <div class="mb-3"><label class="form-label">Brand</label><select class="form-select" name="brand_id" required data-placeholder="Select brand"><option value="">Select brand</option><?php foreach ($brands as $brand): ?><option value="<?php echo (int) $brand['id']; ?>" <?php echo $form['brand_id'] === (string) $brand['id'] ? 'selected' : ''; ?>><?php echo h($brand['brand_name']); ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label">Model Code</label><input type="text" class="form-control" name="model_code" value="<?php echo h($form['id'] > 0 ? $form['model_code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using `MDL-YYYY-0001` format.</div></div>
                <div class="mb-3"><label class="form-label">Model Name</label><input type="text" class="form-control" name="model_name" value="<?php echo h($form['model_name']); ?>" maxlength="150" required></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div>
                <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active model</label></div>
                <div class="d-flex gap-2"><button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/models/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div>
            </form>
        </div></div>
    </div>
    <div class="col-xl-8">
        <div class="card"><div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h5 class="card-title mb-0">Model List</h5><span class="badge text-bg-light"><?php echo count($models); ?> record(s)</span></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Code</th><th>Brand / Model</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if ($models): foreach ($models as $model): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo h($model['model_code']); ?></td>
                            <td><div><?php echo h($model['brand_name']); ?></div><div class="fw-semibold"><?php echo h($model['model_name']); ?></div><small class="text-muted"><?php echo h($model['description'] ?? ''); ?></small></td>
                            <td><span class="badge rounded-pill <?php echo (int) $model['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $model['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                            <td><div><?php echo h(date('M d, Y', strtotime($model['created_at']))); ?></div><small class="text-muted"><?php echo h($model['creator_name'] ?: 'System'); ?></small></td>
                            <td class="text-end"><div class="d-inline-flex gap-2"><a href="<?php echo base_url('modules/models/index.php?edit=' . (int) $model['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php if ((int) $model['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this model?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $model['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button></form><?php endif; ?></div></td>
                        </tr>
                    <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">No models found yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
