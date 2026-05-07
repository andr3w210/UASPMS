<?php

require_once __DIR__ . '/../bootstrap.php';
$db = tools_db();
if ($db->connect_error) {
    fwrite(STDERR, "Connection failed: {$db->connect_error}\n");
    exit(1);
}

$officeAliasMap = [
    'supply' => 31,
    'cas' => 38,
    'cea 04' => 32,
    'cea' => 32,
    'le' => 42,
];

$employeeLastNameMap = [
    'bangcaya' => 39,
    'cortejo' => 20,
];

$selectSql = "
    SELECT id, property_number, remarks, office_id, employee_id
    FROM legacy_assets
    WHERE is_active = 1
      AND system_reference IS NOT NULL
      AND system_reference NOT LIKE 'RPCPPE%'
      AND remarks IS NOT NULL
      AND TRIM(remarks) <> ''
      AND (office_id IS NULL OR employee_id IS NULL)
    ORDER BY id
";

$rows = $db->query($selectSql)->fetch_all(MYSQLI_ASSOC);

$updateStmt = $db->prepare("
    UPDATE legacy_assets
    SET office_id = CASE WHEN office_id IS NULL AND ? > 0 THEN ? ELSE office_id END,
        employee_id = CASE WHEN employee_id IS NULL AND ? > 0 THEN ? ELSE employee_id END,
        updated_at = NOW()
    WHERE id = ?
");

$applied = [];
$skipped = [];

foreach ($rows as $row) {
    $remarks = strtolower(trim((string) ($row['remarks'] ?? '')));
    $officeId = 0;
    $employeeId = 0;

    foreach ($officeAliasMap as $alias => $mappedOfficeId) {
        if (str_contains($remarks, $alias)) {
            $officeId = $mappedOfficeId;
            break;
        }
    }

    if (preg_match('/(?:\/|-)\s*([a-zñ]+)\s*$/iu', (string) $row['remarks'], $m)) {
        $lastName = strtolower(trim((string) $m[1]));
        if (isset($employeeLastNameMap[$lastName])) {
            $employeeId = $employeeLastNameMap[$lastName];
        }
    }

    if ($officeId <= 0 && $employeeId <= 0) {
        $skipped[] = [
            'id' => (int) $row['id'],
            'property_number' => $row['property_number'],
            'remarks' => $row['remarks'],
            'reason' => 'No high-confidence office/employee match',
        ];
        continue;
    }

    $updateStmt->bind_param('iiiii', $officeId, $officeId, $employeeId, $employeeId, $row['id']);
    $ok = $updateStmt->execute();
    if ($ok) {
        $applied[] = [
            'id' => (int) $row['id'],
            'property_number' => $row['property_number'],
            'remarks' => $row['remarks'],
            'office_id' => $officeId,
            'employee_id' => $employeeId,
        ];
    }
}

$updateStmt->close();

echo "Legacy assignment backfill from remarks\n";
echo "=====================================\n";
echo "Applied: " . count($applied) . "\n";
foreach ($applied as $row) {
    echo "- ID {$row['id']} {$row['property_number']} | office_id=" . ($row['office_id'] ?: 'unchanged') . " | employee_id=" . ($row['employee_id'] ?: 'unchanged') . " | {$row['remarks']}\n";
}

echo "\nSkipped: " . count($skipped) . "\n";
foreach ($skipped as $row) {
    echo "- ID {$row['id']} {$row['property_number']} | {$row['remarks']} | {$row['reason']}\n";
}

$db->close();
