<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Unserviceable Semi-Expendable Property';
$errors = [];
$rows = [];
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';
$unserviceableReasonSql = "'" . implode("','", disposal_unserviceable_reason_filters()) . "'";

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $sql = "
        SELECT
            dp.disposal_date,
            dp.reason,
            did.property_number,
            ri.unit_cost,
            r.received_date AS date_acquired,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            f.fund_code,
            f.fund_source
        FROM disposals dp
        INNER JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        WHERE dp.status = 'posted'
          AND dp.disposal_date <= ?
                    AND dp.reason IN ({$unserviceableReasonSql})
        ORDER BY dp.disposal_date DESC, dp.id DESC
    ";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $asOf);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $errors[] = 'Unable to prepare the semi unserviceable report query.';
    }
}

$rowCount = count($rows);
$totalValue = 0.0;
foreach ($rows as $row) {
    $totalValue += (float) ($row['unit_cost'] ?? 0);
}

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            normalize_date_string($row['date_acquired'] ?? null),
            semi_uns_label($row),
            $row['property_number'] ?? '',
            '1',
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            '',
            '',
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            disposal_reason_label($row['reason'] ?? ''),
            disposal_reason_label($row['reason'] ?? ''),
        ];
    }
    export_excel_rows('semi_unserviceable_' . date('Ymd') . '.xls', ['Date Acquired', 'Particulars / Articles', 'Semi-Expendable Property No.', 'Qty', 'Unit Cost', 'Total Cost', 'Accumulated Depreciation', 'Accumulated Impairment Losses', 'Carrying Amount', 'Remarks', 'Disposal'], $exportRows);
}

function semi_uns_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function semi_uns_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

foreach ($rows as $index => $row) {
    $rows[$index]['fund_number'] = semi_uns_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
}

if ($isPrint) {
    $reportFundCluster = report_fund_cluster($rows);
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Inventory and Inspection Report of Unserviceable Semi-Expendable Property</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>@page{size:portrait;margin:0.5in 0.07in 0.07in 0.07in}body{font-size:12px}table{font-size:11px}.sign-table td{border:1px solid #dee2e6;padding:12px 10px;vertical-align:top}.sign-label{font-weight:600;margin-bottom:24px}.sign-line{border-bottom:1px solid #111;height:26px;margin-bottom:8px}.sign-caption{font-size:10px;line-height:1.25}@media print{.no-print{display:none!important}}
            <?php echo print_page_number_css(); ?></style></head><body>
    <div class="container-fluid py-3">
        <?php render_print_action_bar(); ?>
        <?php render_simple_report_header('Annex A.10', 'Inventory and Inspection Report of Unserviceable Semi-Expendable Property', !empty($asOf) ? date('M d, Y', strtotime($asOf)) : '', $reportFundCluster); ?>
        <table class="table table-bordered align-middle">
            <thead><tr><th>Date Acquired</th><th>Particulars / Articles</th><th>Semi-expendable Property No.</th><th class="text-end">Qty</th><th class="text-end">Unit Cost</th><th class="text-end">Total Cost</th><th class="text-end">Accumulated Depreciation</th><th class="text-end">Accumulated Impairment Losses</th><th class="text-end">Carrying Amount</th><th>Reason (COA)</th><th>Disposal Classification</th></tr></thead>
            <tbody><?php if ($rows): foreach ($rows as $row): ?><tr><td><?php echo h(format_date($row['date_acquired'] ?? null)); ?></td><td><?php echo h(semi_uns_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td class="text-end">1.00</td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end"></td><td class="text-end"></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td><?php echo h(disposal_reason_label($row['reason'] ?? '')); ?></td><td><?php echo h(disposal_reason_label($row['reason'] ?? '')); ?></td></tr><?php endforeach; else: ?><tr><td colspan="11" class="text-center text-muted py-4">No unserviceable semi-expendable property found.</td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="8" class="text-end">Total Carrying Amount</th><th class="text-end"><?php echo h(number_format($totalValue, 2)); ?></th><th colspan="2"></th></tr></tfoot>
        </table>
        <?php render_inventory_committee_signature_grid('sign-table mt-3'); ?>
    </div>
<?php render_print_page_number(); ?></body></html>
    <?php exit; }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-body p-4">
<div class="report-page-shell">
<div class="report-toolbar"><div><h5 class="report-toolbar-title mb-0">Annex A.10</h5><p class="report-toolbar-copy">Prepare the inspection list for semi-expendable property already tagged as unserviceable, obsolete, or beyond repair.</p></div><div class="report-toolbar-actions"><a href="<?php echo h(base_url('modules/reports/semi_unserviceable.php?as_of=' . urlencode($asOf) . '&export=excel')); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a><a href="<?php echo h(base_url('modules/reports/semi_unserviceable.php?as_of=' . urlencode($asOf) . '&print=1')); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a></div></div>
<div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Loaded Assets</div><div class="report-summary-value"><?php echo number_format($rowCount); ?></div><div class="report-summary-note">Semi-expendable items marked unserviceable or obsolete.</div></div><div class="report-summary-card"><div class="report-summary-label">Total Unit Value</div><div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div><div class="report-summary-note">Combined unit cost of the current unserviceable list.</div></div><div class="report-summary-card"><div class="report-summary-label">As Of</div><div class="report-summary-value"><?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : '-'); ?></div><div class="report-summary-note">Date printed in the report header.</div></div></div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="report-filter-card"><h6 class="report-filter-title">Filter Report</h6><form method="get" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label">As Of</label><input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>"></div><div class="col-md-8 d-flex gap-2"><button type="submit" class="btn btn-primary">Load Report</button><a href="<?php echo base_url('modules/reports/semi_unserviceable.php'); ?>" class="btn btn-outline-secondary">Reset</a></div></form></div>
<div class="report-table-card table-responsive"><table class="table align-middle"><thead><tr><th>Date Acquired</th><th>Articles</th><th>Property No.</th><th class="text-end">Unit Cost</th><th>Reason (COA)</th><th>Disposal Classification</th></tr></thead><tbody><?php if ($rows): foreach ($rows as $row): ?><tr><td><?php echo h(format_date($row['date_acquired'] ?? null)); ?></td><td><?php echo h(semi_uns_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td><?php echo h(disposal_reason_label($row['reason'] ?? '')); ?></td><td><?php echo h(disposal_reason_label($row['reason'] ?? '')); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted py-4">No unserviceable semi-expendable property found.</td></tr><?php endif; ?></tbody><tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?php echo h(number_format($totalValue, 2)); ?></th><th colspan="2"></th></tr></tfoot></table></div>
</div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
