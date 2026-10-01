<?php
/**
 * Read-only audit: list offices with more than one active unit-head assignment.
 * Each conflict must be resolved manually via Settings > Assignment Cleanup,
 * since choosing which person keeps headship is a business decision.
 */

require_once __DIR__ . '/../bootstrap.php';
$m = tools_db();
if ($m->connect_error) die("Connection failed\n");

$sql = "SELECT o.id AS office_id, o.office_name, ea.id AS assignment_id, ea.employee_id,
               e.first_name, e.last_name, ea.role_title, ea.is_oic
        FROM employee_assignments ea
        INNER JOIN offices o ON o.id = ea.office_id
        INNER JOIN employees e ON e.id = ea.employee_id
        WHERE ea.is_active = 1 AND ea.is_unit_head = 1
          AND ea.office_id IN (
              SELECT office_id FROM employee_assignments
              WHERE is_active = 1 AND is_unit_head = 1
              GROUP BY office_id HAVING COUNT(*) > 1
          )
        ORDER BY o.office_name, ea.is_oic ASC, ea.id ASC";

$result = $m->query($sql);
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

if (!$rows) {
    echo "No offices with more than one active unit head found.\n";
    exit(0);
}

$currentOffice = null;
foreach ($rows as $row) {
    if ($currentOffice !== $row['office_name']) {
        $currentOffice = $row['office_name'];
        echo PHP_EOL . "Office: {$currentOffice} (id {$row['office_id']})" . PHP_EOL;
    }
    $flag = $row['is_oic'] ? ' [OIC]' : '';
    echo "  - assignment #{$row['assignment_id']}: {$row['first_name']} {$row['last_name']} ({$row['role_title']}){$flag}" . PHP_EOL;
}

echo PHP_EOL . 'Resolve each via Settings > Assignment Cleanup: pick the assignment that should' . PHP_EOL
    . 'remain head and click "Keep as Unit Head"; it automatically clears the others.' . PHP_EOL;
