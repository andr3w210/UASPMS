<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Registry of Semi Expendable Property Issued';
$errors = [];
$rows = [];
$offices = [];

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$officeId = (int) ($_GET['office_id'] ?? 0);
$semiType = trim((string) ($_GET['semi_type'] ?? 'all'));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';

if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}

function semi_registry_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function semi_registry_person(array $row, string $prefix = ''): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row[$prefix . 'first_name'] ?? '')),
        trim((string) ($row[$prefix . 'middle_name'] ?? '')),
        trim((string) ($row[$prefix . 'last_name'] ?? '')),
        trim((string) ($row[$prefix . 'suffix_name'] ?? '')),
    ])));
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
        $returnsHasDetailLink = function_exists('schema_has_column')
            ? schema_has_column($db, 'returns', 'distribution_item_detail_id')
            : false;
        $disposalsHasDetailLink = function_exists('schema_has_column')
            ? schema_has_column($db, 'disposals', 'distribution_item_detail_id')
            : false;
        $poItemSupportsSemiType = false;
        $colRes = $db->query("SHOW COLUMNS FROM purchase_order_items LIKE 'semi_expendable_type'");
        if ($colRes && $colRes->num_rows > 0) {
            $poItemSupportsSemiType = true;
        }

        $returnsJoin = $returnsHasDetailLink
            ? "
            LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted'
            LEFT JOIN offices ro ON ro.id = d.office_id
            LEFT JOIN employees re ON re.id = d.employee_id
            "
            : "
            LEFT JOIN returns rt ON 1 = 0
            LEFT JOIN offices ro ON 1 = 0
            LEFT JOIN employees re ON 1 = 0
            ";

        $disposalsJoin = $disposalsHasDetailLink
            ? "
            LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id
            LEFT JOIN offices dof ON dof.id = d.office_id
            LEFT JOIN employees de ON de.id = d.employee_id
            "
            : "
            LEFT JOIN disposals dp ON 1 = 0
            LEFT JOIN offices dof ON 1 = 0
            LEFT JOIN employees de ON 1 = 0
            ";

        if (!$returnsHasDetailLink || !$disposalsHasDetailLink) {
            $errors[] = 'Return and disposal linkage is incomplete in the current database, so this registry shows issued items normally but may not reflect return/disposal history until the newer schema is applied.';
        }

        $sql = "
            SELECT
                did.id AS distribution_item_detail_id,
                d.distribution_date,
                d.document_no AS ics_no,
                did.property_number AS semi_property_number,
                poi.item_description,
                c.classification_name,
                c.classification_family,
                c.useful_life_years,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                di.unit_cost,
                rt.system_reference AS rrsp_no,
                rt.return_date,
                ro.office_name AS return_office_name,
                re.first_name AS return_first_name,
                re.middle_name AS return_middle_name,
                re.last_name AS return_last_name,
                re.suffix_name AS return_suffix_name,
                dp.system_reference AS disposal_ref,
                dp.disposal_date,
                dof.office_name AS disposal_office_name,
                de.first_name AS disposal_first_name,
                de.middle_name AS disposal_middle_name,
                de.last_name AS disposal_last_name,
                de.suffix_name AS disposal_suffix_name,
                CASE WHEN rt.id IS NOT NULL OR dp.id IS NOT NULL THEN 0 ELSE 1 END AS balance_qty
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'ics'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            {$returnsJoin}
            {$disposalsJoin}
            WHERE 1=1
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
            $errors[] = 'Unable to prepare the semi registry query.';
        }
    }
}

$rowCount = count($rows);
$balanceCount = 0;
$totalAmount = 0.0;
foreach ($rows as $row) {
    $balanceCount += (int) round((float) ($row['balance_qty'] ?? 0));
    $totalAmount += (float) ($row['unit_cost'] ?? 0);
}

if ($isPrint) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Registry of Semi Expendable Property Issued</title>
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
            <div class="small fst-italic">Annex A.4</div>
            <h4 class="mb-1">Registry of Semi Expendable Property Issued</h4>
            <div>Entity Name: University of Antique | Fund Cluster: _____________________</div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle">
                <thead>
                <tr>
                    <th rowspan="2">Date</th>
                    <th rowspan="2">Reference<br><small>ICS / RRSP No.</small></th>
                    <th rowspan="2">Item Description<br><small>Semi-expendable Property No.</small></th>
                    <th rowspan="2">Estimated Useful Life</th>
                    <th colspan="2" class="text-center">Issued</th>
                    <th colspan="2" class="text-center">Returned</th>
                    <th colspan="2" class="text-center">Re-issued</th>
                    <th colspan="2" class="text-center">Disposal</th>
                    <th rowspan="2" class="text-end">Balance Qty.</th>
                    <th rowspan="2" class="text-end">Amount</th>
                </tr>
                <tr>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h(!empty($row['distribution_date']) ? date('M d, Y', strtotime((string) $row['distribution_date'])) : ''); ?></td>
                        <td><div><?php echo h($row['ics_no'] ?? ''); ?></div><?php if (!empty($row['rrsp_no'])): ?><div><?php echo h($row['rrsp_no']); ?></div><?php endif; ?></td>
                        <td><div><?php echo h(semi_registry_label($row)); ?></div><div class="small text-muted"><?php echo h($row['semi_property_number'] ?? ''); ?></div></td>
                        <td><?php echo h(!empty($row['useful_life_years']) ? $row['useful_life_years'] . ' year(s)' : ''); ?></td>
                        <td class="text-end">1.00</td>
                        <td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', semi_registry_person($row)])))); ?></td>
                        <td class="text-end"><?php echo !empty($row['return_date']) ? '1.00' : '0.00'; ?></td>
                        <td><?php echo h(trim(implode(' / ', array_filter([$row['return_office_name'] ?? '', semi_registry_person($row, 'return_')])))); ?></td>
                        <td class="text-end">0.00</td>
                        <td></td>
                        <td class="text-end"><?php echo !empty($row['disposal_date']) ? '1.00' : '0.00'; ?></td>
                        <td><?php echo h(trim(implode(' / ', array_filter([$row['disposal_office_name'] ?? '', semi_registry_person($row, 'disposal_')])))); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) ($row['balance_qty'] ?? 0), 2)); ?></td>
                        <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="14" class="text-center text-muted py-4">No registry data found for the selected filters.</td></tr>
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
                        <h5 class="report-toolbar-title mb-0">Annex A.4</h5>
                        <p class="report-toolbar-copy">Monitor semi-expendable property movements across issue, return, and disposal in one running registry view.</p>
                    </div>
                    <div class="report-toolbar-actions">
                        <a href="<?php echo h(base_url('modules/reports/semi_registry.php?print=1&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '&office_id=' . $officeId . '&semi_type=' . urlencode($semiType))); ?>" class="btn btn-primary" target="_blank">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    </div>
                </div>
                <div class="report-summary-grid">
                    <div class="report-summary-card"><div class="report-summary-label">Registry Lines</div><div class="report-summary-value"><?php echo number_format($rowCount); ?></div><div class="report-summary-note">Semi property entries loaded into the registry.</div></div>
                    <div class="report-summary-card"><div class="report-summary-label">Balance Qty.</div><div class="report-summary-value"><?php echo number_format($balanceCount); ?></div><div class="report-summary-note">Items still on hand based on issue, return, and disposal state.</div></div>
                    <div class="report-summary-card"><div class="report-summary-label">Loaded Amount</div><div class="report-summary-value"><?php echo number_format($totalAmount, 2); ?></div><div class="report-summary-note">Total unit amount represented in the current registry view.</div></div>
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
                        <a href="<?php echo base_url('modules/reports/semi_registry.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
                </div>
                <div class="report-table-card table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>Date</th>
                            <th>Ref</th>
                            <th>Item / Property No.</th>
                            <th>Useful Life</th>
                            <th>Issued</th>
                            <th>Returned</th>
                            <th>Re-issued</th>
                            <th>Disposed</th>
                            <th>Balance</th>
                            <th class="text-end">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows): foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo h(!empty($row['distribution_date']) ? date('M d, Y', strtotime((string) $row['distribution_date'])) : ''); ?></td>
                                <td class="fw-semibold"><?php echo h($row['ics_no'] ?? ''); ?></td>
                                <td><div><?php echo h(semi_registry_label($row)); ?></div><div class="small text-muted"><?php echo h($row['semi_property_number'] ?? ''); ?></div></td>
                                <td><?php echo h(!empty($row['useful_life_years']) ? $row['useful_life_years'] . ' year(s)' : ''); ?></td>
                                <td><?php echo h(($row['office_name'] ?? '') . ' / ' . semi_registry_person($row)); ?></td>
                                <td><?php echo h(!empty($row['return_date']) ? (($row['return_office_name'] ?? '') . ' / ' . semi_registry_person($row, 'return_')) : ''); ?></td>
                                <td></td>
                                <td><?php echo h(!empty($row['disposal_date']) ? (($row['disposal_office_name'] ?? '') . ' / ' . semi_registry_person($row, 'disposal_')) : ''); ?></td>
                                <td><?php echo h(number_format((float) ($row['balance_qty'] ?? 0), 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="10" class="text-center text-muted py-4">No registry data found for the selected filters.</td></tr>
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
