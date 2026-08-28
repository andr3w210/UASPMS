<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator');

$page_title = 'Manage DTR Logs';
$db = db();
$traineeId = (int) ($_REQUEST['trainee_id'] ?? 0);
$date = trim((string) ($_REQUEST['date'] ?? ''));
$errors = [];
$flash = get_flash();

if (!$db || $traineeId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    set_flash('danger', 'A valid trainee and DTR date are required.');
    redirect('modules/dtr/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        $logId = (int) ($_POST['log_id'] ?? 0);
        if ($logId < 1) {
            $errors[] = 'Invalid DTR log selected.';
        } elseif ($action === 'delete') {
            $stmt = $db->prepare('DELETE FROM dtr_logs WHERE id = ? AND trainee_id = ?');
            $stmt->bind_param('ii', $logId, $traineeId);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();
            if ($deleted) {
                set_flash('success', 'DTR log deleted.');
                redirect('modules/dtr/edit_log.php?trainee_id=' . $traineeId . '&date=' . rawurlencode($date));
            }
            $errors[] = 'The selected DTR log was not found.';
        } elseif ($action === 'update') {
            $logType = trim((string) ($_POST['log_type'] ?? ''));
            $loggedAtInput = trim((string) ($_POST['logged_at'] ?? ''));
            $loggedAt = DateTime::createFromFormat('Y-m-d\TH:i', $loggedAtInput);
            if (!in_array($logType, ['time_in', 'time_out'], true) || !$loggedAt || $loggedAt->format('Y-m-d\TH:i') !== $loggedAtInput) {
                $errors[] = 'Choose a valid log type and date/time.';
            } else {
                $loggedAtValue = $loggedAt->format('Y-m-d H:i:s');
                $stmt = $db->prepare('UPDATE dtr_logs SET log_type = ?, logged_at = ? WHERE id = ? AND trainee_id = ?');
                $stmt->bind_param('ssii', $logType, $loggedAtValue, $logId, $traineeId);
                $updated = $stmt->execute();
                $stmt->close();
                if ($updated) {
                    set_flash('success', 'DTR log updated.');
                    redirect('modules/dtr/edit_log.php?trainee_id=' . $traineeId . '&date=' . rawurlencode($date));
                }
                $errors[] = 'Unable to update the selected DTR log.';
            }
        } else {
            $errors[] = 'Invalid DTR action.';
        }
    }
}

$stmt = $db->prepare('SELECT id, first_name, middle_name, last_name FROM dtr_trainees WHERE id = ?');
$stmt->bind_param('i', $traineeId);
$stmt->execute();
$trainee = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$trainee) {
    set_flash('danger', 'Trainee not found.');
    redirect('modules/dtr/index.php');
}

$stmt = $db->prepare('SELECT id, log_type, logged_at, match_distance, source FROM dtr_logs WHERE trainee_id = ? AND DATE(logged_at) = ? ORDER BY logged_at ASC');
$stmt->bind_param('is', $traineeId, $date);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row page-section"><div class="col-xl-9"><div class="card"><div class="card-body p-4">
<div class="workspace-header mb-3"><div class="workspace-header-copy"><p class="page-kicker mb-1">OJT / DTR</p><h5 class="page-title mb-1">Manage DTR Logs</h5><p class="text-muted mb-0"><?php echo h(trim($trainee['last_name'] . ', ' . $trainee['first_name'] . ' ' . $trainee['middle_name'])); ?> &middot; <?php echo h(date('F d, Y', strtotime($date))); ?></p></div><div class="workspace-actions"><a class="btn btn-outline-secondary" href="index.php?trainee_id=<?php echo $traineeId; ?>&from=<?php echo h($date); ?>&to=<?php echo h($date); ?>">Back to DTR logs</a></div></div>
<?php if ($flash): ?><div class="alert alert-<?php echo h($flash['type'] ?? 'success'); ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?php echo h($error); ?></div><?php endforeach; ?>
<div class="alert alert-warning small">Editing and deletion are administrator-only. Times are stored as the actual scan time; use this only to correct an entry.</div>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Type</th><th>Actual date and time</th><th>Source</th><th>Match distance</th><th class="text-end">Actions</th></tr></thead><tbody>
<?php foreach ($logs as $log): ?><tr><td colspan="5"><form method="post" class="row g-2 align-items-center"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="trainee_id" value="<?php echo $traineeId; ?>"><input type="hidden" name="date" value="<?php echo h($date); ?>"><input type="hidden" name="log_id" value="<?php echo (int) $log['id']; ?>"><div class="col-md-2"><select class="form-select" name="log_type"><option value="time_in" <?php echo $log['log_type'] === 'time_in' ? 'selected' : ''; ?>>Time in</option><option value="time_out" <?php echo $log['log_type'] === 'time_out' ? 'selected' : ''; ?>>Time out</option></select></div><div class="col-md-3"><input type="datetime-local" class="form-control" name="logged_at" value="<?php echo h(date('Y-m-d\TH:i', strtotime($log['logged_at']))); ?>" required></div><div class="col-md-2"><span class="small text-muted"><?php echo h($log['source'] ?: '—'); ?></span></div><div class="col-md-2"><span class="small text-muted"><?php echo $log['match_distance'] !== null ? h((string) $log['match_distance']) : '—'; ?></span></div><div class="col-md-3 text-md-end"><button class="btn btn-sm btn-outline-primary" name="action" value="update">Save changes</button> <button class="btn btn-sm btn-outline-danger" name="action" value="delete" onclick="return confirm('Delete this DTR log permanently?');">Delete</button></div></form></td></tr><?php endforeach; ?>
<?php if (!$logs): ?><tr><td colspan="5" class="text-center text-muted py-4">No logs exist for this day.</td></tr><?php endif; ?>
</tbody></table></div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
