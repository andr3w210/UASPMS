<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Apply classification account-code mappings from a worksheet CSV.
 *
 * Default mode: dry-run
 * Apply mode:   php apply_classification_account_mapping_from_csv.php --file=<path> --apply
 */

header('Content-Type: text/plain; charset=utf-8');

$argv = $argv ?? [];
$apply = in_array('--apply', $argv, true);

$filePath = '';
foreach ($argv as $arg) {
    if (strpos((string) $arg, '--file=') === 0) {
        $filePath = (string) substr((string) $arg, 7);
        break;
    }
}

if ($filePath === '') {
    fwrite(STDERR, "Usage: php apply_classification_account_mapping_from_csv.php --file=<csv_path> [--apply]" . PHP_EOL);
    exit(1);
}

if (!is_file($filePath)) {
    fwrite(STDERR, 'CSV file not found: ' . $filePath . PHP_EOL);
    exit(1);
}

$normalizeHeaderCell = static function (string $value): string {
    $value = trim($value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    $value = trim($value, "\"' ");
    return strtolower($value);
};

$fp = fopen($filePath, 'rb');
if (!$fp) {
    fwrite(STDERR, 'Unable to open CSV file: ' . $filePath . PHP_EOL);
    exit(1);
}

$header = fgetcsv($fp);
if (!is_array($header) || !$header) {
    fclose($fp);
    fwrite(STDERR, 'CSV appears empty: ' . $filePath . PHP_EOL);
    exit(1);
}

$header = array_map(static function ($cell) use ($normalizeHeaderCell): string {
    return $normalizeHeaderCell((string) $cell);
}, $header);

$findHeaderIndex = static function (array $headerCells, array $candidates): int {
    foreach ($candidates as $candidate) {
        $idx = array_search($candidate, $headerCells, true);
        if ($idx !== false) {
            return (int) $idx;
        }
    }
    return -1;
};

$idxId = $findHeaderIndex($header, ['id', 'classification_id']);
$idxMapped = $findHeaderIndex($header, ['mapped_account_code', 'mapped account code']);

if ($idxId < 0 || $idxMapped < 0) {
    // Fallback for known worksheet export layout.
    if (count($header) >= 11) {
        $idxId = 0;
        $idxMapped = 10;
    }
}

if ($idxId < 0) {
    fclose($fp);
    fwrite(STDERR, 'Missing required CSV column: id' . PHP_EOL);
    exit(1);
}

if ($idxMapped < 0) {
    fclose($fp);
    fwrite(STDERR, 'Missing required CSV column: mapped_account_code' . PHP_EOL);
    exit(1);
}

$rows = [];
$line = 1;
while (($data = fgetcsv($fp)) !== false) {
    $line++;
    $idRaw = isset($data[$idxId]) ? trim((string) $data[$idxId]) : '';
    $codeRaw = isset($data[$idxMapped]) ? trim((string) $data[$idxMapped]) : '';

    if ($idRaw === '') {
        continue;
    }

    $id = (int) $idRaw;
    if ($id <= 0) {
        fclose($fp);
        fwrite(STDERR, 'Invalid id at CSV line ' . $line . ': ' . $idRaw . PHP_EOL);
        exit(1);
    }

    if ($codeRaw === '') {
        // Skip explicitly-unmapped rows in this importer.
        continue;
    }

    $rows[$id] = [
        'id' => $id,
        'mapped_account_code' => $codeRaw,
        'csv_line' => $line,
    ];
}
fclose($fp);

if (!$rows) {
    fwrite(STDERR, 'No mapped rows found in CSV.' . PHP_EOL);
    exit(1);
}

$db = tools_db();

$acctRes = $db->query('SELECT id, account_code, account_group, is_active FROM account_codes');
if (!($acctRes instanceof mysqli_result)) {
    fwrite(STDERR, 'Failed to load account_codes: ' . $db->error . PHP_EOL);
    $db->close();
    exit(1);
}

$accountByCode = [];
while ($acct = $acctRes->fetch_assoc()) {
    $code = (string) ($acct['account_code'] ?? '');
    if ($code === '') {
        continue;
    }
    $accountByCode[$code] = [
        'id' => (int) ($acct['id'] ?? 0),
        'account_group' => (string) ($acct['account_group'] ?? ''),
        'is_active' => (int) ($acct['is_active'] ?? 0),
    ];
}

$ids = array_keys($rows);
$idCsv = implode(',', array_map('intval', $ids));
$clsSql = '
    SELECT id, classification_name, classification_group, account_code_id, is_active
    FROM classifications
    WHERE id IN (' . $idCsv . ')
';
$clsRes = $db->query($clsSql);
if (!($clsRes instanceof mysqli_result)) {
    fwrite(STDERR, 'Failed to load classifications: ' . $db->error . PHP_EOL);
    $db->close();
    exit(1);
}

$classById = [];
while ($cls = $clsRes->fetch_assoc()) {
    $classById[(int) ($cls['id'] ?? 0)] = $cls;
}

$missingClassIds = [];
$unknownAccountCodes = [];
$inactiveAccountCodes = [];
$plan = [];
$skippedInactiveClassifications = [];

foreach ($rows as $id => $row) {
    if (!isset($classById[$id])) {
        $missingClassIds[] = $id;
        continue;
    }

    $code = (string) $row['mapped_account_code'];
    if (!isset($accountByCode[$code])) {
        $unknownAccountCodes[] = $code;
        continue;
    }

    $acct = $accountByCode[$code];
    if ((int) ($acct['is_active'] ?? 0) !== 1) {
        $inactiveAccountCodes[] = $code;
        continue;
    }

    $current = $classById[$id];
    if ((int) ($current['is_active'] ?? 0) !== 1) {
        $skippedInactiveClassifications[] = $id;
        continue;
    }

    $plan[] = [
        'id' => $id,
        'classification_name' => (string) ($current['classification_name'] ?? ''),
        'from_account_code_id' => (int) ($current['account_code_id'] ?? 0),
        'to_account_code_id' => (int) ($acct['id'] ?? 0),
        'to_account_code' => $code,
        'to_account_group' => (string) ($acct['account_group'] ?? ''),
        'from_group' => (string) ($current['classification_group'] ?? ''),
    ];
}

$missingClassIds = array_values(array_unique($missingClassIds));
$unknownAccountCodes = array_values(array_unique($unknownAccountCodes));
$inactiveAccountCodes = array_values(array_unique($inactiveAccountCodes));
$skippedInactiveClassifications = array_values(array_unique($skippedInactiveClassifications));

$duplicateTargetConflicts = [];
$dupStmt = $db->prepare('
    SELECT id
    FROM classifications
    WHERE classification_name = ?
      AND classification_group = ?
      AND id <> ?
    LIMIT 1
');

if (!$dupStmt) {
    fwrite(STDERR, 'Failed to prepare duplicate-check statement: ' . $db->error . PHP_EOL);
    $db->close();
    exit(1);
}

foreach ($plan as $item) {
    $name = (string) $item['classification_name'];
    $group = (string) $item['to_account_group'];
    $id = (int) $item['id'];

    $dupStmt->bind_param('ssi', $name, $group, $id);
    if (!$dupStmt->execute()) {
        $dupStmt->close();
        fwrite(STDERR, 'Failed duplicate check for id ' . $id . ': ' . $dupStmt->error . PHP_EOL);
        $db->close();
        exit(1);
    }
    $dupRes = $dupStmt->get_result();
    if ($dupRes instanceof mysqli_result) {
        $dup = $dupRes->fetch_assoc();
        if ($dup) {
            $duplicateTargetConflicts[] = [
                'id' => $id,
                'classification_name' => $name,
                'target_group' => $group,
                'conflict_id' => (int) ($dup['id'] ?? 0),
            ];
        }
        $dupRes->free();
    }
}

$dupStmt->close();

echo '== Apply Classification Account Mapping From CSV ==' . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . PHP_EOL;
echo 'CSV: ' . $filePath . PHP_EOL;
echo 'Rows with mapped_account_code in CSV: ' . count($rows) . PHP_EOL;
echo 'Planned updates: ' . count($plan) . PHP_EOL;
echo 'Missing classification IDs: ' . count($missingClassIds) . PHP_EOL;
echo 'Unknown account codes: ' . count($unknownAccountCodes) . PHP_EOL;
echo 'Inactive account codes: ' . count($inactiveAccountCodes) . PHP_EOL;
echo 'Skipped inactive classifications: ' . count($skippedInactiveClassifications) . PHP_EOL;
echo 'Target group+name conflicts: ' . count($duplicateTargetConflicts) . PHP_EOL;
echo PHP_EOL;

if ($missingClassIds) {
    echo 'Missing classification IDs: ' . implode(', ', $missingClassIds) . PHP_EOL;
}
if ($unknownAccountCodes) {
    echo 'Unknown account codes: ' . implode(', ', $unknownAccountCodes) . PHP_EOL;
}
if ($inactiveAccountCodes) {
    echo 'Inactive account codes: ' . implode(', ', $inactiveAccountCodes) . PHP_EOL;
}
if ($skippedInactiveClassifications) {
    echo 'Skipped inactive classification IDs: ' . implode(', ', $skippedInactiveClassifications) . PHP_EOL;
}
if ($duplicateTargetConflicts) {
    echo 'Target group+name conflicts:' . PHP_EOL;
    foreach ($duplicateTargetConflicts as $conflict) {
        echo '- ID ' . $conflict['id']
            . ' (' . $conflict['classification_name'] . ')'
            . ' -> group ' . $conflict['target_group']
            . ' conflicts with existing ID ' . $conflict['conflict_id']
            . PHP_EOL;
    }
}

foreach ($plan as $item) {
    echo 'ID ' . $item['id']
        . ' | ' . $item['classification_name']
        . ' | account_code_id: ' . $item['from_account_code_id'] . ' -> ' . $item['to_account_code_id']
        . ' (' . $item['to_account_code'] . ')'
        . ' | group: ' . $item['from_group'] . ' -> ' . $item['to_account_group']
        . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . 'Dry-run complete. Re-run with --apply to perform updates.' . PHP_EOL;
    $db->close();
    exit(0);
}

if ($missingClassIds || $unknownAccountCodes || $inactiveAccountCodes || $duplicateTargetConflicts) {
    fwrite(STDERR, PHP_EOL . 'Apply aborted due to validation errors shown above.' . PHP_EOL);
    $db->close();
    exit(1);
}

if (!$plan) {
    echo PHP_EOL . 'No updates needed.' . PHP_EOL;
    $db->close();
    exit(0);
}

$stmt = $db->prepare('
    UPDATE classifications
    SET account_code_id = ?,
        classification_group = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
');

if (!$stmt) {
    fwrite(STDERR, 'Failed to prepare update statement: ' . $db->error . PHP_EOL);
    $db->close();
    exit(1);
}

$db->begin_transaction();
$updated = 0;

foreach ($plan as $item) {
    $toId = (int) $item['to_account_code_id'];
    $toGroup = (string) $item['to_account_group'];
    $id = (int) $item['id'];

    $stmt->bind_param('isi', $toId, $toGroup, $id);
    if (!$stmt->execute()) {
        $stmt->close();
        $db->rollback();
        fwrite(STDERR, 'Failed to update classification id ' . $id . ': ' . $stmt->error . PHP_EOL);
        $db->close();
        exit(1);
    }
    $updated += $stmt->affected_rows > 0 ? 1 : 0;
}

$stmt->close();
$db->commit();

echo PHP_EOL . 'Applied successfully. Rows updated: ' . $updated . PHP_EOL;

$db->close();
