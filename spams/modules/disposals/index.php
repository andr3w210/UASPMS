<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function disposal_asset_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));

    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

$db = db();
$page_title = 'Disposals';
$flash = get_flash();
$errors = [];
$available = [];
$rows = [];
$employees = [];
$typeFilter = trim((string) ($_GET['item_type'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);
$form = [
    'distribution_item_detail_id' => '',
    'disposal_date' => date('Y-m-d'),
    'reason' => 'unserviceable',
    'approved_by' => '',
    'remarks' => '',
];

if (!in_array($typeFilter, ['all', 'semi_expendable', 'equipment'], true)) {
    $typeFilter = 'all';
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    $employeeResult = $db->query("SELECT id, first_name, middle_name, last_name, suffix_name FROM employees WHERE is_active = 1 ORDER BY last_name ASC, first_name ASC");
    if ($employeeResult) {
        $employees = $employeeResult->fetch_all(MYSQLI_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        $form['distribution_item_detail_id'] = trim((string) ($_POST['distribution_item_detail_id'] ?? ''));
        $form['disposal_date'] = trim((string) ($_POST['disposal_date'] ?? date('Y-m-d')));
        $form['reason'] = trim((string) ($_POST['reason'] ?? 'unserviceable'));
        $form['approved_by'] = trim((string) ($_POST['approved_by'] ?? ''));
        $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

        $detailId = (int) ($form['distribution_item_detail_id'] !== '' ? $form['distribution_item_detail_id'] : 0);
        $approvedBy = (int) ($form['approved_by'] !== '' ? $form['approved_by'] : 0);

        if ($detailId <= 0) {
            $errors[] = 'Select an accountable asset to dispose.';
        }
        if ($form['disposal_date'] === '') {
            $errors[] = 'Disposal date is required.';
        }
        if ($form['reason'] === '') {
            $errors[] = 'Disposal reason is required.';
        }

        $asset = null;
        if (!$errors) {
            $assetStmt = $db->prepare("
                SELECT
                    did.id,
                    did.is_distributed,
                    did.is_disposed,
                    poi.item_type
                FROM distribution_item_details did
                INNER JOIN distribution_items di ON di.id = did.distribution_item_id
                INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
                INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                WHERE did.id = ?
                LIMIT 1
            ");
            if ($assetStmt) {
                $assetStmt->bind_param('i', $detailId);
                $assetStmt->execute();
                $asset = $assetStmt->get_result()->fetch_assoc() ?: null;
                $assetStmt->close();
            }

            if (!$asset) {
                $errors[] = 'The selected asset could not be found.';
            } elseif ((int) ($asset['is_disposed'] ?? 0) === 1) {
                $errors[] = 'The selected asset is already marked as disposed.';
            } elseif ((int) ($asset['is_distributed'] ?? 0) !== 1) {
                $errors[] = 'Only currently accountable assets can be disposed.';
            } else {
                $dupStmt = $db->prepare("SELECT id FROM disposals WHERE distribution_item_detail_id = ? AND status = 'posted' LIMIT 1");
                if ($dupStmt) {
                    $dupStmt->bind_param('i', $detailId);
                    $dupStmt->execute();
                    $existing = $dupStmt->get_result()->fetch_assoc();
                    $dupStmt->close();
                    if ($existing) {
                        $errors[] = 'A posted disposal already exists for the selected asset.';
                    }
                }
            }
        }

        if (!$errors && $asset) {
            $db->begin_transaction();
            try {
                $systemRef = next_module_code($db, 'disposals');
                $userId = current_user_id();

                $ins = $db->prepare("
                    INSERT INTO disposals (
                        system_reference,
                        disposal_date,
                        distribution_item_detail_id,
                        disposal_type,
                        reason,
                        approved_by,
                        remarks,
                        status,
                        created_by
                    ) VALUES (?, ?, ?, ?, ?, NULLIF(?, 0), ?, 'posted', ?)
                ");
                if (!$ins) {
                    throw new RuntimeException('Unable to prepare the disposal insert statement.');
                }

                $disposalType = ($asset['item_type'] ?? '') === 'semi_expendable' ? 'semi_expendable' : 'equipment';
                $ins->bind_param('ssissisi', $systemRef, $form['disposal_date'], $detailId, $disposalType, $form['reason'], $approvedBy, $form['remarks'], $userId);
                $ins->execute();
                $ins->close();

                $upd = $db->prepare("
                    UPDATE distribution_item_details
                    SET
                        is_disposed = 1,
                        is_distributed = 0
                    WHERE id = ?
                ");
                if (!$upd) {
                    throw new RuntimeException('Unable to update the asset disposal state.');
                }
                $upd->bind_param('i', $detailId);
                $upd->execute();
                $upd->close();

                $disposalId = (int) $db->insert_id;
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'disposals',
                    'record_id' => $disposalId,
                    'module_name' => 'disposals',
                    'record_type' => 'disposal',
                    'action_name' => 'post_disposal',
                    'new_values' => [
                        'system_reference' => $systemRef,
                        'disposal_date' => $form['disposal_date'],
                        'distribution_item_detail_id' => $detailId,
                        'disposal_type' => $disposalType,
                        'reason' => $form['reason'],
                        'approved_by' => $approvedBy,
                    ],
                    'description' => 'Posted asset disposal.',
                ]);

                $db->commit();
                set_flash('success', 'Disposal recorded successfully.');
                redirect('modules/disposals/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to record the disposal.';
            }
        }
    }

    $availableSql = "
        SELECT
            did.id,
            did.property_number,
            did.brand,
            did.model,
            did.serial_no,
            poi.item_type,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            d.document_no,
            d.document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name
        FROM distribution_item_details did
        INNER JOIN distribution_items di ON di.id = did.distribution_item_id
        INNER JOIN distributions d ON d.id = di.distribution_id AND d.status = 'posted'
        INNER JOIN receiving_items ri ON ri.id = di.receiving_item_id
        INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        WHERE did.is_distributed = 1
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
          AND poi.item_type IN ('semi_expendable', 'equipment')
    ";
    $types = '';
    $params = [];
    if ($typeFilter !== 'all') {
        $availableSql .= " AND poi.item_type = ?";
        $types .= 's';
        $params[] = $typeFilter;
    }
    if ($search !== '') {
        $availableSql .= " AND (
            did.property_number LIKE CONCAT('%', ?, '%')
            OR did.serial_no LIKE CONCAT('%', ?, '%')
            OR poi.item_description LIKE CONCAT('%', ?, '%')
            OR did.brand LIKE CONCAT('%', ?, '%')
            OR did.model LIKE CONCAT('%', ?, '%')
            OR o.office_name LIKE CONCAT('%', ?, '%')
        )";
        $types .= 'ssssss';
        array_push($params, $search, $search, $search, $search, $search, $search);
    }
    $availableSql .= " ORDER BY poi.item_type ASC, poi.item_description ASC, did.property_number ASC, did.serial_no ASC";

    $availableStmt = $db->prepare($availableSql);
    if ($availableStmt) {
        if ($params) {
            $availableStmt->bind_param($types, ...$params);
        }
        $availableStmt->execute();
        $available = $availableStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $availableStmt->close();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $preselectedDetailId > 0) {
        foreach ($available as $assetRow) {
            if ((int) ($assetRow['id'] ?? 0) === $preselectedDetailId) {
                $form['distribution_item_detail_id'] = (string) $preselectedDetailId;
                break;
            }
        }
    }

    $rowsSql = "
        SELECT
            dp.id,
            dp.system_reference,
            dp.disposal_date,
            dp.disposal_type,
            dp.reason,
            dp.remarks,
            did.property_number,
            did.serial_no,
            poi.item_type,
            poi.item_description,
            c.classification_name,
            c.classification_family,
            d.document_no,
            d.document_type,
            o.office_name,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix_name,
            ap.first_name AS approved_first_name,
            ap.middle_name AS approved_middle_name,
            ap.last_name AS approved_last_name,
            ap.suffix_name AS approved_suffix_name
        FROM disposals dp
        LEFT JOIN distribution_item_details did ON did.id = dp.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        LEFT JOIN employees ap ON ap.id = dp.approved_by
        ORDER BY dp.disposal_date DESC, dp.id DESC
    ";
    $rowsResult = $db->query($rowsSql);
    if ($rowsResult) {
        $rows = $rowsResult->fetch_all(MYSQLI_ASSOC);
    }
}

$availableCount = count($available);
$recentCount = count($rows);
$equipmentAvailable = 0;
$semiAvailable = 0;
foreach ($available as $assetRow) {
    if (($assetRow['item_type'] ?? '') === 'equipment') {
        $equipmentAvailable++;
    } elseif (($assetRow['item_type'] ?? '') === 'semi_expendable') {
        $semiAvailable++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="report-page-shell">
                    <div class="report-toolbar">
                        <div>
                            <h5 class="report-toolbar-title mb-0">Disposals</h5>
                            <p class="report-toolbar-copy">Record the final disposal of semi-expendable or equipment assets, keep the approval trail, and mark the accountable record as no longer active.</p>
                        </div>
                    </div>

                    <?php if ($flash): ?>
                        <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div>
                    <?php endif; ?>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div>
                    <?php endif; ?>

                    <div class="report-summary-grid">
                        <div class="report-summary-card">
                            <div class="report-summary-label">Available Assets</div>
                            <div class="report-summary-value"><?php echo number_format($availableCount); ?></div>
                            <div class="report-summary-note">Accountable assets still open for disposal posting.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Semi-Expendable</div>
                            <div class="report-summary-value"><?php echo number_format($semiAvailable); ?></div>
                            <div class="report-summary-note">Semi assets available for disposal.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Equipment</div>
                            <div class="report-summary-value"><?php echo number_format($equipmentAvailable); ?></div>
                            <div class="report-summary-note">Equipment assets available for disposal.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Recent Records</div>
                            <div class="report-summary-value"><?php echo number_format($recentCount); ?></div>
                            <div class="report-summary-note">Recorded disposal transactions in the module list.</div>
                        </div>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Find Disposable Assets</h6>
                        <form method="get" class="row g-3 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label">Inventory Type</label>
                                <select name="item_type" class="form-select">
                                    <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All accountable assets</option>
                                    <option value="semi_expendable" <?php echo $typeFilter === 'semi_expendable' ? 'selected' : ''; ?>>Semi-Expendable</option>
                                    <option value="equipment" <?php echo $typeFilter === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Search</label>
                                <input type="text" name="q" class="form-control" value="<?php echo h($search); ?>" placeholder="Property no., serial no., description, brand, model, or office">
                            </div>
                            <div class="col-md-2 d-flex gap-2">
                                <button type="submit" class="btn btn-primary w-100">Apply</button>
                                <a href="<?php echo base_url('modules/disposals/index.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Record Disposal</h6>
                        <form method="post" class="row g-3 align-items-end">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <div class="col-md-6">
                                <label class="form-label">Accountable Asset</label>
                                <select name="distribution_item_detail_id" class="form-select" required>
                                    <option value="">Select accountable asset</option>
                                    <?php foreach ($available as $asset): ?>
                                        <option value="<?php echo (int) $asset['id']; ?>" <?php echo $form['distribution_item_detail_id'] === (string) $asset['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h(trim(implode(' | ', array_filter([
                                                strtoupper((string) ($asset['item_type'] ?? '')),
                                                $asset['property_number'] ?? '',
                                                $asset['serial_no'] ?? '',
                                                disposal_asset_label($asset),
                                                $asset['office_name'] ?? '',
                                            ]))));
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Disposal Date</label>
                                <input type="date" name="disposal_date" class="form-control" value="<?php echo h($form['disposal_date']); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Reason</label>
                                <select name="reason" class="form-select">
                                    <option value="unserviceable" <?php echo $form['reason'] === 'unserviceable' ? 'selected' : ''; ?>>Unserviceable</option>
                                    <option value="obsolete" <?php echo $form['reason'] === 'obsolete' ? 'selected' : ''; ?>>Obsolete</option>
                                    <option value="lost" <?php echo $form['reason'] === 'lost' ? 'selected' : ''; ?>>Lost</option>
                                    <option value="beyond_repair" <?php echo $form['reason'] === 'beyond_repair' ? 'selected' : ''; ?>>Beyond Repair</option>
                                    <option value="other" <?php echo $form['reason'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Approved By</label>
                                <select name="approved_by" class="form-select">
                                    <option value="">Select approver</option>
                                    <?php foreach ($employees as $employee): ?>
                                        <option value="<?php echo (int) $employee['id']; ?>" <?php echo $form['approved_by'] === (string) $employee['id'] ? 'selected' : ''; ?>>
                                            <?php echo h(employee_display_name($employee)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-10">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" value="<?php echo h($form['remarks']); ?>" placeholder="Optional disposal notes or basis">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button class="btn btn-danger">Post Disposal</button>
                            </div>
                        </form>
                    </div>

                    <div class="report-table-card table-responsive mobile-table-frame">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Asset</th>
                                    <th>Document</th>
                                    <th>Reason</th>
                                    <th>Approved By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h($row['system_reference']); ?></td>
                                            <td><?php echo h(!empty($row['disposal_date']) ? date('M d, Y', strtotime((string) $row['disposal_date'])) : ''); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($row['property_number'] ?? ''); ?></div>
                                                <div><?php echo h(disposal_asset_label($row)); ?></div>
                                                <?php if (!empty($row['serial_no'])): ?><div class="small text-muted"><?php echo h($row['serial_no']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['document_type'] ?? '', $row['document_no'] ?? ''])))); ?></td>
                                            <td><?php echo h(trim(implode(' | ', array_filter([$row['disposal_type'] ?? '', $row['reason'] ?? '', $row['remarks'] ?? ''])))); ?></td>
                                            <td><?php echo h(employee_display_name([
                                                'first_name' => $row['approved_first_name'] ?? '',
                                                'middle_name' => $row['approved_middle_name'] ?? '',
                                                'last_name' => $row['approved_last_name'] ?? '',
                                                'suffix_name' => $row['approved_suffix_name'] ?? '',
                                            ])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No disposal records yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
