<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_login();

function responsibility_codes_has_reference(mysqli $db, int $recordId): bool
{
    $code = '';
    $codeStmt = $db->prepare("SELECT code FROM responsibility_codes WHERE id = ? LIMIT 1");
    if ($codeStmt) {
        $codeStmt->bind_param('i', $recordId);
        $codeStmt->execute();
        $row = $codeStmt->get_result()->fetch_assoc();
        $codeStmt->close();
        $code = (string) ($row['code'] ?? '');
    }

    $checks = [];
    if ($code !== '') {
        $checks[] = ["SELECT 1 FROM distribution_item_details WHERE property_number LIKE CONCAT(?, '%') LIMIT 1", 's', $code];
    }
    $checks[] = ["SELECT 1 FROM employees WHERE responsibility_code_id = ? LIMIT 1", 'i', $recordId];

    foreach ($checks as $check) {
        [$sql, $type, $value] = $check;
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param($type, $value);
        $stmt->execute();
        $hasRow = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($hasRow) {
            return true;
        }
    }

    return false;
}

$db = db();
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
    $officeResult = $db->query("SELECT id, office_code, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? 'save';

        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } elseif ($action === 'save') {
            $form['id'] = (int) ($_POST['id'] ?? 0);
            $form['office_id'] = old($_POST, 'office_id');
            $form['code'] = strtoupper(old($_POST, 'code'));
            $form['description'] = old($_POST, 'description');
            $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';

            if ($form['office_id'] === '') {
                $errors[] = 'Office is required.';
            }
            if ($form['code'] === '') {
                $errors[] = 'Responsibility code is required.';
            }
            $officeId = (int) ($form['office_id'] ?: 0);
            $recordId = (int) $form['id'];


            $duplicateStmt = $db->prepare("SELECT id FROM responsibility_codes WHERE office_id = ? AND code = ? AND id != ? LIMIT 1");
            if ($duplicateStmt) {
                $duplicateStmt->bind_param('isi', $officeId, $form['code'], $recordId);
                $duplicateStmt->execute();
                if ($duplicateStmt->get_result()->fetch_assoc()) {
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
                        $saved = $stmt->execute();
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'responsibility_codes',
                                'record_id' => $recordId,
                                'module_name' => 'responsibility_codes',
                                'record_type' => 'responsibility_code',
                                'action_name' => 'update_responsibility_code',
                                'description' => 'Updated responsibility code.',
                                'new_values' => [
                                    'office_id' => $officeId,
                                    'code' => $form['code'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Responsibility code updated successfully.');
                            redirect('modules/responsibility_codes/index.php');
                        }
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO responsibility_codes (office_id, code, description, is_active, created_by) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param('issii', $officeId, $form['code'], $form['description'], $isActive, $userId);
                        $saved = $stmt->execute();
                        $newCodeId = (int) $stmt->insert_id;
                        $stmt->close();
                        if ($saved) {
                            write_audit_log($db, [
                                'action' => 'insert',
                                'table_name' => 'responsibility_codes',
                                'record_id' => $newCodeId,
                                'module_name' => 'responsibility_codes',
                                'record_type' => 'responsibility_code',
                                'action_name' => 'create_responsibility_code',
                                'description' => 'Created responsibility code.',
                                'new_values' => [
                                    'office_id' => $officeId,
                                    'code' => $form['code'],
                                    'description' => $form['description'],
                                    'is_active' => $isActive,
                                ],
                            ]);
                            set_flash('success', 'Responsibility code created successfully.');
                            redirect('modules/responsibility_codes/index.php');
                        }
                    }
                }

                $errors[] = 'Unable to save the responsibility code.';
            }
        } elseif ($action === 'delete') {
            $recordId = (int) ($_POST['id'] ?? 0);
            $userId = current_user_id();
            $stmt = $db->prepare("UPDATE responsibility_codes SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'responsibility_codes',
                        'record_id' => $recordId,
                        'module_name' => 'responsibility_codes',
                        'record_type' => 'responsibility_code',
                        'action_name' => 'deactivate_responsibility_code',
                        'description' => 'Deactivated responsibility code.',
                        'new_values' => ['is_active' => 0],
                    ]);
                    set_flash('success', 'Responsibility code deactivated successfully.');
                    redirect('modules/responsibility_codes/index.php');
                }
            }
            $errors[] = 'Unable to deactivate the responsibility code.';
        } elseif ($action === 'hard_delete') {
            if (($_SESSION['user_role'] ?? '') !== 'Administrator') {
                set_flash('error', 'Only administrators can permanently delete records.');
                redirect('modules/responsibility_codes/index.php');
            }
            $recordId = (int) ($_POST['id'] ?? 0);
            if (responsibility_codes_has_reference($db, $recordId)) {
                set_flash('error', 'Cannot delete: record is used in existing transactions.');
                redirect('modules/responsibility_codes/index.php');
            }
            $auditSnapshot = ['id' => $recordId];
            $auditStmt = $db->prepare("SELECT code, description, office_id FROM responsibility_codes WHERE id = ? LIMIT 1");
            if ($auditStmt) {
                $auditStmt->bind_param('i', $recordId);
                $auditStmt->execute();
                $auditRow = $auditStmt->get_result()->fetch_assoc();
                $auditStmt->close();
                if ($auditRow) {
                    $auditSnapshot = $auditRow;
                }
            }
            $stmt = $db->prepare("DELETE FROM responsibility_codes WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $recordId);
                $saved = $stmt->execute();
                $stmt->close();
                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'delete',
                        'table_name' => 'responsibility_codes',
                        'record_id' => $recordId,
                        'module_name' => 'responsibility_codes',
                        'record_type' => 'responsibility_code',
                        'action_name' => 'hard_delete_responsibility_code',
                        'description' => 'Permanently deleted responsibility code.',
                        'old_values' => $auditSnapshot,
                    ]);
                    set_flash('success', 'Record permanently deleted.');
                    redirect('modules/responsibility_codes/index.php');
                }
            }
            $errors[] = 'Unable to permanently delete the responsibility code.';
        }
    }

    if (isset($_GET['edit'])) {
        $recordId = (int) $_GET['edit'];
        $stmt = $db->prepare("SELECT id, office_id, code, description, is_active FROM responsibility_codes WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $recordId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
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
        LEFT JOIN offices o ON o.id = rc.office_id
        ORDER BY COALESCE(o.office_name, 'ZZZ Unassigned'), rc.code ASC
    ");
    if ($listResult) {
        $codes = $listResult->fetch_all(MYSQLI_ASSOC);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="master-data-page"><div class="card master-data-page-card"><div class="card-body p-4 p-xl-4">
<?php if (!empty($errors)): ?><div class="alert alert-danger mb-4"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> mb-4"><?php echo h($flash['message']); ?></div><?php endif; ?>
<div class="master-data-header mb-4"><div><div class="text-uppercase small text-muted fw-semibold">Master Data</div><h4 class="mb-1">Responsibility Codes</h4><div id="recordCount" class="text-muted small">Showing <?php echo count($codes); ?> of <?php echo count($codes); ?> records</div></div><div class="d-flex gap-2 flex-wrap"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/responsibility_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel Edit</a><?php endif; ?><button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCollapse" aria-expanded="<?php echo $form['id'] > 0 ? 'true' : 'false'; ?>"><i class="bi bi-plus-circle me-1"></i><?php echo $form['id'] > 0 ? 'Continue Editing' : 'Add Responsibility Code'; ?></button></div></div>
<div class="collapse <?php echo $form['id'] > 0 ? 'show' : ''; ?> mb-4" id="formCollapse"><div class="master-data-editor"><div class="master-data-editor-header"><div><h5 class="mb-1"><?php echo $form['id'] > 0 ? 'Edit Responsibility Code' : 'New Responsibility Code'; ?></h5><div class="text-muted small">Manage office-specific responsibility codes used in accountability records.</div></div></div><form method="post" class="workspace-form-section mt-3"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>"><div class="row g-3"><div class="col-md-6"><label class="form-label">Office</label><select class="form-select" name="office_id" required data-placeholder="Select office"><option value="">Select office</option><?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $form['office_id'] === (string) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name'] . ' (' . $office['office_code'] . ')'); ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Responsibility Code</label><input type="text" class="form-control" name="code" value="<?php echo h($form['code']); ?>" placeholder="Enter responsibility code" required></div><div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"><?php echo h($form['description']); ?></textarea></div><div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>><label class="form-check-label">Active code</label></div></div><div class="col-12 d-grid gap-2 d-sm-flex justify-content-sm-end pt-2"><?php if ($form['id'] > 0): ?><a href="<?php echo base_url('modules/responsibility_codes/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?><button type="submit" class="btn btn-primary px-4"><?php echo $form['id'] > 0 ? 'Update Code' : 'Save Code'; ?></button></div></div></form></div></div>
<div class="master-data-toolbar mb-3"><div class="row g-3 align-items-end"><div class="col-md-8 col-lg-9"><label class="form-label">Search</label><input type="search" id="tableSearch" class="form-control" placeholder="Search office, code, or description"></div><div class="col-md-4 col-lg-3"><label class="form-label">Status</label><select id="statusFilter" class="form-select"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div></div></div>
<div class="table-responsive mobile-table-frame"><table class="table align-middle" id="dataTable"><thead><tr><th>Office</th><th>Code</th><th>Description</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody><?php if ($codes): foreach ($codes as $code): ?><tr data-status="<?php echo (int) $code['is_active'] ? 'active' : 'inactive'; ?>"><td><?php if (!empty($code['office_name'])): ?><?php echo h($code['office_name'] . ' (' . $code['office_code'] . ')'); ?><?php else: ?><span class="text-warning">Unassigned Office</span><?php endif; ?></td><td class="fw-semibold"><?php echo h($code['code']); ?></td><td><?php echo h($code['description'] ?? ''); ?></td><td><span class="badge <?php echo (int) $code['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $code['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-2"><a href="<?php echo base_url('modules/responsibility_codes/index.php?edit=' . (int) $code['id']); ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i> Edit</a><?php if ((int) $code['is_active'] === 1): ?><form method="post" onsubmit="return confirm('Deactivate this responsibility code?');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int) $code['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><i class="bi bi-slash-circle"></i> Deactivate</button></form><?php endif; ?><?php if (($_SESSION['user_role'] ?? '') === 'Administrator'): ?><form method="post" onsubmit="return confirm('Permanently delete this record? This cannot be undone.');" class="d-inline"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?php echo (int) $code['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash3"></i> Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; else: ?><tr data-status="inactive"><td colspan="5" class="text-center text-muted py-4">No responsibility codes found yet.</td></tr><?php endif; ?></tbody></table></div>
<div class="d-flex align-items-center gap-3 mt-3 flex-wrap"><button class="btn btn-sm btn-outline-secondary" id="prevPage" type="button">Previous</button><span id="pageInfo" class="small text-muted">Page 1 of 1</span><button class="btn btn-sm btn-outline-secondary" id="nextPage" type="button">Next</button><select id="perPageSelect" class="form-select form-select-sm" style="width:auto;"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div>
</div></div></section><script>
document.addEventListener('DOMContentLoaded', function () {
    window.initMasterDataList('dataTable');
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>





