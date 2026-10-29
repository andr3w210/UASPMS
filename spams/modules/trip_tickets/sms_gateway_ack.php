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
request_schema_enforce([
    'action' => ['type' => 'enum', 'enum' => ['ack'], 'required' => true],
    'job_id' => ['type' => 'int', 'min' => 1, 'required' => true],
    'lease_token' => ['type' => 'string', 'min' => 64, 'max' => 64, 'pattern' => '/^[a-f0-9]{64}$/', 'required' => true],
    'status' => ['type' => 'enum', 'enum' => ['sent', 'failed'], 'required' => true],
    'result_message' => ['type' => 'string', 'max' => 255],
], null, true);
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
$jobId = (int) $_POST['job_id'];
$leaseHash = hash('sha256', (string) $_POST['lease_token']);
$status = (string) $_POST['status'];
$result = trim((string) ($_POST['result_message'] ?? ''));
$stmt = $tripDb->prepare("UPDATE trip_ticket_sms_queue SET status = ?, result_message = NULLIF(?, ''), sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE NULL END, lease_token_hash = NULL, lease_expires_at = NULL WHERE id = ? AND status = 'sending' AND lease_token_hash = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to prepare SMS receipt.']);
    exit;
}
$stmt->bind_param('sssis', $status, $result, $status, $jobId, $leaseHash);
$ok = $stmt->execute() && $stmt->affected_rows === 1;
$stmt->close();
if (!$ok) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'SMS job lease is invalid or expired.']);
    exit;
}
echo json_encode(['ok' => true]);
