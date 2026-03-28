<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

function return_asset_label(array $row): string
{
    $prefix = trim(implode(' / ', array_filter([
        trim((string) ($row['classification_family'] ?? '')),
        trim((string) ($row['classification_name'] ?? '')),
    ])));

    return trim(($prefix !== '' ? $prefix . ' - ' : '') . (string) ($row['item_description'] ?? ''));
}

$db = db();
$page_title = 'Returns';
$flash = get_flash();
$errors = [];
$success = '';
$available = [];
$rows = [];
$typeFilter = trim((string) ($_GET['item_type'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));
$preselectedDetailId = (int) ($_GET['detail_id'] ?? 0);
$form = [
    'distribution_item_detail_id' => '',
    'return_date' => date('Y-m-d'),
    'reason' => '',
    'remarks' => '',
];

if (!in_array($typeFilter, ['all', 'semi_expendable', 'equipment'], true)) {
    $typeFilter = 'all';
}

if (!$db) {
    $errors[] = 'Unable to connect to the database.';
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }

        $form['distribution_item_detail_id'] = trim((string) ($_POST['distribution_item_detail_id'] ?? ''));
        $form['return_date'] = trim((string) ($_POST['return_date'] ?? date('Y-m-d')));
        $form['reason'] = trim((string) ($_POST['reason'] ?? ''));
        $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

        $detailId = (int) ($form['distribution_item_detail_id'] !== '' ? $form['distribution_item_detail_id'] : 0);

        if ($detailId <= 0) {
            $errors[] = 'Select an accountable asset to return.';
        }
        if ($form['return_date'] === '') {
            $errors[] = 'Return date is required.';
        }

        $asset = null;
        if (!$errors) {
            $assetStmt = $db->prepare("
                SELECT
                    did.id,
                    did.current_office_id,
                    did.current_employee_id,
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
                $errors[] = 'Disposed assets can no longer be returned.';
            } elseif ((int) ($asset['is_distributed'] ?? 0) !== 1) {
                $errors[] = 'The selected asset is no longer marked as distributed.';
            } else {
                $dupStmt = $db->prepare("SELECT id FROM returns WHERE distribution_item_detail_id = ? AND status = 'posted' LIMIT 1");
                if ($dupStmt) {
                    $dupStmt->bind_param('i', $detailId);
                    $dupStmt->execute();
                    $existing = $dupStmt->get_result()->fetch_assoc();
                    $dupStmt->close();
                    if ($existing) {
                        $errors[] = 'A posted return already exists for the selected asset.';
                    }
                }
            }
        }

        if (!$errors && $asset) {
            $db->begin_transaction();
            try {
                $systemRef = next_module_code($db, 'returns');
                $userId = current_user_id();
                $officeId = (int) ($asset['current_office_id'] ?? 0);
                $employeeId = (int) ($asset['current_employee_id'] ?? 0);

                $ins = $db->prepare("
                    INSERT INTO returns (
                        system_reference,
                        return_date,
                        distribution_item_detail_id,
                        office_id,
                        employee_id,
                        reason,
                        remarks,
                        status,
                        created_by
                    ) VALUES (?, ?, ?, NULLIF(?, 0), NULLIF(?, 0), ?, ?, 'posted', ?)
                ");

                if (!$ins) {
                    throw new RuntimeException('Unable to prepare the return insert statement.');
                }

                $ins->bind_param('ssiiissi', $systemRef, $form['return_date'], $detailId, $officeId, $employeeId, $form['reason'], $form['remarks'], $userId);
                $ins->execute();
                $ins->close();

                $upd = $db->prepare("
                    UPDATE distribution_item_details
                    SET
                        is_distributed = 0,
                        current_office_id = NULL,
                        current_employee_id = NULL,
                        current_responsibility_code_id = NULL
                    WHERE id = ?
                ");
                if (!$upd) {
                    throw new RuntimeException('Unable to update the asset accountability state.');
                }
                $upd->bind_param('i', $detailId);
                $upd->execute();
                $upd->close();

                $returnId = (int) $db->insert_id;
                write_audit_log($db, [
                    'action' => 'insert',
                    'table_name' => 'returns',
                    'record_id' => $returnId,
                    'module_name' => 'returns',
                    'record_type' => 'return',
                    'action_name' => 'post_return',
                    'new_values' => [
                        'system_reference' => $systemRef,
                        'return_date' => $form['return_date'],
                        'distribution_item_detail_id' => $detailId,
                        'office_id' => $officeId,
                        'employee_id' => $employeeId,
                        'reason' => $form['reason'],
                    ],
                    'description' => 'Posted asset return.',
                ]);

                $db->commit();
                set_flash('success', 'Return recorded successfully.');
                redirect('modules/returns/index.php');
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to record the return.';
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
        LEFT JOIN offices base_o ON base_o.id = d.office_id
        LEFT JOIN employees base_e ON base_e.id = d.employee_id
        LEFT JOIN offices o ON o.id = COALESCE(did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(did.current_employee_id, d.employee_id)
        LEFT JOIN returns rt ON rt.distribution_item_detail_id = did.id AND rt.status = 'posted'
        WHERE did.is_distributed = 1
          AND (did.is_disposed IS NULL OR did.is_disposed = 0)
          AND rt.id IS NULL
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
            rt.id,
            rt.system_reference,
            rt.return_date,
            rt.reason,
            rt.remarks,
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
            e.suffix_name
        FROM returns rt
        LEFT JOIN distribution_item_details did ON did.id = rt.distribution_item_detail_id
        LEFT JOIN distribution_items di ON di.id = did.distribution_item_id
        LEFT JOIN distributions d ON d.id = di.distribution_id
        LEFT JOIN receiving_items ri ON ri.id = di.receiving_item_id
        LEFT JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
        LEFT JOIN classifications c ON c.id = poi.classification_id
        LEFT JOIN offices o ON o.id = COALESCE(rt.office_id, did.current_office_id, d.office_id)
        LEFT JOIN employees e ON e.id = COALESCE(rt.employee_id, did.current_employee_id, d.employee_id)
        ORDER BY rt.return_date DESC, rt.id DESC
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
                            <h5 class="report-toolbar-title mb-0">Returns</h5>
                            <p class="report-toolbar-copy">Record the return of distributed semi-expendable or equipment assets and bring them back out of active accountability without losing the audit trail.</p>
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
                            <div class="report-summary-note">Distributed assets that can still be returned.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Semi-Expendable</div>
                            <div class="report-summary-value"><?php echo number_format($semiAvailable); ?></div>
                            <div class="report-summary-note">Semi assets currently available for return.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Equipment</div>
                            <div class="report-summary-value"><?php echo number_format($equipmentAvailable); ?></div>
                            <div class="report-summary-note">Equipment assets currently available for return.</div>
                        </div>
                        <div class="report-summary-card">
                            <div class="report-summary-label">Recent Records</div>
                            <div class="report-summary-value"><?php echo number_format($recentCount); ?></div>
                            <div class="report-summary-note">Posted return transactions already recorded.</div>
                        </div>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Find Returnable Assets</h6>
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
                                <a href="<?php echo base_url('modules/returns/index.php'); ?>" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>
                    </div>

                    <div class="report-filter-card">
                        <h6 class="report-filter-title">Record Return</h6>
                        <form method="post" class="row g-3 align-items-end">
                            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                            <div class="col-md-6">
                                <label class="form-label">Accountable Asset</label>
                                <select name="distribution_item_detail_id" class="form-select" required>
                                    <option value="">Select distributed asset</option>
                                    <?php foreach ($available as $asset): ?>
                                        <option value="<?php echo (int) $asset['id']; ?>" <?php echo $form['distribution_item_detail_id'] === (string) $asset['id'] ? 'selected' : ''; ?>>
                                            <?php
                                            echo h(trim(implode(' | ', array_filter([
                                                strtoupper((string) ($asset['item_type'] ?? '')),
                                                $asset['property_number'] ?? '',
                                                $asset['serial_no'] ?? '',
                                                return_asset_label($asset),
                                                $asset['office_name'] ?? '',
                                            ]))));
                                            ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Return Date</label>
                                <input type="date" name="return_date" class="form-control" value="<?php echo h($form['return_date']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Reason</label>
                                <input type="text" name="reason" class="form-control" value="<?php echo h($form['reason']); ?>" placeholder="Reason for return">
                            </div>
                            <div class="col-md-10">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" class="form-control" value="<?php echo h($form['remarks']); ?>" placeholder="Optional notes for the return record">
                            </div>
                            <div class="col-md-2 d-grid">
                                <button class="btn btn-primary">Post Return</button>
                            </div>
                        </form>
                    </div>

                    <div class="report-table-card table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Date</th>
                                    <th>Asset</th>
                                    <th>Document</th>
                                    <th>From Office / Officer</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows): ?>
                                    <?php foreach ($rows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo h($row['system_reference']); ?></td>
                                            <td><?php echo h(!empty($row['return_date']) ? date('M d, Y', strtotime((string) $row['return_date'])) : ''); ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo h($row['property_number'] ?? ''); ?></div>
                                                <div><?php echo h(return_asset_label($row)); ?></div>
                                                <?php if (!empty($row['serial_no'])): ?><div class="small text-muted"><?php echo h($row['serial_no']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['document_type'] ?? '', $row['document_no'] ?? ''])))); ?></td>
                                            <td><?php echo h(trim(implode(' / ', array_filter([$row['office_name'] ?? '', employee_display_name($row)])))); ?></td>
                                            <td><?php echo h(trim(implode(' | ', array_filter([$row['reason'] ?? '', $row['remarks'] ?? ''])))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No return records yet.</td></tr>
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
