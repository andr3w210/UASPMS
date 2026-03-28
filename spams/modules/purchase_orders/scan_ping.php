<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$logFile = defined('UPLOADS_DIR') ? rtrim(UPLOADS_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'scan_proxy_debug.log' : null;
$entry = [
    'ts' => date('c'),
    'client_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'get' => $_GET,
    'post' => $_POST,
];
if ($logFile) {
    @file_put_contents($logFile, json_encode($entry) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

echo json_encode(['ok' => true, 'message' => 'scan_ping received', 'method' => $_SERVER['REQUEST_METHOD'] ?? '']);
