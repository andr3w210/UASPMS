<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$page_title = 'Global Search';
$query = trim((string) ($_GET['q'] ?? ''));
$results = [];
$resultCount = 0;
$errors = [];
$savedSearches = [
    ['label' => 'Property Numbers', 'query' => '2026-', 'icon' => 'bi-upc-scan'],
    ['label' => 'Purchase Orders', 'query' => 'PO', 'icon' => 'bi-journal-text'],
    ['label' => 'RIS Records', 'query' => 'RIS', 'icon' => 'bi-receipt'],
    ['label' => 'QR Tags', 'query' => 'SPAMS-', 'icon' => 'bi-qr-code'],
    ['label' => 'Employees', 'query' => 'SPMU', 'icon' => 'bi-person-badge'],
    ['label' => 'Stock Items', 'query' => 'supply', 'icon' => 'bi-boxes'],
];

$tableExists = static function (mysqli $connection, string $tableName): bool {
    $escapedTable = $connection->real_escape_string($tableName);
    $result = $connection->query("SHOW TABLES LIKE '{$escapedTable}'");
    if ($result instanceof mysqli_result) {
        $exists = $result->num_rows > 0;
        $result->close();
        return $exists;
    }

    return false;
};

$columnExists = static function (mysqli $connection, string $tableName, string $columnName): bool {
    $escapedTable = $connection->real_escape_string($tableName);
    $escapedColumn = $connection->real_escape_string($columnName);
    $result = $connection->query("SHOW COLUMNS FROM `{$escapedTable}` LIKE '{$escapedColumn}'");
    if ($result instanceof mysqli_result) {
        $exists = $result->num_rows > 0;
        $result->close();
        return $exists;
    }

    return false;
};

$fetchSearchRows = static function (mysqli $connection, string $sql, array $params = []): array {
    try {
        $stmt = $connection->prepare($sql);
    } catch (mysqli_sql_exception $exception) {
        $stmt = false;
    }

    if (!$stmt) {
        return [];
    }

    if ($params) {
        $types = str_repeat('s', count($params));
        $bindValues = [$types];
        foreach ($params as $index => $value) {
            $bindValues[] = &$params[$index];
        }
        $stmt->bind_param(...$bindValues);
    }

    try {
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $exception) {
        return [];
    } finally {
        $stmt->close();
    }
};

$addResults = static function (array &$results, string $group, array $rows): void {
    if (!$rows) {
        return;
    }

    if (!isset($results[$group])) {
        $results[$group] = [];
    }

    foreach ($rows as $row) {
        $results[$group][] = $row;
    }
};

if ($query !== '' && strlen($query) < 2) {
    $errors[] = 'Enter at least 2 characters to search.';
}

if ($db && $query !== '' && !$errors) {
    $like = '%' . $query . '%';

    if ($tableExists($db, 'purchase_orders')) {
        $supplierJoin = $tableExists($db, 'suppliers') ? 'LEFT JOIN suppliers s ON s.id = po.supplier_id' : '';
        $supplierSelect = $tableExists($db, 'suppliers') ? "COALESCE(s.supplier_name, '')" : "''";
        $supplierCondition = $tableExists($db, 'suppliers') ? ' OR s.supplier_name LIKE ?' : '';
        $params = [$like, $like];
        if ($supplierCondition !== '') {
            $params[] = $like;
        }
        $rows = $fetchSearchRows($db, "
            SELECT
                po.po_number AS title,
                CONCAT('Status: ', po.status, ' | Supplier: ', {$supplierSelect}) AS subtitle,
                po.system_reference AS meta,
                CONCAT('modules/purchase_orders/view.php?id=', po.id) AS href
            FROM purchase_orders po
            {$supplierJoin}
            WHERE po.po_number LIKE ?
               OR po.system_reference LIKE ?
               {$supplierCondition}
            ORDER BY po.po_date DESC, po.id DESC
            LIMIT 8
        ", $params);
        $addResults($results, 'Purchase Orders', $rows);
    }

    if ($tableExists($db, 'receivings')) {
        $rows = $fetchSearchRows($db, "
            SELECT
                COALESCE(r.ris_no, r.system_reference) AS title,
                CONCAT('Receiving: ', r.status, ' | PO: ', COALESCE(po.po_number, '')) AS subtitle,
                r.received_date AS meta,
                CONCAT('modules/receivings/index.php?search=', r.system_reference) AS href
            FROM receivings r
            LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id
            WHERE r.system_reference LIKE ?
               OR r.ris_no LIKE ?
               OR r.delivery_receipt_no LIKE ?
               OR r.invoice_no LIKE ?
               OR po.po_number LIKE ?
            ORDER BY r.received_date DESC, r.id DESC
            LIMIT 8
        ", [$like, $like, $like, $like, $like]);
        $addResults($results, 'Receivings And RIS', $rows);
    }

    if ($tableExists($db, 'distributions')) {
        $rows = $fetchSearchRows($db, "
            SELECT
                d.document_no AS title,
                CONCAT(UPPER(d.document_type), ' | ', COALESCE(o.office_name, 'No office')) AS subtitle,
                d.distribution_date AS meta,
                CONCAT('modules/distributions/view.php?id=', d.id) AS href
            FROM distributions d
            LEFT JOIN offices o ON o.id = d.office_id
            WHERE d.document_no LIKE ?
               OR d.system_reference LIKE ?
               OR o.office_name LIKE ?
            ORDER BY d.distribution_date DESC, d.id DESC
            LIMIT 8
        ", [$like, $like, $like]);
        $addResults($results, 'Distributions', $rows);
    }

    if ($tableExists($db, 'distribution_item_details')
        && $tableExists($db, 'distribution_items')
        && $tableExists($db, 'distributions')
        && $tableExists($db, 'receiving_items')
        && $tableExists($db, 'purchase_order_items')
        && $columnExists($db, 'distribution_item_details', 'property_number')) {
        $qrCondition = $columnExists($db, 'distribution_item_details', 'qr_tag_code') ? ' OR did.qr_tag_code LIKE ?' : '';
        $qrSelect = $columnExists($db, 'distribution_item_details', 'qr_tag_code') ? "COALESCE(did.qr_tag_code, '')" : "''";
        $params = [$like, $like, $like, $like, $like];
        if ($qrCondition !== '') {
            $params[] = $like;
        }
        $rows = $fetchSearchRows($db, "
            SELECT
                COALESCE(NULLIF(did.property_number, ''), poi.item_description) AS title,
                CONCAT(poi.item_description, ' | ', COALESCE(d.document_no, ''), ' | QR: ', {$qrSelect}) AS subtitle,
                COALESCE(NULLIF(did.serial_no, ''), did.brand, did.model, '') AS meta,
                CONCAT('modules/property/view.php?source=system&id=', did.id) AS href
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            WHERE did.property_number LIKE ?
               OR did.serial_no LIKE ?
               OR did.brand LIKE ?
               OR did.model LIKE ?
               OR poi.item_description LIKE ?
               {$qrCondition}
            ORDER BY did.id DESC
            LIMIT 12
        ", $params);
        $addResults($results, 'System Assets', $rows);
    }

    if ($tableExists($db, 'legacy_assets')) {
        $legacyHasAccountabilityTracking = $columnExists($db, 'legacy_assets', 'accountability_status')
            && $columnExists($db, 'legacy_assets', 'last_office_id')
            && $columnExists($db, 'legacy_assets', 'last_employee_id');
        $legacyQrCondition = $columnExists($db, 'legacy_assets', 'qr_tag_code') ? ' OR qr_tag_code LIKE ?' : '';
        $legacyQrSelect = $columnExists($db, 'legacy_assets', 'qr_tag_code') ? "COALESCE(qr_tag_code, '')" : "''";
        $legacyTrackingSelect = $legacyHasAccountabilityTracking
            ? "CASE WHEN COALESCE(la.accountability_status, 'active') = 'for_reconciliation' THEN 'For Reconciliation | Last: ' ELSE '' END"
            : "''";
        $legacyTrackingOfficeSelect = $legacyHasAccountabilityTracking
            ? "COALESCE(last_o.office_name, '')"
            : "''";
        $legacyTrackingJoin = $legacyHasAccountabilityTracking
            ? 'LEFT JOIN offices last_o ON last_o.id = la.last_office_id LEFT JOIN employees last_e ON last_e.id = la.last_employee_id'
            : '';
        $legacyTrackingCondition = $legacyHasAccountabilityTracking
            ? " OR last_o.office_name LIKE ? OR last_e.first_name LIKE ? OR last_e.last_name LIKE ? OR CASE WHEN COALESCE(la.accountability_status, 'active') = 'for_reconciliation' THEN 'For Reconciliation' ELSE 'Active' END LIKE ?"
            : '';
        $params = [$like, $like, $like, $like, $like];
        if ($legacyQrCondition !== '') {
            $params[] = $like;
        }
        if ($legacyTrackingCondition !== '') {
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $rows = $fetchSearchRows($db, "
            SELECT
                la.property_number AS title,
                CONCAT(la.item_description, ' | ', {$legacyTrackingSelect}, {$legacyTrackingOfficeSelect}, ' | QR: ', {$legacyQrSelect}) AS subtitle,
                COALESCE(NULLIF(la.serial_no, ''), la.brand, la.model, '') AS meta,
                CONCAT('modules/property/view.php?source=legacy&id=', la.id) AS href
            FROM legacy_assets la
            {$legacyTrackingJoin}
            WHERE la.property_number LIKE ?
               OR la.serial_no LIKE ?
               OR la.brand LIKE ?
               OR la.model LIKE ?
               OR la.item_description LIKE ?
               {$legacyQrCondition}
               {$legacyTrackingCondition}
            ORDER BY la.id DESC
            LIMIT 12
        ", $params);
        $addResults($results, 'Legacy Assets', $rows);
    }

    if ($tableExists($db, 'employees')) {
        $rows = $fetchSearchRows($db, "
            SELECT
                TRIM(CONCAT(COALESCE(last_name, ''), ', ', COALESCE(first_name, ''), ' ', COALESCE(middle_name, ''))) AS title,
                CONCAT('Employee No: ', COALESCE(employee_no, ''), ' | ', COALESCE(position_title, '')) AS subtitle,
                COALESCE(email, '') AS meta,
                CONCAT('modules/employees/index.php?q=', employee_no) AS href
            FROM employees
            WHERE employee_no LIKE ?
               OR first_name LIKE ?
               OR middle_name LIKE ?
               OR last_name LIKE ?
               OR position_title LIKE ?
               OR email LIKE ?
            ORDER BY last_name ASC, first_name ASC
            LIMIT 8
        ", [$like, $like, $like, $like, $like, $like]);
        $addResults($results, 'Employees', $rows);
    }

    if ($tableExists($db, 'offices')) {
        $responsibilityJoin = $tableExists($db, 'responsibility_codes')
            ? 'LEFT JOIN responsibility_codes rc ON rc.office_id = o.id'
            : '';
        $responsibilitySelect = $tableExists($db, 'responsibility_codes')
            ? "COALESCE(MAX(rc.code), '')"
            : "''";
        $responsibilityCondition = $tableExists($db, 'responsibility_codes')
            ? ' OR rc.code LIKE ? OR rc.description LIKE ?'
            : '';
        $params = [$like, $like, $like];
        if ($responsibilityCondition !== '') {
            $params[] = $like;
            $params[] = $like;
        }
        $rows = $fetchSearchRows($db, "
            SELECT
                o.office_name AS title,
                CONCAT('Code: ', COALESCE(o.office_code, ''), ' | RC: ', {$responsibilitySelect}) AS subtitle,
                COALESCE(o.description, '') AS meta,
                CONCAT('modules/offices/index.php?q=', o.office_code) AS href
            FROM offices o
            {$responsibilityJoin}
            WHERE o.office_name LIKE ?
               OR o.office_code LIKE ?
               OR o.description LIKE ?
               {$responsibilityCondition}
            GROUP BY o.id, o.office_name, o.office_code, o.description
            ORDER BY o.office_name ASC
            LIMIT 8
        ", $params);
        $addResults($results, 'Offices', $rows);
    }

    if ($tableExists($db, 'suppliers')) {
        $rows = $fetchSearchRows($db, "
            SELECT
                supplier_name AS title,
                COALESCE(address, '') AS subtitle,
                COALESCE(contact_person, '') AS meta,
                CONCAT('modules/suppliers/index.php?q=', supplier_name) AS href
            FROM suppliers
            WHERE supplier_name LIKE ?
               OR address LIKE ?
               OR contact_person LIKE ?
               OR email LIKE ?
            ORDER BY supplier_name ASC
            LIMIT 8
        ", [$like, $like, $like, $like]);
        $addResults($results, 'Suppliers', $rows);
    }

    if ($tableExists($db, 'stock_catalog')) {
        $rows = $fetchSearchRows($db, "
            SELECT
                COALESCE(item_name, item_description, stock_no) AS title,
                CONCAT('Stock No: ', COALESCE(stock_no, ''), ' | Barcode: ', COALESCE(barcode, '')) AS subtitle,
                COALESCE(item_type, '') AS meta,
                CONCAT('modules/stock_catalog/index.php?q=', COALESCE(stock_no, item_name, item_description, '')) AS href
            FROM stock_catalog
            WHERE stock_no LIKE ?
               OR barcode LIKE ?
               OR item_name LIKE ?
               OR item_description LIKE ?
               OR item_type LIKE ?
            ORDER BY item_name ASC, stock_no ASC
            LIMIT 8
        ", [$like, $like, $like, $like, $like]);
        $addResults($results, 'Stock Catalog', $rows);
    }

    foreach ($results as $groupRows) {
        $resultCount += count($groupRows);
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-12 col-xxl-10">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                        <div>
                            <div class="text-uppercase small text-muted fw-semibold">Global Lookup</div>
                            <h4 class="mb-2">Search the system</h4>
                            <p class="text-muted mb-0">Find purchase orders, receivings, RIS records, distributions, assets, employees, offices, suppliers, and stock catalog items.</p>
                        </div>
                        <form class="d-flex gap-2" method="get" action="<?php echo base_url('modules/search/index.php'); ?>">
                            <input type="search" class="form-control" name="q" value="<?php echo h($query); ?>" placeholder="Search property no., PO, RIS, employee..." autofocus>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                        </form>
                    </div>

                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-warning"><?php echo h($error); ?></div>
                    <?php endforeach; ?>

                    <?php if ($query === ''): ?>
                        <div class="alert alert-info">Enter a keyword above or use the search box in the top bar.</div>
                        <div class="border rounded-3 p-3">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                                <div>
                                    <div class="text-uppercase small text-muted fw-semibold">Saved Patterns</div>
                                    <h5 class="mb-0">Common lookup starters</h5>
                                </div>
                            </div>
                            <div class="row g-2">
                                <?php foreach ($savedSearches as $savedSearch): ?>
                                    <div class="col-sm-6 col-lg-4">
                                        <a class="btn btn-outline-secondary w-100 text-start" href="<?php echo base_url('modules/search/index.php?q=' . urlencode($savedSearch['query'])); ?>">
                                            <i class="bi <?php echo h($savedSearch['icon']); ?> me-2"></i><?php echo h($savedSearch['label']); ?>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php elseif (!$errors): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="fw-semibold">Results for "<?php echo h($query); ?>"</div>
                            <span class="badge text-bg-primary"><?php echo h((string) $resultCount); ?> found</span>
                        </div>

                        <?php if ($results): ?>
                            <div class="vstack gap-4">
                                <?php foreach ($results as $group => $rows): ?>
                                    <div>
                                        <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-2">
                                            <h5 class="mb-0"><?php echo h($group); ?></h5>
                                            <span class="text-muted small"><?php echo h((string) count($rows)); ?> result(s)</span>
                                        </div>
                                        <div class="list-group">
                                            <?php foreach ($rows as $row): ?>
                                                <a class="list-group-item list-group-item-action" href="<?php echo base_url((string) ($row['href'] ?? '#')); ?>">
                                                    <div class="d-flex w-100 justify-content-between gap-3">
                                                        <div>
                                                            <div class="fw-semibold"><?php echo h((string) ($row['title'] ?? 'Untitled')); ?></div>
                                                            <div class="small text-muted"><?php echo h((string) ($row['subtitle'] ?? '')); ?></div>
                                                        </div>
                                                        <div class="small text-muted text-end flex-shrink-0"><?php echo h((string) ($row['meta'] ?? '')); ?></div>
                                                    </div>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary mb-0">No matching records found.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
