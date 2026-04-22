<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Receipt of Returned Property';
$errors = [];
$returns = [];
$record = null;
$returnId = (int) ($_GET['return_id'] ?? 0);
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
            did.property_number,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            f.fund_code,
            f.fund_source
        FROM returns rt
        INNER JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
        INNER JOIN receivings r ON r.id = ri.receiving_id
        INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
        LEFT JOIN funds f ON f.id = po.fund_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        WHERE rt.status = 'posted'
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
                did.property_number,
                d.document_no AS par_no,
                poi.item_description,
                c.classification_name,
                c.classification_family,
                f.fund_code,
                f.fund_source,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                e.position_title
            FROM returns rt
            INNER JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
            INNER JOIN receivings r ON r.id = ri.receiving_id
            INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
            LEFT JOIN funds f ON f.id = po.fund_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            WHERE rt.id = ?
              AND rt.status = 'posted'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $returnId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc() ?: null;
            $stmt->close();
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
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ])));
}

if ($isExport && $record) {
    export_excel_rows('property_return_' . ($record['system_reference'] ?? date('Ymd')) . '.xls', ['RRP No.', 'Date', 'Property Description', 'Property Number', 'Quantity', 'PAR No.', 'End-user', 'Remarks'], [[
        $record['system_reference'] ?? '',
        !empty($record['return_date']) ? date('Y-m-d', strtotime((string) $record['return_date'])) : '',
        property_return_label($record),
        $record['property_number'] ?? '',
        '1',
        $record['par_no'] ?? '',
        trim(implode(' / ', array_filter([$record['office_name'] ?? '', property_return_person($record)]))),
        trim(implode(' | ', array_filter([$record['reason'] ?? '', $record['remarks'] ?? '']))),
    ]]);
}

if ($isPrint && $record) {
    $reportFundCluster = property_return_fund_number($record['fund_code'] ?? '', $record['fund_source'] ?? '');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Receipt of Returned Property <?php echo h($record['system_reference']); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { font-size: 12px; }
            table { font-size: 11px; }
            @media print { .no-print { display: none !important; } }
        </style>
    </head>
    <body>
    <div class="container py-3">
        <?php render_print_action_bar(); ?>
        <div class="text-center mb-3">
            <div class="small fst-italic">Appendix 72</div>
            <h4 class="mb-1">Receipt of Returned Property</h4>
            <div>Entity Name: <?php echo h(APP_NAME); ?> | Fund Cluster: <?php echo h($reportFundCluster); ?></div>
            <div>Date: <?php echo h(!empty($record['return_date']) ? date('M d, Y', strtotime((string) $record['return_date'])) : ''); ?></div>
            <div>RRP No.: <?php echo h($record['system_reference'] ?? ''); ?></div>
            <div class="mt-2">This is to acknowledge receipt of the returned property.</div>
        </div>
        <table class="table table-bordered align-middle">
            <thead>
            <tr>
                <th>Property Description</th>
                <th class="text-end">Quantity</th>
                <th>PAR No.</th>
                <th>End-user</th>
                <th>Remarks</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <div><?php echo h(property_return_label($record)); ?></div>
                    <div class="small text-muted"><?php echo h($record['property_number'] ?? ''); ?></div>
                </td>
                <td class="text-end">1.00</td>
                <td><?php echo h($record['par_no'] ?? ''); ?></td>
                <td><?php echo h(trim(implode(' / ', array_filter([$record['office_name'] ?? '', property_return_person($record)])))); ?></td>
                <td><?php echo h(trim(implode(' | ', array_filter([$record['reason'] ?? '', $record['remarks'] ?? ''])))); ?></td>
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
                            <h5 class="report-toolbar-title mb-0">Appendix 72</h5>
                            <p class="report-toolbar-copy">Select a posted equipment return record, review the Receipt of Returned Property, and print the official COA/GAM form from the same screen.</p>
                        </div>
                        <div class="report-toolbar-actions">
                            <?php if ($record): ?>
                                <a href="<?php echo h(base_url('modules/reports/property_return_slip.php?return_id=' . $returnId . '&export=excel')); ?>" class="btn btn-outline-success">
                                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                                </a>
                                <a href="<?php echo h(base_url('modules/reports/property_return_slip.php?return_id=' . $returnId . '&print=1')); ?>" class="btn btn-primary" target="_blank">
                                    <i class="bi bi-printer me-1"></i>Print
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="report-summary-grid"><div class="report-summary-card"><div class="report-summary-label">Available Returns</div><div class="report-summary-value"><?php echo number_format(count($returns)); ?></div><div class="report-summary-note">Posted equipment return records ready for Receipt of Returned Property printing.</div></div><div class="report-summary-card"><div class="report-summary-label">Loaded Record</div><div class="report-summary-value"><?php echo $record ? 'Ready' : 'None'; ?></div><div class="report-summary-note"><?php echo h($record['system_reference'] ?? 'Select one record to preview.'); ?></div></div></div>
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
                            <div class="col-md-4 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Load RRP</button>
                                <a href="<?php echo base_url('modules/reports/property_return_slip.php'); ?>" class="btn btn-outline-secondary">Clear</a>
                            </div>
                        </form>
                    </div>
                    <?php if (!$record): ?>
                        <div class="report-empty-state">Select a posted equipment return record to preview the Receipt of Returned Property.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
