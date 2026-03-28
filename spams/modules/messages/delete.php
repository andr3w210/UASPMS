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
        'message' => 'Unable to update the message right now.',
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

$messageId = (int) ($_POST['message_id'] ?? 0);
$channelMessageId = (int) ($_POST['channel_message_id'] ?? 0);
if ($channelMessageId > 0) {
    $channelStmt = $db->prepare("
        SELECT id
        FROM channel_messages
        WHERE id = ?
        LIMIT 1
    ");
    if (!$channelStmt) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to update the message.',
        ]);
        exit;
    }

    $channelStmt->bind_param('i', $channelMessageId);
    $channelStmt->execute();
    $channelMessage = $channelStmt->get_result()->fetch_assoc();
    $channelStmt->close();

    if (!$channelMessage) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'message' => 'Message not found.',
        ]);
        exit;
    }

    if (!message_hide_channel_message($db, $channelMessageId, $currentUserId)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to update the message.',
        ]);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Message removed from your view.',
    ]);
    exit;
}

if ($messageId <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Invalid message.',
    ]);
    exit;
}

$messageStmt = $db->prepare("
    SELECT id, sender_user_id, recipient_user_id
    FROM user_messages
    WHERE id = ?
    LIMIT 1
");
if (!$messageStmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to update the message.',
    ]);
    exit;
}

$messageStmt->bind_param('i', $messageId);
$messageStmt->execute();
$message = $messageStmt->get_result()->fetch_assoc();
$messageStmt->close();

if (!$message) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'message' => 'Message not found.',
    ]);
    exit;
}

$isSender = (int) $message['sender_user_id'] === $currentUserId;
$isRecipient = (int) $message['recipient_user_id'] === $currentUserId;

if (!$isSender && !$isRecipient) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'You do not have access to this message.',
    ]);
    exit;
}

if ($isSender) {
    $updateStmt = $db->prepare("UPDATE user_messages SET hidden_for_sender = 1 WHERE id = ?");
} else {
    $updateStmt = $db->prepare("UPDATE user_messages SET hidden_for_recipient = 1 WHERE id = ?");
}

if (!$updateStmt) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to update the message.',
    ]);
    exit;
}

$updateStmt->bind_param('i', $messageId);
$updateStmt->execute();
$updateStmt->close();

echo json_encode([
    'ok' => true,
    'message' => 'Message removed from your view.',
]);
