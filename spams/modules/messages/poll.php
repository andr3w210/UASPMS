<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$db = db();
$currentUserId = current_user_id() ?? 0;

if (!$db || $currentUserId <= 0) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => 'Unable to load message updates.',
    ]);
    exit;
}

$presenceStmt = $db->prepare("
    INSERT INTO user_presence (user_id, last_seen_at)
    VALUES (?, NOW())
    ON DUPLICATE KEY UPDATE last_seen_at = NOW()
");
if ($presenceStmt) {
    $presenceStmt->bind_param('i', $currentUserId);
    $presenceStmt->execute();
    $presenceStmt->close();
}

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
$limit = max(1, min($limit, 100));
$threadUserId = isset($_GET['thread_user']) ? (int) $_GET['thread_user'] : 0;
$threadChannel = trim((string) ($_GET['thread_channel'] ?? ''));
$relatedTable = trim((string) ($_GET['related_table'] ?? ''));
$relatedId = (int) ($_GET['related_id'] ?? 0);
$allowedRelatedTables = ['purchase_orders', 'receivings', 'distributions'];
if (!in_array($relatedTable, $allowedRelatedTables, true)) {
    $relatedTable = '';
    $relatedId = 0;
}
if ($threadChannel !== 'general') {
    $threadChannel = '';
}

$onlineUserMap = [];
$availableUsers = [];
$conversations = [];
$thread = null;
$contextSqlFilter = '';
if ($relatedTable !== '' && $relatedId > 0) {
    $contextSqlFilter = " AND related_table = '" . $db->real_escape_string($relatedTable) . "' AND related_id = " . $relatedId . " ";
}

$onlineUsersResult = $db->query("
    SELECT user_id
    FROM user_presence
    WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
");
if ($onlineUsersResult instanceof mysqli_result) {
    while ($onlineRow = $onlineUsersResult->fetch_assoc()) {
        $onlineUserMap[(int) $onlineRow['user_id']] = true;
    }
    $onlineUsersResult->free();
}

$availableUsersStmt = $db->prepare("
    SELECT id, username, full_name
    FROM users
    WHERE is_active = 1
      AND id != ?
    ORDER BY full_name ASC, username ASC
");
if ($availableUsersStmt) {
    $availableUsersStmt->bind_param('i', $currentUserId);
    $availableUsersStmt->execute();
    $availableRows = $availableUsersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $availableUsersStmt->close();

    foreach ($availableRows as $row) {
        $userId = (int) $row['id'];
        $availableUsers[] = [
            'id' => $userId,
            'display_name' => (string) ($row['full_name'] ?: $row['username']),
            'username' => (string) $row['username'],
            'online' => !empty($onlineUserMap[$userId]),
        ];
    }
}

$directUnreadCount = 0;
$unreadStmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM user_messages
    WHERE recipient_user_id = ?
      AND is_read = 0
");
if ($unreadStmt) {
    $unreadStmt->bind_param('i', $currentUserId);
    $unreadStmt->execute();
    $directUnreadCount = (int) (($unreadStmt->get_result()->fetch_assoc()['total'] ?? 0));
    $unreadStmt->close();
}

$generalUnreadCount = message_channel_unread_count($db, 'general', $currentUserId);

$generalChannelStmt = $db->prepare("
    SELECT cm.id, cm.subject, cm.message_body, cm.created_at
    FROM channel_messages cm
    LEFT JOIN message_channel_hidden mch
        ON mch.channel_message_id = cm.id
       AND mch.user_id = " . $currentUserId . "
    WHERE cm.channel_key = 'general'
      AND mch.channel_message_id IS NULL
    " . $contextSqlFilter . "
    ORDER BY cm.created_at DESC, cm.id DESC
    LIMIT 1
");
$generalPreview = '';
$generalLastAt = '';
if ($generalChannelStmt) {
    $generalChannelStmt->execute();
    $generalRow = $generalChannelStmt->get_result()->fetch_assoc();
    $generalChannelStmt->close();
    if ($generalRow) {
        $generalPreview = trim((string) ($generalRow['subject'] ?: $generalRow['message_body']));
        if (function_exists('mb_strlen') && mb_strlen($generalPreview) > 80) {
            $generalPreview = mb_substr($generalPreview, 0, 80) . '...';
        } elseif (strlen($generalPreview) > 80) {
            $generalPreview = substr($generalPreview, 0, 80) . '...';
        }
        $generalLastAt = (string) $generalRow['created_at'];
    }
}

$conversations[] = [
    'type' => 'channel',
    'channel_key' => 'general',
    'display_name' => 'General Group Chat',
    'preview' => $generalPreview !== '' ? $generalPreview : 'Shared team coordination and announcements.',
    'unread_count' => $generalUnreadCount,
    'online' => false,
    'last_message_at' => $generalLastAt,
    'last_message_label' => $generalLastAt !== '' ? date('M d', strtotime($generalLastAt)) : '',
    'url' => base_url(
        'modules/messages/index.php?channel=general'
        . ($relatedTable !== '' && $relatedId > 0 ? '&related_table=' . urlencode($relatedTable) . '&related_id=' . $relatedId : '')
    ),
];

$conversationSql = "
    SELECT
        last_message.id AS last_message_id,
        last_message.created_at AS last_message_at,
        last_message.message_body AS last_message_body,
        last_message.subject AS last_message_subject,
        last_message.related_table,
        last_message.related_id,
        conv.other_user_id,
        conv.unread_count,
        u.username,
        u.full_name
    FROM (
        SELECT
            CASE
                WHEN sender_user_id = ? THEN recipient_user_id
                ELSE sender_user_id
            END AS other_user_id,
            MAX(id) AS last_message_id,
            SUM(CASE WHEN recipient_user_id = ? AND is_read = 0 THEN 1 ELSE 0 END) AS unread_count
        FROM user_messages
        WHERE (sender_user_id = ?
           OR recipient_user_id = ?)
          AND (
                (sender_user_id = ? AND hidden_for_sender = 0)
             OR (recipient_user_id = ? AND hidden_for_recipient = 0)
          )
          " . $contextSqlFilter . "
        GROUP BY CASE
            WHEN sender_user_id = ? THEN recipient_user_id
            ELSE sender_user_id
        END
    ) conv
    INNER JOIN user_messages last_message ON last_message.id = conv.last_message_id
    INNER JOIN users u ON u.id = conv.other_user_id
    ORDER BY last_message.created_at DESC, last_message.id DESC
    LIMIT " . $limit;
$conversationStmt = $db->prepare($conversationSql);
if ($conversationStmt) {
    $conversationStmt->bind_param('iiiiiii', $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
    $conversationStmt->execute();
    $conversationRows = $conversationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $conversationStmt->close();

    foreach ($conversationRows as $row) {
        $preview = trim((string) ($row['last_message_subject'] ?: $row['last_message_body']));
        if (function_exists('mb_strlen')) {
            if (mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '...';
            }
        } elseif (strlen($preview) > 80) {
            $preview = substr($preview, 0, 80) . '...';
        }

        $conversations[] = [
            'type' => 'direct',
            'other_user_id' => (int) $row['other_user_id'],
            'display_name' => (string) ($row['full_name'] ?: $row['username']),
            'username' => (string) $row['username'],
            'preview' => $preview,
            'unread_count' => (int) $row['unread_count'],
            'online' => !empty($onlineUserMap[(int) $row['other_user_id']]),
            'related_table' => (string) ($row['related_table'] ?? ''),
            'related_id' => (int) ($row['related_id'] ?? 0),
            'last_message_at' => (string) $row['last_message_at'],
            'last_message_label' => date('M d', strtotime((string) $row['last_message_at'])),
            'url' => base_url(
                'modules/messages/index.php?user=' . (int) $row['other_user_id']
                . (!empty($row['related_table']) && !empty($row['related_id'])
                    ? '&related_table=' . urlencode((string) $row['related_table']) . '&related_id=' . (int) $row['related_id']
                    : '')
            ),
        ];
    }
}

if ($threadChannel === 'general') {
    message_mark_channel_read($db, 'general', $currentUserId);
    $messages = [];
    $lastMessageId = 0;
    $threadStmt = $db->prepare("
        SELECT
            cm.id,
            cm.sender_user_id,
            cm.subject,
            cm.message_body,
            cm.related_table,
            cm.related_id,
            cm.created_at,
            sender.full_name AS sender_full_name,
            sender.username AS sender_username
        FROM channel_messages cm
        INNER JOIN users sender ON sender.id = cm.sender_user_id
        LEFT JOIN message_channel_hidden mch
            ON mch.channel_message_id = cm.id
           AND mch.user_id = " . $currentUserId . "
        WHERE cm.channel_key = 'general'
          AND mch.channel_message_id IS NULL
          " . $contextSqlFilter . "
        ORDER BY cm.created_at ASC, cm.id ASC
    ");
    if ($threadStmt) {
        $threadStmt->execute();
        $threadRows = $threadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $threadStmt->close();

        foreach ($threadRows as $row) {
            $lastMessageId = max($lastMessageId, (int) $row['id']);
            $messages[] = [
                'id' => (int) $row['id'],
                'sender_user_id' => (int) $row['sender_user_id'],
                'sender_name' => (string) ($row['sender_full_name'] ?: $row['sender_username']),
                'subject' => (string) ($row['subject'] ?? ''),
                'message_body' => (string) $row['message_body'],
                'related_table' => (string) ($row['related_table'] ?? ''),
                'related_id' => (int) ($row['related_id'] ?? 0),
                'created_at' => (string) $row['created_at'],
                'created_label' => date('M d, Y g:i A', strtotime((string) $row['created_at'])),
                'is_mine' => (int) $row['sender_user_id'] === $currentUserId,
                'can_delete' => true,
            ];
        }
    }

    $thread = [
        'type' => 'channel',
        'channel_key' => 'general',
        'selected_user_id' => 0,
        'selected_user_name' => 'General Group Chat',
        'selected_user_online' => false,
        'messages' => $messages,
        'last_message_id' => $lastMessageId,
    ];
}

if ($threadUserId > 0) {
    $markReadStmt = $db->prepare("
        UPDATE user_messages
        SET is_read = 1,
            read_at = NOW()
        WHERE sender_user_id = ?
          AND recipient_user_id = ?
          AND is_read = 0
    ");
    if ($markReadStmt) {
        $markReadStmt->bind_param('ii', $threadUserId, $currentUserId);
        $markReadStmt->execute();
        $markReadStmt->close();
    }

    $selectedUser = null;
    $selectedUserStmt = $db->prepare("
        SELECT id, username, full_name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    if ($selectedUserStmt) {
        $selectedUserStmt->bind_param('i', $threadUserId);
        $selectedUserStmt->execute();
        $selectedUser = $selectedUserStmt->get_result()->fetch_assoc();
        $selectedUserStmt->close();
    }

    $messages = [];
    $lastMessageId = 0;
    $threadStmt = $db->prepare("
        SELECT
            m.id,
            m.sender_user_id,
            m.subject,
            m.message_body,
            m.related_table,
            m.related_id,
            m.created_at,
            sender.full_name AS sender_full_name,
            sender.username AS sender_username
        FROM user_messages m
        INNER JOIN users sender ON sender.id = m.sender_user_id
        WHERE ((m.sender_user_id = ? AND m.recipient_user_id = ?)
           OR (m.sender_user_id = ? AND m.recipient_user_id = ?))
          AND (
                (m.sender_user_id = ? AND m.hidden_for_sender = 0)
             OR (m.recipient_user_id = ? AND m.hidden_for_recipient = 0)
          )
          " . $contextSqlFilter . "
        ORDER BY m.created_at ASC, m.id ASC
    ");
    if ($threadStmt) {
        $threadStmt->bind_param('iiiiii', $currentUserId, $threadUserId, $threadUserId, $currentUserId, $currentUserId, $currentUserId);
        $threadStmt->execute();
        $threadRows = $threadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $threadStmt->close();

        foreach ($threadRows as $row) {
            $lastMessageId = max($lastMessageId, (int) $row['id']);
            $messages[] = [
                'id' => (int) $row['id'],
                'sender_user_id' => (int) $row['sender_user_id'],
                'sender_name' => (string) ($row['sender_full_name'] ?: $row['sender_username']),
                'subject' => (string) ($row['subject'] ?? ''),
                'message_body' => (string) $row['message_body'],
                'related_table' => (string) ($row['related_table'] ?? ''),
                'related_id' => (int) ($row['related_id'] ?? 0),
                'created_at' => (string) $row['created_at'],
                'created_label' => date('M d, Y g:i A', strtotime((string) $row['created_at'])),
                'is_mine' => (int) $row['sender_user_id'] === $currentUserId,
                'can_delete' => true,
            ];
        }
    }

    foreach ($conversations as &$conversation) {
        if (($conversation['type'] ?? '') === 'direct' && ($conversation['other_user_id'] ?? 0) === $threadUserId) {
            $conversation['unread_count'] = 0;
        }
    }
    unset($conversation);

    $thread = [
        'type' => 'direct',
        'selected_user_id' => $threadUserId,
        'selected_user_name' => $selectedUser ? (string) ($selectedUser['full_name'] ?: $selectedUser['username']) : '',
        'selected_user_online' => !empty($onlineUserMap[$threadUserId]),
        'messages' => $messages,
        'last_message_id' => $lastMessageId,
    ];
}

echo json_encode([
    'ok' => true,
    'unread_count' => $directUnreadCount + message_channel_unread_count($db, 'general', $currentUserId),
    'conversations' => $conversations,
    'available_users' => $availableUsers,
    'thread' => $thread,
]);
