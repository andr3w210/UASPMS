<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();
require_role('Administrator');

$db = db();
$page_title = 'Locations';
$flash = get_flash();
$errors = [];
$locations = [];
$form = [
    'id' => 0,
    'location_code' => '',
    'location_name' => '',
    'description' => '',
    'is_active' => '1',
];

function locations_has_reference(mysqli $db, int $recordId): bool
{
    return has_foreign_key_reference($db, 'locations', $recordId, [
        "SELECT 1 FROM distribution_item_details WHERE location_id = ? LIMIT 1",
        "SELECT 1 FROM legacy_assets WHERE location_id = ? LIMIT 1",
    ]);
}

function locations_code_from_name(mysqli $db, string $locationName, int $ignoreId = 0): string
{
    $base = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $locationName), '-'));
    if ($base === '') {
        $base = 'LOC';
    }
    $base = substr($base, 0, 42);
    $code = $base;
    $suffix = 1;

    while (true) {
        $stmt = $db->prepare("SELECT id FROM locations WHERE location_code = ? AND id != ? LIMIT 1");
        if (!$stmt) {
            return $code;
        }
        $stmt->bind_param('si', $code, $ignoreId);
        $stmt->execute();
        $duplicate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$duplicate) {
            return $code;
        }
        $suffix++;
        $code = substr($base, 0, 42 - strlen((string) $suffix)) . '-' . $suffix;
    }
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    ensure_locations_schema($db);
    ensure_asset_location_tracking_schema($db);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['location_name'] = trim((string) ($_POST['location_name'] ?? ''));
            $form['location_code'] = $form['location_name'] !== '' ? locations_code_from_name($db, $form['location_name'], (int) $form['id']) : '';
            $form['description'] = trim((string) ($_POST['description'] ?? ''));
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['location_name'] === '') {
                $errors[] = 'Location name is required.';
            }

            $duplicateStmt = $db->prepare("SELECT id FROM locations WHERE location_name = ? AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $recordId = (int) $form['id'];
                $duplicateStmt->bind_param('si', $form['location_name'], $recordId);
                $duplicateStmt->execute();
                $duplicate = $duplicateStmt->get_result()->fetch_assoc();
                $duplicateStmt->close();
                if ($duplicate) {
                    $errors[] = 'Location name already exists.';
                }
            }

            if (empty($errors)) {
                $userId = (int) current_user_id();
                $isActive = (int) $form['is_active'];
                if ($form['id'] > 0) {
                    $stmt = $db->prepare("UPDATE locations SET location_code = ?, location_name = ?, description = ?, is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
                    if ($stmt) {
                        $recordId = (int) $form['id'];
                        $stmt->bind_param('sssiii', $form['location_code'], $form['location_name'], $form['description'], $isActive, $userId, $recordId);
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'locations',
                                'record_id' => $recordId,
                                'module_name' => 'locations',
                                'record_type' => 'location',
                                'action_name' => 'update_location',
                                'description' => 'Updated location record.',
                                'new_values' => $form,
                            ]);
                            set_flash('success', 'Location updated successfully.');
                            redirect('modules/locations/index.php');
                        }
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO locations (location_code, location_name, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('sssii', $form['location_code'], $form['location_name'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'locations',
                                'record_id' => $newId,
                                'module_name' => 'locations',
                                'record_type' => 'location',
                                'action_name' => 'create_location',
                                'description' => 'Created location record.',
                                'new_values' => $form,
                            ]);
                            set_flash('success', 'Location created successfully.');
                            redirect('modules/locations/index.php');
                        }
                    }
                }
                $errors[] = 'Unable to save the location.';
            }
        } elseif ($action === 'deactivate' || $action === 'reactivate') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $isActive = $action === 'reactivate' ? 1 : 0;
            $stmt = $db->prepare("UPDATE locations SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $userId = (int) current_user_id();
                $stmt->bind_param('iii', $isActive, $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'locations',
                        'record_id' => $recordId,
                        'module_name' => 'locations',
                        'record_type' => 'location',
                        'action_name' => $action . '_location',
                        'description' => ($isActive ? 'Reactivated' : 'Deactivated') . ' location record.',
                        'new_values' => ['is_active' => $isActive],
                    ]);
                    set_flash('success', $isActive ? 'Location reactivated successfully.' : 'Location deactivated successfully.');
                    redirect('modules/locations/index.php');
                }
            }
            $errors[] = 'Unable to update the location.';
        } elseif ($action === 'hard_delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            if (locations_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: location is used by existing assets.');
                redirect('modules/locations/index.php');
            }
            $stmt = $db->prepare("DELETE FROM locations WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'locations',
                        'record_id' => $recordId,
                        'module_name' => 'locations',
                        'record_type' => 'location',
                        'action_name' => 'hard_delete_location',
                        'description' => 'Permanently deleted location record.',
                    ]);
                    set_flash('success', 'Location permanently deleted.');
                    redirect('modules/locations/index.php');
                }
            }
            $errors[] = 'Unable to delete the location.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, location_code, location_name, description, is_active FROM locations WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($record) {
                $form = [
                    'id' => (int) $record['id'],
                    'location_code' => (string) $record['location_code'],
                    'location_name' => (string) $record['location_name'],
                    'description' => (string) ($record['description'] ?? ''),
                    'is_active' => (string) (int) $record['is_active'],
                ];
            }
        }
    }

    $result = $db->query("SELECT id, location_code, location_name, description, is_active, created_at FROM locations ORDER BY location_name ASC");
    if ($result) {
        $locations = $result->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page">
    <div class="card master-data-page-card">
        <div class="card-body p-4 p-xl-4">
            <?php if ($errors): ?>
                <div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div>
            <?php endif; ?>

            <div class="master-data-header mb-4">
                <div>
                    <div class="text-uppercase small text-muted fw-semibold">Master Data</div>
                    <h4 class="mb-1">Locations</h4>
                    <div id="recordCount" class="text-muted small">Showing <?php echo count($locations); ?> of <?php echo count($locations); ?> records</div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($form['id'] > 0): ?>
                        <a href="<?php echo base_url('modules/locations/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>">
                        <i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Location'; ?>
                    </button>
                </div>
            </div>

            <div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse">
                <div class="master-data-editor">
                    <div class="master-data-editor-header">
                        <div>
                            <h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Location' : 'New Location'; ?></h5>
                            <div class="text-muted small">Encode the physical rooms, buildings, storage areas, or offices used for asset location tracking.</div>
                        </div>
                    </div>
                    <form method="post" class="workspace-form-section mt-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Location Name</label>
                                <input type="text" class="form-control" name="location_name" value="<?php echo h($form['location_name']); ?>" maxlength="180" required>
                                <div class="form-text">Location code is generated automatically from the name.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label">Active location</label>
                                </div>
                            </div>
                            <div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2">
                                <?php if ($form['id'] > 0): ?>
                                    <a href="<?php echo base_url('modules/locations/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Location' : 'Save Location'; ?></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="master-data-toolbar mb-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label">Search</label>
                        <input type="search" id="tableSearch" class="form-control" placeholder="Search code, name, or description">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Status</label>
                        <select id="statusFilter" class="form-select">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label">Rows Per Page</label>
                        <select id="perPageSelect" class="form-select">
                            <option value="25" selected>25 rows</option>
                            <option value="50">50 rows</option>
                            <option value="100">100 rows</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="master-data-table-shell">
                <div class="table-responsive mobile-table-frame master-data-table-scroll">
                    <table class="table align-middle" id="dataTable">
                        <thead>
                            <tr>
                                <th data-sort="code">Code <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="name">Location <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th data-sort="status">Status <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($locations): foreach ($locations as $location): ?>
                                <tr data-status="<?php echo (int) $location['is_active'] ? 'active' : 'inactive'; ?>">
                                    <td class="fw-semibold"><?php echo h($location['location_code']); ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($location['location_name']); ?></div>
                                        <small class="text-muted"><?php echo h((string) ($location['description'] ?? '')); ?></small>
                                    </td>
                                    <td><span class="badge <?php echo (int) $location['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $location['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-2">
                                            <a href="<?php echo base_url('modules/locations/index.php?edit=' . (int) $location['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a>
                                            <form method="post" onsubmit="return confirm('<?php echo (int) $location['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?> this location?');" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="<?php echo (int) $location['is_active'] === 1 ? 'deactivate' : 'reactivate'; ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $location['id']; ?>">
                                                <button type="submit" class="btn btn-sm <?php echo (int) $location['is_active'] === 1 ? 'btn-outline-warning' : 'btn-outline-success'; ?>"><?php echo (int) $location['is_active'] === 1 ? 'Deactivate' : 'Reactivate'; ?></button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Permanently delete this location?');" class="d-inline">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="hard_delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $location['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; else: ?>
                                <tr data-status="inactive"><td colspan="4" class="text-center text-muted py-4">No locations found yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="master-data-pagination">
                    <div id="recordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div>
                    <div class="master-data-pagination-controls">
                        <button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button>
                        <span id="pageInfo" class="small text-muted">Page 1 of 1</span>
                        <button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var recordCountMobile = document.getElementById('recordCountMobile');
    var options = {
        recordCountFormatter: function (visible, total) {
            var text = 'Showing ' + visible + ' of ' + total + ' records';
            if (recordCountMobile) {
                recordCountMobile.textContent = text;
            }
            return text;
        },
        pageInfoFormatter: function (state) {
            return 'Page ' + state.currentPage + ' of ' + state.totalPages + ' (' + state.totalVisible + ' matches)';
        },
        emptyMessage: 'No locations matched your search or status filter.'
    };

    if (typeof window.initMasterDataList === 'function') {
        window.initMasterDataList('dataTable', options);
        return;
    }
    window.__spamsPendingMasterDataLists = window.__spamsPendingMasterDataLists || [];
    window.__spamsPendingMasterDataLists.push(['dataTable', options]);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
