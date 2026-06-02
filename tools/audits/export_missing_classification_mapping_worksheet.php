<?php
require_once __DIR__ . '/../bootstrap.php';

/**
 * Export worksheet for classifications missing account_code_id.
 * Output columns are designed for manual mapping review.
 */

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

$classify = static function (string $name) use ($contains, $projectTokens, $buildingTokens, $infraTokens): string {
    if ($contains($name, $projectTokens)) {
        return 'project_like';
    }
    if ($contains($name, $infraTokens)) {
        return 'likely_infrastructure';
    }
    if ($contains($name, $buildingTokens)) {
        return 'likely_building_structure';
    }
    return 'likely_catalog_item';
};

$suggestion = static function (string $category): array {
    if ($category === 'project_like') {
        return [
            'recommended_account_code_primary' => 'REVIEW-CANONICAL-FIRST',
            'recommended_account_code_secondary' => '1.06.10.020.00 or 1.06.10.030.00',
            'recommended_action' => 'Map to canonical class then deactivate project-like label',
        ];
    }
    if ($category === 'likely_building_structure') {
        return [
            'recommended_account_code_primary' => '1.06.04.010.00',
            'recommended_account_code_secondary' => '1.06.10.030.00',
            'recommended_action' => 'Choose Buildings (completed) or CIP Buildings/Structures (in-progress)',
        ];
    }
    if ($category === 'likely_infrastructure') {
        return [
            'recommended_account_code_primary' => '1.06.03.100.00',
            'recommended_account_code_secondary' => '1.06.10.020.00',
            'recommended_action' => 'Choose Infrastructure (completed) or CIP Infrastructure (in-progress)',
        ];
    }
    return [
        'recommended_account_code_primary' => 'MANUAL-BY-ITEM',
        'recommended_account_code_secondary' => '',
        'recommended_action' => 'Map directly based on actual item nature',
    ];
};

$exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'exports';
if (!is_dir($exportDir)) {
    if (!mkdir($exportDir, 0777, true) && !is_dir($exportDir)) {
        fwrite(STDERR, 'Unable to create export directory: ' . $exportDir . PHP_EOL);
        exit(1);
    }
}

$filePath = $exportDir . DIRECTORY_SEPARATOR . 'classification_account_mapping_worksheet_' . date('Ymd_His') . '.csv';
$fp = fopen($filePath, 'wb');
if (!$fp) {
    fwrite(STDERR, 'Unable to write export file: ' . $filePath . PHP_EOL);
    exit(1);
}

fputcsv($fp, [
    'id',
    'classification_code',
    'classification_name',
    'classification_group',
    'useful_life_years',
    'is_active',
    'triage_category',
    'recommended_account_code_primary',
    'recommended_account_code_secondary',
    'recommended_action',
    'mapped_account_code',
    'mapper_notes',
]);

foreach ($rows as $row) {
    $name = (string) ($row['classification_name'] ?? '');
    $category = $classify($name);
    $rec = $suggestion($category);

    fputcsv($fp, [
        (int) ($row['id'] ?? 0),
        (string) ($row['classification_code'] ?? ''),
        $name,
        (string) ($row['classification_group'] ?? ''),
        (string) ($row['useful_life_years'] ?? ''),
        (int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive',
        $category,
        $rec['recommended_account_code_primary'],
        $rec['recommended_account_code_secondary'],
        $rec['recommended_action'],
        '',
        '',
    ]);
}

fclose($fp);
$db->close();

echo 'Exported worksheet: ' . $filePath . PHP_EOL;
echo 'Rows exported: ' . count($rows) . PHP_EOL;
