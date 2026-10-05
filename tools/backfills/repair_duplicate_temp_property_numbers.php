<?php
/**
 * Give duplicate TEMP legacy assets unique temporary property numbers and sync references.
 * Dry-run by default. Use --apply to persist changes.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/common.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/employee_assignments.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/roles.php';
$db = tools_db();
$apply = in_array('--apply', $argv, true);

$result = $db->query("SELECT id, property_number FROM legacy_assets WHERE is_active = 1 AND property_number LIKE 'TEMP-%' ORDER BY property_number, id");
$groups = [];
foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
    $number = (string) $row['property_number'];
    if (preg_match('/^(TEMP-[A-Z0-9]+-\d{4})-/', $number, $match)) {
        $groups[$match[1]][] = [(int) $row['id'], $number];
    }
}

$duplicateNumbers = [];
$singleNumbers = [];
$duplicateResult = $db->query("SELECT property_number FROM legacy_assets WHERE is_active = 1 AND property_number LIKE 'TEMP-%' GROUP BY property_number HAVING COUNT(*) > 1");
foreach ($duplicateResult->fetch_all(MYSQLI_ASSOC) as $row) {
    $duplicateNumbers[(string) $row['property_number']] = true;
}
$singleResult = $db->query("SELECT property_number FROM legacy_assets WHERE is_active = 1 AND property_number LIKE 'TEMP-%' GROUP BY property_number HAVING COUNT(*) = 1");
foreach ($singleResult->fetch_all(MYSQLI_ASSOC) as $row) {
    $singleNumbers[(string) $row['property_number']] = true;
}

$changes = [];
foreach ($groups as $prefix => $records) {
    if (count($records) <= 1) {
        continue;
    }
    $sequence = 1;
    foreach ($records as [$assetId, $oldNumber]) {
        if (!isset($duplicateNumbers[$oldNumber])) {
            continue;
        }
        do {
            $newNumber = $prefix . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $sequence++;
        } while (isset($singleNumbers[$newNumber]));
        $singleNumbers[$newNumber] = true;
        $changes[] = [$assetId, $oldNumber, $newNumber];
    }
}

echo ($apply ? 'APPLY' : 'DRY-RUN') . ' duplicate TEMP property-number repair' . PHP_EOL;
echo 'assets_to_repair=' . count($changes) . PHP_EOL;
foreach ($changes as $change) {
    echo 'asset_id=' . $change[0] . ' | ' . $change[1] . ' -> ' . $change[2] . PHP_EOL;
}
if (!$apply || !$changes) {
    exit(0);
}

$db->begin_transaction();
try {
    $tables = [
        ['inventory_count_items', 'legacy_asset_id'],
        ['rpcppe_batch_items', 'legacy_asset_id'],
        ['asset_transfers', 'legacy_asset_id'],
        ['transfer_batch_items', 'legacy_asset_id'],
    ];
    foreach ($changes as [$assetId, $oldNumber, $newNumber]) {
        $stmt = $db->prepare('UPDATE legacy_assets SET property_number = ? WHERE id = ? AND property_number = ?');
        $stmt->bind_param('sis', $newNumber, $assetId, $oldNumber);
        $stmt->execute();
        $stmt->close();

        foreach ($tables as [$table, $idColumn]) {
            if (!schema_has_table($db, $table) || !schema_has_column($db, $table, 'property_number')) {
                continue;
            }
            $stmt = $db->prepare("UPDATE {$table} SET property_number = ? WHERE {$idColumn} = ? AND property_number = ?");
            $stmt->bind_param('sis', $newNumber, $assetId, $oldNumber);
            $stmt->execute();
            $stmt->close();
        }
    }
    $db->commit();
    echo 'Repair applied successfully.' . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Repair failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
