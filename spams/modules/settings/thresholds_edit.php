<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('admin');

$db = db_connect();
$page_title = 'Edit Threshold';
$flash = get_flash();
$errors = [];

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    set_flash('error', 'Missing threshold id.');
    redirect('modules/settings/thresholds.php');
}

// Load existing
$row = null;
if ($db) {
    $stmt = $db->prepare('SELECT id, equipment_min, semi_hv_min, effective_date FROM property_thresholds WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if (!$row) {
    set_flash('error', 'Threshold not found.');
    redirect('modules/settings/thresholds.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $equipment_min = isset($_POST['equipment_min']) ? trim((string) $_POST['equipment_min']) : '';
        $semi_hv_min = isset($_POST['semi_hv_min']) ? trim((string) $_POST['semi_hv_min']) : '';
        $effective_date = isset($_POST['effective_date']) ? trim((string) $_POST['effective_date']) : '';

        if ($equipment_min === '' || !is_numeric($equipment_min)) {
            $errors[] = 'Equipment minimum must be a numeric value.';
        }
        if ($semi_hv_min === '' || !is_numeric($semi_hv_min)) {
            $errors[] = 'Semi-expendable high-value minimum must be a numeric value.';
        }
        if ($effective_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effective_date)) {
            $errors[] = 'Effective date is required and must be YYYY-MM-DD.';
        }

        if (empty($errors)) {
            $equipment_min_f = (float) $equipment_min;
            $semi_hv_min_f = (float) $semi_hv_min;

            if ($equipment_min_f <= $semi_hv_min_f) {
                $errors[] = 'Equipment minimum must be greater than semi high-value minimum.';
            }
        }

        if (empty($errors) && $db) {
            $stmt = $db->prepare('UPDATE property_thresholds SET equipment_min = ?, semi_hv_min = ?, effective_date = ? WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ddsi', $equipment_min_f, $semi_hv_min_f, $effective_date, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    // Audit log for update
                    $userId = current_user_id();
                    $desc = json_encode([
                        'equipment_min' => $equipment_min_f,
                        'semi_hv_min' => $semi_hv_min_f,
                        'effective_date' => $effective_date,
                    ]);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $alog = $db->prepare('INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, module_name, record_type, action_name, description, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                    if ($alog) {
                        $action = 'update';
                        $tableName = 'property_thresholds';
                        $mod = 'settings';
                        $rtype = 'property_thresholds';
                        $actionName = 'update_threshold';
                        $alog->bind_param('ississsssss', $userId, $action, $tableName, $id, $oldDesc, $desc, $mod, $rtype, $actionName, $desc, $ip);
                        $alog->execute();
                        $alog->close();
                    }
                    set_flash('success', 'Threshold updated.');
                    redirect('modules/settings/thresholds.php');
                } else {
                    $errors[] = 'Database error while updating threshold.';
                }
            } else {
                $errors[] = 'Unable to prepare database statement.';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<main style="padding:16px;">
    <h4>Edit Threshold</h4>

    <?php if (!empty($errors)): ?>
        <div style="color:#b00020;margin-bottom:12px;">
            <?php foreach ($errors as $e): ?>
                <div><?php echo h($e); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" style="max-width:480px;border:1px solid #eee;padding:12px;border-radius:6px;">
        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
        <div style="margin-bottom:8px;"><label>Equipment Minimum</label><br><input type="text" name="equipment_min" value="<?php echo h($_POST['equipment_min'] ?? $row['equipment_min']); ?>"></div>
        <div style="margin-bottom:8px;"><label>Semi-expendable High-Value Minimum</label><br><input type="text" name="semi_hv_min" value="<?php echo h($_POST['semi_hv_min'] ?? $row['semi_hv_min']); ?>"></div>
        <div style="margin-bottom:8px;"><label>Effective Date</label><br><input type="date" name="effective_date" value="<?php echo h($_POST['effective_date'] ?? $row['effective_date']); ?>"></div>
        <div style="display:flex;gap:8px;"><button type="submit" style="padding:6px 10px;">Save</button><a href="thresholds.php" style="display:inline-block;padding:6px 10px;border:1px solid #ccc;border-radius:4px;text-decoration:none;">Cancel</a></div>
    </form>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
