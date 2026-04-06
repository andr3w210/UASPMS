<?php
require_once __DIR__ . '/../../app/config/init.php';

require_role('Administrator', 'Supply Officer');

header('Content-Type: application/json; charset=utf-8');

$db = db();
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

$itemType = trim((string) ($_POST['item_type'] ?? 'supply'));
$classificationName = trim((string) ($_POST['classification_name'] ?? ''));
$classificationFamily = trim((string) ($_POST['classification_family'] ?? ''));
$accountCodeId = (int) ($_POST['account_code_id'] ?? 0);
$usefulLifeYears = trim((string) ($_POST['useful_life_years'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));

if (!in_array($itemType, ['supply', 'semi_expendable', 'equipment'], true)) {
    $itemType = 'supply';
}

if ($classificationName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Classification name is required.']);
    exit;
}

$classificationGroup = $itemType === 'equipment' ? 'asset' : $itemType;
$usefulLife = $usefulLifeYears !== '' ? (int) $usefulLifeYears : null;

if ($usefulLife !== null && $usefulLife < 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Useful life must be zero or greater.']);
    exit;
}

if ($accountCodeId > 0) {
    $accountStmt = $db->prepare("SELECT id, account_group FROM account_codes WHERE id = ? AND is_active = 1 LIMIT 1");
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

    $expectedGroup = $classificationGroup === 'asset' ? 'asset' : $classificationGroup;
    if (($accountRow['account_group'] ?? '') !== $expectedGroup) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Selected account code does not match the item type.']);
        exit;
    }
}

$duplicateStmt = $db->prepare("SELECT id, classification_name, classification_family, classification_group, account_code_id, useful_life_years FROM classifications WHERE classification_name = ? AND classification_group = ? LIMIT 1");
if (!$duplicateStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to validate classification duplicates.']);
    exit;
}
$duplicateStmt->bind_param('ss', $classificationName, $classificationGroup);
$duplicateStmt->execute();
$existing = $duplicateStmt->get_result()->fetch_assoc();
$duplicateStmt->close();

if ($existing) {
    echo json_encode([
        'ok' => true,
        'classification' => [
            'id' => (int) $existing['id'],
            'classification_name' => $existing['classification_name'],
            'classification_family' => $existing['classification_family'] ?? '',
            'classification_group' => $existing['classification_group'],
            'account_code_id' => $existing['account_code_id'],
            'useful_life_years' => $existing['useful_life_years'],
        ],
        'existing' => true,
    ]);
    exit;
}

$code = next_module_code($db, 'classifications');
$userId = current_user_id();
$insertStmt = $db->prepare("
    INSERT INTO classifications
    (classification_code, system_reference, classification_name, classification_family, classification_group, useful_life_years, account_code_id, description, is_active, created_by)
    VALUES (?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, 0), ?, 1, ?)
");
if (!$insertStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save classification.']);
    exit;
}
$insertStmt->bind_param(
    'ssssssisi',
    $code,
    $code,
    $classificationName,
    $classificationFamily,
    $classificationGroup,
    $usefulLifeYears,
    $accountCodeId,
    $description,
    $userId
);
$insertStmt->execute();
$newId = (int) $insertStmt->insert_id;
$insertStmt->close();

$classificationStmt = $db->prepare("
    SELECT id, classification_name, classification_family, classification_group, account_code_id, useful_life_years
    FROM classifications
    WHERE id = ?
    LIMIT 1
");
if (!$classificationStmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Classification was saved but could not be reloaded.']);
    exit;
}
$classificationStmt->bind_param('i', $newId);
$classificationStmt->execute();
$classification = $classificationStmt->get_result()->fetch_assoc();
$classificationStmt->close();

echo json_encode([
    'ok' => true,
    'classification' => $classification,
    'existing' => false,
]);
exit;
