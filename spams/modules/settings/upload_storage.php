<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'Upload Storage';
$flash = get_flash();
$errors = [];
$uploadsRootMode = $db ? get_system_setting($db, 'uploads_root_mode', 'relative') : 'relative';
$uploadsRoot = $db ? get_system_setting($db, 'uploads_root', 'uploads') : 'uploads';
$uploadsRootAbsolute = $db ? get_system_setting($db, 'uploads_root_absolute', '') : '';
$uploadsRootPublicUrl = $db ? get_system_setting($db, 'uploads_root_public_url', '') : '';
$inventoryPhotoRoot = $db ? get_system_setting($db, 'inventory_photo_root', 'inventory_counts') : 'inventory_counts';
$copyExistingUploads = false;
$moveExistingUploads = false;
$pathTestResult = null;

function normalize_upload_mode(string $value): string
{
    $mode = strtolower(trim($value));
    return $mode === 'absolute' ? 'absolute' : 'relative';
}

function normalize_relative_folder(string $value, string $fallback): string
{
    $clean = trim($value);
    $clean = str_replace('\\', '/', $clean);
    $clean = str_replace('..', '', $clean);
    $clean = preg_replace('#/+#', '/', $clean) ?? $clean;
    $clean = trim($clean, '/');

    if ($clean === '' || !preg_match('/^[A-Za-z0-9_\/-]+$/', $clean)) {
        return $fallback;
    }

    return $clean;
}

function normalize_absolute_folder(string $value): string
{
    $clean = trim($value, " \t\n\r\0\x0B\"'");
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $clean);
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $clean)) {
        return '';
    }

    return rtrim($clean, "\\/");
}

function normalize_upload_public_url(string $value): string
{
    $clean = trim($value);
    if ($clean === '') {
        return '';
    }

    return rtrim($clean, '/');
}

function resolve_uploads_root_absolute_path(string $mode, string $relativeRoot, string $absoluteRoot): string
{
    if ($mode === 'absolute') {
        return rtrim($absoluteRoot, "\\/") . DIRECTORY_SEPARATOR;
    }

    return APP_ROOT . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot) . DIRECTORY_SEPARATOR;
}

function build_upload_preview_url(string $mode, string $relativeRoot, string $absoluteRoot, string $publicUrl, string $relativeFilePath): array
{
    $relativeFilePath = ltrim(str_replace('\\', '/', $relativeFilePath), '/');
    if ($mode === 'relative') {
        return [
            'url' => base_url(trim($relativeRoot, '/') . '/' . $relativeFilePath),
            'note' => '',
        ];
    }

    if ($publicUrl !== '') {
        return [
            'url' => rtrim($publicUrl, '/') . '/' . $relativeFilePath,
            'note' => '',
        ];
    }

    $baseReal = realpath(rtrim($absoluteRoot, "\\/") . DIRECTORY_SEPARATOR);
    $appReal = realpath(APP_ROOT);
    if ($baseReal !== false && $appReal !== false && strpos($baseReal, $appReal) === 0) {
        $suffix = trim(str_replace('\\', '/', substr($baseReal, strlen($appReal))), '/');
        $prefix = $suffix === '' ? '' : $suffix . '/';
        return [
            'url' => base_url($prefix . $relativeFilePath),
            'note' => 'Auto-mapped URL because absolute path is inside app root.',
        ];
    }

    return [
        'url' => '',
        'note' => 'No preview URL generated. Set Uploads Public Base URL for external absolute paths.',
    ];
}

function copy_directory_recursive(string $source, string $target): bool
{
    if (!is_dir($source)) {
        return true;
    }

    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        return false;
    }

    $items = scandir($source);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to = $target . DIRECTORY_SEPARATOR . $item;

        if (is_dir($from)) {
            if (!copy_directory_recursive($from, $to)) {
                return false;
            }
            continue;
        }

        if (!is_file($from)) {
            continue;
        }

        $parent = dirname($to);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            return false;
        }

        if (!copy($from, $to)) {
            return false;
        }
    }

    return true;
}

function delete_directory_contents_recursive(string $path): bool
{
    if (!is_dir($path)) {
        return true;
    }

    $items = scandir($path);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $currentPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_link($currentPath) || is_file($currentPath)) {
            if (!@unlink($currentPath)) {
                return false;
            }
            continue;
        }

        if (is_dir($currentPath)) {
            if (!delete_directory_contents_recursive($currentPath)) {
                return false;
            }
            if (!@rmdir($currentPath)) {
                return false;
            }
        }
    }

    return true;
}

$uploadsRootMode = normalize_upload_mode($uploadsRootMode);
$uploadsRoot = normalize_relative_folder($uploadsRoot, 'uploads');
$uploadsRootAbsolute = normalize_absolute_folder($uploadsRootAbsolute);
$uploadsRootPublicUrl = normalize_upload_public_url($uploadsRootPublicUrl);
$inventoryPhotoRoot = normalize_relative_folder($inventoryPhotoRoot, 'inventory_counts');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $formAction = trim((string) ($_POST['form_action'] ?? 'save'));
        $uploadsRootMode = normalize_upload_mode((string) ($_POST['uploads_root_mode'] ?? 'relative'));
        $uploadsRoot = normalize_relative_folder((string) ($_POST['uploads_root'] ?? 'uploads'), 'uploads');
        $uploadsRootAbsolute = normalize_absolute_folder((string) ($_POST['uploads_root_absolute'] ?? ''));
        $uploadsRootPublicUrl = normalize_upload_public_url((string) ($_POST['uploads_root_public_url'] ?? ''));
        $inventoryPhotoRoot = normalize_relative_folder((string) ($_POST['inventory_photo_root'] ?? 'inventory_counts'), 'inventory_counts');
        $copyExistingUploads = isset($_POST['copy_existing_uploads']) && (string) $_POST['copy_existing_uploads'] === '1';
        $moveExistingUploads = isset($_POST['move_existing_uploads']) && (string) $_POST['move_existing_uploads'] === '1';
        if ($moveExistingUploads) {
            $copyExistingUploads = true;
        }

        $previousUploadsRootMode = $db ? normalize_upload_mode(get_system_setting($db, 'uploads_root_mode', 'relative')) : 'relative';
        $previousUploadsRoot = $db ? normalize_relative_folder(get_system_setting($db, 'uploads_root', 'uploads'), 'uploads') : 'uploads';
        $previousUploadsRootAbsolute = $db ? normalize_absolute_folder(get_system_setting($db, 'uploads_root_absolute', '')) : '';

        if ($previousUploadsRootMode === 'absolute' && $previousUploadsRootAbsolute === '') {
            $previousUploadsRootMode = 'relative';
        }

        if ($uploadsRoot === '' || !preg_match('/^[A-Za-z0-9_\/-]+$/', $uploadsRoot)) {
            $errors[] = 'Uploads root folder may only use letters, numbers, slash, dash, and underscore.';
        }

        if ($uploadsRootMode === 'absolute' && $uploadsRootAbsolute === '') {
            $errors[] = 'Absolute uploads folder is required when Absolute mode is selected.';
        }

        if ($uploadsRootPublicUrl !== '' && !filter_var($uploadsRootPublicUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Uploads public base URL must be a valid URL such as http://192.168.1.42/uploads.';
        }

        if ($inventoryPhotoRoot === '' || !preg_match('/^[A-Za-z0-9_\/-]+$/', $inventoryPhotoRoot)) {
            $errors[] = 'Inventory photo folder may only use letters, numbers, slash, dash, and underscore.';
        }

        $oldAbsoluteRoot = '';
        $newAbsoluteRoot = '';
        if (empty($errors)) {
            $oldAbsoluteRoot = resolve_uploads_root_absolute_path($previousUploadsRootMode, $previousUploadsRoot, $previousUploadsRootAbsolute);
            $newAbsoluteRoot = resolve_uploads_root_absolute_path($uploadsRootMode, $uploadsRoot, $uploadsRootAbsolute);
        }

        $uploadsRootChanged = $oldAbsoluteRoot !== ''
            && $newAbsoluteRoot !== ''
            && rtrim($oldAbsoluteRoot, "\\/") !== rtrim($newAbsoluteRoot, "\\/");

        if ($formAction === 'save' && empty($errors) && $uploadsRootChanged && $copyExistingUploads) {
            if (!is_dir($oldAbsoluteRoot)) {
                $errors[] = 'Current uploads folder was not found for copy: ' . $oldAbsoluteRoot;
            } elseif (!copy_directory_recursive($oldAbsoluteRoot, $newAbsoluteRoot)) {
                $errors[] = 'Unable to copy files from old uploads folder to new uploads folder.';
            } elseif ($moveExistingUploads) {
                $oldReal = realpath($oldAbsoluteRoot);
                $newReal = realpath($newAbsoluteRoot);

                if ($oldReal === false || $newReal === false) {
                    $errors[] = 'Unable to verify old/new uploads paths for move operation.';
                } elseif ($oldReal === $newReal) {
                    $errors[] = 'Old and new uploads folders resolve to the same path. Move was skipped.';
                } elseif (strpos($newReal, $oldReal . DIRECTORY_SEPARATOR) === 0 || strpos($oldReal, $newReal . DIRECTORY_SEPARATOR) === 0) {
                    $errors[] = 'Move aborted because one uploads path is nested inside the other.';
                } elseif (!delete_directory_contents_recursive($oldAbsoluteRoot)) {
                    $errors[] = 'Copied files, but unable to clear old uploads folder contents.';
                }
            }
        }

        if ($formAction === 'test') {
            if (empty($errors)) {
                $targetRoot = $newAbsoluteRoot;
                $createdRoot = false;
                if (!is_dir($targetRoot)) {
                    if (@mkdir($targetRoot, 0775, true)) {
                        $createdRoot = true;
                    } else {
                        $errors[] = 'Unable to create target folder: ' . $targetRoot;
                    }
                }

                $writeOk = false;
                $writeMessage = '';
                if (empty($errors)) {
                    $probeDir = rtrim($targetRoot, "\\/") . DIRECTORY_SEPARATOR . '.__uaspms_path_test';
                    if (!is_dir($probeDir) && !@mkdir($probeDir, 0775, true) && !is_dir($probeDir)) {
                        $writeMessage = 'Cannot create probe directory in target folder.';
                    } else {
                        $probeFile = $probeDir . DIRECTORY_SEPARATOR . 'write_test_' . bin2hex(random_bytes(4)) . '.tmp';
                        $bytes = @file_put_contents($probeFile, 'uaspms_upload_path_test');
                        if ($bytes === false) {
                            $writeMessage = 'Write test failed. Check folder permissions for Apache/PHP service account.';
                        } else {
                            $writeOk = true;
                            $writeMessage = 'Write test passed.';
                            @unlink($probeFile);
                        }
                        @rmdir($probeDir);
                    }
                }

                $sampleRelative = trim($inventoryPhotoRoot, '/') . '/2026/session-1/sample.jpg';
                $preview = build_upload_preview_url($uploadsRootMode, $uploadsRoot, $uploadsRootAbsolute, $uploadsRootPublicUrl, $sampleRelative);
                $pathTestResult = [
                    'ok' => empty($errors) && $writeOk,
                    'mode' => $uploadsRootMode,
                    'resolved_root' => $targetRoot,
                    'created_root' => $createdRoot,
                    'write_ok' => $writeOk,
                    'write_message' => $writeMessage,
                    'preview_url' => $preview['url'],
                    'preview_note' => $preview['note'],
                ];
            }
        } elseif ($db && empty($errors)) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );

            if ($stmt) {
                $savedAll = true;
                $settingsToSave = [
                    'uploads_root_mode' => $uploadsRootMode,
                    'uploads_root' => $uploadsRoot,
                    'uploads_root_absolute' => $uploadsRootAbsolute,
                    'uploads_root_public_url' => $uploadsRootPublicUrl,
                    'inventory_photo_root' => $inventoryPhotoRoot,
                ];

                foreach ($settingsToSave as $settingKey => $settingValue) {
                    $stmt->bind_param('ssi', $settingKey, $settingValue, $userId);
                    if (!$stmt->execute()) {
                        $savedAll = false;
                        break;
                    }
                }
                $stmt->close();

                if ($savedAll) {
                    if (function_exists('spams_cache_forget_prefix')) {
                        spams_cache_forget_prefix('system_setting:');
                    }
                    if (function_exists('write_audit_log')) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'system_settings',
                            'record_id' => 0,
                            'module_name' => 'settings',
                            'record_type' => 'system_setting',
                            'action_name' => 'save_upload_storage_settings',
                            'new_values' => [
                                'uploads_root_mode' => $uploadsRootMode,
                                'uploads_root' => $uploadsRoot,
                                'uploads_root_absolute' => $uploadsRootAbsolute,
                                'uploads_root_public_url' => $uploadsRootPublicUrl,
                                'inventory_photo_root' => $inventoryPhotoRoot,
                                'copy_existing_uploads' => $copyExistingUploads,
                                'move_existing_uploads' => $moveExistingUploads,
                            ],
                        ]);
                    }
                    set_flash('success', 'Upload storage settings saved successfully.');
                    redirect('modules/settings/upload_storage.php');
                } else {
                    $errors[] = 'Database error while saving upload storage settings.';
                }
            } else {
                $errors[] = 'Unable to prepare upload storage settings statement.';
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
require_once __DIR__ . '/../../includes/topbar.php';
?>

<section class="section">
    <div class="row justify-content-center">
        <div class="col-xl-7 col-lg-9">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo h($flash['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo h($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (is_array($pathTestResult)): ?>
                <div class="alert alert-<?php echo $pathTestResult['ok'] ? 'success' : 'warning'; ?>">
                    <div class="fw-semibold mb-2">Path Test Result</div>
                    <div><strong>Mode:</strong> <?php echo h((string) $pathTestResult['mode']); ?></div>
                    <div><strong>Resolved folder:</strong> <?php echo h((string) $pathTestResult['resolved_root']); ?></div>
                    <div><strong>Write check:</strong> <?php echo h((string) $pathTestResult['write_message']); ?></div>
                    <?php if (!empty($pathTestResult['created_root'])): ?>
                        <div><strong>Folder creation:</strong> Target folder was created during test.</div>
                    <?php endif; ?>
                    <?php if ((string) ($pathTestResult['preview_url'] ?? '') !== ''): ?>
                        <div><strong>Sample URL:</strong> <a href="<?php echo h((string) $pathTestResult['preview_url']); ?>" target="_blank" rel="noopener"><?php echo h((string) $pathTestResult['preview_url']); ?></a></div>
                    <?php endif; ?>
                    <?php if ((string) ($pathTestResult['preview_note'] ?? '') !== ''): ?>
                        <div class="small mt-1 text-muted"><?php echo h((string) $pathTestResult['preview_note']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">System Settings</div>
                        <h4 class="mb-2">Upload Storage</h4>
                        <p class="text-muted mb-0">Configure global upload location for all modules and inventory proof-photo subfolder.</p>
                    </div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label d-block">Storage Mode</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="uploads_root_mode" id="uploads_mode_relative" value="relative" <?php echo $uploadsRootMode === 'relative' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="uploads_mode_relative">Relative to <code>spams</code></label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="uploads_root_mode" id="uploads_mode_absolute" value="absolute" <?php echo $uploadsRootMode === 'absolute' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="uploads_mode_absolute">Absolute server path</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="uploads_root" class="form-label">Uploads Root Folder (All Uploads)</label>
                            <input type="text" id="uploads_root" name="uploads_root" class="form-control" value="<?php echo h($uploadsRoot); ?>" placeholder="uploads">
                            <div class="form-text">Relative to <code>spams</code>. Example: <code>uploads</code> or <code>storage/uploads</code>. This applies to all uploaded photos/files system-wide.</div>
                        </div>
                        <div class="mb-3">
                            <label for="uploads_root_absolute" class="form-label">Absolute Uploads Folder (Server Location)</label>
                            <input type="text" id="uploads_root_absolute" name="uploads_root_absolute" class="form-control" value="<?php echo h($uploadsRootAbsolute); ?>" placeholder="C:\\xampp\\htdocs\\UASPMS\\spams\\uploads">
                            <div class="form-text">Use only for server-side absolute paths. Browser cannot open a server folder picker dialog, so this is entered manually.</div>
                        </div>
                        <div class="mb-3">
                            <label for="uploads_root_public_url" class="form-label">Uploads Public Base URL (Optional)</label>
                            <input type="url" id="uploads_root_public_url" name="uploads_root_public_url" class="form-control" value="<?php echo h($uploadsRootPublicUrl); ?>" placeholder="http://172.16.1.42/uploads">
                            <div class="form-text">Needed when Absolute mode points outside the app folder and files are served from a separate web URL.</div>
                        </div>
                        <div class="mb-3">
                            <label for="inventory_photo_root" class="form-label">Inventory Photo Folder</label>
                            <input type="text" id="inventory_photo_root" name="inventory_photo_root" class="form-control" value="<?php echo h($inventoryPhotoRoot); ?>" placeholder="inventory_counts">
                            <div class="form-text">Relative to the uploads root. The system still adds year and session folders under this root.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="copy_existing_uploads" name="copy_existing_uploads" <?php echo $copyExistingUploads ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="copy_existing_uploads">
                                When uploads root changes, copy existing files from old folder to new folder.
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="move_existing_uploads" name="move_existing_uploads" <?php echo $moveExistingUploads ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="move_existing_uploads">
                                Move existing files instead of copy (copy first, then clear old folder contents).
                            </label>
                            <div class="form-text">Use this only after confirming backups. If both options are checked, move mode takes priority.</div>
                        </div>
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-outline-primary" name="form_action" value="test">Test Path</button>
                            <button type="submit" class="btn btn-primary" name="form_action" value="save">Save Upload Storage</button>
                            <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
