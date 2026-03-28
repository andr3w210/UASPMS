<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_role('Administrator');

$db = db_connect();
$page_title = 'Audit Log';
$flash = get_flash();
$errors = [];

$users = [];
$tables = [];
$modules = [];
$actions = [];
$rows = [];
$summary = [
    'total' => 0,
    'today' => 0,
    'users' => 0,
    'modules' => 0,
];

$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$filterUser = (int) ($_GET['user_id'] ?? 0);
$filterTable = trim((string) ($_GET['table_name'] ?? ''));
$filterModule = trim((string) ($_GET['module_name'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    ensure_audit_logs_table($db);

    $userRes = $db->query("SELECT id, username, full_name FROM users ORDER BY username ASC");
    if ($userRes) {
        $users = $userRes->fetch_all(MYSQLI_ASSOC);
    }

    $tableRes = $db->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name ASC");
    if ($tableRes) {
        $tables = array_map(static function ($row) {
            return (string) $row['table_name'];
        }, $tableRes->fetch_all(MYSQLI_ASSOC));
    }

    $moduleRes = $db->query("SELECT DISTINCT module_name FROM audit_logs WHERE COALESCE(module_name, '') <> '' ORDER BY module_name ASC");
    if ($moduleRes) {
        $modules = array_map(static function ($row) {
            return (string) $row['module_name'];
        }, $moduleRes->fetch_all(MYSQLI_ASSOC));
    }

    $actionRes = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
    if ($actionRes) {
        $actions = array_map(static function ($row) {
            return (string) $row['action'];
        }, $actionRes->fetch_all(MYSQLI_ASSOC));
    }

    $where = [];
    $params = [];
    $types = '';

    if ($startDate !== '') {
        $where[] = "al.created_at >= ?";
        $types .= 's';
        $params[] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $where[] = "al.created_at <= ?";
        $types .= 's';
        $params[] = $endDate . ' 23:59:59';
    }

    if ($filterUser > 0) {
        $where[] = "al.user_id = ?";
        $types .= 'i';
        $params[] = $filterUser;
    }

    if ($filterTable !== '') {
        $where[] = "al.table_name = ?";
        $types .= 's';
        $params[] = $filterTable;
    }

    if ($filterModule !== '') {
        $where[] = "al.module_name = ?";
        $types .= 's';
        $params[] = $filterModule;
    }

    if ($filterAction !== '') {
        $where[] = "al.action = ?";
        $types .= 's';
        $params[] = $filterAction;
    }

    if ($search !== '') {
        $where[] = "(al.record_id LIKE ? OR al.table_name LIKE ? OR COALESCE(al.module_name, '') LIKE ? OR COALESCE(al.action_name, '') LIKE ? OR COALESCE(al.description, '') LIKE ? OR COALESCE(u.username, '') LIKE ? OR COALESCE(u.full_name, '') LIKE ?)";
        $types .= 'sssssss';
        $like = '%' . $search . '%';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
        }
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) AS total FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id" . $whereSql;
    $dataSql = "
        SELECT
            al.id,
            al.user_id,
            al.action,
            al.table_name,
            al.record_id,
            al.old_values,
            al.new_values,
            al.module_name,
            al.record_type,
            al.action_name,
            al.description,
            al.ip_address,
            al.created_at,
            u.username,
            u.full_name
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        " . $whereSql . "
        ORDER BY al.created_at DESC, al.id DESC
    ";

    $summaryRes = $db->query("
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            COUNT(DISTINCT COALESCE(user_id, 0)) AS user_count,
            COUNT(DISTINCT COALESCE(NULLIF(module_name, ''), table_name)) AS module_count
        FROM audit_logs
    ");
    if ($summaryRes) {
        $summaryRow = $summaryRes->fetch_assoc();
        $summary = [
            'total' => (int) ($summaryRow['total_count'] ?? 0),
            'today' => (int) ($summaryRow['today_count'] ?? 0),
            'users' => (int) ($summaryRow['user_count'] ?? 0),
            'modules' => (int) ($summaryRow['module_count'] ?? 0),
        ];
    }

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $bindToStmt = static function (mysqli_stmt $stmt, string $paramTypes, array $values): void {
            if ($paramTypes === '') {
                return;
            }
            $bindValues = $values;
            $refs = [];
            $refs[] = &$paramTypes;
            foreach ($bindValues as $key => $value) {
                $refs[] = &$bindValues[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
        };

        $exportStmt = $db->prepare($dataSql);
        if ($exportStmt) {
            $bindToStmt($exportStmt, $types, $params);
            $exportStmt->execute();
            $res = $exportStmt->get_result();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_log_export.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'User', 'Module', 'Action', 'Action Name', 'Table', 'Record ID', 'Description', 'Changes']);
            while ($row = $res->fetch_assoc()) {
                fputcsv($out, [
                    date('Y-m-d H:i:s', strtotime((string) $row['created_at'])),
                    $row['full_name'] ?: ($row['username'] ?: 'System'),
                    $row['module_name'] ?: $row['table_name'],
                    $row['action'],
                    $row['action_name'] ?? '',
                    $row['table_name'],
                    $row['record_id'],
                    $row['description'] ?? '',
                    audit_log_change_text($row['old_values'] ?? null, $row['new_values'] ?? null),
                ]);
            }
            fclose($out);
            $exportStmt->close();
            exit;
        }
    }

    $pageData = paginate($db, $countSql, $dataSql, $params, $types, $page, 20);
    $rows = $pageData['data'];
    $total = $pageData['total'];
    $totalPages = $pageData['total_pages'];
}

function audit_log_change_pairs(?string $oldJson, ?string $newJson): array
{
    $old = $oldJson ? json_decode($oldJson, true) : [];
    $new = $newJson ? json_decode($newJson, true) : [];
    if (!is_array($old)) {
        $old = [];
    }
    if (!is_array($new)) {
        $new = [];
    }

    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    $pairs = [];
    foreach ($keys as $key) {
        $before = array_key_exists($key, $old) ? $old[$key] : null;
        $after = array_key_exists($key, $new) ? $new[$key] : null;
        if ($before === $after) {
            continue;
        }
        $pairs[] = [
            'field' => (string) $key,
            'before' => audit_log_value_text($before),
            'after' => audit_log_value_text($after),
        ];
    }

    return $pairs;
}

function audit_log_value_text($value): string
{
    if ($value === null) {
        return '<null>';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_scalar($value)) {
        return (string) $value;
    }
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function audit_log_change_text(?string $oldJson, ?string $newJson): string
{
    $parts = [];
    foreach (audit_log_change_pairs($oldJson, $newJson) as $pair) {
        $parts[] = $pair['field'] . ': ' . $pair['before'] . ' -> ' . $pair['after'];
    }
    return implode('; ', $parts);
}

function audit_log_action_badge(string $action): string
{
    $map = [
        'insert' => 'text-bg-success',
        'update' => 'text-bg-primary',
        'delete' => 'text-bg-danger',
        'login' => 'text-bg-success',
        'logout' => 'text-bg-secondary',
        'login_failed' => 'text-bg-danger',
    ];
    return $map[$action] ?? 'text-bg-dark';
}

function build_page_url(array $overrides = []): string
{
    $params = [
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? '',
        'user_id' => $_GET['user_id'] ?? '',
        'table_name' => $_GET['table_name'] ?? '',
        'module_name' => $_GET['module_name'] ?? '',
        'action' => $_GET['action'] ?? '',
        'q' => $_GET['q'] ?? '',
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h4 class="card-title mb-1">Audit Log</h4>
                        <div class="text-muted">Review system activity, master-data changes, sign-ins, and transaction history from one place.</div>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?php echo h(build_page_url(['export' => 'csv'])); ?>" class="btn btn-outline-primary">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                        <a href="<?php echo base_url('modules/audit_log/index.php'); ?>" class="btn btn-outline-secondary">
                            Reset
                        </a>
                    </div>
                </div>

                <?php if ($flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Audit Entries</div>
                            <div class="fs-3 fw-semibold"><?php echo number_format((int) $summary['total']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Activity Today</div>
                            <div class="fs-3 fw-semibold"><?php echo number_format((int) $summary['today']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Users In Log</div>
                            <div class="fs-3 fw-semibold"><?php echo number_format((int) $summary['users']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Modules Covered</div>
                            <div class="fs-3 fw-semibold"><?php echo number_format((int) $summary['modules']); ?></div>
                        </div>
                    </div>
                </div>

                <form method="get" class="border rounded-3 p-3 mb-4">
                    <div class="row g-3">
                        <div class="col-md-4 col-xl-3">
                            <label class="form-label">Search</label>
                            <input type="search" class="form-control" name="q" value="<?php echo h($search); ?>" placeholder="Record ID, description, action, user">
                        </div>
                        <div class="col-md-4 col-xl-2">
                            <label class="form-label">Action</label>
                            <select class="form-select" name="action">
                                <option value="">All actions</option>
                                <?php foreach ($actions as $action): ?>
                                    <option value="<?php echo h($action); ?>" <?php echo $filterAction === $action ? 'selected' : ''; ?>><?php echo h(ucfirst(str_replace('_', ' ', $action))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-xl-2">
                            <label class="form-label">Module</label>
                            <select class="form-select" name="module_name">
                                <option value="">All modules</option>
                                <?php foreach ($modules as $module): ?>
                                    <option value="<?php echo h($module); ?>" <?php echo $filterModule === $module ? 'selected' : ''; ?>><?php echo h($module); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-xl-2">
                            <label class="form-label">Table</label>
                            <select class="form-select" name="table_name">
                                <option value="">All tables</option>
                                <?php foreach ($tables as $table): ?>
                                    <option value="<?php echo h($table); ?>" <?php echo $filterTable === $table ? 'selected' : ''; ?>><?php echo h($table); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-xl-3">
                            <label class="form-label">User</label>
                            <select class="form-select" name="user_id">
                                <option value="">All users</option>
                                <?php foreach ($users as $user): ?>
                                    <?php $displayName = trim((string) (($user['full_name'] ?? '') !== '' ? $user['full_name'] : $user['username'])); ?>
                                    <option value="<?php echo (int) $user['id']; ?>" <?php echo $filterUser === (int) $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($displayName); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo h($startDate); ?>">
                        </div>
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo h($endDate); ?>">
                        </div>
                        <div class="col-md-6 col-xl-8 d-flex align-items-end gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-1"></i>Apply Filters
                            </button>
                            <a href="<?php echo base_url('modules/audit_log/index.php'); ?>" class="btn btn-outline-secondary">Clear</a>
                        </div>
                    </div>
                </form>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Activity Entries</h5>
                        <div class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo (int) ($total ?? 0); ?> record(s)</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;">When</th>
                                <th style="min-width: 160px;">User</th>
                                <th style="min-width: 140px;">Module</th>
                                <th style="min-width: 120px;">Action</th>
                                <th style="min-width: 180px;">Record</th>
                                <th style="min-width: 320px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $changes = audit_log_change_pairs($row['old_values'] ?? null, $row['new_values'] ?? null); ?>
                                    <?php $displayUser = trim((string) (($row['full_name'] ?? '') !== '' ? $row['full_name'] : ($row['username'] ?? 'System'))); ?>
                                    <tr>
                                        <td class="align-top">
                                            <div class="fw-semibold"><?php echo h(date('M d, Y', strtotime((string) $row['created_at']))); ?></div>
                                            <div class="text-muted small"><?php echo h(date('h:i A', strtotime((string) $row['created_at']))); ?></div>
                                        </td>
                                        <td class="align-top">
                                            <div class="fw-semibold"><?php echo h($displayUser !== '' ? $displayUser : 'System'); ?></div>
                                            <?php if (!empty($row['ip_address'])): ?>
                                                <div class="text-muted small"><?php echo h((string) $row['ip_address']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-top">
                                            <div class="fw-semibold"><?php echo h((string) ($row['module_name'] ?: $row['table_name'])); ?></div>
                                            <div class="text-muted small"><?php echo h((string) $row['table_name']); ?></div>
                                        </td>
                                        <td class="align-top">
                                            <span class="badge <?php echo audit_log_action_badge((string) $row['action']); ?>">
                                                <?php echo h(ucfirst(str_replace('_', ' ', (string) $row['action']))); ?>
                                            </span>
                                            <?php if (!empty($row['action_name'])): ?>
                                                <div class="text-muted small mt-1"><?php echo h((string) $row['action_name']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="align-top">
                                            <div class="fw-semibold"><?php echo h((string) ($row['record_type'] ?: 'record')); ?></div>
                                            <div class="text-muted small">ID: <?php echo h((string) ($row['record_id'] ?? '')); ?></div>
                                        </td>
                                        <td class="align-top">
                                            <?php if (!empty($row['description'])): ?>
                                                <div class="mb-2"><?php echo h((string) $row['description']); ?></div>
                                            <?php endif; ?>
                                            <?php if ($changes): ?>
                                                <details>
                                                    <summary class="small fw-semibold" style="cursor:pointer;">
                                                        View changes (<?php echo count($changes); ?>)
                                                    </summary>
                                                    <div class="mt-2 border rounded-2 p-2 bg-light">
                                                        <?php foreach ($changes as $change): ?>
                                                            <div class="mb-2">
                                                                <div class="fw-semibold small"><?php echo h($change['field']); ?></div>
                                                                <div class="small text-muted">From: <?php echo h($change['before']); ?></div>
                                                                <div class="small">To: <?php echo h($change['after']); ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            <?php else: ?>
                                                <span class="text-muted small">No field diff captured.</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-5">No audit records matched the current filters.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                    <div class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) ($totalPages ?? 1); ?></div>
                    <div class="d-flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo h(build_page_url(['page' => $page - 1])); ?>" class="btn btn-outline-secondary btn-sm">Previous</a>
                        <?php endif; ?>
                        <?php if ($page < (int) ($totalPages ?? 1)): ?>
                            <a href="<?php echo h(build_page_url(['page' => $page + 1])); ?>" class="btn btn-outline-secondary btn-sm">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
