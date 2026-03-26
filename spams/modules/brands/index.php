<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Brands';
$flash = get_flash();
$errors = [];
$brands = [];
$form = [
    'id' => 0,
    'brand_code' => '',
    'brand_name' => '',
    'description' => '',
    'is_active' => '1',
];

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $generatedCode = preview_module_code($db, 'brands');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['brand_code'] = $form['id'] > 0 ? strtoupper(old($_POST, 'brand_code')) : $generatedCode;
            $form['brand_name'] = old($_POST, 'brand_name');
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['brand_name'] === '') {
                $errors[] = 'Brand name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM brands WHERE (brand_code = ? OR brand_name = ?) AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('ssi', $form['brand_code'], $form['brand_name'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
                    $errors[] = 'Brand code or brand name already exists.';
                }
                $duplicateStmt->close();
            }

            if (!$errors) {
                $isActive = (int) $form['is_active'];
                $userId = current_user_id();

                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE brands SET brand_code = ?, brand_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssiii', $form['brand_code'], $form['brand_name'], $form['description'], $isActive, $userId, $recordId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Brand updated successfully.');
                        redirect('modules/brands/index.php');
                    }
                } else {
                    $form['brand_code'] = next_module_code($db, 'brands');
                    $stmt = $db->prepare("INSERT INTO brands (brand_code, brand_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssii', $form['brand_code'], $form['brand_name'], $form['description'], $isActive, $userId);
                        $stmt->execute();
                        $stmt->close();
                        set_flash('success', 'Brand created successfully.');
                        redirect('modules/brands/index.php');
                    }
                }

                $errors[] = 'Unable to save the brand.';
            }
        }

        if ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE brands SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $stmt->execute();
                $stmt->close();
                set_flash('success', 'Brand deactivated successfully.');
                redirect('modules/brands/index.php');
            }
            $errors[] = 'Unable to deactivate the brand.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, brand_code, brand_name, description, is_active FROM brands WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'brand_code' => $record['brand_code'],
                    'brand_name' => $record['brand_name'],
                    'description' => $record['description'] ?? '',
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("
        SELECT b.id, b.brand_code, b.brand_name, b.description, b.is_active, b.created_at, creator.full_name AS creator_name
        FROM brands b
        LEFT JOIN users creator ON creator.id = b.created_by
        ORDER BY b.brand_name ASC
    ");
    if ($result) {
        $brands = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card"><div class="card-body p-4">
            <h5 class="card-title mb-3"><?php echo $form['id'] > 0 ? 'Edit Brand' : 'Add Brand'; ?></h5>
            <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
            <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                <div class="mb-3"><label class="form-label">Brand Code</label><input type="text" class="form-control" name="brand_code" value="<?php echo h($form['id'] > 0 ? $form['brand_code'] : $generatedCode); ?>" readonly><div class="form-text">Generated automatically using `BRD-YYYY-0001` format.</div></div>
                <div class="mb-3"><label class="form-label">Brand Name</label><input type="text" class="form-control" name="brand_name" value="<?php echo h($form['brand_name']); ?>" maxlength="150" required></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div>
                <div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active brand</label></div>
                <div class="d-flex gap-2"><button type="submit" class="btn btn-primary"><?php echo $form['id'] > 0 ? 'Update' : 'Save'; ?></button><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/brands/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?></div>
            </form>
        </div></div>
    </div>
    <div class="col-xl-8">
        <div class="card"><div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h5 class="card-title mb-0">Brand List</h5><span class="badge text-bg-light"><?php echo count($brands); ?> record(s)</span></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Code</th><th>Brand</th><th>Status</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if ($brands): foreach ($brands as $brand): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo h($brand['brand_code']); ?></td>
                            <td><div><?php echo h($brand['brand_name']); ?></div><small class="text-muted"><?php echo h($brand['description'] ?? ''); ?></small></td>
                            <td><span class="badge rounded-pill <?php echo (int) $brand['is_active'] === 1 ? 'badge-soft-success' : 'badge-soft-secondary'; ?>"><?php echo (int) $brand['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                            <td><div><?php echo h(date('M d, Y', strtotime($brand['created_at']))); ?></div><small class="text-muted"><?php echo h($brand['creator_name'] ?: 'System'); ?></small></td>
                            <td class="text-end"><div class="d-inline-flex gap-2"><a href="<?php echo base_url('modules/brands/index.php?edit=' . (int) $brand['id']); ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php if ((int) $brand['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this brand?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $brand['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button></form><?php endif; ?></div></td>
                        </tr>
                    <?php endforeach; else: ?><tr><td colspan="5" class="text-center text-muted py-4">No brands found yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div></div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
