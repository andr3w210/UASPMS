<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'Purchase Order Email Notifications';
$flash = get_flash();
$errors = [];
$defaults = ['po_notifications_enabled' => '1', 'po_notification_recipients' => 'supply@antiquespride.edu.ph', 'po_notify_supplier' => '1', 'po_notification_weekday' => '1', 'po_delivery_summary_enabled' => '1', 'po_sms_notifications_enabled' => '0', 'po_sms_recipients' => '', 'po_sms_notify_supplier' => '1'];
$settings = $defaults;
if ($db) foreach ($defaults as $key => $default) $settings[$key] = get_system_setting($db, $key, $default);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) $errors[] = 'Invalid CSRF token.';
    $action = (string) ($_POST['action'] ?? 'save_settings');

    if (!$errors && $action === 'send_overdue_sms') {
        $testRecipients = po_notification_sms_recipients($db);
        if (!$testRecipients) {
            $errors[] = 'Save at least one internal Philippine mobile number before sending overdue SMS alerts.';
        } elseif (get_system_setting($db, 'po_sms_notifications_enabled', '0') !== '1') {
            $errors[] = 'Enable SMS delivery alerts before sending overdue SMS alerts.';
        } else {
            $overdueRows = po_notification_overdue_rows($db);
            if (!$overdueRows) {
                $errors[] = 'There are no currently overdue purchase orders to send by SMS.';
            } else {
                $sent = 0;
                $failed = 0;
                foreach ($overdueRows as $po) {
                    $days = max(1, (int) floor((strtotime(date('Y-m-d')) - strtotime((string) $po['expected_delivery_date'])) / 86400));
                    $recipients = $testRecipients;
                    if (get_system_setting($db, 'po_sms_notify_supplier', '1') === '1') {
                        $supplierNumber = po_notification_mobile_number((string) ($po['contact_no'] ?? ''));
                        if ($supplierNumber !== '') $recipients[] = $supplierNumber;
                    }
                    $message = 'SPAMS: PO ' . ($po['po_number'] ?: $po['system_reference']) . ' from ' . $po['supplier_name'] . ' is overdue by ' . $days . ' day(s). Check email for details.';
                    foreach (array_unique($recipients) as $recipient) {
                        if (po_notification_send_sms($recipient, $message)) $sent++;
                        else $failed++;
                    }
                }
                if ($failed > 0) $errors[] = $sent . ' overdue SMS alert(s) sent; ' . $failed . ' could not be sent. Check UniSMS configuration, credits, sender approval, and recipient numbers.';
                else { set_flash('success', $sent . ' overdue PO SMS alert(s) sent successfully.'); redirect('modules/settings/purchase_order_notifications.php'); }
            }
        }
    }

    if (!$errors && $action === 'send_email_test') {
        $testRecipients = po_notification_recipients($db);
        if (!$testRecipients) {
            $errors[] = 'Save at least one internal recipient email address before sending the weekly email.';
        } else {
            $overdueRows = po_notification_overdue_rows($db);
            $poTotalSelect = schema_has_column($db, 'purchase_orders', 'document_total_amount')
                ? 'COALESCE(NULLIF(po.document_total_amount, 0), po.total_amount)'
                : 'po.total_amount';
            $completedSql = "SELECT po.po_number, po.system_reference, s.supplier_name, {$poTotalSelect} AS po_total_amount, MAX(r.received_date) AS completed_date
                FROM purchase_orders po
                INNER JOIN suppliers s ON s.id = po.supplier_id
                LEFT JOIN receivings r ON r.purchase_order_id = po.id AND r.status IN ('completed', 'partial')
                WHERE po.status = 'completed'
                GROUP BY po.id, po.po_number, po.system_reference, s.supplier_name, po.total_amount" . (schema_has_column($db, 'purchase_orders', 'document_total_amount') ? ', po.document_total_amount' : '') . "
                ORDER BY completed_date DESC, po.id DESC";
            $completedRows = ($completedResult = $db->query($completedSql)) ? $completedResult->fetch_all(MYSQLI_ASSOC) : [];
            $body = '<p>Manual weekly purchase order delivery status summary.</p>';
            if ($overdueRows) {
                $body .= '<p><strong>Overdue purchase orders requiring follow-up:</strong></p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Expected Delivery</th><th>PO Total</th><th>Undelivered Amount</th></tr>';
                foreach ($overdueRows as $row) $body .= '<tr><td>' . h($row['po_number'] ?: $row['system_reference']) . '</td><td>' . h($row['supplier_name']) . '</td><td>' . h($row['expected_delivery_date']) . '</td><td>₱' . number_format((float) $row['total_amount'], 2) . '</td><td>₱' . number_format((float) $row['undelivered_amount'], 2) . '</td></tr>';
                $body .= '</table>';
            }
            if ($completedRows) {
                $body .= '<p><strong>Completed purchase orders:</strong></p><table border="1" cellpadding="7" cellspacing="0"><tr><th>PO Number</th><th>Supplier</th><th>Date Completed / Last Delivery</th><th>PO Total</th></tr>';
                foreach ($completedRows as $row) $body .= '<tr><td>' . h($row['po_number'] ?: $row['system_reference']) . '</td><td>' . h($row['supplier_name']) . '</td><td>' . h($row['completed_date'] ?: '—') . '</td><td>₱' . number_format((float) $row['po_total_amount'], 2) . '</td></tr>';
                $body .= '</table>';
            }
            if (!$overdueRows && !$completedRows) $body .= '<p>No completed or overdue purchase orders are currently available.</p>';
            $sent = 0;
            foreach ($testRecipients as $recipient) if (po_notification_send($recipient, '[SPAMS] Weekly purchase order delivery status', $body)) $sent++;
            if ($sent <= 0) $errors[] = 'The weekly email could not be sent. Check the SMTP settings in spams/.env.';
            else { set_flash('success', 'Weekly purchase order email sent to ' . $sent . ' internal recipient(s).'); redirect('modules/settings/purchase_order_notifications.php'); }
        }
    }

    if (!in_array($action, ['send_overdue_sms', 'send_email_test'], true)) {
    $settings['po_notifications_enabled'] = isset($_POST['po_notifications_enabled']) ? '1' : '0';
    $settings['po_notification_recipients'] = trim((string) ($_POST['po_notification_recipients'] ?? ''));
    $settings['po_notify_supplier'] = isset($_POST['po_notify_supplier']) ? '1' : '0';
    $settings['po_notification_weekday'] = (string) max(1, min(7, (int) ($_POST['po_notification_weekday'] ?? 1)));
    $settings['po_delivery_summary_enabled'] = isset($_POST['po_delivery_summary_enabled']) ? '1' : '0';
    $settings['po_sms_notifications_enabled'] = isset($_POST['po_sms_notifications_enabled']) ? '1' : '0';
    $settings['po_sms_recipients'] = trim((string) ($_POST['po_sms_recipients'] ?? ''));
    $settings['po_sms_notify_supplier'] = isset($_POST['po_sms_notify_supplier']) ? '1' : '0';
    $validRecipients = array_filter(preg_split('/[\s,;]+/', $settings['po_notification_recipients']) ?: [], static function ($email): bool {
        return filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) !== false;
    });
    if (!$errors && !$validRecipients) $errors[] = 'Enter at least one valid internal recipient email address.';
    $validSmsRecipients = array_filter(preg_split('/[\s,;]+/', $settings['po_sms_recipients']) ?: [], static function ($number): bool {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';
        return (bool) preg_match('/^(?:09\d{9}|639\d{9})$/', $digits);
    });
    if (!$errors && $settings['po_sms_notifications_enabled'] === '1' && !$validSmsRecipients) $errors[] = 'Enter at least one valid internal Philippine mobile number for SMS notifications.';
    if (!$errors) {
        $stmt = $db->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)');
        if (!$stmt) $errors[] = 'Unable to save notification settings.';
        else {
            $userId = current_user_id(); $ok = true;
            foreach ($settings as $key => $value) { $stmt->bind_param('ssi', $key, $value, $userId); $ok = $stmt->execute() && $ok; }
            $stmt->close();
            if (!$ok) $errors[] = 'Unable to save notification settings.';
            else { spams_cache_forget_prefix('system_setting:'); set_flash('success', 'Purchase order email notification settings saved.'); redirect('modules/settings/purchase_order_notifications.php'); }
        }
    }
    }
}

require_once __DIR__ . '/../../includes/header.php'; require_once __DIR__ . '/../../includes/sidebar.php'; require_once __DIR__ . '/../../includes/topbar.php';
?>
<section class="section"><div class="row justify-content-center"><div class="col-xl-8">
<?php if ($flash): ?><div class="alert alert-success"><?php echo h($flash['message']); ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?php echo h($error); ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card shadow-sm border-0"><div class="card-body p-4 p-lg-5">
<div class="mb-4"><div class="text-uppercase small text-muted fw-semibold">Procurement</div><h4 class="mb-2">Purchase Order Email Notifications</h4><p class="text-muted mb-0">Send alerts 3 days before delivery and 3 days after an overdue delivery, with weekly reminders while incomplete.</p></div>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>"><input type="hidden" name="action" value="save_settings">
<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="po_notifications_enabled" name="po_notifications_enabled" <?php echo $settings['po_notifications_enabled'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="po_notifications_enabled">Enable overdue delivery notifications</label></div>
<div class="mb-3"><label class="form-label" for="po_notification_recipients">Internal recipient email addresses</label><input class="form-control" type="text" id="po_notification_recipients" name="po_notification_recipients" value="<?php echo h($settings['po_notification_recipients']); ?>" required><div class="form-text">Separate multiple email addresses with commas. Default: supply@antiquespride.edu.ph</div></div>
<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="po_notify_supplier" name="po_notify_supplier" <?php echo $settings['po_notify_supplier'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="po_notify_supplier">Also notify the supplier using the email on its supplier record</label></div>
<hr class="my-4"><div class="mb-3"><div class="text-uppercase small text-muted fw-semibold">SMS Notifications</div><div class="small text-muted">Requires the configured SMS provider credentials (UniSMS by default) in the server environment.</div></div>
<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="po_sms_notifications_enabled" name="po_sms_notifications_enabled" <?php echo $settings['po_sms_notifications_enabled'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="po_sms_notifications_enabled">Enable SMS delivery alerts</label></div>
<div class="mb-3"><label class="form-label" for="po_sms_recipients">Admin / Supply Officer mobile number(s)</label><input class="form-control" type="text" id="po_sms_recipients" name="po_sms_recipients" value="<?php echo h($settings['po_sms_recipients']); ?>" placeholder="09171234567, 09181234567"><div class="form-text">Separate multiple Philippine mobile numbers with commas. Use 09XXXXXXXXX or 639XXXXXXXXX.</div></div>
<div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="po_sms_notify_supplier" name="po_sms_notify_supplier" <?php echo $settings['po_sms_notify_supplier'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="po_sms_notify_supplier">Also send SMS to the supplier when its contact number is available</label></div>
<div class="row g-3"><div class="col-md-6"><label class="form-label" for="po_notification_weekday">Weekly reminder and summary day</label><select class="form-select" id="po_notification_weekday" name="po_notification_weekday"><?php foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $value=>$label): ?><option value="<?php echo $value; ?>" <?php echo (int)$settings['po_notification_weekday'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div><div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="po_delivery_summary_enabled" name="po_delivery_summary_enabled" <?php echo $settings['po_delivery_summary_enabled'] === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="po_delivery_summary_enabled">Email delivered-PO summary</label></div></div></div>
<div class="alert alert-info small mt-4 mb-3">The scheduled task runs daily. It sends alerts 3 days before the due date and 3 days after an overdue due date. The weekly email includes all completed POs and all currently overdue POs.</div>
<button class="btn btn-primary" type="submit">Save Notification Settings</button>
<button class="btn btn-outline-primary" type="submit" name="action" value="send_email_test">Manual Send Email</button>
<button class="btn btn-outline-success" type="submit" name="action" value="send_overdue_sms">Manual Send SMS</button>
<a class="btn btn-outline-secondary" href="<?php echo base_url('modules/settings/index.php'); ?>">Back to Settings</a>
</form></div></div></div></div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
