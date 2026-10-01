<?php
/**
 * Backfill: an OIC assignment now always implies acting headship (see
 * employee_save_assignments_unlocked()). Existing rows saved before that rule
 * (is_oic=1 but is_unit_head=0) are promoted here, demoting any other active
 * unit head in the same office and syncing offices.office_head_employee_id.
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
    echo "Dry-run only. Re-run with --apply to persist unit-head promotions." . PHP_EOL;
}

$result = $db->query(
    "SELECT ea.id, ea.employee_id, ea.office_id, ea.role_title, o.office_name,
            e.first_name, e.last_name
     FROM employee_assignments ea
     INNER JOIN offices o ON o.id = ea.office_id
     INNER JOIN employees e ON e.id = ea.employee_id
     WHERE ea.is_active = 1 AND ea.is_oic = 1 AND ea.is_unit_head = 0"
);
$candidates = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

if (!$candidates) {
    echo "No OIC assignments are missing the unit-head flag." . PHP_EOL;
    exit(0);
}

foreach ($candidates as $row) {
    $assignmentId = (int) $row['id'];
    $employeeId = (int) $row['employee_id'];
    $officeId = (int) $row['office_id'];

    echo "Office '{$row['office_name']}': promoting {$row['first_name']} {$row['last_name']} ({$row['role_title']}, OIC) to unit head, assignment #{$assignmentId}" . PHP_EOL;

    if (!$apply) {
        continue;
    }

    $db->begin_transaction();
    try {
        $clear = $db->prepare('UPDATE employee_assignments SET is_unit_head = 0 WHERE office_id = ? AND is_active = 1');
        $clear->bind_param('i', $officeId);
        $clear->execute();
        $clear->close();

        $set = $db->prepare('UPDATE employee_assignments SET is_unit_head = 1 WHERE id = ? AND employee_id = ?');
        $set->bind_param('ii', $assignmentId, $employeeId);
        $set->execute();
        $set->close();

        $officeStmt = $db->prepare('UPDATE offices SET office_head_employee_id = ? WHERE id = ?');
        $officeStmt->bind_param('ii', $employeeId, $officeId);
        $officeStmt->execute();
        $officeStmt->close();

        employee_sync_legacy_assignment_fields($db, $employeeId);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        fwrite(STDERR, "Failed for assignment #{$assignmentId}: " . $e->getMessage() . PHP_EOL);
    }
}

echo 'Done.' . PHP_EOL;
