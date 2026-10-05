<?php
/**
 * Move legacy assets still accountable to Supply after their latest posted return into the Supply Pool.
 * Dry-run by default. Use --apply to persist changes.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/common.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/roles.php';
$db = tools_db();
$apply = in_array('--apply', $argv, true);

$sql = "SELECT la.id, la.property_number, la.office_id, la.employee_id, la.responsibility_code_id,
               o.office_code, o.office_name, rt.id AS return_id, rt.office_id AS returned_from_office_id,
               rt.employee_id AS returned_from_employee_id, rt.created_at AS returned_at, rt.created_by AS returned_by
        FROM legacy_assets la
        JOIN offices o ON o.id = la.office_id
        JOIN (
            SELECT legacy_asset_id, MAX(id) AS latest_id
            FROM returns WHERE source_type = 'legacy' AND status = 'posted'
            GROUP BY legacy_asset_id
        ) latest ON latest.legacy_asset_id = la.id
        JOIN returns rt ON rt.id = latest.latest_id
        WHERE la.is_active = 1
          AND COALESCE(la.accountability_status, 'active') = 'active'
          AND (o.office_code IN ('SPM', 'SPMU') OR o.office_name LIKE '%Supply and Property Management Unit%' OR o.office_name LIKE '%SPMU%')
          AND NOT EXISTS (
              SELECT 1 FROM asset_transfers at
              WHERE at.source_type = 'legacy' AND at.legacy_asset_id = la.id
                AND at.status = 'posted' AND at.created_at > rt.created_at
          )
        ORDER BY la.id";
$result = $db->query($sql);
$candidates = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
echo ($apply ? 'APPLY' : 'DRY-RUN') . ' legacy returned-asset Supply Pool backfill' . PHP_EOL;
echo 'assets=' . count($candidates) . PHP_EOL;
foreach ($candidates as $row) {
    echo $row['id'] . ' | ' . $row['property_number'] . ' | return #' . $row['return_id'] . ' | returned from office ' . ($row['returned_from_office_id'] ?? 'NULL') . PHP_EOL;
}
if (!$apply || !$candidates) {
    exit(0);
}

$db->begin_transaction();
try {
    $update = $db->prepare("UPDATE legacy_assets
        SET last_office_id = COALESCE(?, last_office_id),
            last_employee_id = COALESCE(?, last_employee_id),
            office_id = ?, employee_id = NULL, responsibility_code_id = NULL,
            accountability_status = 'for_reconciliation',
            accountability_cleared_at = COALESCE(?, NOW()),
            accountability_cleared_by = NULLIF(?, 0)
        WHERE id = ? AND is_active = 1 AND COALESCE(accountability_status, 'active') = 'active'");
    if (!$update) {
        throw new RuntimeException('Unable to prepare returned asset pool update.');
    }
    foreach ($candidates as $row) {
        $officeId = (int) $row['office_id'];
        $lastOfficeId = !empty($row['returned_from_office_id']) ? (int) $row['returned_from_office_id'] : null;
        $lastEmployeeId = !empty($row['returned_from_employee_id']) ? (int) $row['returned_from_employee_id'] : null;
        $clearedAt = (string) ($row['returned_at'] ?? '');
        $userId = (int) ($row['returned_by'] ?? 0);
        $assetId = (int) $row['id'];
        $update->bind_param('iiisii', $lastOfficeId, $lastEmployeeId, $officeId, $clearedAt, $userId, $assetId);
        if (!$update->execute()) {
            throw new RuntimeException('Unable to update asset #' . $assetId . ': ' . $update->error);
        }
    }
    $update->close();
    $db->commit();
    echo 'Supply Pool backfill applied.' . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
