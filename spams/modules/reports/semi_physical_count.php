<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Physical Count of Semi-Expendable Property';
$errors = [];
$rows = [];
$offices = [];
$officeId = (int) ($_GET['office_id'] ?? 0);
$semiType = trim((string) ($_GET['semi_type'] ?? 'all'));
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    $threshold = get_active_threshold($db);
    $semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000);
    $poItemSupportsSemiType = false;
    $colRes = $db->query("SHOW COLUMNS FROM purchase_order_items LIKE 'semi_expendable_type'");
    if ($colRes && $colRes->num_rows > 0) {
        $poItemSupportsSemiType = true;
    }

    $sql = "
        SELECT
            did.id,
            did.property_number,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            di.unit_cost,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'ics'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        WHERE (did.is_disposed IS NULL OR did.is_disposed = 0)
    ";
    $types = '';
    $params = [];
    if ($officeId > 0) {
        $sql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
        $types .= 'i';
        $params[] = $officeId;
    }
    if ($semiType !== 'all') {
        if ($poItemSupportsSemiType) {
            $sql .= " AND poi.semi_expendable_type = ?";
            $types .= 's';
            $params[] = $semiType;
        } elseif ($semiType === 'high_value') {
            $sql .= " AND ri.unit_cost >= ?";
            $types .= 'd';
            $params[] = $semiHvMin;
        } else {
            $sql .= " AND ri.unit_cost < ?";
            $types .= 'd';
            $params[] = $semiHvMin;
        }
    }
    $sql .= " ORDER BY c.classification_name ASC, poi.item_description ASC, did.property_number ASC";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

function semi_pc_label(array $row): string
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

if ($isExport) {
    $exportRows = [];
    $article = 1;
    foreach ($rows as $row) {
        $exportRows[] = [
            $article++,
            semi_pc_label($row),
            $row['property_number'] ?? '',
            trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? ''))),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            '1',
            '1',
            '0',
        ];
    }
    export_excel_rows('semi_physical_count_' . date('Ymd') . '.xls', ['Article', 'Description', 'Semi-Expendable Property No.', 'Unit', 'Unit Value', 'Balance', 'On Hand', 'Shortage/Overage'], $exportRows);
}

if ($isPrint) {
    ?>
    <!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Physical Count of Semi-Expendable Property</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px}table{font-size:11px}@media print{.no-print{display:none!important}}</style></head><body>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print"><button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button><button class="btn btn-primary btn-sm" onclick="window.print()">Print</button></div>
        <div class="text-center mb-3"><div class="small fst-italic">Annex A.8</div><h4 class="mb-1">Report on the Physical Count of Semi-Expendable Property</h4><div>As at <?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : ''); ?></div></div>
        <table class="table table-bordered align-middle">
            <thead><tr><th rowspan="2">Article</th><th rowspan="2">Description</th><th rowspan="2">Semi-expendable Property No.</th><th rowspan="2">Unit of Measure</th><th rowspan="2" class="text-end">Unit Value</th><th colspan="2" class="text-center">Balance Per Card</th><th colspan="2" class="text-center">On Hand Per Count</th><th colspan="2" class="text-center">Shortage / Overage</th><th rowspan="2">Remarks</th></tr><tr><th class="text-end">Qty.</th><th class="text-end">Value</th><th class="text-end">Qty.</th><th class="text-end">Value</th><th class="text-end">Qty.</th><th class="text-end">Value</th></tr></thead>
            <tbody>
            <?php if ($rows): $i=1; foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><?php echo h(semi_pc_label($row)); ?></td>
                    <td><?php echo h($row['property_number'] ?? ''); ?></td>
                    <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                    <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                    <td class="text-end">1.00</td>
                    <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                    <td class="text-end">1.00</td>
                    <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                    <td class="text-end">0.00</td>
                    <td class="text-end">0.00</td>
                    <td></td>
                </tr>
            <?php endforeach; else: ?><tr><td colspan="12" class="text-center text-muted py-4">No semi property found for the selected filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></body></html>
    <?php exit; }

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4"><div class="col-12"><div class="card"><div class="card-body p-4">
<div class="report-page-shell">
<div class="report-toolbar"><div><h5 class="report-toolbar-title mb-0">Annex A.8</h5><p class="report-toolbar-copy">Validate current semi-expendable accountability by office and value type before printing the physical count form.</p></div><div class="report-toolbar-actions"><a href="<?php echo h(base_url('modules/reports/semi_physical_count.php?office_id=' . $officeId . '&semi_type=' . urlencode($semiType) . '&as_of=' . urlencode($asOf) . '&export=excel')); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a><a href="<?php echo h(base_url('modules/reports/semi_physical_count.php?office_id=' . $officeId . '&semi_type=' . urlencode($semiType) . '&as_of=' . urlencode($asOf) . '&print=1')); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a></div></div>
<div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Loaded Items</div><div class="report-summary-value"><?php echo number_format($rowCount); ?></div><div class="report-summary-note">Semi property rows ready for physical validation.</div></div><div class="report-summary-card"><div class="report-summary-label">Total Unit Value</div><div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div><div class="report-summary-note">Combined value represented in the current count sheet.</div></div><div class="report-summary-card"><div class="report-summary-label">As Of</div><div class="report-summary-value"><?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : '-'); ?></div><div class="report-summary-note">Reference cutoff date for the printed form.</div></div></div>
<div class="report-filter-card"><h6 class="report-filter-title">Filter Report</h6><form method="get" class="row g-3 align-items-end">
<div class="col-md-4"><label class="form-label">Office</label><select class="form-select" name="office_id"><option value="0">All offices</option><?php foreach ($offices as $office): ?><option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option><?php endforeach; ?></select></div>
<div class="col-md-3"><label class="form-label">Semi Type</label><select class="form-select" name="semi_type"><option value="all" <?php echo $semiType === 'all' ? 'selected' : ''; ?>>All</option><option value="high_value" <?php echo $semiType === 'high_value' ? 'selected' : ''; ?>>High Value</option><option value="low_value" <?php echo $semiType === 'low_value' ? 'selected' : ''; ?>>Low Value</option></select></div>
<div class="col-md-3"><label class="form-label">As Of</label><input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>"></div>
<div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary w-100">Load</button><a href="<?php echo base_url('modules/reports/semi_physical_count.php'); ?>" class="btn btn-outline-secondary">Reset</a></div>
</form></div>
<div class="report-table-card table-responsive"><table class="table align-middle"><thead><tr><th>Article</th><th>Description</th><th>Property No.</th><th>Unit</th><th class="text-end">Unit Value</th><th class="text-end">Balance</th><th class="text-end">On Hand</th><th class="text-end">Shortage/Overage</th></tr></thead><tbody><?php if ($rows): $i=1; foreach ($rows as $row): ?><tr><td><?php echo $i++; ?></td><td><?php echo h(semi_pc_label($row)); ?></td><td><?php echo h($row['property_number'] ?? ''); ?></td><td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td><td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td><td class="text-end">1.00</td><td class="text-end">1.00</td><td class="text-end">0.00</td></tr><?php endforeach; else: ?><tr><td colspan="8" class="text-center text-muted py-4">No semi property found for the selected filters.</td></tr><?php endif; ?></tbody></table></div>
</div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
