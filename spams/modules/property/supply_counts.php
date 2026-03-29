<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$db = db();
$page_title = 'Supply Count Workspace';
$flash = get_flash();
$errors = [];
$sessions = [];
$selectedSession = null;
$sessionItems = [];
$sessionStats = [
    'total_items' => 0,
    'system_qty' => 0,
    'counted_qty' => 0,
    'variance_qty' => 0,
    'matched' => 0,
    'exceptions' => 0,
];
$countTypes = [
    'annual' => 'Annual Count',
    'surprise' => 'Surprise Check',
];
$statusLabels = [
    'pending' => 'Pending',
    'match' => 'Match',
    'shortage' => 'Shortage',
    'overage' => 'Overage',
    'not_counted' => 'Not Counted',
];
$statusBadgeClasses = [
    'pending' => 'text-bg-secondary',
    'match' => 'text-bg-success',
    'shortage' => 'text-bg-warning',
    'overage' => 'text-bg-danger',
    'not_counted' => 'text-bg-dark',
];
$selectedSessionId = (int) ($_GET['session_id'] ?? 0);
$highlightItemId = (int) ($_GET['highlight_item_id'] ?? 0);
$scanFeedback = trim((string) ($_GET['scan_feedback'] ?? ''));
$referencePreview = preview_module_code($db, 'supply_counts');

function build_supply_count_url(array $overrides = []): string
{
    $params = [
        'session_id' => $_GET['session_id'] ?? '',
        'highlight_item_id' => $_GET['highlight_item_id'] ?? '',
        'scan_feedback' => $_GET['scan_feedback'] ?? '',
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }

    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

function normalize_supply_count_status(string $status): string
{
    $allowed = ['pending', 'match', 'shortage', 'overage', 'not_counted'];
    return in_array($status, $allowed, true) ? $status : 'pending';
}

function compute_supply_count_status(?float $countedQty, float $systemQty): array
{
    if ($countedQty === null) {
        return ['pending', null];
    }

    $variance = round($countedQty - $systemQty, 2);

    if ($countedQty == 0.0 && $systemQty > 0) {
        return ['not_counted', $variance];
    }
    if ($variance == 0.0) {
        return ['match', 0.0];
    }
    if ($variance < 0) {
        return ['shortage', $variance];
    }

    return ['overage', $variance];
}

function extract_supply_scan_code(string $rawValue): string
{
    return trim($rawValue);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    if (empty($errors) && $action === 'create_session') {
        $countType = trim((string) ($_POST['count_type'] ?? 'annual'));
        $countDate = trim((string) ($_POST['count_date'] ?? date('Y-m-d')));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (!isset($countTypes[$countType])) {
            $errors[] = 'Invalid count type.';
        }
        if ($countDate === '') {
            $errors[] = 'Count date is required.';
        }

        if (empty($errors)) {
            $db->begin_transaction();

            try {
                $reference = next_module_code($db, 'supply_counts');
                $userId = current_user_id();

                $sessionStmt = $db->prepare("
                    INSERT INTO supply_count_sessions
                        (system_reference, count_type, count_date, notes, created_by)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if (!$sessionStmt) {
                    throw new RuntimeException('Unable to create supply count session.');
                }

                $sessionStmt->bind_param('ssssi', $reference, $countType, $countDate, $notes, $userId);
                if (!$sessionStmt->execute()) {
                    $sessionStmt->close();
                    throw new RuntimeException('Unable to save supply count session.');
                }
                $sessionId = (int) $sessionStmt->insert_id;
                $sessionStmt->close();

                $stocks = [];
                $stockResult = $db->query("
                    SELECT
                        si.id AS stock_item_id,
                        si.system_reference AS stock_reference,
                        sc.id AS stock_catalog_id,
                        sc.stock_no,
                        sc.barcode,
                        sc.item_name,
                        c.classification_name,
                        ac.account_code,
                        uom.unit_name AS unit_of_measure,
                        si.quantity_on_hand AS system_quantity
                    FROM stock_items si
                    INNER JOIN stock_catalog sc ON sc.id = si.stock_catalog_id
                    LEFT JOIN classifications c ON c.id = sc.classification_id
                    LEFT JOIN account_codes ac ON ac.id = si.account_code_id
                    LEFT JOIN unit_of_measures uom ON uom.id = si.unit_of_measure_id
                    WHERE si.item_type = 'supply'
                      AND sc.is_active = 1
                      AND si.quantity_on_hand >= 0
                    ORDER BY sc.item_name ASC, sc.stock_no ASC, si.system_reference ASC
                ");

                if ($stockResult instanceof mysqli_result) {
                    $stocks = $stockResult->fetch_all(MYSQLI_ASSOC);
                }

                $itemStmt = $db->prepare("
                    INSERT INTO supply_count_items
                        (session_id, stock_item_id, stock_catalog_id, stock_reference, stock_no, barcode, item_description, classification_name, account_code, unit_of_measure, system_quantity)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$itemStmt) {
                    throw new RuntimeException('Unable to prepare supply count items.');
                }

                $loadedCount = 0;
                foreach ($stocks as $stock) {
                    $sessionIdValue = $sessionId;
                    $stockItemId = (int) $stock['stock_item_id'];
                    $stockCatalogId = !empty($stock['stock_catalog_id']) ? (int) $stock['stock_catalog_id'] : null;
                    $stockReference = (string) $stock['stock_reference'];
                    $stockNo = (string) ($stock['stock_no'] ?? '');
                    $barcode = (string) ($stock['barcode'] ?? '');
                    $itemDescription = (string) ($stock['item_name'] ?? '');
                    $classificationName = (string) ($stock['classification_name'] ?? '');
                    $accountCode = (string) ($stock['account_code'] ?? '');
                    $unitOfMeasure = (string) ($stock['unit_of_measure'] ?? '');
                    $systemQuantity = (float) ($stock['system_quantity'] ?? 0);

                    $itemStmt->bind_param(
                        'iiisssssssd',
                        $sessionIdValue,
                        $stockItemId,
                        $stockCatalogId,
                        $stockReference,
                        $stockNo,
                        $barcode,
                        $itemDescription,
                        $classificationName,
                        $accountCode,
                        $unitOfMeasure,
                        $systemQuantity
                    );
                    if (!$itemStmt->execute()) {
                        throw new RuntimeException('Unable to preload supply stock items.');
                    }
                    $loadedCount++;
                }
                $itemStmt->close();

                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'supply_count_sessions',
                    'record_id' => $sessionId,
                    'module_name' => 'supply_counts',
                    'record_type' => 'supply_count_session',
                    'action_name' => 'create_supply_count_session',
                    'new_values' => [
                        'system_reference' => $reference,
                        'count_type' => $countType,
                        'count_date' => $countDate,
                        'loaded_items' => $loadedCount,
                    ],
                    'description' => 'Created a supply physical count session.',
                ]);

                $db->commit();
                set_flash('success', 'Supply count session created with ' . number_format($loadedCount) . ' stock line(s).');
                redirect('modules/property/supply_counts.php?session_id=' . $sessionId);
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = $e->getMessage();
            }
        }
    }

    if (empty($errors) && ($action === 'update_item' || $action === 'scan_item')) {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $errors[] = 'Invalid supply count session.';
        } else {
            $sessionStmt = $db->prepare("SELECT id, status FROM supply_count_sessions WHERE id = ? LIMIT 1");
            $sessionRow = null;
            if ($sessionStmt) {
                $sessionStmt->bind_param('i', $sessionId);
                $sessionStmt->execute();
                $sessionRow = $sessionStmt->get_result()->fetch_assoc();
                $sessionStmt->close();
            }

            if (!$sessionRow) {
                $errors[] = 'Supply count session not found.';
            } elseif (($sessionRow['status'] ?? '') !== 'open') {
                $errors[] = 'This supply count session is already closed.';
            } elseif ($action === 'update_item') {
                $itemId = (int) ($_POST['item_id'] ?? 0);
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                $countedQuantityRaw = trim((string) ($_POST['counted_quantity'] ?? ''));
                $countedQuantity = $countedQuantityRaw === '' ? null : (float) $countedQuantityRaw;

                $lookupStmt = $db->prepare("SELECT id, system_quantity, counted_quantity, count_status FROM supply_count_items WHERE id = ? AND session_id = ? LIMIT 1");
                $itemRow = null;
                if ($lookupStmt) {
                    $lookupStmt->bind_param('ii', $itemId, $sessionId);
                    $lookupStmt->execute();
                    $itemRow = $lookupStmt->get_result()->fetch_assoc();
                    $lookupStmt->close();
                }

                if (!$itemRow) {
                    $errors[] = 'Supply count item not found.';
                } else {
                    [$countStatus, $variance] = compute_supply_count_status($countedQuantity, (float) $itemRow['system_quantity']);
                    $userId = current_user_id();
                    $updateStmt = $db->prepare("
                        UPDATE supply_count_items
                        SET counted_quantity = ?, variance_quantity = ?, count_status = ?, remarks = ?, checked_at = NOW(), checked_by = ?
                        WHERE id = ? AND session_id = ?
                    ");

                    if ($updateStmt) {
                        $updateStmt->bind_param('ddssiii', $countedQuantity, $variance, $countStatus, $remarks, $userId, $itemId, $sessionId);
                        $ok = $updateStmt->execute();
                        $updateStmt->close();
                        if ($ok) {
                            write_audit_log($db, [
                                'action' => 'update',
                                'table_name' => 'supply_count_items',
                                'record_id' => $itemId,
                                'module_name' => 'supply_counts',
                                'record_type' => 'supply_count_item',
                                'action_name' => 'update_supply_count_item',
                                'old_values' => ['counted_quantity' => $itemRow['counted_quantity'], 'count_status' => $itemRow['count_status']],
                                'new_values' => ['counted_quantity' => $countedQuantity, 'count_status' => $countStatus, 'remarks' => $remarks],
                                'description' => 'Updated counted quantity for a supply count item.',
                            ]);
                            set_flash('success', 'Counted quantity updated.');
                            redirect('modules/property/supply_counts.php?session_id=' . $sessionId . '&highlight_item_id=' . $itemId);
                        }
                    }
                    $errors[] = 'Unable to update the counted quantity.';
                }
            } elseif ($action === 'scan_item') {
                $scanValue = extract_supply_scan_code((string) ($_POST['scan_value'] ?? ''));
                if ($scanValue === '') {
                    $errors[] = 'Scan or enter a packaging barcode first.';
                } else {
                    $matchStmt = $db->prepare("
                        SELECT id, counted_quantity, system_quantity, barcode, stock_no, stock_reference
                        FROM supply_count_items
                        WHERE session_id = ?
                          AND (barcode = ? OR stock_no = ? OR stock_reference = ?)
                        ORDER BY CASE WHEN barcode = ? THEN 1 WHEN stock_no = ? THEN 2 ELSE 3 END
                        LIMIT 1
                    ");
                    $matchedItem = null;
                    if ($matchStmt) {
                        $matchStmt->bind_param('isssss', $sessionId, $scanValue, $scanValue, $scanValue, $scanValue, $scanValue);
                        $matchStmt->execute();
                        $matchedItem = $matchStmt->get_result()->fetch_assoc();
                        $matchStmt->close();
                    }

                    if (!$matchedItem) {
                        $errors[] = 'Scanned code does not match any loaded supply item in this session.';
                    } else {
                        $newCountedQty = round((float) ($matchedItem['counted_quantity'] ?? 0) + 1, 2);
                        [$countStatus, $variance] = compute_supply_count_status($newCountedQty, (float) $matchedItem['system_quantity']);
                        $userId = current_user_id();
                        $itemId = (int) $matchedItem['id'];

                        $updateStmt = $db->prepare("
                            UPDATE supply_count_items
                            SET counted_quantity = ?, variance_quantity = ?, count_status = ?, checked_at = NOW(), checked_by = ?
                            WHERE id = ? AND session_id = ?
                        ");
                        if ($updateStmt) {
                            $updateStmt->bind_param('ddsiii', $newCountedQty, $variance, $countStatus, $userId, $itemId, $sessionId);
                            $ok = $updateStmt->execute();
                            $updateStmt->close();
                            if ($ok) {
                                write_audit_log($db, [
                                    'action' => 'update',
                                    'table_name' => 'supply_count_items',
                                    'record_id' => $itemId,
                                    'module_name' => 'supply_counts',
                                    'record_type' => 'supply_count_item',
                                    'action_name' => 'scan_supply_count_item',
                                    'new_values' => ['scan_code' => $scanValue, 'counted_quantity' => $newCountedQty, 'count_status' => $countStatus],
                                    'description' => 'Scanned and incremented a supply count item.',
                                ]);
                                redirect('modules/property/supply_counts.php?session_id=' . $sessionId . '&highlight_item_id=' . $itemId . '&scan_feedback=success');
                            }
                        }
                        $errors[] = 'Unable to process the scanned supply barcode.';
                    }
                }
            }
        }
    }

    if (empty($errors) && $action === 'close_session') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        if ($sessionId > 0) {
            $closeStmt = $db->prepare("
                UPDATE supply_count_sessions
                SET status = 'closed', closed_by = ?, closed_at = NOW()
                WHERE id = ? AND status = 'open'
            ");
            if ($closeStmt) {
                $userId = current_user_id();
                $closeStmt->bind_param('ii', $userId, $sessionId);
                $ok = $closeStmt->execute();
                $closeStmt->close();
                if ($ok) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'supply_count_sessions',
                        'record_id' => $sessionId,
                        'module_name' => 'supply_counts',
                        'record_type' => 'supply_count_session',
                        'action_name' => 'close_supply_count_session',
                        'new_values' => ['status' => 'closed'],
                        'description' => 'Closed a supply count session.',
                    ]);
                    set_flash('success', 'Supply count session closed.');
                    redirect('modules/property/supply_counts.php?session_id=' . $sessionId);
                }
            }
            $errors[] = 'Unable to close the session.';
        }
    }
}

$sessionResult = $db->query("
    SELECT scs.*, u.full_name AS created_by_name
    FROM supply_count_sessions scs
    LEFT JOIN users u ON u.id = scs.created_by
    ORDER BY scs.id DESC
");
if ($sessionResult instanceof mysqli_result) {
    $sessions = $sessionResult->fetch_all(MYSQLI_ASSOC);
}

if ($selectedSessionId <= 0 && !empty($sessions)) {
    $selectedSessionId = (int) $sessions[0]['id'];
}

if ($selectedSessionId > 0) {
    $sessionStmt = $db->prepare("
        SELECT scs.*, cu.full_name AS created_by_name, xu.full_name AS closed_by_name
        FROM supply_count_sessions scs
        LEFT JOIN users cu ON cu.id = scs.created_by
        LEFT JOIN users xu ON xu.id = scs.closed_by
        WHERE scs.id = ?
        LIMIT 1
    ");
    if ($sessionStmt) {
        $sessionStmt->bind_param('i', $selectedSessionId);
        $sessionStmt->execute();
        $selectedSession = $sessionStmt->get_result()->fetch_assoc();
        $sessionStmt->close();
    }

    if ($selectedSession) {
        $statsStmt = $db->prepare("
            SELECT
                COUNT(*) AS total_items,
                COALESCE(SUM(system_quantity), 0) AS system_qty,
                COALESCE(SUM(COALESCE(counted_quantity, 0)), 0) AS counted_qty,
                COALESCE(SUM(COALESCE(variance_quantity, 0)), 0) AS variance_qty,
                SUM(CASE WHEN count_status = 'match' THEN 1 ELSE 0 END) AS matched,
                SUM(CASE WHEN count_status IN ('shortage', 'overage', 'not_counted') THEN 1 ELSE 0 END) AS exceptions
            FROM supply_count_items
            WHERE session_id = ?
        ");
        if ($statsStmt) {
            $statsStmt->bind_param('i', $selectedSessionId);
            $statsStmt->execute();
            $stats = $statsStmt->get_result()->fetch_assoc();
            $statsStmt->close();
            if ($stats) {
                $sessionStats = array_merge($sessionStats, $stats);
            }
        }

        $itemsStmt = $db->prepare("
            SELECT *
            FROM supply_count_items
            WHERE session_id = ?
            ORDER BY item_description ASC, stock_no ASC, stock_reference ASC
        ");
        if ($itemsStmt) {
            $itemsStmt->bind_param('i', $selectedSessionId);
            $itemsStmt->execute();
            $sessionItems = $itemsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $itemsStmt->close();
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Supply Count Workspace</h1>
            <p class="text-muted mb-0">Run annual counts or surprise checks for supply stock using counted quantities and packaging barcodes.</p>
        </div>
        <div class="text-end">
            <div class="small text-muted">Next reference</div>
            <div class="fw-semibold"><?php echo h($referencePreview ?: 'SCI-' . date('Y') . '-0001'); ?></div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo h($flash['type']); ?> mb-3"><?php echo h($flash['message']); ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger mb-3">
            <?php foreach ($errors as $error): ?>
                <div><?php echo h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Create Count Session</h5>
                    <form method="post" class="row g-3">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="create_session">
                        <div class="col-12">
                            <label class="form-label">Count Type</label>
                            <select class="form-select" name="count_type">
                                <?php foreach ($countTypes as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Count Date</label>
                            <input type="date" class="form-control" name="count_date" value="<?php echo h(date('Y-m-d')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="Optional count notes"></textarea>
                        </div>
                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-primary">Create Session and Preload Stock</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <h5 class="card-title mb-0">Recent Sessions</h5>
                        <?php if ($selectedSession): ?>
                            <a href="<?php echo h(base_url('modules/property/supply_count_print.php?session_id=' . (int) $selectedSession['id'])); ?>" class="btn btn-outline-secondary btn-sm">Print Result</a>
                        <?php endif; ?>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if ($sessions): ?>
                            <?php foreach ($sessions as $session): ?>
                                <a class="list-group-item list-group-item-action <?php echo (int) $session['id'] === $selectedSessionId ? 'active' : ''; ?>" href="<?php echo h(base_url('modules/property/supply_counts.php?session_id=' . (int) $session['id'])); ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold"><?php echo h($session['system_reference']); ?></div>
                                            <div class="small <?php echo (int) $session['id'] === $selectedSessionId ? 'text-white-50' : 'text-muted'; ?>">
                                                <?php echo h($countTypes[$session['count_type']] ?? ucfirst($session['count_type'])); ?> | <?php echo h(format_date($session['count_date'])); ?>
                                            </div>
                                        </div>
                                        <span class="badge <?php echo ($session['status'] ?? '') === 'closed' ? 'text-bg-dark' : 'text-bg-success'; ?>">
                                            <?php echo h(ucfirst((string) $session['status'])); ?>
                                        </span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">No supply count sessions yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($selectedSession): ?>
        <div class="row g-3 mt-1 mb-4">
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">Loaded Lines</div><div class="fs-4 fw-bold"><?php echo number_format((float) $sessionStats['total_items']); ?></div></div></div>
            </div>
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">System Qty</div><div class="fs-4 fw-bold"><?php echo format_quantity($sessionStats['system_qty']); ?></div></div></div>
            </div>
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">Counted Qty</div><div class="fs-4 fw-bold"><?php echo format_quantity($sessionStats['counted_qty']); ?></div></div></div>
            </div>
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">Matched</div><div class="fs-4 fw-bold"><?php echo number_format((float) $sessionStats['matched']); ?></div></div></div>
            </div>
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">Exceptions</div><div class="fs-4 fw-bold"><?php echo number_format((float) $sessionStats['exceptions']); ?></div></div></div>
            </div>
            <div class="col-md-4 col-xl-2">
                <div class="card"><div class="card-body"><div class="small text-muted">Variance Qty</div><div class="fs-4 fw-bold"><?php echo format_quantity($sessionStats['variance_qty']); ?></div></div></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                        <div>
                            <h5 class="card-title mb-1"><?php echo h($selectedSession['system_reference']); ?></h5>
                            <div class="text-muted small">
                                <?php echo h($countTypes[$selectedSession['count_type']] ?? ucfirst($selectedSession['count_type'])); ?> |
                            <?php echo h(format_date($selectedSession['count_date'])); ?> |
                            Created by <?php echo h($selectedSession['created_by_name'] ?: 'Unknown User'); ?>
                        </div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?php echo h(base_url('modules/property/stock_adjustments.php?session_id=' . (int) $selectedSession['id'])); ?>" class="btn btn-outline-secondary">Open Adjustments</a>
                            <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                                <form method="post">
                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                    <input type="hidden" name="action" value="close_session">
                                <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                                <button type="submit" class="btn btn-dark">Close Session</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="scan_item">
                        <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                        <div class="col-lg-9">
                            <label class="form-label">Scan Packaging Barcode</label>
                            <input type="text" name="scan_value" id="scan_value" class="form-control form-control-lg" placeholder="Scan packaging barcode, stock no., or stock reference" autocomplete="off">
                        </div>
                        <div class="col-lg-3 d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">Scan Supply</button>
                        </div>
                    </form>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary" id="startBarcodeScan">
                            <i class="bi bi-camera-video me-1"></i>Use Camera
                        </button>
                        <button type="button" class="btn btn-outline-secondary d-none" id="stopBarcodeScan">
                            <i class="bi bi-stop-circle me-1"></i>Stop Camera
                        </button>
                        <span class="small text-muted align-self-center" id="barcodeScanStatus">
                            You can use a handheld scanner, type the code, or open the camera scanner.
                        </span>
                    </div>
                    <div class="inventory-camera-panel d-none mt-3" id="barcodeScanPanel">
                        <div class="ratio ratio-16x9 rounded overflow-hidden bg-dark">
                            <video id="barcodeScanVideo" autoplay playsinline muted></video>
                        </div>
                        <div class="small text-muted mt-2">
                            Point the packaging barcode inside the frame. It will fill and submit automatically when detected.
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="min-width: 280px;">Supply Item</th>
                                <th style="min-width: 160px;">Reference</th>
                                <th style="min-width: 120px;">System Qty</th>
                                <th style="min-width: 120px;">Counted Qty</th>
                                <th style="min-width: 120px;">Variance</th>
                                <th style="min-width: 140px;">Status</th>
                                <th style="min-width: 280px;">Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sessionItems): ?>
                                <?php foreach ($sessionItems as $item): ?>
                                    <?php $statusKey = normalize_supply_count_status((string) ($item['count_status'] ?? 'pending')); ?>
                                    <tr id="supply-count-item-<?php echo (int) $item['id']; ?>" class="<?php echo $highlightItemId === (int) $item['id'] ? 'inventory-count-highlight' : ''; ?>">
                                        <td>
                                            <div class="fw-semibold"><?php echo h($item['item_description']); ?></div>
                                            <div class="small text-muted">
                                                <?php echo h(trim(implode(' | ', array_filter([
                                                    $item['classification_name'] ?? '',
                                                    $item['unit_of_measure'] ?? '',
                                                    !empty($item['barcode']) ? 'Barcode: ' . $item['barcode'] : '',
                                                ])))); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div><?php echo h($item['stock_no'] ?: '-'); ?></div>
                                            <div class="small text-muted"><?php echo h($item['stock_reference']); ?></div>
                                        </td>
                                        <td><?php echo format_quantity($item['system_quantity']); ?></td>
                                        <td><?php echo $item['counted_quantity'] !== null ? format_quantity($item['counted_quantity']) : '<span class="text-muted">-</span>'; ?></td>
                                        <td><?php echo $item['variance_quantity'] !== null ? format_quantity($item['variance_quantity']) : '<span class="text-muted">-</span>'; ?></td>
                                        <td><span class="badge <?php echo h($statusBadgeClasses[$statusKey] ?? 'text-bg-secondary'); ?>"><?php echo h($statusLabels[$statusKey] ?? ucfirst($statusKey)); ?></span></td>
                                        <td>
                                            <?php if (($selectedSession['status'] ?? '') === 'open'): ?>
                                                <form method="post" class="row g-2">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="update_item">
                                                    <input type="hidden" name="session_id" value="<?php echo (int) $selectedSession['id']; ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                    <div class="col-md-4">
                                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="counted_quantity" value="<?php echo h($item['counted_quantity'] !== null ? (string) $item['counted_quantity'] : ''); ?>" placeholder="Qty">
                                                    </div>
                                                    <div class="col-md-5">
                                                        <input type="text" class="form-control form-control-sm" name="remarks" value="<?php echo h((string) ($item['remarks'] ?? '')); ?>" placeholder="Optional note">
                                                    </div>
                                                    <div class="col-md-3 d-grid">
                                                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                                    </div>
                                                </form>
                                            <?php else: ?>
                                                <div class="text-muted small">Session closed</div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No supply items loaded yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var scanInput = document.getElementById('scan_value');
    var highlightedRow = document.querySelector('.inventory-count-highlight');
    var scanForm = scanInput ? scanInput.closest('form') : null;
    var startScanButton = document.getElementById('startBarcodeScan');
    var stopScanButton = document.getElementById('stopBarcodeScan');
    var scanPanel = document.getElementById('barcodeScanPanel');
    var scanVideo = document.getElementById('barcodeScanVideo');
    var scanStatus = document.getElementById('barcodeScanStatus');
    var scanStream = null;
    var scanDetector = null;
    var scanTimer = null;
    var scanActive = false;
    var html5QrScanner = null;

    function playTone(frequency, duration) {
        try {
            var audioContext = new (window.AudioContext || window.webkitAudioContext)();
            var oscillator = audioContext.createOscillator();
            var gainNode = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, audioContext.currentTime);
            gainNode.gain.setValueAtTime(0.001, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + duration);
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.start();
            oscillator.stop(audioContext.currentTime + duration);
        } catch (error) {}
    }

    function setScanStatus(message, tone) {
        if (scanStatus) {
            scanStatus.textContent = message;
        }
        if (tone === 'success') {
            playTone(880, 0.18);
        } else if (tone === 'warning') {
            playTone(440, 0.22);
        }
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
                    } catch (error) {}
                });
            } catch (error) {}
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
            setScanStatus('You can use a handheld scanner, type the code, or open the camera scanner.');
        }
    }

    function submitScannedCode(value) {
        if (!value || !scanInput || !scanForm) {
            return;
        }
        scanInput.value = String(value).trim();
        setScanStatus('Barcode detected. Submitting scanned item...', 'success');
        stopBarcodeScanner(false);
        scanForm.submit();
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
                submitScannedCode(rawValue);
            }
        } catch (error) {
            setScanStatus('Camera is active, but the browser has not read a barcode yet.');
        }
    }

    async function startHtml5ScannerFallback() {
        if (!window.Html5Qrcode || !scanPanel) {
            setScanStatus('Fallback barcode scanner is not available on this browser.', 'warning');
            return;
        }

        var readerId = 'supplyCountBarcodeReader';
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
                    submitScannedCode(decodedText);
                }
            },
            function () {}
        );
        setScanStatus('Camera barcode scanner is live. Point the packaging barcode inside the frame.');
    }

    async function startBarcodeScanner() {
        if (!scanInput || !scanForm) {
            return;
        }

        if (!('mediaDevices' in navigator) || !navigator.mediaDevices.getUserMedia) {
            setScanStatus('Camera scanning is not available on this browser.', 'warning');
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
            setScanStatus('Unable to start the camera. Check camera permission and try again.', 'warning');
        }
    }

    if (scanInput) {
        scanInput.focus();
        scanInput.select();
    }

    if (highlightedRow) {
        highlightedRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    if (<?php echo json_encode($scanFeedback === 'success'); ?>) {
        playTone(880, 0.18);
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
.inventory-count-highlight {
    animation: inventoryCountPulse 1.5s ease-in-out 2;
    box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.12);
}

.inventory-camera-panel video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.inventory-camera-reader {
    width: 100%;
    min-height: 240px;
}

@keyframes inventoryCountPulse {
    0% { box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.04); }
    50% { box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.22); }
    100% { box-shadow: inset 0 0 0 9999px rgba(25, 135, 84, 0.12); }
}
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
