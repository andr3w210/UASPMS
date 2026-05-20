<?php
require_once __DIR__ . '/../../app/config/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function normalize_access_url_public(string $value): string
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

function to_spams_base_url(string $value): string
{
    $base = normalize_access_url_public($value);
    if ($base === '') {
        return '';
    }

    return $base . '/UASPMS/spams/';
}

$db = db();

$appUrl = $db ? get_system_setting($db, 'app_url', APP_URL) : APP_URL;
$tailscaleServeUrl = $db ? get_system_setting($db, 'tailscale_serve_url', '') : '';
$tailscaleIpUrl = $db ? get_system_setting($db, 'tailscale_ip_url', '') : '';
$localUrl = $db ? get_system_setting($db, 'local_access_url', '') : '';

$urls = array_values(array_filter(array_unique([
    to_spams_base_url($localUrl),
    to_spams_base_url($tailscaleServeUrl),
    to_spams_base_url($tailscaleIpUrl),
    to_spams_base_url($appUrl),
])));

echo json_encode([
    'ok' => true,
    'urls' => $urls,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
