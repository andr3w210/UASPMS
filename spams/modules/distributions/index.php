<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator', 'Supply Officer', 'Property Officer');

// Page metadata and UI state
$page_title = 'Distribution';
$errors = [];
$flash = get_flash();

// Database and initial state
$db = db_connect();

// Default state variables
$offices              = [];
$employees            = [];
$distributions        = [];
$iarList              = [];
$candidateItems       = [];
$distributionType     = $_GET['document_type'] ?? 'ics';
if (!in_array($distributionType, ['ics','par'], true)) {
    $distributionType = 'ics';
}
$distributionSemiType = $_GET['semi_type'] ?? 'high_value';
if (!in_array($distributionSemiType, ['high_value','low_value'], true)) {
    $distributionSemiType = 'high_value';
}
$itemTypeFilter = $distributionType === 'par' ? 'equipment' : 'semi_expendable';

$form = [
    'system_reference'  => '',
    'document_type'     => $distributionType,
    'document_no'       => '',
    'distribution_date' => date('Y-m-d'),
    'office_id'         => '',
    'employee_id'       => '',
    'purpose'           => '',
    'remarks'           => '',
];

$selectedReceivingId = (int) ($_GET['receiving_id'] ?? 0);

function preview_distribution_doc_no($db, string $docType, string $date, string $semiType = 'high_value'): string {
    $year  = date('Y', strtotime($date) ?: time());
    $month = date('m', strtotime($date) ?: time());
    if ($docType === 'par') {
        $prefix = 'PAR';
    } elseif ($semiType === 'low_value') {
        $prefix = 'SPLV';
    } else {
        $prefix = 'SPHV';
    }
    $like = $prefix . '-' . $year . '-' . $month . '-%';
    $nextSeq = 1;
    $stmt = $db->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(document_no, '-', -1) AS UNSIGNED)), 0) + 1 AS next_seq FROM distributions WHERE document_no LIKE ?");
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $nextSeq = (int)($row['next_seq'] ?? 1);
        $stmt->close();
    }
    return $prefix . '-' . $year . '-' . $month . '-' . str_pad((string)$nextSeq, 4, '0', STR_PAD_LEFT);
}

if ($db) {
    $threshold    = get_active_threshold($db);
    $equipmentMin = (float)$threshold['equipment_min'];
    $semiHvMin    = (float)$threshold['semi_hv_min'];

    $form['system_reference'] = preview_module_code($db, 'distributions');
    $form['document_no'] = preview_distribution_doc_no(
        $db, $distributionType,
        $form['distribution_date'], $distributionSemiType
    );

    // Load offices
    $officeResult = $db->query(
        "SELECT id, office_name FROM offices
         WHERE is_active = 1 ORDER BY office_name ASC"
    );
    if ($officeResult) $offices = $officeResult->fetch_all(MYSQLI_ASSOC);

    // Load employees
    $empResult = $db->query(
        "SELECT id, office_id, employee_no, first_name, middle_name,
                last_name, suffix_name
         FROM employees WHERE is_active = 1
         ORDER BY last_name ASC, first_name ASC"
    );
    if ($empResult) $employees = $empResult->fetch_all(MYSQLI_ASSOC);

    // Load IAR list for split panel
    $iarStmt = $db->prepare("SELECT r.id, r.system_reference, r.received_date, po.po_number, s.supplier_name, COUNT(DISTINCT rid.id) AS available_units FROM receivings r INNER JOIN purchase_orders po ON po.id = r.purchase_order_id INNER JOIN suppliers s ON s.id = po.supplier_id INNER JOIN receiving_items ri ON ri.receiving_id = r.id INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id AND poi.item_type = ? INNER JOIN receiving_item_details rid ON rid.receiving_item_id = ri.id AND rid.is_distributed = 0 WHERE r.status != 'cancelled' GROUP BY r.id, r.system_reference, r.received_date, po.po_number, s.supplier_name HAVING COUNT(DISTINCT rid.id) > 0 ORDER BY r.received_date DESC, r.id DESC");
    if ($iarStmt) {
        $iarStmt->bind_param('s', $itemTypeFilter);
        $iarStmt->execute();
        $iarList = $iarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $iarStmt->close();
    }

    // Posted distributions list with optional filtering (moved inside $db guard)
    $filterDistType = trim($_GET['filter_type'] ?? '');
    $filterDistQ    = trim($_GET['dist_q'] ?? '');

    $distWhere  = [];
    $distParams = [];
    $distTypes  = '';

    if (in_array($filterDistType, ['ics', 'par'], true)) {
        $distWhere[] = 'd.document_type = ?';
        $distTypes .= 's';
        $distParams[] = $filterDistType;
    }

    if ($filterDistQ !== '') {
        $distWhere[] = "(d.system_reference LIKE ? OR d.document_no LIKE ? OR o.office_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? )";
        $like = '%' . $filterDistQ . '%';
        $distTypes .= 'ssss';
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
    }

    $whereSql = $distWhere ? 'WHERE ' . implode(' AND ', $distWhere) : '';

    $sql = "SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.status, " .
        "o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name " .
        "FROM distributions d " .
        "INNER JOIN offices o ON o.id = d.office_id " .
        "LEFT JOIN employees e ON e.id = d.employee_id " .
        $whereSql .
        " ORDER BY d.distribution_date DESC, d.id DESC";

    if (count($distParams) > 0) {
        $distStmt = $db->prepare($sql);
        if ($distStmt) {
            $refs = [];
            $refs[] = &$distTypes;
            foreach ($distParams as $k => $v) {
                $refs[] = &$distParams[$k];
            }
            call_user_func_array([$distStmt, 'bind_param'], $refs);
            $distStmt->execute();
            $distRes = $distStmt->get_result();
            $distributions = $distRes ? $distRes->fetch_all(MYSQLI_ASSOC) : [];
            $distStmt->close();
        } else {
            $distributions = [];
        }
    } else {
        $distResult = $db->query($sql);
        $distributions = $distResult ? $distResult->fetch_all(MYSQLI_ASSOC) : [];
    }

} // end if ($db)

function distribution_doc_label(string $type): string
{
    return $type === 'par' ? 'PAR' : 'ICS';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            $errors[] = 'Invalid CSRF token.';
        }
    $selectedReceivingId = (int) ($_POST['receiving_id'] ?? 0);
        $form['document_type'] = $_POST['document_type'] ?? 'ics';
        if (!in_array($form['document_type'], ['ics', 'par'], true)) {
            $form['document_type'] = 'ics';
        }
        $threshold    = get_active_threshold($db);
        $equipmentMin = (float)$threshold['equipment_min'];
        $semiHvMin    = (float)$threshold['semi_hv_min'];
        $form['system_reference'] = preview_module_code($db, 'distributions');
        $form['distribution_date'] = old($_POST, 'distribution_date', date('Y-m-d'));
        $form['document_no'] = preview_distribution_doc_no($db, $form['document_type'], $form['distribution_date']);
        $form['office_id'] = old($_POST, 'office_id');
        $form['employee_id'] = old($_POST, 'employee_id');
        $form['purpose'] = old($_POST, 'purpose');
        $form['remarks'] = old($_POST, 'remarks');

        if ($form['distribution_date'] === '') {
            $errors[] = 'Distribution date is required.';
        }
        if ($form['office_id'] === '') {
            $errors[] = 'Office is required.';
        }

        $officeId = (int) ($form['office_id'] !== '' ? $form['office_id'] : 0);
        $employeeId = (int) ($form['employee_id'] !== '' ? $form['employee_id'] : 0);
        if ($employeeId > 0) {
            $employeeValid = false;
            foreach ($employees as $employee) {
                if ((int) $employee['id'] === $employeeId) {
                    $employeeValid = (int) ($employee['office_id'] ?? 0) === $officeId;
                    break;
                }
            }
            if (!$employeeValid) {
                $errors[] = 'Selected employee does not belong to the chosen office.';
            }
        }

        $postedItems = $_POST['items'] ?? [];
        $validatedItems = [];
        $totalAmount = 0.00;

        if ($selectedReceivingId > 0) {
            // Unit-level selection: user checks individual receiving_item_details
            $selectedUnits = array_keys(array_filter($_POST['units'] ?? []));
            $unitRemarks = $_POST['unit_remarks'] ?? [];
            if (empty($selectedUnits)) {
                $errors[] = 'Select at least one unit to distribute.';
            } else {
                $detailCheckStmt = $db->prepare("SELECT rid.id AS detail_id, rid.receiving_item_id, rid.brand, rid.model, rid.serial_no, rid.remarks AS detail_remarks, ri.unit_cost, poi.item_type, poi.line_no, poi.item_description, ac.account_code, c.classification_name, u.abbreviation, r.system_reference AS receiving_reference, r.received_date, po.po_number, rid.is_distributed FROM receiving_item_details rid INNER JOIN receiving_items ri ON ri.id = rid.receiving_item_id INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id LEFT JOIN account_codes ac ON ac.id = poi.account_code_id LEFT JOIN classifications c ON c.id = poi.classification_id LEFT JOIN unit_of_measures u ON u.id = poi.unit_of_measure_id INNER JOIN receivings r ON r.id = ri.receiving_id LEFT JOIN purchase_orders po ON po.id = r.purchase_order_id WHERE rid.id = ?");
                if (!$detailCheckStmt) {
                    $errors[] = 'Internal error validating selected units.';
                } else {
                    foreach ($selectedUnits as $did) {
                        $detailId = (int) $did;
                        $detailCheckStmt->bind_param('i', $detailId);
                        $detailCheckStmt->execute();
                        $row = $detailCheckStmt->get_result()->fetch_assoc();
                        if (!$row) {
                            $errors[] = 'Selected unit not found or already distributed: ' . h($detailId);
                            continue;
                        }
                        if (($row['item_type'] ?? '') !== $itemTypeFilter) {
                            $errors[] = 'Selected unit is not of the expected item type.';
                            continue;
                        }
                        if (!empty($row['is_distributed'])) {
                            $errors[] = 'Selected unit has already been distributed: ' . h($detailId);
                            continue;
                        }

                        $issuanceItemId = 0;
                        $originReceivingItemId = (int) $row['receiving_item_id'];
                        $unitCost = (float) $row['unit_cost'];
                        $lineTotal = round($unitCost * 1, 2);
                        $totalAmount += $lineTotal;

                        $validatedItems[] = [
                            'issuance_item_id' => $issuanceItemId,
                            'origin_receiving_item_id' => $originReceivingItemId,
                            'quantity_distributed' => 1,
                            'unit_cost' => $unitCost,
                            'line_total' => $lineTotal,
                            'remarks' => trim((string) ($unitRemarks[$detailId] ?? '')),
                            'details' => [[
                                'id' => $detailId,
                                'brand' => $row['brand'] ?? '',
                                'model' => $row['model'] ?? '',
                                'serial_no' => $row['serial_no'] ?? '',
                                'remarks' => $row['detail_remarks'] ?? '',
                            ]],
                        ];
                    }
                    $detailCheckStmt->close();
                }
            }
        } else {
            foreach ($candidateItems as $candidate) {
                // Guard: skip candidates that don't match current item type filter (prevent supplies from slipping through)
                if (($candidate['item_type'] ?? '') !== $itemTypeFilter) {
                    continue;
                }
                $candidateId = (int) $candidate['id'];
                $posted = isset($postedItems[$candidateId]) && is_array($postedItems[$candidateId]) ? $postedItems[$candidateId] : [];
                $distributeQty = isset($posted['quantity_distributed']) ? (float) $posted['quantity_distributed'] : 0;
                $lineRemarks = trim((string) ($posted['remarks'] ?? ''));
                $remainingQty = (float) $candidate['remaining_distribution_qty'];

                if ($distributeQty <= 0) {
                    continue;
                }
                if ($distributeQty > $remainingQty + 0.001) {
                    $lineNo = isset($candidate['line_no']) ? $candidate['line_no'] : 'N/A';
                    $errors[] = 'Quantity to distribute cannot exceed remaining quantity (' . number_format($remainingQty, 2) . ') for item on line ' . $lineNo . '.';
                    continue;
                }

                $lineTotal = round($distributeQty * (float) $candidate['unit_cost'], 2);
                $totalAmount += $lineTotal;
                // Determine whether candidate came from an issuance (issuance_items) or directly from receiving_items
                $isIssuanceCandidate = isset($candidate['issuance_id']) && $candidate['issuance_id'];
                if ($isIssuanceCandidate) {
                    $issuanceItemId = $candidateId;
                    $originReceivingItemId = (int) ($candidate['receiving_item_id'] ?? 0);
                } else {
                    // Candidate from receiving_items: set issuance_item_id to 0 and origin_receiving_item_id to the receiving_item id
                    $issuanceItemId = 0;
                    $originReceivingItemId = $candidateId;
                }

                $validatedItems[] = [
                    'issuance_item_id' => $issuanceItemId,
                    'origin_receiving_item_id' => $originReceivingItemId,
                    'quantity_distributed' => $distributeQty,
                    'unit_cost' => (float) $candidate['unit_cost'],
                    'line_total' => $lineTotal,
                    'remarks' => $lineRemarks,
                    'details' => $candidate['details'],
                ];
            }
        }

        if (!$validatedItems) {
            $errors[] = 'Select at least one line to distribute.';
        }

        if (!$errors) {
            $db->begin_transaction();
            try {
                $systemReference = next_module_code($db, 'distributions');
                // Determine semi type for this save: prefer POSTed value, fall back to detected value
                $postSemi = $_POST['semi_type'] ?? $distributionSemiType;
                if (!in_array($postSemi, ['high_value', 'low_value'], true)) {
                    $postSemi = null;
                }
                $documentNo = preview_distribution_doc_no($db, $form['document_type'], $form['distribution_date'], $postSemi);
                $userId = current_user_id();

                $headerStmt = $db->prepare("INSERT INTO distributions (system_reference, document_type, semi_expendable_type, document_no, distribution_date, office_id, employee_id, purpose, remarks, status, total_amount, created_by) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, NULLIF(?, 0), ?, ?, 'posted', ?, ?)");
                $itemStmt = $db->prepare("INSERT INTO distribution_items (distribution_id, issuance_item_id, receiving_item_id, quantity_distributed, unit_cost, line_total, remarks) VALUES (?, NULLIF(?,0), NULLIF(?,0), ?, ?, ?, ?)");
                $detailStmt = $db->prepare("INSERT INTO distribution_item_details (distribution_item_id, receiving_item_detail_id, brand, model, serial_no, remarks, property_number) VALUES (?, NULLIF(?, 0), ?, ?, ?, ?, ?)");
                if (!$headerStmt || !$itemStmt || !$detailStmt) {
                    throw new RuntimeException('Unable to prepare distribution statements.');
                }

                $semiForBind = $postSemi ?? '';
                $headerStmt->bind_param('sssssiissdi', $systemReference, $form['document_type'], $semiForBind, $documentNo, $form['distribution_date'], $officeId, $employeeId, $form['purpose'], $form['remarks'], $totalAmount, $userId);
                $headerStmt->execute();
                $distributionId = (int) $headerStmt->insert_id;
                $headerStmt->close();

                // Prepare statement to mark a receiving item detail as distributed when assigned
                $markDetailStmt = $db->prepare("UPDATE receiving_item_details SET is_distributed = 1 WHERE id = ?");
                if (!$markDetailStmt) {
                    throw new RuntimeException('Unable to prepare mark-detail statement.');
                }

                // Prepare statement to fetch fund/account/rc for property number generation
                $fundStmt = $db->prepare(
                    "SELECT f.fund_code, ac.account_code, rc.code AS rc_code
                     FROM receiving_items ri
                     INNER JOIN purchase_order_items poi ON poi.id = ri.purchase_order_item_id
                     INNER JOIN receivings r ON r.id = ri.receiving_id
                     INNER JOIN purchase_orders po ON po.id = r.purchase_order_id
                     INNER JOIN funds f ON f.id = po.fund_id
                     LEFT JOIN account_codes ac ON ac.id = poi.account_code_id
                     LEFT JOIN responsibility_codes rc ON rc.office_id = ?
                     WHERE ri.id = ?
                     LIMIT 1"
                );

                foreach ($validatedItems as $item) {
                    // When candidate is from an issuance, the validated 'receiving_item_id' holds the issuance_item id.
                    // We also pass the original receiving_item_id (if available) into the second parameter to preserve linkage.
                    $issuanceItemId = (int) ($item['issuance_item_id'] ?? 0);
                    $originReceivingItemId = (int) ($item['origin_receiving_item_id'] ?? 0);
                    $itemStmt->bind_param('iiiddds', $distributionId, $issuanceItemId, $originReceivingItemId, $item['quantity_distributed'], $item['unit_cost'], $item['line_total'], $item['remarks']);
                    $itemStmt->execute();
                    $distributionItemId = (int) $itemStmt->insert_id;

                    foreach ($item['details'] as $detail) {
                        $detailId = (int) ($detail['id'] ?? 0);
                        $propertyNo = '';
                        if ($fundStmt) {
                            $fundStmt->bind_param('ii', $officeId, $originReceivingItemId);
                            $fundStmt->execute();
                            $fundRow = $fundStmt->get_result()->fetch_assoc();
                            $year        = date('Y', strtotime($form['distribution_date']));
                            $fundCode    = $fundRow['fund_code'] ?? '';
                            $accountCode = $fundRow['account_code'] ?? '';
                            $rcCode      = $fundRow['rc_code'] ?? '';
                            $propertyNo  = generate_property_number($db, $year, $fundCode, $accountCode, $rcCode);
                        }

                        $detailStmt->bind_param('iisssss', $distributionItemId, $detailId, $detail['brand'], $detail['model'], $detail['serial_no'], $detail['remarks'], $propertyNo);
                        $detailStmt->execute();
                        // If this detail references a receiving_item_detail, mark that unit as distributed
                        if ($detailId > 0) {
                            $markDetailStmt->bind_param('i', $detailId);
                            $markDetailStmt->execute();
                        }
                    }
                }

                $detailStmt->close();
                if ($fundStmt) $fundStmt->close();
                $markDetailStmt->close();
                $itemStmt->close();
                $db->commit();
                set_flash('success', strtoupper($form['document_type']) . ' distribution posted successfully.');
                // Redirect to the canonical document (ICS or PAR) with a created flag
                if ($form['document_type'] === 'par') {
                    $redirectUrl = 'modules/distributions/par.php?id=' . $distributionId . '&created=1';
                } else {
                    // ICS (include semi_type when present)
                    $redirectUrl = 'modules/distributions/ics.php?id=' . $distributionId . '&created=1';
                    if (!empty($postSemi)) {
                        $redirectUrl .= '&semi_type=' . urlencode($postSemi);
                    }
                }
                redirect($redirectUrl);
            } catch (Throwable $e) {
                $db->rollback();
                $errors[] = 'Unable to save the distribution.';
            }
        }
    }

    // Posted distributions list with optional filtering
    $filterDistType = trim($_GET['filter_type'] ?? '');
    $filterDistQ    = trim($_GET['dist_q'] ?? '');

    $distWhere  = [];
    $distParams = [];
    $distTypes  = '';

    if (in_array($filterDistType, ['ics', 'par'], true)) {
        $distWhere[] = 'd.document_type = ?';
        $distTypes .= 's';
        $distParams[] = $filterDistType;
    }

    if ($filterDistQ !== '') {
        $distWhere[] = "(d.system_reference LIKE ? OR d.document_no LIKE ? OR o.office_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? )";
        $like = '%' . $filterDistQ . '%';
        $distTypes .= 'ssss';
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
        $distParams[] = $like;
    }

    $whereSql = $distWhere ? 'WHERE ' . implode(' AND ', $distWhere) : '';

    $sql = "SELECT d.id, d.system_reference, d.document_type, d.document_no, d.distribution_date, d.total_amount, d.status, " .
        "o.office_name, e.employee_no, e.first_name, e.middle_name, e.last_name, e.suffix_name " .
        "FROM distributions d " .
        "INNER JOIN offices o ON o.id = d.office_id " .
        "LEFT JOIN employees e ON e.id = d.employee_id " .
        $whereSql .
        " ORDER BY d.distribution_date DESC, d.id DESC";

    if (count($distParams) > 0) {
        $distStmt = $db->prepare($sql);
        if ($distStmt) {
            $refs = [];
            $refs[] = &$distTypes;
            foreach ($distParams as $k => $v) {
                $refs[] = &$distParams[$k];
            }
            call_user_func_array([$distStmt, 'bind_param'], $refs);
            $distStmt->execute();
            $distRes = $distStmt->get_result();
            $distributions = $distRes ? $distRes->fetch_all(MYSQLI_ASSOC) : [];
            $distStmt->close();
        } else {
            $distributions = [];
        }
    } else {
        $distResult = $db->query($sql);
        $distributions = $distResult ? $distResult->fetch_all(MYSQLI_ASSOC) : [];
    }

// Ensure expected variables exist for the template and SPA compatibility
$selectedPoId = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$selectedReceivingId = isset($_POST['receiving_id']) ? (int) $_POST['receiving_id'] : (isset($_GET['receiving_id']) ? (int) $_GET['receiving_id'] : 0);
$purchaseOrders = $purchaseOrders ?? [];
$iarList = $iarList ?? [];
$selectedReceiving = $selectedReceiving ?? null;
$candidateItems = $candidateItems ?? [];
$distributions = $distributions ?? [];
$employees = $employees ?? [];
$offices = $offices ?? [];
$filterDistType = $filterDistType ?? ($_GET['filter_type'] ?? null);
$filterDistQ = $filterDistQ ?? ($_GET['dist_q'] ?? null);
$itemTypeFilter = $itemTypeFilter ?? 'equipment';
$distributionType = $distributionType ?? ($_GET['document_type'] ?? 'ics');
$distributionSemiType = $distributionSemiType ?? ($_GET['semi_type'] ?? null);
$form = $form ?? ['system_reference' => '', 'document_no' => '', 'distribution_date' => date('Y-m-d'), 'office_id' => '', 'employee_id' => '', 'purpose' => '', 'remarks' => ''];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                                <!-- SPA: Step 1 + Split panel editor -->
                                <div class="card mb-3">
                                    <div class="card-body p-3">
                                        <div class="small fw-semibold text-muted mb-2">Select document type</div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a href="?document_type=par" class="btn btn-sm <?php echo $distributionType==='par' ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                                                PAR
                                                <span class="d-block" style="font-size:10px;font-weight:400;">Equipment ≥ ₱<?php echo number_format($equipmentMin,0,'.',','); ?></span>
                                            </a>
                                            <a href="?document_type=ics&semi_type=high_value" class="btn btn-sm <?php echo ($distributionType==='ics' && $distributionSemiType==='high_value') ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                                ICS – High Value
                                                <span class="d-block" style="font-size:10px;font-weight:400;">₱<?php echo number_format($semiHvMin+0.01,2,'.',','); ?> – ₱<?php echo number_format($equipmentMin-0.01,2,'.',','); ?></span>
                                            </a>
                                            <a href="?document_type=ics&semi_type=low_value" class="btn btn-sm <?php echo ($distributionType==='ics' && $distributionSemiType==='low_value') ? 'btn-warning' : 'btn-outline-secondary'; ?>">
                                                ICS – Low Value
                                                <span class="d-block" style="font-size:10px;font-weight:400;">₱<?php echo number_format($semiHvMin,2,'.',','); ?> and below</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-lg-4">
                                        <div class="card h-100">
                                            <div class="card-body p-3">
                                                <div class="small fw-semibold text-muted mb-2">Select receiving record (IAR)</div>
                                                <input type="text" id="iarSearchInput" class="form-control form-control-sm mb-2" placeholder="Search PO or supplier...">
                                                <div id="iarListScroll" style="max-height:480px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
                                                    <?php foreach ($iarList as $iar): $unitCount = (int)($iar['available_units'] ?? 0); ?>
                                                        <div class="iar-list-row" data-iar-id="<?= (int)$iar['id'] ?>" style="padding:8px 10px;border-radius:6px;cursor:pointer;border:0.5px solid transparent;">
                                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                                <span class="fw-semibold" style="font-size:12px;flex:1;"><?= h($iar['po_number']) ?></span>
                                                                <span class="badge text-bg-secondary" style="font-size:9px;"><?= $unitCount ?> unit<?= $unitCount!==1?'s':'' ?></span>
                                                            </div>
                                                            <div style="font-size:11px;color:var(--bs-secondary-color);overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                                                                <?= h($iar['supplier_name']) ?> · <?= h(date('M d, Y', strtotime($iar['received_date']))) ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (empty($iarList)): ?>
                                                        <div class="text-center text-muted py-4" style="font-size:12px;">No receiving records with available <?= $distributionType==='par' ? 'equipment' : 'semi-expendable' ?> units.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-lg-8">
                                        <div id="distEditorEmpty" class="card h-100">
                                            <div class="card-body d-flex align-items-center justify-content-center text-muted py-5">
                                                <div class="text-center">
                                                    <div class="mb-1">Select a receiving record from the list</div>
                                                    <div style="font-size:12px;">Units available for distribution will appear here</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="distEditorContent" style="display:none;">
                                            <form method="post" id="distributionForm">
                                                <input type="hidden" name="document_type" value="<?= h($distributionType) ?>">
                                                <input type="hidden" name="semi_type" value="<?= h($distributionSemiType) ?>">
                                                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                                <input type="hidden" name="receiving_id" id="hiddenReceivingId" value="">

                                                <div id="distIarSummary" class="mb-3 p-2 rounded-3" style="background:var(--bs-secondary-bg);font-size:12px;"></div>

                                                <div class="card mb-3">
                                                    <div class="card-body p-3">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <div class="small fw-semibold text-muted">Units to distribute</div>
                                                            <label class="small" style="cursor:pointer;"><input type="checkbox" id="selectAllUnits" class="me-1"> Select all</label>
                                                        </div>
                                                        <div id="distUnitsContainer"></div>
                                                        <div id="distUnitsLoading" class="text-center text-muted py-3" style="font-size:12px;display:none;">Loading units...</div>
                                                    </div>
                                                </div>

                                                <div class="card">
                                                    <div class="card-body p-3">
                                                        <div class="small fw-semibold text-muted mb-3">Assign to office</div>
                                                        <div class="row g-3 mb-3">
                                                            <div class="col-md-6">
                                                                <label class="form-label">Office *</label>
                                                                <select class="form-select" id="office_id" name="office_id" required data-placeholder="Select office">
                                                                    <option value="">Select office</option>
                                                                    <?php foreach ($offices as $office): ?>
                                                                        <option value="<?= (int)$office['id'] ?>"><?= h($office['office_name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Accountable Employee</label>
                                                                <select class="form-select" id="employee_id" name="employee_id" data-placeholder="Select employee">
                                                                    <option value="">Select employee</option>
                                                                    <?php foreach ($employees as $emp): ?>
                                                                        <option value="<?= (int)$emp['id'] ?>" data-office-id="<?= (int)($emp['office_id']??0) ?>"><?= h(employee_display_name($emp) . ' - ' . $emp['employee_no']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">Distribution Date *</label>
                                                                <input type="date" class="form-control" id="distribution_date" name="distribution_date" value="<?= h($form['distribution_date']) ?>" required>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label">System Reference</label>
                                                                <input type="text" class="form-control" value="<?= h($form['system_reference']) ?>" readonly>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <label class="form-label"><?= h(distribution_doc_label($distributionType)) ?> Number</label>
                                                                <input type="text" class="form-control" value="<?= h($form['document_no']) ?>" readonly>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Purpose</label>
                                                                <textarea class="form-control" name="purpose" rows="2"><?= h($form['purpose']) ?></textarea>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label class="form-label">Remarks</label>
                                                                <textarea class="form-control" name="remarks" rows="2"><?= h($form['remarks']) ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between">
                                                            <div class="small text-muted"><span id="selectedUnitCount">0</span> unit(s) selected · Total: <strong id="distTotal">₱0.00</strong></div>
                                                            <button type="submit" class="btn btn-primary" id="postDistBtn" disabled>Post <?= h(distribution_doc_label($distributionType)) ?></button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <h5 class="card-title mb-0">Posted Distributions</h5>
                    <span class="badge text-bg-light"><?php echo count($distributions); ?> record(s)</span>
                </div>

                <form method="get" class="row g-2 align-items-center mb-3">
                    <input type="hidden" name="document_type" value="<?php echo h($distributionType); ?>">
                    <div class="col-auto">
                        <select name="filter_type" class="form-select form-select-sm">
                            <option value="">All types</option>
                            <option value="ics" <?php echo (isset($filterDistType) && $filterDistType === 'ics') ? 'selected' : ''; ?>>ICS</option>
                            <option value="par" <?php echo (isset($filterDistType) && $filterDistType === 'par') ? 'selected' : ''; ?>>PAR</option>
                        </select>
                    </div>
                    <div class="col">
                        <input type="search" name="dist_q" class="form-control form-control-sm" placeholder="Search reference, document no, office, employee..." value="<?php echo h($filterDistQ ?? ''); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        <a href="modules/distributions/index.php?document_type=<?php echo h($distributionType); ?>" class="btn btn-sm btn-link">Clear</a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Document No.</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Office</th>
                                <th>Employee</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($distributions): ?>
                                <?php foreach ($distributions as $distribution): ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo h($distribution['system_reference']); ?></td>
                                        <td><?php echo h($distribution['document_no']); ?></td>
                                        <td><?php echo h(date('M d, Y', strtotime($distribution['distribution_date']))); ?></td>
                                        <td><?php echo h(strtoupper($distribution['document_type'])); ?></td>
                                        <td><?php echo h($distribution['office_name']); ?></td>
                                        <td><?php echo $distribution['employee_no'] ? h(employee_display_name($distribution)) . ' - ' . h($distribution['employee_no']) : '<span class="text-muted">Not specified</span>'; ?></td>
                                        <td><span class="badge text-bg-light text-uppercase"><?php echo h($distribution['status']); ?></span></td>
                                        <td class="text-end">
                                            <?php if (($distribution['document_type'] ?? '') === 'par'): ?>
                                                <a href="<?php echo base_url('modules/distributions/par.php?id=' . (int)$distribution['id']); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">Print PAR</a>
                                            <?php else: ?>
                                                <a href="<?php echo base_url('modules/distributions/ics.php?id=' . (int)$distribution['id']); ?>" class="btn btn-sm btn-outline-primary me-1" target="_blank">Print ICS</a>
                                            <?php endif; ?>
                                            <a href="<?php echo base_url('modules/property/tags.php?distribution_id=' . (int)$distribution['id']); ?>" class="btn btn-outline-secondary btn-sm me-1" target="_blank">QR Tags</a>
                                            <!-- "View / Print" removed: Print PAR/ICS and QR Tags are sufficient -->
                                        </td>
                                        <td class="text-end"><?php echo h(number_format((float) $distribution['total_amount'], 2)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">No distributions posted yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var officeSelect = document.getElementById('office_id');
    var employeeSelect = document.getElementById('employee_id');

    function filterEmployees() {
        if (!officeSelect || !employeeSelect) return;
        var selectedOffice = officeSelect.value;
        Array.prototype.forEach.call(employeeSelect.options, function (option) {
            if (!option.value) {
                option.hidden = false;
                return;
            }
            var matches = !selectedOffice || option.getAttribute('data-office-id') === selectedOffice;
            option.hidden = !matches;
            if (!matches && option.selected) {
                employeeSelect.value = '';
            }
        });
        if (window.SPAMS && window.SPAMS.refreshSelect2) {
            window.SPAMS.refreshSelect2(employeeSelect);
        }
    }

    if (officeSelect) {
        officeSelect.addEventListener('change', filterEmployees);
        if (window.jQuery) {
            window.jQuery(officeSelect).on('select2:select select2:clear', filterEmployees);
        }
        filterEmployees();
    }

    // Select-All Units checkbox for distributions unit rows
    var selectAll = document.getElementById('selectAllUnits');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = this.checked;
            document.querySelectorAll('input[name^="units["]').forEach(function (cb) {
                cb.checked = checked;
            });
        });
    }

    // Per-item Select-All: toggle unit checkboxes for the candidate that owns the header
    document.querySelectorAll('.select-all-units').forEach(function(cb) {
        cb.addEventListener('change', function () {
            var row = cb.closest('tr');
            var sibling = row;
            while ((sibling = sibling.nextElementSibling)) {
                var unitCb = sibling.querySelector('.unit-checkbox');
                if (!unitCb) break;
                unitCb.checked = cb.checked;
            }
        });
    });
});
// SPA: bind IAR list and AJAX unit loading
document.addEventListener('DOMContentLoaded', function () {
        var iarRows = document.querySelectorAll('.iar-list-row');
        var editorEmpty = document.getElementById('distEditorEmpty');
        var editorContent = document.getElementById('distEditorContent');
        var unitsContainer = document.getElementById('distUnitsContainer');
        var unitsLoading = document.getElementById('distUnitsLoading');
        var iarSummary = document.getElementById('distIarSummary');
        var hiddenRid = document.getElementById('hiddenReceivingId');
        var postBtn = document.getElementById('postDistBtn');
        var countLabel = document.getElementById('selectedUnitCount');
        var totalLabel = document.getElementById('distTotal');
        var itemType = '<?= h($itemTypeFilter) ?>';

        function updateTotal() {
                var checked = document.querySelectorAll('.unit-checkbox:checked');
                var total = 0;
                checked.forEach(function(cb) { total += parseFloat(cb.dataset.cost || 0); });
                countLabel.textContent = checked.length;
                totalLabel.textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2, maximumFractionDigits:2});
                if (postBtn) postBtn.disabled = checked.length === 0;
        }

        function loadUnits(iarId) {
                if (!hiddenRid) return;
                hiddenRid.value = iarId;
                unitsContainer.innerHTML = '';
                unitsLoading.style.display = 'block';
                editorEmpty.style.display = 'none';
                editorContent.style.display = '';

                fetch('units_preview.php?receiving_id=' + iarId + '&item_type=' + encodeURIComponent(itemType))
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        unitsLoading.style.display = 'none';
                        if (!data.ok) { unitsContainer.innerHTML = '<div class="text-danger small py-2">Failed to load units.</div>'; return; }
                        unitsContainer.innerHTML = data.html;
                        var h = data.header || {};
                        iarSummary.innerHTML = '<span class="fw-semibold">' + (h.system_reference||'') + '</span>' +
                            '<span class="text-muted ms-2">' + (h.po_number||'') + '</span>' +
                            '<span class="text-muted ms-2">' + (h.supplier_name||'') + '</span>';
                        document.querySelectorAll('.unit-checkbox').forEach(function(cb){ cb.addEventListener('change', updateTotal); });
                        updateTotal();
                }).catch(function(){ unitsLoading.style.display = 'none'; unitsContainer.innerHTML = '<div class="text-danger small py-2">Network error.</div>'; });
        }

        iarRows.forEach(function(row){ row.addEventListener('click', function(){ iarRows.forEach(function(r){ r.style.background=''; r.style.borderColor='transparent'; }); row.style.background='var(--bs-primary-bg-subtle)'; row.style.borderColor='var(--bs-primary-border-subtle)'; loadUnits(row.dataset.iarId); }); });

        var selectAllBtn = document.getElementById('selectAllUnits');
        if (selectAllBtn) {
            selectAllBtn.addEventListener('change', function(){ document.querySelectorAll('.unit-checkbox').forEach(function(cb){ cb.checked = selectAllBtn.checked; }); updateTotal(); });
        }

        var iarSearch = document.getElementById('iarSearchInput');
        if (iarSearch) {
            iarSearch.addEventListener('input', function(){ var q = this.value.trim().toLowerCase(); document.querySelectorAll('.iar-list-row').forEach(function(r){ r.style.display = (!q || r.textContent.toLowerCase().includes(q)) ? '' : 'none'; }); });
        }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
