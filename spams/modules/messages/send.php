<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = db_connect();
$currentUserId = current_user_id() ?? 0;

if (!$db || $currentUserId <= 0) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to send the message right now.',
    ]);
    exit;
}

ensure_messaging_infrastructure($db);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid request method.',
    ]);
    exit;
}

if (!csrf_verify()) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid CSRF token.',
    ]);
    exit;
}

$recipientRaw = trim((string) ($_POST['recipient_user_id'] ?? ''));
$recipientUserId = (int) $recipientRaw;
$channelKey = trim((string) ($_POST['channel_key'] ?? ''));
$subject = trim((string) ($_POST['subject'] ?? ''));
$messageBody = trim((string) ($_POST['message_body'] ?? ''));
$relatedTable = trim((string) ($_POST['related_table'] ?? ''));
$relatedId = (int) ($_POST['related_id'] ?? 0);
$allowedRelatedTables = ['purchase_orders', 'receivings', 'distributions'];

if (!in_array($relatedTable, $allowedRelatedTables, true)) {
    $relatedTable = '';
    $relatedId = 0;
}

if ($messageBody === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Message body is required.',
    ]);
    exit;
}

if ($channelKey === 'general') {
    $insertStmt = $db->prepare("
        INSERT INTO channel_messages
            (channel_key, sender_user_id, subject, message_body, related_table, related_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$insertStmt) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to send the group message.',
        ]);
        exit;
    }

    $relatedIdParam = $relatedId > 0 ? $relatedId : null;
    $relatedTableParam = $relatedTable !== '' ? $relatedTable : null;
    $insertStmt->bind_param('sisssi', $channelKey, $currentUserId, $subject, $messageBody, $relatedTableParam, $relatedIdParam);
    $insertStmt->execute();
    $messageId = (int) $insertStmt->insert_id;
    $insertStmt->close();

    message_mark_channel_read($db, $channelKey, $currentUserId);

    echo json_encode([
        'ok' => true,
        'message' => 'Group message sent successfully.',
        'message_id' => $messageId,
        'conversation_url' => base_url(
            'modules/messages/index.php?channel=general'
            . ($relatedTable !== '' && $relatedId > 0 ? '&related_table=' . urlencode($relatedTable) . '&related_id=' . $relatedId : '')
        ),
    ]);
    exit;
}

if ($recipientRaw === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Select a recipient.',
    ]);
    exit;
}

if ($recipientUserId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Select a valid recipient.',
    ]);
    exit;
}

if ($recipientUserId === $currentUserId) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'You cannot send a message to yourself.',
    ]);
    exit;
}

$recipientCheckStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
if ($recipientCheckStmt) {
    $recipientCheckStmt->bind_param('i', $recipientUserId);
    $recipientCheckStmt->execute();
    $recipientExists = (bool) $recipientCheckStmt->get_result()->fetch_assoc();
    $recipientCheckStmt->close();

    if (!$recipientExists) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Selected recipient is no longer available.',
        ]);
        exit;
    }
}

$insertStmt = $db->prepare("
    INSERT INTO user_messages
        (sender_user_id, recipient_user_id, subject, message_body, related_table, related_id)
    VALUES (?, ?, ?, ?, ?, ?)
");

if (!$insertStmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to send the message.',
    ]);
    exit;
}

$relatedIdParam = $relatedId > 0 ? $relatedId : null;
$relatedTableParam = $relatedTable !== '' ? $relatedTable : null;
$insertStmt->bind_param('iisssi', $currentUserId, $recipientUserId, $subject, $messageBody, $relatedTableParam, $relatedIdParam);
$insertStmt->execute();
$messageId = (int) $insertStmt->insert_id;
$insertStmt->close();

echo json_encode([
    'ok' => true,
    'message' => 'Message sent successfully.',
    'message_id' => $messageId,
    'conversation_url' => base_url(
        'modules/messages/index.php?user=' . $recipientUserId
        . ($relatedTable !== '' && $relatedId > 0 ? '&related_table=' . urlencode($relatedTable) . '&related_id=' . $relatedId : '')
    ),
]);
