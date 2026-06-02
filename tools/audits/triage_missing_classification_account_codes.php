<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Triage classifications with missing account_code_id.
 *
 * Goal:
 * - Separate likely catalog item names from project/asset-title style records
 * - Provide safe recommended next action (map vs consolidate vs deactivate)
 */

header('Content-Type: text/plain; charset=utf-8');

$db = tools_db();

$sql = "
    SELECT
        c.id,
        c.classification_code,
        c.classification_name,
        c.classification_group,
        c.useful_life_years,
        c.is_active
    FROM classifications c
    WHERE c.account_code_id IS NULL OR c.account_code_id = 0
    ORDER BY c.classification_name ASC, c.id ASC
";

$res = $db->query($sql);
if (!($res instanceof mysqli_result)) {
    fwrite(STDERR, 'Query failed: ' . $db->error . PHP_EOL);
    exit(1);
}

$rows = $res->fetch_all(MYSQLI_ASSOC);

$contains = static function (string $name, array $tokens): bool {
    $lower = strtolower($name);
    foreach ($tokens as $token) {
        if (strpos($lower, $token) !== false) {
            return true;
        }
    }
    return false;
};

$projectTokens = [
    'construction', 'repair', 'refurbish', 'rehab', 'phase', 'payment', 'title no',
    'contiguous', 'extra work', 'looping', 'realignment', 'subtotal', 'extension campus',
];

$buildingTokens = [
    'building', 'dormitory', 'gym', 'hall', 'center', 'cottage', 'house', 'office', 'laboratory',
    'grandstand', 'stage', 'gate', 'guard house',
];

$infraTokens = [
    'road', 'roadway', 'drainage', 'water system', 'waterline', 'water tank', 'reservoir',
    'bridge', 'pathwalk', 'pavement', 'fence', 'network',
];

$groups = [
    'project_like' => [],
    'likely_building_structure' => [],
    'likely_infrastructure' => [],
    'likely_catalog_item' => [],
];

foreach ($rows as $row) {
    $name = (string) ($row['classification_name'] ?? '');

    if ($contains($name, $projectTokens)) {
        $groups['project_like'][] = $row;
        continue;
    }
    if ($contains($name, $infraTokens)) {
        $groups['likely_infrastructure'][] = $row;
        continue;
    }
    if ($contains($name, $buildingTokens)) {
        $groups['likely_building_structure'][] = $row;
        continue;
    }
    $groups['likely_catalog_item'][] = $row;
}

echo "== Missing Account Code Triage ==" . PHP_EOL;
echo 'As of: ' . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;
echo 'Summary' . PHP_EOL;
echo '- Total missing account_code_id: ' . count($rows) . PHP_EOL;
echo '- Project-like names: ' . count($groups['project_like']) . PHP_EOL;
echo '- Likely building/structure names: ' . count($groups['likely_building_structure']) . PHP_EOL;
echo '- Likely infrastructure names: ' . count($groups['likely_infrastructure']) . PHP_EOL;
echo '- Likely catalog item names: ' . count($groups['likely_catalog_item']) . PHP_EOL;
echo PHP_EOL;

echo "Suggested COA Targets (for mapping candidates)" . PHP_EOL;
echo "- Building/Structure candidates -> 1.06.04.010.00 (Buildings) or 1.06.10.030.00 (CIP - Buildings and Other Structures)" . PHP_EOL;
echo "- Infrastructure candidates -> 1.06.03.100.00 (Other Infrastructure Assets) or 1.06.10.020.00 (CIP - Infrastructure Assets)" . PHP_EOL;
echo "- Project-like labels -> recommend consolidate/deactivate after remapping to canonical catalog classes" . PHP_EOL;
echo PHP_EOL;

$printGroup = static function (string $title, array $items): void {
    echo '== ' . $title . ' ==' . PHP_EOL;
    if (!$items) {
        echo "(none)" . PHP_EOL . PHP_EOL;
        return;
    }
    echo "id\tcode\tname\tgroup\tactive\tuseful_life" . PHP_EOL;
    foreach ($items as $row) {
        echo (int) ($row['id'] ?? 0) . "\t"
            . (string) ($row['classification_code'] ?? '') . "\t"
            . (string) ($row['classification_name'] ?? '') . "\t"
            . (string) ($row['classification_group'] ?? '') . "\t"
            . ((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive') . "\t"
            . (string) ($row['useful_life_years'] ?? '') . PHP_EOL;
    }
    echo PHP_EOL;
};

$printGroup('Project-like (review for consolidation/deactivation)', $groups['project_like']);
$printGroup('Likely Building/Structure (map candidate)', $groups['likely_building_structure']);
$printGroup('Likely Infrastructure (map candidate)', $groups['likely_infrastructure']);
$printGroup('Likely Catalog Item (map directly)', $groups['likely_catalog_item']);

$db->close();
