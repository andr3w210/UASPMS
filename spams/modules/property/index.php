<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$officeId = isset($_GET['office_id']) ? (int) $_GET['office_id'] : 0;
$itemType = trim($_GET['item_type'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 20);
$allowedPerPage = [20, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

if (!in_array($itemType, ['', 'equipment', 'semi_expendable'], true)) {
    $itemType = '';
}
if (!in_array($sourceFilter, ['', 'system', 'legacy'], true)) {
    $sourceFilter = '';
}

$rows = [];
$offices = [];
$summary = ['total' => 0, 'equipment' => 0, 'semi_expendable' => 0, 'legacy' => 0];
$total = 0;
$totalPages = 0;

if ($db) {
    $res = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res instanceof mysqli_result) {
        $offices = $res->fetch_all(MYSQLI_ASSOC);
    }

    $queries = [];
    $params = [];
    $types = '';

    if ($sourceFilter !== 'legacy') {
        $systemSql = "SELECT
                did.id AS detail_id,
                did.property_number AS property_no,
                CONCAT('system:', did.id) AS asset_key,
                poi.item_type,
                poi.item_description AS description,
                c.classification_name,
                c.classification_family,
                did.brand,
                did.model,
                did.serial_no,
                COALESCE(curr_o.office_name, o.office_name) AS office_name,
                COALESCE(curr_e.first_name, e.first_name) AS first_name,
                COALESCE(curr_e.middle_name, e.middle_name) AS middle_name,
                COALESCE(curr_e.last_name, e.last_name) AS last_name,
                COALESCE(curr_e.suffix_name, e.suffix_name) AS suffix_name,
                d.distribution_date AS record_date,
                d.document_no AS document_no,
                d.document_type,
                d.id AS distribution_id,
                'system' AS source_type
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN offices o ON o.id = d.office_id
            LEFT JOIN employees e ON e.id = d.employee_id
            LEFT JOIN offices curr_o ON curr_o.id = did.current_office_id
            LEFT JOIN employees curr_e ON curr_e.id = did.current_employee_id
            WHERE poi.item_type IN ('equipment', 'semi_expendable')
              AND did.is_distributed = 1
              AND (did.is_disposed IS NULL OR did.is_disposed = 0)";

        if ($officeId > 0) {
            $systemSql .= " AND d.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($itemType !== '') {
            $systemSql .= " AND poi.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($dateFrom !== '') {
            $systemSql .= " AND d.distribution_date >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $systemSql .= " AND d.distribution_date <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }

        $queries[] = $systemSql;
    }

    if ($sourceFilter !== 'system') {
        $legacySql = "SELECT
                la.id AS detail_id,
                la.property_number AS property_no,
                CONCAT('legacy:', la.id) AS asset_key,
                la.item_type,
                la.item_description AS description,
                c.classification_name,
                c.classification_family,
                la.brand,
                la.model,
                la.serial_no,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
                la.acquisition_date AS record_date,
                'Beginning Balance' AS document_no,
                'legacy' AS document_type,
                0 AS distribution_id,
                'legacy' AS source_type
            FROM legacy_assets la
            LEFT JOIN classifications c ON c.id = la.classification_id
            LEFT JOIN offices o ON o.id = la.office_id
            LEFT JOIN employees e ON e.id = la.employee_id
            WHERE la.is_active = 1
              AND la.item_type IN ('equipment', 'semi_expendable')";

        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($itemType !== '') {
            $legacySql .= " AND la.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($dateFrom !== '') {
            $legacySql .= " AND (la.acquisition_date IS NULL OR la.acquisition_date >= ?)";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $legacySql .= " AND (la.acquisition_date IS NULL OR la.acquisition_date <= ?)";
            $types .= 's';
            $params[] = $dateTo;
        }

        $queries[] = $legacySql;
    }

    if ($queries) {
        $unionSql = implode(" UNION ALL ", $queries);
        $countSql = "SELECT COUNT(*) AS total FROM (" . $unionSql . ") asset_registry_rows";
        $dataSql = "SELECT * FROM (" . $unionSql . ") asset_registry_rows ORDER BY record_date DESC, property_no DESC, detail_id DESC";
        $summarySql = "
            SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN item_type = 'equipment' THEN 1 ELSE 0 END) AS equipment_count,
                SUM(CASE WHEN item_type = 'semi_expendable' THEN 1 ELSE 0 END) AS semi_count,
                SUM(CASE WHEN source_type = 'legacy' THEN 1 ELSE 0 END) AS legacy_count
            FROM (" . $unionSql . ") asset_registry_rows
        ";

        $pageData = paginate($db, $countSql, $dataSql, $params, $types, $page, $perPage);
        $rows = $pageData['data'];
        $total = $pageData['total'];
        $page = $pageData['page'];
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
            $summaryRow = $summaryStmt->get_result()->fetch_assoc();
            $summaryStmt->close();

            if ($summaryRow) {
                $summary = [
                    'total' => (int) ($summaryRow['total_count'] ?? 0),
                    'equipment' => (int) ($summaryRow['equipment_count'] ?? 0),
                    'semi_expendable' => (int) ($summaryRow['semi_count'] ?? 0),
                    'legacy' => (int) ($summaryRow['legacy_count'] ?? 0),
                ];
            }
        }
    }
}

function employee_display_name_from_row(array $row): string
{
    if (function_exists('employee_display_name')) {
        return employee_display_name($row);
    }
    $parts = [
        trim((string) ($row['first_name'] ?? '')),
        trim((string) ($row['middle_name'] ?? '')),
        trim((string) ($row['last_name'] ?? '')),
        trim((string) ($row['suffix_name'] ?? '')),
    ];
    return trim(implode(' ', array_filter($parts)));
}

function registry_source_label(string $sourceType): string
{
    return $sourceType === 'legacy' ? 'Beginning Balance' : 'System Transaction';
}

function build_registry_url(array $overrides = []): string
{
    $params = [
        'office_id' => $_GET['office_id'] ?? '',
        'item_type' => $_GET['item_type'] ?? '',
        'source' => $_GET['source'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'per_page' => $_GET['per_page'] ?? 20,
        'page' => $_GET['page'] ?? 1,
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

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
                <div class="workspace-header mb-3">
                    <div>
                        <h5 class="card-title mb-0">Asset Registry</h5>
                        <div class="small text-muted workspace-header-copy">Unified action workspace for equipment and semi-expendable assets, including beginning balance entries.</div>
                    </div>
                    <span class="text-muted small workspace-header-meta">
                        <?php if ($total > 0): ?>
                            Showing <?php echo number_format($rangeStart); ?>-<?php echo number_format($rangeEnd); ?> of <?php echo number_format($total); ?> record(s)
                        <?php else: ?>
                            0 record(s)
                        <?php endif; ?>
                    </span>
                </div>

                <div class="workspace-summary-grid mb-4">
                    <div>
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Active Assets</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['total']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Equipment</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['equipment']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Semi-Expendable</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['semi_expendable']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Beginning Balance</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['legacy']); ?></div>
                        </div>
                    </div>
                </div>

                <form method="get" class="workspace-filter-grid mb-3">
                    <div>
                        <label class="form-label mb-0">Office</label>
                        <select name="office_id" class="form-select form-select-sm">
                            <option value="">All Offices</option>
                            <?php foreach ($offices as $office): ?>
                                <option value="<?php echo (int) $office['id']; ?>" <?php echo $officeId === (int) $office['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($office['office_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-0">Item Type</label>
                        <select name="item_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="equipment" <?php echo $itemType === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                            <option value="semi_expendable" <?php echo $itemType === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-0">Source</label>
                        <select name="source" class="form-select form-select-sm">
                            <option value="">All Sources</option>
                            <option value="system" <?php echo $sourceFilter === 'system' ? 'selected' : ''; ?>>System Transactions</option>
                            <option value="legacy" <?php echo $sourceFilter === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-0">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div>
                        <label class="form-label mb-0">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div>
                        <label class="form-label mb-0">Rows</label>
                        <select name="per_page" class="form-select form-select-sm">
                            <?php foreach ($allowedPerPage as $perPageOption): ?>
                                <option value="<?php echo $perPageOption; ?>" <?php echo $perPage === $perPageOption ? 'selected' : ''; ?>>
                                    <?php echo $perPageOption; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-primary">Apply</button>
                    </div>
                </form>

                <div class="table-responsive mobile-table-frame">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 180px;">Asset</th>
                                <th style="min-width: 300px;">Classification / Description</th>
                                <th style="min-width: 180px;">Item Details</th>
                                <th style="min-width: 220px;">Assignment</th>
                                <th style="min-width: 200px;">Reference / Source</th>
                                <th style="min-width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $classificationText = trim((string) ($row['classification_name'] ?? ''));
                                    $classificationLabel = trim((!empty($row['classification_family']) ? $row['classification_family'] . ' / ' : '') . $classificationText);
                                    $brandModel = trim(trim((string) ($row['brand'] ?? '')) . ' ' . trim((string) ($row['model'] ?? '')));
                                    $accountable = employee_display_name_from_row($row);
                                    $sourceLabel = registry_source_label((string) ($row['source_type'] ?? 'system'));
                                    $isLegacy = ($row['source_type'] ?? '') === 'legacy';
                                    $detailId = (int) ($row['detail_id'] ?? 0);
                                    $distributionId = (int) ($row['distribution_id'] ?? 0);
                                    $assetKey = (string) ($row['asset_key'] ?? '');
                                    $propertyNo = (string) ($row['property_no'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($row['property_no'] ?? ''); ?></div>
                                            <?php if (($row['item_type'] ?? '') === 'semi_expendable'): ?>
                                                <span class="badge text-bg-info">Semi-Expendable</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-primary">Equipment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($classificationLabel !== '' ? $classificationLabel : 'Unclassified'); ?></div>
                                            <div class="text-muted small"><?php echo h($row['description'] ?? ''); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo h($brandModel !== '' ? $brandModel : 'No brand/model'); ?></div>
                                            <div class="text-muted small">Serial No.: <?php echo h($row['serial_no'] !== '' ? $row['serial_no'] : '-'); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo h($row['office_name'] ?? '-'); ?></div>
                                            <div class="text-muted small"><?php echo h($accountable !== '' ? $accountable : 'No accountable employee'); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo h($row['document_no'] ?? ''); ?></div>
                                            <div class="text-muted small"><?php echo h(!empty($row['record_date']) ? date('M d, Y', strtotime((string) $row['record_date'])) : ''); ?></div>
                                            <?php if (($row['source_type'] ?? '') === 'legacy'): ?>
                                                <div class="mt-1"><span class="badge text-bg-secondary"><?php echo h($sourceLabel); ?></span></div>
                                            <?php else: ?>
                                                <div class="mt-1"><span class="badge text-bg-success"><?php echo h($sourceLabel); ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2 flex-wrap">
                                                <a href="<?php echo base_url('modules/property/view.php?source=' . urlencode((string) ($row['source_type'] ?? 'system')) . '&id=' . $detailId); ?>" class="btn btn-sm btn-primary">Open</a>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Actions
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item" href="<?php echo base_url('modules/transfers/index.php?asset_key=' . urlencode($assetKey)); ?>">Transfer</a></li>
                                                        <?php if (!$isLegacy): ?>
                                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/returns/index.php?detail_id=' . $detailId); ?>">Return</a></li>
                                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/disposals/index.php?detail_id=' . $detailId); ?>">Dispose</a></li>
                                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/maintenance/index.php?detail_id=' . $detailId); ?>">Maintenance</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/property/tags.php?detail_id=' . $detailId); ?>" target="_blank">Print QR</a></li>
                                                            <li><a class="dropdown-item" href="<?php echo base_url('modules/property/scan.php?ref=' . urlencode($propertyNo)); ?>" target="_blank">Lookup</a></li>
                                                            <?php if ($distributionId > 0): ?>
                                                                <?php if (($row['item_type'] ?? '') === 'semi_expendable'): ?>
                                                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/ics.php?id=' . $distributionId); ?>" target="_blank">Print ICS</a></li>
                                                                <?php else: ?>
                                                                    <li><a class="dropdown-item" href="<?php echo base_url('modules/distributions/par.php?id=' . $distributionId); ?>" target="_blank">Print PAR</a></li>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <li><span class="dropdown-item-text text-muted small">Legacy asset lifecycle actions continue from the detail page.</span></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No asset records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                        <div class="small text-muted">
                            Page <?php echo number_format($page); ?> of <?php echo number_format($totalPages); ?>
                        </div>
                        <nav aria-label="Asset registry pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page <= 1 ? '#' : h(build_registry_url(['page' => $page - 1])); ?>">Previous</a>
                                </li>
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++):
                                ?>
                                    <li class="page-item <?php echo $pageNumber === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo h(build_registry_url(['page' => $pageNumber])); ?>">
                                            <?php echo number_format($pageNumber); ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo $page >= $totalPages ? '#' : h(build_registry_url(['page' => $page + 1])); ?>">Next</a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
