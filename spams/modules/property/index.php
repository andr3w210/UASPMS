<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db_connect();
$officeId = isset($_GET['office_id']) ? (int) $_GET['office_id'] : 0;
$itemType = trim($_GET['item_type'] ?? '');
$sourceFilter = trim($_GET['source'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if (!in_array($itemType, ['', 'equipment', 'semi_expendable'], true)) {
    $itemType = '';
}
if (!in_array($sourceFilter, ['', 'system', 'legacy'], true)) {
    $sourceFilter = '';
}

$rows = [];
$offices = [];
$classifications = [];
$summary = ['total' => 0, 'equipment' => 0, 'semi_expendable' => 0, 'legacy' => 0];

if ($db) {
    ensure_distribution_item_runtime_columns($db);

    $res = $db->query("SELECT id, office_name FROM offices WHERE is_active = 1 ORDER BY office_name ASC");
    if ($res instanceof mysqli_result) {
        $offices = $res->fetch_all(MYSQLI_ASSOC);
    }

    $res = $db->query("SELECT id, classification_name, classification_family FROM classifications WHERE is_active = 1 ORDER BY classification_family ASC, classification_name ASC");
    if ($res instanceof mysqli_result) {
        $classifications = $res->fetch_all(MYSQLI_ASSOC);
    }

    if ($sourceFilter !== 'legacy') {
        $sql = "SELECT
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
        $params = [];
        $types = '';

        if ($officeId > 0) {
            $sql .= " AND d.office_id = ?";
            $types .= 'i';
            $params[] = $officeId;
        }
        if ($itemType !== '') {
            $sql .= " AND poi.item_type = ?";
            $types .= 's';
            $params[] = $itemType;
        }
        if ($dateFrom !== '') {
            $sql .= " AND d.distribution_date >= ?";
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND d.distribution_date <= ?";
            $types .= 's';
            $params[] = $dateTo;
        }
        $sql .= " ORDER BY d.distribution_date DESC, did.id DESC";

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
                $rows[] = $row;
            }
            $stmt->close();
        }
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
        $legacyParams = [];
        $legacyTypes = '';

        if ($officeId > 0) {
            $legacySql .= " AND la.office_id = ?";
            $legacyTypes .= 'i';
            $legacyParams[] = $officeId;
        }
        if ($itemType !== '') {
            $legacySql .= " AND la.item_type = ?";
            $legacyTypes .= 's';
            $legacyParams[] = $itemType;
        }
        if ($dateFrom !== '') {
            $legacySql .= " AND (la.acquisition_date IS NULL OR la.acquisition_date >= ?)";
            $legacyTypes .= 's';
            $legacyParams[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $legacySql .= " AND (la.acquisition_date IS NULL OR la.acquisition_date <= ?)";
            $legacyTypes .= 's';
            $legacyParams[] = $dateTo;
        }
        $legacySql .= " ORDER BY la.acquisition_date DESC, la.id DESC";

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
            $legacyRes = $legacyStmt->get_result();
            while ($legacyRes && ($legacyRow = $legacyRes->fetch_assoc())) {
                $rows[] = $legacyRow;
            }
            $legacyStmt->close();
        }
    }
}

usort($rows, static function (array $a, array $b): int {
    $aDate = $a['record_date'] ?? '';
    $bDate = $b['record_date'] ?? '';
    if ($aDate === $bDate) {
        return strcmp((string) ($b['property_no'] ?? ''), (string) ($a['property_no'] ?? ''));
    }
    return strcmp((string) $bDate, (string) $aDate);
});

foreach ($rows as $summaryRow) {
    $summary['total']++;
    if (($summaryRow['item_type'] ?? '') === 'semi_expendable') {
        $summary['semi_expendable']++;
    } else {
        $summary['equipment']++;
    }
    if (($summaryRow['source_type'] ?? '') === 'legacy') {
        $summary['legacy']++;
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

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="card-title mb-0">Asset Registry</h5>
                        <div class="small text-muted">Unified action workspace for equipment and semi-expendable assets, including beginning balance entries.</div>
                    </div>
                    <span id="recordCount" class="text-muted small"><?php echo count($rows); ?> record(s)</span>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Total Active Assets</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['total']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Equipment</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['equipment']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Semi-Expendable</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['semi_expendable']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded-3 p-3 h-100 bg-light-subtle">
                            <div class="text-muted small">Beginning Balance</div>
                            <div class="fs-4 fw-semibold"><?php echo number_format((int) $summary['legacy']); ?></div>
                        </div>
                    </div>
                </div>

                <form method="get" class="row g-2 mb-3 align-items-end">
                    <div class="col-md-3">
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
                    <div class="col-md-2">
                        <label class="form-label mb-0">Item Type</label>
                        <select name="item_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="equipment" <?php echo $itemType === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                            <option value="semi_expendable" <?php echo $itemType === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-0">Source</label>
                        <select name="source" class="form-select form-select-sm">
                            <option value="">All Sources</option>
                            <option value="system" <?php echo $sourceFilter === 'system' ? 'selected' : ''; ?>>System Transactions</option>
                            <option value="legacy" <?php echo $sourceFilter === 'legacy' ? 'selected' : ''; ?>>Beginning Balance</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-0">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-0">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div class="col-md-1 d-grid">
                        <button class="btn btn-sm btn-primary">Apply</button>
                    </div>
                </form>

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-4">
                        <label for="tableSearch" class="form-label mb-0">Search</label>
                        <input type="search" id="tableSearch" class="form-control form-control-sm" placeholder="Search property no., description, brand, model, office, accountable...">
                    </div>
                    <div class="col-md-2">
                        <label for="typeFilter" class="form-label mb-0">Quick Type Filter</label>
                        <select id="typeFilter" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            <option value="equipment">Equipment</option>
                            <option value="semi_expendable">Semi-Expendable</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="classificationFilter" class="form-label mb-0">Classification</label>
                        <select id="classificationFilter" class="form-select form-select-sm">
                            <option value="">All Classifications</option>
                            <?php foreach ($classifications as $classification): ?>
                                <?php
                                $classificationValue = trim((string) ($classification['classification_name'] ?? ''));
                                if ($classificationValue === '') {
                                    continue;
                                }
                                $classificationLabel = trim((!empty($classification['classification_family']) ? $classification['classification_family'] . ' / ' : '') . $classificationValue);
                                ?>
                                <option value="<?php echo h(strtolower($classificationValue)); ?>"><?php echo h($classificationLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label for="perPageSelect" class="form-label mb-0">Rows</label>
                        <select id="perPageSelect" class="form-select form-select-sm">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div class="col-md-2 text-md-end">
                        <div id="pageInfo" class="small text-muted mb-1"></div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" id="prevPage" class="btn btn-outline-secondary">Prev</button>
                            <button type="button" id="nextPage" class="btn btn-outline-secondary">Next</button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="dataTable">
                        <thead>
                            <tr>
                                <th data-sort="property_no" style="min-width: 180px;">Asset</th>
                                <th data-sort="classification" style="min-width: 300px;">Classification / Description</th>
                                <th data-sort="brand_model" style="min-width: 180px;">Item Details</th>
                                <th data-sort="office_name" style="min-width: 220px;">Assignment</th>
                                <th data-sort="document_no" style="min-width: 200px;">Reference / Source</th>
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
                                    <tr
                                        data-type="<?php echo h((string) ($row['item_type'] ?? '')); ?>"
                                        data-classification="<?php echo h(strtolower($classificationText)); ?>"
                                        data-source="<?php echo h((string) ($row['source_type'] ?? 'system')); ?>"
                                    >
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
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var perPage = 25;
    var currentPage = 1;
    var sortCol = -1;
    var sortDir = 'asc';

    function getRows() {
        return Array.from(document.querySelectorAll('#dataTable tbody tr')).filter(function (row) {
            return row.cells.length > 1;
        });
    }

    function applyFilters() {
        var term = (document.getElementById('tableSearch')?.value || '').toLowerCase();
        var type = document.getElementById('typeFilter')?.value || '';
        var classification = document.getElementById('classificationFilter')?.value || '';
        var rows = getRows();

        rows.forEach(function (row) {
            var textMatch = !term || row.textContent.toLowerCase().includes(term);
            var typeMatch = !type || row.dataset.type === type;
            var classificationMatch = !classification || row.dataset.classification === classification;
            row.dataset.visible = (textMatch && typeMatch && classificationMatch) ? '1' : '0';
        });

        currentPage = 1;
        renderPage();
    }

    function renderPage() {
        var allRows = getRows();
        var rows = allRows.filter(function (row) { return row.dataset.visible !== '0'; });
        var total = rows.length;
        var pages = Math.max(1, Math.ceil(total / perPage));
        currentPage = Math.min(currentPage, pages);

        allRows.forEach(function (row) { row.style.display = 'none'; });

        var start = (currentPage - 1) * perPage;
        rows.slice(start, start + perPage).forEach(function (row) { row.style.display = ''; });

        var pageInfo = document.getElementById('pageInfo');
        if (pageInfo) {
            pageInfo.textContent = 'Page ' + currentPage + ' of ' + pages;
        }

        var recordCount = document.getElementById('recordCount');
        if (recordCount) {
            recordCount.textContent = 'Showing ' + (rows.length ? Math.min(start + 1, total) : 0) + '-' + Math.min(start + perPage, total) + ' of ' + total + ' records';
        }

        var prev = document.getElementById('prevPage');
        var next = document.getElementById('nextPage');
        if (prev) prev.disabled = currentPage <= 1;
        if (next) next.disabled = currentPage >= pages;
    }

    document.getElementById('tableSearch')?.addEventListener('input', applyFilters);
    document.getElementById('typeFilter')?.addEventListener('change', applyFilters);
    document.getElementById('classificationFilter')?.addEventListener('change', applyFilters);
    document.getElementById('prevPage')?.addEventListener('click', function () {
        currentPage--;
        renderPage();
    });
    document.getElementById('nextPage')?.addEventListener('click', function () {
        currentPage++;
        renderPage();
    });
    document.getElementById('perPageSelect')?.addEventListener('change', function () {
        perPage = parseInt(this.value, 10) || 25;
        currentPage = 1;
        renderPage();
    });

    document.querySelectorAll('#dataTable th[data-sort]').forEach(function (th, idx) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function () {
            var tbody = document.querySelector('#dataTable tbody');
            var rows = getRows();
            var dir = (sortCol === idx && sortDir === 'asc') ? 'desc' : 'asc';
            sortCol = idx;
            sortDir = dir;

            rows.sort(function (a, b) {
                var at = (a.cells[idx]?.textContent || '').trim().toLowerCase();
                var bt = (b.cells[idx]?.textContent || '').trim().toLowerCase();
                return dir === 'asc' ? at.localeCompare(bt) : bt.localeCompare(at);
            });

            rows.forEach(function (row) { tbody.appendChild(row); });
            renderPage();
        });
    });

    applyFilters();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
