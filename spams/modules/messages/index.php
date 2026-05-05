<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$db = db();
$page_title = 'Messages';
$flash = get_flash();
$errors = [];
$users = [];
$conversations = [];
$threadMessages = [];
$selectedConversationUser = null;
$currentUserId = current_user_id() ?? 0;
$allowedRelatedTables = ['purchase_orders', 'receivings', 'distributions'];
$selectedConversationUserId = (int) ($_GET['user'] ?? 0);
$selectedChannelKey = trim((string) ($_GET['channel'] ?? ''));
$selectedRelatedTable = trim((string) ($_GET['related_table'] ?? ''));
$selectedRelatedId = (int) ($_GET['related_id'] ?? 0);
if ($selectedChannelKey !== 'general') { $selectedChannelKey = ''; }
if (!in_array($selectedRelatedTable, $allowedRelatedTables, true)) { $selectedRelatedTable = ''; $selectedRelatedId = 0; }
$relatedContextLabel = '';
$relatedContextHref = '';
$threadTitle = 'Select a conversation';
$threadSubtitle = 'Conversation thread';
$relatedContextTone = 'secondary';

if ($db && $currentUserId > 0) {
    $usersStmt = $db->prepare("SELECT id, username, full_name FROM users WHERE is_active = 1 AND id != ? ORDER BY full_name ASC, username ASC");
    if ($usersStmt) {
        $usersStmt->bind_param('i', $currentUserId);
        $usersStmt->execute();
        $users = $usersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $usersStmt->close();
    }

    if ($selectedRelatedTable !== '' && $selectedRelatedId > 0) {
        $relatedRoutes = [
            'purchase_orders' => 'modules/purchase_orders/view.php?id=',
            'receivings' => 'modules/receivings/iar.php?id=',
            'distributions' => 'modules/distributions/view.php?id=',
        ];
        $map = [
            'purchase_orders' => "SELECT CONCAT('Purchase Order: ', COALESCE(po_number, system_reference)) AS label FROM purchase_orders WHERE id = ? LIMIT 1",
            'receivings' => "SELECT CONCAT('Receiving: ', COALESCE(system_reference, ris_no)) AS label FROM receivings WHERE id = ? LIMIT 1",
            'distributions' => "SELECT CONCAT('Distribution: ', COALESCE(document_no, system_reference)) AS label FROM distributions WHERE id = ? LIMIT 1",
        ];
        $ctx = $db->prepare($map[$selectedRelatedTable]);
        if ($ctx) {
            $ctx->bind_param('i', $selectedRelatedId);
            $ctx->execute();
            $relatedContextLabel = (string) (($ctx->get_result()->fetch_assoc()['label'] ?? ''));
            $ctx->close();
        }
        if ($relatedContextLabel !== '' && isset($relatedRoutes[$selectedRelatedTable])) {
            $relatedContextHref = base_url($relatedRoutes[$selectedRelatedTable] . $selectedRelatedId);
        }
    }

    $contextSql = '';
    if ($selectedRelatedTable !== '' && $selectedRelatedId > 0) {
        $contextSql = " AND related_table = '" . $db->real_escape_string($selectedRelatedTable) . "' AND related_id = " . $selectedRelatedId . " ";
    }

    $generalPreviewStmt = $db->prepare("SELECT cm.subject, cm.message_body, cm.created_at FROM channel_messages cm LEFT JOIN message_channel_hidden mch ON mch.channel_message_id = cm.id AND mch.user_id = " . $currentUserId . " WHERE cm.channel_key = 'general' AND mch.channel_message_id IS NULL " . $contextSql . " ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1");
    $generalPreview = 'Shared coordination, announcements, and mentions.';
    $generalCreatedAt = '';
    if ($generalPreviewStmt) {
        $generalPreviewStmt->execute();
        $row = $generalPreviewStmt->get_result()->fetch_assoc();
        $generalPreviewStmt->close();
        if ($row) {
            $generalPreview = trim((string) (($row['subject'] ?? '') ?: ($row['message_body'] ?? '')));
            if (function_exists('mb_strlen') && mb_strlen($generalPreview) > 80) { $generalPreview = mb_substr($generalPreview, 0, 80) . '...'; }
            elseif (strlen($generalPreview) > 80) { $generalPreview = substr($generalPreview, 0, 80) . '...'; }
            $generalCreatedAt = (string) ($row['created_at'] ?? '');
        }
    }

    $conversations[] = [
        'type' => 'channel',
        'channel_key' => 'general',
        'display_name' => 'General Group Chat',
        'preview' => $generalPreview,
        'unread_count' => message_channel_unread_count($db, 'general', $currentUserId),
        'last_message_at' => $generalCreatedAt,
    ];

    $conversationStmt = $db->prepare("
        SELECT last_message.created_at AS last_message_at, last_message.message_body AS last_message_body,
               last_message.subject AS last_message_subject, conv.other_user_id, conv.unread_count,
               u.username, u.full_name
        FROM (
            SELECT CASE WHEN sender_user_id = ? THEN recipient_user_id ELSE sender_user_id END AS other_user_id,
                   MAX(id) AS last_message_id,
                   SUM(CASE WHEN recipient_user_id = ? AND is_read = 0 THEN 1 ELSE 0 END) AS unread_count
            FROM user_messages
            WHERE (sender_user_id = ? OR recipient_user_id = ?)
              AND (((sender_user_id = ? AND hidden_for_sender = 0) OR (recipient_user_id = ? AND hidden_for_recipient = 0)))
              $contextSql
            GROUP BY CASE WHEN sender_user_id = ? THEN recipient_user_id ELSE sender_user_id END
        ) conv
        INNER JOIN user_messages last_message ON last_message.id = conv.last_message_id
        INNER JOIN users u ON u.id = conv.other_user_id
        ORDER BY last_message.created_at DESC, last_message.id DESC
    ");
    if ($conversationStmt) {
        $conversationStmt->bind_param('iiiiiii', $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId, $currentUserId);
        $conversationStmt->execute();
        $rows = $conversationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $conversationStmt->close();
        foreach ($rows as $row) {
            $conversations[] = $row + ['type' => 'direct'];
        }
    }

    if ($selectedConversationUserId <= 0 && $selectedChannelKey === '') { $selectedChannelKey = 'general'; }

    if ($selectedChannelKey === 'general') {
        $threadTitle = 'General Group Chat';
        $threadSubtitle = 'Shared channel for all active users.';
        message_mark_channel_read($db, 'general', $currentUserId);
        $conversations[0]['unread_count'] = 0;
        $threadStmt = $db->prepare("
            SELECT cm.id, cm.sender_user_id, cm.subject, cm.message_body, cm.related_table, cm.related_id, cm.created_at,
                   sender.full_name AS sender_full_name, sender.username AS sender_username
            FROM channel_messages cm
            INNER JOIN users sender ON sender.id = cm.sender_user_id
            LEFT JOIN message_channel_hidden mch ON mch.channel_message_id = cm.id AND mch.user_id = " . $currentUserId . "
            WHERE cm.channel_key = 'general' AND mch.channel_message_id IS NULL $contextSql
            ORDER BY cm.created_at ASC, cm.id ASC
        ");
        if ($threadStmt) {
            $threadStmt->execute();
            $threadMessages = $threadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $threadStmt->close();
        }
    } elseif ($selectedConversationUserId > 0) {
        $markReadStmt = $db->prepare("UPDATE user_messages SET is_read = 1, read_at = NOW() WHERE sender_user_id = ? AND recipient_user_id = ? AND is_read = 0");
        if ($markReadStmt) {
            $markReadStmt->bind_param('ii', $selectedConversationUserId, $currentUserId);
            $markReadStmt->execute();
            $markReadStmt->close();
        }
        $selectedUserStmt = $db->prepare("SELECT id, username, full_name FROM users WHERE id = ? LIMIT 1");
        if ($selectedUserStmt) {
            $selectedUserStmt->bind_param('i', $selectedConversationUserId);
            $selectedUserStmt->execute();
            $selectedConversationUser = $selectedUserStmt->get_result()->fetch_assoc();
            $selectedUserStmt->close();
            if ($selectedConversationUser) {
                $threadTitle = (string) (($selectedConversationUser['full_name'] ?? '') ?: ($selectedConversationUser['username'] ?? 'Conversation'));
                $threadSubtitle = 'Direct conversation';
            }
        }
        foreach ($conversations as &$conversation) {
            if (($conversation['type'] ?? '') === 'direct' && (int) ($conversation['other_user_id'] ?? 0) === $selectedConversationUserId) {
                $conversation['unread_count'] = 0;
                break;
            }
        }
        unset($conversation);
        $threadStmt = $db->prepare("
            SELECT m.id, m.sender_user_id, m.subject, m.message_body, m.related_table, m.related_id, m.created_at,
                   sender.full_name AS sender_full_name, sender.username AS sender_username
            FROM user_messages m
            INNER JOIN users sender ON sender.id = m.sender_user_id
            WHERE ((m.sender_user_id = ? AND m.recipient_user_id = ?) OR (m.sender_user_id = ? AND m.recipient_user_id = ?))
              AND (((m.sender_user_id = ? AND m.hidden_for_sender = 0) OR (m.recipient_user_id = ? AND m.hidden_for_recipient = 0)))
              $contextSql
            ORDER BY m.created_at ASC, m.id ASC
        ");
        if ($threadStmt) {
            $threadStmt->bind_param('iiiiii', $currentUserId, $selectedConversationUserId, $selectedConversationUserId, $currentUserId, $currentUserId, $currentUserId);
            $threadStmt->execute();
            $threadMessages = $threadStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $threadStmt->close();
        }
    }
} else {
    $errors[] = 'Unable to load messages right now.';
}

if ($relatedContextLabel !== '') {
    $threadSubtitle = 'Linked discussion for ' . $relatedContextLabel;
    $relatedContextTone = 'primary';
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0">Internal Messages</h5>
                    <div class="small text-muted">Direct chat, linked discussions, and a shared General channel.</div>
                </div>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#composeCollapse"><i class="bi bi-chat-dots me-1"></i>New Message</button>
            </div>
            <div class="card-body border-top border-bottom bg-body-tertiary py-3">
                <div class="row g-3 align-items-stretch">
                    <div class="col-lg-7">
                        <div class="alert alert-light border mb-0 h-100">
                            <div class="fw-semibold mb-1">Messaging Guidance</div>
                            <div class="small text-muted mb-2">Use Messages for coordination and follow-up. Keep approvals, final decisions, and official transaction outcomes in the actual transaction records, audit trail, and printed forms.</div>
                            <div class="small">Available mentions: <span class="badge text-bg-warning text-dark">@everyone</span> <span class="badge text-bg-warning text-dark">@Administrator</span> <span class="badge text-bg-warning text-dark">@Supply Officer</span> <span class="badge text-bg-warning text-dark">@Property Officer</span></div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="alert alert-<?php echo h($relatedContextTone); ?> mb-0 h-100">
                            <div class="fw-semibold mb-1">Thread Context</div>
                            <?php if ($relatedContextLabel !== ''): ?>
                                <div class="small mb-2">This thread is filtered to one linked record.</div>
                                <div><strong><?php echo h($relatedContextLabel); ?></strong></div>
                                <?php if ($relatedContextHref !== ''): ?>
                                    <div class="mt-2">
                                        <a href="<?php echo h($relatedContextHref); ?>" class="btn btn-sm btn-outline-primary">Open Source Record</a>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="small mb-0">No linked record filter is active. Messages shown here cover the full conversation or the General channel.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse" id="composeCollapse">
                <div class="card-body border-top">
                    <?php if ($relatedContextLabel !== ''): ?><div class="alert alert-primary py-2"><strong>Linked Discussion:</strong> <?php echo h($relatedContextLabel); ?></div><?php endif; ?>
                    <?php if ($flash): ?><div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'info'; ?>"><?php echo h($flash['message']); ?></div><?php endif; ?>
                    <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
                    <form method="post" id="messageComposeForm" action="<?php echo base_url('modules/messages/send.php'); ?>">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="related_table" value="<?php echo h($selectedRelatedTable); ?>">
                        <input type="hidden" name="related_id" value="<?php echo h((string) $selectedRelatedId); ?>">
                        <input type="hidden" name="channel_key" id="compose_channel_key" value="<?php echo h($selectedChannelKey); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="recipient_user_id" class="form-label">Conversation Target</label>
                                <select class="form-select" id="recipient_user_id" name="recipient_user_id">
                                    <option value="">Select direct recipient</option>
                                    <option value="__general__" <?php echo $selectedChannelKey === 'general' ? 'selected' : ''; ?>>General Group Chat</option>
                                    <?php foreach ($users as $user): ?><option value="<?php echo (int) $user['id']; ?>" <?php echo $selectedConversationUserId === (int) $user['id'] ? 'selected' : ''; ?>><?php echo h(($user['full_name'] ?: $user['username']) . ' (' . $user['username'] . ')'); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label for="subject" class="form-label">Subject</label>
                                <input type="text" class="form-control" id="subject" name="subject" placeholder="Optional subject">
                            </div>
                            <div class="col-12">
                                <label for="message_body" class="form-label">Message</label>
                                <textarea class="form-control" id="message_body" name="message_body" rows="4" placeholder="Type your message here..." required></textarea>
                            </div>
                            <div class="col-12 d-flex gap-2 align-items-center">
                                <button type="submit" class="btn btn-primary" id="messageComposeSubmit">Send Message</button>
                                <span class="small text-muted d-none" id="messageComposeStatus"></span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100"><div class="card-body p-0"><div class="p-3 border-bottom"><h6 class="mb-0">Conversations</h6></div><div class="list-group list-group-flush" id="messagesConversationList">
            <?php foreach ($conversations as $conversation): $isChannel = ($conversation['type'] ?? '') === 'channel'; $isActive = $isChannel ? $selectedChannelKey === ($conversation['channel_key'] ?? '') : (int) ($conversation['other_user_id'] ?? 0) === $selectedConversationUserId; $displayName = $isChannel ? (string) ($conversation['display_name'] ?? 'General Group Chat') : (string) (($conversation['full_name'] ?? '') ?: ($conversation['username'] ?? '')); $previewText = $isChannel ? (string) ($conversation['preview'] ?? '') : trim((string) (($conversation['last_message_subject'] ?? '') ?: ($conversation['last_message_body'] ?? ''))); $url = $isChannel ? base_url('modules/messages/index.php?channel=general') : base_url('modules/messages/index.php?user=' . (int) $conversation['other_user_id']); if ($selectedRelatedTable !== '' && $selectedRelatedId > 0) { $url .= '&related_table=' . urlencode($selectedRelatedTable) . '&related_id=' . $selectedRelatedId; } ?>
                <a href="<?php echo $url; ?>" class="list-group-item list-group-item-action <?php echo $isActive ? 'active' : ''; ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="flex-grow-1">
                            <div class="fw-semibold d-flex align-items-center gap-2"><?php echo $isChannel ? '<i class="bi bi-people-fill"></i>' : '<span class="chat-online-dot"></span>'; ?><span><?php echo h($displayName); ?></span></div>
                            <div class="small <?php echo $isActive ? 'text-white-50' : 'text-muted'; ?>"><?php echo h($previewText !== '' ? $previewText : 'No preview available'); ?></div>
                        </div>
                        <?php if ((int) ($conversation['unread_count'] ?? 0) > 0): ?><span class="badge <?php echo $isActive ? 'text-bg-light text-dark' : 'text-bg-primary'; ?>"><?php echo h((string) $conversation['unread_count']); ?></span><?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div></div></div>
    </div>

    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0" id="messagesThreadTitle"><?php echo h($threadTitle); ?></h6>
                <div class="small text-muted" id="messagesThreadSubtitle"><?php echo h($threadSubtitle); ?></div>
            </div>
            <div class="card-body overflow-auto" id="messagesThreadBody">
                <?php if ($threadMessages): ?><div class="d-flex flex-column gap-3"><?php foreach ($threadMessages as $message): $isMine = (int) $message['sender_user_id'] === $currentUserId; ?>
                    <div class="d-flex <?php echo $isMine ? 'justify-content-end' : 'justify-content-start'; ?>">
                        <div class="border rounded-3 p-3 <?php echo $isMine ? 'bg-primary-subtle border-primary-subtle' : 'bg-light'; ?>" style="max-width: 85%;">
                            <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                                <div class="fw-semibold small"><?php echo h($isMine ? 'You' : (($message['sender_full_name'] ?? '') ?: ($message['sender_username'] ?? ''))); ?></div>
                                <div class="small text-muted"><?php echo h(date('M d, Y g:i A', strtotime((string) $message['created_at']))); ?></div>
                            </div>
                            <?php if (trim((string) ($message['subject'] ?? '')) !== ''): ?><div class="fw-semibold mb-2"><?php echo h($message['subject']); ?></div><?php endif; ?>
                            <div style="white-space: pre-wrap;"><?php echo message_highlight_mentions_html((string) $message['message_body']); ?></div>
                            <div class="mt-2 text-end"><button type="button" class="btn btn-sm btn-outline-secondary message-delete-btn" data-message-id="<?php echo $selectedChannelKey === 'general' ? 0 : (int) $message['id']; ?>" data-channel-message-id="<?php echo $selectedChannelKey === 'general' ? (int) $message['id'] : 0; ?>"><i class="bi bi-trash3"></i> Delete for me</button></div>
                        </div>
                    </div>
                <?php endforeach; ?></div><?php else: ?><div class="text-center text-muted py-5">No messages yet in this conversation.</div><?php endif; ?>
            </div>
        </div>
    </div>
</section>
<script>
(function() {
    if (window.jQuery && jQuery.fn.select2) { jQuery('#recipient_user_id').select2({ width: '100%' }); }
    var pollUrl = <?php echo json_encode(base_url('modules/messages/poll.php?limit=100' . ($selectedConversationUserId > 0 ? '&thread_user=' . $selectedConversationUserId : '') . ($selectedChannelKey !== '' ? '&thread_channel=' . urlencode($selectedChannelKey) : '') . ($selectedRelatedTable !== '' && $selectedRelatedId > 0 ? '&related_table=' . urlencode($selectedRelatedTable) . '&related_id=' . $selectedRelatedId : ''))); ?>;
    var sendUrl = <?php echo json_encode(base_url('modules/messages/send.php')); ?>;
    var deleteUrl = <?php echo json_encode(base_url('modules/messages/delete.php')); ?>;
    var composeForm = document.getElementById('messageComposeForm');
    var recipientField = document.getElementById('recipient_user_id');
    var channelField = document.getElementById('compose_channel_key');
    var composeStatus = document.getElementById('messageComposeStatus');
    var threadBody = document.getElementById('messagesThreadBody');
    function syncTarget(){ if(recipientField && channelField){ channelField.value = recipientField.value === '__general__' ? 'general' : ''; } }
    function badge(count){ var b = document.getElementById('topbarMessageBadge'); if(b){ b.textContent = String(count); b.classList.toggle('d-none', count <= 0); } }
    function status(text, err){ if(!composeStatus){ return; } composeStatus.textContent = text || ''; composeStatus.classList.toggle('d-none', !text); composeStatus.classList.toggle('text-danger', !!err); composeStatus.classList.toggle('text-success', !err && !!text); }
    if(recipientField){ recipientField.addEventListener('change', syncTarget); syncTarget(); }
    if(composeForm){ composeForm.addEventListener('submit', function(e){ e.preventDefault(); syncTarget(); var fd = new FormData(composeForm); if(fd.get('recipient_user_id') === '__general__'){ fd.set('recipient_user_id', ''); fd.set('channel_key', 'general'); } status('Sending...', false); fetch(sendUrl, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} }).then(function(r){ return r.json(); }).then(function(data){ if(!data || data.ok !== true){ status(data && data.message ? data.message : 'Unable to send the message.', true); return; } composeForm.reset(); if(window.jQuery && jQuery.fn.select2){ jQuery('#recipient_user_id').val('').trigger('change'); } syncTarget(); status('Message sent.', false); location.reload(); }).catch(function(){ status('Unable to send the message.', true); }); }); }
    if(threadBody){ threadBody.addEventListener('click', function(e){ var btn = e.target.closest('.message-delete-btn'); if(!btn || !confirm('Remove this message from your view?')){ return; } var fd = new FormData(); fd.append('_csrf', <?php echo json_encode(csrf_token()); ?>); fd.append('message_id', btn.getAttribute('data-message-id') || '0'); fd.append('channel_message_id', btn.getAttribute('data-channel-message-id') || '0'); fetch(deleteUrl, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} }).then(function(r){ return r.json(); }).then(function(data){ if(!data || data.ok !== true){ status(data && data.message ? data.message : 'Unable to remove the message.', true); return; } location.reload(); }); }); }
    window.setInterval(function(){ fetch(pollUrl, { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} }).then(function(r){ return r.json(); }).then(function(data){ if(data && data.ok === true){ badge(parseInt(data.unread_count || 0, 10)); } }).catch(function(){}); }, 8000);
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
