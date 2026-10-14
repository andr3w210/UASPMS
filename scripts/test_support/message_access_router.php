<?php
// Local-only router used by test_message_access_isolation.php to impersonate
// fixture user IDs without requiring real browser sessions or credentials.
$testSessionPath = __DIR__ . '/../.tmp_access_test_sessions';
session_save_path($testSessionPath);
ini_set('session.use_strict_mode', '0');

$fixtureUserId = (int) ($_SERVER['HTTP_X_TEST_USER'] ?? 0);
$fixtureCsrf = (string) ($_SERVER['HTTP_X_TEST_CSRF'] ?? '');
if ($fixtureUserId <= 0 || $fixtureCsrf === '') {
    http_response_code(404);
    exit;
}

$cookieSessionId = (string) ($_COOKIE[session_name()] ?? '');
if ($cookieSessionId !== '') session_id($cookieSessionId);
if (!session_start()) {
    http_response_code(500);
    exit;
}
$_SESSION = [
    'user_id' => $fixtureUserId,
    'user_role' => 'User',
    'role_name' => 'User',
    'csrf_token' => $fixtureCsrf,
];
session_write_close();

$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$webRoot = realpath(__DIR__ . '/../../spams');
$requestedScript = $webRoot ? realpath($webRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($requestPath, '/'))) : false;
if (!$webRoot || !$requestedScript || !str_starts_with($requestedScript, $webRoot . DIRECTORY_SEPARATOR) || !is_file($requestedScript) || strtolower(pathinfo($requestedScript, PATHINFO_EXTENSION)) !== 'php') {
    http_response_code(404);
    exit;
}

$_SERVER['SCRIPT_NAME'] = $requestPath;
$_SERVER['SCRIPT_FILENAME'] = $requestedScript;
require $requestedScript;
