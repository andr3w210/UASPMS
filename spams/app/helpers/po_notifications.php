<?php

function po_notification_recipients(mysqli $db): array
{
    $configured = get_system_setting($db, 'po_notification_recipients', 'supply@antiquespride.edu.ph');
    $emails = preg_split('/[\s,;]+/', $configured) ?: [];
    $valid = [];
    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $valid[$email] = true;
    }
    return array_keys($valid);
}

function po_notification_mobile_number(string $number): string
{
    $number = preg_replace('/\D+/', '', $number) ?? '';
    if (str_starts_with($number, '09') && strlen($number) === 11) $number = '63' . substr($number, 1);
    return preg_match('/^639\d{9}$/', $number) ? $number : '';
}

function po_notification_sms_recipients(mysqli $db): array
{
    $configured = get_system_setting($db, 'po_sms_recipients', '');
    $numbers = preg_split('/[\s,;]+/', $configured) ?: [];
    $valid = [];
    foreach ($numbers as $number) {
        $number = po_notification_mobile_number((string) $number);
        if ($number !== '') $valid[$number] = true;
    }
    return array_keys($valid);
}

function po_notification_send_sms(string $number, string $message): bool
{
    $number = po_notification_mobile_number($number);
    $provider = strtolower(trim((string) spams_env('SMS_PROVIDER', 'unisms')));
    if ($provider === 'unisms') {
        $secret = trim((string) spams_env('UNISMS_API_SECRET', ''));
        $senderId = trim((string) spams_env('UNISMS_SENDER_ID', 'UniSMS'));
        $endpoint = trim((string) spams_env('UNISMS_API_URL', 'https://unismsapi.com/api/sms'));
        if ($number === '' || $secret === '' || $senderId === '' || $endpoint === '') return false;
        $payload = json_encode(['recipient' => '+' . $number, 'content' => $message, 'sender_id' => $senderId]);
        if ($payload === false) return false;
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAuthorization: Basic " . base64_encode($secret . ':') . "\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true]]);
        $response = @file_get_contents($endpoint, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s2\d\d\s/', $statusLine)) return true;
        error_log('PO notification UniSMS error: ' . trim((string) $response));
        return false;
    }

    $apiCode = trim((string) spams_env('ITEXMO_API_CODE', ''));
    $password = (string) spams_env('ITEXMO_API_PASSWORD', '');
    $endpoint = trim((string) spams_env('ITEXMO_API_URL', 'https://www.itexmo.com/php_api/api.php'));
    if ($number === '' || $apiCode === '' || $password === '' || $endpoint === '') return false;
    $payload = http_build_query(['1' => $number, '2' => $message, '3' => $apiCode, 'passwd' => $password]);
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $payload, 'timeout' => 20, 'ignore_errors' => true]]);
    $response = @file_get_contents($endpoint, false, $context);
    if (trim((string) $response) === '0') return true;
    error_log('PO notification iTexMo SMS error: ' . trim((string) $response));
    return false;
}

function po_notification_send_sms_to_recipients(mysqli $db, int $poId, string $type, string $period, array $recipients, string $message, array &$result): void
{
    foreach (array_unique($recipients) as $number) {
        if (po_notification_was_sent($db, $poId, $type, $period, $number)) { $result['skipped']++; continue; }
        if (po_notification_send_sms($number, $message)) { po_notification_log_sent($db, $poId, $type, $period, $number); $result['sent']++; }
        else $result['errors'][] = 'Could not send SMS to ' . $number . '.';
    }
}

function po_notification_send(string $to, string $subject, string $html): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $host = trim((string) spams_env('MAIL_HOST', ''));
    $username = trim((string) spams_env('MAIL_USERNAME', ''));
    $password = (string) spams_env('MAIL_PASSWORD', '');
    $from = trim((string) spams_env('MAIL_FROM', $username));
    $fromName = trim((string) spams_env('MAIL_FROM_NAME', 'SPAMS'));
    $port = max(1, (int) spams_env('MAIL_PORT', '587'));
    $encryption = strtolower(trim((string) spams_env('MAIL_ENCRYPTION', 'tls')));
    if ($host === '' || $username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;

    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $error, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $message = 'PO notification SMTP connection failed: ' . $error;
        error_log($message);
        if (PHP_SAPI === 'cli') fwrite(STDERR, $message . PHP_EOL);
        return false;
    }
    stream_set_timeout($socket, 20);
    $read = static function () use ($socket): string { $response = ''; do { $line = fgets($socket, 515); if ($line === false) break; $response .= $line; } while (isset($line[3]) && $line[3] === '-'); return $response; };
    $command = static function (string $line) use ($socket, $read): string { fwrite($socket, $line . "\r\n"); return $read(); };
    $ok = static function (string $response): bool { return preg_match('/^(2|3)\\d{2}/m', $response) === 1; };
    try {
        if (!$ok($read()) || !$ok($command('EHLO localhost'))) throw new RuntimeException('SMTP greeting failed.');
        if ($encryption === 'tls') {
            if (!$ok($command('STARTTLS'))) throw new RuntimeException('SMTP STARTTLS failed.');
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS negotiation failed.');
            if (!$ok($command('EHLO localhost'))) throw new RuntimeException('SMTP TLS greeting failed.');
        }
        $authResponse = $command('AUTH LOGIN');
        if (!$ok($authResponse)) throw new RuntimeException('SMTP authentication failed: ' . trim($authResponse));
        $userResponse = $command(base64_encode($username));
        if (!$ok($userResponse)) throw new RuntimeException('SMTP username rejected: ' . trim($userResponse));
        $passwordResponse = $command(base64_encode($password));
        if (!$ok($passwordResponse)) throw new RuntimeException('SMTP password rejected: ' . trim($passwordResponse));
        if (!$ok($command('MAIL FROM:<' . $from . '>')) || !$ok($command('RCPT TO:<' . $to . '>')) || !$ok($command('DATA'))) throw new RuntimeException('SMTP envelope failed.');
        $safeSubject = str_replace(["\r", "\n"], '', $subject);
        $safeName = str_replace(["\r", "\n", '"'], '', $fromName);
        $message = 'From: "' . $safeName . '" <' . $from . ">\r\n" . 'To: <' . $to . ">\r\n" . 'Subject: ' . $safeSubject . "\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . str_replace("\n.", "\n..", $html) . "\r\n.";
        if (!$ok($command($message))) throw new RuntimeException('SMTP message delivery failed.');
        $command('QUIT'); fclose($socket); return true;
    } catch (Throwable $e) {
        error_log('PO notification SMTP error: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') fwrite(STDERR, 'PO notification SMTP error: ' . $e->getMessage() . PHP_EOL);
        @fclose($socket); return false;
    }
}

function po_notification_was_sent(mysqli $db, ?int $poId, string $type, string $period, string $email): bool
{
    $stmt = $db->prepare('SELECT id FROM purchase_order_email_notifications WHERE purchase_order_id <=> ? AND notification_type = ? AND period_key = ? AND recipient_email = ? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('isss', $poId, $type, $period, $email);
    $stmt->execute();
    $sent = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $sent;
}

function po_notification_log_sent(mysqli $db, ?int $poId, string $type, string $period, string $email): void
{
    $stmt = $db->prepare('INSERT IGNORE INTO purchase_order_email_notifications (purchase_order_id, notification_type, period_key, recipient_email) VALUES (?, ?, ?, ?)');
    if ($stmt) { $stmt->bind_param('isss', $poId, $type, $period, $email); $stmt->execute(); $stmt->close(); }
}

function po_notification_overdue_rows(mysqli $db): array
{
    $sql = "SELECT po.id, po.po_number, po.system_reference, po.expected_delivery_date, po.total_amount, COALESCE(remaining.undelivered_amount, 0) AS undelivered_amount, s.supplier_name, s.email AS supplier_email, s.contact_no
            FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id
            LEFT JOIN (
                SELECT poi.purchase_order_id,
                       SUM(GREATEST(poi.quantity - COALESCE(received.quantities_received, 0), 0) * poi.unit_cost) AS undelivered_amount
                FROM purchase_order_items poi
                LEFT JOIN (
                    SELECT ri.purchase_order_item_id, SUM(COALESCE(ri.quantity_accepted, 0)) AS quantities_received
                    FROM receiving_items ri
                    INNER JOIN receivings r ON r.id = ri.receiving_id
                    WHERE r.status != 'cancelled'
                    GROUP BY ri.purchase_order_item_id
                ) received ON received.purchase_order_item_id = poi.id
                GROUP BY poi.purchase_order_id
            ) remaining ON remaining.purchase_order_id = po.id
            WHERE po.status IN ('encoded', 'partial') AND po.expected_delivery_date < CURDATE()
            ORDER BY po.expected_delivery_date ASC";
    $result = $db->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function po_notification_due_soon_rows(mysqli $db): array
{
    $sql = "SELECT po.id, po.po_number, po.system_reference, po.expected_delivery_date, po.total_amount, s.supplier_name, s.email AS supplier_email, s.contact_no
            FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id
            WHERE po.status IN ('encoded', 'partial') AND po.expected_delivery_date = DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY po.expected_delivery_date ASC";
    $result = $db->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function po_notification_run(mysqli $db, bool $forceWeekly = false, bool $forceResend = false): array
{
    $result = ['sent' => 0, 'skipped' => 0, 'errors' => []];
    if (!schema_has_table($db, 'purchase_order_email_notifications')) {
        $result['errors'][] = 'Notification table is missing. Apply migration 112.';
        return $result;
    }
    if (get_system_setting($db, 'po_notifications_enabled', '1') !== '1') return $result;
    $today = date('Y-m-d');
    $weekKey = date('o-\\WW');
    $weeklyDay = (int) get_system_setting($db, 'po_notification_weekday', '1');
    $isWeeklyDay = $forceWeekly || (int) date('N') === $weeklyDay;
    $internal = po_notification_recipients($db);

    foreach (po_notification_due_soon_rows($db) as $po) {
        $poId = (int) $po['id'];
        $recipients = $internal;
        if (get_system_setting($db, 'po_notify_supplier', '1') === '1' && filter_var($po['supplier_email'] ?? '', FILTER_VALIDATE_EMAIL)) $recipients[] = strtolower($po['supplier_email']);
        $recipients = array_unique($recipients);
        $period = (string) $po['expected_delivery_date'];
        $subject = '[SPAMS] Delivery due in 3 days: ' . ($po['po_number'] ?: $po['system_reference']);
        $body = '<p>The following purchase order is due for delivery in <strong>3 days</strong>.</p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Expected Delivery</th><th>Total Amount</th></tr><tr><td>' . h($po['po_number'] ?: $po['system_reference']) . '</td><td>' . h($po['supplier_name']) . '</td><td>' . h($po['expected_delivery_date']) . '</td><td>₱' . number_format((float) $po['total_amount'], 2) . '</td></tr></table>';
        foreach ($recipients as $email) {
            if (!$forceResend && po_notification_was_sent($db, $poId, 'due_soon_3_days', $period, $email)) { $result['skipped']++; continue; }
            if (po_notification_send($email, $subject, $body)) { po_notification_log_sent($db, $poId, 'due_soon_3_days', $period, $email); $result['sent']++; }
            else $result['errors'][] = 'Could not send to ' . $email . '.';
        }
        if (get_system_setting($db, 'po_sms_notifications_enabled', '0') === '1') {
            $smsRecipients = po_notification_sms_recipients($db);
            if (get_system_setting($db, 'po_sms_notify_supplier', '1') === '1') {
                $supplierNumber = po_notification_mobile_number((string) ($po['contact_no'] ?? ''));
                if ($supplierNumber !== '') $smsRecipients[] = $supplierNumber;
            }
            $sms = 'SPAMS: PO ' . ($po['po_number'] ?: $po['system_reference']) . ' from ' . $po['supplier_name'] . ' is due on ' . $po['expected_delivery_date'] . ' (3 days).';
            po_notification_send_sms_to_recipients($db, $poId, 'due_soon_3_days_sms', $period, $smsRecipients, $sms, $result);
        }
    }

    $overdueRows = po_notification_overdue_rows($db);
    foreach ($overdueRows as $po) {
        $poId = (int) $po['id'];
        $days = max(1, (int) floor((strtotime($today) - strtotime((string) $po['expected_delivery_date'])) / 86400));
        $recipients = $internal;
        if (get_system_setting($db, 'po_notify_supplier', '1') === '1' && filter_var($po['supplier_email'] ?? '', FILTER_VALIDATE_EMAIL)) $recipients[] = strtolower($po['supplier_email']);
        $recipients = array_unique($recipients);
        $type = $days === 3 ? 'overdue_3_days' : 'overdue_weekly';
        if ($type === 'overdue_weekly' && !$isWeeklyDay) continue;
        $period = $type === 'overdue_3_days' ? $today : $weekKey;
        $subject = '[UASPMS] Overdue delivery: ' . ($po['po_number'] ?: $po['system_reference']);
        $body = '<p>Delivery for the following purchase order is overdue by <strong>' . $days . ' day(s)</strong>.</p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Expected Delivery</th><th>PO Total</th><th>Undelivered Amount</th></tr><tr><td>' . h($po['po_number'] ?: $po['system_reference']) . '</td><td>' . h($po['supplier_name']) . '</td><td>' . h($po['expected_delivery_date']) . '</td><td>₱' . number_format((float) $po['total_amount'], 2) . '</td><td>₱' . number_format((float) $po['undelivered_amount'], 2) . '</td></tr></table><p>Please coordinate delivery or update the PO.</p>';
        foreach ($recipients as $email) {
            if (!$forceResend && po_notification_was_sent($db, $poId, $type, $period, $email)) { $result['skipped']++; continue; }
            if (po_notification_send($email, $subject, $body)) { po_notification_log_sent($db, $poId, $type, $period, $email); $result['sent']++; }
            else $result['errors'][] = 'Could not send to ' . $email . '.';
        }
        if (get_system_setting($db, 'po_sms_notifications_enabled', '0') === '1') {
            $smsRecipients = po_notification_sms_recipients($db);
            if (get_system_setting($db, 'po_sms_notify_supplier', '1') === '1') {
                $supplierNumber = po_notification_mobile_number((string) ($po['contact_no'] ?? ''));
                if ($supplierNumber !== '') $smsRecipients[] = $supplierNumber;
            }
            $sms = 'SPAMS: PO ' . ($po['po_number'] ?: $po['system_reference']) . ' from ' . $po['supplier_name'] . ' is overdue by ' . $days . ' day(s). Check email for details.';
            po_notification_send_sms_to_recipients($db, $poId, $type . '_sms', $period, $smsRecipients, $sms, $result);
        }
    }
    if ($isWeeklyDay && get_system_setting($db, 'po_delivery_summary_enabled', '1') === '1') {
        $poTotalSelect = schema_has_column($db, 'purchase_orders', 'document_total_amount')
            ? 'COALESCE(NULLIF(po.document_total_amount, 0), po.total_amount)'
            : 'po.total_amount';
        $sql = "SELECT po.po_number, po.system_reference, s.supplier_name, {$poTotalSelect} AS po_total_amount, MAX(r.received_date) AS completed_date
                FROM purchase_orders po
                INNER JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN receivings r ON r.purchase_order_id = po.id AND r.status IN ('completed', 'partial')
                WHERE po.status = 'completed'
                GROUP BY po.id, po.po_number, po.system_reference, s.supplier_name, po.total_amount" . (schema_has_column($db, 'purchase_orders', 'document_total_amount') ? ', po.document_total_amount' : '') . "
                ORDER BY completed_date DESC, po.id DESC";
        $rows = ($query = $db->query($sql)) ? $query->fetch_all(MYSQLI_ASSOC) : [];
        if ($rows || $overdueRows) {
            $body = '';
            if ($overdueRows) {
                $body .= '<p><strong>Overdue purchase orders requiring follow-up:</strong></p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Expected Delivery</th><th>PO Total</th><th>Undelivered Amount</th></tr>';
                foreach ($overdueRows as $row) $body .= '<tr><td>' . h($row['po_number'] ?: $row['system_reference']) . '</td><td>' . h($row['supplier_name']) . '</td><td>' . h($row['expected_delivery_date']) . '</td><td>₱' . number_format((float) $row['total_amount'], 2) . '</td><td>₱' . number_format((float) $row['undelivered_amount'], 2) . '</td></tr>';
                $body .= '</table>';
            }
            if ($rows) {
                $body .= '<p><strong>Completed purchase orders:</strong></p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Date Completed / Last Delivery</th><th>PO Total</th></tr>';
                foreach ($rows as $row) $body .= '<tr><td>' . h($row['po_number'] ?: $row['system_reference']) . '</td><td>' . h($row['supplier_name']) . '</td><td>' . h($row['completed_date'] ?: '—') . '</td><td>₱' . number_format((float) $row['po_total_amount'], 2) . '</td></tr>';
                $body .= '</table>';
            }
            foreach ($internal as $email) {
                if (($forceResend || !po_notification_was_sent($db, null, 'delivery_summary', $weekKey, $email)) && po_notification_send($email, '[SPAMS] Weekly purchase order delivery status', $body)) { po_notification_log_sent($db, null, 'delivery_summary', $weekKey, $email); $result['sent']++; }
            }
        }
    }
    return $result;
}
