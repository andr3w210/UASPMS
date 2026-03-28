<?php
require_once __DIR__ . '/../../app/config/init.php';
require_login();

$uploadDir = defined('UPLOADS_DIR') ? UPLOADS_DIR : null;
$logFile = $uploadDir ? rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'scan_proxy_debug.log' : null;

$info = [
    'ts' => date('c'),
    'upload_dir' => $uploadDir,
    'logFile' => $logFile,
    'cwd' => getcwd(),
    'remote' => $_SERVER['REMOTE_ADDR'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'file_exists' => $logFile ? file_exists($logFile) : false,
    'is_writable' => $logFile ? is_writable(dirname($logFile)) : false,
];

@file_put_contents($logFile ?: (__DIR__ . '/log_test_local.txt'), json_encode(['entry'=> $info]) . PHP_EOL, FILE_APPEND | LOCK_EX);
echo json_encode($info);
