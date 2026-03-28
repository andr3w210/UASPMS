<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

header('Content-Type: application/json; charset=utf-8');

$db = db_connect();
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to connect to the database.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!csrf_verify()) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$itemName = trim((string) ($_POST['item_name'] ?? ''));
$itemDescription = trim((string) ($_POST['item_description'] ?? ''));
$itemType = trim((string) ($_POST['item_type'] ?? 'supply'));
$accountCodeId = (int) ($_POST['account_code_id'] ?? 0);
$classificationId = (int) ($_POST['classification_id'] ?? 0);
$unitOfMeasureId = (int) ($_POST['unit_of_measure_id'] ?? 0);

if ($itemName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Item name is required.']);
    exit;
}

if (!in_array($itemType, ['supply', 'semi_expendable', 'equipment'], true)) {
    $itemType = 'supply';
}

if ($accountCodeId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Account code is required.']);
    exit;
}

$accountStmt = $db->prepare("SELECT id, account_code, account_name, account_group FROM account_codes WHERE id = ? AND is_active = 1 LIMIT 1");
if (!$accountStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to validate account code.']);
    exit;
}
$accountStmt->bind_param('i', $accountCodeId);
$accountStmt->execute();
$accountRow = $accountStmt->get_result()->fetch_assoc();
$accountStmt->close();

if (!$accountRow) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selected account code was not found.']);
    exit;
}

$expectedGroup = $itemType === 'equipment' ? 'asset' : $itemType;
if (($accountRow['account_group'] ?? '') !== $expectedGroup) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Selected account code does not match the item type.']);
    exit;
}

if ($classificationId > 0) {
    $classificationStmt = $db->prepare("SELECT id, classification_name, classification_group FROM classifications WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$classificationStmt) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to validate classification.']);
        exit;
    }
    $classificationStmt->bind_param('i', $classificationId);
    $classificationStmt->execute();
    $classificationRow = $classificationStmt->get_result()->fetch_assoc();
    $classificationStmt->close();

    if (!$classificationRow) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Selected classification was not found.']);
        exit;
    }

    if (($classificationRow['classification_group'] ?? '') !== $expectedGroup) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Selected classification does not match the item type.']);
        exit;
    }
}

$duplicateStmt = $db->prepare("
    SELECT id
    FROM stock_catalog
    WHERE account_code_id = ?
      AND item_name = ?
      AND COALESCE(item_description, '') = COALESCE(?, '')
    LIMIT 1
");
if (!$duplicateStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to validate duplicate items.']);
    exit;
}
$duplicateStmt->bind_param('iss', $accountCodeId, $itemName, $itemDescription);
$duplicateStmt->execute();
$duplicateRow = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();

if ($duplicateRow) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'A matching catalog item already exists.']);
    exit;
}

$stockNo = stock_catalog_next_number($db, $classificationId > 0 ? $classificationId : null, $itemName, $itemDescription);
if ($stockNo === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to generate stock number.']);
    exit;
}

$userId = current_user_id();
$insertStmt = $db->prepare("
    INSERT INTO stock_catalog
    (stock_no, item_name, item_description, item_type, classification_id, account_code_id, unit_of_measure_id, is_active, created_by)
    VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, NULLIF(?, 0), 1, ?)
");
if (!$insertStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save catalog item.']);
    exit;
}
$insertStmt->bind_param(
    'ssssiiii',
    $stockNo,
    $itemName,
    $itemDescription,
    $itemType,
    $classificationId,
    $accountCodeId,
    $unitOfMeasureId,
    $userId
);
$insertStmt->execute();
$newId = (int) $insertStmt->insert_id;
$insertStmt->close();

$itemStmt = $db->prepare("
    SELECT sc.id, sc.stock_no, sc.item_name, sc.item_description,
           sc.item_type, sc.account_code_id, sc.classification_id,
           sc.unit_of_measure_id,
           ac.account_code, ac.account_name,
           c.classification_name,
           u.abbreviation AS uom_abbr
    FROM stock_catalog sc
    LEFT JOIN account_codes ac ON ac.id = sc.account_code_id
    LEFT JOIN classifications c ON c.id = sc.classification_id
    LEFT JOIN unit_of_measures u ON u.id = sc.unit_of_measure_id
    WHERE sc.id = ?
    LIMIT 1
");
if (!$itemStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Catalog item was saved but could not be reloaded.']);
    exit;
}
$itemStmt->bind_param('i', $newId);
$itemStmt->execute();
$item = $itemStmt->get_result()->fetch_assoc();
$itemStmt->close();

echo json_encode(['ok' => true, 'item' => $item]);
exit;
