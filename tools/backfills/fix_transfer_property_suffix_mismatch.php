<?php
/**
 * One-time correction:
 * Align property_number office suffix with the current accountable office
 * for assets that already have transfer history.
 *
 * Scope:
 * - system assets: distribution_item_details + related transaction tables by distribution_item_detail_id
 * - legacy assets: legacy_assets + related transaction tables by legacy_asset_id
 */

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$apply = in_array('--apply', $argv, true);

$db = tools_db();
if (!$apply) {
    echo 'Dry-run only. Re-run with --apply to persist property-number suffix corrections.' . PHP_EOL;
}

function has_column(mysqli $db, string $table, string $column): bool
{
    $tableEsc = $db->real_escape_string($table);
    $colEsc = $db->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM {$tableEsc} LIKE '{$colEsc}'";
    $res = $db->query($sql);
    if (!$res) {
        return false;
    }
    return $res->num_rows > 0;
}

function clean_office_suffix(string $officeCode): string
{
    $suffix = strtoupper(trim($officeCode));
    $suffix = preg_replace('/[^A-Z0-9]/', '', $suffix) ?? '';
    return $suffix !== '' ? $suffix : 'GEN';
}

function force_office_suffix(string $propertyNumber, string $officeCode): string
{
    $propertyNumber = trim($propertyNumber);
    $officeCode = clean_office_suffix($officeCode);

    if ($propertyNumber === '' || $officeCode === '') {
        return $propertyNumber;
    }

    if (preg_match('/-([A-Z0-9]{2,12})$/i', $propertyNumber)) {
        return (string) preg_replace('/-([A-Z0-9]{2,12})$/i', '-' . $officeCode, $propertyNumber, 1);
    }

    return $propertyNumber;
}

$systemSql = "
    SELECT
        did.id,
        did.property_number,
        o.office_code
    FROM distribution_item_details did
    INNER JOIN offices o ON o.id = did.current_office_id
    WHERE did.current_office_id IS NOT NULL
      AND did.property_number IS NOT NULL
      AND TRIM(did.property_number) <> ''
      AND did.property_number REGEXP '-[A-Za-z0-9]{2,12}$'
      AND EXISTS (
          SELECT 1
          FROM asset_transfers at
          WHERE at.source_type = 'system'
            AND at.distribution_item_detail_id = did.id
      )
";

$legacySql = "
    SELECT
        la.id,
        la.property_number,
        o.office_code
    FROM legacy_assets la
    INNER JOIN offices o ON o.id = la.office_id
    WHERE la.office_id IS NOT NULL
      AND la.property_number IS NOT NULL
      AND TRIM(la.property_number) <> ''
      AND la.property_number REGEXP '-[A-Za-z0-9]{2,12}$'
      AND EXISTS (
          SELECT 1
          FROM asset_transfers at
          WHERE at.source_type = 'legacy'
            AND at.legacy_asset_id = la.id
      )
";

$systemRows = [];
$legacyRows = [];

$systemRes = $db->query($systemSql);
if ($systemRes instanceof mysqli_result) {
    while ($row = $systemRes->fetch_assoc()) {
        $old = trim((string) ($row['property_number'] ?? ''));
        if (stripos($old, 'TEMP-') === 0) {
            continue;
        }
        $new = force_office_suffix($old, (string) ($row['office_code'] ?? ''));
        if ($new !== '' && strcasecmp($new, $old) !== 0) {
            $systemRows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'old' => $old,
                'new' => $new,
            ];
        }
    }
}

$legacyRes = $db->query($legacySql);
if ($legacyRes instanceof mysqli_result) {
    while ($row = $legacyRes->fetch_assoc()) {
        $old = trim((string) ($row['property_number'] ?? ''));
        if (stripos($old, 'TEMP-') === 0) {
            continue;
        }
        $new = force_office_suffix($old, (string) ($row['office_code'] ?? ''));
        if ($new !== '' && strcasecmp($new, $old) !== 0) {
            $legacyRows[] = [
                'id' => (int) ($row['id'] ?? 0),
                'old' => $old,
                'new' => $new,
            ];
        }
    }
}

echo 'system_candidates=' . count($systemRows) . PHP_EOL;
echo 'legacy_candidates=' . count($legacyRows) . PHP_EOL;

$previewCount = 5;
if ($systemRows) {
    echo 'system_preview:' . PHP_EOL;
    for ($i = 0; $i < min($previewCount, count($systemRows)); $i++) {
        $r = $systemRows[$i];
        echo '  did#' . $r['id'] . ' ' . $r['old'] . ' -> ' . $r['new'] . PHP_EOL;
    }
}
if ($legacyRows) {
    echo 'legacy_preview:' . PHP_EOL;
    for ($i = 0; $i < min($previewCount, count($legacyRows)); $i++) {
        $r = $legacyRows[$i];
        echo '  la#' . $r['id'] . ' ' . $r['old'] . ' -> ' . $r['new'] . PHP_EOL;
    }
}

if (!$systemRows && !$legacyRows) {
    echo 'No rows needed correction.' . PHP_EOL;
    $db->close();
    exit(0);
}

$systemTargets = [
    ['table' => 'distribution_item_details', 'id_column' => 'id'],
    ['table' => 'asset_transfers', 'id_column' => 'distribution_item_detail_id'],
    ['table' => 'transfer_batch_items', 'id_column' => 'distribution_item_detail_id'],
    ['table' => 'inventory_count_items', 'id_column' => 'distribution_item_detail_id'],
    ['table' => 'rpcppe_batch_items', 'id_column' => 'distribution_item_detail_id'],
];

$legacyTargets = [
    ['table' => 'legacy_assets', 'id_column' => 'id'],
    ['table' => 'asset_transfers', 'id_column' => 'legacy_asset_id'],
    ['table' => 'transfer_batch_items', 'id_column' => 'legacy_asset_id'],
    ['table' => 'inventory_count_items', 'id_column' => 'legacy_asset_id'],
    ['table' => 'rpcppe_batch_items', 'id_column' => 'legacy_asset_id'],
];

$statements = [];
$updatedByTable = [];

try {
    $db->begin_transaction();

    foreach ($systemTargets as $target) {
        $table = $target['table'];
        $idColumn = $target['id_column'];
        if (!has_column($db, $table, 'property_number') || !has_column($db, $table, $idColumn)) {
            continue;
        }

        $stmt = $db->prepare("UPDATE {$table} SET property_number = ? WHERE {$idColumn} = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare update for ' . $table . '.');
        }
        $statements['system|' . $table] = $stmt;
        $updatedByTable[$table] = 0;
    }

    foreach ($legacyTargets as $target) {
        $table = $target['table'];
        $idColumn = $target['id_column'];
        if (!has_column($db, $table, 'property_number') || !has_column($db, $table, $idColumn)) {
            continue;
        }

        $stmt = $db->prepare("UPDATE {$table} SET property_number = ? WHERE {$idColumn} = ?");
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare update for ' . $table . '.');
        }
        $statements['legacy|' . $table] = $stmt;
        $updatedByTable[$table] = $updatedByTable[$table] ?? 0;
    }

    foreach ($systemRows as $row) {
        $id = (int) $row['id'];
        $newProperty = (string) $row['new'];

        foreach ($systemTargets as $target) {
            $table = $target['table'];
            $key = 'system|' . $table;
            if (!isset($statements[$key])) {
                continue;
            }

            $stmt = $statements[$key];
            $stmt->bind_param('si', $newProperty, $id);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed updating system table ' . $table . ': ' . $stmt->error);
            }
            $updatedByTable[$table] += $stmt->affected_rows;
        }
    }

    foreach ($legacyRows as $row) {
        $id = (int) $row['id'];
        $newProperty = (string) $row['new'];

        foreach ($legacyTargets as $target) {
            $table = $target['table'];
            $key = 'legacy|' . $table;
            if (!isset($statements[$key])) {
                continue;
            }

            $stmt = $statements[$key];
            $stmt->bind_param('si', $newProperty, $id);
            if (!$stmt->execute()) {
                throw new RuntimeException('Failed updating legacy table ' . $table . ': ' . $stmt->error);
            }
            $updatedByTable[$table] += $stmt->affected_rows;
        }
    }

    if ($apply) {
        $db->commit();
    } else {
        $db->rollback();
    }

    foreach ($statements as $stmt) {
        $stmt->close();
    }

    echo $apply ? 'Correction committed.' . PHP_EOL : 'Dry run complete; no changes were saved.' . PHP_EOL;
    ksort($updatedByTable);
    foreach ($updatedByTable as $table => $count) {
        echo $table . '_updated=' . (int) $count . PHP_EOL;
    }
} catch (Throwable $e) {
    $db->rollback();
    foreach ($statements as $stmt) {
        $stmt->close();
    }
    fwrite(STDERR, 'Correction failed: ' . $e->getMessage() . PHP_EOL);
    $db->close();
    exit(1);
}

$db->close();
