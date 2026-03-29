<?php

function upload_url(?string $relativePath): string
{
    $clean = trim((string) $relativePath);
    if ($clean === '') {
        return '';
    }

    return base_url('uploads/' . ltrim(str_replace('\\', '/', $clean), '/'));
}

function upload_absolute_path(?string $relativePath): string
{
    $clean = trim((string) $relativePath);
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
    $clean = ltrim($clean, DIRECTORY_SEPARATOR);

    return UPLOADS_DIR . $clean;
}

function ensure_upload_directory(string $relativeDirectory): ?string
{
    $relativeDirectory = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDirectory), DIRECTORY_SEPARATOR);
    if ($relativeDirectory === '') {
        return null;
    }

    $absoluteDirectory = UPLOADS_DIR . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
        return null;
    }

    return $absoluteDirectory;
}

function store_uploaded_image(array $file, string $relativeDirectory, array &$errors, int $maxBytes = 5242880): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload failed. Please try again.';
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $errors[] = 'Uploaded file could not be verified.';
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

    $uploadsRoot = realpath(UPLOADS_DIR);
    $realPath = realpath($absolutePath);
    if ($uploadsRoot === false || $realPath === false) {
        return;
    }

    if (strpos($realPath, $uploadsRoot) !== 0) {
        return;
    }

    @unlink($realPath);
}
