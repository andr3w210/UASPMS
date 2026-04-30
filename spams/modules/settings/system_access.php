<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'System Access URL';
$flash = get_flash();
$errors = [];
$appUrl = $db ? get_system_setting($db, 'app_url', APP_URL) : APP_URL;
$tailscaleServeUrl = $db ? get_system_setting($db, 'tailscale_serve_url', 'http://spmu-andrew.tail985047.ts.net') : 'http://spmu-andrew.tail985047.ts.net';
$tailscaleIpUrl = $db ? get_system_setting($db, 'tailscale_ip_url', 'http://100.84.75.22') : 'http://100.84.75.22';
$localUrl = $db ? get_system_setting($db, 'local_access_url', 'http://172.16.1.42') : 'http://172.16.1.42';
$sessionTimeoutMinutes = $db ? get_system_setting($db, 'session_timeout_minutes', '30') : '30';
$inventoryPhotoRoot = $db ? get_system_setting($db, 'inventory_photo_root', 'inventory_counts') : 'inventory_counts';
$caCertificatePath = 'C:\xampp\apache\conf\ssl.crt\uaspms-lan-ca.crt';
$caCertificateExists = is_file($caCertificatePath);

function normalize_access_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^https?:\/\//i', $value)) {
        $value = 'http://' . $value;
    }

    $value = preg_replace('#/UASPMS/spams/?$#i', '', $value) ?? $value;
    return rtrim($value, '/');
}

function first_available_access_url(string ...$urls): string
{
    foreach ($urls as $url) {
        $url = normalize_access_url($url);
        if ($url !== '') {
            return $url;
        }
    }

    return '';
}

if (isset($_GET['download']) && $_GET['download'] === 'lan_ca') {
    if (!$caCertificateExists) {
        http_response_code(404);
        exit('LAN certificate was not found on this server.');
    }

    header('Content-Type: application/x-x509-ca-cert');
    header('Content-Disposition: attachment; filename="uaspms-lan-ca.crt"');
    header('Content-Length: ' . (string) filesize($caCertificatePath));
    readfile($caCertificatePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $tailscaleServeUrl = normalize_access_url((string) ($_POST['tailscale_serve_url'] ?? ''));
        $tailscaleIpUrl = normalize_access_url((string) ($_POST['tailscale_ip_url'] ?? ''));
        $localUrl = normalize_access_url((string) ($_POST['local_access_url'] ?? ''));
        $sessionTimeoutMinutes = trim((string) ($_POST['session_timeout_minutes'] ?? '30'));
        $inventoryPhotoRoot = trim((string) ($_POST['inventory_photo_root'] ?? 'inventory_counts'));
        $inventoryPhotoRoot = trim(str_replace(['..', '\\'], ['', '/'], $inventoryPhotoRoot), " /\t\n\r\0\x0B");

        $appUrl = first_available_access_url($tailscaleServeUrl, $tailscaleIpUrl, $localUrl, $appUrl);

        if ($appUrl !== '' && !filter_var($appUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'System Access URL must be a valid URL like http://192.168.1.10 or http://server-name.';
        }

        if ($tailscaleServeUrl !== '' && !filter_var($tailscaleServeUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Tailscale Serve URL must be a valid URL like http://server.tailnet.ts.net.';
        }

        if ($tailscaleIpUrl !== '' && !filter_var($tailscaleIpUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Tailscale IP URL must be a valid URL like http://100.84.75.22.';
        }

        if ($localUrl !== '' && !filter_var($localUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Local URL must be a valid URL like http://172.16.1.42.';
        }

        if ($appUrl === '') {
            $errors[] = 'Enter at least one access URL.';
        }

        if ($sessionTimeoutMinutes === '' || !ctype_digit($sessionTimeoutMinutes)) {
            $errors[] = 'Session timeout must be a whole number of minutes.';
        } elseif ((int) $sessionTimeoutMinutes < 5 || (int) $sessionTimeoutMinutes > 480) {
            $errors[] = 'Session timeout must be between 5 and 480 minutes.';
        }

        if ($inventoryPhotoRoot === '') {
            $errors[] = 'Inventory photo folder cannot be blank.';
        } elseif (!preg_match('/^[A-Za-z0-9_\/-]+$/', $inventoryPhotoRoot)) {
            $errors[] = 'Inventory photo folder may only use letters, numbers, slash, dash, and underscore.';
        }

        if ($db && empty($errors)) {
            $userId = current_user_id();
            $stmt = $db->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
            );

            if ($stmt) {
                $savedAll = true;
                $settingsToSave = [
                    'app_url' => $appUrl,
                    'tailscale_serve_url' => $tailscaleServeUrl,
                    'tailscale_ip_url' => $tailscaleIpUrl,
                    'local_access_url' => $localUrl,
                    'session_timeout_minutes' => (string) ((int) $sessionTimeoutMinutes),
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
                    if (function_exists('write_audit_log')) {
                        write_audit_log($db, [
                            'action' => 'update',
                            'table_name' => 'system_settings',
                            'record_id' => 0,
                            'module_name' => 'settings',
                            'record_type' => 'system_setting',
                            'action_name' => 'save_system_access_settings',
                            'new_values' => [
                                'app_url' => $appUrl,
                                'tailscale_serve_url' => $tailscaleServeUrl,
                                'tailscale_ip_url' => $tailscaleIpUrl,
                                'local_access_url' => $localUrl,
                                'session_timeout_minutes' => (int) $sessionTimeoutMinutes,
                                'inventory_photo_root' => $inventoryPhotoRoot,
                            ],
                        ]);
                    }
                    set_flash('success', 'System access settings saved successfully.');
                    redirect('modules/settings/system_access.php');
                } else {
                    $errors[] = 'Database error while saving the system access settings.';
                }
            } else {
                $errors[] = 'Unable to prepare the system access settings statement.';
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

            <div class="card shadow-sm border-0">
                <div class="card-body p-4 p-lg-5">
                    <div class="mb-4">
                        <div class="text-uppercase small text-muted fw-semibold">System Settings</div>
                        <h4 class="mb-2">System Access & Session</h4>
                        <p class="text-muted mb-0">Set the network address used by QR links, control how long idle users stay signed in, and choose where inventory proof photos are stored.</p>
                    </div>

                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label class="form-label">Access URLs</label>
                            <div class="form-text mb-3">Set every address the system may use. The system tries them in this order: Tailscale Serve, Tailscale IP, then Local Network. Do not add <code>/UASPMS/spams</code>; the system app path is appended automatically.</div>

                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex align-items-start gap-2 mb-2">
                                    <div class="flex-grow-1">
                                        <label for="tailscale_serve_url" class="form-label mb-1 fw-semibold">1. Tailscale Serve URL</label>
                                        <input type="url" id="tailscale_serve_url" name="tailscale_serve_url" class="form-control" value="<?php echo h($tailscaleServeUrl); ?>" placeholder="http://spmu-andrew.tail985047.ts.net">
                                        <div class="form-text">Recommended for Android phones connected through Tailscale.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="d-flex align-items-start gap-2 mb-2">
                                            <div class="flex-grow-1">
                                                <label for="tailscale_ip_url" class="form-label mb-1 fw-semibold">2. Tailscale IP URL</label>
                                                <input type="url" id="tailscale_ip_url" name="tailscale_ip_url" class="form-control" value="<?php echo h($tailscaleIpUrl); ?>" placeholder="http://100.84.75.22">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="border rounded p-3 h-100">
                                        <div class="d-flex align-items-start gap-2 mb-2">
                                            <div class="flex-grow-1">
                                                <label for="local_access_url" class="form-label mb-1 fw-semibold">3. Local Network URL</label>
                                                <input type="url" id="local_access_url" name="local_access_url" class="form-control" value="<?php echo h($localUrl); ?>" placeholder="http://172.16.1.42">
                                                <div class="form-text">Use only when phone and server PC are on the same LAN.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="session_timeout_minutes" class="form-label">Session Timeout (Minutes)</label>
                            <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" class="form-control" min="5" max="480" value="<?php echo h($sessionTimeoutMinutes); ?>">
                            <div class="form-text">Users are signed out after this many minutes of inactivity. Allowed range: 5 to 480 minutes.</div>
                        </div>
                        <div class="mb-3">
                            <label for="inventory_photo_root" class="form-label">Inventory Photo Folder</label>
                            <input type="text" id="inventory_photo_root" name="inventory_photo_root" class="form-control" value="<?php echo h($inventoryPhotoRoot); ?>" placeholder="inventory_counts">
                            <div class="form-text">Relative to <code>spams/uploads</code>. The system still adds the year and session folder under this root.</div>
                        </div>
                        <div class="alert alert-light border small">
                            After saving this, regenerate or reprint QR tags so new tags use the updated network address.
                        </div>
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-primary">Save System Settings</button>
                            <?php if ($caCertificateExists): ?>
                                <a href="<?php echo base_url('modules/settings/system_access.php?download=lan_ca'); ?>" class="btn btn-outline-primary">Download LAN Certificate</a>
                            <?php endif; ?>
                            <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
