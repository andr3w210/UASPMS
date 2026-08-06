<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'RPCPPE';
$errors = [];
$rows = [];
$offices = [];
$accountCodes = [];
$batches = [];
$officeId = (int) ($_GET['office_id'] ?? 0);
$accountCodeId = (int) ($_GET['account_code_id'] ?? 0);
$batchId = (int) ($_GET['batch_id'] ?? 0);
$asOf = trim((string) ($_GET['as_of'] ?? date('Y-m-d')));
$fundNumber = trim((string) ($_GET['fund_number'] ?? ''));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';
$selectedBatch = null;
$selectedAccountCodeValue = '';

if (!in_array($fundNumber, ['', '01', '05', '06', '07'], true)) {
    $fundNumber = '';
}

function rpcppe_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function rpcppe_article(array $row): string
{
    return trim((string) ($row['classification_name'] ?? ''));
}

function rpcppe_label(array $row): string
{
    return trim(implode(', ', array_filter([
        trim((string) ($row['description_detail'] ?? '')),
        trim((string) ($row['brand'] ?? '')),
        trim((string) ($row['model'] ?? '')),
        trim((string) ($row['serial_no'] ?? '')),
    ])));
}

function rpcppe_display_label(array $row): string
{
    return report_short_text(rpcppe_label($row));
}

function rpcppe_office_employee(array $row): string
{
    return trim(implode(' / ', array_filter([
        trim((string) ($row['office_name'] ?? '')),
        trim((string) (($row['employee_name'] ?? '') !== '' ? (string) $row['employee_name'] : person_full_name($row))),
    ])));
}

function rpcppe_qty_property(array $row): int
{
    return max(1, (int) ($row['qty_property_card'] ?? 1));
}

function rpcppe_qty_physical(array $row): int
{
    return max(1, (int) ($row['qty_physical_count'] ?? 1));
}

function rpcppe_line_total(array $row): float
{
    return round((float) ($row['unit_cost'] ?? 0) * rpcppe_qty_property($row), 2);
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if (!schema_has_column($db, 'legacy_assets', 'fund_id')) {
        $errors[] = 'Database schema is outdated: legacy_assets.fund_id is missing. Apply latest migrations before continuing.';
    }
    if (function_exists('schema_has_table') && !schema_has_table($db, 'rpcppe_batches')) {
        $errors[] = 'Database schema is outdated: rpcppe_batches table is missing. Apply latest migrations before continuing.';
    }
    if (function_exists('schema_has_table') && !schema_has_table($db, 'rpcppe_batch_items')) {
        $errors[] = 'Database schema is outdated: rpcppe_batch_items table is missing. Apply latest migrations before continuing.';
    }
    if (!schema_has_column($db, 'rpcppe_batch_items', 'acquisition_date')
        || !schema_has_column($db, 'rpcppe_batch_items', 'qty_property_card')
        || !schema_has_column($db, 'rpcppe_batch_items', 'qty_physical_count')) {
        $errors[] = 'Database schema is outdated: RPCPPE batch item quantity columns are missing. Apply latest migrations before continuing.';
    }

    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    $accountCodeResult = $db->query("SELECT id, account_code, account_name FROM account_codes WHERE is_active = 1 ORDER BY account_code ASC, account_name ASC");
    if ($accountCodeResult) {
        $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($accountCodeId > 0) {
        foreach ($accountCodes as $accountCodeRow) {
            if ((int) ($accountCodeRow['id'] ?? 0) === $accountCodeId) {
                $selectedAccountCodeValue = trim((string) ($accountCodeRow['account_code'] ?? ''));
                break;
            }
        }
    }

    $batchResult = $db->query("SELECT id, batch_year, batch_name, as_of_date, status FROM rpcppe_batches WHERE status = 'finalized' ORDER BY batch_year DESC, id DESC");
    if ($batchResult) {
        $batches = $batchResult->fetch_all(MYSQLI_ASSOC);
    }

    foreach ($batches as $batch) {
        if ((int) $batch['id'] === $batchId) {
            $selectedBatch = $batch;
            break;
        }
    }

    if ($batchId > 0 && !$selectedBatch) {
        $errors[] = 'Selected RPCPPE batch is not available or not finalized.';
        $batchId = 0;
    }

    if ($selectedBatch) {
        $asOf = (string) ($selectedBatch['as_of_date'] ?? $asOf);

        $batchSql = "
            SELECT
                i.source_type,
                i.property_number,
                i.item_description,
                i.description_detail,
                i.classification_name,
                i.classification_family,
                i.uom_name,
                i.abbreviation,
                i.unit_cost,
                i.acquisition_date,
                i.qty_property_card,
                i.qty_physical_count,
                i.brand,
                i.model,
                i.serial_no,
                i.office_name,
                i.employee_name,
                i.account_code,
                i.account_name,
                i.fund_code,
                i.fund_source,
                i.fund_number,
                i.remarks
            FROM rpcppe_batch_items i
            WHERE i.batch_id = ?
              AND i.is_included = 1
              AND i.is_disposed = 0
        ";
        $batchTypes = 'i';
        $batchParams = [$batchId];

        if ($officeId > 0) {
            $batchSql .= " AND i.office_id = ?";
            $batchTypes .= 'i';
            $batchParams[] = $officeId;
        }
        if ($accountCodeId > 0) {
            if ($selectedAccountCodeValue !== '') {
                $batchSql .= " AND (i.account_code_id = ? OR i.account_code = ?)";
                $batchTypes .= 'is';
                $batchParams[] = $accountCodeId;
                $batchParams[] = $selectedAccountCodeValue;
            } else {
                $batchSql .= " AND i.account_code_id = ?";
                $batchTypes .= 'i';
                $batchParams[] = $accountCodeId;
            }
        }
        if ($fundNumber !== '') {
            $batchSql .= " AND (
                LPAD(NULLIF(TRIM(i.fund_source), ''), 2, '0') = ?
                OR LPAD(NULLIF(TRIM(i.fund_number), ''), 2, '0') = ?
            )";
            $batchTypes .= 'ss';
            $batchParams[] = $fundNumber;
            $batchParams[] = $fundNumber;
        }

        $batchSql .= " ORDER BY i.account_code ASC, i.classification_name ASC, i.item_description ASC, i.property_number ASC";
        $batchStmt = $db->prepare($batchSql);
        if ($batchStmt) {
            $batchStmt->bind_param($batchTypes, ...$batchParams);
            $batchStmt->execute();
            $batchRows = $batchStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $batchStmt->close();
            foreach ($batchRows as $row) {
                if (trim((string) ($row['fund_number'] ?? '')) === '') {
                    $row['fund_number'] = rpcppe_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
                }
                $rows[] = $row;
            }
        } else {
            $errors[] = 'Unable to prepare the RPCPPE batch query.';
        }
    } else {

        $systemSql = "
        SELECT
            'system' AS source_type,
            did.property_number,
            poi.item_description,
            poi.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            u.uom_name,
            u.abbreviation,
            ri.unit_cost,
            rid.brand,
            rid.model,
            rid.serial_no,
            COALESCE(curr_o.office_name, o.office_name) AS office_name,
            COALESCE(curr_e.first_name, e.first_name) AS first_name,
            COALESCE(curr_e.middle_name, e.middle_name) AS middle_name,
            COALESCE(curr_e.last_name, e.last_name) AS last_name,
            COALESCE(curr_e.suffix_name, e.suffix_name) AS suffix_name,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
        LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
        LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
        LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted' AND dp.disposal_date <= ?
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted' AND rt.return_date <= ?
        WHERE d.distribution_date <= ?
          AND dp.id IS NULL
          AND rt.id IS NULL
    ";
        $types = 'sss';
        $params = [$asOf, $asOf, $asOf];

        if ($officeId > 0) {
            $systemSql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($accountCodeId > 0) {
            $systemSql .= " AND poi.account_code_id = ?";
            $types .= 'i';
            $params[] = $accountCodeId;
        }
        if ($fundNumber !== '') {
            $systemSql .= " AND (
                LPAD(NULLIF(TRIM(f.fund_source), ''), 2, '0') = ?
                OR LPAD(NULLIF(TRIM(f.fund_code), ''), 2, '0') = ?
            )";
            $types .= 'ss';
            $params[] = $fundNumber;
            $params[] = $fundNumber;
        }

        $systemSql .= " ORDER BY ac.account_code ASC, c.classification_name ASC, poi.item_description ASC, did.property_number ASC";
        $stmt = $db->prepare($systemSql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $systemRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($systemRows as $row) {
                $row['fund_number'] = rpcppe_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
                $rows[] = $row;
            }
        } else {
            $errors[] = 'Unable to prepare the RPCPPE system-assets query.';
        }

        $legacySql = "
        SELECT
            'legacy' AS source_type,
            la.property_number,
            la.item_description,
            la.item_description AS description_detail,
            c.classification_name,
            c.classification_family,
            '' AS uom_name,
            '' AS abbreviation,
            la.unit_cost,
            la.brand,
            la.model,
            la.serial_no,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            ac.account_code,
            ac.account_name,
            f.fund_code,
            f.fund_source
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        WHERE la.is_active = 1
          AND la.item_type = 'equipment'
          AND (la.acquisition_date IS NULL OR la.acquisition_date <= ?)
    ";
        $legacyTypes = 's';
        $legacyParams = [$asOf];

        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $officeId;
        }
        if ($accountCodeId > 0) {
            $legacySql .= " AND la.account_code_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $accountCodeId;
        }
        if ($fundNumber !== '') {
            $legacySql .= " AND (
                LPAD(NULLIF(TRIM(f.fund_source), ''), 2, '0') = ?
                OR LPAD(NULLIF(TRIM(f.fund_code), ''), 2, '0') = ?
            )";
            $legacyTypes .= 'ss';
            $legacyParams[] = $fundNumber;
            $legacyParams[] = $fundNumber;
        }

        $legacySql .= " ORDER BY ac.account_code ASC, c.classification_name ASC, la.item_description ASC, la.property_number ASC";
        $legacyStmt = $db->prepare($legacySql);
        if ($legacyStmt) {
            $legacyStmt->bind_param($legacyTypes, ...$legacyParams);
            $legacyStmt->execute();
            $legacyRows = $legacyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $legacyStmt->close();
            foreach ($legacyRows as $row) {
                $row['fund_number'] = rpcppe_fund_number($row['fund_code'] ?? '', $row['fund_source'] ?? '');
                $rows[] = $row;
            }
        } else {
            $errors[] = 'Unable to prepare the RPCPPE beginning-balance query.';
        }
    }
}

$rowCount = count($rows);
$totalValue = 0.0;
$legacyCount = 0;
$totalQtyProperty = 0;
$totalQtyPhysical = 0;
foreach ($rows as $row) {
    $totalValue += rpcppe_line_total($row);
    $totalQtyProperty += rpcppe_qty_property($row);
    $totalQtyPhysical += rpcppe_qty_physical($row);
    if (($row['source_type'] ?? '') === 'legacy') {
        $legacyCount++;
    }
}

$selectedOfficeName = '';
foreach ($offices as $office) {
    if ((int) $office['id'] === $officeId) {
        $selectedOfficeName = (string) ($office['office_name'] ?? '');
        break;
    }
}

$presidentProfile = $db ? get_university_president_profile($db) : ['name' => '', 'title' => 'University President', 'appointment_date' => ''];
$presidentName = (string) ($presidentProfile['name'] ?? '');
$presidentPosition = (string) ($presidentProfile['title'] ?? 'University President');
$appointmentDate = (string) ($presidentProfile['appointment_date'] ?? '');
$entityName = APP_NAME;
$reportFundCluster = report_fund_cluster($rows, $fundNumber);

$selectedAccountCode = null;
foreach ($accountCodes as $accountCode) {
    if ((int) $accountCode['id'] === $accountCodeId) {
        $selectedAccountCode = $accountCode;
        break;
    }
}

$typeOfProperty = report_account_name($rows, $selectedAccountCode, 'Equipment');

if ($isExport) {
    $exportRows = [];
    foreach ($rows as $row) {
            $exportRows[] = [
                rpcppe_article($row),
                rpcppe_label($row),
                $row['property_number'] ?? '',
                trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? ''))),
                number_format((float) ($row['unit_cost'] ?? 0), 2),
                (string) ($row['acquisition_date'] ?? ''),
                (string) rpcppe_qty_property($row),
                (string) rpcppe_qty_physical($row),
                '0',
                '0.00',
                rpcppe_office_employee($row),
            ];
        }

    $exportRows[] = [
        '',
        'TOTAL',
        '',
        '',
        number_format($totalValue, 2),
        '',
        (string) $totalQtyProperty,
        (string) $totalQtyPhysical,
        '0',
        '0.00',
        '',
    ];

    export_excel_rows(
        'rpcppe_' . date('Ymd') . '.xls',
        ['Article', 'Description', 'Property Number', 'Unit of Measure', 'Unit Value', 'Date Acquired', 'Qty per Property Card', 'Qty per Physical Count', 'Shortage/Overage Qty', 'Shortage/Overage Value', 'Remarks'],
        $exportRows
    );
}

if ($isPrint) {
    $rowsPerPage = 18;
    $pageChunks = array_chunk($rows, $rowsPerPage);
    if (!$pageChunks) {
        $pageChunks = [[]];
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>RPCPPE</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: 13in 8.5in; margin: 0.5in 0.07in 0.07in 0.07in; }
            body { color: #000; font-family: "Times New Roman", serif; font-size: 12px; overflow-x: auto; }
            .rpcppe-wrap { max-width: 1320px; margin: 0 auto; }
            .rpcppe-page { page-break-after: always; }
            .rpcppe-page:last-of-type { page-break-after: auto; }
            .appendix { text-align: right; font-style: italic; font-size: 14px; margin-bottom: 24px; }
            .title { text-align: center; font-size: 20px; font-weight: 700; text-transform: uppercase; margin-bottom: 18px; }
            .type-line { text-align: center; margin-bottom: 6px; }
            .type-fill { display: inline-block; min-width: 290px; border-bottom: 1px solid #000; padding: 0 8px 2px; }
            .type-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .type-caption { font-size: 12px; margin-top: 2px; }
            .asof-line { text-align: center; font-size: 16px; margin-bottom: 32px; }
            .asof-fill { display: inline-block; min-width: 260px; border-bottom: 1px solid #000; padding: 0 8px 2px; }
            .meta-line { font-size: 14px; margin-bottom: 8px; }
            .meta-fill { display: inline-block; min-width: 260px; border-bottom: 1px solid #000; padding: 0 6px 2px; }
            .meta-fill.short { min-width: 160px; }
            .meta-fill.long { min-width: 220px; }
            .meta-fill.emphasis { font-weight: 700; text-transform: uppercase; }
            .rpcppe-table, .rpcppe-sign { width: 100%; border-collapse: collapse; table-layout: fixed; }
            .rpcppe-table th, .rpcppe-table td, .rpcppe-sign td { border: 1px solid #000; padding: 4px 5px; }
            .rpcppe-table th { text-align: center; font-weight: 700; vertical-align: middle; }
            .rpcppe-table td { vertical-align: top; }
            .rpcppe-table .qty, .rpcppe-table .val, .rpcppe-table .date, .rpcppe-table .uom { text-align: center; }
            .rpcppe-table .money { text-align: right; white-space: nowrap; }
            .rpcppe-table tbody td { height: 28px; }
            .rpcppe-table tfoot td { font-weight: 700; }
            .rpcppe-table .remarks-col { font-size: 12px; line-height: 1.25; }
            .rpcppe-table .subtotal-row td { font-weight: 700; background: #f7f7f7; }
            .rpcppe-table .grandtotal-row td { font-weight: 700; background: #ececec; }
            .rpcppe-sign { margin-top: 0; }
            .rpcppe-sign td { height: 132px; vertical-align: top; }
            .sign-label { font-size: 14px; margin-bottom: 34px; }
            .sign-line { width: 82%; margin: 0 auto 6px; border-bottom: 1px solid #000; height: 18px; }
            .sign-caption { text-align: center; line-height: 1.2; font-size: 14px; }
            @media screen and (max-width: 991.98px) {
                .rpcppe-wrap { min-width: 1120px; padding-bottom: 1rem; }
            }
            @media print {
                .no-print { display: none !important; }
                .rpcppe-page { break-after: page; page-break-after: always; }
                .rpcppe-page:last-of-type { break-after: auto; page-break-after: auto; }
            }
        
            <?php echo print_page_number_css(); ?></style>
    </head>
    <body>
    <div class="rpcppe-wrap">
        <?php render_print_action_bar(); ?>
        <?php foreach ($pageChunks as $pageIndex => $pageRows): ?>
            <?php
            $pageNumber = $pageIndex + 1;
            $isLastPage = $pageIndex === count($pageChunks) - 1;
            $pageTotalValue = 0.0;
            $pageQtyProperty = 0;
            $pageQtyPhysical = 0;
            foreach ($pageRows as $pageRow) {
                $pageTotalValue += rpcppe_line_total($pageRow);
                $pageQtyProperty += rpcppe_qty_property($pageRow);
                $pageQtyPhysical += rpcppe_qty_physical($pageRow);
            }
            ?>
            <div class="rpcppe-page">
                <div class="appendix">Appendix 73</div>
                <div class="title">Report on the Physical Count of Property, Plant and Equipment</div>

                <div class="type-line">
                    <span class="type-fill emphasis"><?php echo h($typeOfProperty); ?></span>
                    <div class="type-caption">(Type of Property, Plant and Equipment)</div>
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

                <div class="meta-line text-end">Page <?php echo h((string) $pageNumber); ?></div>

                <table class="rpcppe-table">
                    <colgroup>
                        <col style="width:7%">
                        <col style="width:17.5%">
                        <col style="width:8.5%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:10%">
                        <col style="width:8.5%">
                        <col style="width:7.5%">
                        <col style="width:6%">
                        <col style="width:8%">
                        <col style="width:15%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th rowspan="2">ARTICLE</th>
                            <th rowspan="2">DESCRIPTION</th>
                            <th rowspan="2">PROPERTY<br>NUMBER</th>
                            <th rowspan="2">UNIT OF<br>MEASURE</th>
                            <th rowspan="2">UNIT<br>VALUE</th>
                            <th rowspan="2">DATE<br>ACQUIRED</th>
                            <th rowspan="2">QUANTITY<br>PER PROPERTY CARD</th>
                            <th rowspan="2">QUANTITY<br>PER PHYSICAL COUNT</th>
                            <th colspan="2">SHORTAGE / OVERAGE</th>
                            <th rowspan="2">REMARKS</th>
                        </tr>
                        <tr>
                            <th>QUANTITY</th>
                            <th>VALUE</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pageRows): ?>
                            <?php foreach ($pageRows as $row): ?>
                                <tr>
                                    <td><?php echo h(rpcppe_article($row)); ?></td>
                                    <td><?php echo h(rpcppe_display_label($row)); ?></td>
                                    <td><?php echo h($row['property_number'] ?? ''); ?></td>
                                    <td class="uom"><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                    <td class="money"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                    <?php $ad = (string) ($row['acquisition_date'] ?? ''); ?>
                                    <td class="date"><?php echo h($ad !== '' ? date('M d, Y', strtotime($ad)) : ''); ?></td>
                                    <td class="qty"><?php echo h((string) rpcppe_qty_property($row)); ?></td>
                                    <td class="qty"><?php echo h((string) rpcppe_qty_physical($row)); ?></td>
                                    <td class="qty">0</td>
                                    <td class="money">0.00</td>
                                    <td class="remarks-col"><?php echo h(rpcppe_office_employee($row)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center py-4">No PPE records found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                        <?php for ($i = count($pageRows); $i < $rowsPerPage; $i++): ?>
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
                                <td></td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr class="subtotal-row">
                            <td colspan="4" class="text-end">SUBTOTAL</td>
                            <td class="money"><?php echo h(number_format($pageTotalValue, 2)); ?></td>
                            <td></td>
                            <td class="qty"><?php echo h((string) $pageQtyProperty); ?></td>
                            <td class="qty"><?php echo h((string) $pageQtyPhysical); ?></td>
                            <td class="qty">0</td>
                            <td class="money">0.00</td>
                            <td></td>
                        </tr>
                        <?php if ($isLastPage): ?>
                            <tr class="grandtotal-row">
                                <td colspan="4" class="text-end">GRAND TOTAL</td>
                                <td class="money"><?php echo h(number_format($totalValue, 2)); ?></td>
                                <td></td>
                                <td class="qty"><?php echo h((string) $totalQtyProperty); ?></td>
                                <td class="qty"><?php echo h((string) $totalQtyPhysical); ?></td>
                                <td class="qty">0</td>
                                <td class="money">0.00</td>
                                <td></td>
                            </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>

                <?php if ($isLastPage): ?>
                    <?php render_inventory_committee_signature_grid('rpcppe-sign'); ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
<?php render_print_page_number(); ?></body>
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
                            <h5 class="report-toolbar-title mb-0">RPCPPE</h5>
                            <p class="report-toolbar-copy">Review accountable equipment from either a finalized yearly RPCPPE batch or live posted/legacy records, then print the COA physical count report.</p>
                        </div>
                        <div class="report-toolbar-actions">
                            <a href="<?php echo h(base_url('modules/reports/rpcppe_batches.php')); ?>" class="btn btn-outline-primary"><i class="bi bi-calendar-check me-1"></i>Manage Batches</a>
                            <a href="<?php echo h(base_url('modules/reports/rpcppe.php?' . http_build_query(array_filter([
                                'batch_id' => $batchId ?: null,
                                'office_id' => $officeId ?: null,
                                'as_of' => $asOf !== '' ? $asOf : null,
                                'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                                'account_code_id' => $accountCodeId ?: null,
                                'export' => 'excel',
                            ])))); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Export Excel</a>
                            <a href="<?php echo h(base_url('modules/reports/rpcppe.php?' . http_build_query(array_filter([
                                'batch_id' => $batchId ?: null,
                                'office_id' => $officeId ?: null,
                                'as_of' => $asOf !== '' ? $asOf : null,
                                'fund_number' => $fundNumber !== '' ? $fundNumber : null,
                                'account_code_id' => $accountCodeId ?: null,
                                'print' => '1',
                            ])))); ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-1"></i>Print</a>
                        </div>
                    </div>

                    <div class="report-summary-grid">
                        <div class="report-summary-card">
                            <div class="report-summary-label">Loaded Assets</div>
                            <div class="report-summary-value"><?php echo number_format($rowCount); ?></div>
                            <div class="report-summary-note">Equipment records in the current count sheet.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Total Value</div>
                            <div class="report-summary-value"><?php echo number_format($totalValue, 2); ?></div>
                            <div class="report-summary-note">Extended value based on quantity per property card.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Beginning Balance</div>
                            <div class="report-summary-value"><?php echo number_format($legacyCount); ?></div>
                            <div class="report-summary-note">Legacy equipment merged into this RPCPPE run.</div>
                        </div>
                    </div>

                    <?php if ($selectedBatch): ?>
                        <div class="alert alert-info py-2">
                            Viewing finalized batch <strong><?php echo h(($selectedBatch['batch_name'] ?? 'RPCPPE Batch') . ' (' . ($selectedBatch['batch_year'] ?? '') . ')'); ?></strong> as of <strong><?php echo h(format_date((string) ($selectedBatch['as_of_date'] ?? ''), 'F d, Y')); ?></strong>.
                        </div>
                    <?php endif; ?>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <?php foreach ($errors as $error): ?>
                                <div><?php echo h($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Filter Report</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Finalized Batch</label>
                                <select class="form-select" name="batch_id">
                                    <option value="0">Live records (no batch)</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?php echo (int) $batch['id']; ?>" <?php echo $batchId === (int) $batch['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(($batch['batch_year'] ?? '') . ' - ' . ($batch['batch_name'] ?? 'RPCPPE Batch')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Office</label>
                                <select class="form-select" name="office_id">
                                    <option value="0">All offices</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($office['office_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
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
                            <div class="col-md-3">
                                <label class="form-label">As Of</label>
                                <input type="date" class="form-control" name="as_of" value="<?php echo h($asOf); ?>" <?php echo $selectedBatch ? 'readonly' : ''; ?>>
                                <?php if ($selectedBatch): ?>
                                    <div class="form-text">As Of is locked to selected batch date.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Load Report</button>
                                <a href="<?php echo base_url('modules/reports/rpcppe.php'); ?>" class="btn btn-outline-secondary">Reset</a>
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
                                    <th class="text-center">UOM</th>
                                    <th>Fund No.</th>
                                    <th class="text-center">Qty PC</th>
                                    <th class="text-center">Qty Count</th>
                                    <th class="text-end">Unit Value</th>
                                    <th class="text-end">Total Value</th>
                                    <th>Office / Officer</th>
                                    <th>Source</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td><?php echo h(rpcppe_article($row)); ?></td>
                                            <td><?php echo h(rpcppe_display_label($row)); ?></td>
                                            <td><?php echo h($row['property_number'] ?? ''); ?></td>
                                            <td class="text-center"><?php echo h(trim((string) (($row['abbreviation'] ?? '') !== '' ? $row['abbreviation'] : ($row['uom_name'] ?? '')))); ?></td>
                                            <td><?php echo h($row['fund_number'] ?? ''); ?></td>
                                            <td class="text-center"><?php echo h((string) rpcppe_qty_property($row)); ?></td>
                                            <td class="text-center"><?php echo h((string) rpcppe_qty_physical($row)); ?></td>
                                            <td class="text-end"><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                            <td class="text-end"><?php echo h(number_format(rpcppe_line_total($row), 2)); ?></td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([
                                                $row['office_name'] ?? '',
                                                ($row['employee_name'] ?? '') !== '' ? (string) $row['employee_name'] : person_full_name($row),
                                            ])))); ?></td>
                                            <td><?php echo h(($row['source_type'] ?? '') === 'legacy' ? 'Beginning Balance' : 'System'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-light fw-semibold">
                                        <td colspan="5" class="text-end">TOTAL</td>
                                        <td class="text-center"><?php echo h((string) $totalQtyProperty); ?></td>
                                        <td class="text-center"><?php echo h((string) $totalQtyPhysical); ?></td>
                                        <td></td>
                                        <td class="text-end"><?php echo h(number_format($totalValue, 2)); ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="11" class="text-center text-muted py-4">No PPE records found for the selected filters.</td>
                                    </tr>
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
