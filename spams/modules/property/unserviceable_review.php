<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Property Officer');

$db = db();
$page_title = 'Unserviceable Review';
$flash = get_flash();
$errors = [];
$rows = [];
$summary = [
    'repair_candidates' => 0,
    'disposal_candidates' => 0,
    'endorsed_for_disposal' => 0,
    'endorsed_for_repair' => 0,
];
$sessionId = (int) ($_GET['session_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
if (!in_array($statusFilter, ['all', 'for_repair', 'for_disposal'], true)) {
    $statusFilter = 'all';
}

$resolutionActions = [
    'repair_endorsed' => 'Endorse for Repair',
    'disposal_endorsed' => 'Endorse for Disposal',
    'noted' => 'Monitor / Note',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    if (empty($errors) && $action === 'endorse_item') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $resolutionAction = trim((string) ($_POST['resolution_action'] ?? ''));
        $resolutionNotes = trim((string) ($_POST['resolution_notes'] ?? ''));

        if (!isset($resolutionActions[$resolutionAction])) {
            $errors[] = 'Select a valid endorsement action.';
        } else {
            $lookupStmt = $db->prepare("
                SELECT id, session_id, resolution_action, resolution_status
                FROM inventory_count_items
                WHERE id = ?
                  AND status IN ('for_repair', 'for_disposal')
                LIMIT 1
            ");
            $row = null;
            if ($lookupStmt) {
                $lookupStmt->bind_param('i', $itemId);
                $lookupStmt->execute();
                $row = $lookupStmt->get_result()->fetch_assoc();
                $lookupStmt->close();
            }

            if (!$row) {
                $errors[] = 'Review item not found.';
            } else {
                $resolvedStatus = in_array($resolutionAction, ['repair_endorsed', 'disposal_endorsed'], true) ? 'resolved' : 'unresolved';
                $resolvedAt = $resolvedStatus === 'resolved' ? date('Y-m-d H:i:s') : null;
                $resolvedBy = $resolvedStatus === 'resolved' ? current_user_id() : null;

                $updateStmt = $db->prepare("
                    UPDATE inventory_count_items
                    SET resolution_action = ?, resolution_notes = ?, resolution_status = ?, resolved_at = ?, resolved_by = ?
                    WHERE id = ?
                ");
                if ($updateStmt) {
                    $updateStmt->bind_param('ssssii', $resolutionAction, $resolutionNotes, $resolvedStatus, $resolvedAt, $resolvedBy, $itemId);
                    $ok = $updateStmt->execute();
                    $updateStmt->close();
                    if ($ok) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'inventory_count_items',
                            'record_id' => $itemId,
                            'module_name' => 'unserviceable_review',
                            'record_type' => 'inventory_count_item',
                            'action_name' => 'endorse_unserviceable_action',
                            'old_values' => [
                                'resolution_action' => $row['resolution_action'] ?? '',
                                'resolution_status' => $row['resolution_status'] ?? 'unresolved',
                            ],
                            'new_values' => [
                                'resolution_action' => $resolutionAction,
                                'resolution_status' => $resolvedStatus,
                                'resolution_notes' => $resolutionNotes,
                            ],
                            'description' => 'Updated unserviceable review endorsement.',
                        ]);
                        set_flash('success', 'Unserviceable review updated.');
                        redirect('modules/property/unserviceable_review.php?session_id=' . (int) $row['session_id'] . '&status=' . urlencode($statusFilter));
                    }
                }
                $errors[] = 'Unable to update the review item.';
            }
        }
    }
}

$sql = "
    SELECT
        ici.*,
        ics.system_reference AS session_reference,
        ics.count_date,
        o.office_name
    FROM inventory_count_items ici
    INNER JOIN inventory_count_sessions ics ON ics.id = ici.session_id
    INNER JOIN offices o ON o.id = ics.office_id
    WHERE ici.status IN ('for_repair', 'for_disposal')
";
$types = '';
$params = [];
if ($sessionId > 0) {
    $sql .= " AND ici.session_id = ?";
    $types .= 'i';
    $params[] = $sessionId;
}
if ($statusFilter !== 'all') {
    $sql .= " AND ici.status = ?";
    $types .= 's';
    $params[] = $statusFilter;
}
$sql .= " ORDER BY ici.session_id DESC, ici.status ASC, ici.property_number ASC";

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

foreach ($rows as $row) {
    if (($row['status'] ?? '') === 'for_repair') {
        $summary['repair_candidates']++;
    }
    if (($row['status'] ?? '') === 'for_disposal') {
        $summary['disposal_candidates']++;
    }
    if (($row['resolution_action'] ?? '') === 'repair_endorsed') {
        $summary['endorsed_for_repair']++;
    }
    if (($row['resolution_action'] ?? '') === 'disposal_endorsed') {
        $summary['endorsed_for_disposal']++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="page-section">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h1 class="page-title mb-1">Unserviceable Review</h1>
            <p class="text-muted mb-0">Review count-flagged assets that need repair or disposal and endorse the next action.</p>
        </div>
        <div class="text-end">
            <div class="small text-muted">Issue view</div>
            <div class="fw-semibold"><?php echo h($statusFilter === 'all' ? 'All Review Items' : ucfirst(str_replace('_', ' ', $statusFilter))); ?></div>
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
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Repair Candidates</div><div class="fs-4 fw-bold"><?php echo number_format($summary['repair_candidates']); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Disposal Candidates</div><div class="fs-4 fw-bold"><?php echo number_format($summary['disposal_candidates']); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Endorsed for Repair</div><div class="fs-4 fw-bold"><?php echo number_format($summary['endorsed_for_repair']); ?></div></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Endorsed for Disposal</div><div class="fs-4 fw-bold"><?php echo number_format($summary['endorsed_for_disposal']); ?></div></div></div></div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
                <div>
                    <div class="small text-muted">Review Workspace</div>
                    <h5 class="card-title mb-1">Repair and Disposal Endorsements</h5>
                    <div class="text-muted small">Filter flagged assets, record the endorsement, and jump directly to the right follow-up transaction.</div>
                </div>
            </div>
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Count Session ID</label>
                    <input type="number" name="session_id" class="form-control" value="<?php echo $sessionId > 0 ? (int) $sessionId : ''; ?>" placeholder="Optional specific session">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Issue Type</label>
                    <select name="status" class="form-select">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Review Items</option>
                        <option value="for_repair" <?php echo $statusFilter === 'for_repair' ? 'selected' : ''; ?>>For Repair</option>
                        <option value="for_disposal" <?php echo $statusFilter === 'for_disposal' ? 'selected' : ''; ?>>For Disposal</option>
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
                    <h5 class="card-title mb-0">Review Queue</h5>
                    <div class="text-muted small">Assets currently tagged for repair or disposal follow-up.</div>
                </div>
                <span class="badge text-bg-light"><?php echo number_format($summary['repair_candidates'] + $summary['disposal_candidates']); ?> item(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="min-width: 140px;">Session</th>
                            <th style="min-width: 180px;">Property No.</th>
                            <th style="min-width: 280px;">Asset</th>
                            <th style="min-width: 160px;">Review Issue</th>
                            <th style="min-width: 320px;">Endorsement</th>
                            <th style="min-width: 220px;">Follow-up</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows): ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $isLegacy = ($row['source_type'] ?? '') === 'legacy';
                                $detailId = (int) ($row['distribution_item_detail_id'] ?? 0);
                                $assetId = $isLegacy ? (int) ($row['legacy_asset_id'] ?? 0) : $detailId;
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($row['session_reference']); ?></div>
                                        <div class="small text-muted"><?php echo h($row['office_name']); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo h($row['property_number']); ?></div>
                                        <div class="small text-muted"><?php echo h($isLegacy ? 'Beginning Balance' : 'System Transaction'); ?></div>
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
                                        <span class="badge <?php echo ($row['status'] ?? '') === 'for_disposal' ? 'text-bg-danger' : 'text-bg-warning'; ?>">
                                            <?php echo h(($row['status'] ?? '') === 'for_disposal' ? 'For Disposal' : 'For Repair'); ?>
                                        </span>
                                        <?php if (!empty($row['remarks'])): ?>
                                            <div class="small text-muted mt-1"><?php echo h($row['remarks']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="row g-2">
                                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                            <input type="hidden" name="action" value="endorse_item">
                                            <input type="hidden" name="item_id" value="<?php echo (int) $row['id']; ?>">
                                            <div class="col-12">
                                                <select name="resolution_action" class="form-select form-select-sm">
                                                    <option value="">Select endorsement</option>
                                                    <?php foreach ($resolutionActions as $value => $label): ?>
                                                        <option value="<?php echo h($value); ?>" <?php echo ($row['resolution_action'] ?? '') === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <input type="text" name="resolution_notes" class="form-control form-control-sm" value="<?php echo h((string) ($row['resolution_notes'] ?? '')); ?>" placeholder="Inspection note or endorsement basis">
                                            </div>
                                            <div class="col-12 d-grid">
                                                <button class="btn btn-sm btn-primary">Save Endorsement</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="d-grid gap-2">
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/property/view.php?source=' . urlencode((string) $row['source_type']) . '&id=' . $assetId)); ?>">Open Asset</a>
                                            <?php if (!$isLegacy): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/maintenance/index.php?detail_id=' . $detailId)); ?>">Maintenance</a>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo h(base_url('modules/disposals/index.php?detail_id=' . $detailId)); ?>">Dispose</a>
                                            <?php else: ?>
                                                <span class="small text-muted">Legacy follow-up continues from the asset detail page.</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No unserviceable review items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
