<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$page_title = 'Report of Semi-Expendable Property Issued';
$errors = [];
$rows = [];
$offices = [];

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$officeId = (int) ($_GET['office_id'] ?? 0);
$semiType = trim((string) ($_GET['semi_type'] ?? 'all'));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}

$rowCount = count($rows);
$totalQuantity = 0.0;
$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalQuantity += (float) ($row['quantity_distributed'] ?? 0);
    $totalAmount += (float) ($row['line_total'] ?? 0);
}

function semi_issued_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $errors[] = 'Invalid date_from value.';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $errors[] = 'Invalid date_to value.';
    }

    if (!$errors) {
        $threshold = get_active_threshold($db);
        $semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000);
        $poItemSupportsSemiType = false;
        $colRes = $db->query("SHOW COLUMNS FROM purchase_order_items LIKE 'semi_expendable_type'");
        if ($colRes && $colRes->num_rows > 0) {
            $poItemSupportsSemiType = true;
        }

        $sql = "
            SELECT
                d.document_no AS ics_no,
                d.distribution_date,
                rc.code AS responsibility_center_code,
                did.property_number AS semi_property_number,
                poi.item_description,
                c.classification_name,
                c.classification_family,
                u.uom_name,
                u.abbreviation,
                di.quantity_distributed,
                di.unit_cost,
                di.line_total
            FROM distributions d
            INNER JOIN distribution_items di ON di.distribution_id = d.id
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
            INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
            LEFT JOIN responsibility_codes rc ON rc.office_id = d.office_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
            WHERE d.status = 'posted'
              AND d.document_type = 'ics'
        ";

        $types = '';
        $params = [];

        if ($dateFrom !== '') {
            $sql .= " AND d.distribution_date >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND d.distribution_date <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }
        if ($officeId > 0) {
            $sql .= " AND d.office_id = ?";
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

        $sql .= " ORDER BY d.distribution_date DESC, d.document_no DESC, did.id ASC";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } else {
            $errors[] = 'Unable to prepare the semi-expendable issued report query.';
        }
    }
}

if ($isPrint) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Report of Semi-Expendable Property Issued</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-size: 12px; }
            table { font-size: 11px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.close()">Close</button>
            <button class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
        </div>
        <div class="text-center mb-3">
            <div class="small fst-italic">Annex A.7</div>
            <h4 class="mb-1">Report of Semi-Expendable Property Issued</h4>
            <div>Entity Name: University of Antique</div>
            <div>Fund Cluster: _____________________ | Date: <?php echo h($dateTo !== '' ? date('M d, Y', strtotime($dateTo)) : date('M d, Y')); ?></div>
        </div>
        <div class="small mb-2 text-muted">To be filled by the Property and / or Supply Division / Unit | To be filled out by the Accounting Division / Unit</div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead>
                <tr>
                    <th>ICS No.</th>
                    <th>Responsibility Center Code</th>
                    <th>Semi-expendable Property No.</th>
                    <th>Item Description</th>
                    <th>Unit</th>
                    <th class="text-end">Quantity Issued</th>
                    <th class="text-end">Unit Cost</th>
                    <th class="text-end">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h($row['ics_no'] ?? ''); ?></td>
                        <td><?php echo h($row['responsibility_center_code'] ?? ''); ?></td>
                        <td><?php echo h($row['semi_property_number'] ?? ''); ?></td>
                        <td><?php echo h(semi_issued_label($row)); ?></td>
                        <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                        <td class="text-end"><?php echo h(format_quantity($row['quantity_distributed'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) ($row['line_total'] ?? 0), 2)); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No semi-expendable issued data found for the selected filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            $row['ics_no'] ?? '',
            $row['responsibility_center_code'] ?? '',
            $row['semi_property_number'] ?? '',
            semi_issued_label($row),
            trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? ''))),
            format_quantity($row['quantity_distributed'] ?? 0),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            number_format((float) ($row['line_total'] ?? 0), 2),
        ];
    }
    export_excel_rows('semi_issued_report_' . date('Ymd') . '.xls', ['ICS No.', 'Responsibility Center Code', 'Semi-Expendable Property No.', 'Item Description', 'Unit', 'Quantity Issued', 'Unit Cost', 'Amount'], $exportRows);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="report-page-shell">
                <div class="report-toolbar">
                    <div>
                        <h5 class="report-toolbar-title mb-0">Annex A.7</h5>
                        <p class="report-toolbar-copy">Track posted semi-expendable issuances by office, period, and value bucket before printing the official issued-property report.</p>
                    </div>
                    <div class="report-toolbar-actions">
                        <a href="<?php echo h(base_url('modules/reports/semi_issued_report.php?export=excel&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&office_id=' . $officeId . '&semi_type=' . urlencode($semiType))); ?>" class="btn btn-outline-success">
                            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                        </a>
                        <a href="<?php echo h(base_url('modules/reports/semi_issued_report.php?print=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&office_id=' . $officeId . '&semi_type=' . urlencode($semiType))); ?>" class="btn btn-primary" target="_blank">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </div>
                </div>
                <div class="report-summary-grid">
                    <div class="report-summary-card">
                        <div class="report-summary-label">Issued Lines</div>
                        <div class="report-summary-value"><?php echo number_format($rowCount); ?></div>
                        <div class="report-summary-note">Loaded ICS detail rows for the current filters.</div>
                    </div>
                    <div class="report-summary-card">
                        <div class="report-summary-label">Total Quantity</div>
                        <div class="report-summary-value"><?php echo format_quantity($totalQuantity); ?></div>
                        <div class="report-summary-note">Combined distributed quantity in this report run.</div>
                    </div>
                    <div class="report-summary-card">
                        <div class="report-summary-label">Total Amount</div>
                        <div class="report-summary-value"><?php echo number_format($totalAmount, 2); ?></div>
                        <div class="report-summary-note">Issued value based on posted distribution lines.</div>
                    </div>
                </div>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <div class="report-filter-card">
                <h6 class="report-filter-title">Filter Report</h6>
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="office_id" class="form-label">Office</label>
                        <select class="form-select" id="office_id" name="office_id">
                            <option value="0">All offices</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="semi_type" class="form-label">Semi Type</label>
                        <select class="form-select" id="semi_type" name="semi_type">
                            <option value="all" <?php echo $semiType === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="high_value" <?php echo $semiType === 'high_value' ? 'selected' : ''; ?>>High Value</option>
                            <option value="low_value" <?php echo $semiType === 'low_value' ? 'selected' : ''; ?>>Low Value</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
                    </div>
                    <div class="col-md-12">
                        <a href="<?php echo base_url('modules/reports/semi_issued_report.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                </div>
                <div class="report-table-card table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>ICS No.</th>
                            <th>RC Code</th>
                            <th>Semi Property No.</th>
                            <th>Item Description</th>
                            <th>Unit</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows): foreach ($rows as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?php echo h($row['ics_no'] ?? ''); ?></td>
                                <td><?php echo h($row['responsibility_center_code'] ?? ''); ?></td>
                                <td><?php echo h($row['semi_property_number'] ?? ''); ?></td>
                                <td><?php echo h(semi_issued_label($row)); ?></td>
                                <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                <td class="text-end"><?php echo h(format_quantity($row['quantity_distributed'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['line_total'] ?? 0), 2)); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">No semi-expendable issued data found for the selected filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
