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
        $value = 'https://' . $value;
    }

    $parts = parse_url($value);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) $parts['host'];
    $privateIpv4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && (str_starts_with($host, '10.') || str_starts_with($host, '192.168.')
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host));
    if ($scheme !== 'https' && !($scheme === 'http' && $privateIpv4)) {
        return '';
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
$localAccessUrl = $db ? get_system_setting($db, 'local_access_url', 'http://172.16.1.42') : 'http://172.16.1.42';
$localAccessUrl = trim((string) $localAccessUrl) !== '' ? $localAccessUrl : 'http://172.16.1.42';
$tailscaleUrl = to_spams_base_url($tailscaleServeUrl);
if ($tailscaleUrl === '') {
    $tailscaleUrl = to_spams_base_url($appUrl);
}
if ($tailscaleUrl === '') {
    $tailscaleUrl = to_spams_base_url('https://spmu-andrew.tail985047.ts.net');
}

$urls = array_values(array_unique(array_filter([
    to_spams_base_url($localAccessUrl),
    $tailscaleUrl,
])));

echo json_encode([
    'ok' => true,
    'urls' => $urls,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
