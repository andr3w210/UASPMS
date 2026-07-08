<?php
/**
 * IP-based rate limiting helper.
 *
 * Uses the application database to track attempts per IP address and action
 * key. Required schema must be created by migrations.
 *
 * Usage:
 *   if (rate_limit_check('login')) { $error = 'Too many attempts...'; }
 *   else { rate_limit_record('login'); /* process form *\/ }
 */

/**
 * Ensure required rate limiter schema exists.
 * Uses a static flag so the check runs at most once per request.
 */
function _rate_limit_schema_ready(mysqli $db): bool
{
    static $checked = false;
    static $ready = false;
    if ($checked) {
        return $ready;
    }
    $checked = true;

    $stmt = $db->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'rate_limit_attempts'
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_row();
    $stmt->close();
    $ready = $row !== null;

    return $ready;
}

/**
 * Return the client IP address.
 * Only REMOTE_ADDR is trusted to prevent header-spoofing attacks.
 */
function _rate_limit_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Check whether the current IP has exceeded the rate limit for an action.
 *
 * @param string $key           A short identifier for the action, e.g. 'login'.
 * @param int    $maxAttempts   Maximum allowed attempts within the window.
 * @param int    $windowMinutes Rolling time window in minutes.
 *
 * @param bool   $failClosed    When true, block requests if the limiter backend is unavailable.
 *
 * @return bool  True if the limit is exceeded (request should be blocked).
 */
function rate_limit_check(string $key, int $maxAttempts = 5, int $windowMinutes = 15, bool $failClosed = false): bool
{
    $db = db();
    if (!$db) {
        return $failClosed;
    }

    if (!_rate_limit_schema_ready($db)) {
        return $failClosed;
    }

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM rate_limit_attempts
        WHERE ip_address   = ?
          AND action_key   = ?
          AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");

    if (!$stmt) {
        return $failClosed;
    }

    $stmt->bind_param('ssi', $ip, $key, $windowMinutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int) ($row['cnt'] ?? 0)) >= $maxAttempts;
}

/**
 * Record one attempt for the current IP and action key.
 * Periodically prunes entries older than 24 hours (≈1 % of calls) to prevent
 * unbounded table growth without needing a scheduled job.
 *
 * @param string $key           The same key used in rate_limit_check().
 */
function rate_limit_record(string $key): void
{
    $db = db();
    if (!$db) {
        return;
    }

    if (!_rate_limit_schema_ready($db)) {
        return;
    }

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        INSERT INTO rate_limit_attempts (ip_address, action_key, attempted_at)
        VALUES (?, ?, NOW())
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ss', $ip, $key);
    $stmt->execute();
    $stmt->close();

    // Probabilistic cleanup — avoid a cron dependency
    if (random_int(1, 100) === 1) {
        $db->query("DELETE FROM rate_limit_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    }
}

/**
 * Return the number of seconds until the oldest attempt within the window
 * ages out and the IP is no longer blocked.
 *
 * @param string $key
 * @param int    $maxAttempts
 * @param int    $windowMinutes
 *
 * @return int  Seconds remaining, or 0 if not blocked / DB unavailable.
 */
function rate_limit_retry_after(string $key, int $maxAttempts = 5, int $windowMinutes = 15): int
{
    $db = db();
    if (!$db) {
        return 0;
    }

    _rate_limit_ensure_table($db);

    $ip   = _rate_limit_ip();
    $stmt = $db->prepare("
        SELECT MIN(attempted_at) AS oldest
        FROM (
            SELECT attempted_at
            FROM rate_limit_attempts
            WHERE ip_address = ?
              AND action_key = ?
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ORDER BY attempted_at DESC
            LIMIT ?
        ) sub
    ");

    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('ssii', $ip, $key, $windowMinutes, $maxAttempts);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (empty($row['oldest'])) {
        return 0;
    }

    $resetAt = strtotime((string) $row['oldest']) + ($windowMinutes * 60);
    return max(0, $resetAt - time());
}
