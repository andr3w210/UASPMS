<?php
require_once __DIR__ . '/../../app/config/init.php';
require_role('Administrator');

$db = db();
$page_title = 'System Access URL';
$flash = get_flash();
$errors = [];
$defaultSecureUrl = 'https://spmu-andrew.tail985047.ts.net';
$defaultLocalUrl = 'http://172.16.1.42';
$appUrl = $db ? get_system_setting($db, 'app_url', $defaultSecureUrl) : (APP_URL !== '' ? APP_URL : $defaultSecureUrl);
$tailscaleServeUrl = $db ? get_system_setting($db, 'tailscale_serve_url', $appUrl) : $appUrl;
$localAccessUrl = $db ? get_system_setting($db, 'local_access_url', $defaultLocalUrl) : $defaultLocalUrl;
$localAccessUrl = trim((string) $localAccessUrl) !== '' ? $localAccessUrl : $defaultLocalUrl;
$sessionTimeoutMinutes = $db ? get_system_setting($db, 'session_timeout_minutes', '30') : '30';

function normalize_access_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^https?:\/\//i', $value)) {
        $value = 'https://' . $value;
    }

    $value = preg_replace('#/UASPMS/spams/?$#i', '', $value) ?? $value;
    return rtrim($value, '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    request_schema_enforce([
        'tailscale_serve_url' => ['type' => 'string', 'max' => 255],
        'local_access_url' => ['type' => 'string', 'max' => 255],
        'session_timeout_minutes' => ['type' => 'int', 'min' => 0, 'max' => 10080],
    ]);
    if (!csrf_verify()) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $tailscaleServeUrl = normalize_access_url((string) ($_POST['tailscale_serve_url'] ?? ''));
        $localAccessUrl = normalize_access_url((string) ($_POST['local_access_url'] ?? ''));
        $sessionTimeoutMinutes = trim((string) ($_POST['session_timeout_minutes'] ?? '30'));
        $appUrl = $tailscaleServeUrl;
        $urlParts = $appUrl !== '' ? parse_url($appUrl) : false;

        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !is_array($urlParts) || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https') {
            $errors[] = 'Enter a valid HTTPS URL for Tailscale Serve.';
        }

        $localParts = $localAccessUrl !== '' ? parse_url($localAccessUrl) : false;
        $localHost = is_array($localParts) ? (string) ($localParts['host'] ?? '') : '';
        $isPrivateIpv4 = filter_var($localHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && (str_starts_with($localHost, '10.') || str_starts_with($localHost, '192.168.')
                || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $localHost));
        if ($localAccessUrl !== '' && (!filter_var($localAccessUrl, FILTER_VALIDATE_URL) || !is_array($localParts)
            || !in_array(strtolower((string) ($localParts['scheme'] ?? '')), ['http', 'https'], true) || !$isPrivateIpv4)) {
            $errors[] = 'Local access must be an HTTP or HTTPS URL using a private IPv4 address.';
        }

        if ($sessionTimeoutMinutes === '' || !ctype_digit($sessionTimeoutMinutes)) {
            $errors[] = 'Session timeout must be a whole number of minutes.';
        } elseif ((int) $sessionTimeoutMinutes < 5 || (int) $sessionTimeoutMinutes > 480) {
            $errors[] = 'Session timeout must be between 5 and 480 minutes.';
        }

        if ($db && empty($errors)) {
            $userId = current_user_id();
            $auditBefore = [
                'app_url' => get_system_setting($db, 'app_url', ''),
                'tailscale_serve_url' => get_system_setting($db, 'tailscale_serve_url', ''),
                'tailscale_ip_url' => get_system_setting($db, 'tailscale_ip_url', ''),
                'local_access_url' => get_system_setting($db, 'local_access_url', $defaultLocalUrl),
                'session_timeout_minutes' => (int) get_system_setting($db, 'session_timeout_minutes', '30'),
            ];
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
                    'tailscale_ip_url' => '',
                    'local_access_url' => $localAccessUrl,
                    'session_timeout_minutes' => (string) ((int) $sessionTimeoutMinutes),
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
                            'action_name' => 'save_system_access_settings',
                            'old_values' => $auditBefore,
                            'new_values' => [
                                'app_url' => $appUrl,
                                'tailscale_serve_url' => $tailscaleServeUrl,
                                'tailscale_ip_url' => '',
                                'local_access_url' => $localAccessUrl,
                                'session_timeout_minutes' => (int) $sessionTimeoutMinutes,
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
                        <p class="text-muted mb-0">Office network links use the LAN address first. Tailscale remains available for remote access.</p>
                    </div>

                    <form method="post" data-submit-loading="1">
                        <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
                        <div class="mb-3">
                            <label for="local_access_url" class="form-label">Office LAN URL</label>
                            <input type="url" id="local_access_url" name="local_access_url" class="form-control" value="<?php echo h($localAccessUrl); ?>" placeholder="http://172.16.1.42" aria-describedby="local_access_url_help">
                            <div class="form-text" id="local_access_url_help">Used by PCs and phones on the office network when Tailscale is unavailable. HTTP traffic is unencrypted; use this only on a trusted LAN. HTTPS requires a certificate trusted by each device.</div>
                        </div>
                        <div class="mb-3">
                            <label for="tailscale_serve_url" class="form-label">Remote Tailscale HTTPS URL</label>
                            <input type="url" id="tailscale_serve_url" name="tailscale_serve_url" class="form-control" value="<?php echo h($tailscaleServeUrl); ?>" placeholder="https://spmu-andrew.tail985047.ts.net" aria-describedby="tailscale_serve_url_help" required>
                            <div class="form-text" id="tailscale_serve_url_help">Used as the remote access address when you are outside the office network.</div>
                        </div>
                        <div class="mb-3">
                            <label for="session_timeout_minutes" class="form-label">Session Timeout (Minutes)</label>
                            <input type="number" id="session_timeout_minutes" name="session_timeout_minutes" class="form-control" min="5" max="480" value="<?php echo h($sessionTimeoutMinutes); ?>">
                            <div class="form-text">Users are signed out after this many minutes of inactivity. Allowed range: 5 to 480 minutes.</div>
                        </div>
                        <div class="alert alert-light border small">
                            Upload path and file migration options are managed in Upload Storage settings.
                            <a href="<?php echo base_url('modules/settings/upload_storage.php'); ?>" class="ms-1">Open Upload Storage</a>
                        </div>
                        <div class="alert alert-light border small">
                            After saving this, regenerate or reprint QR tags so new tags use the updated network address.
                        </div>
                        <div class="d-grid gap-2 d-sm-flex">
                            <button type="submit" class="btn btn-primary">Save System Settings</button>
                            <a href="<?php echo base_url('modules/settings/index.php'); ?>" class="btn btn-outline-secondary">Back to Settings</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
