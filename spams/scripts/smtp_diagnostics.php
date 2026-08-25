<?php
require_once __DIR__ . '/../app/config/init.php';

foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_FROM', 'MAIL_FROM_NAME'] as $key) {
    echo $key . '=' . spams_env($key, '') . PHP_EOL;
}
echo 'MAIL_PASSWORD_LENGTH=' . strlen((string) spams_env('MAIL_PASSWORD', '')) . PHP_EOL;
