<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Return and Receipt of Property/Equipment';
$errors = [];
$returns = [];
$record = null;
$returnId = (int) ($_GET['return_id'] ?? 0);
$extraRows = max(0, min(80, (int) ($_GET['extra_rows'] ?? 0)));
$isPrint = isset($_GET['print']) && $_GET['print'] === '1';
$isExport = isset($_GET['export']) && $_GET['export'] === 'excel';

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $listSql = "
        SELECT
            rt.id,
            rt.system_reference,
            rt.return_date,
            COALESCE(did.property_number, la.property_number) AS property_number,
            COALESCE(poi.item_description, la.item_description) AS item_description,
            c.classification_name,
            c.classification_family,
            f.fund_code,
            f.fund_source
        FROM returns rt
        LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN receivings r ON r.id = ri.receiving_id
        LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = COALESCE(po.fund_id, la.fund_id)
        LEFT JOIN classifications c ON c.id = COALESCE(poi.classification_id, la.classification_id)
        WHERE rt.status = 'posted'
          AND COALESCE(poi.item_type, la.item_type) = 'equipment'
        ORDER BY rt.return_date DESC, rt.id DESC
    ";
    $res = $db->query($listSql);
    if ($res) {
        $returns = $res->fetch_all(MYSQLI_ASSOC);
    }

    if ($returnId > 0) {
        $stmt = $db->prepare("
            SELECT
                rt.id,
                rt.system_reference,
                rt.return_date,
                rt.reason,
                rt.remarks,
                COALESCE(did.property_number, la.property_number) AS property_number,
                COALESCE(d.document_no, la.system_reference, 'Beginning Balance') AS par_no,
                COALESCE(poi.item_description, la.item_description) AS item_description,
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
            LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
            LEFT JOIN legacy_assets la ON la.id = rt.legacy_asset_id
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
              AND COALESCE(poi.item_type, la.item_type) = 'equipment'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $returnId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();

            if ($record && trim((string) ($record['system_reference'] ?? '')) === '') {
                $rrpeNo = next_year_series_number($db, 'returns_rrpe');
                if ($rrpeNo !== '') {
                    $repairStmt = $db->prepare("UPDATE returns SET system_reference = ? WHERE id = ? AND (system_reference IS NULL OR system_reference = '') LIMIT 1");
                    if ($repairStmt) {
                        $repairStmt->bind_param('si', $rrpeNo, $returnId);
                        $repairStmt->execute();
                        if ($repairStmt->affected_rows > 0) {
                            $record['system_reference'] = $rrpeNo;
                        }
                        $repairStmt->close();
                    }
                }
            }
        }
    }
}

function property_return_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));
    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

function property_return_fund_number(?string $fundCode, ?string $fundSource = null): string
{
    return fund_number_from_source($fundCode, $fundSource);
}

function property_return_person(array $row): string
{
    return trim(implode(' ', array_filter([
        trim((string) ($row['name_prefix'] ?? '')),
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

function property_return_signatory_name(array $person): string
{
    $suffix = trim((string) ($person['suffix_name'] ?? ''));
    $nameParts = array_filter([
        trim((string) ($person['name_prefix'] ?? '')),
        trim((string) ($person['first_name'] ?? '')),
        trim((string) ($person['middle_name'] ?? '')),
        trim((string) ($person['last_name'] ?? '')),
    ]);
    $name = strtoupper(trim(implode(' ', $nameParts)));
    if ($suffix !== '') {
        $name .= ', ' . $suffix;
    }
    return $name;
}

function property_return_supply_head(mysqli $db): array
{
    if (function_exists('employee_resolve_supply_office_head')) {
        return employee_resolve_supply_office_head($db);
    }
    return [];
}

if ($isExport && $record) {
    export_excel_rows('rrpe_' . ($record['system_reference'] ?? date('Ymd')) . '.xls', ['RRPE No.', 'Date', 'Property/Equipment Description', 'Quantity', 'Property No.', 'End user/Office', 'Remarks'], [[
        $record['system_reference'] ?? '',
        !empty($record['return_date']) ? date('Y-m-d', strtotime((string) $record['return_date'])) : '',
        property_return_label($record),
        '1',
        $record['property_number'] ?? '',
        trim(implode(' / ', array_filter([$record['office_name'] ?? '', property_return_person($record)]))),
        trim(implode(' | ', array_filter([$record['reason'] ?? '', $record['remarks'] ?? '']))),
    ]]);
}

if ($isPrint && $record) {
    $reportFundCluster = property_return_fund_number($record['fund_code'] ?? '', $record['fund_source'] ?? '');
    $returnedByName = property_return_signatory_name($record);
    $supplyHead = property_return_supply_head($db);
    $receivedByName = !empty($supplyHead) ? property_return_signatory_name($supplyHead) : '';
    $receivedByTitle = trim((string) ($supplyHead['position_title'] ?? ''));
    $receivedByOffice = trim((string) ($supplyHead['office_name'] ?? 'Supply Office'));
    $receivedByCaption = trim($receivedByTitle . ($receivedByOffice !== '' ? ' / ' . $receivedByOffice : ''));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>RRPE <?php echo h($record['system_reference']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            @page { size: 13in 8.5in; margin: 0.5in; }
            body { color: #000; font-family: "Times New Roman", Times, serif; font-size: 14px; }
            .print-shell { width: 12in; max-width: 12in !important; margin: 0 auto; padding: 0; }
            .no-print { font-family: Arial, Helvetica, sans-serif; }
            .rrpe-form { width: 12in; border-collapse: collapse; table-layout: fixed; border: 2px solid #000; }
            .rrpe-form th,
            .rrpe-form td { border: 1px solid #000; padding: 2px 3px; vertical-align: middle; }
            .rrpe-annex { text-align: right; font-style: italic; font-size: 18px; height: 26px; }
            .rrpe-title { text-align: center; font-size: 19px; font-weight: 700; text-transform: uppercase; height: 58px; }
            .rrpe-meta-label { font-weight: 700; }
            .rrpe-meta { height: 21px; font-size: 15px; }
            .rrpe-note { text-align: center; font-weight: 700; height: 26px; }
            .rrpe-head th { height: 41px; font-size: 16px; font-weight: 700; text-align: left; }
            .rrpe-data td { height: 24px; line-height: 1.15; vertical-align: top; }
            .rrpe-line td { height: 17px; }
            .rrpe-sign-row td { border-bottom: 0; height: 52px; vertical-align: top; }
            .rrpe-sign-lines td { border-top: 0; height: 84px; vertical-align: top; text-align: center; }
            .sign-line { display: block; width: 68%; margin: 18px auto 4px; border-top: 1px solid #000; height: 1px; }
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
                <input type="hidden" name="return_id" value="<?php echo (int) $returnId; ?>">
                <input type="hidden" name="print" value="1">
                <label for="extra_rows_print" class="small text-muted mb-0">Extra rows</label>
                <input type="number" min="0" max="80" step="1" id="extra_rows_print" name="extra_rows" value="<?php echo (int) $extraRows; ?>" class="form-control form-control-sm" style="width:88px;">
                <button type="submit" class="btn btn-sm btn-outline-dark">Apply</button>
            </form>
        </div>
        <table class="rrpe-form">
            <colgroup>
                <col style="width:25%;">
                <col style="width:12%;">
                <col style="width:22%;">
                <col style="width:13%;">
                <col style="width:28%;">
            </colgroup>
            <thead>
            <tr>
                <td colspan="5" class="rrpe-annex">Annex 28</td>
            </tr>
            <tr>
                <th colspan="5" class="rrpe-title">Return and Receipt of Property/Equipment</th>
            </tr>
            <tr>
                <td colspan="3" rowspan="2" class="rrpe-meta"><span class="rrpe-meta-label">Entity Name:</span> <?php echo h(APP_NAME); ?></td>
                <td colspan="2" class="rrpe-meta"><span class="rrpe-meta-label">Date:</span> <?php echo h(!empty($record['return_date']) ? date('M d, Y', strtotime((string) $record['return_date'])) : ''); ?></td>
            </tr>
            <tr>
                <td colspan="2" class="rrpe-meta"><span class="rrpe-meta-label">RRPE No.:</span> <?php echo h($record['system_reference'] ?? ''); ?></td>
            </tr>
            <tr>
                <td colspan="5" class="rrpe-note">This is to acknowledge receipt of the Property/Equipment</td>
            </tr>
            <tr class="rrpe-head">
                <th>Property/Equipment Description</th>
                <th>Quantity</th>
                <th>Property No.</th>
                <th>End-user</th>
                <th>Remarks</th>
            </tr>
            </thead>
            <tbody>
            <tr class="rrpe-data">
                <td><?php echo h(property_return_label($record)); ?></td>
                <td>1.00</td>
                <td><?php echo h($record['property_number'] ?? ''); ?></td>
                <td><?php echo h(trim(implode(' / ', array_filter([$record['office_name'] ?? '', property_return_person($record)])))); ?></td>
                <td><?php echo h(trim(implode(' | ', array_filter([$record['reason'] ?? '', $record['remarks'] ?? ''])))); ?></td>
            </tr>
            <?php for ($i = 0; $i < $extraRows; $i++): ?>
                <tr class="rrpe-line"><td></td><td></td><td></td><td></td><td></td></tr>
            <?php endfor; ?>
            <tr class="rrpe-sign-row">
                <td colspan="2">Returned by:</td>
                <td colspan="3">Received by:</td>
            </tr>
            <tr class="rrpe-sign-lines">
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
                            <h5 class="report-toolbar-title mb-0">Annex 28</h5>
                            <p class="report-toolbar-copy">Select a posted equipment return record, review the Return and Receipt of Property/Equipment, and print the official form from the same screen.</p>
                        </div>
                        <div class="report-toolbar-actions">
                            <?php if ($record): ?>
                                <a href="<?php echo h(base_url('modules/reports/property_return_slip.php?return_id=' . $returnId . '&extra_rows=' . $extraRows . '&export=excel')); ?>" class="btn btn-outline-success">
                                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                                </a>
                                <a href="<?php echo h(base_url('modules/reports/property_return_slip.php?return_id=' . $returnId . '&extra_rows=' . $extraRows . '&print=1')); ?>" class="btn btn-primary" target="_blank">
                                    <i class="bi bi-printer me-1"></i>Print
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Available Returns</div><div class="report-summary-value"><?php echo number_format(count($returns)); ?></div><div class="report-summary-note">Posted equipment return records ready for RRPE printing.</div></div><div class="report-summary-card"><div class="report-summary-label">Loaded Record</div><div class="report-summary-value"><?php echo $record ? 'Ready' : 'None'; ?></div><div class="report-summary-note"><?php echo h($record['system_reference'] ?? 'Select one record to preview.'); ?></div></div></div>
                    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Load Return Record</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label for="return_id" class="form-label">Return Record</label>
                                <select class="form-select" id="return_id" name="return_id">
                                    <option value="0">Select equipment return</option>
                                    <?php foreach ($returns as $rt): ?>
                                        <option value="<?php echo (int) $rt['id']; ?>" <?php echo $returnId === (int) $rt['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(($rt['system_reference'] ?? '') . ' | ' . ($rt['property_number'] ?? '') . ' | ' . property_return_label($rt)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="extra_rows" class="form-label">Extra rows</label>
                                <input type="number" min="0" max="80" step="1" class="form-control" id="extra_rows" name="extra_rows" value="<?php echo (int) $extraRows; ?>">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Load RRPE</button>
                                <a href="<?php echo base_url('modules/reports/property_return_slip.php'); ?>" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </form>
                    </div>
                    <?php if (!$record): ?>
                        <div class="report-empty-state">Select a posted equipment return record to preview the Return and Receipt of Property/Equipment.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
