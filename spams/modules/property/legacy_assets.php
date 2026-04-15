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
    ensure_legacy_assets_rpcppe_tracking_columns($db);

    $db->query("UPDATE legacy_assets SET item_type = 'equipment' WHERE item_type IS NULL OR item_type = ''");
    $db->query("UPDATE legacy_assets SET quantity = 1 WHERE quantity IS NULL OR quantity <= 0");
    $db->query("UPDATE legacy_assets SET unit_cost = acquisition_cost WHERE unit_cost IS NULL OR unit_cost = 0");

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_rpcppe_tracking') {
        if (!csrf_verify()) {
            set_flash('error', 'Invalid CSRF token.');
            redirect('modules/property/legacy_assets.php');
        }

        $assetId = (int) ($_POST['asset_id'] ?? 0);
        $isCandidate = isset($_POST['is_rpcppe_candidate']) && (string) $_POST['is_rpcppe_candidate'] === '1';
        $requestedStatus = trim((string) ($_POST['rpcppe_status'] ?? ''));
        $normalizedStatus = rpcppe_normalize_status($requestedStatus, $isCandidate);
        $candidateValue = $normalizedStatus === 'excluded' ? 0 : 1;

        $stmt = $db->prepare("UPDATE legacy_assets
            SET is_rpcppe_candidate = ?,
                rpcppe_status = ?,
                rpcppe_submitted_at = CASE
                    WHEN ? IN ('submitted_to_accounting', 'reconciled') THEN COALESCE(rpcppe_submitted_at, NOW())
                    ELSE NULL
                END,
                rpcppe_reconciled_at = CASE
                    WHEN ? = 'reconciled' THEN COALESCE(rpcppe_reconciled_at, NOW())
                    ELSE NULL
                END
            WHERE id = ? AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param('isssi', $candidateValue, $normalizedStatus, $normalizedStatus, $normalizedStatus, $assetId);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'RPCPPE tracking updated.');
            redirect('modules/property/legacy_assets.php');
        }
    }

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
            'Office', 'Employee', 'Responsibility Code', 'Condition Status', 'RPCPPE Candidate', 'RPCPPE Status', 'Remarks', 'Created At',
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
                (int) ($row['is_rpcppe_candidate'] ?? 0) === 1 ? 'Yes' : 'No',
                rpcppe_status_label((string) ($row['rpcppe_status'] ?? 'excluded')),
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
                    <div class="alert <?php echo (($flash['type'] ?? 'success') === 'error') ? 'alert-danger' : 'alert-success'; ?>"><?php echo h($flash['message']); ?></div>
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
                                    <th>RPCPPE</th>
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
                                        <td>
                                            <div class="d-grid gap-2" style="min-width: 210px;">
                                                <a href="<?php echo h(base_url('modules/property/view.php?source=legacy&id=' . (int) ($row['id'] ?? 0))); ?>" class="btn btn-sm btn-outline-secondary">View Details</a>
                                                <form method="post" class="d-grid gap-2">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="update_rpcppe_tracking">
                                                    <input type="hidden" name="asset_id" value="<?php echo (int) ($row['id'] ?? 0); ?>">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="is_rpcppe_candidate" value="1" id="rpcppe_candidate_<?php echo (int) ($row['id'] ?? 0); ?>" <?php echo ((int) ($row['is_rpcppe_candidate'] ?? 0) === 1) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="rpcppe_candidate_<?php echo (int) ($row['id'] ?? 0); ?>">
                                                        Include in RPCPPE
                                                    </label>
                                                </div>
                                                <select name="rpcppe_status" class="form-select form-select-sm" data-no-select2>
                                                    <?php foreach (rpcppe_status_options() as $statusValue => $statusLabel): ?>
                                                        <option value="<?php echo h($statusValue); ?>" <?php echo ((string) ($row['rpcppe_status'] ?? 'excluded') === $statusValue) ? 'selected' : ''; ?>>
                                                            <?php echo h($statusLabel); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <span class="badge <?php echo h(rpcppe_status_badge_class((string) ($row['rpcppe_status'] ?? 'excluded'))); ?>">
                                                        <?php echo h(rpcppe_status_label((string) ($row['rpcppe_status'] ?? 'excluded'))); ?>
                                                    </span>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Save</button>
                                                </div>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="15" class="text-center text-muted py-5">
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
