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
        $effective_date = trim((string)($_POST['effective_date'] ?? ''));
        $equipment_min = isset($_POST['equipment_min']) ? (float) $_POST['equipment_min'] : 0.0;
        $semi_hv_min = isset($_POST['semi_hv_min']) ? (float) $_POST['semi_hv_min'] : 0.0;
        $basis = trim((string)($_POST['basis'] ?? ''));

        if ($effective_date === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $effective_date)) {
            $errors[] = 'Effective date is required.';
        }
        if ($equipment_min <= 0) {
            $errors[] = 'Equipment minimum must be greater than zero.';
        }
        if ($semi_hv_min <= 0) {
            $errors[] = 'Semi HV minimum must be greater than zero.';
        }
        if ($equipment_min <= $semi_hv_min) {
            $errors[] = 'Equipment minimum must be greater than Semi HV minimum.';
        }

        if (empty($errors) && $db) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO property_thresholds (equipment_min, semi_hv_min, effective_date, basis, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param('ddsis', $equipment_min, $semi_hv_min, $effective_date, $basis, $userId);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    // Audit log: insert record for created threshold
                    $newId = $db->insert_id;
                    $desc = json_encode([
                        'equipment_min' => $equipment_min,
                        'semi_hv_min' => $semi_hv_min,
                        'effective_date' => $effective_date,
                        'basis' => $basis,
                    ]);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $alog = $db->prepare('INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, module_name, record_type, action_name, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    if ($alog) {
                        $action = 'insert';
                        $tableName = 'property_thresholds';
                        $mod = 'settings';
                        $rtype = 'property_thresholds';
                        $actionName = 'create_threshold';
                        $alog->bind_param('ississssss', $userId, $action, $tableName, $newId, $desc, $mod, $rtype, $actionName, $desc, $ip);
                        $alog->execute();
                        $alog->close();
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
        "SELECT t.*, u.full_name AS created_by_name FROM property_thresholds t LEFT JOIN users u ON u.id = t.created_by ORDER BY t.effective_date DESC, t.id DESC"
    );
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
}

// Determine active threshold (most recent effective_date <= today)
$active = null;
$today = date('Y-m-d');
foreach ($rows as $r) {
    if ($r['effective_date'] <= $today) {
        $active = $r;
        break;
    }
}


require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<main style="padding:16px;">
    <h4>Property Thresholds</h4>

    <?php if (!empty($flash)): ?>
        <div style="margin-bottom:12px;color:<?php echo $flash['type'] === 'success' ? '#006400' : '#b00020'; ?>;"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div style="color:#b00020;margin-bottom:12px;">
            <?php foreach ($errors as $e): ?>
                <div><?php echo h($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 420px;gap:16px;align-items:start;">
        <div>
            <div style="border:1px solid #eee;padding:12px;border-radius:6px;margin-bottom:12px;background:#fbfbfb;">
                <h5 style="margin-top:0;">Active Threshold</h5>
                <?php if ($active): ?>
                    <?php $semi_lv_max = (float)$active['semi_hv_min'] - 0.01; ?>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;">
                        <div style="min-width:180px;"><strong>Equipment (PPE):</strong><div>≥ ₱<?php echo h(number_format((float)$active['equipment_min'], 2)); ?></div></div>
                        <div style="min-width:180px;"><strong>Semi HV:</strong><div>₱<?php echo h(number_format((float)$active['semi_hv_min'] - 0.01, 2)); ?> — ₱<?php echo h(number_format((float)$active['equipment_min'] - 0.01, 2)); ?></div></div>
                        <div style="min-width:180px;"><strong>Semi LV:</strong><div>≤ ₱<?php echo h(number_format($semi_lv_max, 2)); ?></div></div>
                    </div>
                    <div style="margin-top:8px;color:#555;">Basis: <?php echo h($active['basis'] ?? '—'); ?> • Effective: <?php echo h($active['effective_date']); ?></div>
                <?php else: ?>
                    <div>No active threshold (no effective threshold ≤ today).</div>
                <?php endif; ?>
            </div>

            <div style="border:1px solid #eee;padding:12px;border-radius:6px;">
                <h5 style="margin-top:0;">Threshold History</h5>
                <table style="width:100%;border-collapse:collapse;border:1px solid #ddd;">
                    <thead>
                        <tr style="background:#f5f5f5;text-align:left;">
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Effective</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Equipment Min</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Semi HV Min</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Semi LV Max</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Basis</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Added By</th>
                            <th style="padding:8px;border-bottom:1px solid #ddd;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $semi_lv = (float)$r['semi_hv_min'] - 0.01; ?>
                                <tr>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;white-space:nowrap"><?php echo h($r['effective_date']); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;">₱<?php echo h(number_format((float)$r['equipment_min'], 2)); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;">₱<?php echo h(number_format((float)$r['semi_hv_min'], 2)); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;">≤ ₱<?php echo h(number_format($semi_lv, 2)); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;"><?php echo h($r['basis'] ?? '—'); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;"><?php echo h($r['created_by_name'] ?? 'System'); ?></td>
                                    <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;">
                                        <?php if ($active && (int)$r['id'] === (int)$active['id']): ?>
                                            <span style="background:#d4edda;color:#155724;padding:4px 8px;border-radius:4px;">Active</span>
                                        <?php elseif ($r['effective_date'] > $today): ?>
                                            <span style="background:#cfe2ff;color:#084298;padding:4px 8px;border-radius:4px;">Future</span>
                                        <?php else: ?>
                                            <span style="background:#f0f0f0;color:#333;padding:4px 8px;border-radius:4px;">Superseded</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" style="padding:12px;text-align:center;color:#666;">No thresholds configured.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <div style="border:1px solid #eee;padding:12px;border-radius:6px;">
                <h5 style="margin-top:0;">Add New Threshold</h5>
                <div style="margin-bottom:8px;padding:8px;background:#fff3cd;border:1px solid #ffeeba;border-radius:4px;color:#856404;">Adding a new threshold only affects NEW transactions from the effective date onwards. Existing records are not changed.</div>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <div style="margin-bottom:8px;"><label>Effective Date</label><br><input type="date" name="effective_date" value="<?php echo h($_POST['effective_date'] ?? date('Y-m-d')); ?>" required></div>
                    <div style="margin-bottom:8px;"><label>Equipment Minimum (₱)</label><br><input type="number" step="0.01" name="equipment_min" value="<?php echo h($_POST['equipment_min'] ?? '50000.00'); ?>" required></div>
                    <div style="margin-bottom:8px;"><label>Semi-expendable High-Value Minimum (₱)</label><br><input type="number" step="0.01" name="semi_hv_min" value="<?php echo h($_POST['semi_hv_min'] ?? '5000.01'); ?>" required></div>
                    <div style="margin-bottom:8px;"><label>Legal Basis</label><br><input type="text" name="basis" placeholder="e.g. COA Circular 2022-004" value="<?php echo h($_POST['basis'] ?? ''); ?>"></div>
                    <div><button type="submit" style="padding:6px 10px;">Save New Threshold</button></div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
