<?php
require_once __DIR__ . '/../app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}
$recipients = po_notification_sms_recipients($db);
if (!$recipients) {
    fwrite(STDERR, "No internal SMS recipient is configured.\n");
    exit(1);
}
$recipient = $recipients[0];
if (!po_notification_send_sms($recipient, 'University of Antique Supply and Property Management System notification channel check.')) {
    fwrite(STDERR, "SMS test could not be sent.\n");
    exit(1);
}
echo 'SMS test sent to ' . $recipient . PHP_EOL;
