<?php
require_once __DIR__ . '/../spams/app/config/init.php';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function norm(string $value): string
{
    $value = trim(strtolower($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    return trim($value, '_');
}

function classification_group_from_item_type(string $itemType): string
{
    return $itemType === 'semi_expendable' ? 'semi_expendable' : ($itemType === 'equipment' ? 'asset' : 'supply');
}

function find_or_create_classification(mysqli $db, array &$cache, string $classificationName, string $itemType, ?int $accountCodeId, int $userId): ?int
{
    $classificationName = trim($classificationName);
    if ($classificationName === '') {
        return null;
    }

    $key = norm($classificationName) . '|' . (string) ($accountCodeId ?? 0) . '|' . $itemType;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $select = $db->prepare("SELECT id FROM classifications WHERE LOWER(TRIM(classification_name)) = LOWER(TRIM(?)) LIMIT 1");
    if ($select) {
        $select->bind_param('s', $classificationName);
        $select->execute();
        $existing = $select->get_result()->fetch_assoc() ?: null;
        $select->close();
        if ($existing) {
            $cache[$key] = (int) $existing['id'];
            return $cache[$key];
        }
    }

    $classificationCode = next_module_code($db, 'classifications');
    $classificationGroup = classification_group_from_item_type($itemType);
    $description = 'Auto-created during legacy asset classification backfill.';
    $insert = $db->prepare("INSERT INTO classifications (classification_code, classification_name, classification_group, account_code_id, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)");
    if (!$insert) {
        throw new RuntimeException('Unable to create classification for backfill.');
    }
    $insert->bind_param('sssisi', $classificationCode, $classificationName, $classificationGroup, $accountCodeId, $description, $userId);
    $saved = $insert->execute();
    $newId = (int) $insert->insert_id;
    $insert->close();
    if (!$saved || $newId <= 0) {
        throw new RuntimeException('Unable to create classification for backfill.');
    }

    $cache[$key] = $newId;
    return $newId;
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

$classificationCache = [];
$selectSql = "
    SELECT la.id, la.item_type, la.account_code_id, ac.account_name
    FROM legacy_assets la
    LEFT JOIN account_codes ac ON ac.id = la.account_code_id
    WHERE la.classification_id IS NULL OR la.classification_id = 0
    ORDER BY la.id ASC
";
$rowsRes = $db->query($selectSql);
if (!$rowsRes) {
    fwrite(STDERR, "Unable to load legacy assets for backfill." . PHP_EOL);
    exit(1);
}

$update = $db->prepare("UPDATE legacy_assets SET classification_id = ? WHERE id = ?");
if (!$update) {
    fwrite(STDERR, "Unable to prepare backfill update." . PHP_EOL);
    exit(1);
}

$db->begin_transaction();
$updated = 0;
$skipped = 0;

try {
    while ($row = $rowsRes->fetch_assoc()) {
        $accountName = trim((string) ($row['account_name'] ?? ''));
        $itemType = (string) ($row['item_type'] ?? 'equipment');
        $accountCodeId = isset($row['account_code_id']) ? (int) $row['account_code_id'] : null;

        if ($accountName === '') {
            $fallback = $itemType === 'semi_expendable' ? 'Unclassified Semi-Expendable' : ($itemType === 'supply' ? 'Unclassified Supply' : 'Unclassified Asset');
            $classificationId = find_or_create_classification($db, $classificationCache, $fallback, $itemType, $accountCodeId, $userId);
        } else {
            $classificationId = find_or_create_classification($db, $classificationCache, $accountName, $itemType, $accountCodeId, $userId);
        }

        if (!$classificationId) {
            $skipped++;
            continue;
        }

        $legacyId = (int) $row['id'];
        $update->bind_param('ii', $classificationId, $legacyId);
        if (!$update->execute()) {
            throw new RuntimeException('Failed updating legacy asset ID ' . $legacyId . ': ' . $update->error);
        }
        $updated++;
    }

    $db->commit();
    out('Updated: ' . $updated);
    out('Skipped: ' . $skipped);
} catch (Throwable $e) {
    $db->rollback();
    $update->close();
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$update->close();
exit(0);
