<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'RSMI';
$errors = [];
$rows = [];
$offices = [];

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$officeId = (int) ($_GET['office_id'] ?? 0);
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

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
        $sql = "
            SELECT
                i.id,
                i.system_reference AS ris_no,
                i.issuance_date,
                si.item_description,
                u.uom_name,
                u.abbreviation,
                ii.quantity_issued,
                ii.unit_cost,
                ii.line_total,
                o.office_name,
                e.employee_no,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                ac.account_code
            FROM issuances i
            INNER JOIN issuance_items ii ON ii.issuance_id = i.id
            LEFT JOIN stock_items si ON si.id = ii.stock_item_id
            LEFT JOIN purchase_order_items poi ON poi.id = si.purchase_order_item_id
            LEFT JOIN account_codes ac ON ac.id = COALESCE(poi.account_code_id, si.account_code_id)
            LEFT JOIN unit_of_measures u ON u.id = COALESCE(poi.unit_of_measure_id, si.unit_of_measure_id)
            LEFT JOIN offices o ON o.id = i.office_id
            LEFT JOIN employees e ON e.id = i.employee_id
            WHERE i.status = 'posted'
        ";

        $types = '';
        $params = [];

        if ($dateFrom !== '') {
            $sql .= " AND i.issuance_date >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND i.issuance_date <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }
        if ($officeId > 0) {
            $sql .= " AND i.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }

        $sql .= " ORDER BY i.issuance_date DESC, i.system_reference DESC, ii.id ASC";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } else {
            $errors[] = 'Unable to prepare the RSMI query.';
        }
    }
}

$rowCount = count($rows);
$totalQuantity = 0.0;
$totalAmount = 0.0;
foreach ($rows as $row) {
    $totalQuantity += (float) ($row['quantity_issued'] ?? 0);
    $totalAmount += (float) ($row['line_total'] ?? 0);
}

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            $row['ris_no'] ?? '',
            !empty($row['issuance_date']) ? date('Y-m-d', strtotime((string) $row['issuance_date'])) : '',
            $row['item_description'] ?? '',
            trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? ''))),
            format_quantity($row['quantity_issued'] ?? 0),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            number_format((float) ($row['line_total'] ?? 0), 2),
            $row['office_name'] ?? '',
            trim(employee_display_name($row) . (!empty($row['employee_no']) ? ' - ' . $row['employee_no'] : '')),
            $row['account_code'] ?? '',
        ];
    }
    export_excel_rows('rsmi_' . date('Ymd') . '.xls', ['RIS No', 'Date', 'Item Description', 'Unit', 'Qty Issued', 'Unit Cost', 'Total Amount', 'Office/Department', 'Issued To', 'Account Code'], $exportRows);
}

if ($isPrint) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>RSMI</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-size: 12px; }
            table { font-size: 11px; }
            @media print {
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>
        <div class="container-fluid py-3">
            <?php render_print_action_bar(); ?>
            <div class="text-center mb-3">
                <h4 class="mb-1">Report of Supplies and Materials Issued</h4>
                <div class="small text-muted">
                    <?php echo h($dateFrom !== '' ? date('M d, Y', strtotime($dateFrom)) : 'Beginning'); ?>
                    to
                    <?php echo h($dateTo !== '' ? date('M d, Y', strtotime($dateTo)) : 'Present'); ?>
                </div>
            </div>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>RIS No</th>
                            <th>Date</th>
                            <th>Item Description</th>
                            <th>Unit</th>
                            <th class="text-end">Qty Issued</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Total Amount</th>
                            <th>Office/Department</th>
                            <th>Issued To</th>
                            <th>Account Code</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo h($row['ris_no']); ?></td>
                                <td><?php echo h(date('M d, Y', strtotime($row['issuance_date']))); ?></td>
                                <td><?php echo h($row['item_description'] ?? ''); ?></td>
                                <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                <td class="text-end"><?php echo h(format_quantity($row['quantity_issued'])); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $row['unit_cost'], 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) $row['line_total'], 2)); ?></td>
                                <td><?php echo h($row['office_name'] ?? ''); ?></td>
                                <td><?php echo h(trim(employee_display_name($row) . (!empty($row['employee_no']) ? ' - ' . $row['employee_no'] : ''))); ?></td>
                                <td><?php echo h($row['account_code'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No RSMI data found for the selected filters.</td></tr>
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
                            <h5 class="report-toolbar-title mb-0">RSMI</h5>
                            <p class="report-toolbar-copy">Review posted RIS issuances by date and office, then print the official Report of Supplies and Materials Issued directly from the same screen.</p>
                        </div>
                        <div class="report-toolbar-actions">
                            <a href="<?php echo h(base_url('modules/reports/rsmi.php?export=excel&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&office_id=' . $officeId)); ?>" class="btn btn-outline-success">
                                <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                            </a>
                            <a href="<?php echo h(base_url('modules/reports/rsmi.php?print=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&office_id=' . $officeId)); ?>" class="btn btn-primary" target="_blank">
                                <i class="bi bi-printer me-1"></i>Print
                            </a>
                        </div>
                    </div>

                    <div class="report-summary-grid">
                        <div class="report-summary-card">
                            <div class="report-summary-label">Loaded Lines</div>
                            <div class="report-summary-value"><?php echo number_format($rowCount); ?></div>
                            <div class="report-summary-note">Issued supply rows in the current result.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Total Quantity</div>
                            <div class="report-summary-value"><?php echo format_quantity($totalQuantity); ?></div>
                            <div class="report-summary-note">Combined issued quantity for the selected filters.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Total Amount</div>
                            <div class="report-summary-value"><?php echo number_format($totalAmount, 2); ?></div>
                            <div class="report-summary-note">Line totals summed from posted RIS entries.</div>
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
                            <div class="col-md-4">
                                <label for="office_id" class="form-label">Office</label>
                                <select class="form-select" id="office_id" name="office_id" data-placeholder="All offices">
                                    <option value="0">All offices</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($office['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                                <a href="<?php echo base_url('modules/reports/rsmi.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="report-table-card table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>RIS No</th>
                                <th>Date</th>
                                <th>Item Description</th>
                                <th>Unit</th>
                                <th class="text-end">Qty Issued</th>
                                <th class="text-end">Unit Cost</th>
                                <th class="text-end">Total Amount</th>
                                <th>Office/Department</th>
                                <th>Issued To</th>
                                <th>Account Code</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($row['ris_no']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($row['issuance_date']))); ?></td>
                                        <td><?php echo h($row['item_description'] ?? ''); ?></td>
                                        <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                        <td class="text-end"><?php echo h(format_quantity($row['quantity_issued'])); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) $row['unit_cost'], 2)); ?></td>
                                        <td class="text-end"><?php echo h(number_format((float) $row['line_total'], 2)); ?></td>
                                        <td><?php echo h($row['office_name'] ?? ''); ?></td>
                                        <td><?php echo h(trim(employee_display_name($row) . (!empty($row['employee_no']) ? ' - ' . $row['employee_no'] : ''))); ?></td>
                                        <td><?php echo h($row['account_code'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="10" class="text-center text-muted py-4">No RSMI data found for the selected filters.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('#office_id').select2({
            width: '100%',
            placeholder: 'All offices',
            allowClear: false
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
