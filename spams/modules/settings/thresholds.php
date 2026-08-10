<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'Property Thresholds';
$flash = get_flash();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $equipmentMin = isset($_POST['equipment_min']) ? (float) $_POST['equipment_min'] : 0.0;
        $semiHvMin = isset($_POST['semi_hv_min']) ? (float) $_POST['semi_hv_min'] : 0.0;
        $basis = trim((string) ($_POST['basis'] ?? ''));

        if ($effectiveDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            $errors[] = 'Effective date is required.';
        }
        if ($equipmentMin <= 0) {
            $errors[] = 'Equipment minimum must be greater than zero.';
        }
        if ($semiHvMin <= 0) {
            $errors[] = 'Semi HV minimum must be greater than zero.';
        }
        if ($equipmentMin <= $semiHvMin) {
            $errors[] = 'Equipment minimum must be greater than Semi HV minimum.';
        }

        if (empty($errors) && $db) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO property_thresholds (equipment_min, semi_hv_min, effective_date, basis, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('ddssi', $equipmentMin, $semiHvMin, $effectiveDate, $basis, $userId);
                $ok = $stmt->execute();
                $newId = (int) $db->insert_id;
                $stmt->close();

                if ($ok) {
                    if (function_exists('spams_cache_delete')) {
                        spams_cache_delete('property_thresholds:active');
                    }
                    if (function_exists('write_audit_log')) {
                        write_audit_log($db, [
                            'action' => 'insert',
                            'table_name' => 'property_thresholds',
                            'record_id' => $newId,
                            'module_name' => 'settings',
                            'record_type' => 'property_thresholds',
                            'action_name' => 'create_threshold',
                            'new_values' => [
                                'equipment_min' => $equipmentMin,
                                'semi_hv_min' => $semiHvMin,
                                'effective_date' => $effectiveDate,
                                'basis' => $basis,
                            ],
                        ]);
                    }
                    set_flash('success', 'Threshold saved successfully.');
                    redirect('modules/settings/thresholds.php');
                } else {
                    $errors[] = 'Database error while saving threshold.';
                }
            } else {
                $errors[] = 'Unable to prepare database statement.';
            }
        }
    }
}

$rows = [];
if ($db) {
    $res = $db->query(
        "SELECT t.*, u.full_name AS created_by_name
         FROM property_thresholds t
         LEFT JOIN users u ON u.id = t.created_by
         ORDER BY t.effective_date DESC, t.id DESC"
    );
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
}

$active = null;
$today = date('Y-m-d');
foreach ($rows as $row) {
    if (($row['effective_date'] ?? '') <= $today) {
        $active = $row;
        break;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row g-4">
        <div class="col-12">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo h($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-xl-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">Property Settings</div>
                        <h4 class="mb-2">Property Thresholds</h4>
                        <p class="text-muted mb-0">Maintain threshold bands used to classify new equipment and semi-expendable assets.</p>
                    </div>

                    <div class="row g-3 mb-4">
                        <?php if ($active): ?>
                            <?php $semiLvMax = (float) $active['semi_hv_min'] - 0.01; ?>
                            <div class="col-md-4">
                                <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2">Equipment (PPE)</div>
                                    <div class="fs-5 fw-semibold">>= <?php echo h(format_currency((float) $active['equipment_min'])); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2">Semi High Value</div>
                                    <div class="fw-semibold"><?php echo h(format_currency((float) $active['semi_hv_min'] - 0.01)); ?> to <?php echo h(format_currency((float) $active['equipment_min'] - 0.01)); ?></div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                                    <div class="small text-uppercase text-muted fw-semibold mb-2">Semi Low Value</div>
                                    <div class="fs-5 fw-semibold"><= <?php echo h(format_currency($semiLvMax)); ?></div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="small text-muted">Active basis: <?php echo h($active['basis'] ?? '-'); ?> | Effective: <?php echo h($active['effective_date']); ?></div>
                            </div>
                        <?php else: ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">No active threshold is configured for today.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div>
                            <h5 class="mb-1">Threshold History</h5>
                            <div class="text-muted small">Review all saved threshold versions and their current effectivity.</div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Effective</th>
                                    <th>Equipment Min</th>
                                    <th>Semi HV Min</th>
                                    <th>Semi LV Max</th>
                                    <th>Basis</th>
                                    <th>Added By</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $semiLv = (float) $row['semi_hv_min'] - 0.01; ?>
                                        <tr>
                                            <td><?php echo h($row['effective_date']); ?></td>
                                            <td><?php echo h(format_currency((float) $row['equipment_min'])); ?></td>
                                            <td><?php echo h(format_currency((float) $row['semi_hv_min'])); ?></td>
                                            <td><= <?php echo h(format_currency($semiLv)); ?></td>
                                            <td><?php echo h($row['basis'] ?? '-'); ?></td>
                                            <td><?php echo h($row['created_by_name'] ?? 'System'); ?></td>
                                            <td>
                                                <?php if ($active && (int) $row['id'] === (int) $active['id']): ?>
                                                    <span class="badge text-bg-success">Active</span>
                                                <?php elseif (($row['effective_date'] ?? '') > $today): ?>
                                                    <span class="badge text-bg-primary">Future</span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-secondary">Superseded</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">No thresholds configured.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <div class="mb-3">
                        <div class="text-uppercase small text-muted fw-semibold">Threshold Setup</div>
                        <h5 class="mb-1">Add New Threshold</h5>
                        <p class="text-muted small mb-0">Add a new effective threshold set. Existing records are not changed retroactively.</p>
                    </div>

                    <div class="alert alert-warning small">
                        Adding a new threshold only affects new transactions from the effective date onwards.
                    </div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control" value="<?php echo h($_POST['effective_date'] ?? date('Y-m-d')); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Equipment Minimum (PHP)</label>
                            <input type="number" step="0.01" name="equipment_min" class="form-control" value="<?php echo h($_POST['equipment_min'] ?? '50000.00'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Semi-expendable High-Value Minimum (PHP)</label>
                            <input type="number" step="0.01" name="semi_hv_min" class="form-control" value="<?php echo h($_POST['semi_hv_min'] ?? '5000.01'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Legal Basis</label>
                            <input type="text" name="basis" class="form-control" placeholder="e.g. COA Circular 2022-004" value="<?php echo h($_POST['basis'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Save New Threshold</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
