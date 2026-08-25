<?php
require_once __DIR__ . '/../app/config/init.php';

$db = db();
if (!$db) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}
echo 'SMS enabled: ' . get_system_setting($db, 'po_sms_notifications_enabled', '0') . PHP_EOL;
echo 'Configured internal SMS recipients: ' . implode(', ', po_notification_sms_recipients($db)) . PHP_EOL;
echo 'SMS provider: ' . spams_env('SMS_PROVIDER', 'unisms') . PHP_EOL;
echo 'UniSMS secret configured: ' . (spams_env('UNISMS_API_SECRET', '') !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'UniSMS sender ID configured: ' . (spams_env('UNISMS_SENDER_ID', '') !== '' ? 'yes' : 'no') . PHP_EOL;
