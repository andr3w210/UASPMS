<?php
/**
 * AJAX quick-add endpoint for legacy assets form.
 * Handles creation of classification, account_code, brand, model, supplier, office, employee.
 * Returns JSON: {success: bool, id: int, label: string} or {success: false, error: string}
 */
require_once __DIR__ . '/../../app/config/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function qa_json(bool $success, array $payload = []): never
{
    echo json_encode(array_merge(['success' => $success], $payload));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qa_json(false, ['error' => 'Method not allowed.']);
}

if (!csrf_verify()) {
    qa_json(false, ['error' => 'Invalid CSRF token.']);
}

$db = db();
if (!$db) {
    qa_json(false, ['error' => 'Database unavailable.']);
}

$action = trim((string) ($_POST['action'] ?? ''));
$userId = current_user_id();

switch ($action) {

    // ── Classification ────────────────────────────────────────────
    case 'add_classification': {
        $name = trim((string) ($_POST['classification_name'] ?? ''));
        $family = trim((string) ($_POST['classification_family'] ?? ''));
        $accountCodeId = (int) ($_POST['account_code_id'] ?? 0);
        if ($name === '') { qa_json(false, ['error' => 'Classification name is required.']); }
        if ($accountCodeId <= 0) {
            qa_json(false, ['error' => 'Select account code first before adding classification.']);
        }

        $group = '';
        $accountGroupStmt = $db->prepare('SELECT account_group FROM account_codes WHERE id = ? AND is_active = 1 LIMIT 1');
        if ($accountGroupStmt) {
            $accountGroupStmt->bind_param('i', $accountCodeId);
            $accountGroupStmt->execute();
            $accountGroupRow = $accountGroupStmt->get_result()->fetch_assoc();
            $accountGroupStmt->close();
            $group = trim((string) ($accountGroupRow['account_group'] ?? ''));
        }
        if (!in_array($group, ['asset', 'semi_expendable', 'supply'], true)) {
            qa_json(false, ['error' => 'Selected account code has invalid group mapping.']);
        }

        if ($group === 'supply') {
            qa_json(false, ['error' => 'Supply account codes are not allowed for legacy asset classifications.']);
        }

        $dup = $db->prepare('SELECT id, classification_family, account_code_id, is_active FROM classifications WHERE account_code_id = ? AND LOWER(TRIM(classification_name)) = LOWER(TRIM(?)) LIMIT 1');
        if ($dup) {
            $dup->bind_param('is', $accountCodeId, $name);
            $dup->execute();
            $existing = $dup->get_result()->fetch_assoc();
            if ($existing) {
                $dup->close();

                $existingId = (int) ($existing['id'] ?? 0);
                $existingFamily = trim((string) ($existing['classification_family'] ?? ''));
                $existingAccountCodeId = (int) ($existing['account_code_id'] ?? 0);
                $isActive = (int) ($existing['is_active'] ?? 0) === 1;

                if (!$isActive && $existingId > 0) {
                    $reactivateStmt = $db->prepare('UPDATE classifications SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
                    if ($reactivateStmt) {
                        $reactivateStmt->bind_param('ii', $userId, $existingId);
                        $reactivateStmt->execute();
                        $reactivateStmt->close();
                    }
                }

                $labelFamily = $existingFamily !== '' ? $existingFamily : $family;
                $label = $labelFamily !== '' ? $labelFamily . ' / ' . $name : $name;
                qa_json(true, [
                    'id' => $existingId,
                    'label' => $label,
                    'account_code_id' => $existingAccountCodeId,
                    'classification_group' => $group,
                    'reused' => true,
                ]);
            }
            $dup->close();
        }

        $classificationCode = next_module_code($db, 'classifications');
        $stmt = $db->prepare('INSERT INTO classifications (classification_code, classification_name, classification_family, classification_group, account_code_id, is_active, created_by) VALUES (?, ?, NULLIF(?,\'\'), ?, ?, 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssssii', $classificationCode, $name, $family, $group, $accountCodeId, $userId);
        $saved = $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        if (!$saved || $id <= 0) {
            qa_json(false, ['error' => 'Unable to save classification right now.']);
        }

        $label = $family !== '' ? $family . ' / ' . $name : $name;
        qa_json(true, [
            'id' => $id,
            'label' => $label,
            'account_code_id' => $accountCodeId,
            'classification_group' => $group,
        ]);
    }

    // ── Account Code ──────────────────────────────────────────────
    case 'add_account_code': {
        $code = trim((string) ($_POST['account_code'] ?? ''));
        $aname = trim((string) ($_POST['account_name'] ?? ''));
        if ($code === '') { qa_json(false, ['error' => 'Account code is required.']); }
        if ($aname === '') { qa_json(false, ['error' => 'Account name is required.']); }

        $dup = $db->prepare('SELECT id FROM account_codes WHERE account_code = ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('s', $code);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                qa_json(false, ['error' => 'Account code already exists.']);
            }
            $dup->close();
        }

        $stmt = $db->prepare('INSERT INTO account_codes (account_code, account_name, is_active, created_by) VALUES (?, ?, 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssi', $code, $aname, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        qa_json(true, ['id' => $id, 'label' => $code . ' - ' . $aname]);
    }

    // ── Brand ─────────────────────────────────────────────────────
    case 'add_brand': {
        $bname = trim((string) ($_POST['brand_name'] ?? ''));
        if ($bname === '') { qa_json(false, ['error' => 'Brand name is required.']); }

        $dup = $db->prepare('SELECT id FROM brands WHERE brand_name = ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('s', $bname);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                qa_json(false, ['error' => 'Brand already exists.']);
            }
            $dup->close();
        }

        $brandCode = next_module_code($db, 'brands');
        $stmt = $db->prepare('INSERT INTO brands (brand_code, brand_name, is_active, created_by) VALUES (?, ?, 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssi', $brandCode, $bname, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        qa_json(true, ['id' => $id, 'label' => $bname, 'brand_id' => $id, 'code' => $brandCode]);
    }

    // ── Model ─────────────────────────────────────────────────────
    case 'add_model': {
        $mname   = trim((string) ($_POST['model_name'] ?? ''));
        $brandId = (int) ($_POST['brand_id'] ?? 0);
        if ($mname === '') { qa_json(false, ['error' => 'Model name is required.']); }
        if ($brandId <= 0) { qa_json(false, ['error' => 'Brand is required for a model.']); }

        $dup = $db->prepare('SELECT id FROM models WHERE model_name = ? AND brand_id = ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('si', $mname, $brandId);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                qa_json(false, ['error' => 'This model already exists for the selected brand.']);
            }
            $dup->close();
        }

        $modelCode = next_module_code($db, 'models');
        $stmt = $db->prepare('INSERT INTO models (model_code, model_name, brand_id, is_active, created_by) VALUES (?, ?, ?, 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssii', $modelCode, $mname, $brandId, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        qa_json(true, ['id' => $id, 'label' => $mname, 'brand_id' => $brandId, 'code' => $modelCode]);
    }

    // ── Office ────────────────────────────────────────────────────
    case 'add_supplier': {
        $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
        if ($supplierName === '') { qa_json(false, ['error' => 'Supplier name is required.']); }

        $dup = $db->prepare('SELECT id, supplier_name, is_active FROM suppliers WHERE LOWER(TRIM(supplier_name)) = LOWER(TRIM(?)) LIMIT 1');
        if ($dup) {
            $dup->bind_param('s', $supplierName);
            $dup->execute();
            $existing = $dup->get_result()->fetch_assoc();
            $dup->close();
            if ($existing) {
                $existingId = (int) ($existing['id'] ?? 0);
                $isActive = (int) ($existing['is_active'] ?? 0) === 1;
                if (!$isActive && $existingId > 0) {
                    $reactivateStmt = $db->prepare('UPDATE suppliers SET is_active = 1, updated_by = ?, updated_at = NOW() WHERE id = ? LIMIT 1');
                    if ($reactivateStmt) {
                        $reactivateStmt->bind_param('ii', $userId, $existingId);
                        $reactivateStmt->execute();
                        $reactivateStmt->close();
                    }
                }
                $existingName = trim((string) ($existing['supplier_name'] ?? ''));
                qa_json(true, ['id' => $existingId, 'label' => ($existingName !== '' ? $existingName : $supplierName), 'reused' => true]);
            }
        }

        $supplierCode = next_module_code($db, 'suppliers');
        $stmt = $db->prepare('INSERT INTO suppliers (supplier_code, supplier_name, is_active, created_by) VALUES (?, ?, 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssi', $supplierCode, $supplierName, $userId);
        $saved = $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        if (!$saved || $id <= 0) {
            qa_json(false, ['error' => 'Unable to save supplier right now.']);
        }

        qa_json(true, ['id' => $id, 'label' => $supplierName, 'code' => $supplierCode]);
    }

    // ── Office ────────────────────────────────────────────────────
    case 'add_office': {
        $code  = strtoupper(trim((string) ($_POST['office_code'] ?? '')));
        $oname = trim((string) ($_POST['office_name'] ?? ''));
        if ($code === '') { qa_json(false, ['error' => 'Office code is required.']); }
        if ($oname === '') { qa_json(false, ['error' => 'Office name is required.']); }

        $dup = $db->prepare('SELECT id FROM offices WHERE office_code = ? OR office_name = ? LIMIT 1');
        if ($dup) {
            $dup->bind_param('ss', $code, $oname);
            $dup->execute();
            if ($dup->get_result()->fetch_assoc()) {
                $dup->close();
                qa_json(false, ['error' => 'Office code or name already exists.']);
            }
            $dup->close();
        }

        $stmt = $db->prepare('INSERT INTO offices (office_code, office_name, department_id, office_head_employee_id, description, is_active, created_by) VALUES (?, ?, NULL, NULL, \'\', 1, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $stmt->bind_param('ssi', $code, $oname, $userId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        qa_json(true, ['id' => $id, 'label' => $oname]);
    }

    // ── Employee ──────────────────────────────────────────────────
    case 'add_employee': {
        $firstName  = trim((string) ($_POST['first_name'] ?? ''));
        $middleName = trim((string) ($_POST['middle_name'] ?? ''));
        $lastName   = trim((string) ($_POST['last_name'] ?? ''));
        $position   = trim((string) ($_POST['position_title'] ?? ''));
        $officeId   = (int) ($_POST['office_id'] ?? 0);
        if ($firstName === '') { qa_json(false, ['error' => 'First name is required.']); }
        if ($lastName === '')  { qa_json(false, ['error' => 'Last name is required.']); }

        $officeIdParam = $officeId > 0 ? $officeId : null;
        $empty = '';
        $isUnitHead = 0;
        $isActive = 1;

        $stmt = $db->prepare('INSERT INTO employees (employee_no, first_name, middle_name, last_name, suffix_name, email, photo_path, department_id, office_id, responsibility_code_id, position_title, employment_status, is_unit_head, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) { qa_json(false, ['error' => 'Failed to prepare insert.']); }
        $nullRc = null;
        $empStatus = '';
        $stmt->bind_param(
            'sssssssiiissiii',
            $empty,       // employee_no
            $firstName,
            $middleName,
            $lastName,
            $empty,       // suffix_name
            $empty,       // email
            $empty,       // photo_path
            $officeIdParam,
            $nullRc,      // responsibility_code_id
            $position,
            $empStatus,   // employment_status
            $isUnitHead,
            $isActive,
            $userId
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        $parts = array_filter([$firstName, $middleName !== '' ? $middleName[0] . '.' : '', $lastName]);
        $label = implode(' ', $parts);

        qa_json(true, ['id' => $id, 'label' => $label, 'office_id' => $officeId]);
    }

    default:
        qa_json(false, ['error' => 'Unknown action.']);
}
