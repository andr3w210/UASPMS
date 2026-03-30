<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Registry of Semi Expendable Property Issued';
$errors = [];
$rows = [];
$offices = [];
$accountCodes = [];

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$officeId = (int) ($_GET['office_id'] ?? 0);
$accountCodeId = (int) ($_GET['account_code_id'] ?? 0);
$fundNumber = trim((string) ($_GET['fund_number'] ?? ''));
$semiType = trim((string) ($_GET['semi_type'] ?? 'all'));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}
if (!in_array($fundNumber, ['', '01', '05', '06', '07'], true)) {
    $fundNumber = '';
}

function semi_registry_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function semi_registry_label(array $row): string
{
    return trim(implode(', ', array_filter([
        trim((string) ($row['item_description'] ?? '')),
        trim((string) ($row['brand'] ?? '')),
        trim((string) ($row['model'] ?? '')),
        trim((string) ($row['serial_no'] ?? '')),
    ])));
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
    $accountCodeResult = $db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC, account_name ASC");
    if ($accountCodeResult) {
        $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);
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
                rid.brand,
                rid.model,
                rid.serial_no,
                c.classification_name,
                c.classification_family,
                c.useful_life_years,
                ac.account_code,
                ac.account_name,
                f.fund_code,
                f.fund_source,
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
            LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
            LEFT JOIN receivings rcv ON rcv.id = ri.receiving_id
            LEFT JOIN purchase_orders po ON po.id = rcv.purchase_order_id
            LEFT JOIN funds f ON f.id = po.fund_id
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
        if ($accountCodeId > 0) {
            $sql .= " AND poi.account_code_id = ?";
            $types .= 'i';
            $params[] = $accountCodeId;
        }
        if ($fundNumber !== '') {
            $sql .= " AND (f.fund_code LIKE ? OR f.fund_source LIKE ?)";
            $types .= 'ss';
            $params[] = '%' . $fundNumber . '%';
            $params[] = '%' . $fundNumber . '%';
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
                $row['fund_number'] = semi_registry_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
                $rows[] = $row;
            }
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

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            !empty($row['distribution_date']) ? date('Y-m-d', strtotime((string) $row['distribution_date'])) : '',
            $row['ics_no'] ?? '',
            $row['rrsp_no'] ?? '',
            semi_registry_label($row),
            $row['semi_property_number'] ?? '',
            !empty($row['useful_life_years']) ? $row['useful_life_years'] . ' year(s)' : '',
            '1',
            trim(implode(' / ', array_filter([$row['office_name'] ?? '', semi_registry_person($row)]))),
            !empty($row['return_date']) ? '1' : '0',
            !empty($row['return_date']) ? trim(implode(' / ', array_filter([$row['return_office_name'] ?? '', semi_registry_person($row, 'return_')]))) : '',
            !empty($row['disposal_date']) ? '1' : '0',
            trim(implode(' / ', array_filter([$row['disposal_office_name'] ?? '', semi_registry_person($row, 'disposal_')]))),
            format_quantity($row['balance_qty'] ?? 0),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            trim((string) ($row['fund_number'] ?? '')),
        ];
    }
    export_excel_rows('semi_registry_' . date('Ymd') . '.xls', ['Date', 'ICS No.', 'RRSP No.', 'Item Description', 'Semi-Expendable Property No.', 'Estimated Useful Life', 'Issued Qty', 'Issued Office/Officer', 'Returned Qty', 'Returned Office/Officer', 'Disposal Qty', 'Disposal Office/Officer', 'Balance Qty', 'Amount', 'Fund Number'], $exportRows);
}

if ($isPrint) {
    $reportFundCluster = report_fund_cluster($rows, $fundNumber);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Registry of Semi Expendable Property Issued</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: landscape; margin: 0.35in; }
            body { color: #000; font-family: "Times New Roman", serif; font-size: 12px; overflow-x: auto; }
            .registry-wrap { max-width: 1560px; margin: 0 auto; }
            .appendix { text-align: right; font-style: italic; font-size: 14px; margin-bottom: 24px; }
            .title { text-align: center; font-size: 20px; font-weight: 700; text-transform: uppercase; margin-bottom: 28px; }
            .meta-line { display: flex; justify-content: space-between; gap: 1rem; font-size: 14px; margin-bottom: 10px; }
            .meta-fill { display: inline-block; min-width: 200px; border-bottom: 1px solid #000; padding: 0 6px 2px; }
            .meta-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .registry-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .registry-table th, .registry-table td { border: 1px solid #000; padding: 4px 5px; vertical-align: top; }
            .registry-table th { text-align: center; font-weight: 700; line-height: 1.15; }
            .registry-table .qty { text-align: center; }
            .registry-table .money { text-align: right; white-space: nowrap; }
            .registry-table tbody td { height: 24px; }
            @media screen and (max-width: 991.98px) { .registry-wrap { min-width: 1320px; padding-bottom: 1rem; } }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
    <div class="registry-wrap py-3">
        <?php render_print_action_bar(); ?>
        <div class="appendix">Annex A.4</div>
        <div class="title">Registry Semi Expendable Property Issued</div>
        <div class="meta-line">
            <div>Entity Name: <span class="meta-fill emphasis"><?php echo h(APP_NAME); ?></span></div>
            <div>Fund Cluster : <span class="meta-fill emphasis"><?php echo h($reportFundCluster); ?></span></div>
        </div>
        <div class="table-responsive">
            <table class="registry-table">
                <thead>
                <tr>
                    <th rowspan="2">Date</th>
                    <th colspan="2">Reference</th>
                    <th rowspan="2">Item Description</th>
                    <th rowspan="2">Estimated Useful Life</th>
                    <th colspan="2" class="text-center">Issued</th>
                    <th colspan="2" class="text-center">Returned</th>
                    <th colspan="2" class="text-center">Re-issued</th>
                    <th class="text-center">Disposal</th>
                    <th class="text-center">Balance</th>
                    <th rowspan="2" class="text-end">Amount</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th>ICS/RRSP No.</th>
                    <th>Semi-expendable Property No.</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th>Office / Officer</th>
                    <th class="text-end">Qty.</th>
                    <th class="text-end">Qty.</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo h(!empty($row['distribution_date']) ? date('M d, Y', strtotime((string) $row['distribution_date'])) : ''); ?></td>
                        <td><div><?php echo h($row['ics_no'] ?? ''); ?></div><?php if (!empty($row['rrsp_no'])): ?><div><?php echo h($row['rrsp_no']); ?></div><?php endif; ?></td>
                        <td><?php echo h($row['semi_property_number'] ?? ''); ?></td>
                        <td><?php echo h(semi_registry_label($row)); ?></td>
                        <td><?php echo h(!empty($row['useful_life_years']) ? $row['useful_life_years'] . ' year(s)' : ''); ?></td>
                        <td class="qty">1</td>
                        <td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', semi_registry_person($row)])))); ?></td>
                        <td class="qty"><?php echo !empty($row['return_date']) ? '1' : ''; ?></td>
                        <td><?php echo h(!empty($row['return_date']) ? trim(implode(' / ', array_filter([$row['return_office_name'] ?? '', semi_registry_person($row, 'return_')]))) : ''); ?></td>
                        <td class="qty"></td>
                        <td></td>
                        <td class="qty"><?php echo !empty($row['disposal_date']) ? '1' : ''; ?></td>
                        <td class="qty"><?php echo h(format_quantity($row['balance_qty'] ?? 0)); ?></td>
                        <td class="money"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                        <td></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="14" class="text-center text-muted py-4">No registry data found for the selected filters.</td></tr>
                <?php endif; ?>
                <?php for ($i = count($rows); $i < 18; $i++): ?>
                    <tr>
                        <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                    </tr>
                <?php endfor; ?>
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
                        <a href="<?php echo h(base_url('modules/reports/semi_registry.php?' . http_build_query(array_filter([
                            'export' => 'excel',
                            'date_from' => $dateFrom !== '' ? $dateFrom : null,
                            'date_to' => $dateTo !== '' ? $dateTo : null,
                            'office_id' => $officeId ?: null,
                            'semi_type' => $semiType !== 'all' ? $semiType : null,
                            'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                            'account_code_id' => $accountCodeId ?: null,
                        ])))); ?>" class="btn btn-outline-success">
                            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                        </a>
                        <a href="<?php echo h(base_url('modules/reports/semi_registry.php?' . http_build_query(array_filter([
                            'print' => '1',
                            'date_from' => $dateFrom !== '' ? $dateFrom : null,
                            'date_to' => $dateTo !== '' ? $dateTo : null,
                            'office_id' => $officeId ?: null,
                            'semi_type' => $semiType !== 'all' ? $semiType : null,
                            'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                            'account_code_id' => $accountCodeId ?: null,
                        ])))); ?>" class="btn btn-primary" target="_blank">
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
                    <div class="col-md-2">
                        <label for="fund_number" class="form-label">Fund Number</label>
                        <select class="form-select" id="fund_number" name="fund_number">
                            <option value="">All fund numbers</option>
                            <option value="01" <?php echo $fundNumber === '01' ? 'selected' : ''; ?>>01</option>
                            <option value="05" <?php echo $fundNumber === '05' ? 'selected' : ''; ?>>05</option>
                            <option value="06" <?php echo $fundNumber === '06' ? 'selected' : ''; ?>>06</option>
                            <option value="07" <?php echo $fundNumber === '07' ? 'selected' : ''; ?>>07</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="account_code_id" class="form-label">Account Code</label>
                        <select class="form-select" id="account_code_id" name="account_code_id">
                            <option value="0">All account codes</option>
                            <?php foreach ($accountCodes as $accountCode): ?>
                                <option value="<?php echo (int) $accountCode['id']; ?>" <?php echo $accountCodeId === (int) $accountCode['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(trim(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Go</button>
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
                                <td><?php echo h(format_quantity($row['balance_qty'] ?? 0)); ?></td>
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
