<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Property Card Print';

$purchaseOrderId = (int) ($_GET['purchase_order_id'] ?? 0);
$officeId = (int) ($_GET['office_id'] ?? 0);
$source = trim($_GET['source'] ?? 'all');
if (!in_array($source, ['all', 'system', 'legacy'], true)) {
    $source = 'all';
}
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$purchaseOrders = [];
$offices = [];
$cards = [];

function property_card_meta(array $card): array
{
    $itemType = (string) ($card['item_type'] ?? '');
    if ($itemType === 'semi_expendable') {
        return [
            'appendix' => 'Annex A.1',
            'title' => 'Semi Expendable Property Card',
            'asset_label' => 'Semi-expendable Property',
            'number_label' => 'Semi-expendable Property Number',
            'reference_label' => 'Reference',
            'issue_label' => 'Issue / Transfer / Disposal',
            'issue_ref_label' => 'Item No.',
            'issue_party_label' => 'Office / Officer',
        ];
    }
    return [
        'appendix' => 'Appendix 69',
        'title' => 'Property Card',
        'asset_label' => 'Property, Plant and Equipment',
        'number_label' => 'Property Number',
        'reference_label' => 'Reference / PAR No.',
        'issue_label' => 'Issue / Transfer / Disposal',
        'issue_ref_label' => 'Reference',
        'issue_party_label' => 'Office / Officer',
    ];
}

if ($db) {
    ensure_distribution_item_runtime_columns($db);

    $poRes = $db->query("SELECT id, po_number FROM purchase_orders ORDER BY po_date DESC, id DESC");
    if ($poRes) {
        $purchaseOrders = $poRes->fetch_all(MYSQLI_ASSOC);
    }

    $officeRes = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeRes) {
        $offices = $officeRes->fetch_all(MYSQLI_ASSOC);
    }

    if ($source !== 'legacy') {
        $sql = "SELECT
                    si.id AS card_key,
                    'system' AS source_type,
                    si.system_reference AS stock_ref,
                    rid.brand,
                    rid.model,
                    rid.serial_no,
                    poi.item_description,
                    ri.unit_cost,
                    r.received_date,
                    r.system_reference AS iar_ref,
                    c.useful_life_years,
                    d.document_no AS accountability_no,
                    d.document_type,
                    d.distribution_date,
                    o.office_name,
                    e.first_name,
                    e.middle_name,
                    e.last_name,
                    e.suffix_name,
                    e.position_title,
                    rc.code AS rc_code,
                    did.property_number,
                    f.fund_code,
                    po.po_number,
                    poi.item_type,
                    c.classification_name,
                    c.classification_family
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                INNER JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
                INNER JOIN stock_items si ON si.id = rid.stock_item_id
                INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                INNER JOIN receivings r ON r.id = ri.receiving_id
                INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                LEFT JOIN funds f ON f.id = po.fund_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN offices o ON o.id = d.office_id
                LEFT JOIN employees e ON e.id = d.employee_id
                LEFT JOIN responsibility_codes rc ON rc.office_id = d.office_id
                WHERE poi.item_type IN ('equipment', 'semi_expendable')";

        $types = '';
        $params = [];
        if ($purchaseOrderId > 0) {
            $sql .= " AND po.id = ?";
            $types .= 'i';
            $params[] = $purchaseOrderId;
        }
        if ($officeId > 0) {
            $sql .= " AND d.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        $sql .= " ORDER BY po.po_number ASC, did.property_number ASC, si.id ASC";

        $stmt = $db->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $refs = [$types];
                foreach ($params as $k => $v) {
                    $refs[] = &$params[$k];
                }
                call_user_func_array([$stmt, 'bind_param'], $refs);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $cards[] = [
                    'card_key' => 'system-' . (int) $row['card_key'],
                    'source_type' => 'system',
                    'po_number' => $row['po_number'] ?? '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_code' => $row['fund_code'] ?? '',
                    'accountability_no' => $row['accountability_no'] ?? '',
                    'document_type' => strtoupper((string) ($row['document_type'] ?? '')),
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => $row['useful_life_years'] ?? '',
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                    'ledger' => [
                        [
                            'date' => $row['received_date'] ?? null,
                            'reference' => $row['iar_ref'] ?? '',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => (float) ($row['unit_cost'] ?? 0),
                            'receipt_cost' => (float) ($row['unit_cost'] ?? 0),
                            'issue_qty' => 0,
                            'issue_reference' => '',
                            'issue_party' => '',
                            'remarks' => 'Receipt',
                        ],
                        [
                            'date' => $row['distribution_date'] ?? null,
                            'reference' => $row['accountability_no'] ?? '',
                            'receipt_qty' => 0,
                            'receipt_unit_cost' => 0,
                            'receipt_cost' => 0,
                            'issue_qty' => 1,
                            'issue_reference' => $row['property_number'] ?? '',
                            'issue_party' => trim(implode(' / ', array_filter([
                                $row['office_name'] ?? '',
                                employee_display_name($row),
                            ]))),
                            'remarks' => 'Issued (' . strtoupper((string) ($row['document_type'] ?? '')) . ')',
                        ],
                    ],
                ];
            }
            $stmt->close();
        }
    }

    if ($source !== 'system' && $purchaseOrderId === 0) {
        $legacySql = "SELECT
                        la.id AS card_key,
                        'legacy' AS source_type,
                        la.system_reference AS stock_ref,
                        la.brand,
                        la.model,
                        la.serial_no,
                        la.item_description,
                        la.unit_cost,
                        la.acquisition_date AS received_date,
                        la.system_reference AS iar_ref,
                        NULL AS useful_life_years,
                        '' AS accountability_no,
                        la.item_type AS document_type,
                        la.acquisition_date AS distribution_date,
                        o.office_name,
                        e.first_name,
                        e.middle_name,
                        e.last_name,
                        e.suffix_name,
                        e.position_title,
                        rc.code AS rc_code,
                        la.property_number,
                        '' AS fund_code,
                        '' AS po_number,
                        la.item_type,
                        c.classification_name,
                        c.classification_family
                    FROM legacy_assets la
                    LEFT JOIN classifications c ON c.id = la.classification_id
                    LEFT JOIN offices o ON o.id = la.office_id
                    LEFT JOIN employees e ON e.id = la.employee_id
                    LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
                    WHERE la.is_active = 1";
        $legacyTypes = '';
        $legacyParams = [];
        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $officeId;
        }
        $legacySql .= " ORDER BY la.property_number ASC, la.id ASC";

        $legacyStmt = $db->prepare($legacySql);
        if ($legacyStmt) {
            if ($legacyTypes !== '') {
                $refs = [$legacyTypes];
                foreach ($legacyParams as $k => $v) {
                    $refs[] = &$legacyParams[$k];
                }
                call_user_func_array([$legacyStmt, 'bind_param'], $refs);
            }
            $legacyStmt->execute();
            $res = $legacyStmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $cards[] = [
                    'card_key' => 'legacy-' . (int) $row['card_key'],
                    'source_type' => 'legacy',
                    'po_number' => '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_code' => '',
                    'accountability_no' => 'Beginning Balance',
                    'document_type' => 'LEGACY',
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => '',
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => (float) ($row['unit_cost'] ?? 0),
                    'ledger' => [
                        [
                            'date' => $row['received_date'] ?? null,
                            'reference' => 'Beginning Balance',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => (float) ($row['unit_cost'] ?? 0),
                            'receipt_cost' => (float) ($row['unit_cost'] ?? 0),
                            'issue_qty' => 0,
                            'issue_reference' => '',
                            'issue_party' => '',
                            'remarks' => 'Opening balance',
                        ],
                    ],
                ];
            }
            $legacyStmt->close();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Property Card Print</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            .card-print { page-break-after: always; }
            .card-print:last-child { page-break-after: auto; }
        }
    </style>
</head>
<body>
<div class="container mt-3">
    <div class="no-print mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-0">Property Card Print</h4>
                <div class="text-muted small">Print property cards by PO, office, or source.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/property/index.php'); ?>" class="btn btn-outline-secondary">Back to Property Register</a>
                <?php if ($cards): ?>
                    <a href="<?php echo h(base_url('modules/property/property_card_print.php?' . http_build_query(array_filter([
                        'purchase_order_id' => $purchaseOrderId ?: null,
                        'office_id' => $officeId ?: null,
                        'source' => $source !== 'all' ? $source : null,
                        'print' => 1,
                    ])))); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Purchase Order</label>
                <select name="purchase_order_id" class="form-select">
                    <option value="">All Purchase Orders</option>
                    <?php foreach ($purchaseOrders as $po): ?>
                        <option value="<?php echo (int) $po['id']; ?>" <?php echo $purchaseOrderId === (int) $po['id'] ? 'selected' : ''; ?>>
                            <?php echo h($po['po_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Legacy assets do not have a linked PO unless they were encoded through the new transaction flow.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Office</label>
                <select name="office_id" class="form-select">
                    <option value="">All Offices</option>
                    <?php foreach ($offices as $office): ?>
                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                            <?php echo h($office['office_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <option value="all" <?php echo $source === 'all' ? 'selected' : ''; ?>>All Sources</option>
                    <option value="system" <?php echo $source === 'system' ? 'selected' : ''; ?>>System Transactions</option>
                    <option value="legacy" <?php echo $source === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Load Cards</button>
                <a href="<?php echo base_url('modules/property/property_card_print.php'); ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>

        <div class="small text-muted mt-3"><?php echo count($cards); ?> card(s) found.</div>
    </div>

    <?php if (!$cards): ?>
        <div class="alert alert-info">No property cards found for the current filter.</div>
    <?php endif; ?>

    <?php foreach ($cards as $card): ?>
        <div class="card card-print mb-4">
            <div class="card-body">
                <div class="text-center">
                    <?php $meta = property_card_meta($card); ?>
                    <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px;height:60px;object-fit:contain;" alt="logo">
                    <div class="small fst-italic"><?php echo h($meta['appendix']); ?></div>
                    <h5 class="mt-2">University of Antique</h5>
                    <div><?php echo h($meta['title']); ?></div>
                </div>
                <div class="row mt-3">
                    <div class="col-6">
                        <div><strong>Fund Cluster:</strong> <?php echo h($card['fund_code']); ?></div>
                        <div><strong><?php echo h($meta['asset_label']); ?>:</strong> <?php echo h($card['classification_name'] ?: $card['classification_family']); ?></div>
                        <div><strong><?php echo h($meta['number_label']); ?>:</strong> <?php echo h($card['property_number']); ?></div>
                        <div><strong>Source:</strong> <?php echo h($card['source_type'] === 'legacy' ? 'Beginning Balance' : 'System Transaction'); ?></div>
                        <?php if ($card['po_number'] !== ''): ?>
                            <div><strong>PO Number:</strong> <?php echo h($card['po_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <div><strong>Item Description:</strong> <?php echo h($card['item_description']); ?></div>
                        <div><strong>Reference No.:</strong> <?php echo h($card['accountability_no']); ?></div>
                        <div><strong>Brand:</strong> <?php echo h($card['brand']); ?></div>
                        <div><strong>Model:</strong> <?php echo h($card['model']); ?></div>
                        <div><strong>Serial No.:</strong> <?php echo h($card['serial_no']); ?></div>
                        <div><strong>Estimated Useful Life:</strong> <?php echo h($card['useful_life_years']); ?></div>
                        <div><strong>Responsibility Center / Office:</strong> <?php echo h($card['office_name']); ?></div>
                        <div><strong>Accountable Person:</strong> <?php echo h(trim($card['accountable_person'] . ' ' . $card['position_title'])); ?></div>
                        <div><strong>RC Code:</strong> <?php echo h($card['rc_code']); ?></div>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th rowspan="2">Date</th>
                                <th rowspan="2"><?php echo h($meta['reference_label']); ?></th>
                                <th colspan="3" class="text-center">Receipt</th>
                                <th colspan="3" class="text-center"><?php echo h($meta['issue_label']); ?></th>
                                <th rowspan="2">Balance Qty</th>
                                <th rowspan="2">Amount</th>
                                <th rowspan="2">Remarks</th>
                            </tr>
                            <tr>
                                <th>Qty.</th>
                                <th>Unit Cost</th>
                                <th>Total Cost</th>
                                <th><?php echo h($meta['issue_ref_label']); ?></th>
                                <th>Qty.</th>
                                <th><?php echo h($meta['issue_party_label']); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $balQty = 0;
                            $balCost = 0.0;
                            foreach ($card['ledger'] as $row):
                                $balQty += ($row['receipt_qty'] ?? 0) - ($row['issue_qty'] ?? 0);
                                $balCost += ($row['receipt_cost'] ?? 0) - (($row['issue_qty'] ?? 0) * ($card['unit_cost'] ?? 0));
                            ?>
                            <tr>
                                <td><?php echo h(!empty($row['date']) ? date('M d, Y', strtotime($row['date'])) : ''); ?></td>
                                <td><?php echo h($row['reference'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['receipt_qty'] ?? 0), 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['receipt_unit_cost'] ?? 0), 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['receipt_cost'] ?? 0), 2)); ?></td>
                                <td><?php echo h($row['issue_reference'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['issue_qty'] ?? 0), 2)); ?></td>
                                <td><?php echo h($row['issue_party'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(number_format($balQty, 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format($balCost, 2)); ?></td>
                                <td><?php echo h($row['remarks'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($autoPrint && $cards): ?>
<script>window.addEventListener('load', function(){ window.print(); });</script>
<?php endif; ?>
</body>
</html>
