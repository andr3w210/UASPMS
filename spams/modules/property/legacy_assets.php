<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$flash = get_flash();
$rows = [];
$encodedSummary = [
    'count'    => 0,
    'quantity' => 0,
    'cost'     => 0.0,
];

if ($db) {
    ensure_legacy_assets_fund_column($db);

    $db->query("UPDATE legacy_assets SET item_type = 'equipment' WHERE item_type IS NULL OR item_type = ''");
    $db->query("UPDATE legacy_assets SET quantity = 1 WHERE quantity IS NULL OR quantity <= 0");
    $db->query("UPDATE legacy_assets SET unit_cost = acquisition_cost WHERE unit_cost IS NULL OR unit_cost = 0");

    $listSql = "
        SELECT la.*, c.classification_name, c.classification_family, ac.account_code, ac.account_name,
               f.fund_code, f.fund_name, f.fund_source, o.office_name,
               s.supplier_name, b.brand_name, m.model_name,
               e.first_name, e.middle_name, e.last_name, e.suffix_name, rc.code AS rc_code
        FROM legacy_assets la
        LEFT JOIN classifications c ON c.id = la.classification_id
        LEFT JOIN account_codes ac ON ac.id = la.account_code_id
        LEFT JOIN funds f ON f.id = la.fund_id
        LEFT JOIN suppliers s ON s.id = la.supplier_id
        LEFT JOIN brands b ON b.id = la.brand_id
        LEFT JOIN models m ON m.id = la.model_id
        LEFT JOIN offices o ON o.id = la.office_id
        LEFT JOIN employees e ON e.id = la.employee_id
        LEFT JOIN responsibility_codes rc ON rc.id = la.responsibility_code_id
        WHERE la.is_active = 1
        ORDER BY la.created_at DESC, la.id DESC
    ";
    $listStmt = $db->prepare($listSql);
    if ($listStmt) {
        $listStmt->execute();
        $rows = $listStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $listStmt->close();
        foreach ($rows as $summaryRow) {
            $encodedSummary['count']++;
            $encodedSummary['quantity'] += (int) ($summaryRow['quantity'] ?? 0);
            $encodedSummary['cost']     += (float) ($summaryRow['acquisition_cost'] ?? 0);
        }
    }

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $filename = 'legacy_assets_export_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'ID', 'System Reference', 'Property Number', 'PO Number', 'Item Type', 'Description',
            'Classification', 'Classification Family', 'Account Code', 'Account Name',
            'Fund', 'Supplier', 'Brand', 'Model', 'Serial No', 'Acquisition Date',
            'Quantity', 'Unit Cost', 'Acquisition Cost',
            'Office', 'Employee', 'Responsibility Code', 'Condition Status', 'Remarks', 'Created At',
        ]);
        foreach ($rows as $row) {
            $employeeName = trim(implode(' ', array_filter([
                trim((string) ($row['first_name'] ?? '')),
                trim((string) ($row['middle_name'] ?? '')),
                trim((string) ($row['last_name'] ?? '')),
                trim((string) ($row['suffix_name'] ?? '')),
            ])));
            fputcsv($output, [
                $row['id'] ?? '',
                $row['system_reference'] ?? '',
                $row['property_number'] ?? '',
                $row['po_number'] ?? '',
                $row['item_type'] ?? '',
                preg_replace('/\s+/', ' ', (string) ($row['item_description'] ?? '')),
                $row['classification_name'] ?? '',
                $row['classification_family'] ?? '',
                $row['account_code'] ?? '',
                $row['account_name'] ?? '',
                trim(implode(' - ', array_filter([
                    trim((string) ($row['fund_code'] ?? '')),
                    trim((string) ($row['fund_name'] ?? '')),
                ]))),
                $row['supplier_name'] ?? '',
                $row['brand_name'] ?? ($row['brand'] ?? ''),
                $row['model_name'] ?? ($row['model'] ?? ''),
                $row['serial_no'] ?? '',
                $row['acquisition_date'] ?? '',
                $row['quantity'] ?? '',
                $row['unit_cost'] ?? '',
                $row['acquisition_cost'] ?? '',
                $row['office_name'] ?? '',
                $employeeName,
                $row['rc_code'] ?? '',
                $row['condition_status'] ?? '',
                $row['remarks'] ?? '',
                $row['created_at'] ?? '',
            ]);
        }
        fclose($output);
        exit;
    }
}

$page_title = 'Beginning Balance Encoding';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
<div class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header border-0 pb-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="text-uppercase small text-muted fw-semibold">Legacy Workspace</div>
                        <h4 class="mb-1">Beginning Balance Encoding</h4>
                        <div class="small text-muted">Encoded assets already owned by the university prior to system adoption.</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="<?php echo h(base_url('modules/property/legacy_assets.php?export=csv')); ?>" class="btn btn-outline-success">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                        <a href="<?php echo h(base_url('modules/property/encode_legacy_asset.php')); ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>Encode New Asset
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if ($flash): ?>
                    <div class="alert alert-success"><?php echo h($flash['message']); ?></div>
                <?php endif; ?>

                <div class="row g-3 mb-4">
                    <div class="col-sm-4">
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Encoded Records</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $encodedSummary['count']); ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Total Quantity</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $encodedSummary['quantity']); ?></div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Total Acquisition Cost</div>
                            <div class="workspace-summary-value"><?php echo number_format((float) $encodedSummary['cost'], 2); ?></div>
                        </div>
                    </div>
                </div>

                <div class="master-data-toolbar mb-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-8">
                            <label class="form-label">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                <input type="search" id="legacyTableSearch" class="form-control" placeholder="Search property no., description, office, accountable, supplier, brand, model">
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-4">
                            <label class="form-label">Rows Per Page</label>
                            <select id="legacyPerPageSelect" class="form-select" data-no-select2>
                                <option value="25" selected>25 rows</option>
                                <option value="50">50 rows</option>
                                <option value="100">100 rows</option>
                                <option value="250">250 rows</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="master-data-table-shell">
                    <div class="table-responsive mobile-table-frame master-data-table-scroll">
                        <table class="table table-sm align-middle" id="legacyAssetsTable" data-no-table-search>
                            <thead>
                                <tr>
                                    <th>Property No.</th>
                                    <th>PO No.</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th>Fund</th>
                                    <th>Supplier</th>
                                    <th>Brand / Model</th>
                                    <th>Office</th>
                                    <th>Accountable</th>
                                    <th>Acquired</th>
                                    <th>Qty</th>
                                    <th>Unit Cost</th>
                                    <th>Cost</th>
                                    <th>Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo h($row['property_number']); ?></td>
                                        <td><?php echo h($row['po_number'] ?? ''); ?></td>
                                        <td><?php echo h($row['item_description']); ?></td>
                                        <td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['item_type'] ?? '')))); ?></td>
                                        <td><?php echo h(trim(implode(' - ', array_filter([$row['fund_code'] ?? '', $row['fund_name'] ?? ''])))); ?></td>
                                        <td><?php echo h($row['supplier_name'] ?? ''); ?></td>
                                        <td><?php echo h(trim((($row['brand_name'] ?? '') ?: ($row['brand'] ?? '')) . ' ' . (($row['model_name'] ?? '') ?: ($row['model'] ?? '')))); ?></td>
                                        <td><?php echo h($row['office_name'] ?? ''); ?></td>
                                        <td><?php echo h(employee_display_name($row)); ?></td>
                                        <td><?php echo h(!empty($row['acquisition_date']) ? date('M d, Y', strtotime($row['acquisition_date'])) : ''); ?></td>
                                        <td><?php echo h(number_format((float) ($row['quantity'] ?? 0), 0)); ?></td>
                                        <td><?php echo h(number_format((float) ($row['unit_cost'] ?? 0), 2)); ?></td>
                                        <td><?php echo h(number_format((float) ($row['acquisition_cost'] ?? 0), 2)); ?></td>
                                        <td><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['condition_status'] ?? '')))); ?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="14" class="text-center text-muted py-5">
                                            <i class="bi bi-inbox fs-3 d-block mb-2 text-muted opacity-50"></i>
                                            No encoded legacy assets yet.
                                            <a href="<?php echo h(base_url('modules/property/encode_legacy_asset.php')); ?>" class="d-block mt-1">Encode the first asset</a>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="master-data-pagination">
                        <div id="legacyRecordCountMobile" class="master-data-pagination-meta">Search updates the table instantly.</div>
                        <div class="master-data-pagination-controls">
                            <button class="btn btn-sm btn-outline-secondary" id="legacyPrevPage" type="button">Previous</button>
                            <span id="legacyPageInfo" class="small text-muted">Page 1 of 1</span>
                            <button class="btn btn-sm btn-outline-secondary" id="legacyNextPage" type="button">Next</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var recordCountEl = document.getElementById('legacyRecordCountMobile');
    var options = {
        searchInputId: 'legacyTableSearch',
        statusFilterId: null,
        prevButtonId: 'legacyPrevPage',
        nextButtonId: 'legacyNextPage',
        pageInfoId: 'legacyPageInfo',
        perPageSelectId: 'legacyPerPageSelect',
        recordCountId: null,
        recordCountFormatter: function (state) {
            var text = 'Showing ' + state.totalVisible + ' of ' + state.totalOverall + ' records';
            if (recordCountEl) { recordCountEl.textContent = text; }
            return text;
        },
        pageInfoFormatter: function (state) {
            return 'Page ' + state.currentPage + ' of ' + state.totalPages + ' (' + state.totalVisible + ' matches)';
        }
    };
    if (typeof window.initDataTable === 'function') {
        window.initDataTable('legacyAssetsTable', options);
        return;
    }
    window.__spamsPendingInitDataTables = window.__spamsPendingInitDataTables || [];
    window.__spamsPendingInitDataTables.push(['legacyAssetsTable', options]);
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
