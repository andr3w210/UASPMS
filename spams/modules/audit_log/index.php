<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';
require_role('Administrator');

$db = db();
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
$categorySummary = [
    'data_change' => 0,
    'access' => 0,
    'request' => 0,
    'auth' => 0,
    'other' => 0,
];

$startDate = trim((string) ($_GET['start_date'] ?? ''));
$endDate = trim((string) ($_GET['end_date'] ?? ''));
$datePreset = trim((string) ($_GET['date_preset'] ?? ''));
$filterUser = (int) ($_GET['user_id'] ?? 0);
$filterTable = trim((string) ($_GET['table_name'] ?? ''));
$filterModule = trim((string) ($_GET['module_name'] ?? ''));
$filterAction = trim((string) ($_GET['action'] ?? ''));
$filterCategory = array_key_exists('category', $_GET)
    ? trim((string) ($_GET['category'] ?? ''))
    : 'data_change';
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($datePreset !== '') {
    $today = date('Y-m-d');
    if ($datePreset === 'today') {
        $startDate = $today;
        $endDate = $today;
    } elseif ($datePreset === 'last_7_days') {
        $startDate = date('Y-m-d', strtotime('-6 days'));
        $endDate = $today;
    } elseif ($datePreset === 'this_month') {
        $startDate = date('Y-m-01');
        $endDate = $today;
    } else {
        $datePreset = '';
    }
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
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

    $summaryWhere = $where;
    $summaryParams = $params;
    $summaryTypes = $types;

    if ($filterCategory !== '') {
        [$categorySql, $categoryTypes, $categoryParams] = audit_log_category_filter_sql($filterCategory);
        if ($categorySql !== '') {
            $where[] = $categorySql;
            $types .= $categoryTypes;
            foreach ($categoryParams as $categoryParam) {
                $params[] = $categoryParam;
            }
        }
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

    $summaryWhereSql = $summaryWhere ? (' WHERE ' . implode(' AND ', $summaryWhere)) : '';
    $summarySql = "
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN DATE(al.created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            COUNT(DISTINCT COALESCE(al.user_id, 0)) AS user_count,
            COUNT(DISTINCT COALESCE(NULLIF(al.module_name, ''), al.table_name)) AS module_count,
            SUM(CASE WHEN al.action IN ('insert', 'update', 'delete') THEN 1 ELSE 0 END) AS data_change_count,
            SUM(CASE WHEN al.action = 'access' THEN 1 ELSE 0 END) AS access_count,
            SUM(CASE WHEN al.action = 'request' THEN 1 ELSE 0 END) AS request_count,
            SUM(CASE WHEN al.action IN ('login', 'logout', 'login_failed') THEN 1 ELSE 0 END) AS auth_count,
            SUM(CASE WHEN al.action NOT IN ('insert', 'update', 'delete', 'access', 'request', 'login', 'logout', 'login_failed') THEN 1 ELSE 0 END) AS other_count
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        $summaryWhereSql
    ";
    $summaryStmt = $db->prepare($summarySql);
    if ($summaryStmt) {
        if ($summaryTypes !== '') {
            $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
        }
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->get_result()->fetch_assoc();
        $summaryStmt->close();
        if ($summaryRow) {
            $summary = [
                'total' => (int) ($summaryRow['total_count'] ?? 0),
                'today' => (int) ($summaryRow['today_count'] ?? 0),
                'users' => (int) ($summaryRow['user_count'] ?? 0),
                'modules' => (int) ($summaryRow['module_count'] ?? 0),
            ];
            $categorySummary = [
                'data_change' => (int) ($summaryRow['data_change_count'] ?? 0),
                'access' => (int) ($summaryRow['access_count'] ?? 0),
                'request' => (int) ($summaryRow['request_count'] ?? 0),
                'auth' => (int) ($summaryRow['auth_count'] ?? 0),
                'other' => (int) ($summaryRow['other_count'] ?? 0),
            ];
        }
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
            $exportFilename = audit_log_export_filename($filterCategory, $datePreset, $startDate, $endDate);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
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

function audit_log_pretty_json(?string $json): string
{
    if ($json === null || trim($json) === '') {
        return '';
    }

    $decoded = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return trim($json);
    }

    $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($pretty) ? $pretty : trim($json);
}

function audit_log_action_badge(string $action): string
{
    $map = [
        'insert' => 'text-bg-success',
        'update' => 'text-bg-primary',
        'delete' => 'text-bg-danger',
        'access' => 'text-bg-info',
        'request' => 'text-bg-warning',
        'login' => 'text-bg-success',
        'logout' => 'text-bg-secondary',
        'login_failed' => 'text-bg-danger',
    ];
    return $map[$action] ?? 'text-bg-dark';
}

function audit_log_category_map(): array
{
    return [
        'data_change' => [
            'label' => 'Data Changes',
            'actions' => ['insert', 'update', 'delete'],
            'badge' => 'text-bg-primary',
        ],
        'access' => [
            'label' => 'Page Access',
            'actions' => ['access'],
            'badge' => 'text-bg-info',
        ],
        'request' => [
            'label' => 'Requests',
            'actions' => ['request'],
            'badge' => 'text-bg-warning',
        ],
        'auth' => [
            'label' => 'Authentication',
            'actions' => ['login', 'logout', 'login_failed'],
            'badge' => 'text-bg-success',
        ],
    ];
}

function audit_log_category_for_action(string $action): array
{
    foreach (audit_log_category_map() as $key => $category) {
        if (in_array($action, $category['actions'], true)) {
            return [
                'key' => $key,
                'label' => $category['label'],
                'badge' => $category['badge'],
            ];
        }
    }

    return [
        'key' => 'other',
        'label' => 'Other',
        'badge' => 'text-bg-dark',
    ];
}

function audit_log_category_filter_sql(string $category): array
{
    $map = audit_log_category_map();
    if (!isset($map[$category])) {
        return ['', '', []];
    }

    $actions = $map[$category]['actions'];
    if (!$actions) {
        return ['', '', []];
    }

    $placeholders = implode(',', array_fill(0, count($actions), '?'));
    return ["al.action IN ($placeholders)", str_repeat('s', count($actions)), $actions];
}

function audit_log_export_filename(
    string $category,
    string $datePreset,
    string $startDate,
    string $endDate
): string {
    $parts = ['audit_log'];
    $parts[] = $category !== ''
        ? (preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($category)) ?: 'category')
        : 'all_activity';

    if ($datePreset !== '') {
        $parts[] = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower($datePreset)) ?: 'date_preset';
    } elseif ($startDate !== '' || $endDate !== '') {
        $parts[] = 'from_' . ($startDate !== '' ? $startDate : 'start');
        $parts[] = 'to_' . ($endDate !== '' ? $endDate : 'today');
    } else {
        $parts[] = 'all_dates';
    }

    $parts[] = date('Y-m-d_H-i-s');

    return implode('_', array_filter($parts)) . '.csv';
}

function build_page_url(array $overrides = []): string
{
    $params = [
        'date_preset' => $_GET['date_preset'] ?? '',
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? '',
        'user_id' => $_GET['user_id'] ?? '',
        'table_name' => $_GET['table_name'] ?? '',
        'module_name' => $_GET['module_name'] ?? '',
        'action' => $_GET['action'] ?? '',
        'category' => $_GET['category'] ?? '',
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
<section class="row g-4 page-section">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="workspace-header mb-4">
                    <div class="workspace-header-copy">
                        <p class="page-kicker mb-1">Administration</p>
                        <h4 class="card-title mb-1">Audit Log</h4>
                        <div class="text-muted">Review system activity, master-data changes, sign-ins, and transaction history from one place.</div>
                    </div>
                    <div class="workspace-actions">
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

                <div class="row g-3 mb-4">
                    <?php foreach (audit_log_category_map() as $categoryKey => $category): ?>
                        <div class="col-md-6 col-xl-3">
                            <a href="<?php echo h(build_page_url(['category' => $categoryKey, 'page' => 1])); ?>" class="text-decoration-none text-reset">
                                <div class="border rounded-3 p-3 h-100 <?php echo $filterCategory === $categoryKey ? 'bg-light border-primary' : 'bg-white'; ?>">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <div class="text-muted small"><?php echo h($category['label']); ?></div>
                                        <span class="badge <?php echo h($category['badge']); ?>"><?php echo h($category['label']); ?></span>
                                    </div>
                                    <div class="fs-4 fw-semibold"><?php echo number_format((int) ($categorySummary[$categoryKey] ?? 0)); ?></div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="get" class="workspace-filter-panel mb-4">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <a href="<?php echo h(build_page_url(['date_preset' => 'today', 'start_date' => '', 'end_date' => '', 'page' => 1])); ?>" class="btn btn-sm <?php echo $datePreset === 'today' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Today</a>
                        <a href="<?php echo h(build_page_url(['date_preset' => 'last_7_days', 'start_date' => '', 'end_date' => '', 'page' => 1])); ?>" class="btn btn-sm <?php echo $datePreset === 'last_7_days' ? 'btn-primary' : 'btn-outline-secondary'; ?>">Last 7 Days</a>
                        <a href="<?php echo h(build_page_url(['date_preset' => 'this_month', 'start_date' => '', 'end_date' => '', 'page' => 1])); ?>" class="btn btn-sm <?php echo $datePreset === 'this_month' ? 'btn-primary' : 'btn-outline-secondary'; ?>">This Month</a>
                        <a href="<?php echo h(build_page_url(['date_preset' => '', 'start_date' => '', 'end_date' => '', 'page' => 1])); ?>" class="btn btn-sm <?php echo $datePreset === '' && $startDate === '' && $endDate === '' ? 'btn-dark' : 'btn-outline-dark'; ?>">All Dates</a>
                    </div>
                    <div class="row g-3 align-items-end">
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
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="">All categories</option>
                                <?php foreach (audit_log_category_map() as $categoryKey => $category): ?>
                                    <option value="<?php echo h($categoryKey); ?>" <?php echo $filterCategory === $categoryKey ? 'selected' : ''; ?>><?php echo h($category['label']); ?></option>
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
                        <div class="col-md-4 col-xl-1">
                            <label class="form-label">Table</label>
                            <select class="form-select" name="table_name">
                                <option value="">All tables</option>
                                <?php foreach ($tables as $table): ?>
                                    <option value="<?php echo h($table); ?>" <?php echo $filterTable === $table ? 'selected' : ''; ?>><?php echo h($table); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 col-xl-2">
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
                            <input type="hidden" name="date_preset" value="">
                            <input type="date" class="form-control" name="start_date" value="<?php echo h($startDate); ?>">
                        </div>
                        <div class="col-md-3 col-xl-2">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo h($endDate); ?>">
                        </div>
                        <div class="col-md-6 col-xl-8">
                            <div class="d-grid gap-2 d-sm-flex justify-content-xl-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-1"></i>Apply Filters
                            </button>
                            <a href="<?php echo base_url('modules/audit_log/index.php'); ?>" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="workspace-header mb-3">
                    <div class="workspace-header-copy">
                        <h5 class="card-title mb-0">Activity Entries</h5>
                        <div class="text-muted small">Showing <?php echo count($rows); ?> of <?php echo (int) ($total ?? 0); ?> record(s)</div>
                    </div>
                </div>

                <div class="table-responsive mobile-table-frame">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;" data-sort="when">When <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th style="min-width: 160px;" data-sort="user">User <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th style="min-width: 140px;" data-sort="module">Module <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th style="min-width: 120px;" data-sort="action">Action <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th style="min-width: 180px;" data-sort="record">Record <i class="bi bi-arrow-down-up text-muted small"></i></th>
                                <th style="min-width: 320px;">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php $changes = audit_log_change_pairs($row['old_values'] ?? null, $row['new_values'] ?? null); ?>
                                    <?php $category = audit_log_category_for_action((string) $row['action']); ?>
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
                                            <div class="mb-1">
                                                <span class="badge <?php echo h($category['badge']); ?>">
                                                    <?php echo h($category['label']); ?>
                                                </span>
                                            </div>
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
                                            <?php
                                                $oldPayload = audit_log_pretty_json($row['old_values'] ?? null);
                                                $newPayload = audit_log_pretty_json($row['new_values'] ?? null);
                                            ?>
                                            <?php if (!empty($row['description'])): ?>
                                                <div class="mb-2"><?php echo h((string) $row['description']); ?></div>
                                            <?php endif; ?>
                                            <details>
                                                <summary class="small fw-semibold" style="cursor:pointer;">
                                                    <?php if ($changes): ?>
                                                        View details (<?php echo count($changes); ?> field change<?php echo count($changes) === 1 ? '' : 's'; ?>)
                                                    <?php elseif ($oldPayload !== '' || $newPayload !== ''): ?>
                                                        View payload
                                                    <?php else: ?>
                                                        View entry details
                                                    <?php endif; ?>
                                                </summary>
                                                <div class="mt-2 border rounded-2 p-3 bg-light">
                                                    <div class="row g-2 mb-3">
                                                        <div class="col-md-4">
                                                            <div class="small text-muted">Category</div>
                                                            <div class="fw-semibold small"><?php echo h($category['label']); ?></div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="small text-muted">Action Name</div>
                                                            <div class="small"><?php echo h((string) ($row['action_name'] ?? '')); ?></div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="small text-muted">IP Address</div>
                                                            <div class="small"><?php echo h((string) ($row['ip_address'] ?? '')); ?></div>
                                                        </div>
                                                    </div>

                                                    <?php if ($changes): ?>
                                                        <div class="mb-3">
                                                            <div class="fw-semibold small mb-2">Field Changes</div>
                                                            <?php foreach ($changes as $change): ?>
                                                                <div class="border rounded-2 bg-white p-2 mb-2">
                                                                    <div class="fw-semibold small mb-1"><?php echo h($change['field']); ?></div>
                                                                    <div class="small text-muted">Before: <?php echo h($change['before']); ?></div>
                                                                    <div class="small">After: <?php echo h($change['after']); ?></div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if ($oldPayload !== '' || $newPayload !== ''): ?>
                                                        <div class="row g-3">
                                                            <?php if ($oldPayload !== ''): ?>
                                                                <div class="col-lg-6">
                                                                    <div class="fw-semibold small mb-1">Before Payload</div>
                                                                    <pre class="small bg-white border rounded-2 p-2 mb-0" style="white-space:pre-wrap; word-break:break-word;"><?php echo h($oldPayload); ?></pre>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if ($newPayload !== ''): ?>
                                                                <div class="col-lg-6">
                                                                    <div class="fw-semibold small mb-1">After Payload</div>
                                                                    <pre class="small bg-white border rounded-2 p-2 mb-0" style="white-space:pre-wrap; word-break:break-word;"><?php echo h($newPayload); ?></pre>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif (!$changes): ?>
                                                        <span class="text-muted small">No field diff or payload captured for this entry.</span>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
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

                <div class="workspace-header mt-3">
                    <div class="workspace-header-copy">
                        <div class="text-muted small">Page <?php echo (int) $page; ?> of <?php echo (int) ($totalPages ?? 1); ?></div>
                    </div>
                    <div class="workspace-actions">
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
