<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

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

function semi_issued_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
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
                f.fund_code,
                f.fund_source,
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
            LEFT JOIN receivings rcv ON rcv.id = ri.receiving_id
            LEFT JOIN purchase_orders po ON po.id = rcv.purchase_order_id
            LEFT JOIN funds f ON f.id = po.fund_id
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
            $queryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($queryRows as $row) {
                $row['fund_number'] = semi_issued_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
                $rows[] = $row;
            }
        } else {
            $errors[] = 'Unable to prepare the semi-expendable issued report query.';
        }
    }
}

$rowCount = count($rows);
$totalQuantity = 0.0;
$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalQuantity += (float) ($row['quantity_distributed'] ?? 0);
    $totalAmount += (float) ($row['line_total'] ?? 0);
}

if ($isPrint) {
    $reportFundCluster = report_fund_cluster($rows);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Report of Semi-Expendable Property Issued</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: portrait; margin: 0.5in 0.07in 0.07in 0.07in; }
            body { color: #000; font-family: "Times New Roman", serif; font-size: 12px; overflow-x: auto; }
            .issued-wrap { max-width: 900px; margin: 0 auto; }
            .appendix { text-align: right; font-style: italic; font-size: 14px; margin-bottom: 10px; }
            .title { text-align: center; font-size: 18px; font-weight: 700; text-transform: uppercase; margin-bottom: 14px; }
            .meta-grid { display: grid; grid-template-columns: 1fr 220px; gap: 10px 18px; margin-bottom: 8px; font-size: 13px; }
            .meta-row { display: flex; align-items: baseline; gap: 6px; }
            .meta-label { font-weight: 700; }
            .meta-fill { display: inline-block; min-width: 120px; flex: 1 1 auto; border-bottom: 1px solid #000; padding: 0 4px 1px; }
            .meta-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .issued-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .issued-table th, .issued-table td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; }
            .issued-table th { text-align: center; font-weight: 700; line-height: 1.1; }
            .issued-table .guide-row th { font-size: 10px; font-style: italic; font-weight: 400; padding: 2px 4px; }
            .issued-table .qty, .issued-table .unit { text-align: center; }
            .issued-table .money { text-align: right; white-space: nowrap; }
            .issued-table tbody td { height: 24px; }
            .footer-grid { width: 100%; border-collapse: collapse; margin-top: -1px; table-layout: fixed; }
            .footer-grid td { border: 1px solid #000; vertical-align: top; }
            .footer-cell { min-height: 96px; padding: 6px; position: relative; }
            .footer-label { font-size: 12px; }
            .signature-line { margin: 32px 18px 0; border-top: 1px solid #000; padding-top: 3px; text-align: center; font-size: 11px; }
            .posted-by-tag { position: absolute; top: 6px; left: 6px; right: 6px; border-bottom: 1px solid #000; padding-bottom: 2px; font-size: 12px; }
            @media screen and (max-width: 991.98px) { .issued-wrap { min-width: 860px; padding-bottom: 1rem; } }
            @media print { .no-print { display: none !important; } }
        
            <?php echo print_page_number_css(); ?></style>
    </head>
    <body>
    <div class="issued-wrap py-3">
        <?php render_print_action_bar(); ?>
        <div class="appendix">Annex A.7</div>
        <div class="title">Report of Semi-Expendable Property Issued</div>
        <div class="meta-grid">
            <div class="meta-row">
                <span class="meta-label">Entity Name :</span>
                <span class="meta-fill emphasis"><?php echo h(strtoupper(APP_NAME)); ?></span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Serial No. :</span>
                <span class="meta-fill">&nbsp;</span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Fund Cluster :</span>
                <span class="meta-fill emphasis"><?php echo h($reportFundCluster); ?></span>
            </div>
            <div class="meta-row">
                <span class="meta-label">Date :</span>
                <span class="meta-fill"><?php echo h($dateTo !== '' ? date('F d, Y', strtotime($dateTo)) : date('F d, Y')); ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="issued-table">
                <thead>
                <tr>
                    <th colspan="5">To be filled by the Property and / or Supply Division / Unit</th>
                    <th colspan="3">To be filled out by the Accounting Division / Unit</th>
                </tr>
                <tr class="guide-row">
                    <th>ICS No.</th>
                    <th>Responsibility Center Code</th>
                    <th>Semi-expendable Property</th>
                    <th>Item Description</th>
                    <th>Unit</th>
                    <th>Quantity<br>Issue</th>
                    <th>Unit Cost</th>
                    <th>Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h($row['ics_no'] ?? ''); ?></td>
                        <td><?php echo h($row['responsibility_center_code'] ?? ''); ?></td>
                        <td><?php echo h($row['semi_property_number'] ?? ''); ?></td>
                        <td><?php echo h(semi_issued_label($row)); ?></td>
                        <td class="unit"><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                        <td class="qty"><?php echo h(format_quantity($row['quantity_distributed'] ?? 0)); ?></td>
                        <td class="money"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                        <td class="money"><?php echo h(number_format((float) ($row['line_total'] ?? 0), 2)); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No semi-expendable issued data found for the selected filters.</td></tr>
                <?php endif; ?>
                <?php for ($i = count($rows); $i < 18; $i++): ?>
                    <tr>
                        <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
            <table class="footer-grid">
                <tr>
                    <td style="width:68%;">
                        <div class="footer-cell">
                            <div class="footer-label">I hereby certify to the correctness of the above information.</div>
                            <div class="signature-line">Signature Over Printed Name of Property and/ or Supply Custodian</div>
                        </div>
                    </td>
                    <td style="width:32%;">
                        <div class="footer-cell">
                            <div class="posted-by-tag">Posted by:</div>
                            <div class="signature-line" style="margin-top: 42px;">Signature over Printed Name of Designated</div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
    
<?php render_print_page_number(); ?></body>
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
