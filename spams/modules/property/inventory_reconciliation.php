<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Property Officer');

$db = db();
$page_title = 'Count Reconciliation';
$flash = get_flash();
$errors = [];
$sessions = [];
$rows = [];
$summary = ['exceptions' => 0, 'resolved' => 0, 'unresolved' => 0];
$resolutionActions = [
    'recounted_found' => 'Recounted / Found',
    'transfer_initiated' => 'Transfer Initiated',
    'accountability_corrected' => 'Accountability Corrected',
    'unassigned_for_reconciliation' => 'Unassigned for Reconciliation',
    'repair_endorsed' => 'Repair Endorsed',
    'disposal_endorsed' => 'Disposal Endorsed',
    'tag_replacement' => 'Tag Replacement',
    'noted' => 'Noted / Monitored',
];
$statusLabels = [
    'missing' => 'Missing',
    'for_repair' => 'For Repair',
    'for_disposal' => 'For Disposal',
    'wrong_office' => 'Wrong Office',
    'wrong_accountable' => 'Wrong Accountable',
];

$selectedSessionId = (int) ($_GET['session_id'] ?? 0);
$resolutionFilter = trim((string) ($_GET['resolution'] ?? 'unresolved'));
if (!in_array($resolutionFilter, ['all', 'unresolved', 'resolved'], true)) {
    $resolutionFilter = 'unresolved';
}

if ($db) {
    ensure_legacy_assets_accountability_tracking_columns($db);
}

function build_reconciliation_url(array $overrides = []): string
{
    $params = [
        'session_id' => $_GET['session_id'] ?? '',
        'resolution' => $_GET['resolution'] ?? 'unresolved',
    ];
    foreach ($overrides as $key => $value) {
        $params[$key] = $value;
    }
    return '?' . http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    if ($db) {
        ensure_legacy_assets_accountability_tracking_columns($db);
    }

    if (empty($errors) && $action === 'mark_for_reconciliation') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $resolutionNotes = trim((string) ($_POST['resolution_notes'] ?? ''));

        if ($itemId <= 0 || $sessionId <= 0) {
            $errors[] = 'Invalid reconciliation item.';
        }

        if (empty($errors)) {
            $lookupStmt = $db->prepare("
                SELECT
                    ici.id,
                    ici.status,
                    ici.source_type,
                    ici.legacy_asset_id,
                    ici.resolution_status,
                    ici.resolution_action,
                    ici.resolution_notes,
                    la.property_number,
                    la.office_id,
                    la.employee_id,
                    la.responsibility_code_id,
                    la.accountability_status
                FROM inventory_count_items ici
                INNER JOIN legacy_assets la ON la.id = ici.legacy_asset_id
                WHERE ici.id = ?
                  AND ici.session_id = ?
                  AND ici.source_type = 'legacy'
                LIMIT 1
            ");
            $row = null;
            if ($lookupStmt) {
                $lookupStmt->bind_param('ii', $itemId, $sessionId);
                $lookupStmt->execute();
                $row = $lookupStmt->get_result()->fetch_assoc();
                $lookupStmt->close();
            }

            if (!$row) {
                $errors[] = 'Only legacy asset discrepancy items can be marked for reconciliation.';
            } else {
                $userId = (int) current_user_id();
                $legacyAssetId = (int) ($row['legacy_asset_id'] ?? 0);
                $notes = $resolutionNotes !== ''
                    ? $resolutionNotes
                    : 'Current accountability unassigned; previous office/person retained for reconciliation.';

                $db->begin_transaction();
                $saved = false;

                $assetStmt = $db->prepare("
                    UPDATE legacy_assets
                    SET last_office_id = office_id,
                        last_employee_id = employee_id,
                        last_responsibility_code_id = responsibility_code_id,
                        office_id = NULL,
                        employee_id = NULL,
                        responsibility_code_id = NULL,
                        accountability_status = 'for_reconciliation',
                        accountability_cleared_at = NOW(),
                        accountability_cleared_by = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                if ($assetStmt) {
                    $assetStmt->bind_param('ii', $userId, $legacyAssetId);
                    $saved = (bool) $assetStmt->execute();
                    $assetStmt->close();
                }

                if ($saved) {
                    $itemStmt = $db->prepare("
                        UPDATE inventory_count_items
                        SET resolution_status = 'unresolved',
                            resolution_action = 'unassigned_for_reconciliation',
                            resolution_notes = ?,
                            resolved_at = NULL,
                            resolved_by = NULL
                        WHERE id = ?
                          AND session_id = ?
                    ");
                    if ($itemStmt) {
                        $itemStmt->bind_param('sii', $notes, $itemId, $sessionId);
                        $saved = (bool) $itemStmt->execute();
                        $itemStmt->close();
                    } else {
                        $saved = false;
                    }
                }

                if ($saved) {
                    write_audit_log($db, [
                        'action' => 'update',
                        'table_name' => 'legacy_assets',
                        'record_id' => $legacyAssetId,
                        'module_name' => 'inventory_reconciliation',
                        'record_type' => 'legacy_asset',
                        'action_name' => 'mark_for_reconciliation_unassign_accountability',
                        'old_values' => [
                            'office_id' => $row['office_id'] ?? null,
                            'employee_id' => $row['employee_id'] ?? null,
                            'responsibility_code_id' => $row['responsibility_code_id'] ?? null,
                            'accountability_status' => $row['accountability_status'] ?? 'active',
                        ],
                        'new_values' => [
                            'office_id' => null,
                            'employee_id' => null,
                            'responsibility_code_id' => null,
                            'last_office_id' => $row['office_id'] ?? null,
                            'last_employee_id' => $row['employee_id'] ?? null,
                            'last_responsibility_code_id' => $row['responsibility_code_id'] ?? null,
                            'accountability_status' => 'for_reconciliation',
                        ],
                        'description' => 'Marked legacy asset as For Reconciliation and unassigned current accountability.',
                    ]);
                    $db->commit();
                    set_flash('success', 'Asset marked For Reconciliation. Current accountability was unassigned and last accountability was retained.');
                    redirect('modules/property/inventory_reconciliation.php?session_id=' . $sessionId . '&resolution=' . urlencode($resolutionFilter));
                }

                $db->rollback();
                $errors[] = 'Unable to mark the asset for reconciliation.';
            }
        }
    }

    if (empty($errors) && $action === 'resolve_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $resolutionStatus = trim((string) ($_POST['resolution_status'] ?? 'unresolved'));
        $resolutionAction = trim((string) ($_POST['resolution_action'] ?? ''));
        $resolutionNotes = trim((string) ($_POST['resolution_notes'] ?? ''));

        if (!in_array($resolutionStatus, ['unresolved', 'resolved'], true)) {
            $resolutionStatus = 'unresolved';
        }
        if ($resolutionAction !== '' && !isset($resolutionActions[$resolutionAction])) {
            $errors[] = 'Invalid resolution action.';
        }

        if (empty($errors)) {
            $lookupStmt = $db->prepare("
                SELECT id, status, resolution_status, resolution_action
                FROM inventory_count_items
                WHERE id = ? AND session_id = ?
                LIMIT 1
            ");
            $row = null;
            if ($lookupStmt) {
                $lookupStmt->bind_param('ii', $itemId, $sessionId);
                $lookupStmt->execute();
                $row = $lookupStmt->get_result()->fetch_assoc();
                $lookupStmt->close();
            }

            if (!$row) {
                $errors[] = 'Discrepancy item not found.';
            } else {
                $resolvedAt = $resolutionStatus === 'resolved' ? date('Y-m-d H:i:s') : null;
                $resolvedBy = $resolutionStatus === 'resolved' ? current_user_id() : null;

                $updateStmt = $db->prepare("
                    UPDATE inventory_count_items
                    SET resolution_status = ?, resolution_action = ?, resolution_notes = ?, resolved_at = ?, resolved_by = ?
                    WHERE id = ? AND session_id = ?
                ");
                if ($updateStmt) {
                    $updateStmt->bind_param('ssssiii', $resolutionStatus, $resolutionAction, $resolutionNotes, $resolvedAt, $resolvedBy, $itemId, $sessionId);
                    $ok = $updateStmt->execute();
                    $updateStmt->close();
                    if ($ok) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'inventory_count_items',
                            'record_id' => $itemId,
                            'module_name' => 'inventory_reconciliation',
                            'record_type' => 'inventory_count_item',
                            'action_name' => 'resolve_inventory_discrepancy',
                            'old_values' => [
                                'resolution_status' => $row['resolution_status'] ?? 'unresolved',
                                'resolution_action' => $row['resolution_action'] ?? '',
                            ],
                            'new_values' => [
                                'resolution_status' => $resolutionStatus,
                                'resolution_action' => $resolutionAction,
                                'resolution_notes' => $resolutionNotes,
                            ],
                            'description' => 'Updated property count discrepancy resolution.',
                        ]);
                        set_flash('success', 'Discrepancy resolution updated.');
                        redirect('modules/property/inventory_reconciliation.php?session_id=' . $sessionId . '&resolution=' . urlencode($resolutionFilter));
                    }
                }
                $errors[] = 'Unable to update the discrepancy resolution.';
            }
        }
    }
}

$sessionResult = $db->query("
    SELECT ics.id, ics.system_reference, ics.count_date, o.office_name
    FROM inventory_count_sessions ics
    INNER JOIN offices o ON o.id = ics.office_id
    ORDER BY ics.id DESC
");
if ($sessionResult instanceof mysqli_result) {
    $sessions = $sessionResult->fetch_all(MYSQLI_ASSOC);
}

if ($selectedSessionId <= 0 && !empty($sessions)) {
    $selectedSessionId = (int) $sessions[0]['id'];
}

$params = [];
$types = '';
$where = "WHERE ici.status IN ('missing', 'for_repair', 'for_disposal', 'wrong_office', 'wrong_accountable')";
if ($selectedSessionId > 0) {
    $where .= " AND ici.session_id = ?";
    $types .= 'i';
    $params[] = $selectedSessionId;
}
if ($resolutionFilter !== 'all') {
    $where .= " AND ici.resolution_status = ?";
    $types .= 's';
    $params[] = $resolutionFilter;
}

$sql = "
    SELECT
        ici.*,
        ics.system_reference,
        ics.count_date,
        o.office_name,
        COALESCE(la.accountability_status, 'active') AS legacy_accountability_status
    FROM inventory_count_items ici
    INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
    INNER JOIN offices o ON o.id = ics.office_id
    LEFT JOIN legacy_assets la ON la.id = ici.legacy_asset_id AND ici.source_type = 'legacy'
    {$where}
    ORDER BY ics.id DESC, ici.status ASC, ici.property_number ASC
";

$stmt = $db->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$summarySql = "
    SELECT
        COUNT(*) AS exceptions,
        SUM(CASE WHEN resolution_status = 'resolved' THEN 1 ELSE 0 END) AS resolved_count,
        SUM(CASE WHEN resolution_status = 'unresolved' THEN 1 ELSE 0 END) AS unresolved_count
    FROM inventory_count_items ici
    {$where}
";
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
            'exceptions' => (int) ($summaryRow['exceptions'] ?? 0),
            'resolved' => (int) ($summaryRow['resolved_count'] ?? 0),
            'unresolved' => (int) ($summaryRow['unresolved_count'] ?? 0),
        ];
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Count Reconciliation</h1>
            <p class="text-muted mb-0">Resolve annual inventory and surprise-check discrepancies with follow-up actions and documented decisions.</p>
        </div>
        <div class="text-end">
            <div class="small text-muted">Active view</div>
            <div class="fw-semibold"><?php echo h(ucfirst($resolutionFilter)); ?></div>
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

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Exception Items</div><div class="fs-4 fw-bold"><?php echo number_format($summary['exceptions']); ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Resolved</div><div class="fs-4 fw-bold"><?php echo number_format($summary['resolved']); ?></div></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><div class="small text-muted">Unresolved</div><div class="fs-4 fw-bold"><?php echo number_format($summary['unresolved']); ?></div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="small text-muted">Reconciliation Workspace</div>
                    <h5 class="card-title mb-1">Review Count Exceptions</h5>
                    <div class="text-muted small">Filter one count session, then resolve missing, repair, disposal, and accountability issues from one queue.</div>
                </div>
            </div>
            <form method="get" class="row g-3 align-items-end workspace-filter-panel">
                <div class="col-md-6">
                    <label class="form-label">Count Session</label>
                    <select name="session_id" class="form-select">
                        <?php foreach ($sessions as $session): ?>
                            <option value="<?php echo (int) $session['id']; ?>" <?php echo $selectedSessionId === (int) $session['id'] ? 'selected' : ''; ?>>
                                <?php echo h($session['system_reference'] . ' | ' . $session['office_name'] . ' | ' . format_date($session['count_date'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Resolution View</label>
                    <select name="resolution" class="form-select">
                        <option value="unresolved" <?php echo $resolutionFilter === 'unresolved' ? 'selected' : ''; ?>>Unresolved Only</option>
                        <option value="resolved" <?php echo $resolutionFilter === 'resolved' ? 'selected' : ''; ?>>Resolved Only</option>
                        <option value="all" <?php echo $resolutionFilter === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="card-title mb-0">Discrepancy Queue</h5>
                    <div class="text-muted small">Follow-up items for the selected count session.</div>
                </div>
                <span class="badge text-bg-light"><?php echo number_format($summary['exceptions']); ?> item(s)</span>
            </div>
            <div class="table-responsive mobile-table-frame">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Session</th>
                            <th style="min-width: 180px;">Property No.</th>
                            <th style="min-width: 280px;">Asset</th>
                            <th style="min-width: 180px;">Issue</th>
                            <th style="min-width: 320px;">Resolution</th>
                            <th style="min-width: 220px;">Follow-up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $isLegacy = ($row['source_type'] ?? '') === 'legacy';
                                $detailId = (int) ($row['distribution_item_detail_id'] ?? 0);
                                $assetKey = ($row['source_type'] ?? 'system') . ':' . (int) (($isLegacy ? $row['legacy_asset_id'] : $row['distribution_item_detail_id']) ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($row['system_reference']); ?></div>
                                        <div class="small text-muted"><?php echo h($row['office_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($row['property_number']); ?></div>
                                        <div class="small text-muted"><?php echo h($isLegacy ? 'Beginning Balance' : 'System Transaction'); ?></div>
                                        <?php if ($isLegacy && ($row['legacy_accountability_status'] ?? 'active') === 'for_reconciliation'): ?>
                                            <span class="badge text-bg-info mt-1">For Reconciliation</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($row['item_description']); ?></div>
                                        <div class="small text-muted">
                                            <?php echo h(trim(implode(' | ', array_filter([
                                                ($row['item_type'] ?? '') === 'semi_expendable' ? 'Semi-Expendable' : 'Equipment',
                                                $row['classification_name'] ?? '',
                                                trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')),
                                                !empty($row['serial_no']) ? 'SN: ' . $row['serial_no'] : '',
                                            ])))); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge text-bg-warning"><?php echo h($statusLabels[$row['status']] ?? ucfirst((string) $row['status'])); ?></span>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <div class="small text-muted mt-1"><?php echo h($row['remarks']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="row g-2">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="resolve_item">
                                            <input type="hidden" name="session_id" value="<?php echo (int) $row['session_id']; ?>">
                                            <input type="hidden" name="item_id" value="<?php echo (int) $row['id']; ?>">
                                            <div class="col-12">
                                                <select name="resolution_status" class="form-select form-select-sm">
                                                    <option value="unresolved" <?php echo ($row['resolution_status'] ?? 'unresolved') === 'unresolved' ? 'selected' : ''; ?>>Unresolved</option>
                                                    <option value="resolved" <?php echo ($row['resolution_status'] ?? '') === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <select name="resolution_action" class="form-select form-select-sm">
                                                    <option value="">Select action</option>
                                                    <?php foreach ($resolutionActions as $value => $label): ?>
                                                        <option value="<?php echo h($value); ?>" <?php echo ($row['resolution_action'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <input type="text" name="resolution_notes" class="form-control form-control-sm" value="<?php echo h((string) ($row['resolution_notes'] ?? '')); ?>" placeholder="Resolution notes">
                                            </div>
                                            <div class="col-12 d-grid">
                                                <button class="btn btn-sm btn-primary">Save Resolution</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="d-grid gap-2">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/property/view.php?source=' . urlencode((string) $row['source_type']) . '&id=' . ($isLegacy ? (int) $row['legacy_asset_id'] : $detailId))); ?>">Open Asset</a>
                                            <?php if ($isLegacy && ($row['legacy_accountability_status'] ?? 'active') !== 'for_reconciliation'): ?>
                                                <form method="post" onsubmit="return confirm('Mark this legacy asset as For Reconciliation and unassign its current accountability?');">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="mark_for_reconciliation">
                                                    <input type="hidden" name="session_id" value="<?php echo (int) $row['session_id']; ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo (int) $row['id']; ?>">
                                                    <input type="hidden" name="resolution_notes" value="Current accountability unassigned from inventory reconciliation; last accountability retained.">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning w-100">Mark as For Reconciliation / Unassign Accountability</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array((string) ($row['status'] ?? ''), ['for_repair', 'for_disposal'], true)): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/property/unserviceable_review.php?session_id=' . (int) $row['session_id'] . '&status=' . urlencode((string) $row['status']))); ?>">Open Review</a>
                                            <?php endif; ?>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/transfers/index.php?asset_key=' . urlencode($assetKey))); ?>">Transfer</a>
                                            <?php if (!$isLegacy): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/maintenance/index.php?detail_id=' . $detailId)); ?>">Maintenance</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/disposals/index.php?detail_id=' . $detailId)); ?>">Dispose</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/returns/index.php?detail_id=' . $detailId)); ?>">Return</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No discrepancy items in this view.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
