<?php

function normalize_uploads_root_path(?string $value): string
{
    $clean = trim((string) $value);
    if ($clean === '') {
        return 'uploads';
    }

    $clean = str_replace('\\', '/', $clean);
    $clean = str_replace('..', '', $clean);
    $clean = preg_replace('#/+#', '/', $clean) ?? $clean;
    $clean = trim($clean, '/');

    if ($clean === '' || !preg_match('/^[A-Za-z0-9_\/-]+$/', $clean)) {
        return 'uploads';
    }

    return $clean;
}

function normalize_uploads_absolute_path(?string $value): string
{
    $clean = trim((string) $value, " \t\n\r\0\x0B\"'");
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $clean)) {
        return '';
    }

    return rtrim($clean, "\\/");
}

function uploads_root_configuration(): array
{
    static $cachedConfig = null;
    if ($cachedConfig !== null) {
        return $cachedConfig;
    }

    $config = [
        'mode' => 'relative',
        'relative' => 'uploads',
        'absolute' => '',
        'public_url' => '',
    ];

    if (function_exists('db')) {
        $db = db();
        if ($db && function_exists('get_system_setting')) {
            $savedMode = trim((string) get_system_setting($db, 'uploads_root_mode', 'relative'));
            $config['mode'] = $savedMode === 'absolute' ? 'absolute' : 'relative';
            $config['relative'] = normalize_uploads_root_path(get_system_setting($db, 'uploads_root', 'uploads'));
            $config['absolute'] = normalize_uploads_absolute_path(get_system_setting($db, 'uploads_root_absolute', ''));
            $config['public_url'] = rtrim(trim((string) get_system_setting($db, 'uploads_root_public_url', '')), '/');
        }
    }

    if ($config['mode'] === 'absolute' && $config['absolute'] === '') {
        $config['mode'] = 'relative';
    }

    $cachedConfig = $config;
    return $cachedConfig;
}

function uploads_root_relative_path(): string
{
    return uploads_root_configuration()['relative'];
}

function uploads_base_directory(): string
{
    $config = uploads_root_configuration();
    if ($config['mode'] === 'absolute' && $config['absolute'] !== '') {
        return rtrim($config['absolute'], "\\/") . DIRECTORY_SEPARATOR;
    }

    $relativeRoot = str_replace('/', DIRECTORY_SEPARATOR, $config['relative']);
    return APP_ROOT . $relativeRoot . DIRECTORY_SEPARATOR;
}

function upload_url(?string $relativePath): string
{
    $clean = trim((string) $relativePath);
    if ($clean === '') {
        return '';
    }

    $clean = ltrim(str_replace('\\', '/', $clean), '/');
    $config = uploads_root_configuration();

    if ($config['mode'] === 'relative') {
        return base_url($config['relative'] . '/' . $clean);
    }

    if ($config['public_url'] !== '') {
        return rtrim($config['public_url'], '/') . '/' . $clean;
    }

    $baseReal = realpath(uploads_base_directory());
    $appReal = realpath(APP_ROOT);
    if ($baseReal !== false && $appReal !== false && strpos($baseReal, $appReal) === 0) {
        $suffix = trim(str_replace('\\', '/', substr($baseReal, strlen($appReal))), '/');
        $prefix = $suffix === '' ? '' : $suffix . '/';
        return base_url($prefix . $clean);
    }

    // Fallback keeps legacy behavior when absolute directory cannot be URL-mapped.
    return base_url('uploads/' . $clean);
}

function upload_absolute_path(?string $relativePath): string
{
    $clean = trim((string) $relativePath);
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
    $clean = ltrim($clean, DIRECTORY_SEPARATOR);

    return uploads_base_directory() . $clean;
}

function ensure_upload_directory(string $relativeDirectory): ?string
{
    $relativeDirectory = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory), DIRECTORY_SEPARATOR);
    if ($relativeDirectory === '') {
        return null;
    }

    $baseDirectory = uploads_base_directory();
    if (!is_dir($baseDirectory) && !mkdir($baseDirectory, 0775, true) && !is_dir($baseDirectory)) {
        return null;
    }

    $absoluteDirectory = $baseDirectory . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
        return null;
    }

    return $absoluteDirectory;
}

function store_uploaded_image(array $file, string $relativeDirectory, array &$errors, int $maxBytes = 5242880): ?string
{
    // Normalize error code to int — sometimes values arrive as strings from multipart implementations.
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed (code: ' . $errorCode . '). Please try again.';
        // Server-side debug log: capture full file array to inspect types and nested structures.
        $debug = [
            'time' => date('c'),
            'error_code' => $errorCode,
            'file_snapshot' => $file,
        ];
        @file_put_contents(APP_ROOT . 'logs/upload_debug.log', json_encode($debug, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errors[] = 'Uploaded file could not be verified.';
        @file_put_contents(APP_ROOT . 'logs/upload_debug.log', date('c') . " - Uploaded file not recognized: tmp_name=" . $tmpName . PHP_EOL, FILE_APPEND | LOCK_EX);
        return null;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        $errors[] = 'Image must be smaller than 5 MB.';
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mimeType])) {
        $errors[] = 'Only JPG, PNG, GIF, and WEBP images are allowed.';
        return null;
    }

    $directory = ensure_upload_directory($relativeDirectory);
    if ($directory === null) {
        $errors[] = 'Upload directory is not writable.';
        return null;
    }

    $extension = $allowed[$mimeType];
    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $absolutePath = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($tmpName, $absolutePath)) {
        $errors[] = 'Unable to save the uploaded image.';
        @file_put_contents(APP_ROOT . 'logs/upload_debug.log', date('c') . " - move_uploaded_file failed: tmp=" . $tmpName . " dst=" . $absolutePath . " - perms=" . substr(sprintf('%o', fileperms(uploads_base_directory())), -4) . PHP_EOL, FILE_APPEND | LOCK_EX);
        return null;
    }

    return trim(str_replace(['/', '\\'], '/', $relativeDirectory), '/') . '/' . $fileName;
}

function delete_uploaded_file(?string $relativePath): void
{
    $absolutePath = upload_absolute_path($relativePath);
    if ($absolutePath === '' || !is_file($absolutePath)) {
        return;
    }

    $uploadsRoot = realpath(uploads_base_directory());
    $realPath = realpath($absolutePath);
    if ($uploadsRoot === false || $realPath === false) {
        return;
    }

    if (strpos($realPath, $uploadsRoot) !== 0) {
        return;
    }

    @unlink($realPath);
}
