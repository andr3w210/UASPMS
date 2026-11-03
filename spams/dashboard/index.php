<?php
require_once __DIR__ . '/../app/config/init.php';
require_login();

$page_title = 'Dashboard';
$db = db();
$displayName = trim((string) ($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));
$roleName = trim((string) ($_SESSION['role_name'] ?? $_SESSION['user_role'] ?? 'User'));
$isAdministrator = $roleName === 'Administrator';
$summary = [
    'active_pos' => 0,
    'pending_receivings' => 0,
    'pending_distribution_units' => 0,
    'distributed_items' => 0,
    'disposed_this_year' => 0,
    'returned_this_year' => 0,
    'open_inventory_counts' => 0,
    'unresolved_property_discrepancies' => 0,
    'open_supply_counts' => 0,
    'pending_stock_adjustments' => 0,
    'unserviceable_review_items' => 0,
    'missing_qr_tags' => 0,
];
$recentPurchaseOrders = [];
$recentDistributions = [];
$lowStockItems = [];
$procurementStatusMix = [];
$movementMonthlyTrend = [];
$assetLifecycleMix = [];
$topAccountableOffices = [];
$inventoryExceptionMix = [];
$stockRiskSummary = [
    'total_supply_items' => 0,
    'low_stock_items' => 0,
    'zero_stock_items' => 0,
    'supply_on_hand' => 0,
];
$lowStockThreshold = defined('LOW_STOCK_THRESHOLD') ? max(0, (int) LOW_STOCK_THRESHOLD) : 5;

if ($db) {
    $currentYear = (int) date('Y');
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
    $fetchRows = static function (mysqli $connection, string $sql, string $types = '', array $params = []): array {
        try {
            $stmt = $connection->prepare($sql);
        } catch (mysqli_sql_exception $exception) {
            $stmt = false;
        }

        if (!$stmt) {
            return [];
        }

        if ($types !== '' && $params) {
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
    $receivingDetailHasDisposedFlag = false;
    $receivingDetailDisposedCondition = '1 = 1';
    $receivingDetailDisposedColumnResult = $db->query("SHOW COLUMNS FROM receiving_item_details LIKE 'is_disposed'");
    if ($receivingDetailDisposedColumnResult instanceof mysqli_result) {
        $receivingDetailHasDisposedFlag = $receivingDetailDisposedColumnResult->num_rows > 0;
        $receivingDetailDisposedColumnResult->close();
    }
    if ($receivingDetailHasDisposedFlag) {
        $receivingDetailDisposedCondition = 'COALESCE(rid.is_disposed, 0) = 0';
    }

    $queries = [
        'active_pos' => [
            'tables' => ['purchase_orders'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM purchase_orders
                WHERE status != 'cancelled'
            ",
        ],
        'pending_receivings' => [
            'tables' => ['purchase_orders', 'purchase_order_items', 'receiving_items', 'receivings'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM (
                    SELECT po.id
                    FROM purchase_orders po
                    LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                    LEFT JOIN (
                        SELECT
                            poi2.purchase_order_id,
                            SUM(COALESCE(ri2.quantity_accepted, 0)) AS total_received_qty
                        FROM receiving_items ri2
                        INNER JOIN receivings r2 ON r2.id = ri2.receiving_id
                        INNER JOIN purchase_order_items poi2 ON poi2.id = ri2.purchase_order_item_id
                        WHERE r2.status != 'cancelled'
                        GROUP BY poi2.purchase_order_id
                    ) received_totals ON received_totals.purchase_order_id = po.id
                    WHERE po.status != 'cancelled'
                    GROUP BY po.id
                    HAVING COALESCE(SUM(poi.quantity), 0) > COALESCE(MAX(received_totals.total_received_qty), 0)
                ) pending_receiving_rows
            ",
        ],
        'distributed_items' => [
            'tables' => ['distribution_item_details'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM distribution_item_details
                WHERE is_distributed = 1
                  AND is_disposed = 0
            ",
        ],
        'pending_distribution_units' => [
            'tables' => ['receiving_item_details', 'receiving_items', 'receivings', 'purchase_order_items'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM receiving_item_details rid
                INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id
                INNER JOIN receivings r ON r.id = ri.receiving_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                WHERE r.status != 'cancelled'
                  AND poi.item_type IN ('semi_expendable', 'equipment')
                  AND rid.is_distributed = 0
                  AND {$receivingDetailDisposedCondition}
            ",
        ],
        'disposed_this_year' => [
            'tables' => ['disposals'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM disposals
                WHERE status = 'posted'
                  AND YEAR(disposal_date) = ?
            ",
        ],
        'returned_this_year' => [
            'tables' => ['returns'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM returns
                WHERE status = 'posted'
                  AND YEAR(return_date) = ?
            ",
        ],
        'open_inventory_counts' => [
            'tables' => ['inventory_count_sessions'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM inventory_count_sessions
                WHERE status = 'open'
            ",
        ],
        'unresolved_property_discrepancies' => [
            'tables' => ['inventory_count_items'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM inventory_count_items
                WHERE status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable')
                  AND resolution_status = 'unresolved'
            ",
        ],
        'open_supply_counts' => [
            'tables' => ['supply_count_sessions'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM supply_count_sessions
                WHERE status = 'open'
            ",
        ],
        'pending_stock_adjustments' => [
            'tables' => ['stock_adjustments'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM stock_adjustments
                WHERE status = 'pending'
            ",
        ],
        'unserviceable_review_items' => [
            'tables' => ['inventory_count_items'],
            'sql' => "
                SELECT COUNT(*) AS total
                FROM inventory_count_items
                WHERE status IN ('for_repair', 'for_disposal')
            ",
        ],
    ];

    foreach ($queries as $key => $queryConfig) {
        $requiredTables = $queryConfig['tables'] ?? [];
        $canRunQuery = true;
        foreach ($requiredTables as $requiredTable) {
            if (!$tableExists($db, $requiredTable)) {
                $canRunQuery = false;
                break;
            }
        }
        if (!$canRunQuery) {
            continue;
        }

        try {
            $stmt = $db->prepare($queryConfig['sql']);
        } catch (mysqli_sql_exception $exception) {
            $stmt = false;
        }

        if ($stmt) {
            if (in_array($key, ['disposed_this_year', 'returned_this_year'], true)) {
                $stmt->bind_param('i', $currentYear);
            }
            try {
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $summary[$key] = (int) ($row['total'] ?? 0);
            } catch (mysqli_sql_exception $exception) {
                $summary[$key] = 0;
            } finally {
                $stmt->close();
            }
        }
    }

    $poStmt = $db->prepare("
        SELECT po.po_number, po.po_date, po.status, s.supplier_name
        FROM purchase_orders po
        LEFT JOIN suppliers s ON s.id = po.supplier_id
        ORDER BY po.po_date DESC, po.id DESC
        LIMIT 5
    ");
    if ($poStmt) {
        $poStmt->execute();
        $recentPurchaseOrders = $poStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $poStmt->close();
    }

    $distributionStmt = $db->prepare("
        SELECT d.document_no, d.document_type, d.distribution_date,
               o.office_name,
               e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM distributions d
        LEFT JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        ORDER BY d.distribution_date DESC, d.id DESC
        LIMIT 5
    ");
    if ($distributionStmt) {
        $distributionStmt->execute();
        $recentDistributions = $distributionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $distributionStmt->close();
    }

    if ($tableExists($db, 'stock_items') && $tableExists($db, 'stock_catalog') && $tableExists($db, 'classifications')) {
        try {
            $lowStockStmt = $db->prepare("
                SELECT
                    COALESCE(sc.stock_no, si.system_reference) AS stock_no,
                    COALESCE(sc.item_name, si.item_description) AS item_name,
                    COALESCE(c.classification_name, '') AS classification_name,
                    SUM(si.quantity_on_hand) AS quantity_on_hand
                FROM stock_items si
                LEFT JOIN stock_catalog sc ON sc.id = si.stock_catalog_id
                LEFT JOIN classifications c ON c.id = COALESCE(sc.classification_id, si.classification_id)
                WHERE si.item_type = 'supply'
                GROUP BY
                    COALESCE(sc.id, 0),
                    COALESCE(sc.stock_no, si.system_reference),
                    COALESCE(sc.item_name, si.item_description),
                    COALESCE(c.classification_name, '')
                HAVING SUM(si.quantity_on_hand) <= ?
                ORDER BY SUM(si.quantity_on_hand) ASC, COALESCE(sc.item_name, si.item_description) ASC
                LIMIT 5
            ");
        } catch (mysqli_sql_exception $exception) {
            $lowStockStmt = false;
        }
        if ($lowStockStmt) {
            $lowStockStmt->bind_param('i', $lowStockThreshold);
            try {
                $lowStockStmt->execute();
                $lowStockItems = $lowStockStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            } catch (mysqli_sql_exception $exception) {
                $lowStockItems = [];
            } finally {
                $lowStockStmt->close();
            }
        }
    }

    if ($tableExists($db, 'purchase_orders')) {
        $procurementStatusMix = $fetchRows($db, "
            SELECT status AS label, COUNT(*) AS total
            FROM purchase_orders
            GROUP BY status
            ORDER BY total DESC, status ASC
        ");
    }

    if ($tableExists($db, 'distributions') && $tableExists($db, 'returns') && $tableExists($db, 'disposals')) {
        $movementMonthlyTrend = $fetchRows($db, "
            SELECT month_key, month_label, SUM(distributed) AS distributed, SUM(returned) AS returned, SUM(disposed) AS disposed
            FROM (
                SELECT DATE_FORMAT(distribution_date, '%Y-%m') AS month_key,
                       DATE_FORMAT(distribution_date, '%b %Y') AS month_label,
                       COUNT(*) AS distributed,
                       0 AS returned,
                       0 AS disposed
                FROM distributions
                WHERE distribution_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
                  AND status != 'cancelled'
                GROUP BY DATE_FORMAT(distribution_date, '%Y-%m'), DATE_FORMAT(distribution_date, '%b %Y')
                UNION ALL
                SELECT DATE_FORMAT(return_date, '%Y-%m') AS month_key,
                       DATE_FORMAT(return_date, '%b %Y') AS month_label,
                       0 AS distributed,
                       COUNT(*) AS returned,
                       0 AS disposed
                FROM returns
                WHERE return_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
                  AND status != 'cancelled'
                GROUP BY DATE_FORMAT(return_date, '%Y-%m'), DATE_FORMAT(return_date, '%b %Y')
                UNION ALL
                SELECT DATE_FORMAT(disposal_date, '%Y-%m') AS month_key,
                       DATE_FORMAT(disposal_date, '%b %Y') AS month_label,
                       0 AS distributed,
                       0 AS returned,
                       COUNT(*) AS disposed
                FROM disposals
                WHERE disposal_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
                  AND status != 'cancelled'
                GROUP BY DATE_FORMAT(disposal_date, '%Y-%m'), DATE_FORMAT(disposal_date, '%b %Y')
            ) movement
            GROUP BY month_key, month_label
            ORDER BY month_key ASC
        ");
    }

    if ($tableExists($db, 'distribution_item_details')) {
        $assetLifecycleMix = $fetchRows($db, "
            SELECT lifecycle_status AS label, COUNT(*) AS total
            FROM (
                SELECT
                    CASE
                        WHEN COALESCE(is_disposed, 0) = 1 THEN 'Disposed or Returned'
                        WHEN COALESCE(is_distributed, 0) = 1 THEN 'In Accountability'
                        ELSE 'Available'
                    END AS lifecycle_status
                FROM distribution_item_details
            ) lifecycle_rows
            GROUP BY lifecycle_status
            ORDER BY FIELD(lifecycle_status, 'In Accountability', 'Available', 'Disposed or Returned'), lifecycle_status
        ");
    }

    if ($tableExists($db, 'distribution_item_details') && $tableExists($db, 'distribution_items') && $tableExists($db, 'distributions') && $tableExists($db, 'offices')) {
        $topAccountableOffices = $fetchRows($db, "
            SELECT COALESCE(o.office_name, 'Unassigned') AS office_name,
                   COUNT(did.id) AS total_assets,
                   COALESCE(SUM(di.unit_cost), 0) AS total_value
            FROM distribution_item_details did
            INNER JOIN distribution_items di ON di.id = did.distribution_item_id
            INNER JOIN distributions d ON d.id = di.distribution_id
            LEFT JOIN offices o ON o.id = d.office_id
            WHERE COALESCE(did.is_distributed, 0) = 1
              AND COALESCE(did.is_disposed, 0) = 0
              AND d.status != 'cancelled'
            GROUP BY o.id, o.office_name
            ORDER BY total_assets DESC, total_value DESC, office_name ASC
            LIMIT 5
        ");
    }

    if ($tableExists($db, 'inventory_count_items')) {
        $inventoryExceptionMix = $fetchRows($db, "
            SELECT status AS label, COUNT(*) AS total
            FROM inventory_count_items
            WHERE status != 'found'
            GROUP BY status
            ORDER BY total DESC, status ASC
        ");
    }

    if ($tableExists($db, 'stock_items')) {
        $stockRows = $fetchRows($db, "
            SELECT
                COUNT(*) AS total_supply_items,
                SUM(CASE WHEN quantity_on_hand <= ? THEN 1 ELSE 0 END) AS low_stock_items,
                SUM(CASE WHEN quantity_on_hand <= 0 THEN 1 ELSE 0 END) AS zero_stock_items,
                SUM(quantity_on_hand) AS supply_on_hand
            FROM stock_items
            WHERE item_type = 'supply'
        ", 'i', [$lowStockThreshold]);
        if ($stockRows) {
            $stockRiskSummary = [
                'total_supply_items' => (int) ($stockRows[0]['total_supply_items'] ?? 0),
                'low_stock_items' => (int) ($stockRows[0]['low_stock_items'] ?? 0),
                'zero_stock_items' => (int) ($stockRows[0]['zero_stock_items'] ?? 0),
                'supply_on_hand' => (float) ($stockRows[0]['supply_on_hand'] ?? 0),
            ];
        }
    }

    if ($tableExists($db, 'distribution_item_details') && $columnExists($db, 'distribution_item_details', 'qr_tag_code')) {
        $missingQrRows = $fetchRows($db, "
            SELECT COUNT(*) AS total
            FROM distribution_item_details
            WHERE COALESCE(is_distributed, 0) = 1
              AND COALESCE(is_disposed, 0) = 0
              AND (qr_tag_code IS NULL OR TRIM(qr_tag_code) = '')
        ");
        if ($missingQrRows) {
            $summary['missing_qr_tags'] = (int) ($missingQrRows[0]['total'] ?? 0);
        }
    }
}

$inventoryCards = [
    [
        'label' => 'Open Inventory Counts',
        'value' => $summary['open_inventory_counts'],
        'note' => 'Property count sessions still in progress',
        'icon' => 'bi-clipboard-check',
        'tone' => 'info',
        'href' => base_url('modules/property/inventory_counts.php'),
        'cta' => 'Open Counts',
    ],
    [
        'label' => 'Pending Stock Adjustments',
        'value' => $summary['pending_stock_adjustments'],
        'note' => 'Supply adjustments waiting for approval',
        'icon' => 'bi-sliders2-vertical',
        'tone' => 'danger',
        'href' => base_url('modules/property/stock_adjustments.php'),
        'cta' => 'Review Adjustments',
    ],
    [
        'label' => 'Property Discrepancies',
        'value' => $summary['unresolved_property_discrepancies'],
        'note' => 'Count exceptions still unresolved',
        'icon' => 'bi-exclamation-octagon',
        'tone' => 'warning',
        'href' => base_url('modules/property/inventory_reconciliation.php?resolution=unresolved'),
        'cta' => 'Resolve Items',
    ],
];

$stockRiskRate = $stockRiskSummary['total_supply_items'] > 0
    ? round(($stockRiskSummary['low_stock_items'] / $stockRiskSummary['total_supply_items']) * 100)
    : 0;
$controlExceptionTotal = array_sum(array_map(static fn ($row): int => (int) ($row['total'] ?? 0), $inventoryExceptionMix));
$movementTrendPeak = 0;
foreach ($movementMonthlyTrend as $row) {
    $movementTrendPeak = max($movementTrendPeak, (int) ($row['distributed'] ?? 0) + (int) ($row['returned'] ?? 0) + (int) ($row['disposed'] ?? 0));
}
$procurementStatusTotal = array_sum(array_map(static fn ($row): int => (int) ($row['total'] ?? 0), $procurementStatusMix));
$assetLifecycleTotal = array_sum(array_map(static fn ($row): int => (int) ($row['total'] ?? 0), $assetLifecycleMix));
$topOfficePeak = 0;
foreach ($topAccountableOffices as $row) {
    $topOfficePeak = max($topOfficePeak, (int) ($row['total_assets'] ?? 0));
}
$workQueueCards = [
    [
        'label' => 'POs Awaiting Receiving',
        'value' => $summary['pending_receivings'],
        'note' => 'Purchase orders with remaining quantities to receive',
        'icon' => 'bi-inbox',
        'tone' => 'warning',
        'href' => base_url('modules/receivings/index.php'),
        'cta' => 'Receive Items',
    ],
    [
        'label' => 'Received Units For Distribution',
        'value' => $summary['pending_distribution_units'],
        'note' => 'Equipment and semi-expendable units not yet issued',
        'icon' => 'bi-send-check',
        'tone' => 'primary',
        'href' => base_url('modules/distributions/index.php'),
        'cta' => 'Open Distribution',
    ],
    [
        'label' => 'QR Tags Needed',
        'value' => $summary['missing_qr_tags'],
        'note' => 'Active accountable assets without generated QR tag codes',
        'icon' => 'bi-qr-code',
        'tone' => 'info',
        'href' => base_url('modules/reports/qr_printing.php'),
        'cta' => 'Print Tags',
    ],
    [
        'label' => 'Inventory Discrepancies',
        'value' => $summary['unresolved_property_discrepancies'],
        'note' => 'Missing, wrong office, repair, or disposal findings',
        'icon' => 'bi-exclamation-triangle',
        'tone' => 'danger',
        'href' => base_url('modules/property/inventory_reconciliation.php?resolution=unresolved'),
        'cta' => 'Reconcile',
    ],
    [
        'label' => 'Low Stock Supplies',
        'value' => $stockRiskSummary['low_stock_items'],
        'note' => 'Supply stock items at or below threshold',
        'icon' => 'bi-thermometer-low',
        'tone' => 'warning',
        'href' => base_url('modules/stock_catalog/index.php'),
        'cta' => 'Review Stock',
    ],
    [
        'label' => 'Unserviceable Items',
        'value' => $summary['unserviceable_review_items'],
        'note' => 'Assets flagged for repair or disposal follow-up',
        'icon' => 'bi-tools',
        'tone' => 'dark',
        'href' => base_url('modules/property/unserviceable_review.php'),
        'cta' => 'Review Items',
    ],
];
$movementCards = [
    [
        'label' => 'Distributed Assets',
        'value' => $summary['distributed_items'],
        'note' => 'Units already assigned and in circulation',
        'icon' => 'bi-diagram-3',
        'tone' => 'success',
        'href' => base_url('modules/property/index.php'),
        'cta' => 'Open Registry',
    ],
    [
        'label' => 'Disposed This Year',
        'value' => $summary['disposed_this_year'],
        'note' => 'Posted disposal transactions for ' . date('Y'),
        'icon' => 'bi-trash3',
        'tone' => 'danger',
        'href' => base_url('modules/disposals/index.php'),
        'cta' => 'Review Disposals',
    ],
    [
        'label' => 'Returned This Year',
        'value' => $summary['returned_this_year'],
        'note' => 'Posted return transactions for ' . date('Y'),
        'icon' => 'bi-arrow-counterclockwise',
        'tone' => 'info',
        'href' => base_url('modules/returns/index.php'),
        'cta' => 'Review Returns',
    ],
];
$analyticsCards = [
    [
        'label' => 'Low Stock Rate',
        'value' => $stockRiskRate . '%',
        'note' => number_format($stockRiskSummary['low_stock_items']) . ' of ' . number_format($stockRiskSummary['total_supply_items']) . ' supply items at or below threshold',
        'icon' => 'bi-activity',
        'tone' => $stockRiskRate > 0 ? 'warning' : 'success',
        'href' => base_url('modules/stock_catalog/index.php'),
        'cta' => 'Open Catalog',
    ],
    [
        'label' => 'Control Exceptions',
        'value' => $controlExceptionTotal,
        'note' => 'Inventory count lines that are not marked found',
        'icon' => 'bi-clipboard-pulse',
        'tone' => $controlExceptionTotal > 0 ? 'danger' : 'success',
        'href' => base_url('modules/property/inventory_reconciliation.php'),
        'cta' => 'Open Reconciliation',
    ],
    [
        'label' => 'Supply On Hand',
        'value' => format_quantity($stockRiskSummary['supply_on_hand']),
        'note' => 'Combined quantity on hand across supply stock items',
        'icon' => 'bi-boxes',
        'tone' => 'info',
        'href' => base_url('modules/stock_catalog/index.php'),
        'cta' => 'Review Stock',
    ],
];
$quickLinks = [
    ['label' => 'Registry', 'href' => base_url('modules/property/index.php'), 'icon' => 'bi-grid-1x2'],
    ['label' => 'Receive Items', 'href' => base_url('modules/receivings/index.php'), 'icon' => 'bi-box-arrow-in-down'],
    ['label' => 'Distribution', 'href' => base_url('modules/distributions/index.php'), 'icon' => 'bi-send-check'],
    ['label' => 'Inventory Counts', 'href' => base_url('modules/property/inventory_counts.php'), 'icon' => 'bi-clipboard-check'],
    ['label' => 'Reports', 'href' => base_url('modules/reports/index.php'), 'icon' => 'bi-bar-chart-line'],
];
if ($isAdministrator) {
    $quickLinks[] = ['label' => 'Audit Log', 'href' => base_url('modules/audit_log/index.php'), 'icon' => 'bi-shield-check'];
}

$cardVisibleForRole = static function (array $item) use ($roleName): bool {
    $href = (string) ($item['href'] ?? '');
    if ($href === '') {
        return true;
    }

    $rules = [
        '/modules/receivings/' => ['Administrator', 'Supply Officer'],
        '/modules/issuances/' => ['Administrator', 'Supply Officer'],
        '/modules/distributions/' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/transfers/' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/returns/' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/disposals/' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/reports/qr_printing.php' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/property/inventory_counts.php' => ['Administrator', 'Supply Officer', 'Property Officer'],
        '/modules/property/inventory_reconciliation.php' => ['Administrator', 'Property Officer'],
        '/modules/property/unserviceable_review.php' => ['Administrator', 'Property Officer'],
        '/modules/property/stock_card.php' => ['Administrator', 'Supply Officer'],
        '/modules/property/supply_counts.php' => ['Administrator', 'Supply Officer'],
        '/modules/property/stock_adjustments.php' => ['Administrator', 'Supply Officer'],
        '/modules/suppliers/monitoring_calendar.php' => ['Administrator', 'Supply Officer'],
        '/modules/stock_catalog/' => ['Administrator'],
        '/modules/audit_log/' => ['Administrator'],
    ];

    foreach ($rules as $needle => $roles) {
        if (str_contains($href, $needle)) {
            return in_array($roleName, $roles, true);
        }
    }

    return true;
};

$workQueueCards = array_values(array_filter($workQueueCards, $cardVisibleForRole));
$inventoryCards = array_values(array_filter($inventoryCards, $cardVisibleForRole));
$movementCards = array_values(array_filter($movementCards, $cardVisibleForRole));
$analyticsCards = array_values(array_filter($analyticsCards, $cardVisibleForRole));
$quickLinks = array_values(array_filter($quickLinks, $cardVisibleForRole));
$activeQueueCount = count(array_filter($workQueueCards, static fn (array $item): bool => (int) ($item['value'] ?? 0) > 0));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>
<section class="dashboard-v2 container-fluid px-0">
    <header class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3 mb-4">
        <div>
            <div class="small text-uppercase fw-semibold text-primary mb-1"><?php echo h($roleName); ?> workspace</div>
            <h1 class="h2 fw-bold mb-1">Welcome back, <?php echo h($displayName); ?></h1>
            <p class="text-secondary mb-0"><?php echo h(date('l, F j, Y')); ?> <span class="mx-1">·</span> Your daily operations at a glance.</p>
        </div>
        <nav class="d-flex flex-wrap gap-2" aria-label="Dashboard shortcuts">
            <?php foreach ($quickLinks as $index => $link): ?>
                <a class="btn <?php echo $index === 0 ? 'btn-primary' : 'btn-outline-primary'; ?> btn-sm" href="<?php echo h($link['href']); ?>">
                    <i class="bi <?php echo h($link['icon']); ?> me-1" aria-hidden="true"></i><?php echo h($link['label']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </header>

    <nav class="nav nav-pills gap-2 mb-4" aria-label="Dashboard sections">
        <a class="nav-link active" href="#today">Today</a>
        <a class="nav-link" href="#inventory">Inventory</a>
        <a class="nav-link" href="#movement">Movement</a>
        <a class="nav-link" href="#analytics">Analytics</a>
    </nav>

    <section id="today" class="dashboard-v2-section mb-4" aria-labelledby="today-heading">
        <h2 id="today-heading" class="visually-hidden">Today</h2>
        <div class="row g-3 mb-4">
            <?php
                $overviewMetrics = [
                    ['label' => 'Awaiting receiving', 'value' => $summary['pending_receivings'], 'icon' => 'bi-inbox', 'tone' => 'warning', 'href' => base_url('modules/receivings/index.php')],
                    ['label' => 'Ready to distribute', 'value' => $summary['pending_distribution_units'], 'icon' => 'bi-send-check', 'tone' => 'primary', 'href' => base_url('modules/distributions/index.php')],
                    ['label' => 'Assets in circulation', 'value' => $summary['distributed_items'], 'icon' => 'bi-boxes', 'tone' => 'success', 'href' => base_url('modules/property/index.php')],
                    ['label' => 'Unresolved discrepancies', 'value' => $summary['unresolved_property_discrepancies'], 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger', 'href' => base_url('modules/property/inventory_reconciliation.php?resolution=unresolved')],
                ];
                $metricTextClass = ['warning' => 'text-warning-emphasis', 'primary' => 'text-primary', 'success' => 'text-success', 'danger' => 'text-danger'];
            ?>
            <?php foreach ($overviewMetrics as $metric): ?>
                <?php if ($cardVisibleForRole(['href' => $metric['href']])): ?>
                    <div class="col-12 col-sm-6 col-xxl-3">
                        <a href="<?php echo h($metric['href']); ?>" class="card h-100 text-decoration-none border-0 shadow-sm dashboard-v2-metric">
                            <div class="card-body d-flex align-items-center gap-3">
                                <span class="rounded-3 bg-body-tertiary p-3 <?php echo $metricTextClass[$metric['tone']]; ?>">
                                    <i class="bi <?php echo h($metric['icon']); ?> fs-4" aria-hidden="true"></i>
                                </span>
                                <span>
                                    <span class="d-block small text-secondary"><?php echo h($metric['label']); ?></span>
                                    <strong class="d-block fs-3 text-body lh-1 mt-1"><?php echo number_format((int) $metric['value']); ?></strong>
                                </span>
                                <i class="bi bi-arrow-up-right ms-auto text-secondary" aria-hidden="true"></i>
                            </div>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-7">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-start gap-3">
                        <div>
                            <h2 class="h5 fw-bold mb-1">Work queue</h2>
                            <p class="small text-secondary mb-0">Open the items that need follow-up.</p>
                        </div>
                        <span class="badge rounded-pill <?php echo $activeQueueCount > 0 ? 'text-bg-warning' : 'text-bg-success'; ?>">
                            <?php echo number_format($activeQueueCount); ?> active queues
                        </span>
                    </div>
                    <div class="card-body px-4 pt-2">
                        <?php if ($workQueueCards): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($workQueueCards as $item): ?>
                                    <?php $toneClass = ['warning' => 'text-warning-emphasis', 'primary' => 'text-primary', 'info' => 'text-info-emphasis', 'danger' => 'text-danger', 'dark' => 'text-secondary'][$item['tone']] ?? 'text-primary'; ?>
                                    <a href="<?php echo h($item['href']); ?>" class="list-group-item list-group-item-action px-0 py-3 d-flex align-items-center gap-3">
                                        <span class="rounded-3 bg-body-tertiary p-2 <?php echo $toneClass; ?>"><i class="bi <?php echo h($item['icon']); ?> fs-5" aria-hidden="true"></i></span>
                                        <span class="flex-grow-1 min-w-0">
                                            <span class="d-block fw-semibold text-body"><?php echo h($item['label']); ?></span>
                                            <span class="d-block small text-secondary text-truncate"><?php echo h($item['note']); ?></span>
                                        </span>
                                        <span class="badge rounded-pill text-bg-light border text-dark fs-6"><?php echo number_format((int) $item['value']); ?></span>
                                        <i class="bi bi-chevron-right small text-secondary" aria-hidden="true"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>No pending work is assigned to your role.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <div class="col-12 col-xl-5">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 fw-bold mb-1">Recent purchase orders</h2>
                            <p class="small text-secondary mb-0">Latest procurement activity.</p>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/purchase_orders/index.php'); ?>">All orders</a>
                    </div>
                    <div class="card-body px-4 pt-2">
                        <?php if ($recentPurchaseOrders): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($recentPurchaseOrders as $po): ?>
                                    <?php
                                        $poStatus = (string) ($po['status'] ?? 'pending');
                                        $poBadge = $poStatus === 'completed' ? 'text-bg-success' : ($poStatus === 'cancelled' ? 'text-bg-secondary' : 'text-bg-warning');
                                    ?>
                                    <div class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center gap-3">
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-truncate"><?php echo h((string) ($po['po_number'] ?? 'No PO Number')); ?></div>
                                            <div class="small text-secondary text-truncate"><?php echo h((string) ($po['supplier_name'] ?? 'No supplier')); ?>
                                                <span class="mx-1">·</span><?php echo !empty($po['po_date']) ? h(date('M d, Y', strtotime($po['po_date']))) : 'No date'; ?>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo $poBadge; ?>"><?php echo h(ucfirst($poStatus)); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-secondary mb-0 py-3">No purchase orders yet.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="inventory" class="dashboard-v2-section mb-4" aria-labelledby="inventory-heading">
        <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
            <div>
                <div class="small text-uppercase fw-semibold text-primary">Stock and accountability</div>
                <h2 id="inventory-heading" class="h4 fw-bold mb-0">Inventory overview</h2>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/property/index.php'); ?>">Open registry</a>
        </div>
        <div class="row g-3">
            <div class="col-12 col-lg-7">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Inventory controls</h3>
                        <p class="small text-secondary mb-0">Counts, exceptions, and supply approvals.</p>
                    </div>
                    <div class="card-body px-4 pt-2">
                        <div class="list-group list-group-flush">
                            <?php foreach ($inventoryCards as $item): ?>
                                <a href="<?php echo h($item['href']); ?>" class="list-group-item list-group-item-action px-0 py-3 d-flex align-items-center gap-3">
                                    <i class="bi <?php echo h($item['icon']); ?> fs-5 text-primary" aria-hidden="true"></i>
                                    <span class="flex-grow-1">
                                        <span class="d-block fw-semibold"><?php echo h($item['label']); ?></span>
                                        <span class="d-block small text-secondary"><?php echo h($item['note']); ?></span>
                                    </span>
                                    <span class="badge text-bg-light border text-dark fs-6"><?php echo number_format((int) $item['value']); ?></span>
                                    <i class="bi bi-chevron-right small text-secondary" aria-hidden="true"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-12 col-lg-5">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-start gap-2">
                        <div>
                            <h3 class="h6 fw-bold mb-1">Low stock</h3>
                            <p class="small text-secondary mb-0">Items at or below <?php echo number_format($lowStockThreshold); ?> on hand.</p>
                        </div>
                        <?php if ($cardVisibleForRole(['href' => base_url('modules/stock_catalog/index.php')])): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/stock_catalog/index.php'); ?>">Catalog</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body px-4 pt-2">
                        <?php if ($lowStockItems): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($lowStockItems as $item): ?>
                                    <div class="list-group-item px-0 py-3 d-flex justify-content-between align-items-center gap-3">
                                        <div class="min-w-0">
                                            <div class="fw-semibold text-truncate"><?php echo h((string) ($item['item_name'] ?? 'Supply item')); ?></div>
                                            <div class="small text-secondary text-truncate"><?php echo h((string) ($item['stock_no'] ?? '')); ?></div>
                                        </div>
                                        <span class="badge <?php echo (float) ($item['quantity_on_hand'] ?? 0) <= 0 ? 'text-bg-danger' : 'text-bg-warning'; ?>"><?php echo h(format_quantity($item['quantity_on_hand'] ?? 0)); ?> on hand</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0"><i class="bi bi-check-circle me-2"></i>No supplies are below the stock threshold.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
        <div class="row g-3 mt-1">
            <div class="col-12 col-lg-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Asset lifecycle</h3>
                        <p class="small text-secondary mb-0">Current accountability status.</p>
                    </div>
                    <div class="card-body px-4">
                        <?php if ($assetLifecycleMix): ?>
                            <?php foreach ($assetLifecycleMix as $row): ?>
                                <?php $total = (int) ($row['total'] ?? 0); $percent = $assetLifecycleTotal > 0 ? (int) round(($total / $assetLifecycleTotal) * 100) : 0; ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1"><span><?php echo h((string) ($row['label'] ?? 'Unknown')); ?></span><strong><?php echo number_format($total); ?></strong></div>
                                    <?php $lifecycleLabel = (string) ($row['label'] ?? 'Unknown'); ?>
                                    <div class="progress" role="img" aria-label="<?php echo h($lifecycleLabel); ?>: <?php echo $percent; ?> percent"><div class="progress-bar <?php echo $lifecycleLabel === 'In Accountability' ? 'bg-primary' : ($lifecycleLabel === 'Available' ? 'bg-success' : 'bg-secondary'); ?>" style="width: <?php echo $percent; ?>%"></div></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-secondary mb-0">No asset lifecycle data available.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <div class="col-12 col-lg-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Top accountable offices</h3>
                        <p class="small text-secondary mb-0">Offices with the most assigned active assets.</p>
                    </div>
                    <div class="card-body px-4">
                        <?php if ($topAccountableOffices): ?>
                            <?php foreach ($topAccountableOffices as $row): ?>
                                <?php $totalAssets = (int) ($row['total_assets'] ?? 0); $percent = $topOfficePeak > 0 ? (int) round(($totalAssets / $topOfficePeak) * 100) : 0; ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1 gap-2"><span class="text-truncate"><?php echo h((string) ($row['office_name'] ?? 'Unassigned')); ?></span><strong><?php echo number_format($totalAssets); ?></strong></div>
                                    <div class="progress" role="img" aria-label="<?php echo h((string) ($row['office_name'] ?? 'Unassigned')); ?>: <?php echo number_format($totalAssets); ?> assets"><div class="progress-bar bg-info" style="width: <?php echo $percent; ?>%"></div></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-secondary mb-0">No office accountability data available.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <div class="col-12 col-lg-4">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Inventory findings</h3>
                        <p class="small text-secondary mb-0">Recorded count findings grouped by status.</p>
                    </div>
                    <div class="card-body px-4">
                        <?php if ($inventoryExceptionMix): ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($inventoryExceptionMix as $row): ?>
                                    <div class="list-group-item px-0 py-2 d-flex justify-content-between gap-3">
                                        <span><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['label'] ?? 'Unknown')))); ?></span>
                                        <span class="badge text-bg-warning"><?php echo number_format((int) ($row['total'] ?? 0)); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">No inventory count findings need follow-up.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section id="movement" class="dashboard-v2-section mb-4" aria-labelledby="movement-heading">
        <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
            <div>
                <div class="small text-uppercase fw-semibold text-primary">Recent transactions</div>
                <h2 id="movement-heading" class="h4 fw-bold mb-0">Movement and accountability</h2>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/reports/index.php'); ?>">Reports</a>
        </div>
        <div class="row g-3 mb-3">
            <?php foreach ($movementCards as $item): ?>
                <div class="col-12 col-md-4">
                    <a href="<?php echo h($item['href']); ?>" class="card h-100 border-0 shadow-sm text-decoration-none dashboard-v2-metric">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi <?php echo h($item['icon']); ?> fs-3 text-primary" aria-hidden="true"></i>
                            <span><span class="d-block small text-secondary"><?php echo h($item['label']); ?></span><strong class="fs-4 text-body"><?php echo h((string) $item['value']); ?></strong></span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <article class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-start gap-2">
                <div>
                    <h3 class="h6 fw-bold mb-1">Latest distributions</h3>
                    <p class="small text-secondary mb-0">Most recently recorded property movements.</p>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/distributions/index.php'); ?>">All distributions</a>
            </div>
            <div class="card-body px-4 pt-2">
                <?php if ($recentDistributions): ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th scope="col">Document</th><th scope="col">Office</th><th scope="col">Accountable person</th><th scope="col">Date</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentDistributions as $distribution): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo h((string) ($distribution['document_no'] ?? 'No document')); ?></td>
                                    <td><?php echo h((string) ($distribution['office_name'] ?? 'No office')); ?></td>
                                    <td><?php echo !empty($distribution['employee_no']) ? h(employee_display_name($distribution) . ' · ' . $distribution['employee_no']) : 'Not specified'; ?></td>
                                    <td><?php echo !empty($distribution['distribution_date']) ? h(date('M d, Y', strtotime($distribution['distribution_date']))) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-secondary mb-0 py-3">No distribution records yet.</p>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <section id="analytics" class="dashboard-v2-section mb-5" aria-labelledby="analytics-heading">
        <div class="d-flex justify-content-between align-items-end gap-3 mb-3">
            <div>
                <div class="small text-uppercase fw-semibold text-primary">Trends and indicators</div>
                <h2 id="analytics-heading" class="h4 fw-bold mb-0">Analytics</h2>
            </div>
            <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/reports/index.php'); ?>">Open reports</a>
        </div>
        <div class="row g-3 mb-4">
            <?php foreach ($analyticsCards as $item): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <a href="<?php echo h($item['href']); ?>" class="card h-100 border-0 shadow-sm text-decoration-none dashboard-v2-metric">
                        <div class="card-body">
                            <div class="small text-secondary"><?php echo h($item['label']); ?></div>
                            <div class="d-flex align-items-baseline gap-2 mt-1">
                                <strong class="fs-3 text-body"><?php echo h((string) $item['value']); ?></strong>
                                <i class="bi <?php echo h($item['icon']); ?> text-primary" aria-hidden="true"></i>
                            </div>
                            <div class="small text-secondary mt-2"><?php echo h($item['note']); ?></div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Six-month movement</h3>
                        <p class="small text-secondary mb-0">Distributions, returns, and disposals by month.</p>
                    </div>
                    <div class="card-body px-4">
                        <?php if ($movementMonthlyTrend): ?>
                            <?php foreach ($movementMonthlyTrend as $row): ?>
                                <?php
                                    $distributed = (int) ($row['distributed'] ?? 0);
                                    $returned = (int) ($row['returned'] ?? 0);
                                    $disposed = (int) ($row['disposed'] ?? 0);
                                    $rowTotal = $distributed + $returned + $disposed;
                                    $peak = max(1, $movementTrendPeak);
                                ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1"><span><?php echo h((string) ($row['month_label'] ?? '')); ?></span><strong><?php echo number_format($rowTotal); ?></strong></div>
                                    <div class="progress" role="img" aria-label="<?php echo h((string) ($row['month_label'] ?? '')); ?>: <?php echo number_format($rowTotal); ?> movements">
                                        <div class="progress-bar bg-primary" style="width: <?php echo (int) round(($distributed / $peak) * 100); ?>%" title="Distributed: <?php echo $distributed; ?>"></div>
                                        <div class="progress-bar bg-info" style="width: <?php echo (int) round(($returned / $peak) * 100); ?>%" title="Returned: <?php echo $returned; ?>"></div>
                                        <div class="progress-bar bg-danger" style="width: <?php echo (int) round(($disposed / $peak) * 100); ?>%" title="Disposed: <?php echo $disposed; ?>"></div>
                                    </div>
                                    <div class="small text-secondary mt-1">Distributed <?php echo number_format($distributed); ?> <span class="mx-1">·</span> Returned <?php echo number_format($returned); ?> <span class="mx-1">·</span> Disposed <?php echo number_format($disposed); ?></div>
                                </div>
                            <?php endforeach; ?>
                            <div class="small text-secondary"><span class="badge text-bg-primary me-1"> </span>Distributed <span class="badge text-bg-info ms-2 me-1"> </span>Returned <span class="badge text-bg-danger ms-2 me-1"> </span>Disposed</div>
                        <?php else: ?>
                            <p class="text-secondary mb-0">No posted movement data for the last six months.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
            <div class="col-12 col-xl-5">
                <article class="card h-100 border-0 shadow-sm">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h3 class="h6 fw-bold mb-1">Purchase order status</h3>
                        <p class="small text-secondary mb-0">Current procurement mix.</p>
                    </div>
                    <div class="card-body px-4">
                        <?php if ($procurementStatusMix): ?>
                            <?php foreach ($procurementStatusMix as $row): ?>
                                <?php
                                    $total = (int) ($row['total'] ?? 0);
                                    $percent = $procurementStatusTotal > 0 ? (int) round(($total / $procurementStatusTotal) * 100) : 0;
                                    $statusName = ucwords(str_replace('_', ' ', (string) ($row['label'] ?? 'Unknown')));
                                    $statusTone = strtolower((string) ($row['label'] ?? '')) === 'completed' ? 'bg-success' : (strtolower((string) ($row['label'] ?? '')) === 'cancelled' ? 'bg-secondary' : 'bg-warning');
                                ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between small mb-1"><span><?php echo h($statusName); ?></span><strong><?php echo number_format($total); ?> <span class="text-secondary fw-normal">(<?php echo $percent; ?>%)</span></strong></div>
                                    <div class="progress" role="img" aria-label="<?php echo h($statusName); ?>: <?php echo $percent; ?> percent"><div class="progress-bar <?php echo $statusTone; ?>" style="width: <?php echo $percent; ?>%"></div></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-secondary mb-0">No purchase order data available.</p>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </section>
</section>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
