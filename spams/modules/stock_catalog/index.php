<?php
require_once __DIR__ . '/../../app/config/init.php';
require_once __DIR__ . '/../../app/helpers/audit.php';

require_role('Administrator', 'Supply Officer');

$db = db();
$page_title = 'Stock Catalog';
$flash = get_flash();
$errors = [];
$warnings = [];
$items = [];
$classifications = [];
$accountCodes = [];
$unitOfMeasures = [];
$selectedItem = null;
$search = trim((string) ($_GET['q'] ?? ''));
$filterType = trim((string) ($_GET['type'] ?? 'all'));
$selectedId = (int) ($_GET['id'] ?? 0);
$mode = trim((string) ($_GET['mode'] ?? ''));

if (!in_array($filterType, ['all', 'supply', 'semi_expendable', 'equipment'], true)) {
    $filterType = 'all';
}
if (!in_array($mode, ['', 'create', 'edit'], true)) {
    $mode = '';
}

$form = [
    'id' => 0,
    'stock_no' => '',
    'barcode' => '',
    'item_name' => '',
    'item_description' => '',
    'item_type' => 'supply',
    'classification_id' => '',
    'account_code_id' => '',
    'unit_of_measure_id' => '',
    'is_active' => '1',
];
$generatedStockNoPreview = '';

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $classificationResult = $db->query("
        SELECT id, classification_name, classification_family, classification_group
        FROM classifications
        WHERE is_active = 1
        ORDER BY COALESCE(classification_family, ''), classification_name ASC
    ");
    if ($classificationResult) {
        $classifications = $classificationResult->fetch_all(MYSQLI_ASSOC);
    }

    $accountCodeResult = $db->query("
        SELECT id, account_code, account_name, account_group
        FROM account_codes
        WHERE is_active = 1
        ORDER BY account_code ASC
    ");
    if ($accountCodeResult) {
        $accountCodes = $accountCodeResult->fetch_all(MYSQLI_ASSOC);
    }

    $uomResult = $db->query("
        SELECT id, uom_name, abbreviation
        FROM unit_of_measures
        WHERE is_active = 1
        ORDER BY uom_name ASC
    ");
    if ($uomResult) {
        $unitOfMeasures = $uomResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        } else {
            $action = trim((string) ($_POST['action'] ?? 'create'));
            $form = [
                'id' => (int) ($_POST['id'] ?? 0),
                'stock_no' => '',
                'barcode' => old($_POST, 'barcode'),
                'item_name' => old($_POST, 'item_name'),
                'item_description' => old($_POST, 'item_description'),
                'item_type' => old($_POST, 'item_type', 'supply'),
                'classification_id' => old($_POST, 'classification_id'),
                'account_code_id' => old($_POST, 'account_code_id'),
                'unit_of_measure_id' => old($_POST, 'unit_of_measure_id'),
                'is_active' => isset($_POST['is_active']) ? '1' : '0',
            ];

            if (!in_array($form['item_type'], ['supply', 'semi_expendable', 'equipment'], true)) {
                $form['item_type'] = 'supply';
            }

            if ($action === 'toggle') {
                $toggleId = (int) ($_POST['id'] ?? 0);
                $toggleStmt = $db->prepare("UPDATE stock_catalog SET is_active = IF(is_active = 1, 0, 1), updated_by = ?, updated_at = NOW() WHERE id = ?");
                if ($toggleStmt) {
                    $userId = current_user_id();
                    $toggleStmt->bind_param('ii', $userId, $toggleId);
                    $saved = $toggleStmt->execute();
                    $toggleStmt->close();
                    if ($saved) {
                        $statusStmt = $db->prepare("SELECT stock_no, item_name, is_active FROM stock_catalog WHERE id = ? LIMIT 1");
                        $statusPayload = ['id' => $toggleId];
                        if ($statusStmt) {
                            $statusStmt->bind_param('i', $toggleId);
                            $statusStmt->execute();
                            $statusRow = $statusStmt->get_result()->fetch_assoc();
                            $statusStmt->close();
                            if ($statusRow) {
                                $statusPayload = $statusRow;
                            }
                        }
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'stock_catalog',
                            'record_id' => $toggleId,
                            'module_name' => 'stock_catalog',
                            'record_type' => 'stock_item',
                            'action_name' => 'toggle_stock_catalog_status',
                            'description' => 'Updated stock catalog item status.',
                            'new_values' => $statusPayload,
                        ]);
                        set_flash('success', 'Stock catalog item status updated.');
                        redirect('modules/stock_catalog/index.php?id=' . $toggleId);
                    }
                }
                $errors[] = 'Unable to update stock catalog status.';
            }

            if ($action === 'create' || $action === 'update') {
                if ($form['item_name'] === '') {
                    $errors[] = 'Item name is required.';
                }

                $recordId = (int) $form['id'];
                $accountCodeId = $form['account_code_id'] !== '' ? (int) $form['account_code_id'] : null;
                if (!$accountCodeId) {
                    $errors[] = 'Account code is required.';
                }

                foreach ($accountCodes as $accountCode) {
                    if ((int) $accountCode['id'] === (int) $accountCodeId) {
                        $expectedGroup = $form['item_type'] === 'equipment' ? 'asset' : $form['item_type'];
                        if (($accountCode['account_group'] ?? '') !== $expectedGroup) {
                            $errors[] = 'Selected account code does not match the item type.';
                        }
                        break;
                    }
                }

                $classificationId = $form['classification_id'] !== '' ? (int) $form['classification_id'] : null;

                if ($action === 'create') {
                    $form['stock_no'] = stock_catalog_next_number($db, $classificationId, $form['item_name'], $form['item_description']);
                } elseif ($action === 'update' && $recordId > 0) {
                    $currentStockStmt = $db->prepare("SELECT stock_no FROM stock_catalog WHERE id = ? LIMIT 1");
                    if ($currentStockStmt) {
                        $currentStockStmt->bind_param('i', $recordId);
                        $currentStockStmt->execute();
                        $currentStockRow = $currentStockStmt->get_result()->fetch_assoc();
                        $currentStockStmt->close();
                        $form['stock_no'] = (string) ($currentStockRow['stock_no'] ?? '');
                    }
                }

                if ($form['stock_no'] === '') {
                    $errors[] = 'Unable to generate a stock number for the item name/description.';
                }

                $barcode = trim((string) $form['barcode']);
                if ($barcode !== '') {
                    $barcodeDuplicateStmt = $db->prepare("SELECT id FROM stock_catalog WHERE barcode = ? AND id != ? LIMIT 1");
                    if ($barcodeDuplicateStmt) {
                        $barcodeDuplicateStmt->bind_param('si', $barcode, $recordId);
                        $barcodeDuplicateStmt->execute();
                        if ($barcodeDuplicateStmt->get_result()->fetch_assoc()) {
                            $errors[] = 'Barcode already exists on another stock catalog item.';
                        }
                        $barcodeDuplicateStmt->close();
                    }
                } else {
                    $barcode = null;
                }

                $duplicateStmt = $db->prepare("SELECT id FROM stock_catalog WHERE stock_no = ? AND id != ? LIMIT 1");
                if ($duplicateStmt) {
                    $duplicateStmt->bind_param('si', $form['stock_no'], $recordId);
                    $duplicateStmt->execute();
                    if ($duplicateStmt->get_result()->fetch_assoc()) {
                        $errors[] = 'Generated stock number already exists. Please try saving again.';
                    }
                    $duplicateStmt->close();
                }

                $duplicateItemStmt = $db->prepare("
                    SELECT id
                    FROM stock_catalog
                    WHERE account_code_id = ?
                      AND item_name = ?
                      AND COALESCE(item_description, '') = COALESCE(?, '')
                      AND id != ?
                    LIMIT 1
                ");
                if ($duplicateItemStmt) {
                    $duplicateItemStmt->bind_param('issi', $accountCodeId, $form['item_name'], $form['item_description'], $recordId);
                    $duplicateItemStmt->execute();
                    if ($duplicateItemStmt->get_result()->fetch_assoc()) {
                        $errors[] = 'A stock catalog item with the same account code, item, and description already exists.';
                    }
                    $duplicateItemStmt->close();
                }

                foreach ($classifications as $classification) {
                    if ((int) $classification['id'] === (int) $classificationId) {
                        $expectedGroup = $form['item_type'] === 'equipment' ? 'asset' : $form['item_type'];
                        if (($classification['classification_group'] ?? '') !== $expectedGroup) {
                            $errors[] = 'Selected classification does not match the item type.';
                        }
                        break;
                    }
                }

                if ($action === 'update' && $recordId > 0) {
                    $typeCheckStmt = $db->prepare("
                        SELECT COUNT(*) AS total
                        FROM purchase_order_items
                        WHERE stock_catalog_id = ?
                          AND id IN (
                              SELECT poi.id
                              FROM purchase_order_items poi
                              INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
                              WHERE po.status != 'cancelled'
                          )
                    ");
                    if ($typeCheckStmt) {
                        $typeCheckStmt->bind_param('i', $recordId);
                        $typeCheckStmt->execute();
                        $typeRow = $typeCheckStmt->get_result()->fetch_assoc();
                        $typeCheckStmt->close();
                        if ((int) ($typeRow['total'] ?? 0) > 0) {
                            $currentTypeStmt = $db->prepare("SELECT item_type FROM stock_catalog WHERE id = ? LIMIT 1");
                            if ($currentTypeStmt) {
                                $currentTypeStmt->bind_param('i', $recordId);
                                $currentTypeStmt->execute();
                                $currentTypeRow = $currentTypeStmt->get_result()->fetch_assoc();
                                $currentTypeStmt->close();
                                if ($currentTypeRow && ($currentTypeRow['item_type'] ?? '') !== $form['item_type']) {
                                    $errors[] = 'Item type cannot be changed because this item is already referenced by active PO lines. Create a new catalog item for the new type instead.';
                                }
                            }
                        }
                    }
                }

                if (empty($errors)) {
                    $unitOfMeasureId = $form['unit_of_measure_id'] !== '' ? (int) $form['unit_of_measure_id'] : null;
                    $isActive = (int) $form['is_active'];
                    $userId = current_user_id();

                    if ($action === 'create') {
                        $stmt = $db->prepare("
                            INSERT INTO stock_catalog
                            (stock_no, barcode, item_name, item_description, item_type, classification_id, account_code_id, unit_of_measure_id, is_active, created_by)
                            VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), NULLIF(?, 0), ?, ?)
                        ");
                        if ($stmt) {
                            $stmt->bind_param(
                                'sssssiiiii',
                                $form['stock_no'],
                                $barcode,
                                $form['item_name'],
                                $form['item_description'],
                                $form['item_type'],
                                $classificationId,
                                $accountCodeId,
                                $unitOfMeasureId,
                                $isActive,
                                $userId
                            );
                            $saved = $stmt->execute();
                            $newId = (int) $stmt->insert_id;
                            $stmt->close();
                            if ($saved) {
                                write_audit_log($db, [
                                    'action' => 'insert',
                                    'table_name' => 'stock_catalog',
                                    'record_id' => $newId,
                                    'module_name' => 'stock_catalog',
                                    'record_type' => 'stock_item',
                                    'action_name' => 'create_stock_catalog_item',
                                    'description' => 'Created stock catalog item.',
                                    'new_values' => [
                                        'stock_no' => $form['stock_no'],
                                        'barcode' => $barcode,
                                        'item_name' => $form['item_name'],
                                        'item_description' => $form['item_description'],
                                        'item_type' => $form['item_type'],
                                        'classification_id' => $classificationId,
                                        'account_code_id' => $accountCodeId,
                                        'unit_of_measure_id' => $unitOfMeasureId,
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                set_flash('success', 'Stock catalog item created successfully.');
                                redirect('modules/stock_catalog/index.php?id=' . $newId);
                            }
                        }
                    }

                    if ($action === 'update') {
                        $stmt = $db->prepare("
                            UPDATE stock_catalog
                            SET stock_no = ?, barcode = ?, item_name = ?, item_description = ?, item_type = ?,
                                classification_id = NULLIF(?, 0), account_code_id = NULLIF(?, 0),
                                unit_of_measure_id = NULLIF(?, 0), is_active = ?, updated_by = ?, updated_at = NOW()
                            WHERE id = ?
                        ");
                        if ($stmt) {
                            $stmt->bind_param(
                                'sssssiiiiii',
                                $form['stock_no'],
                                $barcode,
                                $form['item_name'],
                                $form['item_description'],
                                $form['item_type'],
                                $classificationId,
                                $accountCodeId,
                                $unitOfMeasureId,
                                $isActive,
                                $userId,
                                $recordId
                            );
                            $saved = $stmt->execute();
                            $stmt->close();
                            if ($saved) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'stock_catalog',
                                    'record_id' => $recordId,
                                    'module_name' => 'stock_catalog',
                                    'record_type' => 'stock_item',
                                    'action_name' => 'update_stock_catalog_item',
                                    'description' => 'Updated stock catalog item.',
                                    'new_values' => [
                                        'stock_no' => $form['stock_no'],
                                        'barcode' => $barcode,
                                        'item_name' => $form['item_name'],
                                        'item_description' => $form['item_description'],
                                        'item_type' => $form['item_type'],
                                        'classification_id' => $classificationId,
                                        'account_code_id' => $accountCodeId,
                                        'unit_of_measure_id' => $unitOfMeasureId,
                                        'is_active' => $isActive,
                                    ],
                                ]);
                                if ($warnings) {
                                    set_flash('info', implode(' ', $warnings));
                                } else {
                                    set_flash('success', 'Stock catalog item updated successfully.');
                                }
                                redirect('modules/stock_catalog/index.php?id=' . $recordId);
                            }
                        }
                    }

                    $errors[] = 'Unable to save the stock catalog item.';
                }
            }
        }
    }

    if ($selectedId > 0) {
        $selectedStmt = $db->prepare("
            SELECT sc.*, c.classification_name, c.classification_family, ac.account_code, ac.account_name, u.uom_name, u.abbreviation
            FROM stock_catalog sc
            LEFT JOIN classifications c ON c.id = sc.classification_id
            LEFT JOIN account_codes ac ON ac.id = sc.account_code_id
            LEFT JOIN unit_of_measures u ON u.id = sc.unit_of_measure_id
            WHERE sc.id = ?
            LIMIT 1
        ");
        if ($selectedStmt) {
            $selectedStmt->bind_param('i', $selectedId);
            $selectedStmt->execute();
            $selectedItem = $selectedStmt->get_result()->fetch_assoc() ?: null;
            $selectedStmt->close();
        }

        if ($selectedItem && $mode === 'edit' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            $form = [
                'id' => (int) $selectedItem['id'],
                'stock_no' => $selectedItem['stock_no'],
                'barcode' => $selectedItem['barcode'] ?? '',
                'item_name' => $selectedItem['item_name'],
                'item_description' => $selectedItem['item_description'] ?? '',
                'item_type' => $selectedItem['item_type'],
                'classification_id' => (string) ($selectedItem['classification_id'] ?? ''),
                'account_code_id' => (string) ($selectedItem['account_code_id'] ?? ''),
                'unit_of_measure_id' => (string) ($selectedItem['unit_of_measure_id'] ?? ''),
                'is_active' => (string) (int) ($selectedItem['is_active'] ?? 1),
            ];
        }
    }

    if ($mode === 'create') {
        if ($form['item_name'] !== '' || $form['item_description'] !== '') {
            $generatedStockNoPreview = stock_catalog_next_number(
                $db,
                $form['classification_id'] !== '' ? (int) $form['classification_id'] : null,
                $form['item_name'],
                $form['item_description']
            );
        }
    } elseif (!empty($form['stock_no'])) {
        $generatedStockNoPreview = $form['stock_no'];
    }

    $where = [];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(sc.stock_no LIKE ? OR sc.barcode LIKE ? OR sc.item_name LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($filterType !== 'all') {
        $where[] = 'sc.item_type = ?';
        $types .= 's';
        $params[] = $filterType;
    }

    $sql = "
        SELECT sc.id, sc.stock_no, sc.barcode, sc.item_name, sc.item_type,
               sc.is_active, c.classification_name, c.classification_family, ac.account_code,
               u.abbreviation AS uom
        FROM stock_catalog sc
        LEFT JOIN classifications c ON c.id = sc.classification_id
        LEFT JOIN account_codes ac ON ac.id = sc.account_code_id
        LEFT JOIN unit_of_measures u ON u.id = sc.unit_of_measure_id
    ";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sc.item_type ASC, sc.stock_no ASC';

    if ($types !== '') {
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $bindValues = $params;
            $refs = [];
            $refs[] = &$types;
            foreach ($bindValues as $key => $value) {
                $refs[] = &$bindValues[$key];
            }
            call_user_func_array([$stmt, 'bind_param'], $refs);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        $result = $db->query($sql);
        if ($result) {
            $items = $result->fetch_all(MYSQLI_ASSOC);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-lg-5 col-xl-4">
        <div class="card h-100">
            <div class="card-body p-4 d-flex flex-column">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="card-title mb-0">Stock Catalog</h5>
                    <a href="<?php echo base_url('modules/stock_catalog/index.php?mode=create'); ?>" class="btn btn-primary btn-sm">Add New Item</a>
                </div>

                <form method="get" class="mb-3">
                    <div class="input-group input-group-sm mb-2">
                        <input type="search" class="form-control" name="q" placeholder="Search stock no. or item name" value="<?php echo h($search); ?>">
                        <button type="submit" class="btn btn-outline-secondary">Search</button>
                    </div>
                    <div class="d-flex gap-2 flex-wrap align-items-center">
                        <span class="small text-muted fw-semibold">Quick filters:</span>
                        <?php foreach (['all' => 'All', 'supply' => 'Supply', 'semi_expendable' => 'Semi-Expendable', 'equipment' => 'Equipment'] as $typeKey => $typeLabel): ?>
                            <a href="<?php echo base_url('modules/stock_catalog/index.php?type=' . urlencode($typeKey) . ($search !== '' ? '&q=' . urlencode($search) : '')); ?>" class="btn btn-sm <?php echo $filterType === $typeKey ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo h($typeLabel); ?></a>
                        <?php endforeach; ?>
                    </div>
                </form>

                <div class="flex-grow-1" style="max-height: 70vh; overflow-y: auto;">
                    <?php if ($items): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($items as $item): ?>
                                <a href="<?php echo base_url('modules/stock_catalog/index.php?id=' . (int) $item['id'] . ($filterType !== 'all' ? '&type=' . urlencode($filterType) : '') . ($search !== '' ? '&q=' . urlencode($search) : '')); ?>" class="list-group-item list-group-item-action <?php echo $selectedId === (int) $item['id'] ? 'active' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="flex-grow-1">
                                            <div class="mb-1">
                                                <span class="badge text-bg-secondary" style="font-family: monospace;"><?php echo h($item['stock_no']); ?></span>
                                                <span class="badge <?php echo ($item['item_type'] ?? '') === 'equipment' ? 'text-bg-warning' : (($item['item_type'] ?? '') === 'semi_expendable' ? 'text-bg-primary' : 'text-bg-success'); ?>">
                                                    <?php echo h(ucwords(str_replace('_', ' ', (string) $item['item_type']))); ?>
                                                </span>
                                            </div>
                                            <div class="fw-semibold"><?php echo h($item['item_name']); ?></div>
                                            <div class="small opacity-75"><?php echo h(trim((!empty($item['classification_family']) ? $item['classification_family'] . ' / ' : '') . ($item['classification_name'] ?? 'No class') . ' | ' . ($item['account_code'] ?? 'No account') . ' | ' . ($item['uom'] ?? 'No UOM'))); ?></div>
                                            <?php if (!empty($item['barcode'])): ?><div class="small opacity-75">Barcode: <?php echo h($item['barcode']); ?></div><?php endif; ?>
                                        </div>
                                        <span class="badge <?php echo (int) ($item['is_active'] ?? 0) === 1 ? 'text-bg-light' : 'text-bg-secondary'; ?>"><?php echo (int) ($item['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-5">No stock catalog items found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7 col-xl-8">
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($warnings): ?>
            <div class="alert alert-warning"><?php foreach ($warnings as $warning): ?><div><?php echo h($warning); ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <?php if ($mode === 'create' || ($mode === 'edit' && $selectedItem)): ?>
            <div class="card">
                <div class="card-body p-4">
                    <h5 class="card-title mb-3"><?php echo $mode === 'create' ? 'Add Stock Catalog Item' : 'Edit Stock Catalog Item'; ?></h5>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="<?php echo $mode === 'create' ? 'create' : 'update'; ?>">
                        <input type="hidden" name="id" value="<?php echo (int) $form['id']; ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Stock Number</label>
                                <input type="text" class="form-control" id="stockCatalogStockNoPreview" data-form-mode="<?php echo h($mode); ?>" value="<?php echo h($generatedStockNoPreview !== '' ? $generatedStockNoPreview : 'Auto-generated on save'); ?>" readonly>
                                <div class="form-text">Generated automatically as a two-letter item code plus running series, for example <code>JS-001</code>.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Packaging Barcode</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="stockCatalogBarcode" name="barcode" value="<?php echo h($form['barcode']); ?>" placeholder="Scan or enter existing product barcode">
                                    <button type="button" class="btn btn-outline-secondary" id="stockCatalogStartScan">
                                        <i class="bi bi-upc-scan me-1"></i>Scan
                                    </button>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="stockCatalogStopScan">
                                        <i class="bi bi-stop-circle me-1"></i>Stop Camera
                                    </button>
                                    <span class="small text-muted align-self-center" id="stockCatalogScanStatus">Use the existing barcode printed on the supply packaging when available.</span>
                                </div>
                                <div class="inventory-camera-panel d-none mt-2" id="stockCatalogScanPanel">
                                    <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                                        <video id="stockCatalogScanVideo" autoplay playsinline muted></video>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Item Name *</label>
                                <input type="text" class="form-control" id="stockCatalogItemName" name="item_name" value="<?php echo h($form['item_name']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Item Type *</label>
                                <select class="form-select" id="stockCatalogItemType" name="item_type" required>
                                    <?php foreach (['supply' => 'Supply', 'semi_expendable' => 'Semi-Expendable', 'equipment' => 'Equipment'] as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $form['item_type'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Classification</label>
                                <select class="form-select stock-catalog-classification" name="classification_id">
                                    <option value="">Select classification</option>
                                    <?php foreach ($classifications as $classification): ?>
                                        <option value="<?php echo (int) $classification['id']; ?>" data-family="<?php echo h((string) ($classification['classification_family'] ?? '')); ?>" data-item-type="<?php echo h(($classification['classification_group'] ?? '') === 'asset' ? 'equipment' : (string) $classification['classification_group']); ?>" <?php echo $form['classification_id'] === (string) $classification['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(!empty($classification['classification_family']) ? ($classification['classification_family'] . ' / ' . $classification['classification_name']) : $classification['classification_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Account Code</label>
                                <select class="form-select stock-catalog-account" name="account_code_id">
                                    <option value="">Select account code</option>
                                    <?php foreach ($accountCodes as $accountCode): ?>
                                        <option value="<?php echo (int) $accountCode['id']; ?>" data-item-type="<?php echo h(($accountCode['account_group'] ?? '') === 'asset' ? 'equipment' : (string) $accountCode['account_group']); ?>" <?php echo $form['account_code_id'] === (string) $accountCode['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($accountCode['account_code'] . ' - ' . $accountCode['account_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Unit of Measure</label>
                                <select class="form-select" name="unit_of_measure_id">
                                    <option value="">Select unit</option>
                                    <?php foreach ($unitOfMeasures as $uom): ?>
                                        <option value="<?php echo (int) $uom['id']; ?>" <?php echo $form['unit_of_measure_id'] === (string) $uom['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($uom['uom_name'] . (!empty($uom['abbreviation']) ? ' (' . $uom['abbreviation'] . ')' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Full Description</label>
                                <textarea class="form-control" id="stockCatalogItemDescription" name="item_description" rows="4"><?php echo h($form['item_description']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="stockCatalogActive" name="is_active" value="1" <?php echo $form['is_active'] === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="stockCatalogActive">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary"><?php echo $mode === 'create' ? 'Save Item' : 'Update Item'; ?></button>
                            <a href="<?php echo base_url('modules/stock_catalog/index.php' . ($selectedId > 0 ? '?id=' . $selectedId : '')); ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($selectedItem): ?>
            <div class="card">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <div class="mb-2">
                                <span class="badge text-bg-secondary" style="font-family: monospace;"><?php echo h($selectedItem['stock_no']); ?></span>
                                <span class="badge <?php echo ($selectedItem['item_type'] ?? '') === 'equipment' ? 'text-bg-warning' : (($selectedItem['item_type'] ?? '') === 'semi_expendable' ? 'text-bg-primary' : 'text-bg-success'); ?>">
                                    <?php echo h(ucwords(str_replace('_', ' ', (string) $selectedItem['item_type']))); ?>
                                </span>
                            </div>
                            <h5 class="card-title mb-1"><?php echo h($selectedItem['item_name']); ?></h5>
                            <div class="text-muted small"><?php echo h($selectedItem['item_description'] ?? ''); ?></div>
                        </div>
                        <span class="badge <?php echo (int) ($selectedItem['is_active'] ?? 0) === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                            <?php echo (int) ($selectedItem['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><div class="small text-muted">Classification</div><div><?php echo h(!empty($selectedItem['classification_family']) ? ($selectedItem['classification_family'] . ' / ' . ($selectedItem['classification_name'] ?? '')) : ($selectedItem['classification_name'] ?? 'Not set')); ?></div></div>
                        <div class="col-md-4"><div class="small text-muted">Account Code</div><div><?php echo h(!empty($selectedItem['account_code']) ? $selectedItem['account_code'] . ' - ' . ($selectedItem['account_name'] ?? '') : 'Not set'); ?></div></div>
                        <div class="col-md-4"><div class="small text-muted">Unit of Measure</div><div><?php echo h(!empty($selectedItem['uom_name']) ? $selectedItem['uom_name'] . (!empty($selectedItem['abbreviation']) ? ' (' . $selectedItem['abbreviation'] . ')' : '') : 'Not set'); ?></div></div>
                        <div class="col-md-6"><div class="small text-muted">Stock Number</div><div><?php echo h($selectedItem['stock_no']); ?></div></div>
                        <div class="col-md-6"><div class="small text-muted">Packaging Barcode</div><div><?php echo h($selectedItem['barcode'] ?: 'Not set'); ?></div></div>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="<?php echo base_url('modules/stock_catalog/index.php?id=' . (int) $selectedItem['id'] . '&mode=edit'); ?>" class="btn btn-primary">Edit</a>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo (int) $selectedItem['id']; ?>">
                            <button type="submit" class="btn btn-outline-danger"><?php echo (int) ($selectedItem['is_active'] ?? 0) === 1 ? 'Deactivate' : 'Reactivate'; ?></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body p-5 text-center text-muted">
                    Select a catalog item from the list, or create a new one to start building your university stock catalog.
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var itemTypeSelect = document.getElementById('stockCatalogItemType');
    var classificationSelect = document.querySelector('.stock-catalog-classification');
    var accountSelect = document.querySelector('.stock-catalog-account');
    var stockNoPreview = document.getElementById('stockCatalogStockNoPreview');
    var itemNameInput = document.getElementById('stockCatalogItemName');
    var itemDescriptionInput = document.getElementById('stockCatalogItemDescription');
    var barcodeInput = document.getElementById('stockCatalogBarcode');
    var startScanButton = document.getElementById('stockCatalogStartScan');
    var stopScanButton = document.getElementById('stockCatalogStopScan');
    var scanPanel = document.getElementById('stockCatalogScanPanel');
    var scanVideo = document.getElementById('stockCatalogScanVideo');
    var scanStatus = document.getElementById('stockCatalogScanStatus');
    var scanStream = null;
    var scanDetector = null;
    var scanTimer = null;
    var scanActive = false;
    var html5QrScanner = null;

    function buildItemCode(label) {
        var cleaned = String(label || '').toUpperCase().replace(/[^A-Z0-9& ]+/g, ' ').trim();
        if (!cleaned) return 'SC';
        var words = cleaned.split(/\s+/).filter(function (word) {
            return ['AND', 'OF', 'THE', '&'].indexOf(word) === -1;
        });
        if (words.length >= 2) {
            var first = words[0].replace(/[^A-Z]/g, '');
            var second = /^[A-Z]/.test(words[1]) ? words[1].replace(/[^A-Z]/g, '') : '';
            var code = (first.charAt(0) || '') + (second.charAt(0) || '');
            if (code.length === 2) return code;
            if (first.length >= 2) return first.substring(0, 2);
        }
        var single = (words[0] || cleaned).replace(/[^A-Z]/g, '');
        return (single + 'XX').substring(0, 2);
    }

    function syncStockNoPreview() {
        if (!stockNoPreview) return;
        if ((stockNoPreview.getAttribute('data-form-mode') || '') !== 'create') {
            return;
        }
        var label = '';
        if (classificationSelect && classificationSelect.value) {
            var selectedOption = classificationSelect.options[classificationSelect.selectedIndex];
            if (selectedOption) {
                label = selectedOption.getAttribute('data-family') || selectedOption.textContent || '';
            }
        }
        if (!label) {
            label = (itemNameInput && itemNameInput.value ? itemNameInput.value : '') || (itemDescriptionInput && itemDescriptionInput.value ? itemDescriptionInput.value : '');
        }
        if (!label) {
            stockNoPreview.value = 'Auto-generated on save';
            return;
        }
        stockNoPreview.value = buildItemCode(label) + '-001+';
    }

    function filterSelectOptions(select, itemType) {
        if (!select) return;
        var hasStrictMatch = Array.prototype.some.call(select.options, function (option) {
            if (!option.value) {
                return false;
            }
            var optionType = option.getAttribute('data-item-type') || '';
            return optionType === '' || optionType === itemType;
        });

        Array.prototype.forEach.call(select.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var optionType = option.getAttribute('data-item-type') || '';
            var matches = optionType === '' || optionType === itemType;
            option.hidden = hasStrictMatch ? !matches : false;
            if (hasStrictMatch && !matches && option.selected) {
                select.value = '';
            }
        });
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(select);
        }
    }

    function syncDependentPicklists() {
        if (!itemTypeSelect) return;
        filterSelectOptions(classificationSelect, itemTypeSelect.value);
        filterSelectOptions(accountSelect, itemTypeSelect.value);
        syncStockNoPreview();
    }

    if (itemTypeSelect) {
        itemTypeSelect.addEventListener('change', syncDependentPicklists);
        syncDependentPicklists();
    }
    if (accountSelect) {
        accountSelect.addEventListener('change', syncStockNoPreview);
    }
    if (classificationSelect) {
        classificationSelect.addEventListener('change', syncStockNoPreview);
    }
    if (itemNameInput) {
        itemNameInput.addEventListener('input', syncStockNoPreview);
    }
    if (itemDescriptionInput) {
        itemDescriptionInput.addEventListener('input', syncStockNoPreview);
    }

    function setScanStatus(message) {
        if (scanStatus) {
            scanStatus.textContent = message;
        }
    }

    function fillBarcodeValue(value) {
        if (!value || !barcodeInput) {
            return;
        }
        barcodeInput.value = String(value).trim();
        barcodeInput.focus();
        barcodeInput.select();
        stopBarcodeScanner(false);
        setScanStatus('Barcode captured. Review or save the item.');
    }

    function stopBarcodeScanner(resetMessage) {
        scanActive = false;
        if (scanTimer) {
            window.clearInterval(scanTimer);
            scanTimer = null;
        }
        if (html5QrScanner) {
            try {
                html5QrScanner.stop().catch(function () {}).finally(function () {
                    try {
                        html5QrScanner.clear();
                    } catch (error) {
                        // Ignore cleanup failures.
                    }
                });
            } catch (error) {
                // Ignore cleanup failures.
            }
            html5QrScanner = null;
        }
        if (scanStream) {
            scanStream.getTracks().forEach(function (track) {
                track.stop();
            });
            scanStream = null;
        }
        if (scanVideo) {
            scanVideo.srcObject = null;
            scanVideo.classList.remove('d-none');
        }
        if (scanPanel) {
            scanPanel.classList.add('d-none');
        }
        if (startScanButton) {
            startScanButton.classList.remove('d-none');
        }
        if (stopScanButton) {
            stopScanButton.classList.add('d-none');
        }
        if (resetMessage) {
            setScanStatus('Use the existing barcode printed on the supply packaging when available.');
        }
    }

    async function detectBarcodeFrame() {
        if (!scanActive || !scanDetector || !scanVideo || scanVideo.readyState < 2) {
            return;
        }

        try {
            var barcodes = await scanDetector.detect(scanVideo);
            if (!barcodes || !barcodes.length) {
                return;
            }

            var rawValue = (barcodes[0].rawValue || '').trim();
            if (rawValue) {
                fillBarcodeValue(rawValue);
            }
        } catch (error) {
            setScanStatus('Camera is active, but the browser has not read a barcode yet.');
        }
    }

    async function startHtml5ScannerFallback() {
        if (!window.Html5Qrcode || !scanPanel) {
            setScanStatus('Fallback barcode scanner is not available on this browser.');
            return;
        }

        var readerId = 'stockCatalogBarcodeReader';
        var readerNode = document.getElementById(readerId);
        if (!readerNode) {
            readerNode = document.createElement('div');
            readerNode.id = readerId;
            readerNode.className = 'inventory-camera-reader';
            scanPanel.appendChild(readerNode);
        }

        html5QrScanner = new window.Html5Qrcode(readerId);
        await html5QrScanner.start(
            { facingMode: 'environment' },
            {
                fps: 10,
                qrbox: { width: 260, height: 140 },
                formatsToSupport: [
                    window.Html5QrcodeSupportedFormats.CODE_128,
                    window.Html5QrcodeSupportedFormats.CODE_39,
                    window.Html5QrcodeSupportedFormats.EAN_13,
                    window.Html5QrcodeSupportedFormats.EAN_8,
                    window.Html5QrcodeSupportedFormats.UPC_A,
                    window.Html5QrcodeSupportedFormats.UPC_E
                ]
            },
            function (decodedText) {
                if (decodedText) {
                    fillBarcodeValue(decodedText);
                }
            },
            function () {
                // Ignore decode misses.
            }
        );
        setScanStatus('Camera barcode scanner is live. Point the packaging barcode inside the frame.');
    }

    async function startBarcodeScanner() {
        if (!barcodeInput) {
            return;
        }

        if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
            setScanStatus('Camera scanning is not available on this browser.');
            return;
        }

        try {
            scanActive = true;
            if (scanPanel) {
                scanPanel.classList.remove('d-none');
            }
            if (startScanButton) {
                startScanButton.classList.add('d-none');
            }
            if (stopScanButton) {
                stopScanButton.classList.remove('d-none');
            }

            if ('BarcodeDetector' in window) {
                scanDetector = new window.BarcodeDetector({
                    formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']
                });
                scanStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false
                });
                if (scanVideo) {
                    scanVideo.srcObject = scanStream;
                }
                setScanStatus('Camera barcode scanner is live. Point the packaging barcode inside the frame.');
                scanTimer = window.setInterval(detectBarcodeFrame, 600);
            } else {
                if (scanVideo) {
                    scanVideo.classList.add('d-none');
                }
                await startHtml5ScannerFallback();
            }
        } catch (error) {
            stopBarcodeScanner(false);
            setScanStatus('Unable to start the camera. Check camera permission and try again.');
        }
    }

    if (startScanButton) {
        startScanButton.addEventListener('click', function () {
            startBarcodeScanner();
        });
    }

    if (stopScanButton) {
        stopScanButton.addEventListener('click', function () {
            stopBarcodeScanner(true);
        });
    }

    window.addEventListener('beforeunload', function () {
        stopBarcodeScanner(false);
    });
});
</script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
.inventory-camera-panel video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.inventory-camera-reader {
    width: 100%;
    min-height: 240px;
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
