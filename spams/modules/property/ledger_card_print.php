<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$page_title = 'Ledger Card Print';

$purchaseOrderId = (int) ($_GET['purchase_order_id'] ?? 0);
$officeId = (int) ($_GET['office_id'] ?? 0);
$source = trim($_GET['source'] ?? 'all');
$itemType = trim($_GET['item_type'] ?? 'all');
if (!in_array($source, ['all', 'system', 'legacy'], true)) {
    $source = 'all';
}
if (!in_array($itemType, ['all', 'equipment', 'semi_expendable'], true)) {
    $itemType = 'all';
}
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$purchaseOrders = [];
$offices = [];
$cards = [];

function ledger_card_meta(array $card): array
{
    $type = (string) ($card['item_type'] ?? '');
    if ($type === 'semi_expendable') {
        return [
            'appendix' => 'Annex A.2',
            'title' => 'Semi Expendable Property Ledger Card',
            'asset_label' => 'Semi-expendable Property',
            'number_label' => 'Semi-expendable Property Number',
            'code_label' => 'UACS Object Code',
            'life_label' => '',
            'rate_label' => '',
            'issue_label' => 'Issues / Transfers / Adjustment/s',
            'balance_label' => 'Adjusted Cost',
            'repair_label' => '',
        ];
    }

    return [
        'appendix' => 'Appendix 70',
        'title' => 'Property, Plant and Equipment Ledger Card',
        'asset_label' => 'Property, Plant and Equipment',
        'number_label' => 'Property Number',
        'code_label' => 'Object Account Code',
        'life_label' => 'Estimated Useful Life',
        'rate_label' => 'Rate of Depreciation',
        'issue_label' => 'Issues / Transfers / Adjustment/s',
        'balance_label' => 'Adjusted Cost',
        'repair_label' => 'Repair History',
    ];
}

function ledger_sort_rows(array &$rows): void
{
    usort($rows, static function ($a, $b) {
        $dateA = (string) ($a['date'] ?? '');
        $dateB = (string) ($b['date'] ?? '');
        if ($dateA === $dateB) {
            return strcmp((string) ($a['reference'] ?? ''), (string) ($b['reference'] ?? ''));
        }
        return strcmp($dateA, $dateB);
    });
}

if ($db) {
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
                    did.id AS detail_id,
                    'system' AS source_type,
                    rid.stock_item_id AS card_key,
                    did.property_number,
                    poi.item_type,
                    poi.item_description,
                    c.classification_name,
                    c.classification_family,
                    ac.account_code,
                    ac.account_name,
                    c.useful_life_years,
                    rid.brand,
                    rid.model,
                    rid.serial_no,
                    ri.unit_cost,
                    r.received_date,
                    r.system_reference AS iar_ref,
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
                    f.fund_code,
                    po.po_number
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
                INNER JOIN receiving_item_details rid ON rid.id = did.receiving_item_detail_id
                INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                INNER JOIN receivings r ON r.id = ri.receiving_id
                INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                LEFT JOIN funds f ON f.id = po.fund_id
                LEFT JOIN classifications c ON c.id = poi.classification_id
                LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
                LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
                LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
                LEFT JOIN responsibility_codes rc ON rc.id = did.current_responsibility_code_id
                WHERE poi.item_type IN ('equipment', 'semi_expendable')
                  AND did.is_distributed = 1
                  AND (did.is_disposed IS NULL OR did.is_disposed = 0)";

        $types = '';
        $params = [];
        if ($purchaseOrderId > 0) {
            $sql .= " AND po.id = ?";
            $types .= 'i';
            $params[] = $purchaseOrderId;
        }
        if ($officeId > 0) {
            $sql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($itemType !== 'all') {
            $sql .= " AND poi.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        $sql .= " ORDER BY poi.item_type ASC, did.property_number ASC, did.id ASC";

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
                $key = 'system-' . (int) ($row['detail_id'] ?? 0);
                $unitCost = (float) ($row['unit_cost'] ?? 0);
                $rate = !empty($row['useful_life_years']) && (float) $row['useful_life_years'] > 0
                    ? number_format(100 / (float) $row['useful_life_years'], 2) . '%'
                    : '';
                $cards[$key] = [
                    'card_key' => $key,
                    'source_type' => 'system',
                    'detail_id' => (int) ($row['detail_id'] ?? 0),
                    'po_number' => $row['po_number'] ?? '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_code' => $row['fund_code'] ?? '',
                    'account_code' => trim(implode(' - ', array_filter([
                        $row['account_code'] ?? '',
                        $row['account_name'] ?? '',
                    ]))),
                    'accountability_no' => $row['accountability_no'] ?? '',
                    'document_type' => strtoupper((string) ($row['document_type'] ?? '')),
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => $row['useful_life_years'] ?? '',
                    'depreciation_rate' => $rate,
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => $unitCost,
                    'ledger' => [
                        [
                            'date' => $row['received_date'] ?? null,
                            'reference' => $row['iar_ref'] ?? '',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => $unitCost,
                            'receipt_cost' => $unitCost,
                            'accumulated_depreciation' => '',
                            'accumulated_impairment' => '',
                            'issue_reference' => '',
                            'issue_qty' => 0,
                            'issue_party' => '',
                            'adjusted_cost' => $unitCost,
                            'repair_nature' => '',
                            'repair_amount' => '',
                            'remarks' => 'Receipt',
                        ],
                        [
                            'date' => $row['distribution_date'] ?? null,
                            'reference' => $row['accountability_no'] ?? '',
                            'receipt_qty' => 0,
                            'receipt_unit_cost' => 0,
                            'receipt_cost' => 0,
                            'accumulated_depreciation' => '',
                            'accumulated_impairment' => '',
                            'issue_reference' => $row['property_number'] ?? '',
                            'issue_qty' => 1,
                            'issue_party' => trim(implode(' / ', array_filter([
                                $row['office_name'] ?? '',
                                employee_display_name($row),
                            ]))),
                            'adjusted_cost' => 0,
                            'repair_nature' => '',
                            'repair_amount' => '',
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
                        la.id AS legacy_id,
                        'legacy' AS source_type,
                        la.property_number,
                        la.item_type,
                        la.item_description,
                        la.brand,
                        la.model,
                        la.serial_no,
                        la.unit_cost,
                        la.acquisition_date,
                        la.system_reference,
                        la.office_id,
                        o.office_name,
                        e.first_name,
                        e.middle_name,
                        e.last_name,
                        e.suffix_name,
                        e.position_title,
                        rc.code AS rc_code,
                        c.classification_name,
                        c.classification_family,
                        ac.account_code,
                        ac.account_name
                    FROM legacy_assets la
                    LEFT JOIN offices o ON o.id = la.office_id
                    LEFT JOIN employees e ON e.id = la.employee_id
                    LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
                    LEFT JOIN classifications c ON c.id = la.classification_id
                    LEFT JOIN account_codes ac ON ac.id = la.account_code_id
                    WHERE la.is_active = 1
                      AND la.item_type IN ('equipment', 'semi_expendable')";
        $legacyTypes = '';
        $legacyParams = [];
        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $officeId;
        }
        if ($itemType !== 'all') {
            $legacySql .= " AND la.item_type = ?";
            $legacyTypes .= 's';
            $legacyParams[] = $itemType;
        }
        $legacySql .= " ORDER BY la.item_type ASC, la.property_number ASC, la.id ASC";

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
                $key = 'legacy-' . (int) ($row['legacy_id'] ?? 0);
                $unitCost = (float) ($row['unit_cost'] ?? 0);
                $cards[$key] = [
                    'card_key' => $key,
                    'source_type' => 'legacy',
                    'detail_id' => 0,
                    'po_number' => '',
                    'item_type' => $row['item_type'] ?? '',
                    'classification_name' => $row['classification_name'] ?? '',
                    'classification_family' => $row['classification_family'] ?? '',
                    'fund_code' => '',
                    'account_code' => trim(implode(' - ', array_filter([
                        $row['account_code'] ?? '',
                        $row['account_name'] ?? '',
                    ]))),
                    'accountability_no' => 'Beginning Balance',
                    'document_type' => 'LEGACY',
                    'property_number' => $row['property_number'] ?? '',
                    'item_description' => $row['item_description'] ?? '',
                    'brand' => $row['brand'] ?? '',
                    'model' => $row['model'] ?? '',
                    'serial_no' => $row['serial_no'] ?? '',
                    'useful_life_years' => '',
                    'depreciation_rate' => '',
                    'office_name' => $row['office_name'] ?? '',
                    'accountable_person' => employee_display_name($row),
                    'position_title' => $row['position_title'] ?? '',
                    'rc_code' => $row['rc_code'] ?? '',
                    'unit_cost' => $unitCost,
                    'ledger' => [
                        [
                            'date' => $row['acquisition_date'] ?? null,
                            'reference' => 'Beginning Balance',
                            'receipt_qty' => 1,
                            'receipt_unit_cost' => $unitCost,
                            'receipt_cost' => $unitCost,
                            'accumulated_depreciation' => '',
                            'accumulated_impairment' => '',
                            'issue_reference' => '',
                            'issue_qty' => 0,
                            'issue_party' => '',
                            'adjusted_cost' => $unitCost,
                            'repair_nature' => '',
                            'repair_amount' => '',
                            'remarks' => 'Opening balance',
                        ],
                    ],
                ];
            }
            $legacyStmt->close();
        }
    }

    $detailIds = [];
    foreach ($cards as $card) {
        if (($card['source_type'] ?? '') === 'system' && (int) ($card['detail_id'] ?? 0) > 0 && ($card['item_type'] ?? '') === 'equipment') {
            $detailIds[] = (int) $card['detail_id'];
        }
    }
    $detailIds = array_values(array_unique($detailIds));

    if ($detailIds) {
        $placeholders = implode(',', array_fill(0, count($detailIds), '?'));
        $types = str_repeat('i', count($detailIds));
        $repairSql = "
            SELECT
                distribution_item_detail_id,
                maintenance_date,
                system_reference,
                work_description,
                cost,
                remarks
            FROM maintenance_logs
            WHERE status = 'posted'
              AND distribution_item_detail_id IN ($placeholders)
            ORDER BY maintenance_date ASC, id ASC
        ";
        $repairStmt = $db->prepare($repairSql);
        if ($repairStmt) {
            $refs = [$types];
            foreach ($detailIds as $k => $v) {
                $refs[] = &$detailIds[$k];
            }
            call_user_func_array([$repairStmt, 'bind_param'], $refs);
            $repairStmt->execute();
            $repairs = $repairStmt->get_result();
            while ($repairs && ($repair = $repairs->fetch_assoc())) {
                $key = 'system-' . (int) ($repair['distribution_item_detail_id'] ?? 0);
                if (!isset($cards[$key])) {
                    continue;
                }
                $cards[$key]['ledger'][] = [
                    'date' => $repair['maintenance_date'] ?? null,
                    'reference' => $repair['system_reference'] ?? '',
                    'receipt_qty' => 0,
                    'receipt_unit_cost' => 0,
                    'receipt_cost' => 0,
                    'accumulated_depreciation' => '',
                    'accumulated_impairment' => '',
                    'issue_reference' => '',
                    'issue_qty' => 0,
                    'issue_party' => '',
                    'adjusted_cost' => $cards[$key]['unit_cost'] ?? 0,
                    'repair_nature' => $repair['work_description'] ?? '',
                    'repair_amount' => (float) ($repair['cost'] ?? 0) > 0 ? number_format((float) ($repair['cost'] ?? 0), 2) : '',
                    'remarks' => $repair['remarks'] ?? '',
                ];
            }
            $repairStmt->close();
        }
    }

    foreach ($cards as &$card) {
        ledger_sort_rows($card['ledger']);
    }
    unset($card);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Ledger Card Print</title>
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
                <h4 class="mb-0">Ledger Card Print</h4>
                <div class="text-muted small">Print semi-expendable and equipment ledger cards by PO, office, source, or type.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo base_url('modules/property/index.php'); ?>" class="btn btn-outline-secondary">Back to Property Register</a>
                <?php if ($cards): ?>
                    <a href="<?php echo h(base_url('modules/property/ledger_card_print.php?' . http_build_query(array_filter([
                        'purchase_order_id' => $purchaseOrderId ?: null,
                        'office_id' => $officeId ?: null,
                        'source' => $source !== 'all' ? $source : null,
                        'item_type' => $itemType !== 'all' ? $itemType : null,
                        'print' => 1,
                    ])))); ?>" class="btn btn-primary">Print Current Result</a>
                <?php endif; ?>
            </div>
        </div>

        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Purchase Order</label>
                <select name="purchase_order_id" class="form-select">
                    <option value="">All Purchase Orders</option>
                    <?php foreach ($purchaseOrders as $po): ?>
                        <option value="<?php echo (int) $po['id']; ?>" <?php echo $purchaseOrderId === (int) $po['id'] ? 'selected' : ''; ?>>
                            <?php echo h($po['po_number']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
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
            <div class="col-md-2">
                <label class="form-label">Source</label>
                <select name="source" class="form-select">
                    <option value="all" <?php echo $source === 'all' ? 'selected' : ''; ?>>All Sources</option>
                    <option value="system" <?php echo $source === 'system' ? 'selected' : ''; ?>>System Transactions</option>
                    <option value="legacy" <?php echo $source === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Item Type</label>
                <select name="item_type" class="form-select">
                    <option value="all" <?php echo $itemType === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="equipment" <?php echo $itemType === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                    <option value="semi_expendable" <?php echo $itemType === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary" type="submit">Load Cards</button>
                <a href="<?php echo base_url('modules/property/ledger_card_print.php'); ?>" class="btn btn-outline-secondary">Clear</a>
            </div>
        </form>

        <div class="small text-muted mt-3"><?php echo count($cards); ?> card(s) found.</div>
    </div>

    <?php if (!$cards): ?>
        <div class="alert alert-info">No ledger cards found for the current filter.</div>
    <?php endif; ?>

    <?php foreach ($cards as $card): ?>
        <?php $meta = ledger_card_meta($card); ?>
        <div class="card card-print mb-4">
            <div class="card-body">
                <div class="text-center">
                    <div class="small fst-italic"><?php echo h($meta['appendix']); ?></div>
                    <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px;height:60px;object-fit:contain;" alt="logo">
                    <h5 class="mt-2">University of Antique</h5>
                    <div><?php echo h($meta['title']); ?></div>
                </div>

                <div class="row mt-3">
                    <div class="col-6">
                        <div><strong>Fund Cluster:</strong> <?php echo h($card['fund_code']); ?></div>
                        <div><strong><?php echo h($meta['asset_label']); ?>:</strong> <?php echo h($card['classification_name'] ?: $card['classification_family']); ?></div>
                        <div><strong><?php echo h($meta['number_label']); ?>:</strong> <?php echo h($card['property_number']); ?></div>
                        <div><strong>Description:</strong> <?php echo h($card['item_description']); ?></div>
                    </div>
                    <div class="col-6">
                        <div><strong><?php echo h($meta['code_label']); ?>:</strong> <?php echo h($card['account_code']); ?></div>
                        <?php if ($meta['life_label'] !== ''): ?>
                            <div><strong><?php echo h($meta['life_label']); ?>:</strong> <?php echo h($card['useful_life_years'] !== '' ? $card['useful_life_years'] . ' year(s)' : ''); ?></div>
                        <?php endif; ?>
                        <?php if ($meta['rate_label'] !== ''): ?>
                            <div><strong><?php echo h($meta['rate_label']); ?>:</strong> <?php echo h($card['depreciation_rate']); ?></div>
                        <?php endif; ?>
                        <div><strong>Office / Officer:</strong> <?php echo h(trim(implode(' / ', array_filter([$card['office_name'], $card['accountable_person']])))); ?></div>
                        <div><strong>RC Code:</strong> <?php echo h($card['rc_code']); ?></div>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th rowspan="2">Date</th>
                                <th rowspan="2">Reference</th>
                                <th colspan="3" class="text-center">Receipt</th>
                                <?php if ($card['item_type'] === 'equipment'): ?>
                                    <th rowspan="2">Accumulated Depreciation</th>
                                <?php endif; ?>
                                <th rowspan="2">Accumulated Impairment Losses</th>
                                <th colspan="3" class="text-center"><?php echo h($meta['issue_label']); ?></th>
                                <th rowspan="2"><?php echo h($meta['balance_label']); ?></th>
                                <?php if ($card['item_type'] === 'equipment'): ?>
                                    <th colspan="2" class="text-center">Repair History</th>
                                <?php endif; ?>
                                <th rowspan="2">Remarks</th>
                            </tr>
                            <tr>
                                <th>Qty.</th>
                                <th>Unit Cost</th>
                                <th>Total Cost</th>
                                <th>Reference</th>
                                <th>Qty.</th>
                                <th>Office / Officer</th>
                                <?php if ($card['item_type'] === 'equipment'): ?>
                                    <th>Nature of Repair</th>
                                    <th>Amount</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $balQty = 0.0;
                            $balCost = 0.0;
                            foreach ($card['ledger'] as $row):
                                $balQty += (float) ($row['receipt_qty'] ?? 0) - (float) ($row['issue_qty'] ?? 0);
                                $balCost += (float) ($row['receipt_cost'] ?? 0) - ((float) ($row['issue_qty'] ?? 0) * (float) ($card['unit_cost'] ?? 0));
                            ?>
                                <tr>
                                    <td><?php echo h(!empty($row['date']) ? date('M d, Y', strtotime((string) $row['date'])) : ''); ?></td>
                                    <td><?php echo h($row['reference'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($row['receipt_qty'] ?? 0), 2)); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($row['receipt_unit_cost'] ?? 0), 2)); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($row['receipt_cost'] ?? 0), 2)); ?></td>
                                    <?php if ($card['item_type'] === 'equipment'): ?>
                                        <td class="text-end"><?php echo h($row['accumulated_depreciation'] !== '' ? (string) $row['accumulated_depreciation'] : ''); ?></td>
                                    <?php endif; ?>
                                    <td class="text-end"><?php echo h($row['accumulated_impairment'] !== '' ? (string) $row['accumulated_impairment'] : ''); ?></td>
                                    <td><?php echo h($row['issue_reference'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($row['issue_qty'] ?? 0), 2)); ?></td>
                                    <td><?php echo h($row['issue_party'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo h(number_format((float) ($row['adjusted_cost'] !== '' ? $row['adjusted_cost'] : $balCost), 2)); ?></td>
                                    <?php if ($card['item_type'] === 'equipment'): ?>
                                        <td><?php echo h($row['repair_nature'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo h($row['repair_amount'] ?? ''); ?></td>
                                    <?php endif; ?>
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
