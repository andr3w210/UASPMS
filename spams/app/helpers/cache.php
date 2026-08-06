<?php

function &spams_cache_runtime_store(): array
{
    static $store = [];
    return $store;
}

function spams_cache_is_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $flag = strtolower(trim((string) spams_env('SPAMS_CACHE_ENABLED', '1')));
    $enabled = !in_array($flag, ['0', 'false', 'off', 'no'], true);
    return $enabled;
}

function spams_cache_default_ttl(): int
{
    static $ttl = null;
    if ($ttl !== null) {
        return $ttl;
    }

    $configured = (int) spams_env('SPAMS_CACHE_TTL_SECONDS', '300');
    if ($configured < 5) {
        $configured = 5;
    }
    if ($configured > 3600) {
        $configured = 3600;
    }

    $ttl = $configured;
    return $ttl;
}

function spams_cache_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $dir = APP_ROOT . 'logs' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

function spams_cache_path(string $key): string
{
    $safePrefix = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(substr($key, 0, 40))) ?? 'cache';
    $safePrefix = trim($safePrefix, '_');
    if ($safePrefix === '') {
        $safePrefix = 'cache';
    }

    return spams_cache_dir() . $safePrefix . '_' . sha1($key) . '.bin';
}

function spams_cache_get(string $key, $default = null)
{
    $requestCache = &spams_cache_runtime_store();

    if (!spams_cache_is_enabled()) {
        return $default;
    }

    if (array_key_exists($key, $requestCache)) {
        return $requestCache[$key];
    }

    $path = spams_cache_path($key);
    if (!is_file($path)) {
        return $default;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $payload = @unserialize($raw, ['allowed_classes' => false]);
    if (!is_array($payload) || !array_key_exists('expires_at', $payload) || !array_key_exists('value', $payload)) {
        @unlink($path);
        return $default;
    }

    $expiresAt = (int) ($payload['expires_at'] ?? 0);
    if ($expiresAt > 0 && $expiresAt < time()) {
        @unlink($path);
        return $default;
    }

    $requestCache[$key] = $payload['value'];
    return $payload['value'];
}

function spams_cache_set(string $key, $value, ?int $ttlSeconds = null): bool
{
    $requestCache = &spams_cache_runtime_store();

    if (!spams_cache_is_enabled()) {
        return false;
    }

    $ttl = $ttlSeconds ?? spams_cache_default_ttl();
    if ($ttl < 1) {
        $ttl = 1;
    }

    $payload = [
        'cache_key' => $key,
        'expires_at' => time() + $ttl,
        'value' => $value,
    ];

    $serialized = serialize($payload);
    $path = spams_cache_path($key);
    $tempPath = $path . '.tmp';

    $written = @file_put_contents($tempPath, $serialized, LOCK_EX);
    if ($written === false) {
        return false;
    }

    if (!@rename($tempPath, $path)) {
        @unlink($tempPath);
        return false;
    }

    $requestCache[$key] = $value;
    return true;
}

function spams_cache_delete(string $key): bool
{
    $requestCache = &spams_cache_runtime_store();

    unset($requestCache[$key]);

    $path = spams_cache_path($key);
    if (!is_file($path)) {
        return true;
    }

    return @unlink($path);
}

function spams_cache_forget_prefix(string $prefix): int
{
    $requestCache = &spams_cache_runtime_store();

    $deleted = 0;
    foreach (array_keys($requestCache) as $key) {
        if (strpos($key, $prefix) === 0) {
            unset($requestCache[$key]);
        }
    }

    $dir = spams_cache_dir();
    $entries = @scandir($dir);
    if ($entries === false) {
        return $deleted;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . $entry;
        if (!is_file($path)) {
            continue;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }

        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !array_key_exists('value', $payload)) {
            continue;
        }

        if (!array_key_exists('cache_key', $payload)) {
            continue;
        }

        $cacheKey = (string) $payload['cache_key'];
        if (strpos($cacheKey, $prefix) === 0 && @unlink($path)) {
            $deleted++;
        }
    }

    return $deleted;
}

function spams_cache_remember(string $key, int $ttlSeconds, callable $resolver)
{
    $cached = spams_cache_get($key, '__spams_cache_miss__');
    if ($cached !== '__spams_cache_miss__') {
        return $cached;
    }

    $value = $resolver();
    spams_cache_set($key, $value, max(1, $ttlSeconds));

    return $value;
}

?>