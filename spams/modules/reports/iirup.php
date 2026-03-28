<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'IIRUP';
$rows = [];
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

if ($db) {
    ensure_disposals_runtime_schema($db);

    $sql = "
        SELECT
            dp.disposal_date,
            dp.reason,
            did.property_number,
            ri.unit_cost,
            r.received_date AS date_acquired,
            poi.item_description,
            c.classification_name,
            c.classification_family
        FROM disposals dp
        INNER JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        LEFT JOIN classifications c ON c.id = poi.classification_id
        WHERE dp.reason IN ('unserviceable','obsolete','beyond_repair')
        ORDER BY dp.disposal_date DESC, dp.id DESC
    ";
    $res = $db->query($sql);
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
}

function iirup_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

$rowCount = count($rows);
$totalValue = 0.0;
foreach ($rows as $row) {
    $totalValue += (float) ($row['unit_cost'] ?? 0);
}

if ($isPrint) {
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>IIRUP</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px}table{font-size:11px}@media print{.no-print{display:none!important}}</style></head><body>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print"><button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button></div>
        <div class="text-center mb-3"><div class="small fst-italic">Appendix 74</div><h4 class="mb-1">Inventory and Inspection Report of Unserviceable Property</h4><div>As at <?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : ''); ?></div><div>Entity Name: University of Antique | Fund Cluster: _____________________</div></div>
        <table class="table table-bordered align-middle"><thead><tr><th>Date Acquired</th><th>Particulars / Articles</th><th>Property No.</th><th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total Cost</th><th class="text-end">Accumulated Depreciation</th><th class="text-end">Accumulated Impairment Losses</th><th class="text-end">Carrying Amount</th><th>Remarks</th><th>Disposal</th></tr></thead><tbody><?php if ($rows): foreach ($rows as $row): ?><tr><td><?php echo h(!empty($row['date_acquired']) ? date('M d, Y', strtotime((string) $row['date_acquired'])) : ''); ?></td><td><?php echo h(iirup_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td class="text-end">1.00</td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end"></td><td class="text-end"></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td><?php echo h($row['reason'] ?? ''); ?></td><td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['reason'] ?? '')))); ?></td></tr><?php endforeach; else: ?><tr><td colspan="11" class="text-center text-muted py-4">No unserviceable property found.</td></tr><?php endif; ?></tbody></table>
    </div></body></html>
    <?php exit; }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-body p-4">
<div class="report-page-shell">
<div class="report-toolbar"><div><h5 class="report-toolbar-title mb-0">IIRUP</h5><p class="report-toolbar-copy">Review disposed and unserviceable equipment before printing the official Inventory and Inspection Report of Unserviceable Property.</p></div><div class="report-toolbar-actions"><a href="<?php echo h(base_url('modules/reports/iirup.php?as_of=' . urlencode($asOf) . '&print=1')); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a></div></div>
<div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Loaded Assets</div><div class="report-summary-value"><?php echo number_format($rowCount); ?></div><div class="report-summary-note">Unserviceable equipment in the current IIRUP view.</div></div><div class="report-summary-card"><div class="report-summary-label">Book Value</div><div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div><div class="report-summary-note">Total unit cost currently reflected in the result.</div></div><div class="report-summary-card"><div class="report-summary-label">Cutoff Date</div><div class="report-summary-value"><?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : '-'); ?></div><div class="report-summary-note">Reference date for the printout header.</div></div></div>
<div class="report-filter-card"><h6 class="report-filter-title">Filter Report</h6><form method="get" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label">As Of</label><input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>"></div><div class="col-md-8 d-flex gap-2"><button type="submit" class="btn btn-primary">Load Report</button><a href="<?php echo base_url('modules/reports/iirup.php'); ?>" class="btn btn-outline-secondary">Reset</a></div></form></div>
<div class="report-table-card table-responsive"><table class="table align-middle"><thead><tr><th>Date Acquired</th><th>Articles</th><th>Property No.</th><th class="text-end">Unit Cost</th><th>Reason</th><th>Disposal</th></tr></thead><tbody><?php if ($rows): foreach ($rows as $row): ?><tr><td><?php echo h(!empty($row['date_acquired']) ? date('M d, Y', strtotime((string) $row['date_acquired'])) : ''); ?></td><td><?php echo h(iirup_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td><?php echo h($row['reason'] ?? ''); ?></td><td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['reason'] ?? '')))); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted py-4">No unserviceable property found.</td></tr><?php endif; ?></tbody></table></div>
</div>
</div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
