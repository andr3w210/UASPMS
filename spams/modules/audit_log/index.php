<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db_connect();
$page_title = 'Audit Log';
$flash = get_flash();
$errors = [];

$users = [];
$tables = [];

if ($db) {
    $userRes = $db->query("SELECT id, username FROM users ORDER BY username ASC");
    if ($userRes) {
        $users = $userRes->fetch_all(MYSQLI_ASSOC);
    }

    $tableRes = $db->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name ASC");
    if ($tableRes) {
        $tables = array_map(function($r) { return $r['table_name']; }, $tableRes->fetch_all(MYSQLI_ASSOC));
    }

    // Filters
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));
    $filterUser = (int) ($_GET['user_id'] ?? 0);
    $filterTable = trim((string) ($_GET['table_name'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $where = '';
    $params = [];
    $types = '';

    if ($startDate !== '') {
        $where .= " AND created_at >= ?";
        $types .= 's';
        $params[] = $startDate . ' 00:00:00';
    }

    if ($endDate !== '') {
        $where .= " AND created_at <= ?";
        $types .= 's';
        $params[] = $endDate . ' 23:59:59';
    }

    if ($filterUser > 0) {
        $where .= " AND user_id = ?";
        $types .= 'i';
        $params[] = $filterUser;
    }

    if ($filterTable !== '') {
        $where .= " AND table_name = ?";
        $types .= 's';
        $params[] = $filterTable;
    }

    $countSql = "SELECT COUNT(*) AS total FROM audit_logs WHERE 1=1" . $where;
    $dataSql = "SELECT al.id, al.user_id, al.action, al.table_name, al.record_id, al.old_values, al.new_values, al.created_at, u.username FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1" . $where . " ORDER BY al.created_at DESC, al.id DESC";

    // Export CSV if requested
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        // bind helper
        $bindToStmt = function (mysqli_stmt $stmt, string $types, array $values): void {
            if ($types === '') return;
            $bindValues = $values;
            $refs = [];
            foreach ($bindValues as $key => $val) {
                $refs[$key] = &$bindValues[$key];
            }
            array_unshift($refs, $types);
            call_user_func_array([$stmt, 'bind_param'], $refs);
        };

        $exportStmt = $db->prepare($dataSql);
        if ($exportStmt) {
            if ($types !== '') {
                $bindToStmt($exportStmt, $types, $params);
            }
            $exportStmt->execute();
            $res = $exportStmt->get_result();

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_log_export.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'User', 'Action', 'Table', 'Record ID', 'Changes']);
            while ($row = $res->fetch_assoc()) {
                $changes = '';
                $old = $row['old_values'] ? json_decode($row['old_values'], true) : [];
                $new = $row['new_values'] ? json_decode($row['new_values'], true) : [];
                if (!is_array($old)) $old = [];
                if (!is_array($new)) $new = [];
                $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
                $lines = [];
                foreach ($keys as $k) {
                    $o = array_key_exists($k, $old) ? $old[$k] : null;
                    $n = array_key_exists($k, $new) ? $new[$k] : null;
                    if ($o === $n) continue;
                    $oText = $o === null ? '<null>' : (is_scalar($o) ? (string) $o : json_encode($o));
                    $nText = $n === null ? '<null>' : (is_scalar($n) ? (string) $n : json_encode($n));
                    $lines[] = $k . ': ' . $oText . ' -> ' . $nText;
                }
                if ($lines) $changes = implode("; ", $lines);

                fputcsv($out, [date('Y-m-d H:i:s', strtotime($row['created_at'])), $row['username'] ?? 'System', $row['action'], $row['table_name'], $row['record_id'], $changes]);
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
} else {
    $errors[] = 'Unable to connect to the database.';
    $rows = [];
    $total = 0;
    $totalPages = 0;
}

function render_diff_display(?string $oldJson, ?string $newJson): string
{
    $old = $oldJson ? json_decode($oldJson, true) : [];
    $new = $newJson ? json_decode($newJson, true) : [];
    if (!is_array($old)) $old = [];
    if (!is_array($new)) $new = [];

    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
    $lines = [];
    foreach ($keys as $k) {
        $o = array_key_exists($k, $old) ? $old[$k] : null;
        $n = array_key_exists($k, $new) ? $new[$k] : null;
        if ($o === $n) {
            continue;
        }
        $oText = $o === null ? '<null>' : (is_scalar($o) ? (string) $o : json_encode($o));
        $nText = $n === null ? '<null>' : (is_scalar($n) ? (string) $n : json_encode($n));
        $lines[] = $k . ': ' . $oText . ' → ' . $nText;
    }

    if (!$lines) {
        return '';
    }

    return '<pre style="margin:0;padding:4px;white-space:pre-wrap;">' . h(implode("\n", $lines)) . '</pre>';
}

function build_page_url(array $overrides = []): string
{
    $params = [
        'start_date' => $_GET['start_date'] ?? '',
        'end_date' => $_GET['end_date'] ?? '',
        'user_id' => $_GET['user_id'] ?? '',
        'table_name' => $_GET['table_name'] ?? '',
    ];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    return '?' . http_build_query(array_filter($params, function($v) { return $v !== ''; }));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section style="padding:16px;">
    <h4>Audit Log</h4>
    <?php if (!empty($errors)): ?><div style="color:#b00020;margin-bottom:12px;"><?php foreach ($errors as $e): ?><div><?php echo h($e); ?></div><?php endforeach; ?></div><?php endif; ?>

    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <div><label style="display:block;font-size:12px;">Start Date</label><input type="date" name="start_date" value="<?php echo h($_GET['start_date'] ?? ''); ?>"></div>
        <div><label style="display:block;font-size:12px;">End Date</label><input type="date" name="end_date" value="<?php echo h($_GET['end_date'] ?? ''); ?>"></div>
        <div>
            <label style="display:block;font-size:12px;">User</label>
            <select name="user_id">
                <option value="">All users</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int) $u['id']; ?>" <?php echo (isset($_GET['user_id']) && (int) $_GET['user_id'] === (int) $u['id']) ? 'selected' : ''; ?>><?php echo h($u['username']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;">Table</label>
            <select name="table_name">
                <option value="">All tables</option>
                <?php foreach ($tables as $t): ?>
                    <option value="<?php echo h($t); ?>" <?php echo (isset($_GET['table_name']) && $_GET['table_name'] === $t) ? 'selected' : ''; ?>><?php echo h($t); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self:end;display:flex;gap:8px;">
            <button type="submit" style="padding:6px 10px;">Filter</button>
            <a href="<?php echo build_page_url(['export' => 'csv']); ?>" style="display:inline-block;padding:6px 10px;border:1px solid #ccc;border-radius:4px;text-decoration:none;">Export CSV</a>
        </div>
    </form>

    <div style="margin-bottom:8px;font-size:13px;color:#333;">Showing <?php echo h((string) count($rows)); ?> of <?php echo h((string) $total); ?> record(s)</div>

    <table style="width:100%;border-collapse:collapse;border:1px solid #ddd;">
        <thead>
            <tr style="background:#f5f5f5;text-align:left;">
                <th style="padding:8px;border-bottom:1px solid #ddd;">When</th>
                <th style="padding:8px;border-bottom:1px solid #ddd;">User</th>
                <th style="padding:8px;border-bottom:1px solid #ddd;">Action</th>
                <th style="padding:8px;border-bottom:1px solid #ddd;">Table</th>
                <th style="padding:8px;border-bottom:1px solid #ddd;">Record ID</th>
                <th style="padding:8px;border-bottom:1px solid #ddd;">Changes</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows): ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;white-space:nowrap"><?php echo h(date('M d, Y H:i', strtotime($r['created_at']))); ?></td>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top"><?php echo h($r['username'] ?? 'System'); ?></td>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top"><?php echo h($r['action']); ?></td>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top"><?php echo h($r['table_name']); ?></td>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top"><?php echo h((string) $r['record_id']); ?></td>
                        <td style="padding:8px;border-top:1px solid #eee;vertical-align:top;max-width:480px;">
                            <?php echo render_diff_display($r['old_values'] ?? null, $r['new_values'] ?? null); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" style="padding:20px;text-align:center;color:#666;">No audit records found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
        <div>Page <?php echo h((string) $page); ?> of <?php echo h((string) $totalPages); ?></div>
        <div style="display:flex;gap:8px;">
            <?php if ($page > 1): ?>
                <a href="<?php echo build_page_url(['page' => $page - 1]); ?>" style="text-decoration:none;padding:6px 10px;border:1px solid #ccc;border-radius:4px;">&laquo; Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?php echo build_page_url(['page' => $page + 1]); ?>" style="text-decoration:none;padding:6px 10px;border:1px solid #ccc;border-radius:4px;">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
