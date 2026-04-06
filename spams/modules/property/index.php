<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$officeId = isset($_GET['office_id']) ? (int) $_GET['office_id'] : 0;
$search = trim($_GET['q'] ?? '');
$itemType = trim($_GET['item_type'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$brandModelFilter = trim($_GET['brand_model'] ?? '');
$serialFilter = trim($_GET['serial_no'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);
$allowedPerPage = [25, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 25;
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
            ri.unit_cost AS amount,
                COALESCE(curr_o.office_name, o.office_name) AS office_name,
                COALESCE(curr_e.first_name, e.first_name) AS first_name,
                COALESCE(curr_e.middle_name, e.middle_name) AS middle_name,
                COALESCE(curr_e.last_name, e.last_name) AS last_name,
                COALESCE(curr_e.suffix_name, e.suffix_name) AS suffix_name,
            r.received_date AS date_acquired,
                d.distribution_date AS record_date,
                d.document_no AS document_no,
                d.document_type,
                d.id AS distribution_id,
                'system' AS source_type
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
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
            for ($index = 0; $index < 10; $index++) {
                $params[] = $searchLike;
            }
        }
        if ($itemType !== '') {
            $systemSql .= " AND poi.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($brandModelFilter !== '') {
            $systemSql .= " AND (did.brand LIKE ? OR did.model LIKE ? OR CONCAT(COALESCE(did.brand, ''), ' ', COALESCE(did.model, '')) LIKE ?)";
            $brandModelLike = '%' . $brandModelFilter . '%';
            $types .= 'sss';
            $params[] = $brandModelLike;
            $params[] = $brandModelLike;
            $params[] = $brandModelLike;
        }
        if ($serialFilter !== '') {
            $systemSql .= " AND did.serial_no LIKE ?";
            $types .= 's';
            $params[] = '%' . $serialFilter . '%';
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
            la.acquisition_cost AS amount,
                o.office_name,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix_name,
            la.acquisition_date AS date_acquired,
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
            for ($index = 0; $index < 10; $index++) {
                $params[] = $searchLike;
            }
        }
        if ($itemType !== '') {
            $legacySql .= " AND la.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($brandModelFilter !== '') {
            $legacySql .= " AND (la.brand LIKE ? OR la.model LIKE ? OR CONCAT(COALESCE(la.brand, ''), ' ', COALESCE(la.model, '')) LIKE ?)";
            $brandModelLike = '%' . $brandModelFilter . '%';
            $types .= 'sss';
            $params[] = $brandModelLike;
            $params[] = $brandModelLike;
            $params[] = $brandModelLike;
        }
        if ($serialFilter !== '') {
            $legacySql .= " AND la.serial_no LIKE ?";
            $types .= 's';
            $params[] = '%' . $serialFilter . '%';
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

        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $exportStmt = $db->prepare($dataSql);
            $exportRows = [];
            if ($exportStmt) {
                if ($types !== '') {
                    $refs = [$types];
                    foreach ($params as $key => $value) {
                        $refs[] = &$params[$key];
                    }
                    call_user_func_array([$exportStmt, 'bind_param'], $refs);
                }
                $exportStmt->execute();
                $result = $exportStmt->get_result();
                if ($result instanceof mysqli_result) {
                    $exportRows = $result->fetch_all(MYSQLI_ASSOC);
                }
                $exportStmt->close();
            }

            $filename = 'asset_registry_export_' . date('Y-m-d_H-i-s') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            if ($output !== false) {
                fputcsv($output, [
                    'Source',
                    'Property Number',
                    'Item Type',
                    'Classification',
                    'Classification Family',
                    'Description',
                    'Brand',
                    'Model',
                    'Serial No',
                    'Amount',
                    'Date Acquired',
                    'Office',
                    'Accountable Person',
                    'Reference',
                    'Record Date',
                ]);

                foreach ($exportRows as $row) {
                    fputcsv($output, [
                        registry_source_label((string) ($row['source_type'] ?? 'system')),
                        $row['property_no'] ?? '',
                        $row['item_type'] ?? '',
                        $row['classification_name'] ?? '',
                        $row['classification_family'] ?? '',
                        preg_replace('/\s+/', ' ', (string) ($row['description'] ?? '')),
                        $row['brand'] ?? '',
                        $row['model'] ?? '',
                        $row['serial_no'] ?? '',
                        number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                        $row['date_acquired'] ?? '',
                        $row['office_name'] ?? '',
                        employee_display_name_from_row($row),
                        $row['document_no'] ?? '',
                        $row['record_date'] ?? '',
                    ]);
                }

                fclose($output);
            }
            exit;
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
        'q' => $_GET['q'] ?? '',
        'item_type' => $_GET['item_type'] ?? '',
        'source' => $_GET['source'] ?? '',
        'brand_model' => $_GET['brand_model'] ?? '',
        'serial_no' => $_GET['serial_no'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
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
                <div class="workspace-hero workspace-hero-registry mb-4">
                    <div class="workspace-hero-main">
                        <span class="workspace-eyebrow">Registry Workspace</span>
                        <h5 class="card-title mb-1">Asset Registry</h5>
                        <div class="workspace-header-copy">Track active equipment and semi-expendable assets across system transactions and beginning balance records from one search surface.</div>
                    </div>
                    <div class="workspace-hero-side">
                        <div class="workspace-hero-stat">
                            <span class="workspace-hero-stat-label">Visible Rows</span>
                            <strong><?php echo number_format((int) count($rows)); ?></strong>
                        </div>
                        <div class="workspace-hero-stat">
                            <span class="workspace-hero-stat-label">Matched Assets</span>
                            <strong><?php echo number_format((int) $total); ?></strong>
                        </div>
                        <a href="<?php echo h(build_registry_url(['export' => 'csv', 'page' => 1])); ?>" class="btn btn-outline-success">
                            <i class="bi bi-download me-1"></i>Export Assets
                        </a>
                    </div>
                </div>

                <div class="workspace-header mb-3">
                    <div>
                        <div class="small text-muted workspace-header-copy">Use quick search for property no., classification, office, accountable person, brand, model, or serial number.</div>
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
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Total Active Assets</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $summary['total']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Equipment</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $summary['equipment']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Semi-Expendable</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $summary['semi_expendable']); ?></div>
                        </div>
                    </div>
                    <div>
                        <div class="workspace-summary-card h-100">
                            <div class="workspace-summary-label">Beginning Balance</div>
                            <div class="workspace-summary-value"><?php echo number_format((int) $summary['legacy']); ?></div>
                        </div>
                    </div>
                </div>

                <form method="get" class="workspace-filter-panel workspace-filter-panel-strong mb-3">
                    <div class="workspace-filter-title-row">
                        <div>
                            <div class="workspace-filter-title">Find Assets Faster</div>
                            <div class="workspace-filter-copy">Start with quick search, then narrow by office, type, source, or date when needed.</div>
                        </div>
                        <div class="workspace-actions">
                            <button class="btn btn-sm btn-primary" type="submit">Apply Filters</button>
                            <a href="<?php echo h(build_registry_url(['office_id' => '', 'q' => '', 'item_type' => '', 'source' => '', 'brand_model' => '', 'serial_no' => '', 'date_from' => '', 'date_to' => '', 'per_page' => 25, 'page' => 1])); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                        </div>
                    </div>
                    <div class="workspace-filter-grid">
                    <div class="workspace-filter-wide">
                        <label class="form-label mb-0">Quick Search</label>
                        <input type="search" name="q" class="form-control" value="<?php echo h($search); ?>" placeholder="Property no., description, classification, office, accountable, brand, model, or serial no.">
                    </div>
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
                    <div class="workspace-filter-wide">
                        <label class="form-label mb-0">Brand / Model</label>
                        <input type="text" name="brand_model" class="form-control form-control-sm" value="<?php echo h($brandModelFilter); ?>" placeholder="Search brand or model">
                    </div>
                    <div>
                        <label class="form-label mb-0">Serial No.</label>
                        <input type="text" name="serial_no" class="form-control form-control-sm" value="<?php echo h($serialFilter); ?>" placeholder="Search serial">
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
                    </div>
                </form>

                <div class="workspace-filter-panel workspace-results-bar mb-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="small text-muted">
                            <?php if ($total > 0): ?>
                                Showing <?php echo number_format($rangeStart); ?>-<?php echo number_format($rangeEnd); ?> of <?php echo number_format($total); ?> matching assets
                            <?php else: ?>
                                No assets matched the current filters
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            Review details, then open the asset record or continue to lifecycle actions
                        </div>
                    </div>
                </div>

                <div class="table-responsive mobile-table-frame asset-registry-table-frame workspace-filter-panel">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th class="asset-col-primary" style="min-width: 180px;">Asset</th>
                                <th class="asset-col-classification" style="min-width: 300px;">Classification / Description</th>
                                <th class="asset-col-item-details" style="min-width: 180px;">Item Details</th>
                                <th class="asset-col-amount" style="min-width: 180px;">Amount</th>
                                <th class="asset-col-date" style="min-width: 160px;">Date Acquired</th>
                                <th class="asset-col-assignment" style="min-width: 220px;">Assignment</th>
                                <th class="asset-col-reference" style="min-width: 200px;">Reference / Source</th>
                                <th class="asset-col-actions" style="min-width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rows): ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $classificationText = trim((string) ($row['classification_name'] ?? ''));
                                    $classificationLabel = trim((!empty($row['classification_family']) ? $row['classification_family'] . ' / ' : '') . $classificationText);
                                    $brandModel = trim(trim((string) ($row['brand'] ?? '')) . ' ' . trim((string) ($row['model'] ?? '')));
                                    $descriptionFull = trim((string) ($row['description'] ?? ''));
                                    $descriptionShort = $descriptionFull;
                                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                                        if (mb_strlen($descriptionShort) > 180) {
                                            $descriptionShort = rtrim(mb_substr($descriptionShort, 0, 180)) . '...';
                                        }
                                    } elseif (strlen($descriptionShort) > 180) {
                                        $descriptionShort = rtrim(substr($descriptionShort, 0, 180)) . '...';
                                    }
                                    $accountable = employee_display_name_from_row($row);
                                    $sourceLabel = registry_source_label((string) ($row['source_type'] ?? 'system'));
                                    $isLegacy = ($row['source_type'] ?? '') === 'legacy';
                                    $detailId = (int) ($row['detail_id'] ?? 0);
                                    $distributionId = (int) ($row['distribution_id'] ?? 0);
                                    $assetKey = (string) ($row['asset_key'] ?? '');
                                    $propertyNo = (string) ($row['property_no'] ?? '');
                                    $amountValue = (float) ($row['amount'] ?? 0);
                                    $dateAcquiredLabel = !empty($row['date_acquired']) ? date('M d, Y', strtotime((string) $row['date_acquired'])) : '-';
                                    $recordDateLabel = !empty($row['record_date']) ? date('M d, Y', strtotime((string) $row['record_date'])) : '';
                                    ?>
                                    <tr class="asset-registry-row">
                                        <td class="asset-registry-cell-primary asset-col-primary">
                                            <div class="asset-registry-primary"><?php echo h($row['property_no'] ?? ''); ?></div>
                                            <?php if (($row['item_type'] ?? '') === 'semi_expendable'): ?>
                                                <span class="badge text-bg-info">Semi-Expendable</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-primary">Equipment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="asset-col-classification">
                                            <div class="asset-registry-classification"><?php echo h($classificationLabel !== '' ? $classificationLabel : 'Unclassified'); ?></div>
                                            <div class="text-muted small asset-registry-description" title="<?php echo h($descriptionFull); ?>"><?php echo h($descriptionShort); ?></div>
                                            <div class="asset-registry-compact-meta">
                                                <div class="small text-muted">Item: <?php echo h($brandModel !== '' ? $brandModel : 'No brand / model'); ?> | SN: <?php echo h($row['serial_no'] !== '' ? $row['serial_no'] : '-'); ?></div>
                                                <div class="small text-muted">Amount: <?php echo h(number_format($amountValue, 2)); ?> | Acquired: <?php echo h($dateAcquiredLabel); ?></div>
                                                <div class="small text-muted">Assigned: <?php echo h($row['office_name'] ?? '-'); ?><?php echo $accountable !== '' ? ' / ' . h($accountable) : ''; ?></div>
                                                <div class="small text-muted">Ref: <?php echo h($row['document_no'] ?? ''); ?> | <?php echo h($recordDateLabel); ?> | <?php echo h($sourceLabel); ?></div>
                                            </div>
                                        </td>
                                        <td class="asset-col-item-details">
                                            <div class="asset-registry-detail-line"><?php echo h($brandModel !== '' ? $brandModel : 'No brand / model'); ?></div>
                                            <div class="text-muted small">Serial No.: <?php echo h($row['serial_no'] !== '' ? $row['serial_no'] : '-'); ?></div>
                                        </td>
                                        <td class="asset-col-amount">
                                            <div class="asset-registry-detail-line text-end"><?php echo h(number_format($amountValue, 2)); ?></div>
                                        </td>
                                        <td class="asset-col-date">
                                            <div class="asset-registry-detail-line"><?php echo h($dateAcquiredLabel); ?></div>
                                        </td>
                                        <td class="asset-col-assignment">
                                            <div class="asset-registry-detail-line"><?php echo h($row['office_name'] ?? '-'); ?></div>
                                            <div class="text-muted small"><?php echo h($accountable !== '' ? $accountable : 'No accountable employee'); ?></div>
                                        </td>
                                        <td class="asset-col-reference">
                                            <div class="asset-registry-detail-line"><?php echo h($row['document_no'] ?? ''); ?></div>
                                            <div class="text-muted small"><?php echo h($recordDateLabel); ?></div>
                                            <?php if (($row['source_type'] ?? '') === 'legacy'): ?>
                                                <div class="mt-1"><span class="badge text-bg-secondary"><?php echo h($sourceLabel); ?></span></div>
                                            <?php else: ?>
                                                <div class="mt-1"><span class="badge text-bg-success"><?php echo h($sourceLabel); ?></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="asset-registry-cell-actions asset-col-actions">
                                            <div class="d-flex gap-2 flex-wrap">
                                                <a href="<?php echo base_url('modules/property/view.php?source=' . urlencode((string) ($row['source_type'] ?? 'system')) . '&id=' . $detailId); ?>" class="btn btn-sm btn-primary">Open Record</a>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        More
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
                                                            <li><span class="dropdown-item-text text-muted small">Legacy asset actions continue from the detail page.</span></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">No asset records found.</td>
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


