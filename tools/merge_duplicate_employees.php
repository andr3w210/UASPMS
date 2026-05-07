<?php
require_once __DIR__ . '/../spams/app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$verbose = in_array('--verbose', $argv, true);

function str_norm(?string $value): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_strtolower($value);
}

function employee_group_key(array $row): string
{
    return implode('|', [
        str_norm($row['first_name'] ?? ''),
        str_norm($row['middle_name'] ?? ''),
        str_norm($row['last_name'] ?? ''),
        str_norm($row['suffix_name'] ?? ''),
    ]);
}

function pick_keeper(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $aActive = (int) ($a['is_active'] ?? 0);
        $bActive = (int) ($b['is_active'] ?? 0);
        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }

        $aEmail = trim((string) ($a['email'] ?? '')) !== '' ? 1 : 0;
        $bEmail = trim((string) ($b['email'] ?? '')) !== '' ? 1 : 0;
        if ($aEmail !== $bEmail) {
            return $bEmail <=> $aEmail;
        }

        $aNo = trim((string) ($a['employee_no'] ?? '')) !== '' ? 1 : 0;
        $bNo = trim((string) ($b['employee_no'] ?? '')) !== '' ? 1 : 0;
        if ($aNo !== $bNo) {
            return $bNo <=> $aNo;
        }

        return ((int) $a['id']) <=> ((int) $b['id']);
    });

    return $rows[0];
}

function table_has_column(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists;
}

$refTargets = [];
$targetColumns = [
    'employee_id',
    'office_head_employee_id',
    'current_employee_id',
    'source_employee_id',
    'to_employee_id',
    'driver_employee_id',
];

$in = implode("','", array_map(static fn($c) => $db->real_escape_string($c), $targetColumns));
$refSql = "SELECT TABLE_NAME, COLUMN_NAME
           FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND COLUMN_NAME IN ('{$in}')";
$refRes = $db->query($refSql);
if ($refRes instanceof mysqli_result) {
    while ($r = $refRes->fetch_assoc()) {
        $table = (string) ($r['TABLE_NAME'] ?? '');
        $column = (string) ($r['COLUMN_NAME'] ?? '');
        if ($table === '' || $column === '' || $table === 'employees') {
            continue;
        }
        $refTargets[] = ['table' => $table, 'column' => $column];
    }
    $refRes->free();
}

$empRes = $db->query("SELECT id, employee_no, name_prefix, first_name, middle_name, last_name, suffix_name, email, office_id, responsibility_code_id, position_title, is_active FROM employees ORDER BY id ASC");
if (!$empRes instanceof mysqli_result) {
    fwrite(STDERR, "Unable to load employees.\n");
    exit(1);
}

$groups = [];
while ($row = $empRes->fetch_assoc()) {
    $key = employee_group_key($row);
    if ($key === '||||') {
        continue;
    }
    $groups[$key][] = $row;
}
$empRes->free();

$duplicateGroups = array_filter($groups, static fn($rows) => count($rows) > 1);
if (!$duplicateGroups) {
    echo "No duplicate employee groups found.\n";
    exit(0);
}

$mergePlan = [];
foreach ($duplicateGroups as $key => $rows) {
    $keeper = pick_keeper($rows);
    $keeperId = (int) $keeper['id'];
    $dupes = array_values(array_filter($rows, static fn($r) => (int) $r['id'] !== $keeperId));
    $mergePlan[] = [
        'key' => $key,
        'keeper' => $keeper,
        'duplicates' => $dupes,
    ];
}

$totalDuplicates = 0;
foreach ($mergePlan as $plan) {
    $totalDuplicates += count($plan['duplicates']);
}

echo "Duplicate groups found: " . count($mergePlan) . "\n";
echo "Duplicate employee records to merge: " . $totalDuplicates . "\n";

foreach ($mergePlan as $index => $plan) {
    $k = $plan['keeper'];
    $name = trim(implode(' ', array_filter([
        $k['name_prefix'] ?? '',
        $k['first_name'] ?? '',
        $k['middle_name'] ?? '',
        $k['last_name'] ?? '',
        $k['suffix_name'] ?? '',
    ])));
    echo sprintf(
        "%d) KEEP id=%d (%s | %s) <- MERGE [%s]\n",
        $index + 1,
        (int) $k['id'],
        (string) ($k['employee_no'] ?? ''),
        $name,
        implode(', ', array_map(static function ($d): string {
            return (int) $d['id'] . ':' . (string) ($d['employee_no'] ?? '');
        }, $plan['duplicates']))
    );
}

if (!$apply) {
    echo "Dry run only. Re-run with --apply to execute merge.\n";
    exit(0);
}

$db->begin_transaction();

try {
    foreach ($mergePlan as $plan) {
        $keeper = $plan['keeper'];
        $keeperId = (int) $keeper['id'];

        foreach ($plan['duplicates'] as $dup) {
            $dupId = (int) $dup['id'];

            if (table_has_column($db, 'employee_assignments', 'employee_id')) {
                // Move assignments; dedupe equivalent office+role pairs already present in keeper.
                $dedupeSql = "DELETE ea_dup
                              FROM employee_assignments ea_dup
                              INNER JOIN employee_assignments ea_keep
                                      ON ea_keep.employee_id = ?
                                     AND ea_keep.office_id <=> ea_dup.office_id
                                     AND ea_keep.responsibility_code_id <=> ea_dup.responsibility_code_id
                                     AND TRIM(LOWER(COALESCE(ea_keep.role_title, ''))) = TRIM(LOWER(COALESCE(ea_dup.role_title, '')))
                              WHERE ea_dup.employee_id = ?";
                $dedupeStmt = $db->prepare($dedupeSql);
                if ($dedupeStmt) {
                    $dedupeStmt->bind_param('ii', $keeperId, $dupId);
                    $dedupeStmt->execute();
                    $dedupeStmt->close();
                }

                $moveAssignStmt = $db->prepare("UPDATE employee_assignments SET employee_id = ? WHERE employee_id = ?");
                if ($moveAssignStmt) {
                    $moveAssignStmt->bind_param('ii', $keeperId, $dupId);
                    $moveAssignStmt->execute();
                    $moveAssignStmt->close();
                }
            }

            foreach ($refTargets as $target) {
                $table = str_replace('`', '``', $target['table']);
                $column = str_replace('`', '``', $target['column']);
                $sql = "UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?";
                $stmt = $db->prepare($sql);
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('ii', $keeperId, $dupId);
                $stmt->execute();
                $stmt->close();
            }

            // Merge selected nullable profile fields if keeper lacks them.
            $fillStmt = $db->prepare(
                "UPDATE employees k
                 JOIN employees d ON d.id = ?
                 SET
                    k.email = CASE WHEN (k.email IS NULL OR k.email = '') THEN d.email ELSE k.email END,
                    k.office_id = CASE WHEN k.office_id IS NULL THEN d.office_id ELSE k.office_id END,
                    k.responsibility_code_id = CASE WHEN k.responsibility_code_id IS NULL THEN d.responsibility_code_id ELSE k.responsibility_code_id END,
                    k.position_title = CASE WHEN (k.position_title IS NULL OR k.position_title = '') THEN d.position_title ELSE k.position_title END,
                    k.is_unit_head = CASE WHEN k.is_unit_head = 0 THEN d.is_unit_head ELSE k.is_unit_head END,
                    k.is_active = CASE WHEN k.is_active = 0 THEN d.is_active ELSE k.is_active END
                 WHERE k.id = ?"
            );
            if ($fillStmt) {
                $fillStmt->bind_param('ii', $dupId, $keeperId);
                $fillStmt->execute();
                $fillStmt->close();
            }

            $deleteStmt = $db->prepare("DELETE FROM employees WHERE id = ? LIMIT 1");
            if (!$deleteStmt) {
                throw new RuntimeException("Unable to delete duplicate employee id={$dupId}");
            }
            $deleteStmt->bind_param('i', $dupId);
            $deleteStmt->execute();
            $deleteStmt->close();

            if ($verbose) {
                echo "Merged duplicate employee {$dupId} -> {$keeperId}\n";
            }
        }
    }

    $db->commit();
    echo "Merge complete.\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "Merge failed: " . $e->getMessage() . "\n");
    exit(1);
}
