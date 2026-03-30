<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Physical Count of Semi-Expendable Property';
$errors = [];
$rows = [];
$offices = [];
$accountCodes = [];
$officeId = (int) ($_GET['office_id'] ?? 0);
$accountCodeId = (int) ($_GET['account_code_id'] ?? 0);
$semiType = trim((string) ($_GET['semi_type'] ?? 'all'));
$fundNumber = trim((string) ($_GET['fund_number'] ?? ''));
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!in_array($semiType, ['all', 'high_value', 'low_value'], true)) {
    $semiType = 'all';
}
if (!in_array($fundNumber, ['', '01', '05', '06', '07'], true)) {
    $fundNumber = '';
}

function semi_pc_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function semi_pc_article(array $row): string
{
    return trim((string) ($row['classification_name'] ?? ''));
}

function semi_pc_description(array $row): string
{
    return trim(implode(', ', array_filter([
        trim((string) ($row['description_detail'] ?? '')),
        trim((string) ($row['brand'] ?? '')),
        trim((string) ($row['model'] ?? '')),
        trim((string) ($row['serial_no'] ?? '')),
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

    $threshold = get_active_threshold($db);
    $semiHvMin = (float) ($threshold['semi_hv_min'] ?? 5000.01);
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
            poi.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            di.unit_cost,
            rid.brand,
            rid.model,
            rid.serial_no,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'ics'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'semi_expendable'
        LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted' AND rt.return_date <= ?
        LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted' AND dp.disposal_date <= ?
        WHERE d.distribution_date <= ?
          AND rt.id IS NULL
          AND dp.id IS NULL
    ";
    $types = 'sss';
    $params = [$asOf, $asOf, $asOf];

    if ($officeId > 0) {
        $sql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
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
            $semiTypeValue = $semiType;
            $sql .= " AND poi.semi_expendable_type = ?";
            $types .= 's';
            $params[] = $semiTypeValue;
        } elseif ($semiType === 'high_value') {
            $sql .= " AND di.unit_cost >= ?";
            $types .= 'd';
            $params[] = $semiHvMin;
        } else {
            $sql .= " AND di.unit_cost < ?";
            $types .= 'd';
            $params[] = $semiHvMin;
        }
    }

    $sql .= " ORDER BY ac.account_code ASC, c.classification_name ASC, poi.item_description ASC, did.property_number ASC";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $queryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($queryRows as $row) {
            $row['fund_number'] = semi_pc_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
            $rows[] = $row;
        }
    } else {
        $errors[] = 'Unable to prepare the semi physical count query.';
    }
}

$rowCount = count($rows);
$totalValue = 0.0;
foreach ($rows as $row) {
    $totalValue += (float) ($row['unit_cost'] ?? 0);
}

$selectedAccountCode = null;
foreach ($accountCodes as $accountCode) {
    if ((int) $accountCode['id'] === $accountCodeId) {
        $selectedAccountCode = $accountCode;
        break;
    }
}

$typeOfProperty = report_account_name($rows, $selectedAccountCode, 'Semi-Expendable Property');

$presidentProfile = $db ? get_university_president_profile($db) : ['name' => '', 'title' => 'University President', 'appointment_date' => ''];
$presidentName = (string) ($presidentProfile['name'] ?? '');
$presidentPosition = (string) ($presidentProfile['title'] ?? 'University President');
$appointmentDate = (string) ($presidentProfile['appointment_date'] ?? '');
$entityName = APP_NAME;
$reportFundCluster = report_fund_cluster($rows, $fundNumber);

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
        $exportRows[] = [
            semi_pc_article($row),
            semi_pc_description($row),
            $row['property_number'] ?? '',
            trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? ''))),
            number_format((float) ($row['unit_cost'] ?? 0), 2),
            '1',
            '1',
            '0',
            '0.00',
            '',
        ];
    }
    export_excel_rows(
        'semi_physical_count_' . date('Ymd') . '.xls',
        ['Article', 'Description', 'Semi-Expendable Property No.', 'Unit of Measure', 'Unit Value', 'Balance Per Card Qty', 'On Hand Per Count Qty', 'Shortage/Overage Qty', 'Shortage/Overage Value', 'Remarks'],
        $exportRows
    );
}

if ($isPrint) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Physical Count of Semi-Expendable Property</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: landscape; margin: 0.35in; }
            body { color: #000; font-family: "Times New Roman", serif; font-size: 12px; overflow-x: auto; }
            .semi-wrap { max-width: 1320px; margin: 0 auto; }
            .appendix { text-align: right; font-style: italic; font-size: 14px; margin-bottom: 24px; }
            .title { text-align: center; font-size: 20px; font-weight: 700; text-transform: uppercase; margin-bottom: 18px; }
            .type-line { text-align: center; margin-bottom: 6px; }
            .type-fill { display: inline-block; min-width: 290px; border-bottom: 1px solid #000; padding: 0 8px 2px; }
            .type-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .type-caption { font-size: 12px; margin-top: 2px; }
            .asof-line { text-align: center; font-size: 16px; margin-bottom: 32px; }
            .asof-fill { display: inline-block; min-width: 160px; border-bottom: 1px solid #000; padding: 0 8px 2px; }
            .meta-line { font-size: 14px; margin-bottom: 8px; }
            .meta-fill { display: inline-block; min-width: 260px; border-bottom: 1px solid #000; padding: 0 6px 2px; }
            .meta-fill.short { min-width: 160px; }
            .meta-fill.long { min-width: 220px; }
            .meta-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .semi-table, .semi-sign { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .semi-table th, .semi-table td, .semi-sign td { border: 1px solid #000; padding: 4px 5px; }
            .semi-table th { text-align: center; font-weight: 700; vertical-align: middle; }
            .semi-table td { vertical-align: top; }
            .semi-table .qty { text-align: center; }
            .semi-table .money { text-align: right; white-space: nowrap; }
            .semi-table tbody td { height: 28px; }
            .semi-sign td { height: 132px; vertical-align: top; }
            .sign-label { font-size: 14px; margin-bottom: 34px; }
            .sign-line { width: 82%; margin: 0 auto 6px; border-bottom: 1px solid #000; height: 18px; }
            .sign-caption { text-align: center; line-height: 1.2; font-size: 14px; }
            @media screen and (max-width: 991.98px) {
                .semi-wrap { min-width: 1120px; padding-bottom: 1rem; }
            }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
    <div class="semi-wrap">
        <?php render_print_action_bar(); ?>

        <div class="appendix">Annex A.8</div>
        <div class="title">Report on the Physical Count of Semi-Expendable Property</div>

        <div class="type-line">
            <span class="type-fill emphasis"><?php echo h($typeOfProperty); ?></span>
            <div class="type-caption">(Type of Semi-expendable Property)</div>
        </div>

        <div class="asof-line">As at <span class="asof-fill"><?php echo h(!empty($asOf) ? date('F d, Y', strtotime($asOf)) : ''); ?></span></div>

        <div class="meta-line">Fund Cluster : <span class="meta-fill emphasis"><?php echo h($reportFundCluster); ?></span></div>
        <div class="meta-line">
            For which
            <span class="meta-fill short emphasis"><?php echo h($presidentName); ?></span>,
            <span class="meta-fill short emphasis"><?php echo h($presidentPosition); ?></span>,
            <span class="meta-fill short emphasis"><?php echo h($entityName); ?></span>
            is accountable, having assumed such accountability on
            <span class="meta-fill long emphasis"><?php echo h($appointmentDate !== '' ? format_date($appointmentDate, 'F d, Y') : ''); ?></span>.
        </div>

        <table class="semi-table">
            <colgroup>
                <col style="width:7%">
                <col style="width:19%">
                <col style="width:7.5%">
                <col style="width:6.5%">
                <col style="width:5.5%">
                <col style="width:8.5%">
                <col style="width:8.5%">
                <col style="width:7.5%">
                <col style="width:7%">
                <col style="width:18%">
            </colgroup>
            <thead>
                <tr>
                    <th rowspan="2">Article</th>
                    <th rowspan="2">Description</th>
                    <th rowspan="2">Semi-expendable<br>Property No.</th>
                    <th rowspan="2">Unit of<br>Measure</th>
                    <th rowspan="2">Unit<br>Value</th>
                    <th rowspan="2">Balance Per<br>Card<br>(Quantity)</th>
                    <th rowspan="2">On Hand Per<br>Count<br>(Quantity)</th>
                    <th colspan="2">Shortage/Overage</th>
                    <th rowspan="2">Remarks</th>
                </tr>
                <tr>
                    <th>Quantity</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo h(semi_pc_article($row)); ?></td>
                            <td><?php echo h(semi_pc_description($row)); ?></td>
                            <td><?php echo h($row['property_number'] ?? ''); ?></td>
                            <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                            <td class="money"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                            <td class="qty">1</td>
                            <td class="qty">1</td>
                            <td class="qty">0</td>
                            <td class="money">0.00</td>
                            <td></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center py-4">No semi property found for the selected filters.</td>
                    </tr>
                <?php endif; ?>
                <?php for ($i = count($rows); $i < 10; $i++): ?>
                    <tr>
                        <td>&nbsp;</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <?php render_inventory_committee_signature_grid('semi-sign'); ?>
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
                            <h5 class="report-toolbar-title mb-0">Annex A.8</h5>
                            <p class="report-toolbar-copy">Validate current semi-expendable accountability by office, value type, fund number, and account code before printing the COA physical count form.</p>
                        </div>
                        <div class="report-toolbar-actions">
                            <a href="<?php echo h(base_url('modules/reports/semi_physical_count.php?' . http_build_query(array_filter([
                                'office_id' => $officeId ?: null,
                                'semi_type' => $semiType !== 'all' ? $semiType : null,
                                'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                                'account_code_id' => $accountCodeId ?: null,
                                'as_of' => $asOf !== '' ? $asOf : null,
                                'export' => 'excel',
                            ])))); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
                            <a href="<?php echo h(base_url('modules/reports/semi_physical_count.php?' . http_build_query(array_filter([
                                'office_id' => $officeId ?: null,
                                'semi_type' => $semiType !== 'all' ? $semiType : null,
                                'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                                'account_code_id' => $accountCodeId ?: null,
                                'as_of' => $asOf !== '' ? $asOf : null,
                                'print' => '1',
                            ])))); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a>
                        </div>
                    </div>
                    <div class="report-summary-grid">
                        <div class="report-summary-card">
                            <div class="report-summary-label">Loaded Items</div>
                            <div class="report-summary-value"><?php echo number_format($rowCount); ?></div>
                            <div class="report-summary-note">Semi property rows ready for physical validation.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Total Unit Value</div>
                            <div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div>
                            <div class="report-summary-note">Combined value represented in the current count sheet.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">As Of</div>
                            <div class="report-summary-value"><?php echo h(!empty($asOf) ? date('M d, Y', strtotime($asOf)) : '-'); ?></div>
                            <div class="report-summary-note">Reference cutoff date for the printed form.</div>
                        </div>
                    </div>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                    <?php endif; ?>
                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Filter Report</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Office</label>
                                <select class="form-select" name="office_id">
                                    <option value="0">All offices</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Semi Type</label>
                                <select class="form-select" name="semi_type">
                                    <option value="all" <?php echo $semiType === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="high_value" <?php echo $semiType === 'high_value' ? 'selected' : ''; ?>>High Value</option>
                                    <option value="low_value" <?php echo $semiType === 'low_value' ? 'selected' : ''; ?>>Low Value</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Fund Number</label>
                                <select class="form-select" name="fund_number">
                                    <option value="">All fund numbers</option>
                                    <option value="01" <?php echo $fundNumber === '01' ? 'selected' : ''; ?>>01</option>
                                    <option value="05" <?php echo $fundNumber === '05' ? 'selected' : ''; ?>>05</option>
                                    <option value="06" <?php echo $fundNumber === '06' ? 'selected' : ''; ?>>06</option>
                                    <option value="07" <?php echo $fundNumber === '07' ? 'selected' : ''; ?>>07</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Account Code</label>
                                <select class="form-select" name="account_code_id">
                                    <option value="0">All account codes</option>
                                    <?php foreach ($accountCodes as $accountCode): ?>
                                        <option value="<?php echo (int) $accountCode['id']; ?>" <?php echo $accountCodeId === (int) $accountCode['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(trim(($accountCode['account_code'] ?? '') . ' - ' . ($accountCode['account_name'] ?? ''))); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">As Of</label>
                                <input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>">
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Load Report</button>
                                <a href="<?php echo base_url('modules/reports/semi_physical_count.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>
                    <div class="report-table-card table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Article</th>
                                    <th>Description</th>
                                    <th>Property No.</th>
                                    <th>Fund No.</th>
                                    <th>Unit</th>
                                    <th class="text-end">Unit Value</th>
                                    <th class="text-end">Balance</th>
                                    <th class="text-end">On Hand</th>
                                    <th class="text-end">Shortage/Overage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo h(semi_pc_article($row)); ?></td>
                                            <td><?php echo h(semi_pc_description($row)); ?></td>
                                            <td><?php echo h($row['property_number'] ?? ''); ?></td>
                                            <td><?php echo h($row['fund_number'] ?? ''); ?></td>
                                            <td><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                            <td class="text-end">1.00</td>
                                            <td class="text-end">1.00</td>
                                            <td class="text-end">0.00</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="9" class="text-center text-muted py-4">No semi property found for the selected filters.</td></tr>
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
