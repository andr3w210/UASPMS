<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Audit classifications against the finalized rule:
 * - Item Name + Account Code must be unique (normalized item name)
 * - Account Code must be linked
 */

header('Content-Type: text/plain; charset=utf-8');

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
        ac.account_name,
        ac.account_group
    FROM classifications c
    LEFT JOIN account_codes ac ON ac.id = c.account_code_id
    ORDER BY c.account_code_id ASC, c.classification_name ASC, c.id ASC
";

$result = $db->query($sql);
if (!($result instanceof mysqli_result)) {
    fwrite(STDERR, "Query failed: " . $db->error . PHP_EOL);
    exit(1);
}

$rows = $result->fetch_all(MYSQLI_ASSOC);

$normalizeName = static function (string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return $value;
};

$missingAccountCode = [];
$duplicateBuckets = [];
$nameAcrossAccounts = [];

foreach ($rows as $row) {
    $accountCodeId = (int) ($row['account_code_id'] ?? 0);
    $name = (string) ($row['classification_name'] ?? '');
    $normalizedName = $normalizeName($name);

    if ($accountCodeId <= 0) {
        $missingAccountCode[] = $row;
        continue;
    }

    $dupKey = $accountCodeId . '||' . $normalizedName;
    if (!isset($duplicateBuckets[$dupKey])) {
        $duplicateBuckets[$dupKey] = [
            'account_code_id' => $accountCodeId,
            'account_code' => (string) ($row['account_code'] ?? ''),
            'account_name' => (string) ($row['account_name'] ?? ''),
            'normalized_name' => $normalizedName,
            'rows' => [],
        ];
    }
    $duplicateBuckets[$dupKey]['rows'][] = $row;

    if (!isset($nameAcrossAccounts[$normalizedName])) {
        $nameAcrossAccounts[$normalizedName] = [];
    }
    $nameAcrossAccounts[$normalizedName][$accountCodeId] = (string) ($row['account_code'] ?? ('#' . $accountCodeId));
}

$duplicateGroups = array_values(array_filter($duplicateBuckets, static function (array $bucket): bool {
    return count($bucket['rows']) > 1;
}));

$activeDuplicateGroups = array_values(array_filter($duplicateBuckets, static function (array $bucket): bool {
    $activeRows = array_values(array_filter($bucket['rows'], static function (array $row): bool {
        return (int) ($row['is_active'] ?? 0) === 1;
    }));
    return count($activeRows) > 1;
}));

$crossAccountNames = array_values(array_filter($nameAcrossAccounts, static function (array $codes): bool {
    return count($codes) > 1;
}));

echo "== Classification Integrity Audit (Item Name + Account Code) ==" . PHP_EOL;
echo "As of: " . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

echo "Summary" . PHP_EOL;
echo "- Total classifications: " . count($rows) . PHP_EOL;
echo "- Missing account code link: " . count($missingAccountCode) . PHP_EOL;
echo "- Duplicate groups (active only, blocking): " . count($activeDuplicateGroups) . PHP_EOL;
echo "- Duplicate groups (including inactive, historical): " . count($duplicateGroups) . PHP_EOL;
echo "- Names reused across different account codes (informational): " . count($crossAccountNames) . PHP_EOL;
echo PHP_EOL;

if ($missingAccountCode) {
    echo "== Missing Account Code Link ==" . PHP_EOL;
    echo "id\tcode\tname\tgroup\tactive\tuseful_life" . PHP_EOL;
    foreach ($missingAccountCode as $row) {
        echo (int) ($row['id'] ?? 0) . "\t"
            . (string) ($row['classification_code'] ?? '') . "\t"
            . (string) ($row['classification_name'] ?? '') . "\t"
            . (string) ($row['classification_group'] ?? '') . "\t"
            . ((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive') . "\t"
            . (string) ($row['useful_life_years'] ?? '') . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($activeDuplicateGroups) {
    echo "== Duplicate Groups (Blocking Violation: Active Rows) ==" . PHP_EOL;
    foreach ($activeDuplicateGroups as $group) {
        echo "Account: " . ($group['account_code'] !== '' ? $group['account_code'] : ('#' . $group['account_code_id']))
            . " | Name(normalized): " . $group['normalized_name']
            . " | Rows: " . count($group['rows']) . PHP_EOL;
        echo "id\tcode\tname\tgroup\tactive\tuseful_life" . PHP_EOL;
        foreach ($group['rows'] as $row) {
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                continue;
            }
            echo (int) ($row['id'] ?? 0) . "\t"
                . (string) ($row['classification_code'] ?? '') . "\t"
                . (string) ($row['classification_name'] ?? '') . "\t"
                . (string) ($row['classification_group'] ?? '') . "\t"
                . ((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive') . "\t"
                . (string) ($row['useful_life_years'] ?? '') . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

if ($duplicateGroups) {
    echo "== Duplicate Groups (Historical Includes Inactive) ==" . PHP_EOL;
    foreach ($duplicateGroups as $group) {
        echo "Account: " . ($group['account_code'] !== '' ? $group['account_code'] : ('#' . $group['account_code_id']))
            . " | Name(normalized): " . $group['normalized_name']
            . " | Rows: " . count($group['rows']) . PHP_EOL;
    }
    echo PHP_EOL;
}

if (!$missingAccountCode && !$activeDuplicateGroups) {
    echo "No blocking violations found for Item Name + Account Code rule." . PHP_EOL;
}

$db->close();
