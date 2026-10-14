<?php
// HTTP regression test: User A sends a direct message to User C; unrelated User B
// cannot read its thread, update it, or delete it. The test message is removed.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../spams/app/config/init.php';

$sessionPath = __DIR__ . '/.tmp_access_test_sessions';
if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700) && !is_dir($sessionPath)) {
    fwrite(STDERR, "FAIL could not create temporary session directory\n");
    exit(1);
}
session_save_path($sessionPath);

function isolation_assert(bool $passed, string $label, array &$failures): void
{
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
}

function isolation_make_session(int $userId): array
{
    return ['id' => bin2hex(random_bytes(24)), 'csrf' => bin2hex(random_bytes(32)), 'user' => $userId];
}

function isolation_http_request(string $url, array $session, string $method = 'GET', array $fields = []): array
{
    $headers = [
        'Cookie: PHPSESSID=' . $session['id'],
        'X-Test-User: ' . $session['user'],
        'X-Test-Csrf: ' . $session['csrf'],
        'Accept: application/json, text/plain, */*',
    ];
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10];
    if ($method === 'POST') {
        $fields['_csrf'] = $session['csrf'];
        $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['content'] = http_build_query($fields);
    }
    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

$db = db();
if (!$db) {
    fwrite(STDERR, "FAIL database connection unavailable\n");
    exit(1);
}

$userResult = $db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 3');
$userIds = $userResult ? array_map('intval', array_column($userResult->fetch_all(MYSQLI_ASSOC), 'id')) : [];
if (count($userIds) < 3) {
    fwrite(STDERR, "FAIL requires three active users (creator, unrelated user, recipient)\n");
    exit(1);
}
[$userA, $userB, $userC] = $userIds;

$presenceBefore = [];
$presenceStmt = $db->prepare('SELECT user_id, last_seen_at FROM user_presence WHERE user_id IN (?, ?)');
if ($presenceStmt) {
    $presenceStmt->bind_param('ii', $userB, $userC);
    $presenceStmt->execute();
    foreach ($presenceStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $presenceRow) {
        $presenceBefore[(int) $presenceRow['user_id']] = (string) $presenceRow['last_seen_at'];
    }
    $presenceStmt->close();
}

$phpServer = null;
$serverPipes = [];
$messageId = 0;
$failures = [];
$uniqueBody = '[access-isolation-test-' . bin2hex(random_bytes(8)) . ']';
$portSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$portSocket) {
    fwrite(STDERR, "FAIL could not reserve local test port\n");
    exit(1);
}
$serverAddress = stream_socket_get_name($portSocket, false);
fclose($portSocket);
$baseUrl = 'http://' . $serverAddress;

try {
    $phpServer = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-S', $serverAddress, __DIR__ . '/test_support/message_access_router.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes, dirname(__DIR__));
    if (!is_resource($phpServer)) throw new RuntimeException('could not start local PHP server');
    foreach ($serverPipes as $pipe) stream_set_blocking($pipe, false);

    $serverReady = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $probe = @fsockopen('127.0.0.1', (int) substr(strrchr($serverAddress, ':'), 1), $probeErrno, $probeError, 0.1);
        if ($probe) { fclose($probe); $serverReady = true; break; }
        usleep(100000);
    }
    if (!$serverReady) throw new RuntimeException('local PHP server did not start');

    $sessionA = isolation_make_session($userA);
    $create = isolation_http_request($baseUrl . '/modules/messages/send.php', $sessionA, 'POST', [
        'recipient_user_id' => (string) $userC,
        'channel_key' => 'direct',
        'subject' => 'Access isolation test',
        'message_body' => $uniqueBody,
        'related_table' => '',
        'related_id' => '0',
    ]);
    $createJson = json_decode($create['body'], true);
    $messageId = (int) ($createJson['message_id'] ?? 0);
    isolation_assert($create['status'] === 200 && !empty($createJson['ok']) && $messageId > 0, 'User A creates the test message (HTTP ' . $create['status'] . ')', $failures);
    if ($messageId <= 0) throw new RuntimeException('message creation failed; cannot exercise authorization checks (' . trim($create['body']) . ')');

    $sessionB = isolation_make_session($userB);
    $read = isolation_http_request($baseUrl . '/modules/messages/poll.php?limit=100&thread_user=' . $userC, $sessionB);
    isolation_assert($read['status'] === 404 && strpos($read['body'], $uniqueBody) === false, 'User B cannot read User A\'s message thread (HTTP ' . $read['status'] . ')', $failures);

    $update = isolation_http_request($baseUrl . '/modules/messages/update.php?id=' . $messageId, $sessionB, 'POST', [
        'message_id' => (string) $messageId,
        'subject' => 'Tampered by User B',
        'message_body' => 'Tampered by User B',
    ]);
    isolation_assert($update['status'] === 404, 'User B update attempt is rejected because direct messages have no update route (HTTP ' . $update['status'] . ')', $failures);

    $delete = isolation_http_request($baseUrl . '/modules/messages/delete.php', $sessionB, 'POST', [
        'message_id' => (string) $messageId,
        'channel_message_id' => '0',
    ]);
    isolation_assert($delete['status'] === 403, 'User B cannot delete User A\'s message (HTTP ' . $delete['status'] . ')', $failures);
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($phpServer && is_resource($phpServer)) {
        proc_terminate($phpServer);
        proc_close($phpServer);
    }
    if ($messageId > 0) {
        $cleanupStmt = $db->prepare('DELETE FROM user_messages WHERE id = ?');
        if ($cleanupStmt) {
            $cleanupStmt->bind_param('i', $messageId);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }
    }
    foreach ([$userB, $userC] as $userId) {
        if (isset($presenceBefore[$userId])) {
            $restoreStmt = $db->prepare('UPDATE user_presence SET last_seen_at = ? WHERE user_id = ?');
            if ($restoreStmt) {
                $restoreStmt->bind_param('si', $presenceBefore[$userId], $userId);
                $restoreStmt->execute();
                $restoreStmt->close();
            }
        } else {
            $removeStmt = $db->prepare('DELETE FROM user_presence WHERE user_id = ?');
            if ($removeStmt) {
                $removeStmt->bind_param('i', $userId);
                $removeStmt->execute();
                $removeStmt->close();
            }
        }
    }
    foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        @unlink($sessionFile);
    }
    @rmdir($sessionPath);
    $db->close();
}

if ($failures) {
    fwrite(STDERR, count($failures) . " failure(s).\n");
    exit(1);
}
echo "All isolation checks passed. Test data cleaned up.\n";
