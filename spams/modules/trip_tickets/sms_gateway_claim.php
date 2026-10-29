<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
require_role('Administrator', 'Transport Officer');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
request_schema_enforce(['action' => ['type' => 'enum', 'enum' => ['claim'], 'required' => true]], null, true);
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$tripDb = trip_db();
if (!$tripDb) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Trip ticket database unavailable.']);
    exit;
}

try {
    $tripDb->begin_transaction();
    $tripDb->query("UPDATE trip_ticket_sms_queue SET status = 'failed', result_message = 'Delivery was not confirmed after three attempts.', lease_token_hash = NULL, lease_expires_at = NULL WHERE status = 'sending' AND lease_expires_at < NOW() AND attempts >= 3");
    $stmt = $tripDb->prepare("SELECT id, recipient_phone, message_body FROM trip_ticket_sms_queue WHERE attempts < 3 AND (status = 'pending' OR (status = 'sending' AND lease_expires_at < NOW())) ORDER BY id ASC LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare SMS queue read.');
    }
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$job) {
        $tripDb->commit();
        echo json_encode(['ok' => true, 'job' => null]);
        exit;
    }

    $jobId = (int) $job['id'];
    $leaseToken = bin2hex(random_bytes(32));
    $leaseHash = hash('sha256', $leaseToken);
    $update = $tripDb->prepare("UPDATE trip_ticket_sms_queue SET status = 'sending', attempts = attempts + 1, lease_token_hash = ?, lease_expires_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE), result_message = NULL WHERE id = ?");
    if (!$update) {
        throw new RuntimeException('Unable to prepare SMS queue claim.');
    }
    $update->bind_param('si', $leaseHash, $jobId);
    if (!$update->execute()) {
        throw new RuntimeException('Unable to claim SMS queue item.');
    }
    $update->close();
    $tripDb->commit();

    echo json_encode(['ok' => true, 'job' => [
        'id' => $jobId,
        'phone' => (string) $job['recipient_phone'],
        'message' => (string) $job['message_body'],
        'lease_token' => $leaseToken,
    ]]);
} catch (Throwable $e) {
    $tripDb->rollback();
    error_log('Trip SMS gateway claim failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to claim an SMS right now.']);
}
