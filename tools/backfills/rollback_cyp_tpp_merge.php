<?php
/**
 * Roll back the 2026-09-23 CYP -> TPP office merge.
 * Dry-run by default. Use --apply to persist the targeted reversal.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once dirname(__DIR__, 2) . '/spams/app/helpers/employee_assignments.php';
$db = tools_db();
$apply = in_array('--apply', $argv, true);
$sourceOfficeId = 77;
$targetOfficeId = 97;
$mergeAt = '2026-09-23 15:57:33';

$legacy = $db->query("SELECT id FROM legacy_assets WHERE office_id = {$targetOfficeId} AND property_number LIKE '%-CYP'")->fetch_all(MYSQLI_ASSOC);
$detailRows = $db->query("SELECT did.id, di.distribution_id FROM distribution_item_details did JOIN distribution_items di ON di.id = did.distribution_item_id JOIN distributions d ON d.id = di.distribution_id WHERE d.office_id = {$targetOfficeId} AND did.property_number LIKE '%-CYP'")->fetch_all(MYSQLI_ASSOC);
$distributionIds = array_values(array_unique(array_map('intval', array_column($detailRows, 'distribution_id'))));

$summary = [
    'legacy_assets' => count($legacy),
    'distribution_details' => count($detailRows),
    'distributions' => count($distributionIds),
    'responsibility_code' => 0,
    'location_pin' => 0,
    'source_assignment' => 0,
];
$rcCheck = $db->query("SELECT id FROM responsibility_codes WHERE id = 76 AND office_id = {$targetOfficeId} LIMIT 1");
$summary['responsibility_code'] = $rcCheck && $rcCheck->num_rows ? 1 : 0;
$pinCheck = $db->query("SELECT id FROM office_location_pins WHERE id = 18 AND office_id = {$targetOfficeId} LIMIT 1");
$summary['location_pin'] = $pinCheck && $pinCheck->num_rows ? 1 : 0;
$assignmentCheck = $db->query("SELECT id FROM employee_assignments WHERE id = 74 AND office_id = {$sourceOfficeId} AND is_active = 0 LIMIT 1");
$summary['source_assignment'] = $assignmentCheck && $assignmentCheck->num_rows ? 1 : 0;

echo ($apply ? 'APPLY' : 'DRY-RUN') . " CYP -> TPP rollback" . PHP_EOL;
foreach ($summary as $label => $count) { echo $label . '=' . $count . PHP_EOL; }
if (!$apply) { exit(0); }

$db->begin_transaction();
try {
    $stmt = $db->prepare("UPDATE legacy_assets SET office_id = ? WHERE office_id = ? AND property_number LIKE '%-CYP'");
    $stmt->bind_param('ii', $sourceOfficeId, $targetOfficeId);
    $stmt->execute();
    $stmt->close();

    foreach ($distributionIds as $distributionId) {
        $stmt = $db->prepare('UPDATE distributions SET office_id = ? WHERE id = ? AND office_id = ?');
        $stmt->bind_param('iii', $sourceOfficeId, $distributionId, $targetOfficeId);
        $stmt->execute();
        $stmt->close();
    }
    foreach ($detailRows as $detail) {
        $detailId = (int) $detail['id'];
        $stmt = $db->prepare('UPDATE distribution_item_details SET current_office_id = ? WHERE id = ? AND current_office_id = ?');
        $stmt->bind_param('iii', $sourceOfficeId, $detailId, $targetOfficeId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $db->prepare('UPDATE responsibility_codes SET office_id = ?, updated_at = NOW() WHERE id = 76 AND office_id = ?');
    $stmt->bind_param('ii', $sourceOfficeId, $targetOfficeId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE office_location_pins SET office_id = ?, office_name_snapshot = "COCOON Yearbook Production", manual_location = "COCOON Yearbook Production", updated_at = NOW() WHERE id = 18 AND office_id = ?');
    $stmt->bind_param('ii', $sourceOfficeId, $targetOfficeId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE employee_assignments SET office_id = ?, is_active = 1, is_unit_head = 1, end_date = NULL, updated_at = NOW() WHERE id = 74 AND employee_id = 74 AND office_id = ?');
    $stmt->bind_param('ii', $sourceOfficeId, $sourceOfficeId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE offices SET is_active = 1, office_head_employee_id = 74, updated_at = NOW() WHERE id = 77');
    $stmt->execute();
    $stmt->close();

    employee_sync_legacy_assignment_fields($db, 74);
    employee_sync_office_head_cache($db, 77);
    $db->commit();
    echo "Rollback applied successfully." . PHP_EOL;
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'Rollback failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
