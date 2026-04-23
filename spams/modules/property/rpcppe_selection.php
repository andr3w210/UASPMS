<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer');

$db = db();
$flash = get_flash();
$errors = [];
$rows = [];
$offices = [];
$summary = [
    'total' => 0,
    'included_draft' => 0,
    'submitted_to_accounting' => 0,
    'reconciled' => 0,
    'excluded' => 0,
];

$search = trim((string) ($_GET['q'] ?? ''));
$sourceFilter = trim((string) ($_GET['source'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$officeId = (int) ($_GET['office_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
$allowedPerPage = [25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
}
if (!in_array($sourceFilter, ['', 'system', 'legacy'], true)) {
    $sourceFilter = '';
}
$allowedStatusFilters = array_merge(['', 'candidate_only'], array_keys(rpcppe_status_options()));
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = '';
}

function rpcppe_selection_employee_label(array $row): string
{
    return trim((string) employee_display_name([
        'first_name' => $row['first_name'] ?? '',
        'middle_name' => $row['middle_name'] ?? '',
        'last_name' => $row['last_name'] ?? '',
        'suffix_name' => $row['suffix_name'] ?? '',
    ]));
}

function rpcppe_selection_source_label(string $sourceType): string
{
    return $sourceType === 'legacy' ? 'Beginning Balance' : 'System Asset';
}

function rpcppe_selection_build_url(array $overrides = []): string
{
    $params = [
        'q' => $_GET['q'] ?? '',
        'source' => $_GET['source'] ?? '',
        'status' => $_GET['status'] ?? '',
        'office_id' => $_GET['office_id'] ?? '',
        'per_page' => $_GET['per_page'] ?? 25,
        'page' => $_GET['page'] ?? 1,
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

if ($db) {
    ensure_legacy_assets_rpcppe_tracking_columns($db);
    ensure_distribution_item_rpcppe_tracking_columns($db);

    $officeResult = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($officeResult instanceof mysqli_result) {
        $offices = $officeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'bulk_update') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $selectedKeys = array_values(array_filter(array_map(static function ($value): string {
                return trim((string) $value);
            }, (array) ($_POST['asset_keys'] ?? []))));
            $requestedStatus = trim((string) ($_POST['rpcppe_status'] ?? ''));
            if (!$selectedKeys) {
                $errors[] = 'Select at least one asset.';
            }
            if (!array_key_exists($requestedStatus, rpcppe_status_options())) {
                $errors[] = 'Select a valid RPCPPE status.';
            }

            if (!$errors) {
                $candidateValue = $requestedStatus === 'excluded' ? 0 : 1;
                $db->begin_transaction();
                try {
                    $legacyStmt = $db->prepare("UPDATE legacy_assets
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
                        WHERE id = ?");
                    $systemStmt = $db->prepare("UPDATE distribution_item_details
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
                        WHERE id = ?");

                    foreach ($selectedKeys as $assetKey) {
                        [$sourceType, $idPart] = array_pad(explode(':', $assetKey, 2), 2, '');
                        $recordId = (int) $idPart;
                        if ($recordId <= 0) {
                            continue;
                        }

                        if ($sourceType === 'legacy' && $legacyStmt) {
                            $legacyStmt->bind_param('isssi', $candidateValue, $requestedStatus, $requestedStatus, $requestedStatus, $recordId);
                            $legacyStmt->execute();
                        } elseif ($sourceType === 'system' && $systemStmt) {
                            $systemStmt->bind_param('isssi', $candidateValue, $requestedStatus, $requestedStatus, $requestedStatus, $recordId);
                            $systemStmt->execute();
                        }
                    }

                    if ($legacyStmt) {
                        $legacyStmt->close();
                    }
                    if ($systemStmt) {
                        $systemStmt->close();
                    }

                    $db->commit();
                    set_flash('success', 'RPCPPE tracking updated for the selected assets.');
                    redirect('modules/property/rpcppe_selection.php' . rpcppe_selection_build_url());
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = 'Unable to update RPCPPE tracking.';
                }
            }
        }
    }

    $queries = [];
    $params = [];
    $types = '';

    if ($sourceFilter !== 'legacy') {
        $systemSql = "SELECT
                CONCAT('system:', did.id) AS asset_key,
                did.id AS record_id,
                'system' AS source_type,
                did.property_number,
                poi.item_description,
                c.classification_name,
                c.classification_family,
                did.brand,
                did.model,
                did.serial_no,
                ri.unit_cost AS amount,
                r.received_date AS acquisition_date,
                COALESCE(curr_o.office_name, o.office_name) AS office_name,
                COALESCE(curr_e.first_name, e.first_name) AS first_name,
                COALESCE(curr_e.middle_name, e.middle_name) AS middle_name,
                COALESCE(curr_e.last_name, e.last_name) AS last_name,
                COALESCE(curr_e.suffix_name, e.suffix_name) AS suffix_name,
                did.is_rpcppe_candidate,
                did.rpcppe_status
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted' AND d.document_type = 'par'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = 'equipment'
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
            LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
            WHERE did.is_distributed = 1
              AND (did.is_disposed IS NULL OR did.is_disposed = 0)";

        if ($officeId > 0) {
            $systemSql .= " AND COALESCE(did.current_office_id, d.office_id) = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($search !== '') {
            $systemSql .= " AND (
                did.property_number LIKE ?
                OR poi.item_description LIKE ?
                OR c.classification_name LIKE ?
                OR c.classification_family LIKE ?
                OR COALESCE(curr_o.office_name, o.office_name) LIKE ?
                OR COALESCE(curr_e.first_name, e.first_name) LIKE ?
                OR COALESCE(curr_e.last_name, e.last_name) LIKE ?
                OR did.brand LIKE ?
                OR did.model LIKE ?
                OR did.serial_no LIKE ?
            )";
            $searchLike = '%' . $search . '%';
            $types .= 'ssssssssss';
            for ($i = 0; $i < 10; $i++) {
                $params[] = $searchLike;
            }
        }
        if ($statusFilter !== '') {
            if ($statusFilter === 'candidate_only') {
                $systemSql .= " AND COALESCE(did.is_rpcppe_candidate, 0) = 1";
            } else {
                $systemSql .= " AND COALESCE(did.rpcppe_status, 'excluded') = ?";
                $types .= 's';
                $params[] = $statusFilter;
            }
        }

        $queries[] = $systemSql;
    }

    if ($sourceFilter !== 'system') {
        $legacySql = "SELECT
                CONCAT('legacy:', la.id) AS asset_key,
                la.id AS record_id,
                'legacy' AS source_type,
                la.property_number,
                la.item_description,
                c.classification_name,
                c.classification_family,
                la.brand,
                la.model,
                la.serial_no,
                la.acquisition_cost AS amount,
                la.acquisition_date,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                la.is_rpcppe_candidate,
                la.rpcppe_status
            FROM legacy_assets la
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e ON e.id = la.employee_id
            WHERE la.is_active = 1
              AND la.item_type = 'equipment'
              AND la.item_description NOT LIKE 'RPCPPE Reconciliation Adjustment %'
              AND la.item_description NOT LIKE 'RPCPPE 2025 Reconciliation Adjustment %'";

        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($search !== '') {
            $legacySql .= " AND (
                la.property_number LIKE ?
                OR la.item_description LIKE ?
                OR c.classification_name LIKE ?
                OR c.classification_family LIKE ?
                OR o.office_name LIKE ?
                OR e.first_name LIKE ?
                OR e.last_name LIKE ?
                OR la.brand LIKE ?
                OR la.model LIKE ?
                OR la.serial_no LIKE ?
            )";
            $searchLike = '%' . $search . '%';
            $types .= 'ssssssssss';
            for ($i = 0; $i < 10; $i++) {
                $params[] = $searchLike;
            }
        }
        if ($statusFilter !== '') {
            if ($statusFilter === 'candidate_only') {
                $legacySql .= " AND COALESCE(la.is_rpcppe_candidate, 0) = 1";
            } else {
                $legacySql .= " AND COALESCE(la.rpcppe_status, 'excluded') = ?";
                $types .= 's';
                $params[] = $statusFilter;
            }
        }

        $queries[] = $legacySql;
    }

    if ($queries) {
        $unionSql = implode(" UNION ALL ", $queries);
        $countSql = "SELECT COUNT(*) AS total FROM (" . $unionSql . ") rpcppe_rows";
        $dataSql = "SELECT * FROM (" . $unionSql . ") rpcppe_rows ORDER BY rpcppe_status ASC, office_name ASC, property_number ASC";
        $summarySql = "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN rpcppe_status = 'included_draft' THEN 1 ELSE 0 END) AS included_draft_count,
                SUM(CASE WHEN rpcppe_status = 'submitted_to_accounting' THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN rpcppe_status = 'reconciled' THEN 1 ELSE 0 END) AS reconciled_count,
                SUM(CASE WHEN COALESCE(rpcppe_status, 'excluded') = 'excluded' THEN 1 ELSE 0 END) AS excluded_count
            FROM (" . $unionSql . ") rpcppe_rows";

        $pageData = paginate($db, $countSql, $dataSql, $params, $types, $page, $perPage);
        $rows = $pageData['data'];
        $page = $pageData['page'];
        $total = $pageData['total'];
        $totalPages = $pageData['total_pages'];

        $summaryStmt = $db->prepare($summarySql);
        if ($summaryStmt) {
            if ($types !== '') {
                $refs = [$types];
                foreach ($params as $key => $value) {
                    $refs[] = &$params[$key];
                }
                call_user_func_array([$summaryStmt, 'bind_param'], $refs);
            }
            $summaryStmt->execute();
            $summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
            $summaryStmt->close();
            $summary = [
                'total' => (int) ($summaryRow['total_count'] ?? 0),
                'included_draft' => (int) ($summaryRow['included_draft_count'] ?? 0),
                'submitted_to_accounting' => (int) ($summaryRow['submitted_count'] ?? 0),
                'reconciled' => (int) ($summaryRow['reconciled_count'] ?? 0),
                'excluded' => (int) ($summaryRow['excluded_count'] ?? 0),
            ];
        }
    } else {
        $total = 0;
        $totalPages = 1;
    }
} else {
    $errors[] = 'Unable to connect to the database.';
    $total = 0;
    $totalPages = 1;
}

$page_title = 'RPCPPE Inclusion';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';

$rangeStart = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
$rangeEnd = $total > 0 ? min($total, $rangeStart + count($rows) - 1) : 0;
?>
<section class="page-section">
    <div class="row g-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">RPCPPE Workspace</div>
                            <h4 class="mb-1">RPCPPE Inclusion</h4>
                            <div class="text-muted">Manage inclusion, exclusion, and submission status for equipment assets from both system and beginning balance records.</div>
                        </div>
                        <a href="<?php echo h(base_url('modules/reports/rpcppe_batches.php')); ?>" class="btn btn-outline-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Open RPCPPE Batches
                        </a>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo (($flash['type'] ?? 'success') === 'error') ? 'alert-danger' : 'alert-success'; ?>"><?php echo h($flash['message']); ?></div>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                    <?php endif; ?>

                    <div class="report-summary-grid mb-4">
                        <div class="report-summary-card"><div class="report-summary-label">Total Assets</div><div class="report-summary-value"><?php echo number_format($summary['total']); ?></div><div class="report-summary-note">Equipment assets currently loaded into the RPCPPE management workspace.</div></div>
                        <div class="report-summary-card"><div class="report-summary-label">Included Draft</div><div class="report-summary-value"><?php echo number_format($summary['included_draft']); ?></div><div class="report-summary-note">Assets marked for inclusion but not yet submitted.</div></div>
                        <div class="report-summary-card"><div class="report-summary-label">Submitted</div><div class="report-summary-value"><?php echo number_format($summary['submitted_to_accounting']); ?></div><div class="report-summary-note">Assets already marked as submitted to accounting.</div></div>
                        <div class="report-summary-card"><div class="report-summary-label">Reconciled</div><div class="report-summary-value"><?php echo number_format($summary['reconciled']); ?></div><div class="report-summary-note">Assets fully reconciled in the current tracking state.</div></div>
                    </div>

                    <form method="get" class="report-filter-card mb-3">
                        <div class="row g-3 align-items-end">
                            <div class="col-lg-5">
                                <label class="form-label">Search</label>
                                <input type="search" name="q" class="form-control" value="<?php echo h($search); ?>" placeholder="Property no., description, classification, office, accountable, brand, model, serial">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Source</label>
                                <select name="source" class="form-select">
                                    <option value="">All Sources</option>
                                    <option value="system" <?php echo $sourceFilter === 'system' ? 'selected' : ''; ?>>System Assets</option>
                                    <option value="legacy" <?php echo $sourceFilter === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">RPCPPE Status</label>
                                <select name="status" class="form-select">
                                    <option value="">All Statuses</option>
                                    <option value="candidate_only" <?php echo $statusFilter === 'candidate_only' ? 'selected' : ''; ?>>Candidate Only</option>
                                    <?php foreach (rpcppe_status_options() as $statusValue => $statusLabel): ?>
                                        <option value="<?php echo h($statusValue); ?>" <?php echo $statusFilter === $statusValue ? 'selected' : ''; ?>><?php echo h($statusLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Office</label>
                                <select name="office_id" class="form-select">
                                    <option value="0">All Offices</option>
                                    <?php foreach ($offices as $office): ?>
                                        <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>><?php echo h($office['office_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">Rows</label>
                                <select name="per_page" class="form-select">
                                    <?php foreach ($allowedPerPage as $option): ?>
                                        <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Load Assets</button>
                                <a href="<?php echo h(base_url('modules/property/rpcppe_selection.php')); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </div>
                    </form>

                    <form method="post" class="report-filter-card mb-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="bulk_update">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Bulk RPCPPE Status</label>
                                <select name="rpcppe_status" class="form-select" required>
                                    <option value="">Select status</option>
                                    <?php foreach (rpcppe_status_options() as $statusValue => $statusLabel): ?>
                                        <option value="<?php echo h($statusValue); ?>"><?php echo h($statusLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8 d-flex gap-2">
                                <button type="submit" class="btn btn-primary">Apply to Selected Assets</button>
                                <div class="small text-muted align-self-center">Use the checkboxes below to update multiple system and beginning balance assets at once.</div>
                            </div>

                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                    <div class="small text-muted">
                                        <?php if ($total > 0): ?>
                                            Showing <?php echo number_format($rangeStart); ?>-<?php echo number_format($rangeEnd); ?> of <?php echo number_format($total); ?> assets
                                        <?php else: ?>
                                            No assets matched the current filters
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">Only equipment assets are shown because this workspace is for RPCPPE.</div>
                                </div>

                                <div class="report-table-card table-responsive mobile-table-frame">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th style="width: 48px;"><input type="checkbox" id="rpcppeSelectAll"></th>
                                                <th>Asset</th>
                                                <th>Details</th>
                                                <th>Assignment</th>
                                                <th>Source</th>
                                                <th>RPCPPE</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($rows): ?>
                                                <?php foreach ($rows as $row): ?>
                                                    <?php
                                                    $assetKey = (string) ($row['asset_key'] ?? '');
                                                    $detailUrl = base_url('modules/property/view.php?source=' . urlencode((string) ($row['source_type'] ?? 'system')) . '&id=' . (int) ($row['record_id'] ?? 0));
                                                    $classificationLabel = trim(implode(' / ', array_filter([
                                                        trim((string) ($row['classification_family'] ?? '')),
                                                        trim((string) ($row['classification_name'] ?? '')),
                                                    ])));
                                                    $accountable = rpcppe_selection_employee_label($row);
                                                    ?>
                                                    <tr>
                                                        <td><input type="checkbox" class="rpcppe-row-checkbox" name="asset_keys[]" value="<?php echo h($assetKey); ?>"></td>
                                                        <td>
                                                            <div class="fw-semibold"><?php echo h($row['property_number'] ?? ''); ?></div>
                                                            <div><?php echo h($row['item_description'] ?? ''); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?php echo h($classificationLabel !== '' ? $classificationLabel : 'Unclassified'); ?></div>
                                                            <div class="small text-muted"><?php echo h(trim(implode(' | ', array_filter([
                                                                trim((string) ($row['brand'] ?? '')),
                                                                trim((string) ($row['model'] ?? '')),
                                                                trim((string) ($row['serial_no'] ?? '')),
                                                            ])))); ?></div>
                                                            <div class="small text-muted">Acquired: <?php echo h(!empty($row['acquisition_date']) ? date('M d, Y', strtotime((string) $row['acquisition_date'])) : '-'); ?> | Amount: <?php echo h(number_format((float) ($row['amount'] ?? 0), 2)); ?></div>
                                                        </td>
                                                        <td>
                                                            <div><?php echo h($row['office_name'] ?? '-'); ?></div>
                                                            <div class="small text-muted"><?php echo h($accountable !== '' ? $accountable : 'No accountable employee'); ?></div>
                                                        </td>
                                                        <td><span class="badge <?php echo ($row['source_type'] ?? '') === 'legacy' ? 'text-bg-secondary' : 'text-bg-success'; ?>"><?php echo h(rpcppe_selection_source_label((string) ($row['source_type'] ?? 'system'))); ?></span></td>
                                                        <td>
                                                            <div><span class="badge <?php echo h(rpcppe_status_badge_class((string) ($row['rpcppe_status'] ?? 'excluded'))); ?>"><?php echo h(rpcppe_status_label((string) ($row['rpcppe_status'] ?? 'excluded'))); ?></span></div>
                                                            <div class="small text-muted mt-1"><?php echo ((int) ($row['is_rpcppe_candidate'] ?? 0) === 1) ? 'Included candidate' : 'Not included'; ?></div>
                                                        </td>
                                                        <td><a href="<?php echo h($detailUrl); ?>" class="btn btn-sm btn-outline-primary">Open Asset</a></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr><td colspan="7" class="text-center text-muted py-4">No RPCPPE assets found for the current filters.</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </form>

                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                            <div class="small text-muted">Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?></div>
                            <nav aria-label="RPCPPE inclusion pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $page <= 1 ? '#' : h(rpcppe_selection_build_url(['page' => $page - 1])); ?>">Previous</a>
                                    </li>
                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                    ?>
                                        <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo h(rpcppe_selection_build_url(['page' => $pageNumber])); ?>"><?php echo number_format($pageNumber); ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : h(rpcppe_selection_build_url(['page' => $page + 1])); ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('rpcppeSelectAll');
    if (!selectAll) {
        return;
    }

    selectAll.addEventListener('change', function () {
        var checked = !!selectAll.checked;
        document.querySelectorAll('.rpcppe-row-checkbox').forEach(function (checkbox) {
            checkbox.checked = checked;
        });
    });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
