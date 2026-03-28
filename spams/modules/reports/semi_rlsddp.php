<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'RLSDDP - Semi-Expendable';
$errors = [];
$records = [];
$record = null;
$disposalId = (int) ($_GET['disposal_id'] ?? 0);
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

if ($db) {
    $listSql = "
        SELECT dp.id, dp.system_reference, dp.disposal_date, did.property_number, poi.item_description, c.classification_name, c.classification_family
        FROM disposals dp
        INNER JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
        LEFT JOIN classifications c ON c.id = poi.classification_id
        ORDER BY dp.disposal_date DESC, dp.id DESC
    ";
    $res = $db->query($listSql);
    if ($res) {
        $records = $res->fetch_all(MYSQLI_ASSOC);
    }

    if ($disposalId > 0) {
        $stmt = $db->prepare("
            SELECT
                dp.id, dp.system_reference, dp.disposal_date, dp.reason, dp.remarks,
                did.property_number, ri.unit_cost,
                d.document_no AS ics_no, d.distribution_date AS ics_date,
                o.office_name, e.first_name, e.middle_name, e.last_name, e.suffix_name, e.position_title,
                poi.item_description, c.classification_name, c.classification_family
            FROM disposals dp
            INNER JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            WHERE dp.id = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $disposalId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
        }
    }
}

function semi_rls_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function semi_rls_person(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

$status = strtolower((string) ($record['reason'] ?? ''));
$isLost = $status === 'lost';
$isDamaged = $status === 'beyond_repair' || $status === 'unserviceable';
$isStolen = false;
$isDestroyed = $status === 'obsolete';

if ($isPrint && $record) {
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RLSDDP <?php echo h($record['system_reference']); ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px}table{font-size:11px}@media print{.no-print{display:none!important}}</style></head><body>
    <div class="container py-3">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print"><button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button></div>
        <div class="text-center mb-3"><div class="small fst-italic">Annex A.9</div><h4 class="mb-1">Report of Lost, Stolen, Damaged or Destroyed Semi-Expendable Property</h4></div>
        <div class="row g-2 mb-3">
            <div class="col-md-6"><strong>Entity Name:</strong> University of Antique</div>
            <div class="col-md-6"><strong>Fund Cluster:</strong> __________________</div>
            <div class="col-md-6"><strong>Department/Office:</strong> <?php echo h($record['office_name'] ?? ''); ?></div>
            <div class="col-md-6"><strong>RLSDDP No.:</strong> <?php echo h($record['system_reference'] ?? ''); ?></div>
            <div class="col-md-6"><strong>Accountable Officer:</strong> <?php echo h(semi_rls_person($record)); ?></div>
            <div class="col-md-6"><strong>RLSDDP Date:</strong> <?php echo h(!empty($record['disposal_date']) ? date('M d, Y', strtotime((string) $record['disposal_date'])) : ''); ?></div>
            <div class="col-md-6"><strong>Designation:</strong> <?php echo h($record['position_title'] ?? ''); ?></div>
            <div class="col-md-6"><strong>ICS No.:</strong> <?php echo h($record['ics_no'] ?? ''); ?></div>
        </div>
        <div class="mb-3"><strong>Status of Property:</strong> [<?php echo $isLost ? '/' : ' '; ?>] Lost [<?php echo $isDamaged ? '/' : ' '; ?>] Damaged [<?php echo $isStolen ? '/' : ' '; ?>] Stolen [<?php echo $isDestroyed ? '/' : ' '; ?>] Destroyed</div>
        <table class="table table-bordered align-middle"><thead><tr><th>Property No.</th><th>Description</th><th class="text-end">Acquisition Cost</th></tr></thead><tbody><tr><td><?php echo h($record['property_number'] ?? ''); ?></td><td><?php echo h(semi_rls_label($record)); ?></td><td class="text-end"><?php echo h(number_format((float) ($record['unit_cost'] ?? 0), 2)); ?></td></tr></tbody></table>
        <div class="mt-3"><strong>Circumstances:</strong><div class="border p-3" style="min-height:110px;"><?php echo nl2br(h(trim(implode("\n", array_filter([$record['reason'] ?? '', $record['remarks'] ?? '']))))); ?></div></div>
    </div></body></html>
    <?php exit; }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-body p-4">
<div class="report-page-shell">
<div class="report-toolbar"><div><h5 class="report-toolbar-title mb-0">Annex A.9</h5><p class="report-toolbar-copy">Choose a posted semi-expendable disposal record to prepare the official RLSDDP printout.</p></div><div class="report-toolbar-actions"><?php if ($record): ?><a href="<?php echo h(base_url('modules/reports/semi_rlsddp.php?disposal_id=' . $disposalId . '&print=1')); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a><?php endif; ?></div></div>
<div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Available Records</div><div class="report-summary-value"><?php echo number_format(count($records)); ?></div><div class="report-summary-note">Semi disposal entries that can generate RLSDDP.</div></div><div class="report-summary-card"><div class="report-summary-label">Loaded Record</div><div class="report-summary-value"><?php echo $record ? 'Ready' : 'None'; ?></div><div class="report-summary-note"><?php echo h($record['system_reference'] ?? 'Select one disposal record to preview.'); ?></div></div></div>
<div class="report-filter-card"><h6 class="report-filter-title">Load Disposal Record</h6><form method="get" class="row g-3 align-items-end"><div class="col-md-8"><label class="form-label">Disposal Record</label><select class="form-select" name="disposal_id"><option value="0">Select semi disposal</option><?php foreach ($records as $rw): ?><option value="<?php echo (int) $rw['id']; ?>" <?php echo $disposalId === (int) $rw['id'] ? 'selected' : ''; ?>><?php echo h(($rw['system_reference'] ?? '') . ' | ' . ($rw['property_number'] ?? '') . ' | ' . semi_rls_label($rw)); ?></option><?php endforeach; ?></select></div><div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-primary">Load RLSDDP</button><a href="<?php echo base_url('modules/reports/semi_rlsddp.php'); ?>" class="btn btn-outline-secondary">Clear</a></div></form></div>
<?php if (!$record): ?><div class="report-empty-state">Select a posted semi-expendable disposal record to preview the RLSDDP.</div><?php endif; ?>
</div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
