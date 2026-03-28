<?php

function message_mark_channel_read(mysqli $db, string $channelKey, int $userId): void
{
    if ($channelKey === '' || $userId <= 0) {
        return;
    }

    $maxStmt = $db->prepare("SELECT COALESCE(MAX(id), 0) AS last_id FROM channel_messages WHERE channel_key = ?");
    if (!$maxStmt) {
        return;
    }

    $maxStmt->bind_param('s', $channelKey);
    $maxStmt->execute();
    $lastId = (int) (($maxStmt->get_result()->fetch_assoc()['last_id'] ?? 0));
    $maxStmt->close();

    $upsertStmt = $db->prepare("
        INSERT INTO message_channel_reads (channel_key, user_id, last_read_message_id, last_read_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = NOW()
    ");
    if ($upsertStmt) {
        $upsertStmt->bind_param('sii', $channelKey, $userId, $lastId);
        $upsertStmt->execute();
        $upsertStmt->close();
    }
}

function message_channel_unread_count(mysqli $db, string $channelKey, int $userId): int
{
    if ($channelKey === '' || $userId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM channel_messages cm
        LEFT JOIN message_channel_reads mcr
            ON mcr.channel_key = cm.channel_key
           AND mcr.user_id = ?
        LEFT JOIN message_channel_hidden mch
            ON mch.channel_message_id = cm.id
           AND mch.user_id = ?
        WHERE cm.channel_key = ?
          AND cm.sender_user_id != ?
          AND mch.channel_message_id IS NULL
          AND cm.id > COALESCE(mcr.last_read_message_id, 0)
    ");
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('iisi', $userId, $userId, $channelKey, $userId);
    $stmt->execute();
    $total = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();

    return $total;
}

function message_hide_channel_message(mysqli $db, int $messageId, int $userId): bool
{
    if ($messageId <= 0 || $userId <= 0) {
        return false;
    }

    $stmt = $db->prepare("
        INSERT INTO message_channel_hidden (channel_message_id, user_id)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE hidden_at = CURRENT_TIMESTAMP
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $messageId, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function message_highlight_mentions_html(string $message): string
{
    $html = h($message);
    $patterns = [
        '/@everyone\b/i',
        '/@administrator\b/i',
        '/@supply officer\b/i',
        '/@property officer\b/i',
        '/@viewer\b/i',
    ];

    foreach ($patterns as $pattern) {
        $html = preg_replace($pattern, '<span class="badge text-bg-warning text-dark">$0</span>', $html);
    }

    return nl2br($html, false);
}
