<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

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
$stockCardTargetRows = 18;

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
        'reference' => trim(implode(' / ', array_filter([
            $selectedLot['iar_reference'] ?? '',
            $selectedLot['ris_no'] ?? '',
        ]))),
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
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM issuance_items ii
        INNER JOIN issuances iss ON iss.id = ii.issuance_id AND iss.status = 'posted'
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
                            <div class="fw-semibold"><?php echo h(number_format((float) $summary['total_received'], 2)); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Issued</div>
                            <div class="fw-semibold"><?php echo h(number_format((float) $summary['total_issued'], 2)); ?></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total On Hand</div>
                            <div class="fw-semibold"><?php echo h(number_format((float) $summary['total_on_hand'], 2)); ?></div>
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
                                                        <div class="fw-semibold"><?php echo h(number_format((float) ($lot['quantity_on_hand'] ?? 0), 2)); ?></div>
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
    <section class="stock-card-print-only">
        <div class="stock-card-print-shell">
            <div class="stock-card-form-meta">
                <span>&nbsp;</span>
                <span>Appendix 58</span>
            </div>
            <div class="text-center stock-card-header-block">
                <img src="<?php echo h(LOGO_PATH); ?>" style="width:60px;height:60px;object-fit:contain;" alt="logo">
                <h5 class="mt-2 mb-1">University of Antique</h5>
                <div class="stock-card-form-title">Stock Card</div>
            </div>

            <table class="table table-bordered stock-card-head-table mb-3">
                <tbody>
                    <tr>
                        <td class="stock-card-label">Entity Name</td>
                        <td>University of Antique</td>
                        <td class="stock-card-label">Fund Cluster</td>
                        <td><?php echo h($selectedLot['fund_code'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Item</td>
                        <td><?php echo h($stockCardItemLabel); ?></td>
                        <td class="stock-card-label">Stock No.</td>
                        <td><?php echo h($selectedLot['system_reference'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Description</td>
                        <td><?php echo h($selectedLot['item_description'] ?? ''); ?></td>
                        <td class="stock-card-label">Re-order Point</td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="stock-card-label">Unit of Measurement</td>
                        <td colspan="3"><?php echo h($unitLabel); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle mb-0 stock-card-ledger-table" data-no-table-search>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Reference</th>
                            <th class="text-end">Receipt Qty</th>
                            <th class="text-end">Issue Qty</th>
                            <th>Office</th>
                            <th class="text-end">Balance Qty</th>
                            <th>No. of Days to Consume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerRows as $row): ?>
                            <tr>
                                <td><?php echo h(!empty($row['date']) ? date('M d, Y', strtotime((string) $row['date'])) : ''); ?></td>
                                <td><?php echo h($row['reference'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['receipt_qty'] ?? 0), 2)); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['issue_qty'] ?? 0), 2)); ?></td>
                                <td><?php echo h($row['office'] ?? ''); ?></td>
                                <td class="text-end"><?php echo h(number_format((float) ($row['balance_qty'] ?? 0), 2)); ?></td>
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
        </div>
    </section>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
