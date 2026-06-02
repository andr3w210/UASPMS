<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Deactivate duplicate classifications for the same normalized item name + account_code_id.
 *
 * Default mode: dry-run
 * Apply mode:   php apply_classification_duplicate_cleanup.php --apply
 */

header('Content-Type: text/plain; charset=utf-8');

$apply = in_array('--apply', $argv ?? [], true);
$db = tools_db();

$sql = "
    SELECT
        c.id,
        c.classification_code,
        c.classification_name,
        c.classification_group,
        c.account_code_id,
        c.useful_life_years,
        c.is_active,
        ac.account_code,
        ac.account_group
    FROM classifications c
    LEFT JOIN account_codes ac ON ac.id = c.account_code_id
    WHERE c.account_code_id IS NOT NULL
      AND c.account_code_id > 0
    ORDER BY c.account_code_id ASC, c.classification_name ASC, c.id ASC
";

$res = $db->query($sql);
if (!($res instanceof mysqli_result)) {
    fwrite(STDERR, 'Query failed: ' . $db->error . PHP_EOL);
    exit(1);
}

$rows = $res->fetch_all(MYSQLI_ASSOC);

$normalizeName = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return $value;
};

$groups = [];
foreach ($rows as $row) {
    $key = ((int) ($row['account_code_id'] ?? 0)) . '||' . $normalizeName((string) ($row['classification_name'] ?? ''));
    if (!isset($groups[$key])) {
        $groups[$key] = [];
    }
    $groups[$key][] = $row;
}

$duplicateGroups = array_values(array_filter($groups, static function (array $group): bool {
    return count($group) > 1;
}));

$chooseCanonical = static function (array $groupRows): array {
    usort($groupRows, static function (array $a, array $b): int {
        $aActive = (int) ($a['is_active'] ?? 0) === 1 ? 1 : 0;
        $bActive = (int) ($b['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }

        $aGroupMatch = ((string) ($a['classification_group'] ?? '') === (string) ($a['account_group'] ?? '')) ? 1 : 0;
        $bGroupMatch = ((string) ($b['classification_group'] ?? '') === (string) ($b['account_group'] ?? '')) ? 1 : 0;
        if ($aGroupMatch !== $bGroupMatch) {
            return $bGroupMatch <=> $aGroupMatch;
        }

        $aLife = (int) ($a['useful_life_years'] ?? -1);
        $bLife = (int) ($b['useful_life_years'] ?? -1);
        if ($aLife !== $bLife) {
            return $bLife <=> $aLife;
        }

        return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
    });

    return $groupRows[0];
};

$toDeactivate = [];

echo "== Classification Duplicate Cleanup ==" . PHP_EOL;
echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY-RUN') . PHP_EOL;
echo 'As of: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

foreach ($duplicateGroups as $groupRows) {
    $canonical = $chooseCanonical($groupRows);
    $canonicalId = (int) ($canonical['id'] ?? 0);

    $accountCode = (string) ($canonical['account_code'] ?? ('#' . (int) ($canonical['account_code_id'] ?? 0)));
    $normalizedName = $normalizeName((string) ($canonical['classification_name'] ?? ''));

    $groupDeactivate = [];
    foreach ($groupRows as $row) {
        $rowId = (int) ($row['id'] ?? 0);
        if ($rowId !== $canonicalId) {
            $groupDeactivate[] = $rowId;
            $toDeactivate[] = $rowId;
        }
    }

    echo 'Account: ' . $accountCode
        . ' | Name(normalized): ' . $normalizedName
        . ' | Keep ID: ' . $canonicalId
        . ' | Deactivate IDs: ' . implode(', ', $groupDeactivate)
        . PHP_EOL;
}

$toDeactivate = array_values(array_unique($toDeactivate));

echo PHP_EOL;
echo 'Total duplicate groups: ' . count($duplicateGroups) . PHP_EOL;
echo 'Total rows to deactivate: ' . count($toDeactivate) . PHP_EOL;

if (!$apply) {
    echo PHP_EOL . 'Dry-run complete. Re-run with --apply to perform updates.' . PHP_EOL;
    $db->close();
    exit(0);
}

if (!$toDeactivate) {
    echo PHP_EOL . 'No updates needed.' . PHP_EOL;
    $db->close();
    exit(0);
}

$db->begin_transaction();

$updated = 0;
$stmt = $db->prepare('UPDATE classifications SET is_active = 0, updated_at = NOW() WHERE id = ? AND is_active <> 0 LIMIT 1');
if (!$stmt) {
    $db->rollback();
    fwrite(STDERR, 'Failed to prepare update statement: ' . $db->error . PHP_EOL);
    $db->close();
    exit(1);
}

foreach ($toDeactivate as $id) {
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
        $stmt->close();
        $db->rollback();
        fwrite(STDERR, 'Failed to update id ' . $id . ': ' . $stmt->error . PHP_EOL);
        $db->close();
        exit(1);
    }
    $updated += $stmt->affected_rows > 0 ? 1 : 0;
}

$stmt->close();
$db->commit();

echo PHP_EOL;
echo 'Applied successfully. Rows deactivated: ' . $updated . PHP_EOL;

$db->close();
