<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer');

$db = db();
$page_title = 'Stock Adjustments';
$flash = get_flash();
$errors = [];
$sessions = [];
$rows = [];
$recentAdjustments = [];
$selectedSession = null;
$adjustmentStats = [
    'lines' => 0,
    'shortage' => 0,
    'overage' => 0,
    'not_counted' => 0,
    'pending' => 0,
    'approved' => 0,
    'cancelled' => 0,
];
$selectedSessionId = (int) ($_GET['session_id'] ?? 0);
$referencePreview = preview_module_code($db, 'stock_adjustments');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    if (empty($errors) && $action === 'post_adjustment') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $adjustmentDate = trim((string) ($_POST['adjustment_date'] ?? date('Y-m-d')));
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        $selectedItems = $_POST['items'] ?? [];

        if ($sessionId <= 0) {
            $errors[] = 'Select a supply count session first.';
        }
        if ($adjustmentDate === '') {
            $errors[] = 'Adjustment date is required.';
        }
        if (!is_array($selectedItems) || !$selectedItems) {
            $errors[] = 'Select at least one discrepancy line to adjust.';
        }

        if (empty($errors)) {
            $placeholders = implode(',', array_fill(0, count($selectedItems), '?'));
            $types = str_repeat('i', count($selectedItems) + 1);
            $params = array_merge([$sessionId], array_map('intval', array_keys($selectedItems)));

            $sql = "
                SELECT sci.*, si.quantity_on_hand
                FROM supply_count_items sci
                INNER JOIN stock_items si ON si.id = sci.stock_item_id
                WHERE sci.session_id = ?
                  AND sci.id IN ($placeholders)
                  AND sci.count_status IN ('shortage', 'overage', 'not_counted')
            ";

            $stmt = $db->prepare($sql);
            $lines = [];
            if ($stmt) {
                $refs = [$types];
                foreach ($params as $key => $value) {
                    $refs[] = &$params[$key];
                }
                call_user_func_array([$stmt, 'bind_param'], $refs);
                $stmt->execute();
                $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }

            if (!$lines) {
                $errors[] = 'No valid discrepancy lines were selected.';
            } else {
                $db->begin_transaction();

                try {
                    $reference = next_module_code($db, 'stock_adjustments');
                    $userId = current_user_id();

                    $headerStmt = $db->prepare("
                        INSERT INTO stock_adjustments
                            (system_reference, supply_count_session_id, adjustment_date, remarks, created_by)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    if (!$headerStmt) {
                        throw new RuntimeException('Unable to create stock adjustment.');
                    }
                    $headerStmt->bind_param('sissi', $reference, $sessionId, $adjustmentDate, $remarks, $userId);
                    if (!$headerStmt->execute()) {
                        $headerStmt->close();
                        throw new RuntimeException('Unable to save stock adjustment header.');
                    }
                    $adjustmentId = (int) $headerStmt->insert_id;
                    $headerStmt->close();

                    $itemStmt = $db->prepare("
                        INSERT INTO stock_adjustment_items
                            (stock_adjustment_id, supply_count_item_id, stock_item_id, system_quantity, counted_quantity, variance_quantity, adjustment_type, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    if (!$itemStmt) {
                        throw new RuntimeException('Unable to prepare stock adjustment posting.');
                    }

                    foreach ($lines as $line) {
                        $variance = (float) ($line['variance_quantity'] ?? 0);
                        $countedQuantity = (float) ($line['counted_quantity'] ?? 0);
                        $systemQuantity = (float) ($line['system_quantity'] ?? 0);
                        $adjustmentType = $variance >= 0 ? 'increase' : 'decrease';
                        $lineRemarks = trim((string) ($line['remarks'] ?? ''));

                        $itemStmt->bind_param(
                            'iiidddss',
                            $adjustmentId,
                            $line['id'],
                            $line['stock_item_id'],
                            $systemQuantity,
                            $countedQuantity,
                            $variance,
                            $adjustmentType,
                            $lineRemarks
                        );
                        if (!$itemStmt->execute()) {
                            throw new RuntimeException('Unable to save stock adjustment item.');
                        }
                    }

                    $itemStmt->close();

                    write_audit_log($db, [
                        'action' => 'insert',
                        'table_name' => 'stock_adjustments',
                        'record_id' => $adjustmentId,
                        'module_name' => 'stock_adjustments',
                        'record_type' => 'stock_adjustment',
                        'action_name' => 'create_stock_adjustment',
                        'new_values' => [
                            'system_reference' => $reference,
                            'supply_count_session_id' => $sessionId,
                            'line_count' => count($lines),
                            'status' => 'pending',
                        ],
                        'description' => 'Created pending stock adjustment from supply count discrepancies.',
                    ]);

                    $db->commit();
                    set_flash('success', 'Stock adjustment saved as pending approval.');
                    redirect('modules/property/stock_adjustments.php?session_id=' . $sessionId);
                } catch (Throwable $e) {
                    $db->rollback();
                    $errors[] = $e->getMessage();
                }
            }
        }
    }

    if (empty($errors) && $action === 'approve_adjustment') {
        $adjustmentId = (int) ($_POST['adjustment_id'] ?? 0);
        if (!in_array($_SESSION['user_role'] ?? '', ['Administrator', 'admin'], true)) {
            $errors[] = 'Only administrators can approve stock adjustments.';
        } elseif ($adjustmentId <= 0) {
            $errors[] = 'Invalid stock adjustment.';
        } else {
            $db->begin_transaction();
            try {
                $headerStmt = $db->prepare("
                    SELECT *
                    FROM stock_adjustments
                    WHERE id = ? AND status = 'pending'
                    LIMIT 1
                ");
                $header = null;
                if ($headerStmt) {
                    $headerStmt->bind_param('i', $adjustmentId);
                    $headerStmt->execute();
                    $header = $headerStmt->get_result()->fetch_assoc();
                    $headerStmt->close();
                }

                if (!$header) {
                    throw new RuntimeException('Pending stock adjustment not found.');
                }

                $linesStmt = $db->prepare("
                    SELECT *
                    FROM stock_adjustment_items
                    WHERE stock_adjustment_id = ?
                ");
                $lines = [];
                if ($linesStmt) {
                    $linesStmt->bind_param('i', $adjustmentId);
                    $linesStmt->execute();
                    $lines = $linesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $linesStmt->close();
                }

                if (!$lines) {
                    throw new RuntimeException('No adjustment lines found.');
                }

                $stockStmt = $db->prepare("
                    UPDATE stock_items
                    SET quantity_on_hand = ?, updated_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $movementStmt = $db->prepare("
                    INSERT INTO stock_movements
                        (stock_item_id, movement_type, movement_date, reference_type, reference_id, quantity_in, quantity_out, balance_after, remarks, created_by)
                    VALUES (?, 'adjustment', ?, 'stock_adjustment', ?, ?, ?, ?, ?, ?)
                ");
                if (!$stockStmt || !$movementStmt) {
                    throw new RuntimeException('Unable to prepare approval posting.');
                }

                $userId = current_user_id();
                foreach ($lines as $line) {
                    $variance = (float) ($line['variance_quantity'] ?? 0);
                    $countedQuantity = (float) ($line['counted_quantity'] ?? 0);
                    $qtyIn = $variance > 0 ? $variance : 0.00;
                    $qtyOut = $variance < 0 ? abs($variance) : 0.00;
                    $balanceAfter = $countedQuantity;
                    $stockItemId = (int) $line['stock_item_id'];

                    $stockStmt->bind_param('dii', $balanceAfter, $userId, $stockItemId);
                    if (!$stockStmt->execute()) {
                        throw new RuntimeException('Unable to update stock on hand.');
                    }

                    $movementNote = 'Approved stock adjustment ' . ($header['system_reference'] ?? '');
                    $movementStmt->bind_param('isidddsi', $stockItemId, $header['adjustment_date'], $adjustmentId, $qtyIn, $qtyOut, $balanceAfter, $movementNote, $userId);
                    if (!$movementStmt->execute()) {
                        throw new RuntimeException('Unable to save stock movement.');
                    }
                }
                $stockStmt->close();
                $movementStmt->close();

                $approveStmt = $db->prepare("
                    UPDATE stock_adjustments
                    SET status = 'approved', approved_by = ?, approved_at = NOW()
                    WHERE id = ?
                ");
                if (!$approveStmt) {
                    throw new RuntimeException('Unable to approve stock adjustment.');
                }
                $approveStmt->bind_param('ii', $userId, $adjustmentId);
                if (!$approveStmt->execute()) {
                    $approveStmt->close();
                    throw new RuntimeException('Unable to save approval.');
                }
                $approveStmt->close();

                write_audit_log($db, [
                    'action' => 'update',
                    'table_name' => 'stock_adjustments',
                    'record_id' => $adjustmentId,
                    'module_name' => 'stock_adjustments',
                    'record_type' => 'stock_adjustment',
                    'action_name' => 'approve_stock_adjustment',
                    'new_values' => ['status' => 'approved'],
                    'description' => 'Approved stock adjustment and posted stock movement.',
                ]);

                $db->commit();
                set_flash('success', 'Stock adjustment approved and posted.');
                redirect('modules/property/stock_adjustments.php?session_id=' . (int) ($header['supply_count_session_id'] ?? 0));
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = $e->getMessage();
            }
        }
    }

    if (empty($errors) && $action === 'cancel_adjustment') {
        $adjustmentId = (int) ($_POST['adjustment_id'] ?? 0);
        $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if ($adjustmentId <= 0) {
            $errors[] = 'Invalid stock adjustment.';
        } elseif ($cancelReason === '') {
            $errors[] = 'Cancellation reason is required.';
        } else {
            $headerStmt = $db->prepare("SELECT status, remarks, supply_count_session_id FROM stock_adjustments WHERE id = ? AND status = 'pending' LIMIT 1");
            $header = null;
            if ($headerStmt) {
                $headerStmt->bind_param('i', $adjustmentId);
                $headerStmt->execute();
                $header = $headerStmt->get_result()->fetch_assoc();
                $headerStmt->close();
            }

            if (!$header) {
                $errors[] = 'Pending stock adjustment not found.';
            }
        }

        if (empty($errors) && $adjustmentId > 0) {
            $cancelStmt = $db->prepare("UPDATE stock_adjustments SET status = 'cancelled', remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, ?)) WHERE id = ? AND status = 'pending'");
            if ($cancelStmt) {
                $cancelNote = 'Cancellation reason: ' . $cancelReason;
                $cancelStmt->bind_param('si', $cancelNote, $adjustmentId);
                $ok = $cancelStmt->execute();
                $cancelStmt->close();
                if ($ok) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'stock_adjustments',
                        'record_id' => $adjustmentId,
                        'module_name' => 'stock_adjustments',
                        'record_type' => 'stock_adjustment',
                        'action_name' => 'cancel_stock_adjustment',
                        'old_values' => ['status' => $header['status'] ?? 'pending', 'remarks' => $header['remarks'] ?? null],
                        'new_values' => ['status' => 'cancelled', 'reason' => $cancelReason],
                        'description' => 'Cancelled pending stock adjustment. Reason: ' . $cancelReason,
                    ]);
                    set_flash('success', 'Pending stock adjustment cancelled.');
                    redirect('modules/property/stock_adjustments.php?session_id=' . (int) ($header['supply_count_session_id'] ?? $selectedSessionId));
                }
            }
            $errors[] = 'Unable to cancel the stock adjustment.';
        }
    }
}

$sessionResult = $db->query("
    SELECT id, system_reference, count_date, status
    FROM supply_count_sessions
    ORDER BY id DESC
");
if ($sessionResult instanceof mysqli_result) {
    $sessions = $sessionResult->fetch_all(MYSQLI_ASSOC);
}
if ($selectedSessionId <= 0 && !empty($sessions)) {
    $selectedSessionId = (int) $sessions[0]['id'];
}

foreach ($sessions as $session) {
    if ((int) ($session['id'] ?? 0) === $selectedSessionId) {
        $selectedSession = $session;
        break;
    }
}

if ($selectedSessionId > 0) {
    $stmt = $db->prepare("
        SELECT sci.*, scs.system_reference AS session_reference
        FROM supply_count_items sci
        INNER JOIN supply_count_sessions scs ON scs.id = sci.session_id
        WHERE sci.session_id = ?
          AND sci.count_status IN ('shortage', 'overage', 'not_counted')
        ORDER BY sci.item_description ASC, sci.stock_no ASC, sci.stock_reference ASC
    ");
    if ($stmt) {
        $stmt->bind_param('i', $selectedSessionId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    $statsStmt = $db->prepare("
        SELECT
            COUNT(*) AS total_lines,
            SUM(CASE WHEN count_status = 'shortage' THEN 1 ELSE 0 END) AS shortage_count,
            SUM(CASE WHEN count_status = 'overage' THEN 1 ELSE 0 END) AS overage_count,
            SUM(CASE WHEN count_status = 'not_counted' THEN 1 ELSE 0 END) AS not_counted_count
        FROM supply_count_items
        WHERE session_id = ?
          AND count_status IN ('shortage', 'overage', 'not_counted')
    ");
    if ($statsStmt) {
        $statsStmt->bind_param('i', $selectedSessionId);
        $statsStmt->execute();
        $statsRow = $statsStmt->get_result()->fetch_assoc();
        $statsStmt->close();
        if ($statsRow) {
            $adjustmentStats['lines'] = (int) ($statsRow['total_lines'] ?? 0);
            $adjustmentStats['shortage'] = (int) ($statsRow['shortage_count'] ?? 0);
            $adjustmentStats['overage'] = (int) ($statsRow['overage_count'] ?? 0);
            $adjustmentStats['not_counted'] = (int) ($statsRow['not_counted_count'] ?? 0);
        }
    }
}

$recentResult = $db->query("
    SELECT sa.*, scs.system_reference AS session_reference, u.full_name AS created_by_name, au.full_name AS approved_by_name
    FROM stock_adjustments sa
    LEFT JOIN supply_count_sessions scs ON scs.id = sa.supply_count_session_id
    LEFT JOIN users u ON u.id = sa.created_by
    LEFT JOIN users au ON au.id = sa.approved_by
    ORDER BY sa.id DESC
    LIMIT 10
");
if ($recentResult instanceof mysqli_result) {
    $recentAdjustments = $recentResult->fetch_all(MYSQLI_ASSOC);
}

foreach ($recentAdjustments as $adjustment) {
    $status = (string) ($adjustment['status'] ?? '');
    if (isset($adjustmentStats[$status])) {
        $adjustmentStats[$status]++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
    <div class="workspace-header mb-4">
        <div>
            <h1 class="page-title mb-1">Stock Adjustments</h1>
            <p class="text-muted mb-0 workspace-header-copy">Review supply count variances, prepare pending adjustments, and control approval before posting any stock change.</p>
        </div>
        <div class="workspace-header-meta">
            <div class="small text-muted">Next reference</div>
            <div class="fw-semibold"><?php echo h($referencePreview ?: 'ADJ-' . date('Y') . '-0001'); ?></div>
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

    <div class="workspace-summary-grid mb-4">
        <div>
            <div class="card"><div class="card-body"><div class="small text-muted">Variance Lines</div><div class="fs-4 fw-bold"><?php echo number_format($adjustmentStats['lines']); ?></div></div></div>
        </div>
        <div>
            <div class="card"><div class="card-body"><div class="small text-muted">Shortages</div><div class="fs-4 fw-bold text-warning"><?php echo number_format($adjustmentStats['shortage']); ?></div></div></div>
        </div>
        <div>
            <div class="card"><div class="card-body"><div class="small text-muted">Overages</div><div class="fs-4 fw-bold text-danger"><?php echo number_format($adjustmentStats['overage']); ?></div></div></div>
        </div>
        <div>
            <div class="card"><div class="card-body"><div class="small text-muted">Pending Adjustments</div><div class="fs-4 fw-bold text-primary"><?php echo number_format($adjustmentStats['pending']); ?></div></div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-body">
                    <div class="workspace-header mb-4">
                        <div>
                            <div class="small text-muted">Adjustment Workspace</div>
                            <h5 class="mb-1">Build Adjustment from Count Session</h5>
                            <div class="text-muted small">Load one supply count session, review discrepancy lines, and create one pending adjustment batch.</div>
                        </div>
                        <?php if ($selectedSession): ?>
                            <div class="workspace-header-meta">
                                <div class="small text-muted">Selected Session</div>
                                <div class="fw-semibold"><?php echo h($selectedSession['system_reference'] ?? ''); ?></div>
                                <div class="small text-muted"><?php echo h(!empty($selectedSession['count_date']) ? format_date($selectedSession['count_date']) : ''); ?><?php echo !empty($selectedSession['status']) ? ' | ' . h(ucfirst((string) $selectedSession['status'])) : ''; ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <form method="get" class="workspace-filter-grid mb-4">
                        <div class="workspace-filter-wide">
                            <label class="form-label">Supply Count Session</label>
                            <select name="session_id" class="form-select">
                                <?php foreach ($sessions as $session): ?>
                                    <option value="<?php echo (int) $session['id']; ?>" <?php echo $selectedSessionId === (int) $session['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($session['system_reference'] . ' | ' . format_date($session['count_date']) . ' | ' . ucfirst((string) $session['status'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button class="btn btn-outline-secondary">Load Session</button>
                        </div>
                    </form>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="post_adjustment">
                        <input type="hidden" name="session_id" value="<?php echo (int) $selectedSessionId; ?>">
                        <div class="workspace-filter-grid mb-3">
                            <div>
                                <label class="form-label">Adjustment Date</label>
                                <input type="date" class="form-control" name="adjustment_date" value="<?php echo h(date('Y-m-d')); ?>">
                            </div>
                            <div class="workspace-filter-wide">
                                <label class="form-label">Remarks</label>
                                <input type="text" class="form-control" name="remarks" placeholder="Optional overall adjustment note">
                            </div>
                        </div>

                        <div class="table-responsive mobile-table-frame">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"></th>
                                        <th>Supply Item</th>
                                        <th>Reference</th>
                                        <th class="text-end">System Qty</th>
                                        <th class="text-end">Counted Qty</th>
                                        <th class="text-end">Variance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($rows): ?>
                                        <?php foreach ($rows as $row): ?>
                                            <?php $statusKey = (string) ($row['count_status'] ?? ''); ?>
                                            <?php $statusBadge = $statusKey === 'overage' ? 'text-bg-danger' : ($statusKey === 'shortage' ? 'text-bg-warning' : 'text-bg-dark'); ?>
                                            <tr>
                                                <td><input type="checkbox" name="items[<?php echo (int) $row['id']; ?>]" value="1"></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo h($row['item_description']); ?></div>
                                                    <div class="small text-muted"><?php echo h(trim(implode(' | ', array_filter([$row['classification_name'] ?? '', $row['unit_of_measure'] ?? '', !empty($row['barcode']) ? 'Barcode: ' . $row['barcode'] : ''])))); ?></div>
                                                </td>
                                                <td>
                                                    <div><?php echo h($row['stock_no'] ?: '-'); ?></div>
                                                    <div class="small text-muted"><?php echo h($row['stock_reference']); ?></div>
                                                </td>
                                                <td class="text-end"><?php echo format_quantity($row['system_quantity']); ?></td>
                                                <td class="text-end"><?php echo format_quantity($row['counted_quantity'] ?? 0); ?></td>
                                                <td class="text-end <?php echo (float) ($row['variance_quantity'] ?? 0) < 0 ? 'text-warning' : 'text-danger'; ?>"><?php echo format_quantity($row['variance_quantity'] ?? 0); ?></td>
                                                <td><span class="badge <?php echo $statusBadge; ?>"><?php echo h(ucfirst(str_replace('_', ' ', $statusKey))); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="7" class="text-center text-muted py-4">No discrepancy lines available for adjustment in this session.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary" <?php echo !$rows ? 'disabled' : ''; ?>>Create Pending Adjustment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <div class="small text-muted">Approval Queue</div>
                            <h5 class="card-title mb-0">Recent Adjustments</h5>
                        </div>
                        <div class="text-end small text-muted">
                            <div>Approved: <?php echo number_format($adjustmentStats['approved']); ?></div>
                            <div>Cancelled: <?php echo number_format($adjustmentStats['cancelled']); ?></div>
                        </div>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php if ($recentAdjustments): ?>
                            <?php foreach ($recentAdjustments as $adjustment): ?>
                                <div class="list-group-item px-0">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-semibold"><?php echo h($adjustment['system_reference']); ?></div>
                                            <div class="small text-muted">
                                                <?php echo h(format_date($adjustment['adjustment_date'])); ?> |
                                                <?php echo h($adjustment['session_reference'] ?: 'Manual'); ?>
                                            </div>
                                            <div class="small text-muted">
                                                Created by <?php echo h($adjustment['created_by_name'] ?: 'Unknown User'); ?>
                                                <?php if (!empty($adjustment['approved_by_name'])): ?>
                                                    | Approved by <?php echo h($adjustment['approved_by_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php echo operational_status_badge('stock_adjustment', (string) ($adjustment['status'] ?? 'pending')); ?>
                                    </div>
                                    <?php if (($adjustment['status'] ?? '') === 'pending' && in_array($_SESSION['user_role'] ?? '', ['Administrator', 'admin'], true)): ?>
                                        <div class="d-flex flex-wrap gap-2 mt-2">
                                            <form method="post">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="approve_adjustment">
                                                <input type="hidden" name="adjustment_id" value="<?php echo (int) $adjustment['id']; ?>">
                                                <button class="btn btn-sm btn-success">Approve</button>
                                            </form>
                                            <form method="post" class="d-flex flex-wrap gap-2">
                                                <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                <input type="hidden" name="action" value="cancel_adjustment">
                                                <input type="hidden" name="adjustment_id" value="<?php echo (int) $adjustment['id']; ?>">
                                                <input type="text" name="cancel_reason" class="form-control form-control-sm" placeholder="Cancellation reason" required style="max-width: 220px;">
                                                <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-muted">No stock adjustments posted yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
