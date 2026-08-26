<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Time Keeper');
require_once __DIR__ . '/dtr_helpers.php';

$page_title = 'DTR Logs';
$db = db();
$traineeId = (int) ($_GET['trainee_id'] ?? 0);
$from = trim($_GET['from'] ?? date('Y-m-01'));
$to = trim($_GET['to'] ?? date('Y-m-d'));
$trainees = [];
$dailyRows = [];
$schedule = $db ? dtr_load_schedule($db) : dtr_default_schedule();

if ($db) {
    $result = $db->query('SELECT id, first_name, middle_name, last_name FROM dtr_trainees ORDER BY last_name, first_name');
    if ($result) $trainees = $result->fetch_all(MYSQLI_ASSOC);
    $sql = 'SELECT l.trainee_id, l.log_type, l.logged_at, t.first_name, t.middle_name, t.last_name
            FROM dtr_logs l INNER JOIN dtr_trainees t ON t.id = l.trainee_id
            WHERE DATE(l.logged_at) BETWEEN ? AND ?';
    $types = 'ss'; $params = [$from, $to];
    if ($traineeId > 0) { $sql .= ' AND l.trainee_id = ?'; $types .= 'i'; $params[] = $traineeId; }
    $sql .= ' ORDER BY l.logged_at ASC';
    $stmt = $db->prepare($sql); $refs = [$types]; foreach ($params as $key => $value) $refs[] = &$params[$key]; call_user_func_array([$stmt, 'bind_param'], $refs); $stmt->execute();
    $groups = [];
    foreach ($stmt->get_result() as $log) { $key = $log['trainee_id'] . '|' . date('Y-m-d', strtotime($log['logged_at'])); $groups[$key]['meta'] = $log; $groups[$key]['logs'][] = $log; }
    $stmt->close();
    foreach ($groups as $group) {
        $split = dtr_split_day_logs($group['logs'], $schedule);
        foreach (['am_in' => 'am_login', 'pm_in' => 'pm_login'] as $slot => $scheduleKey) if ($split[$slot]) $split[$slot] = dtr_apply_grace($split[$slot], new DateTime($split[$slot]->format('Y-m-d') . ' ' . $schedule[$scheduleKey]), (int) $schedule['grace_minutes']);
        $dailyRows[] = ['trainee_id' => (int) $group['meta']['trainee_id'], 'name' => trim($group['meta']['last_name'] . ', ' . $group['meta']['first_name'] . ' ' . $group['meta']['middle_name']), 'date' => date('Y-m-d', strtotime($group['meta']['logged_at'])), 'split' => $split, 'hours' => dtr_compute_day_hours($split)];
    }
    usort($dailyRows, static fn($a, $b) => strcmp($b['date'] . $b['name'], $a['date'] . $a['name']));
}
if (($_GET['export'] ?? '') === 'csv') stream_csv_download('dtr_logs_' . date('Ymd_His') . '.csv', ['Trainee','Date','AM Time In','AM Time Out','PM Time In','PM Time Out','Hours'], $dailyRows, static function ($row) { $format = static fn($time) => $time ? $time->format('g:i A') : ''; return [$row['name'], $row['date'], $format($row['split']['am_in']), $format($row['split']['am_out']), $format($row['split']['pm_in']), $format($row['split']['pm_out']), number_format($row['hours'], 2)]; });

require_once __DIR__ . '/../../includes/header.php'; require_once __DIR__ . '/../../includes/sidebar.php'; require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row page-section"><div class="col-12"><div class="card"><div class="card-body p-4"><div class="workspace-header mb-3"><div class="workspace-header-copy"><p class="page-kicker mb-1">OJT / DTR</p><h5 class="page-title mb-1">Daily Time Records</h5></div><div class="workspace-actions"><a class="btn btn-outline-secondary" href="trainees.php">Trainees</a><a class="btn btn-primary" href="kiosk.php">Open Kiosk</a></div></div><form method="get" class="row g-2 mb-3"><div class="col-md-4"><select name="trainee_id" class="form-select"><option value="">All trainees</option><?php foreach ($trainees as $trainee): ?><option value="<?php echo (int) $trainee['id']; ?>" <?php echo $traineeId === (int) $trainee['id'] ? 'selected' : ''; ?>><?php echo h(trim($trainee['last_name'] . ', ' . $trainee['first_name'] . ' ' . $trainee['middle_name'])); ?></option><?php endforeach; ?></select></div><div class="col-md-3"><input type="date" class="form-control" name="from" value="<?php echo h($from); ?>"></div><div class="col-md-3"><input type="date" class="form-control" name="to" value="<?php echo h($to); ?>"></div><div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Filter</button><a class="btn btn-outline-success" href="?<?php echo h(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">CSV</a></div></form><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Trainee</th><th>Date</th><th>AM Time In</th><th>AM Time Out</th><th>PM Time In</th><th>PM Time Out</th><th>Hours</th><th></th></tr></thead><tbody><?php foreach ($dailyRows as $row): $format = static fn($time) => $time ? $time->format('g:i A') : '—'; ?><tr><td><?php echo h($row['name']); ?></td><td><?php echo h(date('M d, Y', strtotime($row['date']))); ?></td><td><?php echo h($format($row['split']['am_in'])); ?></td><td><?php echo h($format($row['split']['am_out'])); ?></td><td><?php echo h($format($row['split']['pm_in'])); ?></td><td><?php echo h($format($row['split']['pm_out'])); ?></td><td><?php echo h(number_format($row['hours'], 2)); ?></td><td><a class="btn btn-sm btn-outline-primary" target="_blank" href="print.php?trainee_id=<?php echo (int) $row['trainee_id']; ?>&month=<?php echo h(substr($row['date'], 0, 7)); ?>">Print DTR</a></td></tr><?php endforeach; ?><?php if (!$dailyRows): ?><tr><td colspan="8" class="text-center text-muted py-4">No DTR logs found.</td></tr><?php endif; ?></tbody></table></div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
