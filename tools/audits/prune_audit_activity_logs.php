<?php
require_once __DIR__ . '/../bootstrap.php';

$days = 90;
$apply = false;
$route = '';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
        continue;
    }

    if (preg_match('/^--days=(\d+)$/', $arg, $matches)) {
        $days = max(1, (int) $matches[1]);
        continue;
    }

    if (preg_match('/^--route=(.+)$/', $arg, $matches)) {
        $route = trim((string) $matches[1]);
        continue;
    }

    fwrite(STDERR, "Unknown option: {$arg}\n");
    fwrite(STDERR, "Usage: php tools/audits/prune_audit_activity_logs.php [--days=90] [--route=modules/messages/poll.php] [--apply]\n");
    exit(1);
}

$db = tools_db();
$hasRoutePathColumn = false;
$columnStmt = $db->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_logs'
      AND COLUMN_NAME = 'route_path'
    LIMIT 1
");
if ($columnStmt) {
    $columnStmt->execute();
    $hasRoutePathColumn = (bool) $columnStmt->get_result()->fetch_assoc();
    $columnStmt->close();
}

$cutoff = (new DateTimeImmutable('now'))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s');

$where = "action IN ('access', 'request') AND created_at < ?";
$types = 's';
$params = [$cutoff];

if ($route !== '') {
    if ($hasRoutePathColumn) {
        $where .= " AND (route_path = ? OR (COALESCE(route_path, '') = '' AND JSON_VALID(new_values) AND JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.route')) = ?))";
        $types .= 'ss';
        $params[] = $route;
        $params[] = $route;
    } else {
        $where .= " AND JSON_VALID(new_values) AND JSON_UNQUOTE(JSON_EXTRACT(new_values, '$.route')) = ?";
        $types .= 's';
        $params[] = $route;
    }
}

$countSql = "SELECT COUNT(*) AS total FROM audit_logs WHERE {$where}";
$countStmt = $db->prepare($countSql);
if (!$countStmt) {
    fwrite(STDERR, 'Unable to prepare count query: ' . $db->error . PHP_EOL);
    exit(1);
}
$countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

echo 'Cutoff: ' . $cutoff . PHP_EOL;
echo 'Scope: access/request audit activity';
if ($route !== '') {
    echo ' for route ' . $route;
}
echo PHP_EOL;
echo 'Matched rows: ' . $total . PHP_EOL;

if (!$apply) {
    echo 'Dry run only. Add --apply to delete matched low-value activity rows.' . PHP_EOL;
    exit(0);
}

$deleteSql = "DELETE FROM audit_logs WHERE {$where}";
$deleteStmt = $db->prepare($deleteSql);
if (!$deleteStmt) {
    fwrite(STDERR, 'Unable to prepare delete query: ' . $db->error . PHP_EOL);
    exit(1);
}
$deleteStmt->bind_param($types, ...$params);
$deleteStmt->execute();
$deleted = $deleteStmt->affected_rows;
$deleteStmt->close();

echo 'Deleted rows: ' . $deleted . PHP_EOL;
