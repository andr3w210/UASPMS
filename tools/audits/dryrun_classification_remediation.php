<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Dry-run remediation planner for classifications.
 *
 * Outputs recommendations only. No updates are executed.
 */

header('Content-Type: text/plain; charset=utf-8');

$db = tools_db();

$sql = "
    SELECT
        c.id,
        c.classification_code,
        c.classification_name,
        c.classification_family,
        c.classification_group,
        c.account_code_id,
        c.useful_life_years,
        c.description,
        c.is_active,
        c.created_at,
        ac.account_code,
        ac.account_name,
        ac.account_group
    FROM classifications c
    LEFT JOIN account_codes ac ON ac.id = c.account_code_id
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

$groupedByAccountAndName = [];
$missingAccount = [];
$byNameAndGroupWithAccount = [];

foreach ($rows as $row) {
    $accountCodeId = (int) ($row['account_code_id'] ?? 0);
    $normalizedName = $normalizeName((string) ($row['classification_name'] ?? ''));
    $classificationGroup = (string) ($row['classification_group'] ?? '');

    if ($accountCodeId <= 0) {
        $missingAccount[] = $row;
    } else {
        $dupKey = $accountCodeId . '||' . $normalizedName;
        if (!isset($groupedByAccountAndName[$dupKey])) {
            $groupedByAccountAndName[$dupKey] = [
                'account_code_id' => $accountCodeId,
                'account_code' => (string) ($row['account_code'] ?? ''),
                'account_name' => (string) ($row['account_name'] ?? ''),
                'account_group' => (string) ($row['account_group'] ?? ''),
                'normalized_name' => $normalizedName,
                'rows' => [],
            ];
        }
        $groupedByAccountAndName[$dupKey]['rows'][] = $row;
    }

    if ($accountCodeId > 0 && $normalizedName !== '' && $classificationGroup !== '') {
        $mapKey = $normalizedName . '||' . $classificationGroup;
        if (!isset($byNameAndGroupWithAccount[$mapKey])) {
            $byNameAndGroupWithAccount[$mapKey] = [];
        }
        $byNameAndGroupWithAccount[$mapKey][] = $row;
    }
}

$duplicateGroups = array_values(array_filter(
    $groupedByAccountAndName,
    static function (array $group): bool {
        return count($group['rows']) > 1;
    }
));

$chooseCanonical = static function (array $groupRows, string $accountGroup): array {
    usort($groupRows, static function (array $a, array $b) use ($accountGroup): int {
        $aActive = (int) ($a['is_active'] ?? 0) === 1 ? 1 : 0;
        $bActive = (int) ($b['is_active'] ?? 0) === 1 ? 1 : 0;
        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }

        $aGroupMatch = ((string) ($a['classification_group'] ?? '') === $accountGroup) ? 1 : 0;
        $bGroupMatch = ((string) ($b['classification_group'] ?? '') === $accountGroup) ? 1 : 0;
        if ($aGroupMatch !== $bGroupMatch) {
            return $bGroupMatch <=> $aGroupMatch;
        }

        $aLife = (int) ($a['useful_life_years'] ?? -1);
        $bLife = (int) ($b['useful_life_years'] ?? -1);
        if ($aLife !== $bLife) {
            return $bLife <=> $aLife;
        }

        $aId = (int) ($a['id'] ?? 0);
        $bId = (int) ($b['id'] ?? 0);
        return $aId <=> $bId;
    });

    return $groupRows[0];
};

echo "== Classification Remediation Dry-Run ==" . PHP_EOL;
echo 'As of: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

echo "Summary" . PHP_EOL;
echo '- Duplicate groups requiring merge: ' . count($duplicateGroups) . PHP_EOL;
echo '- Records missing account code: ' . count($missingAccount) . PHP_EOL;
echo PHP_EOL;

if ($duplicateGroups) {
    echo "== Proposed Duplicate Merge Plan (No Changes Applied) ==" . PHP_EOL;
    foreach ($duplicateGroups as $group) {
        $canonical = $chooseCanonical($group['rows'], (string) ($group['account_group'] ?? ''));
        $canonicalId = (int) ($canonical['id'] ?? 0);
        $toDeactivate = array_values(array_filter($group['rows'], static function (array $row) use ($canonicalId): bool {
            return (int) ($row['id'] ?? 0) !== $canonicalId;
        }));

        echo 'Account: ' . (($group['account_code'] ?? '') !== '' ? $group['account_code'] : ('#' . (int) $group['account_code_id']));
        echo ' | Name(normalized): ' . $group['normalized_name'];
        echo ' | Keep ID: ' . $canonicalId;
        echo ' | Deactivate IDs: ' . implode(', ', array_map(static function (array $row): string { return (string) ((int) ($row['id'] ?? 0)); }, $toDeactivate));
        echo PHP_EOL;
        echo 'keep_row\t' . (string) ($canonical['classification_code'] ?? '') . '\t' . (string) ($canonical['classification_name'] ?? '') . '\tgroup=' . (string) ($canonical['classification_group'] ?? '') . '\tactive=' . ((int) ($canonical['is_active'] ?? 0) === 1 ? 'yes' : 'no') . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($missingAccount) {
    echo "== Proposed Mapping Plan for Missing Account Code (No Changes Applied) ==" . PHP_EOL;
    echo "id\tcode\tname\tgroup\trecommended_account_code\trecommended_account_id\tbasis" . PHP_EOL;
    foreach ($missingAccount as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = (string) ($row['classification_name'] ?? '');
        $normalizedName = $normalizeName($name);
        $classificationGroup = (string) ($row['classification_group'] ?? '');
        $mapKey = $normalizedName . '||' . $classificationGroup;

        $recommendedCode = '';
        $recommendedId = 0;
        $basis = 'no direct match';

        if (isset($byNameAndGroupWithAccount[$mapKey]) && count($byNameAndGroupWithAccount[$mapKey]) > 0) {
            $candidateRows = $byNameAndGroupWithAccount[$mapKey];
            usort($candidateRows, static function (array $a, array $b): int {
                $aActive = (int) ($a['is_active'] ?? 0) === 1 ? 1 : 0;
                $bActive = (int) ($b['is_active'] ?? 0) === 1 ? 1 : 0;
                if ($aActive !== $bActive) {
                    return $bActive <=> $aActive;
                }
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            });

            $pick = $candidateRows[0];
            $recommendedCode = (string) ($pick['account_code'] ?? '');
            $recommendedId = (int) ($pick['account_code_id'] ?? 0);
            $basis = 'matched by normalized name + group';
        }

        echo $id . "\t"
            . (string) ($row['classification_code'] ?? '') . "\t"
            . $name . "\t"
            . $classificationGroup . "\t"
            . ($recommendedCode !== '' ? $recommendedCode : '-') . "\t"
            . ($recommendedId > 0 ? (string) $recommendedId : '-') . "\t"
            . $basis . PHP_EOL;
    }
    echo PHP_EOL;
}

if (!$duplicateGroups && !$missingAccount) {
    echo "No remediation actions needed based on current rules." . PHP_EOL;
}

$db->close();
