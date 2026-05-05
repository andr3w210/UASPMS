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

$focusItems = [
    [
        'label' => 'Pending Distribution',
        'value' => $summary['pending_distribution_units'],
        'note' => 'Units waiting for ICS and PAR posting',
        'icon' => 'bi-hourglass-split',
        'tone' => 'warning',
        'href' => base_url('modules/distributions/index.php'),
        'cta' => 'Review Queue',
    ],
    [
        'label' => 'Pending Receivings',
        'value' => $summary['pending_receivings'],
        'note' => 'POs still waiting for complete receiving',
        'icon' => 'bi-box-seam',
        'tone' => 'warning',
        'href' => base_url('modules/receivings/index.php'),
        'cta' => 'Open Receiving',
    ],
    [
        'label' => 'Active Assets',
        'value' => $summary['distributed_items'],
        'note' => 'Equipment and semi assets in circulation',
        'icon' => 'bi-diagram-3',
        'tone' => 'success',
        'href' => base_url('modules/property/index.php'),
        'cta' => 'Open Registry',
    ],
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
];

$snapshotItems = [
    [
        'label' => 'Active POs',
        'value' => $summary['active_pos'],
        'note' => 'Open procurement records',
        'icon' => 'bi-journal-text',
        'tone' => 'primary',
    ],
    [
        'label' => 'Distributed Items',
        'value' => $summary['distributed_items'],
        'note' => 'Current accountable units',
        'icon' => 'bi-diagram-3',
        'tone' => 'success',
    ],
    [
        'label' => 'Disposed This Year',
        'value' => $summary['disposed_this_year'],
        'note' => date('Y') . ' posted disposals',
        'icon' => 'bi-trash3',
        'tone' => 'danger',
    ],
    [
        'label' => 'Returned This Year',
        'value' => $summary['returned_this_year'],
        'note' => date('Y') . ' posted returns',
        'icon' => 'bi-arrow-counterclockwise',
        'tone' => 'info',
    ],
    [
        'label' => 'Property Discrepancies',
        'value' => $summary['unresolved_property_discrepancies'],
        'note' => 'Unresolved count exceptions',
        'icon' => 'bi-exclamation-diamond',
        'tone' => 'warning',
    ],
    [
        'label' => 'Supply Count Sessions',
        'value' => $summary['open_supply_counts'],
        'note' => 'Open supply count workspaces',
        'icon' => 'bi-boxes',
        'tone' => 'secondary',
    ],
    [
        'label' => 'Unserviceable Review',
        'value' => $summary['unserviceable_review_items'],
        'note' => 'Assets flagged for repair or disposal',
        'icon' => 'bi-tools',
        'tone' => 'dark',
    ],
];

$urgentWorkload = $summary['pending_receivings']
    + $summary['pending_distribution_units']
    + $summary['unresolved_property_discrepancies']
    + $summary['pending_stock_adjustments'];
$activityThisYear = $summary['distributed_items'] + $summary['disposed_this_year'] + $summary['returned_this_year'];
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
$commandStatus = $urgentWorkload > 0 ? 'Priority Focus' : 'Steady State';
$commandTone = $urgentWorkload > 0 ? 'warning' : 'success';
$heroMetrics = [
    [
        'label' => 'Urgent workload',
        'value' => $urgentWorkload,
        'note' => 'Receiving, distribution, and control items waiting',
        'icon' => 'bi-lightning-charge',
        'tone' => 'warning',
    ],
    [
        'label' => 'Active procurement',
        'value' => $summary['active_pos'],
        'note' => 'Purchase orders currently in the pipeline',
        'icon' => 'bi-journal-richtext',
        'tone' => 'primary',
    ],
    [
        'label' => 'Movement this year',
        'value' => $activityThisYear,
        'note' => 'Distributed, returned, and disposed records posted',
        'icon' => 'bi-arrow-left-right',
        'tone' => 'success',
    ],
];
$operationsCards = [
    $focusItems[0],
    $focusItems[1],
    [
        'label' => 'Low Stock Items',
        'value' => count($lowStockItems),
        'note' => 'Supply items at or below the threshold',
        'icon' => 'bi-thermometer-low',
        'tone' => 'danger',
        'href' => base_url('modules/stock_catalog/index.php'),
        'cta' => 'Open Stock Catalog',
    ],
];
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
$inventoryCards = [
    $focusItems[3],
    $focusItems[4],
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
    ['label' => 'Distribution', 'href' => base_url('modules/distributions/index.php'), 'icon' => 'bi-send-check'],
    ['label' => 'Receiving', 'href' => base_url('modules/receivings/index.php'), 'icon' => 'bi-box-seam'],
    ['label' => 'Registry', 'href' => base_url('modules/property/index.php'), 'icon' => 'bi-grid-1x2'],
    ['label' => 'Counts', 'href' => base_url('modules/property/inventory_counts.php'), 'icon' => 'bi-clipboard-check'],
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

$operationsCards = array_values(array_filter($operationsCards, $cardVisibleForRole));
$workQueueCards = array_values(array_filter($workQueueCards, $cardVisibleForRole));
$inventoryCards = array_values(array_filter($inventoryCards, $cardVisibleForRole));
$movementCards = array_values(array_filter($movementCards, $cardVisibleForRole));
$analyticsCards = array_values(array_filter($analyticsCards, $cardVisibleForRole));
$quickLinks = array_values(array_filter($quickLinks, $cardVisibleForRole));

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
require_once __DIR__ . '/../includes/topbar.php';
?>
<section class="dashboard-hub" data-dashboard-hub>
    <div class="dashboard-hub-hero">
        <div class="dashboard-hub-hero-main">
            <div class="dashboard-hub-kicker">
                <span><?php echo h($roleName); ?></span>
                <span class="dashboard-hub-dot"></span>
                <span><?php echo h($commandStatus); ?></span>
            </div>
            <h1 class="dashboard-hub-title">Supply and property command center</h1>
            <p class="dashboard-hub-copy">
                Track procurement, receiving, accountability, and inventory controls from one responsive workspace built for both desktop and mobile.
            </p>
            <div class="dashboard-hub-actions">
                <?php foreach ($quickLinks as $link): ?>
                    <a class="dashboard-hub-action" href="<?php echo h($link['href']); ?>">
                        <i class="bi <?php echo h($link['icon']); ?>"></i>
                        <span><?php echo h($link['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="dashboard-hub-metrics">
                <?php foreach ($heroMetrics as $metric): ?>
                    <div class="dashboard-hub-metric">
                        <span class="dashboard-hub-metric-icon tone-<?php echo h($metric['tone']); ?>">
                            <i class="bi <?php echo h($metric['icon']); ?>"></i>
                        </span>
                        <div>
                            <div class="dashboard-hub-metric-label"><?php echo h($metric['label']); ?></div>
                            <div class="dashboard-hub-metric-value"><?php echo h((string) $metric['value']); ?></div>
                            <div class="dashboard-hub-metric-note"><?php echo h($metric['note']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="dashboard-hub-hero-side">
            <div class="dashboard-hub-status-card tone-<?php echo h($commandTone); ?>">
                <div class="dashboard-hub-status-label">Welcome back</div>
                <div class="dashboard-hub-status-name"><?php echo h($displayName); ?></div>
                <div class="dashboard-hub-status-copy">
                    <?php if ($urgentWorkload > 0): ?>
                        You have <?php echo h((string) $urgentWorkload); ?> items that still need active follow-up across receiving, distribution, and controls.
                    <?php else: ?>
                        Your queues are stable right now. This is a good time to review movement history and control quality.
                    <?php endif; ?>
                </div>
                <div class="dashboard-hub-status-pills">
                    <span class="dashboard-hub-pill">Receivings <?php echo number_format($summary['pending_receivings']); ?></span>
                    <span class="dashboard-hub-pill">Distribution <?php echo number_format($summary['pending_distribution_units']); ?></span>
                    <span class="dashboard-hub-pill">Controls <?php echo number_format($summary['unresolved_property_discrepancies'] + $summary['pending_stock_adjustments']); ?></span>
                </div>
            </div>

            <div class="dashboard-hub-spotlight-grid">
                <?php foreach (array_slice($snapshotItems, 0, 4) as $item): ?>
                    <div class="dashboard-hub-spotlight">
                        <div class="dashboard-hub-spotlight-label"><?php echo h($item['label']); ?></div>
                        <div class="dashboard-hub-spotlight-value"><?php echo h((string) $item['value']); ?></div>
                        <div class="dashboard-hub-spotlight-note"><?php echo h($item['note']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="dashboard-hub-band">
        <?php foreach ($snapshotItems as $item): ?>
            <div class="dashboard-hub-band-card">
                <div class="dashboard-hub-band-icon tone-<?php echo h($item['tone']); ?>">
                    <i class="bi <?php echo h($item['icon']); ?>"></i>
                </div>
                <div class="dashboard-hub-band-body">
                    <div class="dashboard-hub-band-label"><?php echo h($item['label']); ?></div>
                    <div class="dashboard-hub-band-value"><?php echo h((string) $item['value']); ?></div>
                    <div class="dashboard-hub-band-note"><?php echo h($item['note']); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="dashboard-hub-switcher">
        <button type="button" class="dashboard-hub-switch is-active" data-dashboard-view="operations">
            <i class="bi bi-kanban"></i>
            <span>Operations</span>
        </button>
        <button type="button" class="dashboard-hub-switch" data-dashboard-view="inventory">
            <i class="bi bi-clipboard-data"></i>
            <span>Inventory</span>
        </button>
        <button type="button" class="dashboard-hub-switch" data-dashboard-view="movement">
            <i class="bi bi-arrow-left-right"></i>
            <span>Movement</span>
        </button>
        <button type="button" class="dashboard-hub-switch" data-dashboard-view="analytics">
            <i class="bi bi-bar-chart"></i>
            <span>Analytics</span>
        </button>
    </div>

    <div class="dashboard-hub-panel is-active" data-dashboard-panel="operations">
        <article class="dashboard-hub-surface dashboard-hub-surface-strong mb-4">
            <div class="dashboard-hub-section-head">
                <div>
                    <div class="dashboard-hub-section-kicker">Work Queues</div>
                    <h2 class="dashboard-hub-section-title">Next actions that need attention</h2>
                </div>
                <span class="dashboard-hub-badge">Phase 1</span>
            </div>
            <div class="dashboard-hub-card-list">
                <?php foreach ($workQueueCards as $item): ?>
                    <a class="dashboard-hub-task-card tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                        <span class="dashboard-hub-task-icon">
                            <i class="bi <?php echo h($item['icon']); ?>"></i>
                        </span>
                        <span class="dashboard-hub-task-body">
                            <span class="dashboard-hub-task-label"><?php echo h($item['label']); ?></span>
                            <span class="dashboard-hub-task-note"><?php echo h($item['note']); ?></span>
                            <span class="dashboard-hub-task-cta"><?php echo h($item['cta']); ?></span>
                        </span>
                        <span class="dashboard-hub-task-value"><?php echo h((string) $item['value']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <div class="dashboard-hub-grid">
            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface dashboard-hub-surface-strong">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Action Queue</div>
                            <h2 class="dashboard-hub-section-title">Clear the high-friction work first</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/distributions/index.php'); ?>">Open Queue</a>
                    </div>
                    <div class="dashboard-hub-card-list">
                        <?php foreach ($operationsCards as $item): ?>
                            <a class="dashboard-hub-task-card tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                                <span class="dashboard-hub-task-icon">
                                    <i class="bi <?php echo h($item['icon']); ?>"></i>
                                </span>
                                <span class="dashboard-hub-task-body">
                                    <span class="dashboard-hub-task-label"><?php echo h($item['label']); ?></span>
                                    <span class="dashboard-hub-task-note"><?php echo h($item['note']); ?></span>
                                    <span class="dashboard-hub-task-cta"><?php echo h($item['cta']); ?></span>
                                </span>
                                <span class="dashboard-hub-task-value"><?php echo h((string) $item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Recent Procurement</div>
                            <h2 class="dashboard-hub-section-title">Latest purchase orders</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/purchase_orders/index.php'); ?>">View All</a>
                    </div>
                    <div class="dashboard-hub-feed">
                        <?php if ($recentPurchaseOrders): ?>
                            <?php foreach ($recentPurchaseOrders as $po): ?>
                                <?php $poStatus = (string) ($po['status'] ?? ''); ?>
                                <div class="dashboard-hub-feed-item">
                                    <div class="dashboard-hub-feed-main">
                                        <div class="dashboard-hub-feed-title"><?php echo h((string) ($po['po_number'] ?? 'No PO Number')); ?></div>
                                        <div class="dashboard-hub-feed-meta">
                                            <span><?php echo h((string) ($po['supplier_name'] ?? 'No supplier')); ?></span>
                                            <span class="dashboard-hub-dot"></span>
                                            <span><?php echo !empty($po['po_date']) ? h(date('M d, Y', strtotime($po['po_date']))) : 'No date'; ?></span>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo $poStatus === 'cancelled' ? 'text-bg-secondary' : ($poStatus === 'completed' ? 'text-bg-success' : 'text-bg-warning'); ?>">
                                        <?php echo h(ucfirst($poStatus !== '' ? $poStatus : 'pending')); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dashboard-hub-empty">No purchase orders found.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>

            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface dashboard-hub-surface-accent">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Supply Watch</div>
                            <h2 class="dashboard-hub-section-title">Low stock radar</h2>
                        </div>
                        <span class="dashboard-hub-badge">Threshold <?php echo h((string) $lowStockThreshold); ?></span>
                    </div>
                    <div class="dashboard-hub-feed">
                        <?php if ($lowStockItems): ?>
                            <?php foreach ($lowStockItems as $item): ?>
                                <div class="dashboard-hub-feed-item">
                                    <div class="dashboard-hub-feed-main">
                                        <div class="dashboard-hub-feed-title"><?php echo h((string) ($item['item_name'] ?? 'Supply Item')); ?></div>
                                        <div class="dashboard-hub-feed-meta">
                                            <span><?php echo h((string) ($item['stock_no'] ?? '')); ?></span>
                                            <?php if (!empty($item['classification_name'])): ?>
                                                <span class="dashboard-hub-dot"></span>
                                                <span><?php echo h((string) $item['classification_name']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dashboard-hub-feed-aside">
                                        <span class="dashboard-hub-feed-metric"><?php echo h(format_quantity($item['quantity_on_hand'] ?? 0)); ?></span>
                                        <span class="dashboard-hub-feed-caption">On hand</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dashboard-hub-empty">No low stock items right now.</div>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Control Quality</div>
                            <h2 class="dashboard-hub-section-title">Follow-up requiring attention</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-danger" href="<?php echo base_url('modules/property/inventory_reconciliation.php'); ?>">Open Reconciliation</a>
                    </div>
                    <div class="dashboard-hub-mini-grid">
                        <a class="dashboard-hub-mini-card tone-danger" href="<?php echo base_url('modules/property/inventory_reconciliation.php?resolution=unresolved'); ?>">
                            <span class="dashboard-hub-mini-label">Discrepancies</span>
                            <strong><?php echo number_format($summary['unresolved_property_discrepancies']); ?></strong>
                            <span class="dashboard-hub-mini-note">Unresolved property count exceptions</span>
                        </a>
                        <a class="dashboard-hub-mini-card tone-warning" href="<?php echo base_url('modules/property/stock_adjustments.php'); ?>">
                            <span class="dashboard-hub-mini-label">Adjustments</span>
                            <strong><?php echo number_format($summary['pending_stock_adjustments']); ?></strong>
                            <span class="dashboard-hub-mini-note">Pending stock adjustment approvals</span>
                        </a>
                        <a class="dashboard-hub-mini-card tone-secondary" href="<?php echo base_url('modules/property/unserviceable_review.php'); ?>">
                            <span class="dashboard-hub-mini-label">Review</span>
                            <strong><?php echo number_format($summary['unserviceable_review_items']); ?></strong>
                            <span class="dashboard-hub-mini-note">Assets tagged for repair or disposal</span>
                        </a>
                    </div>
                </article>
            </div>
        </div>
    </div>

    <div class="dashboard-hub-panel" data-dashboard-panel="inventory">
        <div class="dashboard-hub-grid dashboard-hub-grid-wide">
            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface dashboard-hub-surface-strong">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Inventory Control</div>
                            <h2 class="dashboard-hub-section-title">Counts, adjustments, and reconciliation</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/property/inventory_counts.php'); ?>">Open Inventory Counts</a>
                    </div>
                    <div class="dashboard-hub-card-list">
                        <?php foreach ($inventoryCards as $item): ?>
                            <a class="dashboard-hub-task-card tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                                <span class="dashboard-hub-task-icon">
                                    <i class="bi <?php echo h($item['icon']); ?>"></i>
                                </span>
                                <span class="dashboard-hub-task-body">
                                    <span class="dashboard-hub-task-label"><?php echo h($item['label']); ?></span>
                                    <span class="dashboard-hub-task-note"><?php echo h($item['note']); ?></span>
                                    <span class="dashboard-hub-task-cta"><?php echo h($item['cta']); ?></span>
                                </span>
                                <span class="dashboard-hub-task-value"><?php echo h((string) $item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>

            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Control Snapshot</div>
                            <h2 class="dashboard-hub-section-title">Current inventory posture</h2>
                        </div>
                    </div>
                    <div class="dashboard-hub-mini-grid">
                        <div class="dashboard-hub-mini-card tone-info">
                            <span class="dashboard-hub-mini-label">Open Property Counts</span>
                            <strong><?php echo number_format($summary['open_inventory_counts']); ?></strong>
                            <span class="dashboard-hub-mini-note">Sessions still active and not yet closed</span>
                        </div>
                        <div class="dashboard-hub-mini-card tone-secondary">
                            <span class="dashboard-hub-mini-label">Open Supply Counts</span>
                            <strong><?php echo number_format($summary['open_supply_counts']); ?></strong>
                            <span class="dashboard-hub-mini-note">Supply workspaces still in progress</span>
                        </div>
                        <div class="dashboard-hub-mini-card tone-dark">
                            <span class="dashboard-hub-mini-label">Unserviceable Review</span>
                            <strong><?php echo number_format($summary['unserviceable_review_items']); ?></strong>
                            <span class="dashboard-hub-mini-note">Items flagged for repair or disposal</span>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </div>

    <div class="dashboard-hub-panel" data-dashboard-panel="movement">
        <div class="dashboard-hub-grid">
            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface dashboard-hub-surface-strong">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Asset Movement</div>
                            <h2 class="dashboard-hub-section-title">Registry and posted movement</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/property/index.php'); ?>">Open Asset Registry</a>
                    </div>
                    <div class="dashboard-hub-card-list">
                        <?php foreach ($movementCards as $item): ?>
                            <a class="dashboard-hub-task-card tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                                <span class="dashboard-hub-task-icon">
                                    <i class="bi <?php echo h($item['icon']); ?>"></i>
                                </span>
                                <span class="dashboard-hub-task-body">
                                    <span class="dashboard-hub-task-label"><?php echo h($item['label']); ?></span>
                                    <span class="dashboard-hub-task-note"><?php echo h($item['note']); ?></span>
                                    <span class="dashboard-hub-task-cta"><?php echo h($item['cta']); ?></span>
                                </span>
                                <span class="dashboard-hub-task-value"><?php echo h((string) $item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>

            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Recent Activity</div>
                            <h2 class="dashboard-hub-section-title">Latest distributions</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('modules/distributions/index.php'); ?>">View All</a>
                    </div>
                    <div class="dashboard-hub-feed">
                        <?php if ($recentDistributions): ?>
                            <?php foreach ($recentDistributions as $distribution): ?>
                                <div class="dashboard-hub-feed-item">
                                    <div class="dashboard-hub-feed-main">
                                        <div class="dashboard-hub-feed-title"><?php echo h((string) ($distribution['document_no'] ?? 'No Document Number')); ?></div>
                                        <div class="dashboard-hub-feed-meta">
                                            <span><?php echo h(strtoupper((string) ($distribution['document_type'] ?? ''))); ?></span>
                                            <span class="dashboard-hub-dot"></span>
                                            <span><?php echo h((string) ($distribution['office_name'] ?? 'No office')); ?></span>
                                        </div>
                                        <div class="dashboard-hub-feed-note">
                                            <?php if (!empty($distribution['employee_no'])): ?>
                                                <?php echo h(employee_display_name($distribution) . ' - ' . $distribution['employee_no']); ?>
                                            <?php else: ?>
                                                Not specified
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="dashboard-hub-feed-aside">
                                        <span class="dashboard-hub-feed-metric"><?php echo !empty($distribution['distribution_date']) ? h(date('M d', strtotime($distribution['distribution_date']))) : '--'; ?></span>
                                        <span class="dashboard-hub-feed-caption"><?php echo !empty($distribution['distribution_date']) ? h(date('Y', strtotime($distribution['distribution_date']))) : 'No date'; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="dashboard-hub-empty">No distributions found.</div>
                        <?php endif; ?>
                    </div>
                </article>
            </div>
        </div>
    </div>

    <div class="dashboard-hub-panel" data-dashboard-panel="analytics">
        <div class="dashboard-hub-grid dashboard-hub-grid-wide">
            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface dashboard-hub-surface-strong">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Analytics Overview</div>
                            <h2 class="dashboard-hub-section-title">Risk, control, and supply pressure</h2>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/reports/index.php'); ?>">Open Reports</a>
                    </div>
                    <div class="dashboard-hub-card-list">
                        <?php foreach ($analyticsCards as $item): ?>
                            <a class="dashboard-hub-task-card tone-<?php echo h($item['tone']); ?>" href="<?php echo h($item['href']); ?>">
                                <span class="dashboard-hub-task-icon">
                                    <i class="bi <?php echo h($item['icon']); ?>"></i>
                                </span>
                                <span class="dashboard-hub-task-body">
                                    <span class="dashboard-hub-task-label"><?php echo h($item['label']); ?></span>
                                    <span class="dashboard-hub-task-note"><?php echo h($item['note']); ?></span>
                                    <span class="dashboard-hub-task-cta"><?php echo h($item['cta']); ?></span>
                                </span>
                                <span class="dashboard-hub-task-value"><?php echo h((string) $item['value']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Six-Month Movement</div>
                            <h2 class="dashboard-hub-section-title">Posted activity trend</h2>
                        </div>
                    </div>
                    <?php if ($movementMonthlyTrend): ?>
                        <div class="dashboard-hub-chart-list">
                            <?php foreach ($movementMonthlyTrend as $row): ?>
                                <?php
                                    $distributed = (int) ($row['distributed'] ?? 0);
                                    $returned = (int) ($row['returned'] ?? 0);
                                    $disposed = (int) ($row['disposed'] ?? 0);
                                    $rowTotal = $distributed + $returned + $disposed;
                                    $barWidth = $movementTrendPeak > 0 ? max(4, (int) round(($rowTotal / $movementTrendPeak) * 100)) : 0;
                                ?>
                                <div class="dashboard-hub-chart-row">
                                    <div class="dashboard-hub-chart-label"><?php echo h((string) ($row['month_label'] ?? '')); ?></div>
                                    <div class="dashboard-hub-chart-track">
                                        <span class="dashboard-hub-chart-bar tone-primary" style="width: <?php echo $barWidth; ?>%;"></span>
                                    </div>
                                    <div class="dashboard-hub-chart-value"><?php echo number_format($rowTotal); ?></div>
                                    <div class="dashboard-hub-chart-meta">
                                        Distributed <?php echo number_format($distributed); ?> - Returned <?php echo number_format($returned); ?> - Disposed <?php echo number_format($disposed); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-hub-empty">No posted movement found for the last six months.</div>
                    <?php endif; ?>
                </article>
            </div>

            <div class="dashboard-hub-stack">
                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Procurement Mix</div>
                            <h2 class="dashboard-hub-section-title">Purchase order status</h2>
                        </div>
                    </div>
                    <?php if ($procurementStatusMix): ?>
                        <div class="dashboard-hub-chart-list">
                            <?php foreach ($procurementStatusMix as $row): ?>
                                <?php
                                    $total = (int) ($row['total'] ?? 0);
                                    $percent = $procurementStatusTotal > 0 ? (int) round(($total / $procurementStatusTotal) * 100) : 0;
                                ?>
                                <div class="dashboard-hub-chart-row dashboard-hub-chart-row-compact">
                                    <div class="dashboard-hub-chart-label"><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['label'] ?? 'Unknown')))); ?></div>
                                    <div class="dashboard-hub-chart-track">
                                        <span class="dashboard-hub-chart-bar tone-success" style="width: <?php echo max(4, $percent); ?>%;"></span>
                                    </div>
                                    <div class="dashboard-hub-chart-value"><?php echo number_format($total); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-hub-empty">No purchase order status data available.</div>
                    <?php endif; ?>
                </article>

                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Asset Lifecycle</div>
                            <h2 class="dashboard-hub-section-title">Accountability status mix</h2>
                        </div>
                    </div>
                    <?php if ($assetLifecycleMix): ?>
                        <div class="dashboard-hub-chart-list">
                            <?php foreach ($assetLifecycleMix as $row): ?>
                                <?php
                                    $total = (int) ($row['total'] ?? 0);
                                    $percent = $assetLifecycleTotal > 0 ? (int) round(($total / $assetLifecycleTotal) * 100) : 0;
                                ?>
                                <div class="dashboard-hub-chart-row dashboard-hub-chart-row-compact">
                                    <div class="dashboard-hub-chart-label"><?php echo h((string) ($row['label'] ?? 'Unknown')); ?></div>
                                    <div class="dashboard-hub-chart-track">
                                        <span class="dashboard-hub-chart-bar tone-info" style="width: <?php echo max(4, $percent); ?>%;"></span>
                                    </div>
                                    <div class="dashboard-hub-chart-value"><?php echo number_format($total); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-hub-empty">No asset lifecycle data available.</div>
                    <?php endif; ?>
                </article>

                <article class="dashboard-hub-surface dashboard-hub-surface-accent">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Accountability Leaders</div>
                            <h2 class="dashboard-hub-section-title">Top offices by active assets</h2>
                        </div>
                    </div>
                    <?php if ($topAccountableOffices): ?>
                        <div class="dashboard-hub-chart-list">
                            <?php foreach ($topAccountableOffices as $row): ?>
                                <?php
                                    $totalAssets = (int) ($row['total_assets'] ?? 0);
                                    $percent = $topOfficePeak > 0 ? (int) round(($totalAssets / $topOfficePeak) * 100) : 0;
                                ?>
                                <div class="dashboard-hub-chart-row">
                                    <div class="dashboard-hub-chart-label"><?php echo h((string) ($row['office_name'] ?? 'Unassigned')); ?></div>
                                    <div class="dashboard-hub-chart-track">
                                        <span class="dashboard-hub-chart-bar tone-warning" style="width: <?php echo max(4, $percent); ?>%;"></span>
                                    </div>
                                    <div class="dashboard-hub-chart-value"><?php echo number_format($totalAssets); ?></div>
                                    <div class="dashboard-hub-chart-meta">Value <?php echo h(number_format((float) ($row['total_value'] ?? 0), 2)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-hub-empty">No active accountability data available.</div>
                    <?php endif; ?>
                </article>

                <article class="dashboard-hub-surface">
                    <div class="dashboard-hub-section-head">
                        <div>
                            <div class="dashboard-hub-section-kicker">Exception Mix</div>
                            <h2 class="dashboard-hub-section-title">Inventory count findings</h2>
                        </div>
                    </div>
                    <?php if ($inventoryExceptionMix): ?>
                        <div class="dashboard-hub-mini-grid">
                            <?php foreach ($inventoryExceptionMix as $row): ?>
                                <div class="dashboard-hub-mini-card tone-warning">
                                    <span class="dashboard-hub-mini-label"><?php echo h(ucwords(str_replace('_', ' ', (string) ($row['label'] ?? 'Unknown')))); ?></span>
                                    <strong><?php echo number_format((int) ($row['total'] ?? 0)); ?></strong>
                                    <span class="dashboard-hub-mini-note">Count lines requiring verification or resolution</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="dashboard-hub-empty">No inventory count exceptions found.</div>
                    <?php endif; ?>
                </article>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var dashboardRoot = document.querySelector('[data-dashboard-hub]');
    if (!dashboardRoot) {
        return;
    }

    var switches = Array.prototype.slice.call(dashboardRoot.querySelectorAll('[data-dashboard-view]'));
    var panels = Array.prototype.slice.call(dashboardRoot.querySelectorAll('[data-dashboard-panel]'));

    function activateDashboardView(viewName) {
        switches.forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-dashboard-view') === viewName);
        });

        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-dashboard-panel') === viewName);
        });
    }

    switches.forEach(function (button) {
        button.addEventListener('click', function () {
            activateDashboardView(button.getAttribute('data-dashboard-view'));
        });
    });

    var initialButton = dashboardRoot.querySelector('[data-dashboard-view].is-active') || switches[0];
    if (initialButton) {
        activateDashboardView(initialButton.getAttribute('data-dashboard-view'));
    }
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
