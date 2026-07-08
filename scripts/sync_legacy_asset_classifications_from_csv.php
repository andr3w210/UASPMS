<?php
require_once __DIR__ . '/../spams/app/config/init.php';

$apply = in_array('--apply', $argv, true);

function sync_out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function sync_norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function sync_clean(string $value): string
{
    return trim($value);
}

function sync_parse_csv(string $filePath): array
{
    $rows = [];
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        throw new RuntimeException('Unable to open CSV file: ' . $filePath);
    }
    while (($csvRow = fgetcsv($handle)) !== false) {
        $rows[] = array_map(static fn($v) => trim((string) $v), $csvRow);
    }
    fclose($handle);
    return $rows;
}

function sync_group_from_item_type(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'semi_expendable' : ($itemType === 'equipment' ? 'asset' : 'supply');
}

function sync_find_or_create_classification(mysqli $db, array &$cache, string $classificationName, string $itemType, ?int $accountCodeId, int $userId): ?int
{
    $classificationName = sync_clean($classificationName);
    if ($classificationName === '') {
        return null;
    }

    $key = sync_norm($classificationName);
    if (isset($cache[$key])) {
        return (int) $cache[$key];
    }

    $select = $db->prepare("SELECT id FROM classifications WHERE LOWER(TRIM(classification_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('s', $classificationName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            $cache[$key] = (int) $existing['id'];
            return (int) $cache[$key];
        }
    }

    $classificationCode = next_module_code($db, 'classifications');
    $classificationGroup = sync_group_from_item_type($itemType);
    $description = 'Auto-created from CSV classification sync.';
    $insert = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create missing classification during CSV sync.');
    }
    $insert->bind_param('sssisi', $classificationCode, $classificationName, $classificationGroup, $accountCodeId, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create missing classification during CSV sync.');
    }

    $cache[$key] = $newId;
    return $newId;
}

$filePath = $argv[1] ?? '';
if ($filePath === '') {
    fwrite(STDERR, "Usage: php scripts/sync_legacy_asset_classifications_from_csv.php <csv-path>" . PHP_EOL);
    exit(1);
}
if (!is_file($filePath)) {
    fwrite(STDERR, "File not found: {$filePath}" . PHP_EOL);
    exit(1);
}

$db = db();
if (!$db) {
    fwrite(STDERR, "Unable to connect to database." . PHP_EOL);
    exit(1);
}

$userId = 1;
$userRes = $db->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
if ($userRes && ($userRow = $userRes->fetch_assoc())) {
    $userId = (int) $userRow['id'];
}

$rows = sync_parse_csv($filePath);
if (count($rows) < 2) {
    fwrite(STDERR, "The file must contain a header row and at least one data row." . PHP_EOL);
    exit(1);
}

$header = array_map('sync_norm', $rows[0]);
$col = array_flip($header);
foreach (['property_number', 'classification'] as $required) {
    if (!isset($col[$required])) {
        fwrite(STDERR, "Missing required column: {$required}" . PHP_EOL);
        exit(1);
    }
}

$classificationCache = [];
$update = $db->prepare("UPDATE legacy_assets SET classification_id = ? WHERE id = ?");
if (!$update) {
    fwrite(STDERR, "Unable to prepare classification sync update." . PHP_EOL);
    exit(1);
}

$lookup = $db->prepare("SELECT id, item_type, account_code_id FROM legacy_assets WHERE property_number = ?");
if (!$lookup) {
    fwrite(STDERR, "Unable to prepare legacy asset lookup." . PHP_EOL);
    exit(1);
}

$db->begin_transaction();
if (!$apply) {
    sync_out('Dry-run only. Re-run with --apply to persist CSV classification sync updates.');
}
$updated = 0;
$createdOrMatched = 0;
$missingProperty = 0;
$missingClassification = 0;
$notFound = 0;

try {
    for ($i = 1; $i < count($rows); $i++) {
        $src = $rows[$i];
        if (!array_filter($src, static fn($v) => trim((string) $v) !== '')) {
            continue;
        }

        $propertyNumber = trim((string) ($src[$col['property_number'] ?? null] ?? ''));
        $classificationName = trim((string) ($src[$col['classification'] ?? null] ?? ''));

        if ($propertyNumber === '') {
            $missingProperty++;
            continue;
        }
        if ($classificationName === '') {
            $missingClassification++;
            continue;
        }

        $lookup->bind_param('s', $propertyNumber);
        $lookup->execute();
        $result = $lookup->get_result();
        $matchedAny = false;
        while ($asset = $result->fetch_assoc()) {
            $matchedAny = true;
            $classificationId = sync_find_or_create_classification(
                $db,
                $classificationCache,
                $classificationName,
                (string) ($asset['item_type'] ?? 'equipment'),
                isset($asset['account_code_id']) ? (int) $asset['account_code_id'] : null,
                $userId
            );
            if ($classificationId === null) {
                continue;
            }
            $legacyId = (int) $asset['id'];
            $update->bind_param('ii', $classificationId, $legacyId);
            if (!$update->execute()) {
                throw new RuntimeException('Failed updating legacy asset ID ' . $legacyId . ': ' . $update->error);
            }
            $updated++;
            $createdOrMatched++;
        }

        if (!$matchedAny) {
            $notFound++;
        }
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollback();
    }
    sync_out(($apply ? 'Updated: ' : 'Rows that would update: ') . $updated);
    sync_out('Rows with blank property_number: ' . $missingProperty);
    sync_out('Rows with blank classification: ' . $missingClassification);
    sync_out('Rows with no matching imported asset: ' . $notFound);
} catch (Throwable $e) {
    $db->rollback();
    $update->close();
    $lookup->close();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$update->close();
$lookup->close();
exit(0);
