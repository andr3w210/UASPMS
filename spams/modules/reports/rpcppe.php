<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'RPCPPE';
$errors = [];
$rows = [];
$offices = [];
$officeId = (int) ($_GET['office_id'] ?? 0);
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    ensure_distribution_item_runtime_columns($db);

    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    $systemSql = "
        SELECT
            'system' AS source_type,
            did.property_number,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            ri.unit_cost,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            COALESCE(curr_e.first_name, e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, e.suffix_name) AS suffix_name
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        WHERE (did.is_disposed IS NULL OR did.is_disposed = 0)
    ";
    $types = '';
    $params = [];
    if ($officeId > 0) {
        $systemSql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
        $types .= 'i';
        $params[] = $officeId;
    }
    $systemSql .= " ORDER BY c.classification_name ASC, poi.item_description ASC, did.property_number ASC";
    $stmt = $db->prepare($systemSql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $legacySql = "
        SELECT
            'legacy' AS source_type,
            la.property_number,
            la.item_description,
            c.classification_name,
            c.classification_family,
            '' AS uom_name,
            '' AS abbreviation,
            la.unit_cost,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        WHERE la.is_active = 1
          AND la.item_type = 'equipment'
    ";
    $legacyTypes = '';
    $legacyParams = [];
    if ($officeId > 0) {
        $legacySql .= " AND la.office_id = ?";
        $legacyTypes .= 'i';
        $legacyParams[] = $officeId;
    }
    $legacySql .= " ORDER BY c.classification_name ASC, la.item_description ASC, la.property_number ASC";
    $legacyStmt = $db->prepare($legacySql);
    if ($legacyStmt) {
        if ($legacyParams) {
            $legacyStmt->bind_param($legacyTypes, ...$legacyParams);
        }
        $legacyStmt->execute();
        $legacyRows = $legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $legacyStmt->close();
        $rows = array_merge($rows, $legacyRows);
    }
}

function rpcppe_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function rpcppe_person(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

if ($isPrint) {
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RPCPPE</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px}table{font-size:11px}@media print{.no-print{display:none!important}}</style></head><body>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print"><button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button></div>
        <div class="text-center mb-3"><div class="small fst-italic">Appendix 73</div><h4 class="mb-1">Report on the Physical Count of Property, Plant and Equipment</h4><div>As at <?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : ''); ?></div><div>Fund Cluster: ________________________________</div></div>
        <table class="table table-bordered align-middle">
            <thead><tr><th rowspan="2">Article</th><th rowspan="2">Description</th><th rowspan="2">Property Number</th><th rowspan="2">Unit of Measure</th><th rowspan="2" class="text-end">Unit Value</th><th colspan="2" class="text-center">Quantity per Property Card</th><th colspan="2" class="text-center">Quantity per Physical Count</th><th colspan="2" class="text-center">Shortage / Overage</th><th rowspan="2">Remarks</th></tr><tr><th class="text-end">Qty</th><th class="text-end">Value</th><th class="text-end">Qty</th><th class="text-end">Value</th><th class="text-end">Qty</th><th class="text-end">Value</th></tr></thead>
            <tbody><?php if ($rows): $i=1; foreach ($rows as $row): ?><tr><td><?php echo $i++; ?></td><td><?php echo h(rpcppe_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end">1.00</td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end">1.00</td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end">0.00</td><td class="text-end">0.00</td><td><?php echo h(($row['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : ''); ?></td></tr><?php endforeach; else: ?><tr><td colspan="12" class="text-center text-muted py-4">No PPE records found for the selected filters.</td></tr><?php endif; ?></tbody>
        </table>
    </div></body></html>
    <?php exit; }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';

$rowCount = count($rows);
$totalValue = 0.0;
$legacyCount = 0;
foreach ($rows as $row) {
    $totalValue += (float) ($row['unit_cost'] ?? 0);
    if (($row['source_type'] ?? '') === 'legacy') {
        $legacyCount++;
    }
}
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-body p-4">
<div class="report-page-shell">
<div class="report-toolbar"><div><h5 class="report-toolbar-title mb-0">RPCPPE</h5><p class="report-toolbar-copy">Review current accountable equipment from both posted system transactions and beginning-balance assets, then print the official physical count report.</p></div><div class="report-toolbar-actions"><a href="<?php echo h(base_url('modules/reports/rpcppe.php?office_id=' . $officeId . '&as_of=' . urlencode($asOf) . '&print=1')); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a></div></div>
<div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Loaded Assets</div><div class="report-summary-value"><?php echo number_format($rowCount); ?></div><div class="report-summary-note">Equipment records in the current count sheet.</div></div><div class="report-summary-card"><div class="report-summary-label">Total Value</div><div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div><div class="report-summary-note">Combined unit value of the loaded equipment.</div></div><div class="report-summary-card"><div class="report-summary-label">Beginning Balance</div><div class="report-summary-value"><?php echo number_format($legacyCount); ?></div><div class="report-summary-note">Legacy equipment merged into this RPCPPE run.</div></div></div>
<div class="report-filter-card"><h6 class="report-filter-title">Filter Report</h6><form method="get" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label">Office</label><select class="form-select" name="office_id"><option value="0">All offices</option><?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">As Of</label><input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>"></div><div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-primary">Load Report</button><a href="<?php echo base_url('modules/reports/rpcppe.php'); ?>" class="btn btn-outline-secondary">Reset</a></div></form></div>
<div class="report-table-card table-responsive"><table class="table align-middle"><thead><tr><th>Article</th><th>Description</th><th>Property No.</th><th class="text-end">Unit Value</th><th>Office / Officer</th><th>Source</th></tr></thead><tbody><?php if ($rows): $i=1; foreach ($rows as $row): ?><tr><td><?php echo $i++; ?></td><td><?php echo h(rpcppe_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', rpcppe_person($row)])))); ?></td><td><?php echo h(($row['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System'); ?></td></tr><?php endforeach; else: ?><tr><td colspan="6" class="text-center text-muted py-4">No PPE records found for the selected filters.</td></tr><?php endif; ?></tbody></table></div>
</div>
</div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
