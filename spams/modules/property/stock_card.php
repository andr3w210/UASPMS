<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

$db = db();
$page_title = 'Stock Card Workspace';
$purchaseOrderId = (int) ($_GET['purchase_order_id'] ?? 0);
$stockItemId = (int) ($_GET['stock_item_id'] ?? 0);
$receivingId = (int) ($_GET['receiving_id'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));
$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';

$purchaseOrders = [];
$stockLots = [];
$selectedLot = null;
$ledgerRows = [];
$summary = [
    'lot_count' => 0,
    'total_received' => 0.00,
    'total_issued' => 0.00,
    'total_on_hand' => 0.00,
];
$stockCardTargetRows = 34;

if (!$db) {
    http_response_code(500);
    exit('Unable to connect to the database.');
}

$poRes = $db->query("
    SELECT DISTINCT po.id, po.po_number, po.po_date
    FROM stock_items si
    INNER JOIN purchase_order_items poi ON poi.id = si.purchase_order_item_id
    INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
    WHERE si.item_type = 'supply'
    ORDER BY po.po_date DESC, po.id DESC
");
if ($poRes instanceof mysqli_result) {
    $purchaseOrders = $poRes->fetch_all(MYSQLI_ASSOC);
}

$sql = "
    SELECT
        si.id,
        si.system_reference,
        si.item_description,
        si.unit_cost,
        si.quantity_received,
        si.quantity_issued,
        si.quantity_on_hand,
        si.created_at,
        poi.purchase_order_id,
        si.receiving_id,
        ac.account_code,
        ac.account_name,
        c.classification_name,
        c.classification_family,
        u.uom_name,
        u.abbreviation,
        r.system_reference AS iar_reference,
        r.ris_no,
        r.received_date,
        po.po_number,
        po.po_date,
        f.fund_code,
        f.fund_source,
        s.supplier_name
    FROM stock_items si
    LEFT JOIN account_codes ac ON ac.id = si.account_code_id
    LEFT JOIN classifications c ON c.id = si.classification_id
    LEFT JOIN unit_of_measures u ON u.id = si.unit_of_measure_id
    LEFT JOIN receivings r ON r.id = si.receiving_id
    LEFT JOIN purchase_order_items poi ON poi.id = si.purchase_order_item_id
    LEFT JOIN purchase_orders po ON po.id = poi.purchase_order_id
    LEFT JOIN funds f ON f.id = po.fund_id
    LEFT JOIN suppliers s ON s.id = po.supplier_id
    WHERE si.item_type = 'supply'
";
$types = '';
$params = [];

if ($purchaseOrderId > 0) {
    $sql .= " AND poi.purchase_order_id = ?";
    $types .= 'i';
    $params[] = $purchaseOrderId;
}
if ($search !== '') {
    $sql .= " AND (
        si.system_reference LIKE ?
        OR si.item_description LIKE ?
        OR po.po_number LIKE ?
        OR s.supplier_name LIKE ?
        OR c.classification_name LIKE ?
    )";
    $types .= 'sssss';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY po.po_date DESC, r.received_date DESC, si.id DESC";

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
    $stockLots = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}

foreach ($stockLots as $lot) {
    $summary['lot_count']++;
    $summary['total_received'] += (float) ($lot['quantity_received'] ?? 0);
    $summary['total_issued'] += (float) ($lot['quantity_issued'] ?? 0);
    $summary['total_on_hand'] += (float) ($lot['quantity_on_hand'] ?? 0);
}

if ($stockItemId <= 0 && $receivingId > 0) {
    foreach ($stockLots as $lot) {
        if ((int) ($lot['receiving_id'] ?? 0) === $receivingId) {
            $stockItemId = (int) ($lot['id'] ?? 0);
            break;
        }
    }
}
if ($stockItemId <= 0 && !empty($stockLots)) {
    $stockItemId = (int) ($stockLots[0]['id'] ?? 0);
}

foreach ($stockLots as $lot) {
    if ((int) ($lot['id'] ?? 0) === $stockItemId) {
        $selectedLot = $lot;
        break;
    }
}

if ($selectedLot) {
    $ledgerRows[] = [
        'date' => $selectedLot['received_date'] ?? '',
        'reference' => (string) ($selectedLot['ris_no'] ?? $selectedLot['iar_reference'] ?? ''),
        'receipt_qty' => (float) ($selectedLot['quantity_received'] ?? 0),
        'issue_qty' => 0.00,
        'office' => '',
        'balance_qty' => (float) ($selectedLot['quantity_received'] ?? 0),
        'days_to_consume' => '',
    ];

    $issueStmt = $db->prepare("
        SELECT
            iss.issuance_date,
            iss.system_reference,
            iss.purpose,
            ii.quantity_issued,
            r.ris_no AS receiving_ris_no,
            r.system_reference AS receiving_iar_no,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM issuance_items ii
        INNER JOIN issuances iss ON iss.id = ii.issuance_id AND iss.status = 'posted'
        LEFT JOIN stock_items si ON si.id = ii.stock_item_id
        LEFT JOIN receivings r ON r.id = si.receiving_id
        LEFT JOIN offices o ON o.id = iss.office_id
        LEFT JOIN employees e ON e.id = iss.employee_id
        WHERE ii.stock_item_id = ?
        ORDER BY iss.issuance_date ASC, ii.id ASC
    ");
    if ($issueStmt) {
        $issueStmt->bind_param('i', $stockItemId);
        $issueStmt->execute();
        $issueRes = $issueStmt->get_result();
        $balance = (float) ($selectedLot['quantity_received'] ?? 0);
        while ($issueRes && ($row = $issueRes->fetch_assoc())) {
            $balance -= (float) ($row['quantity_issued'] ?? 0);
            $employeeName = trim(implode(' ', array_filter([
                trim((string) ($row['first_name'] ?? '')),
                trim((string) ($row['middle_name'] ?? '')),
                trim((string) ($row['last_name'] ?? '')),
                trim((string) ($row['suffix_name'] ?? '')),
            ])));
            $ledgerRows[] = [
                'date' => $row['issuance_date'] ?? '',
                'reference' => (string) ($row['system_reference'] ?? ''),
                'receipt_qty' => 0.00,
                'issue_qty' => (float) ($row['quantity_issued'] ?? 0),
                'office' => (string) ($row['office_name'] ?? ''),
                'balance_qty' => $balance,
                'days_to_consume' => '',
            ];
        }
        $issueStmt->close();
    }
}

$stockCardFundCluster = '';
if ($selectedLot) {
    $stockCardFundCluster = trim((string) ($selectedLot['fund_source'] ?? ''));
    if ($stockCardFundCluster === '') {
        $stockCardFundCluster = trim((string) ($selectedLot['fund_code'] ?? ''));
    }
    if (preg_match('/(?:^|[^0-9])(0[1567])(?:[^0-9]|$)/', $stockCardFundCluster, $matches)) {
        $stockCardFundCluster = $matches[1];
    } elseif (preg_match('/([0-9]{2})/', $stockCardFundCluster, $matches)) {
        $stockCardFundCluster = $matches[1];
    }
}

$blankLedgerRowCount = 0;
if ($selectedLot) {
    $blankLedgerRowCount = max(0, $stockCardTargetRows - count($ledgerRows));
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4 stock-card-workspace-shell">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4 stock-card-screen-only">
                    <div>
                        <h4 class="mb-1">Stock Card Workspace</h4>
                        <div class="text-muted small">Supply stock lots with receipt and issuance history, ready for review and print.</div>
                    </div>
                    <?php if ($selectedLot): ?>
                        <div class="d-flex gap-2 flex-wrap">
                            <a href="<?php echo base_url('modules/property/stock_card.php?' . http_build_query([
                                'stock_item_id' => (int) $selectedLot['id'],
                                'purchase_order_id' => $purchaseOrderId ?: null,
                                'q' => $search !== '' ? $search : null,
                            ])); ?>" class="btn btn-outline-secondary btn-sm">Reset View</a>
                            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print Selected Card</button>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="row g-3 mb-4 stock-card-screen-only">
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Supply Lots</div>
                            <div class="fw-semibold"><?php echo h((string) $summary['lot_count']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Received</div>
                            <div class="fw-semibold"><?php echo h(format_quantity($summary['total_received'])); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Issued</div>
                            <div class="fw-semibold"><?php echo h(format_quantity($summary['total_issued'])); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total On Hand</div>
                            <div class="fw-semibold"><?php echo h(format_quantity($summary['total_on_hand'])); ?></div>
                        </div>
                    </div>
                </div>

                <form method="get" class="row g-2 align-items-end mb-4 stock-card-screen-only">
                    <div class="col-md-4">
                        <label class="form-label mb-0">Purchase Order</label>
                        <select name="purchase_order_id" class="form-select form-select-sm">
                            <option value="">All Purchase Orders</option>
                            <?php foreach ($purchaseOrders as $po): ?>
                                <option value="<?php echo (int) $po['id']; ?>" <?php echo $purchaseOrderId === (int) $po['id'] ? 'selected' : ''; ?>>
                                    <?php echo h(($po['po_number'] ?? '') . ' | ' . (!empty($po['po_date']) ? date('M d, Y', strtotime((string) $po['po_date'])) : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-0">Search Lots</label>
                        <input type="search" name="q" class="form-control form-control-sm" value="<?php echo h($search); ?>" placeholder="Stock no, description, PO, supplier, classification">
                    </div>
                    <div class="col-md-2">
                        <input type="hidden" name="stock_item_id" value="<?php echo $stockItemId > 0 ? (int) $stockItemId : ''; ?>">
                        <button type="submit" class="btn btn-sm btn-primary w-100">Apply</button>
                    </div>
                    <div class="col-md-2">
                        <a href="<?php echo base_url('modules/property/stock_card.php'); ?>" class="btn btn-sm btn-outline-secondary w-100">Clear</a>
                    </div>
                </form>

                <div class="row g-4">
                    <div class="col-lg-4 stock-card-screen-only">
                        <div class="card h-100">
                            <div class="card-header bg-white">
                                <h6 class="mb-0">Supply Lots</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php if ($stockLots): ?>
                                        <?php foreach ($stockLots as $lot): ?>
                                            <?php
                                            $isActive = (int) ($lot['id'] ?? 0) === $stockItemId;
                                            $linkParams = [
                                                'stock_item_id' => (int) $lot['id'],
                                            ];
                                            if ($purchaseOrderId > 0) {
                                                $linkParams['purchase_order_id'] = $purchaseOrderId;
                                            }
                                            if ($search !== '') {
                                                $linkParams['q'] = $search;
                                            }
                                            ?>
                                            <a href="<?php echo base_url('modules/property/stock_card.php?' . http_build_query($linkParams)); ?>" class="list-group-item list-group-item-action <?php echo $isActive ? 'active' : ''; ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="fw-semibold"><?php echo h($lot['item_description'] ?? ''); ?></div>
                                                        <div class="small <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">
                                                            <?php echo h(($lot['classification_name'] ?? 'Unclassified') . (!empty($lot['po_number']) ? ' | PO ' . $lot['po_number'] : '')); ?>
                                                        </div>
                                                        <div class="small <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">
                                                            <?php echo h($lot['system_reference'] ?? ''); ?><?php echo !empty($lot['supplier_name']) ? ' | ' . h($lot['supplier_name']) : ''; ?>
                                                        </div>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="small <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>">On hand</div>
                                                        <div class="fw-semibold"><?php echo h(format_quantity($lot['quantity_on_hand'] ?? 0)); ?></div>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="p-4 text-center text-muted">No supply stock lots found for the current filter.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <?php if ($selectedLot): ?>
                            <?php
                            $unitLabel = trim((string) ($selectedLot['uom_name'] ?? ''));
                            if ($unitLabel === '' && !empty($selectedLot['abbreviation'])) {
                                $unitLabel = (string) $selectedLot['abbreviation'];
                            }
                            $stockCardItemLabel = trim(implode(' / ', array_filter([
                                trim((string) ($selectedLot['classification_family'] ?? '')),
                                trim((string) ($selectedLot['classification_name'] ?? '')),
                            ])));
                            ?>
                            <div class="card mb-4 stock-card-screen-only">
                                <div class="card-header bg-white">
                                    <h6 class="mb-0">Selected Lot</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="small text-muted">Stock Card No.</div>
                                            <div class="fw-semibold"><?php echo h($selectedLot['system_reference'] ?? ''); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Purchase Order</div>
                                            <div class="fw-semibold"><?php echo h($selectedLot['po_number'] ?? ''); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Description</div>
                                            <div><?php echo h($selectedLot['item_description'] ?? ''); ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="small text-muted">Classification</div>
                                            <div><?php echo h(trim(implode(' / ', array_filter([
                                                trim((string) ($selectedLot['classification_family'] ?? '')),
                                                trim((string) ($selectedLot['classification_name'] ?? '')),
                                            ])))); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="small text-muted">Unit of Measure</div>
                                            <div><?php echo h($unitLabel); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="small text-muted">Account Code</div>
                                            <div><?php echo h(trim(implode(' - ', array_filter([
                                                $selectedLot['account_code'] ?? '',
                                                $selectedLot['account_name'] ?? '',
                                            ])))); ?></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="small text-muted">Supplier</div>
                                            <div><?php echo h($selectedLot['supplier_name'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card stock-card-screen-only">
                                <div class="card-body text-center text-muted py-5">
                                    Use <strong>Print Selected Card</strong> to open the Appendix 58 stock card layout for this lot.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card h-100">
                                <div class="card-body d-flex align-items-center justify-content-center text-center text-muted py-5">
                                    Select a supply lot to view its stock card and ledger.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if ($selectedLot): ?>
    <style>
        .stock-card-print-only,
        .stock-card-print-only * {
            font-family: "Times New Roman", serif !important;
            color: #000 !important;
        }

        .stock-card-print-only .stock-card-print-shell {
            width: 7.55in;
            min-height: 12in;
            max-width: 7.55in;
            margin: 0 auto;
            background: #fff;
            padding-top: 0 !important;
        }

        .stock-card-print-only .stock-card-form-meta {
            display: flex !important;
            justify-content: space-between !important;
            align-items: flex-start;
            font-size: 12px !important;
            font-style: italic !important;
            margin: 0 0 4px 0 !important;
        }

        .stock-card-print-only .stock-card-form-title {
            margin: 0 0 10px 0 !important;
            text-align: center !important;
            font-size: 18px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 0 !important;
        }

        .stock-card-print-only .stock-card-head-table,
        .stock-card-print-only .stock-card-ledger-table {
            width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 !important;
            border: 1px solid #000 !important;
            background: #fff !important;
        }

        .stock-card-print-only .stock-card-head-table {
            margin-bottom: 0 !important;
        }

        .stock-card-print-only .stock-card-head-table td,
        .stock-card-print-only .stock-card-ledger-table th,
        .stock-card-print-only .stock-card-ledger-table td {
            border: 1px solid #000 !important;
            background: #fff !important;
            box-shadow: none !important;
        }

        .stock-card-print-only .stock-card-head-table td {
            font-size: 11px !important;
            line-height: 1.05 !important;
            padding: 2px 4px !important;
            vertical-align: top !important;
        }

        .stock-card-print-only .stock-card-label {
            width: 16% !important;
            font-weight: 700 !important;
            white-space: nowrap !important;
        }

        .stock-card-print-only .stock-card-fill {
            display: inline-block !important;
            width: 100% !important;
            min-height: 12px !important;
            border-bottom: 1px solid #000 !important;
            padding: 0 2px !important;
        }

        .stock-card-print-only .stock-card-ledger-table th,
        .stock-card-print-only .stock-card-ledger-table td {
            font-size: 10px !important;
            line-height: 1.04 !important;
            padding: 2px 3px !important;
            vertical-align: top !important;
        }

        .stock-card-print-only .stock-card-ledger-table thead th {
            text-align: center !important;
            font-weight: 700 !important;
            text-transform: none !important;
        }

        .stock-card-print-only .stock-card-group-title {
            font-style: italic !important;
            font-weight: 700 !important;
        }

        .stock-card-print-only .stock-card-ledger-table tbody tr {
            height: 18px !important;
        }

        .stock-card-print-only .stock-card-blank-row td {
            color: transparent !important;
        }

        @media print {
            @page {
                size: 8.5in 13in;
                margin: 0.5in;
            }

            body {
                margin: 0 !important;
                padding: 0 !important;
            }

            .stock-card-print-shell {
                width: 7.55in !important;
                min-height: 12in !important;
                max-width: 7.55in !important;
            }

            .stock-card-print-only {
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
            }
        }
    
            <?php echo print_page_number_css(); ?></style>
    <section class="stock-card-print-only">
        <div class="stock-card-print-shell">
            <div class="stock-card-form-meta">
                <span></span>
                <span>Appendix 58</span>
            </div>
            <div class="stock-card-form-title">STOCK CARD</div>

            <table class="stock-card-head-table">
                <tbody>
                    <tr>
                        <td class="stock-card-label">Entity Name:</td>
                        <td><span class="stock-card-fill"><?php echo h(APP_NAME); ?></span></td>
                        <td class="stock-card-label">Fund Cluster:</td>
                        <td><span class="stock-card-fill"><?php echo h($stockCardFundCluster); ?></span></td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Item:</td>
                        <td><span class="stock-card-fill"><?php echo h($stockCardItemLabel); ?></span></td>
                        <td class="stock-card-label">Stock No.:</td>
                        <td><span class="stock-card-fill"><?php echo h($selectedLot['system_reference'] ?? ''); ?></span></td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Description:</td>
                        <td><span class="stock-card-fill"><?php echo h($selectedLot['item_description'] ?? ''); ?></span></td>
                        <td class="stock-card-label">Re-order Point:</td>
                        <td><span class="stock-card-fill">&nbsp;</span></td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Unit of Measurement:</td>
                        <td colspan="3"><span class="stock-card-fill"><?php echo h($unitLabel); ?></span></td>
                    </tr>
                </tbody>
            </table>

            <table class="stock-card-ledger-table" data-no-table-search>
                    <thead>
                        <tr>
                            <th rowspan="2" style="width:11%;">Date</th>
                            <th rowspan="2" style="width:14%;">Reference</th>
                            <th colspan="1" style="width:11%;"><span class="stock-card-group-title">Receipt</span></th>
                            <th colspan="2" style="width:34%;"><span class="stock-card-group-title">Issue</span></th>
                            <th colspan="1" style="width:15%;"><span class="stock-card-group-title">Balance</span></th>
                            <th rowspan="2" style="width:15%;">No. of Days to<br>Consume</th>
                        </tr>
                        <tr>
                            <th>Qty.</th>
                            <th>Qty.</th>
                            <th>Office</th>
                            <th>Qty.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerRows as $row): ?>
                            <tr>
                                <td><?php echo h(!empty($row['date']) ? date('m/d/Y', strtotime((string) $row['date'])) : ''); ?></td>
                                <td><?php echo h($row['reference'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(format_quantity($row['receipt_qty'] ?? 0)); ?></td>
                                <td class="text-end"><?php echo h(format_quantity($row['issue_qty'] ?? 0)); ?></td>
                                <td><?php echo h($row['office'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(format_quantity($row['balance_qty'] ?? 0)); ?></td>
                                <td><?php echo h($row['days_to_consume'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php for ($i = 0; $i < $blankLedgerRowCount; $i++): ?>
                            <tr class="stock-card-blank-row">
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                                <td>&nbsp;</td>
                            </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
        </div>
    </section>
<?php endif; ?>

<?php render_print_page_number(); ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
