<?php
/**
 * Refresh employees.position_title cache so OIC assignments print as
 * "OIC, <role title>" instead of the bare role title (e.g. "Principal").
 *
 * Dry-run by default. Use --apply to persist changes.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/employee_assignments.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$apply = in_array('--apply', $argv, true);
$db = tools_db();

if (!$apply) {
    echo "Dry-run only. Re-run with --apply to persist position_title updates." . PHP_EOL;
}

if (!employee_assignments_enabled($db)) {
    echo "employee_assignments table not found; nothing to backfill." . PHP_EOL;
    exit(0);
}

$result = $db->query("SELECT DISTINCT employee_id FROM employee_assignments WHERE is_active = 1");
$employeeIds = $result ? array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'employee_id')) : [];

$changed = 0;
$checked = 0;

foreach ($employeeIds as $employeeId) {
    $primary = employee_fetch_primary_assignment($db, $employeeId);
    if (!$primary || empty($primary['is_oic'])) {
        continue;
    }

    $checked++;
    $expectedTitle = employee_format_title_with_oic((string) ($primary['role_title'] ?? ''), true);

    $currentStmt = $db->prepare('SELECT position_title FROM employees WHERE id = ? LIMIT 1');
    $currentStmt->bind_param('i', $employeeId);
    $currentStmt->execute();
    $current = (string) ($currentStmt->get_result()->fetch_assoc()['position_title'] ?? '');
    $currentStmt->close();

    if ($current === $expectedTitle) {
        continue;
    }

    echo "Employee #{$employeeId}: '{$current}' -> '{$expectedTitle}'" . PHP_EOL;
    $changed++;

    if ($apply) {
        employee_sync_legacy_assignment_fields($db, $employeeId);
    }
}

echo "Checked {$checked} employee(s) currently flagged OIC on their primary assignment; " . ($apply ? 'updated' : 'would update') . " {$changed}." . PHP_EOL;
