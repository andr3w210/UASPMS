<?php
if (!is_logged_in()) {
    return;
}

$chatDb = (isset($db) && $db instanceof mysqli) ? $db : db();
$chatCurrentUserId = current_user_id() ?? 0;
$chatUnreadCount = 0;
$chatConversations = [];
$chatUsers = [];
$chatOnlineUserMap = [];

if ($chatDb && $chatCurrentUserId > 0) {
    $chatPresenceStmt = $chatDb->prepare("
        INSERT INTO user_presence (user_id, last_seen_at)
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()
    ");
    if ($chatPresenceStmt) {
        $chatPresenceStmt->bind_param('i', $chatCurrentUserId);
        $chatPresenceStmt->execute();
        $chatPresenceStmt->close();
    }

    $chatOnlineResult = $chatDb->query("
        SELECT user_id
        FROM user_presence
        WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
    ");
    if ($chatOnlineResult instanceof mysqli_result) {
        while ($chatOnlineRow = $chatOnlineResult->fetch_assoc()) {
            $chatOnlineUserMap[(int) $chatOnlineRow['user_id']] = true;
        }
        $chatOnlineResult->free();
    }

    $chatUnreadStmt = $chatDb->prepare("
        SELECT COUNT(*) AS total
        FROM user_messages
        WHERE recipient_user_id = ?
          AND is_read = 0
    ");
    if ($chatUnreadStmt) {
        $chatUnreadStmt->bind_param('i', $chatCurrentUserId);
        $chatUnreadStmt->execute();
        $chatUnreadCount = (int) (($chatUnreadStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $chatUnreadStmt->close();
    }
    $chatUnreadCount += message_channel_unread_count($chatDb, 'general', $chatCurrentUserId);

    $chatConversationStmt = $chatDb->prepare("
        SELECT
            last_message.id AS last_message_id,
            last_message.created_at AS last_message_at,
            last_message.message_body AS last_message_body,
            last_message.subject AS last_message_subject,
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
            GROUP BY CASE
                WHEN sender_user_id = ? THEN recipient_user_id
                ELSE sender_user_id
            END
        ) conv
        INNER JOIN user_messages last_message ON last_message.id = conv.last_message_id
        INNER JOIN users u ON u.id = conv.other_user_id
        ORDER BY last_message.created_at DESC, last_message.id DESC
        LIMIT 5
    ");
    $chatConversations[] = [
        'type' => 'channel',
        'channel_key' => 'general',
        'display_name' => 'General Group Chat',
        'preview' => 'Shared coordination, announcements, and role mentions.',
        'unread_count' => message_channel_unread_count($chatDb, 'general', $chatCurrentUserId),
        'last_message_label' => '',
        'online' => false,
    ];

    if ($chatConversationStmt) {
        $chatConversationStmt->bind_param(
            'iiiiiii',
            $chatCurrentUserId,
            $chatCurrentUserId,
            $chatCurrentUserId,
            $chatCurrentUserId,
            $chatCurrentUserId,
            $chatCurrentUserId,
            $chatCurrentUserId
        );
        $chatConversationStmt->execute();
        $chatConversationRows = $chatConversationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $chatConversationStmt->close();

        foreach ($chatConversationRows as $conversation) {
            $chatPreview = trim((string) ($conversation['last_message_subject'] ?: $conversation['last_message_body']));
            if (function_exists('mb_strlen') && mb_strlen($chatPreview) > 70) {
                $chatPreview = mb_substr($chatPreview, 0, 70) . '...';
            } elseif (strlen($chatPreview) > 70) {
                $chatPreview = substr($chatPreview, 0, 70) . '...';
            }

            $otherUserId = (int) $conversation['other_user_id'];
            $chatConversations[] = [
                'type' => 'direct',
                'other_user_id' => $otherUserId,
                'display_name' => (string) ($conversation['full_name'] ?: $conversation['username']),
                'preview' => $chatPreview,
                'unread_count' => (int) $conversation['unread_count'],
                'last_message_label' => date('M d', strtotime((string) $conversation['last_message_at'])),
                'online' => !empty($chatOnlineUserMap[$otherUserId]),
            ];
        }
    }

    $chatUsersStmt = $chatDb->prepare("
        SELECT id, username, full_name
        FROM users
        WHERE is_active = 1
          AND id != ?
        ORDER BY full_name ASC, username ASC
    ");
    if ($chatUsersStmt) {
        $chatUsersStmt->bind_param('i', $chatCurrentUserId);
        $chatUsersStmt->execute();
        $chatUserRows = $chatUsersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $chatUsersStmt->close();

        foreach ($chatUserRows as $chatUser) {
            $userId = (int) $chatUser['id'];
            $chatUsers[] = [
                'id' => $userId,
                'display_name' => (string) ($chatUser['full_name'] ?: $chatUser['username']),
                'online' => !empty($chatOnlineUserMap[$userId]),
            ];
        }
    }

    $chatGeneralStmt = $chatDb->prepare("
        SELECT cm.subject, cm.message_body, cm.created_at
        FROM channel_messages cm
        LEFT JOIN message_channel_hidden mch
            ON mch.channel_message_id = cm.id
           AND mch.user_id = " . $chatCurrentUserId . "
        WHERE cm.channel_key = 'general'
          AND mch.channel_message_id IS NULL
        ORDER BY cm.created_at DESC, cm.id DESC
        LIMIT 1
    ");
    if ($chatGeneralStmt) {
        $chatGeneralStmt->execute();
        $chatGeneralRow = $chatGeneralStmt->get_result()->fetch_assoc();
        $chatGeneralStmt->close();
        if ($chatGeneralRow) {
            $chatGeneralPreview = trim((string) (($chatGeneralRow['subject'] ?? '') ?: ($chatGeneralRow['message_body'] ?? '')));
            if (function_exists('mb_strlen') && mb_strlen($chatGeneralPreview) > 70) {
                $chatGeneralPreview = mb_substr($chatGeneralPreview, 0, 70) . '...';
            } elseif (strlen($chatGeneralPreview) > 70) {
                $chatGeneralPreview = substr($chatGeneralPreview, 0, 70) . '...';
            }
            $chatConversations[0]['preview'] = $chatGeneralPreview;
            $chatConversations[0]['last_message_label'] = date('M d', strtotime((string) $chatGeneralRow['created_at']));
        }
    }
}
?>
<div
    class="chat-widget"
    id="chatWidget"
    data-poll-url="<?php echo h(base_url('modules/messages/poll.php?limit=5')); ?>"
    data-send-url="<?php echo h(base_url('modules/messages/send.php')); ?>"
    data-csrf="<?php echo h(csrf_token()); ?>"
>
    <button type="button" class="chat-widget-toggle" id="chatWidgetToggle" aria-expanded="false" aria-controls="chatWidgetPanel">
        <i class="bi bi-chat-dots-fill"></i>
        <span>Chat</span>
        <span class="chat-widget-badge <?php echo $chatUnreadCount > 0 ? '' : 'd-none'; ?>" id="chatWidgetBadge"><?php echo h((string) $chatUnreadCount); ?></span>
    </button>

    <div class="chat-widget-panel shadow-lg d-none" id="chatWidgetPanel">
        <div class="chat-widget-header">
            <div>
                <div class="fw-semibold" id="chatWidgetTitle">Messages</div>
                <div class="small text-muted" id="chatWidgetSubtitle">Recent conversations</div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="chatWidgetBack">
                <i class="bi bi-arrow-left"></i>
            </button>
        </div>

        <div class="chat-widget-select-wrap" id="chatWidgetSelectWrap">
            <select class="form-select form-select-sm" id="chatWidgetUserSelect" data-no-select2>
                <option value="">Start new chat...</option>
                <option value="__general__">General Group Chat</option>
                <?php foreach ($chatUsers as $chatUser): ?>
                    <option value="<?php echo (int) $chatUser['id']; ?>">
                        <?php echo h($chatUser['display_name'] . ($chatUser['online'] ? ' (Online)' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="chat-widget-body" id="chatWidgetBody">
            <div class="list-group list-group-flush" id="chatWidgetConversationList">
                <?php if ($chatConversations): ?>
                    <?php foreach ($chatConversations as $conversation): ?>
                        <button type="button" class="list-group-item list-group-item-action chat-widget-item chat-widget-conversation-btn" data-user-id="<?php echo (int) ($conversation['other_user_id'] ?? 0); ?>" data-channel-key="<?php echo h((string) ($conversation['channel_key'] ?? '')); ?>" data-user-name="<?php echo h($conversation['display_name']); ?>">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small d-flex align-items-center gap-2">
                                        <?php if (($conversation['type'] ?? '') === 'channel'): ?>
                                            <i class="bi bi-people-fill"></i>
                                        <?php else: ?>
                                            <span class="chat-online-dot <?php echo $conversation['online'] ? 'is-online' : ''; ?>"></span>
                                        <?php endif; ?>
                                        <span><?php echo h($conversation['display_name']); ?></span>
                                    </div>
                                    <div class="small text-muted"><?php echo h($conversation['preview'] !== '' ? $conversation['preview'] : 'Open conversation'); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="small text-muted"><?php echo h($conversation['last_message_label']); ?></div>
                                    <?php if ($conversation['unread_count'] > 0): ?>
                                        <span class="badge text-bg-primary"><?php echo h((string) $conversation['unread_count']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4" id="chatWidgetEmptyState">
                        No conversations yet.
                    </div>
                <?php endif; ?>
            </div>

            <div class="chat-widget-thread d-none" id="chatWidgetThread">
                <div class="chat-widget-thread-messages" id="chatWidgetThreadMessages">
                    <div class="text-center text-muted py-4">Select a conversation to start chatting.</div>
                </div>
                <form id="chatWidgetForm" class="chat-widget-form">
                    <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                    <input type="hidden" name="recipient_user_id" id="chatWidgetRecipientId" value="">
                    <input type="hidden" name="subject" value="">
                    <textarea class="form-control form-control-sm" id="chatWidgetMessageBody" name="message_body" rows="2" placeholder="Type your message..." required></textarea>
                    <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                        <span class="small text-muted d-none" id="chatWidgetStatus"></span>
                        <button type="submit" class="btn btn-primary btn-sm ms-auto" id="chatWidgetSubmit">Send</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="chat-widget-footer" id="chatWidgetFooter">
            <a href="<?php echo base_url('modules/messages/index.php'); ?>" class="btn btn-primary btn-sm w-100">
                View All Messages
            </a>
        </div>
    </div>
</div>
<script>
(function() {
    var widget = document.getElementById('chatWidget');
    var toggle = document.getElementById('chatWidgetToggle');
    var panel = document.getElementById('chatWidgetPanel');
    var badge = document.getElementById('chatWidgetBadge');
    var body = document.getElementById('chatWidgetBody');
    var conversationList = document.getElementById('chatWidgetConversationList');
    var thread = document.getElementById('chatWidgetThread');
    var threadMessages = document.getElementById('chatWidgetThreadMessages');
    var title = document.getElementById('chatWidgetTitle');
    var subtitle = document.getElementById('chatWidgetSubtitle');
    var backButton = document.getElementById('chatWidgetBack');
    var userSelect = document.getElementById('chatWidgetUserSelect');
    var selectWrap = document.getElementById('chatWidgetSelectWrap');
    var footer = document.getElementById('chatWidgetFooter');
    var form = document.getElementById('chatWidgetForm');
    var recipientInput = document.getElementById('chatWidgetRecipientId');
    var messageInput = document.getElementById('chatWidgetMessageBody');
    var submitButton = document.getElementById('chatWidgetSubmit');
    var statusNode = document.getElementById('chatWidgetStatus');
    var pollUrl = widget ? widget.getAttribute('data-poll-url') : '';
    var sendUrl = widget ? widget.getAttribute('data-send-url') : '';
    var deleteUrl = <?php echo json_encode(base_url('modules/messages/delete.php')); ?>;
    var csrfToken = widget ? widget.getAttribute('data-csrf') : '';
    var activeThreadUserId = 0;
    var activeThreadChannelKey = '';
    var activeThreadUserName = '';
    var lastMessageId = 0;
    var polling = false;

    if (!widget || !toggle || !panel) {
        return;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setComposeState(message, isError) {
        if (!statusNode) {
            return;
        }

        statusNode.textContent = message || '';
        statusNode.classList.toggle('d-none', !message);
        statusNode.classList.toggle('text-danger', !!isError);
        statusNode.classList.toggle('text-success', !isError && !!message);
        statusNode.classList.toggle('text-muted', !isError && !message);
    }

    function updateUnread(count) {
        var topbarBadge = document.getElementById('topbarMessageBadge');
        if (badge) {
            badge.textContent = String(count);
            badge.classList.toggle('d-none', count <= 0);
        }
        if (topbarBadge) {
            topbarBadge.textContent = String(count);
            topbarBadge.classList.toggle('d-none', count <= 0);
        }
    }

    function setOpen(isOpen) {
        panel.classList.toggle('d-none', !isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        widget.classList.toggle('is-open', isOpen);
    }

    function setThreadMode(enabled, userName, isOnline) {
        activeThreadUserName = userName || '';
        conversationList.classList.toggle('d-none', enabled);
        thread.classList.toggle('d-none', !enabled);
        selectWrap.classList.toggle('d-none', enabled);
        footer.classList.toggle('d-none', enabled);
        backButton.classList.toggle('d-none', !enabled);
        title.textContent = enabled ? activeThreadUserName : 'Messages';
        subtitle.textContent = enabled ? (isOnline ? 'Online now' : 'Recent messages') : 'Recent conversations';
        if (!enabled) {
            activeThreadUserId = 0;
            activeThreadChannelKey = '';
            recipientInput.value = '';
            lastMessageId = 0;
            setComposeState('', false);
        }
    }

    function renderConversations(conversations) {
        if (!conversationList) {
            return;
        }

        if (!Array.isArray(conversations) || conversations.length === 0) {
            conversationList.innerHTML = '<div class="text-center text-muted py-4" id="chatWidgetEmptyState">No conversations yet.</div>';
            return;
        }

        var html = '';
        conversations.forEach(function(conversation) {
            var isChannel = conversation.type === 'channel';
            html += ''
                + '<button type="button" class="list-group-item list-group-item-action chat-widget-item chat-widget-conversation-btn"'
                + ' data-user-id="' + escapeHtml(conversation.other_user_id || 0) + '"'
                + ' data-channel-key="' + escapeHtml(conversation.channel_key || '') + '"'
                + ' data-user-name="' + escapeHtml(conversation.display_name) + '"'
                + ' data-user-online="' + (conversation.online ? '1' : '0') + '">'
                +   '<div class="d-flex justify-content-between gap-2 align-items-start">'
                +       '<div class="flex-grow-1">'
                +           '<div class="fw-semibold small d-flex align-items-center gap-2">'
                +               (isChannel ? '<i class="bi bi-people-fill"></i>' : '<span class="chat-online-dot ' + (conversation.online ? 'is-online' : '') + '"></span>')
                +               '<span>' + escapeHtml(conversation.display_name) + '</span>'
                +           '</div>'
                +           '<div class="small text-muted">' + escapeHtml(conversation.preview || 'Open conversation') + '</div>'
                +       '</div>'
                +       '<div class="text-end">'
                +           '<div class="small text-muted">' + escapeHtml(conversation.last_message_label || '') + '</div>';
            if (parseInt(conversation.unread_count || 0, 10) > 0) {
                html += '<span class="badge text-bg-primary">' + escapeHtml(conversation.unread_count) + '</span>';
            }
            html += ''
                +       '</div>'
                +   '</div>'
                + '</button>';
        });
        conversationList.innerHTML = html;
    }

    function renderUsers(users) {
        if (!userSelect) {
            return;
        }

        var currentValue = userSelect.value;
        var html = '<option value="">Start new chat...</option><option value="__general__">General Group Chat</option>';
        (users || []).forEach(function(user) {
            html += '<option value="' + escapeHtml(user.id) + '">' + escapeHtml(user.display_name + (user.online ? ' (Online)' : '')) + '</option>';
        });
        userSelect.innerHTML = html;
        if (currentValue) {
            userSelect.value = currentValue;
        }
    }

    function renderThread(threadData) {
        if (!threadMessages) {
            return;
        }

        if (!threadData || !Array.isArray(threadData.messages)) {
            threadMessages.innerHTML = '<div class="text-center text-muted py-4">No messages yet in this conversation.</div>';
            lastMessageId = 0;
            return;
        }

        setThreadMode(true, threadData.selected_user_name || activeThreadUserName, !!threadData.selected_user_online);
        subtitle.textContent = threadData.type === 'channel' ? 'Shared group chat' : (threadData.selected_user_online ? 'Online now' : 'Recent messages');

        if (threadData.messages.length === 0) {
            threadMessages.innerHTML = '<div class="text-center text-muted py-4">No messages yet in this conversation.</div>';
            lastMessageId = 0;
            return;
        }

        var html = '';
        threadData.messages.forEach(function(message) {
            html += ''
                + '<div class="chat-thread-message ' + (message.is_mine ? 'is-mine' : '') + '">'
                +   '<div class="chat-thread-bubble ' + (message.is_mine ? 'is-mine' : '') + '">'
                +       '<div class="chat-thread-meta">'
                +           '<span>' + escapeHtml(message.is_mine ? 'You' : message.sender_name) + '</span>'
                +           '<span>' + escapeHtml(message.created_label || '') + '</span>'
                +       '</div>';
            if (message.subject) {
                html += '<div class="fw-semibold mb-1">' + escapeHtml(message.subject) + '</div>';
            }
            html += '<div style="white-space: pre-wrap;">' + escapeHtml(message.message_body) + '</div>';
            if (message.can_delete) {
                html += '<div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-secondary chat-widget-delete-btn" data-message-id="' + escapeHtml(threadData.type === "channel" ? 0 : message.id) + '" data-channel-message-id="' + escapeHtml(threadData.type === "channel" ? message.id : 0) + '"><i class="bi bi-trash3"></i> Delete for me</button></div>';
            }
            html += '</div></div>';
        });
        threadMessages.innerHTML = html;

        if (parseInt(threadData.last_message_id || 0, 10) > lastMessageId) {
            threadMessages.scrollTop = threadMessages.scrollHeight;
        }
        lastMessageId = parseInt(threadData.last_message_id || 0, 10);
    }

    function pollMessages() {
        if (!pollUrl || polling) {
            return;
        }

        polling = true;
        var url = pollUrl + (activeThreadUserId > 0 ? '&thread_user=' + encodeURIComponent(activeThreadUserId) : '') + (activeThreadChannelKey ? '&thread_channel=' + encodeURIComponent(activeThreadChannelKey) : '');
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || data.ok !== true) {
                    return;
                }
                updateUnread(parseInt(data.unread_count || 0, 10));
                renderConversations(data.conversations || []);
                renderUsers(data.available_users || []);
                if (activeThreadUserId > 0 || activeThreadChannelKey) {
                    renderThread(data.thread || null);
                }
            })
            .catch(function() {
            })
            .finally(function() {
                polling = false;
            });
    }

    toggle.addEventListener('click', function() {
        setOpen(panel.classList.contains('d-none'));
    });

    document.addEventListener('click', function(event) {
        if (!widget.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    conversationList.addEventListener('click', function(event) {
        var button = event.target.closest('.chat-widget-conversation-btn');
        if (!button) {
            return;
        }
        activeThreadUserId = parseInt(button.getAttribute('data-user-id') || '0', 10);
        activeThreadChannelKey = button.getAttribute('data-channel-key') || '';
        recipientInput.value = activeThreadUserId > 0 ? String(activeThreadUserId) : '';
        setThreadMode(true, button.getAttribute('data-user-name') || '', button.getAttribute('data-user-online') === '1');
        setComposeState('', false);
        pollMessages();
    });

    if (backButton) {
        backButton.addEventListener('click', function() {
            setThreadMode(false, '', false);
        });
    }

    if (userSelect) {
        userSelect.addEventListener('change', function() {
            var selectedOption = userSelect.options[userSelect.selectedIndex];
            activeThreadChannelKey = userSelect.value === '__general__' ? 'general' : '';
            activeThreadUserId = activeThreadChannelKey ? 0 : parseInt(userSelect.value || '0', 10);
            if (activeThreadUserId <= 0 && !activeThreadChannelKey) {
                return;
            }
            recipientInput.value = activeThreadUserId > 0 ? String(activeThreadUserId) : '';
            setThreadMode(true, activeThreadChannelKey ? 'General Group Chat' : (selectedOption ? selectedOption.text.replace(' (Online)', '') : 'Conversation'), selectedOption ? selectedOption.text.indexOf('(Online)') !== -1 : false);
            threadMessages.innerHTML = '<div class="text-center text-muted py-4">No messages yet in this conversation.</div>';
            setComposeState('', false);
            pollMessages();
        });
    }

    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();

            if ((activeThreadUserId <= 0 && !activeThreadChannelKey) || !sendUrl) {
                setComposeState('Select a conversation first.', true);
                return;
            }

            var formData = new FormData();
            formData.append('_csrf', csrfToken);
            formData.append('recipient_user_id', activeThreadUserId > 0 ? String(activeThreadUserId) : '');
            formData.append('channel_key', activeThreadChannelKey);
            formData.append('subject', '');
            formData.append('message_body', messageInput.value.trim());

            if (!messageInput.value.trim()) {
                setComposeState('Type a message first.', true);
                return;
            }

            submitButton.disabled = true;
            setComposeState('Sending...', false);

            fetch(sendUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.ok !== true) {
                        setComposeState((data && data.message) ? data.message : 'Unable to send the message.', true);
                        return;
                    }
                    messageInput.value = '';
                    setComposeState('', false);
                    pollMessages();
                })
                .catch(function() {
                    setComposeState('Unable to send the message.', true);
                })
                .finally(function() {
                    submitButton.disabled = false;
                });
        });
    }

    if (threadMessages) {
        threadMessages.addEventListener('click', function(event) {
            var deleteButton = event.target.closest('.chat-widget-delete-btn');
            if (!deleteButton) {
                return;
            }

            event.preventDefault();
            if (!deleteUrl || !window.fetch) {
                return;
            }

            var messageId = parseInt(deleteButton.getAttribute('data-message-id') || '0', 10);
            if (messageId <= 0 || !window.confirm('Remove this message from your view?')) {
                return;
            }

            var formData = new FormData();
            formData.append('_csrf', csrfToken);
            formData.append('message_id', String(messageId));
            formData.append('channel_message_id', deleteButton.getAttribute('data-channel-message-id') || '0');

            fetch(deleteUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || data.ok !== true) {
                        setComposeState((data && data.message) ? data.message : 'Unable to remove the message.', true);
                        return;
                    }
                    setComposeState('Message removed from your view.', false);
                    pollMessages();
                })
                .catch(function() {
                    setComposeState('Unable to remove the message.', true);
                });
        });
    }

    pollMessages();
    window.setInterval(pollMessages, 10000);
})();
</script>
