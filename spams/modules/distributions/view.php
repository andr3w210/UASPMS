<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Supply Officer', 'Property Officer', 'Viewer');

function distribution_doc_title(string $type): string
{
    return strtoupper($type) === 'PAR' ? 'PROPERTY ACKNOWLEDGMENT RECEIPT' : 'INVENTORY CUSTODIAN SLIP';
}

function distribution_correction_options(): array
{
    return [
        'for_replacement' => 'For Replacement',
        'for_repair' => 'For Repair',
        'for_safekeeping' => 'For Safekeeping',
        'for_disposal' => 'For Disposal',
    ];
}

function distribution_resolve_spmu_office_id(mysqli $db): int
{
    $stmt = $db->prepare("
        SELECT id
        FROM offices
        WHERE is_active = 1
          AND (
              office_code IN ('SPM', 'SPMU')
              OR office_name LIKE '%Supply and Property Management Unit%'
              OR office_name LIKE '%SPMU%'
          )
        ORDER BY
            CASE
                WHEN office_code = 'SPMU' THEN 0
                WHEN office_code = 'SPM' THEN 1
                ELSE 2
            END,
            id ASC
        LIMIT 1
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return (int) ($row['id'] ?? 0);
}

function distribution_ensure_correction_schema(mysqli $db): void
{
    // Schema mutations are migration-only; keep request path read-only.
}

function distribution_ensure_schema(mysqli $db): void
{
    if (!function_exists('schema_has_column')) {
        return;
    }
}

$db = db();
$distributionId = (int) ($_GET['id'] ?? 0);
$distribution = null;
$items = [];
$errors = [];
$correctionOptions = distribution_correction_options();
$canCancelDistribution = user_has_any_role('Administrator');

if ($db) {
    distribution_ensure_correction_schema($db);
    distribution_ensure_schema($db);
    if (!schema_has_column($db, 'distribution_item_details', 'correction_status')
        || !schema_has_column($db, 'distribution_item_details', 'correction_reason')
        || !schema_has_column($db, 'distribution_item_details', 'correction_remarks')
        || !schema_has_column($db, 'distribution_item_details', 'corrected_at')
        || !schema_has_column($db, 'distribution_item_details', 'corrected_by')) {
        $errors[] = 'Database schema is outdated: distribution correction columns are missing. Apply latest migrations before continuing.';
    }
    if (!schema_has_column($db, 'distributions', 'updated_by')) {
        $errors[] = 'Database schema is outdated: distributions.updated_by is missing. Apply latest migrations before continuing.';
    }
}

if ($db && $distributionId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_distribution') {
    if (!$canCancelDistribution) {
        $errors[] = 'Only administrators can cancel distributions.';
    }
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
    if ($cancelReason === '') {
        $errors[] = 'Cancellation reason is required.';
    }

    if (!$errors) {
        $db->begin_transaction();
        try {
            $headerStmt = $db->prepare("SELECT id, status, system_reference, document_no, total_amount, remarks FROM distributions WHERE id = ? FOR UPDATE");
            if (!$headerStmt) {
                throw new RuntimeException('Unable to prepare the distribution cancellation lookup.');
            }
            $headerStmt->bind_param('i', $distributionId);
            $headerStmt->execute();
            $header = $headerStmt->get_result()->fetch_assoc() ?: null;
            $headerStmt->close();

            if (!$header) {
                throw new RuntimeException('Distribution record not found.');
            }
            if (($header['status'] ?? '') !== 'posted') {
                throw new RuntimeException('Only posted distributions can be cancelled.');
            }

            $dependencyStmt = $db->prepare("
                SELECT
                    SUM(CASE WHEN rt.id IS NOT NULL THEN 1 ELSE 0 END) AS return_count,
                    SUM(CASE WHEN dp.id IS NOT NULL THEN 1 ELSE 0 END) AS disposal_count,
                    SUM(CASE WHEN at.id IS NOT NULL THEN 1 ELSE 0 END) AS transfer_count
                FROM distribution_items di
                INNER JOIN distribution_item_details did ON did.distribution_item_id = di.id
                LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted'
                LEFT JOIN disposals dp ON dp.distribution_item_detail_id = did.id AND dp.status = 'posted'
                LEFT JOIN asset_transfers at ON at.distribution_item_detail_id = did.id AND at.status = 'posted'
                WHERE di.distribution_id = ?
            ");
            if (!$dependencyStmt) {
                throw new RuntimeException('Unable to check cancellation dependencies.');
            }
            $dependencyStmt->bind_param('i', $distributionId);
            $dependencyStmt->execute();
            $dependency = $dependencyStmt->get_result()->fetch_assoc() ?: [];
            $dependencyStmt->close();

            if ((int) ($dependency['return_count'] ?? 0) > 0 || (int) ($dependency['disposal_count'] ?? 0) > 0 || (int) ($dependency['transfer_count'] ?? 0) > 0) {
                throw new RuntimeException('This distribution already has return, disposal, or transfer records. Cancel those dependent transactions first.');
            }

            $note = 'Distribution cancelled: ' . $cancelReason;
            $userId = current_user_id();

            $releaseReceivingStmt = $db->prepare("
                UPDATE receiving_item_details rid
                INNER JOIN distribution_item_details did ON did.receiving_item_detail_id = rid.id
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                SET rid.is_distributed = 0
                WHERE di.distribution_id = ?
                  AND did.receiving_item_detail_id IS NOT NULL
            ");
            if (!$releaseReceivingStmt) {
                throw new RuntimeException('Unable to release receiving units.');
            }
            $releaseReceivingStmt->bind_param('i', $distributionId);
            $releaseReceivingStmt->execute();
            $releaseReceivingStmt->close();

            $detailStmt = $db->prepare("
                UPDATE distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                SET
                    did.is_distributed = 0,
                    did.current_office_id = NULL,
                    did.current_employee_id = NULL,
                    did.current_responsibility_code_id = NULL,
                    did.correction_status = NULL,
                    did.correction_reason = NULL,
                    did.correction_remarks = NULL,
                    did.corrected_at = NULL,
                    did.corrected_by = NULL,
                    did.remarks = TRIM(CONCAT(COALESCE(NULLIF(did.remarks, ''), ''), CASE WHEN COALESCE(NULLIF(did.remarks, ''), '') = '' THEN '' ELSE '\n' END, ?))
                WHERE di.distribution_id = ?
            ");
            if (!$detailStmt) {
                throw new RuntimeException('Unable to update distribution unit records.');
            }
            $detailStmt->bind_param('si', $note, $distributionId);
            $detailStmt->execute();
            $detailStmt->close();

            $itemStmt = $db->prepare("
                UPDATE distribution_items
                SET
                    quantity_distributed = 0,
                    line_total = 0,
                    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, ?))
                WHERE distribution_id = ?
            ");
            if (!$itemStmt) {
                throw new RuntimeException('Unable to update distribution item records.');
            }
            $itemStmt->bind_param('si', $note, $distributionId);
            $itemStmt->execute();
            $itemStmt->close();

            $cancelStmt = $db->prepare("
                UPDATE distributions
                SET
                    status = 'cancelled',
                    total_amount = 0,
                    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, ?)),
                    updated_by = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            if (!$cancelStmt) {
                throw new RuntimeException('Unable to cancel the distribution.');
            }
            $cancelStmt->bind_param('sii', $note, $userId, $distributionId);
            $cancelStmt->execute();
            $cancelStmt->close();

            write_audit_log($db, [
                'action' => 'update',
                'table_name' => 'distributions',
                'record_id' => $distributionId,
                'module_name' => 'distributions',
                'record_type' => 'distribution',
                'action_name' => 'cancel_distribution',
                'old_values' => [
                    'status' => $header['status'] ?? 'posted',
                    'total_amount' => $header['total_amount'] ?? null,
                ],
                'new_values' => [
                    'status' => 'cancelled',
                    'total_amount' => 0,
                    'reason' => $cancelReason,
                ],
                'description' => 'Cancelled distribution and released units back to receiving. ' . $note,
            ]);

            $db->commit();
            set_flash('success', 'Distribution cancelled. Linked items were returned to Receiving availability.');
            redirect('modules/distributions/view.php?id=' . $distributionId);
        } catch (Throwable $e) {
            $db->rollback();
            $errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to cancel the distribution.';
        }
    }
}

if ($db && $distributionId > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'correct_distribution_unit') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    }

    $detailId = (int) ($_POST['detail_id'] ?? 0);
    $nextStatus = trim((string) ($_POST['next_status'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($detailId <= 0) {
        $errors[] = 'Select a distributed unit to correct.';
    }
    if (!isset($correctionOptions[$nextStatus])) {
        $errors[] = 'Select the next status for the corrected unit.';
    }
    if ($reason === '') {
        $errors[] = 'Correction reason is required.';
    }

    if (!$errors) {
        $spmuOfficeId = distribution_resolve_spmu_office_id($db);
        if ($spmuOfficeId <= 0) {
            $errors[] = 'SPMU office record could not be found. Please add or activate the Supply and Property Management Unit office first.';
        }
    }

    if (!$errors) {
        $db->begin_transaction();
        try {
            $lockStmt = $db->prepare("
                SELECT
                    did.id,
                    did.distribution_item_id,
                    did.receiving_item_detail_id,
                    did.property_number,
                    did.serial_no,
                    did.is_distributed,
                    did.is_disposed,
                    di.quantity_distributed,
                    di.unit_cost,
                    d.status AS distribution_status,
                    poi.item_description
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN distributions d ON d.id = di.distribution_id
                LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
                LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                WHERE did.id = ?
                  AND di.distribution_id = ?
                FOR UPDATE
            ");
            if (!$lockStmt) {
                throw new RuntimeException('Unable to prepare the correction lookup.');
            }
            $lockStmt->bind_param('ii', $detailId, $distributionId);
            $lockStmt->execute();
            $detail = $lockStmt->get_result()->fetch_assoc() ?: null;
            $lockStmt->close();

            if (!$detail) {
                throw new RuntimeException('The selected distribution unit could not be found.');
            }
            if (($detail['distribution_status'] ?? '') !== 'posted') {
                throw new RuntimeException('Only posted distributions can be corrected.');
            }
            if ((int) ($detail['is_distributed'] ?? 0) !== 1) {
                throw new RuntimeException('This unit is no longer marked as distributed.');
            }
            if ((int) ($detail['is_disposed'] ?? 0) === 1) {
                throw new RuntimeException('Disposed units cannot be corrected here.');
            }

            $userId = current_user_id();
            $note = 'Distribution correction: ' . $correctionOptions[$nextStatus] . ' - ' . $reason . ($remarks !== '' ? ' | ' . $remarks : '');

            $detailStmt = $db->prepare("
                UPDATE distribution_item_details
                SET
                    is_distributed = 0,
                    current_office_id = ?,
                    current_employee_id = NULL,
                    current_responsibility_code_id = NULL,
                    correction_status = ?,
                    correction_reason = ?,
                    correction_remarks = ?,
                    corrected_at = NOW(),
                    corrected_by = ?,
                    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, ?))
                WHERE id = ?
            ");
            if (!$detailStmt) {
                throw new RuntimeException('Unable to prepare the unit correction update.');
            }
            $detailStmt->bind_param('isssisi', $spmuOfficeId, $nextStatus, $reason, $remarks, $userId, $note, $detailId);
            $detailStmt->execute();
            $detailStmt->close();

            $distributionItemId = (int) ($detail['distribution_item_id'] ?? 0);
            $unitCost = (float) ($detail['unit_cost'] ?? 0);
            $itemStmt = $db->prepare("
                UPDATE distribution_items
                SET
                    line_total = GREATEST(quantity_distributed - 1, 0) * unit_cost,
                    quantity_distributed = GREATEST(quantity_distributed - 1, 0),
                    remarks = TRIM(CONCAT(COALESCE(NULLIF(remarks, ''), ''), CASE WHEN COALESCE(NULLIF(remarks, ''), '') = '' THEN '' ELSE '\n' END, ?))
                WHERE id = ?
            ");
            if (!$itemStmt) {
                throw new RuntimeException('Unable to prepare the distribution line correction update.');
            }
            $itemStmt->bind_param('si', $note, $distributionItemId);
            $itemStmt->execute();
            $itemStmt->close();

            $totalStmt = $db->prepare("UPDATE distributions SET total_amount = GREATEST(total_amount - ?, 0), updated_by = ?, updated_at = NOW() WHERE id = ?");
            if (!$totalStmt) {
                throw new RuntimeException('Unable to prepare the distribution total update.');
            }
            $totalStmt->bind_param('dii', $unitCost, $userId, $distributionId);
            $totalStmt->execute();
            $totalStmt->close();

            write_audit_log($db, [
                'action' => 'update',
                'table_name' => 'distribution_item_details',
                'record_id' => $detailId,
                'module_name' => 'distributions',
                'record_type' => 'distribution_correction',
                'action_name' => 'correct_distribution_unit',
                'old_values' => [
                    'is_distributed' => (int) ($detail['is_distributed'] ?? 0),
                    'property_number' => $detail['property_number'] ?? null,
                    'serial_no' => $detail['serial_no'] ?? null,
                ],
                'new_values' => [
                    'is_distributed' => 0,
                    'correction_status' => $nextStatus,
                    'correction_reason' => $reason,
                    'correction_remarks' => $remarks,
                    'spmu_office_id' => $spmuOfficeId,
                ],
                'description' => 'Corrected a distribution unit before physical issuance. ' . $note,
            ]);

            $db->commit();
            set_flash('success', 'Distribution unit corrected and blocked from redistribution.');
            redirect('modules/distributions/view.php?id=' . $distributionId);
        } catch (Throwable $e) {
            $db->rollback();
            $errors[] = $e->getMessage() !== '' ? $e->getMessage() : 'Unable to correct the distribution unit.';
        }
    }
}

if ($db && $distributionId > 0) {
    $headerStmt = $db->prepare("
        SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.purpose, d.remarks, d.status,
               o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name
        FROM distributions d
        INNER JOIN offices o ON o.id = d.office_id
        LEFT JOIN employees e ON e.id = d.employee_id
        WHERE d.id = ?
        LIMIT 1
    ");
    if ($headerStmt) {
        $headerStmt->bind_param('i', $distributionId);
        $headerStmt->execute();
        $distribution = $headerStmt->get_result()->fetch_assoc() ?: null;
        $headerStmt->close();
    }

    if ($distribution) {
        $itemStmt = $db->prepare("
            SELECT di.id, di.quantity_distributed, di.unit_cost, di.line_total, di.remarks,
                   ri.item_condition, poi.line_no, poi.item_type, COALESCE(NULLIF(di.reconciled_item_description, ''), poi.item_description) AS item_description, ac.account_code, c.classification_name, c.classification_family,
                   u.uom_name, u.abbreviation, r.system_reference AS receiving_reference
            FROM distribution_items di
            INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
            INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
            INNER JOIN receivings r ON r.id = ri.receiving_id
            LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
            LEFT JOIN classifications c ON c.id = poi.classification_id
            LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id
            WHERE di.distribution_id = ?
            ORDER BY poi.line_no ASC, di.id ASC
        ");
        if ($itemStmt) {
            $itemStmt->bind_param('i', $distributionId);
            $itemStmt->execute();
            $itemResult = $itemStmt->get_result();
            while ($itemResult && ($item = $itemResult->fetch_assoc())) {
                $detailStmt = $db->prepare("
                    SELECT id, brand, model, serial_no, property_number, remarks, is_distributed, is_disposed, correction_status, correction_reason, correction_remarks
                    FROM distribution_item_details
                    WHERE distribution_item_id = ?
                    ORDER BY id ASC
                ");
                $item['details'] = [];
                if ($detailStmt) {
                    $itemId = (int) $item['id'];
                    $detailStmt->bind_param('i', $itemId);
                    $detailStmt->execute();
                    $detailResult = $detailStmt->get_result();
                    if ($detailResult) {
                        $item['details'] = $detailResult->fetch_all(MYSQLI_ASSOC);
                    }
                    $detailStmt->close();
                }
                $items[] = $item;
            }
            $itemStmt->close();
        }
    }
}

if (!$distribution) {
    http_response_code(404);
    echo 'Distribution record not found.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($distribution['document_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body{font-size:12px;background:#fff}
        .print-wrap{max-width:1050px;margin:24px auto;padding:24px}
        .table th,.table td{font-size:12px;vertical-align:top}
        .correction-panel{border:1px solid #dee2e6;border-radius:8px;background:#f8f9fa;padding:14px;margin-bottom:16px}
        .unit-correction-form{border-top:1px dashed #ced4da;margin-top:8px;padding-top:8px}
        @media print {.no-print{display:none}.print-wrap{margin:0;max-width:none;padding:0}}
    </style>
</head>
<body>
    <div class="print-wrap">
        <div class="d-flex justify-content-between align-items-center mb-3 no-print">
            <a href="<?php echo base_url('modules/distributions/index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back</a>
            <div class="d-flex gap-2">
                <?php if ($canCancelDistribution && ($distribution['status'] ?? '') === 'posted'): ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="var panel=document.getElementById('cancelDistributionPanel'); if(panel){panel.classList.toggle('d-none');}">Cancel Distribution</button>
                <?php endif; ?>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Print</button>
            </div>
        </div>
        <?php $flash = get_flash(); ?>
        <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?> no-print"><?php echo h($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger no-print"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php if ($canCancelDistribution && ($distribution['status'] ?? '') === 'posted'): ?>
            <div class="d-none no-print" id="cancelDistributionPanel">
                <div class="alert alert-warning">
                    <div class="fw-semibold mb-1">Cancel this distribution</div>
                    <div class="small mb-2">This will return all linked unit details to Receiving availability and mark this distribution as cancelled. Use this only when the PAR/ICS was created but issuance will not proceed.</div>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="cancel_distribution">
                        <div class="col-md-9">
                            <label class="form-label small mb-1">Cancellation reason</label>
                            <input type="text" name="cancel_reason" class="form-control form-control-sm" placeholder="Example: item has dent before issuance" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-danger btn-sm w-100" onclick="return confirm('Cancel this distribution and return all linked items to Receiving?');">Confirm Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div class="correction-panel no-print">
            <div class="fw-semibold mb-1">Correction before physical issuance</div>
            <div class="text-muted mb-2">Use this only when the PAR/ICS was created but the unit was not physically issued yet. The unit will be removed from this distribution and blocked from redistribution until resolved.</div>
            <div class="small">Open an item below, then use <strong>Correct this unit</strong> on the affected serial/property number.</div>
        </div>
        <div class="text-center mb-4">
            <div class="fw-bold"><?php echo h(distribution_doc_title((string) $distribution['document_type'])); ?></div>
            <div>University of Antique</div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-4"><strong>Reference:</strong> <?php echo h($distribution['system_reference']); ?></div>
            <div class="col-4"><strong>Document No.:</strong> <?php echo h($distribution['document_no']); ?></div>
            <div class="col-4"><strong>Date:</strong> <?php echo h(date('M d, Y', strtotime($distribution['distribution_date']))); ?></div>
            <div class="col-4"><strong>Status:</strong> <?php echo operational_status_badge('posted_transaction', (string) ($distribution['status'] ?? 'posted')); ?></div>
            <div class="col-6"><strong>Office:</strong> <?php echo h($distribution['office_name']); ?></div>
            <div class="col-6"><strong>Accountable Employee:</strong> <?php echo $distribution['employee_no'] ? h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']) : 'Not specified'; ?></div>
            <div class="col-12"><strong>Purpose:</strong> <?php echo h($distribution['purpose'] ?: ''); ?></div>
        </div>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th style="width:5%;">Line</th>
                    <th>Description</th>
                    <th style="width:10%;">Qty</th>
                    <th style="width:10%;">Unit Cost</th>
                    <th style="width:10%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $uomLabel = trim((string) ($item['uom_name'] ?? ''));
                    if ($uomLabel === '' && !empty($item['abbreviation'])) {
                        $uomLabel = $item['abbreviation'];
                    } elseif (!empty($item['abbreviation'])) {
                        $uomLabel .= ' (' . $item['abbreviation'] . ')';
                    }
                    ?>
                    <tr>
                        <td><?php echo (int) $item['line_no']; ?></td>
                        <td>
                            <?php
                                $viewLabel = trim((!empty($item['classification_family']) ? $item['classification_family'] . ' / ' : '') . ($item['classification_name'] ?: 'No inventory class'));
                                $viewDescription = trim(($viewLabel !== '' ? $viewLabel . ' - ' : '') . ($item['item_description'] ?? ''));
                            ?>
                            <div class="fw-semibold"><?php echo h($viewLabel); ?></div>
                            <div><?php echo nl2br(h($viewDescription)); ?></div>
                            <div class="text-muted"><?php echo h($item['account_code'] ?: ''); ?><?php echo $uomLabel ? ' | ' . h($uomLabel) : ''; ?><?php echo $item['receiving_reference'] ? ' | ' . h($item['receiving_reference']) : ''; ?></div>
                            <div class="text-muted">Condition: <?php echo h($item['item_condition'] ?: ''); ?></div>
                            <?php if (!empty($item['details'])): ?>
                                <div class="mt-2">
                                    <?php foreach ($item['details'] as $detail): ?>
                                        <?php
                                        $isCorrected = (int) ($detail['is_distributed'] ?? 0) !== 1 || trim((string) ($detail['correction_status'] ?? '')) !== '';
                                        ?>
                                        <div class="mb-2">
                                            <div>
                                                Brand: <?php echo h($detail['brand']); ?> |
                                                Model: <?php echo h($detail['model']); ?> |
                                                Serial: <?php echo h($detail['serial_no']); ?>
                                                <?php if (!empty($detail['property_number'])): ?> | Property No.: <?php echo h($detail['property_number']); ?><?php endif; ?>
                                                <?php echo $detail['remarks'] !== '' ? ' | ' . h($detail['remarks']) : ''; ?>
                                                <?php if ($isCorrected): ?>
                                                    <span class="badge text-bg-warning ms-1">Corrected</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($detail['correction_status'])): ?>
                                                <div class="small text-muted">Status: <?php echo h($correctionOptions[$detail['correction_status']] ?? ucwords(str_replace('_', ' ', (string) $detail['correction_status']))); ?><?php echo !empty($detail['correction_reason']) ? ' | Reason: ' . h($detail['correction_reason']) : ''; ?></div>
                                            <?php endif; ?>
                                            <?php if (!$isCorrected && (int) ($detail['is_disposed'] ?? 0) === 0): ?>
                                                <form method="post" class="unit-correction-form row g-2 align-items-end">
                                                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="correct_distribution_unit">
                                                    <input type="hidden" name="detail_id" value="<?php echo (int) $detail['id']; ?>">
                                                    <div class="col-md-3">
                                                        <label class="form-label small mb-1">Next status</label>
                                                        <select name="next_status" class="form-select form-select-sm" required>
                                                            <option value="">Select</option>
                                                            <?php foreach ($correctionOptions as $statusValue => $statusLabel): ?>
                                                                <option value="<?php echo h($statusValue); ?>"><?php echo h($statusLabel); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-3">
                                                        <label class="form-label small mb-1">Reason</label>
                                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="Dent, damaged, wrong specs..." required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label small mb-1">Remarks</label>
                                                        <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Optional details">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button type="submit" class="btn btn-warning btn-sm w-100" onclick="return confirm('Correct this unit and remove it from the distribution?');">Correct unit</button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?php echo h(format_quantity($item['quantity_distributed'])); ?></td>
                        <td class="text-end"><?php echo h(format_currency((float) $item['unit_cost'])); ?></td>
                        <td class="text-end"><?php echo h(format_currency((float) $item['line_total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">Total</th>
                    <th class="text-end"><?php echo h(format_currency((float) $distribution['total_amount'])); ?></th>
                </tr>
            </tfoot>
        </table>

        <div class="row mt-5">
            <div class="col-6 text-center">
                <?php if (!empty($distribution['employee_no'])): ?>
                    <div class="fw-semibold"><?php echo h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']); ?></div>
                <?php else: ?>
                    <div class="fw-semibold text-muted">Not specified</div>
                <?php endif; ?>
                <div class="border-top pt-2"></div>
                <div class="small text-muted">Signature over Printed Name</div>
            </div>
            <div class="col-6 text-center">
                <div class="border-top pt-2"></div>
                <div class="small text-muted">Supply Officer</div>
            </div>
        </div>
    </div>
</body>
</html>
