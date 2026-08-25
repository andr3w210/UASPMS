<?php
require_once __DIR__ . '/../app/config/init.php';
$db = db();
if (!$db) { fwrite(STDERR, "Database connection failed.\n"); exit(1); }
$forceWeekly = in_array('--force-weekly', $argv ?? [], true);
$forceResend = in_array('--force-resend', $argv ?? [], true);
$result = po_notification_run($db, $forceWeekly, $forceResend);
echo 'Sent: ' . $result['sent'] . '; skipped: ' . $result['skipped'] . PHP_EOL;
foreach ($result['errors'] as $error) fwrite(STDERR, $error . PHP_EOL);
exit($result['errors'] ? 1 : 0);
