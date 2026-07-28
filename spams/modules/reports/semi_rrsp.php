<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Receipt of Returned Semi-Expendable Property';
$errors = [];
$returns = [];
$record = null;
$recordRows = [];
$returnId = (int) ($_GET['return_id'] ?? 0);
$selectedReturnIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['return_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
$extraRows = max(0, min(80, (int) ($_GET['extra_rows'] ?? 0)));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $listSql = "
        SELECT
            rt.id,
            COALESCE(rb.system_reference, rt.system_reference) AS system_reference,
            COALESCE(rb.return_date, rt.return_date) AS return_date,
            (
                SELECT COUNT(*)
                FROM returns rt_count
                LEFT JOIN distribution_item_details did_count ON did_count.id = rt_count.distribution_item_detail_id
                LEFT JOIN legacy_assets la_count ON la_count.id = rt_count.legacy_asset_id
                LEFT JOIN distribution_items di_count ON di_count.id = did_count.distribution_item_id
                LEFT JOIN receiving_items ri_count ON ri_count.id = di_count.receiving_item_id
                LEFT JOIN purchase_order_items poi_count ON poi_count.id = ri_count.purchase_order_item_id
                WHERE rt_count.status = 'posted'
                  AND (
                      (rt.return_batch_id IS NOT NULL AND rt_count.return_batch_id = rt.return_batch_id)
                      OR (rt.return_batch_id IS NULL AND rt_count.id = rt.id)
                  )
                  AND COALESCE(poi_count.item_type, la_count.item_type) = 'semi_expendable'
            ) AS item_count,
            COALESCE(did.property_number, la.property_number) AS property_number,
            COALESCE(poi.item_description, la.item_description) AS item_description,
            COALESCE(NULLIF(did.brand, ''), NULLIF(rid.brand, ''), bd.brand_name, NULLIF(la.brand, ''), bl.brand_name) AS brand,
            COALESCE(NULLIF(did.model, ''), NULLIF(rid.model, ''), md.model_name, NULLIF(la.model, ''), ml.model_name) AS model,
            COALESCE(did.serial_no, la.serial_no) AS serial_no,
            c.classification_name,
            c.classification_family,
            f.fund_code,
            f.fund_source
        FROM returns rt
        LEFT JOIN return_batches rb ON rb.id = rt.return_batch_id
        LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
        LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
        LEFT JOIN brands bd ON bd.id = rid.brand_id
        LEFT JOIN models md ON md.id = rid.model_id
        LEFT JOIN brands bl ON bl.id = la.brand_id
        LEFT JOIN models ml ON ml.id = la.model_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = COALESCE(po.fund_id, la.fund_id)
        LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, la.classification_id)
        WHERE rt.status = 'posted'
          AND COALESCE(poi.item_type, la.item_type) = 'semi_expendable'
          AND (
              rt.return_batch_id IS NULL
              OR rt.id = (
              SELECT MIN(rt_first.id)
              FROM returns rt_first
              LEFT JOIN distribution_item_details did_first ON did_first.id = rt_first.distribution_item_detail_id
              LEFT JOIN legacy_assets la_first ON la_first.id = rt_first.legacy_asset_id
              LEFT JOIN distribution_items di_first ON di_first.id = did_first.distribution_item_id
              LEFT JOIN receiving_items ri_first ON ri_first.id = di_first.receiving_item_id
              LEFT JOIN purchase_order_items poi_first ON poi_first.id = ri_first.purchase_order_item_id
              WHERE rt_first.status = 'posted'
                AND (
                    rt_first.return_batch_id = rt.return_batch_id
                )
                AND COALESCE(poi_first.item_type, la_first.item_type) = 'semi_expendable'
              )
          )
        ORDER BY rt.return_date DESC, rt.id DESC
    ";
    $res = $db->query($listSql);
    if ($res) {
        $returns = $res->fetch_all(MYSQLI_ASSOC);
    }

    if ($selectedReturnIds) {
        $placeholders = implode(',', array_fill(0, count($selectedReturnIds), '?'));
        $types = str_repeat('i', count($selectedReturnIds));
        $stmt = $db->prepare("
            SELECT
                rt.id,
                rt.return_batch_id,
                COALESCE(rb.system_reference, rt.system_reference) AS system_reference,
                COALESCE(rb.return_date, rt.return_date) AS return_date,
                rt.reason,
                rt.remarks,
                COALESCE(did.property_number, la.property_number) AS property_number,
                COALESCE(NULLIF(d.document_no, ''), NULLIF(la.system_reference, ''), COALESCE(did.property_number, la.property_number)) AS ics_no,
                COALESCE(poi.item_description, la.item_description) AS item_description,
                COALESCE(NULLIF(did.brand, ''), NULLIF(rid.brand, ''), bd.brand_name, NULLIF(la.brand, ''), bl.brand_name) AS brand,
                COALESCE(NULLIF(did.model, ''), NULLIF(rid.model, ''), md.model_name, NULLIF(la.model, ''), ml.model_name) AS model,
                COALESCE(did.serial_no, la.serial_no) AS serial_no,
                c.classification_name,
                c.classification_family,
                f.fund_code,
                f.fund_source,
                o.office_name,
                e.name_prefix,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                e.position_title
            FROM returns rt
            LEFT JOIN return_batches rb ON rb.id = rt.return_batch_id
            LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
            LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
            LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
            LEFT JOIN brands bd ON bd.id = rid.brand_id
            LEFT JOIN models md ON md.id = rid.model_id
            LEFT JOIN brands bl ON bl.id = la.brand_id
            LEFT JOIN models ml ON ml.id = la.model_id
            LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
            LEFT JOIN distributions d ON d.id = di.distribution_id
            LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
            LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN funds f ON f.id = COALESCE(po.fund_id, la.fund_id)
            LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, la.classification_id)
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.id IN ({$placeholders})
              AND rt.status = 'posted'
              AND COALESCE(poi.item_type, la.item_type) = 'semi_expendable'
            ORDER BY rt.id ASC
        ");
        if ($stmt) {
            $stmt->bind_param($types, ...$selectedReturnIds);
            $stmt->execute();
            $recordRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $record = $recordRows[0] ?? null;
            if ($record && count($recordRows) > 1) {
                $refs = array_values(array_filter(array_map(static fn(array $row): string => trim((string) ($row['system_reference'] ?? '')), $recordRows)));
                if ($refs) {
                    $record['system_reference'] = $refs[0] . ' to ' . $refs[count($refs) - 1];
                }
            }
        }
    } elseif ($returnId > 0) {
        $stmt = $db->prepare("
            SELECT
                rt.id,
                rt.return_batch_id,
                COALESCE(rb.system_reference, rt.system_reference) AS system_reference,
                COALESCE(rb.return_date, rt.return_date) AS return_date,
                rt.reason,
                rt.remarks,
                COALESCE(did.property_number, la.property_number) AS property_number,
                COALESCE(NULLIF(d.document_no, ''), NULLIF(la.system_reference, ''), COALESCE(did.property_number, la.property_number)) AS ics_no,
                COALESCE(poi.item_description, la.item_description) AS item_description,
                COALESCE(NULLIF(did.brand, ''), NULLIF(rid.brand, ''), bd.brand_name, NULLIF(la.brand, ''), bl.brand_name) AS brand,
                COALESCE(NULLIF(did.model, ''), NULLIF(rid.model, ''), md.model_name, NULLIF(la.model, ''), ml.model_name) AS model,
                COALESCE(did.serial_no, la.serial_no) AS serial_no,
                c.classification_name,
                c.classification_family,
                f.fund_code,
                f.fund_source,
                o.office_name,
                e.name_prefix,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                e.position_title
            FROM returns rt
            LEFT JOIN return_batches rb ON rb.id = rt.return_batch_id
            LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
            LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
            LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
            LEFT JOIN brands bd ON bd.id = rid.brand_id
            LEFT JOIN models md ON md.id = rid.model_id
            LEFT JOIN brands bl ON bl.id = la.brand_id
            LEFT JOIN models ml ON ml.id = la.model_id
            LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
            LEFT JOIN distributions d ON d.id = di.distribution_id
            LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
            LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN funds f ON f.id = COALESCE(po.fund_id, la.fund_id)
            LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, la.classification_id)
            LEFT JOIN offices o ON o.id = rt.office_id
            LEFT JOIN employees e ON e.id = rt.employee_id
            WHERE rt.id = ?
              AND rt.status = 'posted'
              AND COALESCE(poi.item_type, la.item_type) = 'semi_expendable'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $returnId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if ($record) {
                $returnBatchId = (int) ($record['return_batch_id'] ?? 0);
                $rowsSql = "
                    SELECT
                        rt.id,
                        rt.return_batch_id,
                        COALESCE(rb.system_reference, rt.system_reference) AS system_reference,
                        COALESCE(rb.return_date, rt.return_date) AS return_date,
                        rt.reason,
                        rt.remarks,
                        COALESCE(did.property_number, la.property_number) AS property_number,
                        COALESCE(NULLIF(d.document_no, ''), NULLIF(la.system_reference, ''), COALESCE(did.property_number, la.property_number)) AS ics_no,
                        COALESCE(poi.item_description, la.item_description) AS item_description,
                        COALESCE(NULLIF(did.brand, ''), NULLIF(rid.brand, ''), bd.brand_name, NULLIF(la.brand, ''), bl.brand_name) AS brand,
                        COALESCE(NULLIF(did.model, ''), NULLIF(rid.model, ''), md.model_name, NULLIF(la.model, ''), ml.model_name) AS model,
                        COALESCE(did.serial_no, la.serial_no) AS serial_no,
                        c.classification_name,
                        c.classification_family,
                        f.fund_code,
                        f.fund_source,
                        o.office_name,
                        e.name_prefix,
                        e.first_name,
                        e.middle_name,
                        e.last_name,
                        e.suffix_name,
                        e.position_title
                    FROM returns rt
                    LEFT JOIN return_batches rb ON rb.id = rt.return_batch_id
                    LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
                    LEFT JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
                    LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
                    LEFT JOIN brands bd ON bd.id = rid.brand_id
                    LEFT JOIN models md ON md.id = rid.model_id
                    LEFT JOIN brands bl ON bl.id = la.brand_id
                    LEFT JOIN models ml ON ml.id = la.model_id
                    LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
                    LEFT JOIN distributions d ON d.id = di.distribution_id
                    LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
                    LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                    LEFT JOIN receivings r ON r.id = ri.receiving_id
                    LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
                    LEFT JOIN funds f ON f.id = COALESCE(po.fund_id, la.fund_id)
                    LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, la.classification_id)
                    LEFT JOIN offices o ON o.id = rt.office_id
                    LEFT JOIN employees e ON e.id = rt.employee_id
                    WHERE rt.status = 'posted'
                      AND COALESCE(poi.item_type, la.item_type) = 'semi_expendable'
                ";
                if ($returnBatchId > 0) {
                    $rowsSql .= " AND rt.return_batch_id = ? ORDER BY rt.id ASC";
                    $rowsStmt = $db->prepare($rowsSql);
                    if ($rowsStmt) {
                        $rowsStmt->bind_param('i', $returnBatchId);
                    }
                } else {
                    $rowsSql .= " AND rt.id = ? ORDER BY rt.id ASC";
                    $rowsStmt = $db->prepare($rowsSql);
                    if ($rowsStmt) {
                        $rowsStmt->bind_param('i', $returnId);
                    }
                }
                if (isset($rowsStmt) && $rowsStmt) {
                    $rowsStmt->execute();
                    $recordRows = $rowsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $rowsStmt->close();
                }
            }
        }
    }
}

function rrsp_label(array $row): string
{
    $classification = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(implode(' - ', array_filter([
        $classification,
        trim((string) ($row['item_description'] ?? '')),
    ])));
}

function rrsp_item_specs(array $row): string
{
    $parts = [];
    $model = trim((string) ($row['model'] ?? ''));
    $brand = trim((string) ($row['brand'] ?? ''));
    $serialNo = trim((string) ($row['serial_no'] ?? ''));
    if ($model !== '') {
        $parts[] = 'Model: ' . $model;
    }
    if ($brand !== '') {
        $parts[] = 'Brand: ' . $brand;
    }
    if ($serialNo !== '') {
        $parts[] = 'SN: ' . $serialNo;
    }
    return implode(' | ', $parts);
}

function rrsp_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function rrsp_reference_number(array $row): string
{
    $propertyNumber = trim((string) ($row['property_number'] ?? ''));
    if ($propertyNumber !== '') {
        return $propertyNumber;
    }
    $icsNo = trim((string) ($row['ics_no'] ?? ''));
    return $icsNo;
}

function rrsp_person(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row['name_prefix'] ?? '')),
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

function rrsp_signatory_name(array $person): string
{
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $middle = trim((string) ($person['middle_name'] ?? ''));
    $middleInitial = $middle !== '' ? strtoupper(substr(rtrim($middle, '.'), 0, 1)) . '.' : '';
    $nameParts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        $middleInitial,
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $nameParts)));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name;
}

function rrsp_supply_head(mysqli $db): array
{
    if (function_exists('employee_resolve_supply_office_head')) {
        return employee_resolve_supply_office_head($db);
    }
    return [];
}

if ($record && !$recordRows) {
    $recordRows = [$record];
}

if ($isExport && $record) {
    $exportRows = array_map(static function (array $row): array {
        return [
            $row['system_reference'] ?? '',
            !empty($row['return_date']) ? date('Y-m-d', strtotime((string) $row['return_date'])) : '',
            trim(implode(' | ', array_filter([rrsp_label($row), rrsp_item_specs($row)]))),
            $row['property_number'] ?? '',
            '1',
            rrsp_reference_number($row),
            trim(implode(' / ', array_filter([$row['office_name'] ?? '', rrsp_person($row)]))),
            trim(implode(' | ', array_filter([$row['reason'] ?? '', $row['remarks'] ?? '']))),
        ];
    }, $recordRows);
    export_excel_rows('semi_rrsp_' . ($record['system_reference'] ?? date('Ymd')) . '.xls', ['RRSP No.', 'Date', 'Item Description', 'Property Number', 'Quantity', 'ICS No.', 'End-user', 'Remarks'], $exportRows);
}

if ($isPrint && $record) {
    $reportFundCluster = rrsp_fund_number($record['fund_code'] ?? '', $record['fund_source'] ?? '');
    $returnedByName = rrsp_signatory_name($record);
    $supplyHead = rrsp_supply_head($db);
    $receivedByName = !empty($supplyHead) ? rrsp_signatory_name($supplyHead) : '';
    $receivedByTitle = trim((string) ($supplyHead['position_title'] ?? ''));
    $receivedByOffice = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));
    $receivedByCaption = trim($receivedByTitle . ($receivedByOffice !== '' ? ' / ' . $receivedByOffice : ''));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>RRSP <?php echo h($record['system_reference']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: auto; margin: 0.5in; }
            body { color: #000; font-family: "Times New Roman", Times, serif; font-size: 14px; }
            .print-shell { width: 100%; max-width: none !important; margin: 0 auto; padding: 0; }
            .no-print { font-family: Arial, Helvetica, sans-serif; }
            .rrsp-form { width: 100%; border-collapse: collapse; table-layout: fixed; border: 2px solid #000; }
            .rrsp-form th,
            .rrsp-form td { border: 1px solid #000; padding: 2px 3px; vertical-align: middle; }
            .rrsp-annex { text-align: right; font-style: italic; font-size: 18px; height: 26px; }
            .rrsp-title { text-align: center; font-size: 19px; font-weight: 700; text-transform: uppercase; height: 58px; }
            .rrsp-meta-label { font-weight: 700; }
            .rrsp-meta { height: 21px; font-size: 15px; }
            .rrsp-note { text-align: center; font-weight: 700; height: 26px; }
            .rrsp-head th { height: 41px; font-size: 16px; font-weight: 700; text-align: left; }
            .rrsp-data td { height: 24px; line-height: 1.15; vertical-align: top; }
            .item-specs { display: block; margin-top: 2px; font-size: 12px; line-height: 1.1; }
            .rrsp-line td { height: 17px; }
            .rrsp-sign-row td { border-bottom: 0; height: 52px; vertical-align: top; }
            .rrsp-sign-lines td { border-top: 0; height: 84px; vertical-align: top; text-align: center; }
            .date-line { display: block; width: 68%; margin: 18px auto 4px; border-top: 1px solid #000; height: 1px; }
            .sign-caption { display: block; font-size: 14px; }
            .sign-name { display: inline-block; border-bottom: 1px solid #000; min-width: 68%; padding: 0 8px 1px; font-weight: 700; font-size: 14px; min-height: 18px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
    <div class="container print-shell py-3">
        <div class="no-print mb-2 d-flex gap-2 align-items-center flex-wrap">
            <?php render_print_action_bar(); ?>
            <form method="get" class="d-flex align-items-center gap-2">
                <?php if ($selectedReturnIds): ?>
                    <?php foreach ($selectedReturnIds as $selectedId): ?>
                        <input type="hidden" name="return_ids[]" value="<?php echo (int) $selectedId; ?>">
                    <?php endforeach; ?>
                <?php else: ?>
                    <input type="hidden" name="return_id" value="<?php echo (int) $returnId; ?>">
                <?php endif; ?>
                <input type="hidden" name="print" value="1">
                <label for="extra_rows_print" class="small text-muted mb-0">Extra rows</label>
                <input type="number" min="0" max="80" step="1" id="extra_rows_print" name="extra_rows" value="<?php echo (int) $extraRows; ?>" class="form-control form-control-sm" style="width:88px;">
                <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
            </form>
        </div>
        <table class="rrsp-form">
            <colgroup>
                <col style="width:25%;">
                <col style="width:12%;">
                <col style="width:22%;">
                <col style="width:13%;">
                <col style="width:28%;">
            </colgroup>
            <thead>
            <tr>
                <td colspan="5" class="rrsp-annex">Annex A.6</td>
            </tr>
            <tr>
                <th colspan="5" class="rrsp-title">Receipt of Returned Semi-Expendable Property</th>
            </tr>
            <tr>
                <td colspan="3" rowspan="2" class="rrsp-meta"><span class="rrsp-meta-label">Entity Name:</span> <?php echo h(APP_NAME); ?><br><span class="rrsp-meta-label">Fund Cluster:</span> <?php echo h($reportFundCluster); ?></td>
                <td colspan="2" class="rrsp-meta"><span class="rrsp-meta-label">Date:</span> <?php echo h(!empty($record['return_date']) ? date('M d, Y', strtotime((string) $record['return_date'])) : ''); ?></td>
            </tr>
            <tr>
                <td colspan="2" class="rrsp-meta"><span class="rrsp-meta-label">RRSP No.:</span> <?php echo h($record['system_reference'] ?? ''); ?></td>
            </tr>
            <tr>
                <td colspan="5" class="rrsp-note">This is to acknowledge receipt of the returned Semi-expendable Property</td>
            </tr>
            <tr class="rrsp-head">
                <th>Item Description</th>
                <th>Quantity</th>
                <th>ICS No.</th>
                <th>End-user</th>
                <th>Remarks</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recordRows as $row): ?>
                <tr class="rrsp-data">
                    <td>
                        <div><?php echo h(rrsp_label($row)); ?></div>
                        <?php $itemSpecs = rrsp_item_specs($row); ?>
                        <?php if ($itemSpecs !== ''): ?><span class="item-specs"><?php echo h($itemSpecs); ?></span><?php endif; ?>
                    </td>
                    <td>1.00</td>
                    <td><?php echo h(rrsp_reference_number($row)); ?></td>
                    <td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', rrsp_person($row)])))); ?></td>
                    <td><?php echo h(trim(implode(' | ', array_filter([$row['reason'] ?? '', $row['remarks'] ?? ''])))); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($i = 0; $i < $extraRows; $i++): ?>
                <tr class="rrsp-line"><td></td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
            <tr class="rrsp-sign-row">
                <td colspan="2">Returned by:</td>
                <td colspan="3">Received by:</td>
            </tr>
            <tr class="rrsp-sign-lines">
                <td colspan="2">
                    <span class="sign-name"><?php echo h($returnedByName); ?></span>
                    <span class="sign-caption">End User</span>
                    <span class="date-line"></span>
                    <span class="sign-caption">Date</span>
                </td>
                <td colspan="3">
                    <span class="sign-name"><?php echo h($receivedByName); ?></span>
                    <span class="sign-caption"><?php echo h($receivedByCaption !== '' ? $receivedByCaption : 'Head, Property and/or Supply Division/ Unit'); ?></span>
                    <span class="date-line"></span>
                    <span class="sign-caption">Date</span>
                </td>
            </tr>
            </tbody>
        </table>
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
                        <h5 class="report-toolbar-title mb-0">Annex A.6</h5>
                        <p class="report-toolbar-copy">Select a posted semi-expendable return record, review the RRSP details, and print the official receipt form from the same screen.</p>
                    </div>
                    <div class="report-toolbar-actions">
                    <?php if ($record): ?>
                        <?php $selectedQuery = $selectedReturnIds ? http_build_query(['return_ids' => $selectedReturnIds]) : ('return_id=' . $returnId); ?>
                        <a href="<?php echo h(base_url('modules/reports/semi_rrsp.php?' . $selectedQuery . '&extra_rows=' . $extraRows . '&export=excel')); ?>" class="btn btn-outline-success">
                            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                        </a>
                        <a href="<?php echo h(base_url('modules/reports/semi_rrsp.php?' . $selectedQuery . '&extra_rows=' . $extraRows . '&print=1')); ?>" class="btn btn-primary" target="_blank">
                            <i class="bi bi-printer me-1"></i>Print
                        </a>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Available Returns</div><div class="report-summary-value"><?php echo number_format(count($returns)); ?></div><div class="report-summary-note">Posted semi return documents ready for RRSP printing.</div></div><div class="report-summary-card"><div class="report-summary-label">Loaded Record</div><div class="report-summary-value"><?php echo $record ? number_format(count($recordRows)) . ' item(s)' : 'None'; ?></div><div class="report-summary-note"><?php echo h($record['system_reference'] ?? 'Select one record to preview.'); ?></div></div></div>
                <div class="report-filter-card">
                <h6 class="report-filter-title">Load Return Record</h6>
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label for="return_id" class="form-label">Return Record</label>
                        <select class="form-select" id="return_id" name="return_id">
                            <option value="0">Select semi return</option>
                            <?php foreach ($returns as $rt): ?>
                                <option value="<?php echo (int) $rt['id']; ?>" <?php echo $returnId === (int) $rt['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(($rt['system_reference'] ?? '') . ' | ' . number_format((int) ($rt['item_count'] ?? 1)) . ' item(s) | ' . ($rt['property_number'] ?? '') . ' | ' . rrsp_label($rt)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="extra_rows" class="form-label">Extra rows</label>
                        <input type="number" min="0" max="80" step="1" class="form-control" id="extra_rows" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Load RRSP</button>
                        <a href="<?php echo base_url('modules/reports/semi_rrsp.php'); ?>" class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
                </div>
                <div class="report-filter-card">
                <h6 class="report-filter-title">Print Multiple Posted Records</h6>
                <form method="get" class="row g-3">
                    <div class="col-12">
                        <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                            <table class="table table-sm align-middle">
                                <thead><tr><th style="width:1%;"></th><th>Reference</th><th>Date</th><th>Item</th></tr></thead>
                                <tbody>
                                <?php foreach ($returns as $rt): ?>
                                    <tr>
                                        <td><input type="checkbox" name="return_ids[]" value="<?php echo (int) $rt['id']; ?>" <?php echo in_array((int) $rt['id'], $selectedReturnIds, true) ? 'checked' : ''; ?>></td>
                                        <td class="fw-semibold"><?php echo h($rt['system_reference'] ?? ''); ?></td>
                                        <td><?php echo h(!empty($rt['return_date']) ? date('M d, Y', strtotime((string) $rt['return_date'])) : ''); ?></td>
                                        <td><?php echo h(($rt['property_number'] ?? '') . ' | ' . rrsp_label($rt)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label for="extra_rows_selected" class="form-label">Extra rows</label>
                        <input type="number" min="0" max="80" step="1" class="form-control" id="extra_rows_selected" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                    </div>
                    <div class="col-md-10 d-flex gap-2 align-items-end">
                        <button type="submit" class="btn btn-primary">Load Selected</button>
                        <?php if ($selectedReturnIds): ?>
                            <a class="btn btn-outline-primary" target="_blank" href="<?php echo h(base_url('modules/reports/semi_rrsp.php?' . http_build_query(['return_ids' => $selectedReturnIds]) . '&extra_rows=' . $extraRows . '&print=1')); ?>">Print Selected</a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
                <?php if (!$record): ?>
                    <div class="report-empty-state">Select a posted semi-expendable return record to preview the RRSP.</div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
